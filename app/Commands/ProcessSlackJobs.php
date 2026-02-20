<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class ProcessSlackJobs extends BaseCommand
{
    protected $group       = 'Slack';
    protected $name        = 'slack:process';
    protected $description = 'Process pending Slack scheduling jobs via LLM';

    public function run(array $params)
    {
        CLI::write('🔍 Checking for pending jobs...', 'yellow');

        $db   = \Config\Database::connect();
        $jobs = $db->table('slack_jobs')->where('status', 'pending')->get()->getResultArray();

        if (empty($jobs)) {
            CLI::write('✅ No pending jobs found.', 'green');
            return;
        }

        foreach ($jobs as $job) {
            CLI::write("⚙️  Processing job ID: {$job['id']} — \"{$job['text']}\"", 'cyan');
            $db->table('slack_jobs')->where('id', $job['id'])->update(['status' => 'processing']);

            try {
                $parsed    = $this->callLLM($job['text']);
                $meetingId = $this->storeMeeting($parsed, $job);
                $this->notifySlack($job['response_url'], $parsed, $job, $meetingId);
                $db->table('slack_jobs')->where('id', $job['id'])->update(['status' => 'done']);
                CLI::write("✅ Job {$job['id']} done.", 'green');
            } catch (\Exception $e) {
                $db->table('slack_jobs')->where('id', $job['id'])->update(['status' => 'failed']);
                CLI::error("❌ Job {$job['id']} failed: " . $e->getMessage());
            }
        }
    }

    private function callLLM(string $userText): array
    {
        $apiKey = getenv('OPENAI_API_KEY');

        if (!$apiKey) {
            throw new \Exception('OPENAI_API_KEY not found in .env');
        }

        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $prompt = "Today is $today (tomorrow is $tomorrow). Extract meeting details from this scheduling request and return ONLY valid JSON with these keys: title, date (YYYY-MM-DD), time (HH:MM in 24h), duration_minutes (integer), participants (array of names), notes. User said: \"$userText\"";

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "Authorization: Bearer $apiKey"
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'       => 'gpt-4o-mini',
                'messages'    => [
                    ['role' => 'system', 'content' => 'You are a meeting scheduling parser. Return only valid JSON, no markdown, no explanation.'],
                    ['role' => 'user',   'content' => $prompt]
                ],
                'temperature' => 0
            ])
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        CLI::write("LLM HTTP status: $httpCode", 'yellow');
        CLI::write("LLM raw response: $response", 'yellow');

        $data    = json_decode($response, true);
        $content = $data['choices'][0]['message']['content'] ?? '{}';
        $parsed  = json_decode(trim($content), true);

        if (!$parsed) {
            throw new \Exception('LLM returned invalid JSON: ' . $content);
        }

        return $parsed;
    }

    private function storeMeeting(array $parsed, array $job): int
    {
        $db = \Config\Database::connect();
        $db->table('meetings')->insert([
            'user_id'          => $job['user_id'],
            'title'            => $parsed['title']            ?? 'Untitled Meeting',
            'meeting_date'     => $parsed['date']             ?? null,
            'meeting_time'     => $parsed['time']             ?? null,
            'duration_minutes' => $parsed['duration_minutes'] ?? 60,
            'participants'     => json_encode($parsed['participants'] ?? []),
            'notes'            => $parsed['notes']            ?? '',
            'status'           => 'pending_confirmation',
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return $db->insertID();
    }

    private function notifySlack(string $responseUrl, array $parsed, array $job, int $meetingId): void
    {
        $participants = implode(', ', $parsed['participants'] ?? ['None']);

        $message = [
            'response_type' => 'ephemeral',
            'blocks'        => [
                [
                    'type' => 'section',
                    'text' => ['type' => 'mrkdwn', 'text' => "📅 *Here's what I understood — please confirm:*"]
                ],
                [
                    'type'   => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*📌 Meeting:*\n" . ($parsed['title'] ?? 'N/A')],
                        ['type' => 'mrkdwn', 'text' => "*📆 Date:*\n"    . ($parsed['date']  ?? 'N/A')],
                        ['type' => 'mrkdwn', 'text' => "*🕐 Time:*\n"    . ($parsed['time']  ?? 'N/A')],
                        ['type' => 'mrkdwn', 'text' => "*⏱ Duration:*\n" . ($parsed['duration_minutes'] ?? 60) . ' min'],
                        ['type' => 'mrkdwn', 'text' => "*👥 With:*\n"    . $participants],
                    ]
                ],
                [
                    'type'     => 'actions',
                    'elements' => [
                        [
                            'type'      => 'button',
                            'text'      => ['type' => 'plain_text', 'text' => '✅ Confirm'],
                            'style'     => 'primary',
                            'value'     => 'confirm_' . $job['id'],
                            'action_id' => 'confirm_meeting'
                        ],
                        [
                            'type'      => 'button',
                            'text'      => ['type' => 'plain_text', 'text' => '❌ Cancel'],
                            'style'     => 'danger',
                            'value'     => 'cancel_' . $job['id'],
                            'action_id' => 'cancel_meeting'
                        ],
                    ]
                ]
            ]
        ];

        $ch = curl_init($responseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($message)
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
