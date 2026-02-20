# 🗓️ SlackMeet — AI-Powered Meeting Scheduler for Slack

Schedule meetings in plain English, directly from Slack.
No calendar switching. No back-and-forth. Just type what you want.

## ✨ Demo

/schedule Team standup with Sarah tomorrow at 10am for 30 minutes

→ Bot parses with AI, shows confirmation card, saves to database.

## 🚀 Features

- `/schedule` — Natural language meeting scheduling via GPT-4o-mini
- `/reschedule` — Pick from your meetings and set a new time
- `/cancel` — Select exactly which meeting to cancel
- `/schedstatus` — View all upcoming meetings with status
- ✅ Interactive Confirm/Cancel buttons via Slack Block Kit
- ⚡ Async processing — responds in <1 second, AI runs in background

## 🛠️ Tech Stack

| Layer | Technology |
|---|---|
| Backend | CodeIgniter 4 (PHP) |
| AI/NLP | OpenAI GPT-4o-mini |
| Database | MySQL |
| Slack UI | Block Kit Interactive Cards |
| Tunnel | ngrok |

## 🏗️ Architecture

User types slash command
→ SlackController responds instantly (<1s)
→ Job stored in MySQL queue
→ Background processor calls GPT-4o-mini
→ Parsed meeting saved to DB
→ Confirmation card sent to Slack
→ User clicks Confirm → Meeting confirmed ✅

## ⚙️ Setup

### Prerequisites
- PHP 8.x
- MySQL
- Composer
- ngrok
- OpenAI API key
- Slack App with slash commands enabled

### Installation

# Clone the repo
git clone https://github.com/yourusername/slackmeet.git
cd slackmeet/backend

# Install dependencies
composer install

# Configure environment
cp env .env
# Edit .env with your DB credentials and OpenAI key

# Create database tables
mysql -u root -p ci4 < schema.sql

# Start the server
php spark serve

# Start ngrok
ngrok http 8080

# Process jobs (run this after each slash command)
php spark slack:process

### Slack App Configuration

Set these Request URLs in your Slack App dashboard:

| Command | URL |
|---|---|
| `/schedule` | `https://your-ngrok-url/slack/schedule` |
| `/reschedule` | `https://your-ngrok-url/slack/reschedule` |
| `/cancel` | `https://your-ngrok-url/slack/cancel` |
| `/schedstatus` | `https://your-ngrok-url/slack/schedstatus` |
| Interactivity | `https://your-ngrok-url/slack/interactivity` |

## 🗄️ Database Schema

Two tables:
- `slack_jobs` — Job queue for async processing
- `meetings` — Confirmed meeting storage with JSON participants

## 🔮 Future Enhancements

- Google Calendar sync
- Participant DM notifications
- Recurring meeting support
- Conflict detection
- Always-on background processor with cron

## 👩‍💻 Built By

Neha Suram — Built in one day as part of a technical challenge.
