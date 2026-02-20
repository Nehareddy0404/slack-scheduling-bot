<?php
namespace App\Controllers;
use CodeIgniter\Controller;

class SlackController extends Controller
{
    public function schedule()
    {
        $text        = $this->request->getPost('text');
        $userId      = $this->request->getPost('user_id');
        $userName    = $this->request->getPost('user_name');
        $responseUrl = $this->request->getPost('response_url');

        $db = \Config\Database::connect();
        $db->table('slack_jobs')->insert([
            'user_id'      => $userId,
            'user_name'    => $userName,
            'text'         => $text,
            'response_url' => $responseUrl,
            'status'       => 'pending',
            'created_at'   => date('Y-m-d H:i:s'),
        ]);

        return $this->response->setJSON([
            'response_type' => 'ephemeral',
            'text'          => "⏳ Got it! Parsing: *\"$text\"* — confirming shortly..."
        ]);
    }

    public function reschedule()
    {
        $text        = $this->request->getPost('text');
        $userId      = $this->request->getPost('user_id');
        $responseUrl = $this->request->getPost('response_url');

        $db      = \Config\Database::connect();
        $meetings = $db->table('meetings')
                      ->where('user_id', $userId)
                      ->where('status', 'confirmed')
                      ->orderBy('meeting_date', 'ASC')
                      ->get()->getResultArray();

        if (empty($meetings)) {
            return $this->response->setJSON([
                'response_type' => 'ephemeral',
                'text'          => '❌ No confirmed meetings found to reschedule.'
            ]);
        }

        $blocks = [
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "*🔄 Which meeting would you like to reschedule to: \"$text\"?*"]
            ]
        ];

        foreach ($meetings as $m) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => "*{$m['title']}*\n📆 {$m['meeting_date']} at {$m['meeting_time']}\n⏱ {$m['duration_minutes']} min"],
                'accessory' => [
                    'type'      => 'button',
                    'text'      => ['type' => 'plain_text', 'text' => '🔄 Reschedule This'],
                    'style'     => 'primary',
                    'value'     => 'reschedulemeeting_' . $m['id'] . '_' . urlencode($text) . '_' . urlencode($responseUrl),
                    'action_id' => 'reschedule_specific_meeting'
                ]
            ];
        }

        return $this->response->setJSON([
            'response_type' => 'ephemeral',
            'blocks'        => $blocks
        ]);
    }

    public function cancel()
    {
        $userId      = $this->request->getPost('user_id');
        $responseUrl = $this->request->getPost('response_url');
        $db          = \Config\Database::connect();
        $meetings    = $db->table('meetings')
                          ->where('user_id', $userId)
                          ->whereIn('status', ['confirmed', 'pending_confirmation'])
                          ->orderBy('meeting_date', 'ASC')
                          ->get()->getResultArray();

        if (empty($meetings)) {
            return $this->response->setJSON([
                'response_type' => 'ephemeral',
                'text'          => '📭 You have no active meetings to cancel.'
            ]);
        }

        $blocks = [
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => '*Which meeting would you like to cancel?*']
            ]
        ];

        foreach ($meetings as $m) {
            $blocks[] = [
                'type'      => 'section',
                'text'      => ['type' => 'mrkdwn', 'text' => "*{$m['title']}*\n📆 {$m['meeting_date']} at {$m['meeting_time']}\n⏱ {$m['duration_minutes']} min"],
                'accessory' => [
                    'type'      => 'button',
                    'text'      => ['type' => 'plain_text', 'text' => '❌ Cancel This'],
                    'style'     => 'danger',
                    'value'     => 'cancelmeeting_' . $m['id'],
                    'action_id' => 'cancel_specific_meeting'
                ]
            ];
        }

        return $this->response->setJSON([
            'response_type' => 'ephemeral',
            'blocks'        => $blocks
        ]);
    }

    public function status()
    {
        $userId   = $this->request->getPost('user_id');
        $db       = \Config\Database::connect();
        $meetings = $db->table('meetings')
                       ->where('user_id', $userId)
                       ->whereNotIn('status', ['cancelled'])
                       ->orderBy('meeting_date', 'ASC')
                       ->get()->getResultArray();

        if (empty($meetings)) {
            return $this->response->setJSON([
                'response_type' => 'ephemeral',
                'text'          => '📭 You have no upcoming meetings.'
            ]);
        }

        $blocks = [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*📅 Your Upcoming Meetings:*']]
        ];

        foreach ($meetings as $m) {
            $statusEmoji = $m['status'] === 'confirmed' ? '✅' : '⏳';
            $blocks[] = [
                'type'   => 'section',
                'fields' => [
                    ['type' => 'mrkdwn', 'text' => "*{$m['title']}*"],
                    ['type' => 'mrkdwn', 'text' => "📆 {$m['meeting_date']} at {$m['meeting_time']}"],
                    ['type' => 'mrkdwn', 'text' => "⏱ {$m['duration_minutes']} min"],
                    ['type' => 'mrkdwn', 'text' => "$statusEmoji `{$m['status']}`"],
                ]
            ];
            $blocks[] = ['type' => 'divider'];
        }

        return $this->response->setJSON([
            'response_type' => 'ephemeral',
            'blocks'        => $blocks
        ]);
    }

    public function interactivity()
    {
        $payload = json_decode($this->request->getPost('payload'), true);
        $action  = $payload['actions'][0] ?? null;
        $db      = \Config\Database::connect();

        if (!$action) {
            return $this->response->setStatusCode(200);
        }

        $value = $action['value'];
        $parts = explode('_', $value);
        $type  = $parts[0];

        // ✅ Confirm meeting from confirmation card
        if ($type === 'confirm') {
            $jobId = end($parts);
            $job   = $db->table('slack_jobs')->where('id', $jobId)->get()->getRowArray();
            $db->table('meetings')
               ->where('user_id', $job['user_id'])
               ->where('status', 'pending_confirmation')
               ->orderBy('id', 'DESC')
               ->update(['status' => 'confirmed', 'updated_at' => date('Y-m-d H:i:s')]);

            return $this->response->setJSON([
                'response_type'    => 'ephemeral',
                'replace_original' => true,
                'text'             => '✅ Meeting confirmed! It\'s on your calendar.'
            ]);
        }

        // ❌ Cancel meeting from confirmation card (decline before confirming)
        if ($type === 'cancel' && isset($parts[1]) && is_numeric($parts[1])) {
            $jobId = $parts[1];
            $job   = $db->table('slack_jobs')->where('id', $jobId)->get()->getRowArray();
            $db->table('meetings')
               ->where('user_id', $job['user_id'])
               ->where('status', 'pending_confirmation')
               ->orderBy('id', 'DESC')
               ->update(['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);

            return $this->response->setJSON([
                'response_type'    => 'ephemeral',
                'replace_original' => true,
                'text'             => '❌ Meeting cancelled.'
            ]);
        }

        // ❌ Cancel specific meeting from /cancel list
        if ($type === 'cancelmeeting') {
            $meetingId = end($parts);
            $meeting   = $db->table('meetings')->where('id', $meetingId)->get()->getRowArray();
            $db->table('meetings')->where('id', $meetingId)->update([
                'status'     => 'cancelled',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            return $this->response->setJSON([
                'response_type'    => 'ephemeral',
                'replace_original' => true,
                'text'             => "✅ *\"{$meeting['title']}\"* on {$meeting['meeting_date']} has been cancelled."
            ]);
        }

        // 🔄 Reschedule specific meeting from /reschedule list
        if ($type === 'reschedulemeeting') {
            $meetingId   = $parts[1];
            $newText     = urldecode($parts[2] ?? '');
            $responseUrl = urldecode(implode('_', array_slice($parts, 3)));

            $meeting = $db->table('meetings')->where('id', $meetingId)->get()->getRowArray();

            // Queue a reschedule job
            $db->table('slack_jobs')->insert([
                'user_id'      => $meeting['user_id'],
                'user_name'    => '',
                'text'         => "RESCHEDULE meeting_id:{$meetingId} to: $newText",
                'response_url' => $responseUrl,
                'status'       => 'pending',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            return $this->response->setJSON([
                'response_type'    => 'ephemeral',
                'replace_original' => true,
                'text'             => "🔄 Rescheduling *\"{$meeting['title']}\"* — one moment..."
            ]);
        }

        return $this->response->setStatusCode(200);
    }
}