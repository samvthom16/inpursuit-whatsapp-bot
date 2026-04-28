# Session Summary — InPursuit WhatsApp Bot
**Last updated:** 2026-04-28 (session 2)

---

## Overview

WordPress plugin `inpursuit-whatsapp-bot` — a WhatsApp bot add-on for the InPursuit church management system. Allows the admin team to look up members, check special dates, and log follow-up notes directly from WhatsApp using Meta Cloud API and an OpenAI-powered AI agent.

---

## Existing System

### 1. WordPress Plugin — `InPursuit-Wp-Plugin`
**Local path:** `C:\Users\LH Media\ClaudeCode\InPursuit-Wp-Plugin`
**GitHub:** https://github.com/samvthom16/InPursuit

- Custom post types: `inpursuit-members`, `inpursuit-events`
- REST namespace: `inpursuit/v1`
- Auth: WordPress Application Passwords
- Taxonomies: `inpursuit-status`, `inpursuit-group`, `inpursuit-gender`, `inpursuit-profession`, `inpursuit-location`, `inpursuit-event-type`
- DB tables: `wp_ip_member_dates`, `wp_ip_event_member_relation`, `wp_ip_comments`, `wp_ip_comments_category`, `wp_ip_push_subscription`, `wp_ip_user`
- DB helper classes: `INPURSUIT_DB_MEMBER`, `INPURSUIT_DB_EVENT`, `INPURSUIT_DB_MEMBER_DATES`, `INPURSUIT_DB_COMMENT`, `INPURSUIT_DB`

### 2. Vue PWA — `inPursuit`
**Local path:** `C:\Users\LH Media\ClaudeCode\inPursuit`
**GitHub:** https://github.com/samvthom16/testvue
**Live:** https://inpursuit.vercel.app

- Vue 3 + Vuex + Vue Router + Vite + Tailwind CSS
- Communicates with the WordPress plugin via REST API using Basic Auth

---

## Plugin

**Plugin name:** InPursuit WhatsApp Bot
**Local path:** `C:\Users\LH Media\ClaudeCode\inpursuit-whatsapp-bot`

### File Structure

```
inpursuit-whatsapp-bot/
├── inpursuit-whatsapp.php              ← Plugin entry point
├── admin/
│   ├── class-wa-settings.php          ← WP admin settings page + log viewer
│   └── class-wa-profile.php           ← WhatsApp number field on user profile
└── includes/
    ├── class-wa-logger.php             ← File-based webhook logger
    ├── class-wa-user-table.php         ← wp_ip_wa_users table CRUD
    ├── class-wa-auth.php               ← Resolves phone → WP_User
    ├── class-wa-api.php                ← Outgoing messages via Meta Graph API
    ├── class-wa-webhook.php            ← Incoming webhook (GET verify + POST messages)
    ├── class-wa-db-tools.php           ← DB query tools for the AI agent
    └── class-wa-ai-agent.php           ← Agentic loop, system prompt, tool dispatch
```

### Architecture

```
WhatsApp User
     │
     ▼
Meta Cloud API
     │  POST /wp-json/inpursuit-wa/v1/webhook
     ▼
INPURSUIT_WA_Webhook
     │  mark as read
     │  resolve phone → WP_User (wp_ip_wa_users table)
     │  send "thinking" message
     ▼
INPURSUIT_WA_AI_Agent::handle($text, $wp_user, $phone)
     │  load session history (transient)
     │  agentic loop: OpenAI tool calling → DB_Tools → repeat (max 5 steps)
     │  save session history
     ▼
INPURSUIT_WA_API::send_text()
     │
     ▼
Meta Cloud API  ──►  WhatsApp User
```

---

## What the Bot Can Do

The agent is scoped to exactly four user functions:

| Function | Description |
|---|---|
| **Member lookup** | Full profile — status, group, gender, profession, location, age, last seen event, and last 5 notes |
| **Special dates** | Birthdays and anniversaries coming up in the next 30 days |
| **Follow-up history** | All recorded notes for a specific member, summarised into one short readable paragraph |
| **Add a note** | Save a follow-up comment to a member with a category |

The agent politely declines any request outside these four tasks.

---

## AI Agent

### Model
`gpt-4o-mini` via OpenAI Chat Completions API (tool calling).

### Tools (5)

| Tool | Type | Description |
|---|---|---|
| `get_member_details` | Read | Full profile by name + last 5 notes |
| `get_events` | Read | Birthdays & anniversaries in the next 30 days |
| `get_member_comments` | Read | All follow-up notes for a member, returned as a flat text block; agent summarises into one short paragraph |
| `add_member_comment` | **Write** | Save a note/comment with a category |
| `get_comment_categories` | Read | List all comment categories — called before `add_member_comment` |

### Group Access Control
- Enforced in PHP inside each DB tool — the AI cannot bypass it
- Users with assigned groups only see members from those groups
- `add_member_comment` is the only permitted write operation

### Session State
Conversation history is persisted between messages using WordPress transients.

| Detail | Value |
|---|---|
| Transient key | `inpursuit_wa_sess_{md5(phone)}` |
| Value | JSON array of `{role, content}` pairs (user + assistant turns only) |
| TTL | 300 seconds, reset on every reply |
| History cap | 20 entries (10 pairs) — oldest dropped when exceeded |

Tool call messages are ephemeral and not stored.
Session is **cleared** after a successful `add_member_comment`.

### Multi-turn Clarifying Questions
When information is missing, the agent asks one focused question at a time rather than guessing:

```
user:   "add a note"
agent:  "Who would you like to add a note for?"
user:   "Peter"
agent:  "What should the note say?"
user:   "He called today asking for prayer"
agent:  [calls get_comment_categories → add_member_comment]
```

### Thinking Message
A random message is sent immediately before each agent call:
```
⏳ Looking into that...   🔍 Let me check that for you...   📋 Pulling that up now...
🤔 On it, give me a moment...   📊 Fetching that information...   💬 Just a second...
🔎 Searching the records...
```

---

## Authentication

### Table — `wp_ip_wa_users`

| Column | Type | Notes |
|---|---|---|
| `ID` | BIGINT | Auto-increment PK |
| `user_id` | BIGINT | Unique FK → `wp_users` |
| `phone` | VARCHAR(30) | Unique, no + prefix |

Created automatically via `register_activation_hook`.

### Auth Flow
```
Incoming phone number
    → INPURSUIT_WA_Auth::get_user()    looks up wp_ip_wa_users
    → returns WP_User, or sends "Access Denied" and stops
    → WP_User passed into AI_Agent::handle()
    → group IDs read from user meta inside each DB tool
```

### To Authorise a User
WP Admin → Users → Edit User → *InPursuit WhatsApp Bot* section → enter number → Save.

### Unauthenticated Response
```
⛔ *Access Denied*

Your number is not registered to use this bot.
Please contact your administrator to get access.
```

---

## Settings Page

**WP Admin → InPursuit → WhatsApp Bot**

| Field | Description |
|---|---|
| Phone Number ID | From Meta App → WhatsApp → API Setup |
| Access Token | Permanent system user token from Meta Business Manager |
| Verify Token | Secret string; paste same value in Meta webhook configuration |
| OpenAI API Key | Required — powers the AI agent |

**Webhook URL** (shown on settings page):
```
https://yoursite.com/wp-json/inpursuit-wa/v1/webhook
```

---

## Logging

File-based logger writing to `wp-content/uploads/inpursuit-wa-logs/webhook.log`.
- Rotates at 512 KB
- Directory protected with `.htaccess` (blocks direct browser access)
- Methods: `INPURSUIT_WA_Logger::info()`, `::warning()`, `::error()`

**WP Admin → InPursuit → WhatsApp Logs** — shows last 150 entries, auto-scrolls to newest, with a Clear Logs button.

| Event | Level |
|---|---|
| Webhook GET verified | INFO |
| Webhook verification failed | WARN |
| Incoming POST received | INFO |
| Status update (delivered/read) | INFO |
| Non-text message ignored | INFO |
| Message received (from, id, text) | INFO |
| Unauthorised sender blocked | WARN |
| Authenticated as user | INFO |
| AI Agent tool called | INFO |
| AI Agent resolved in N steps | INFO |
| Reply sent successfully | INFO |
| Failed to send reply | ERROR |
| OpenAI HTTP/API error | ERROR |

---

## Key Design Decisions

| Decision | Reason |
|---|---|
| Meta Cloud API (not Twilio) | Free tier, official, no per-message cost |
| Dedicated bot number required | Personal WhatsApp numbers cannot run the API simultaneously |
| Add-on plugin (not modifying parent) | Clean separation; parent plugin stays unmodified |
| Checks `defined('INPURSUIT_VERSION')` | Prevents activation if parent plugin is missing |
| Auth via `wp_ip_wa_users` table | Links phone numbers to WP users; inherits WP roles and group meta |
| Group access enforced in PHP | AI cannot be prompted to bypass access control |
| Agent-only mode | No slash commands — plain English only, simpler UX |
| Session cleared after comment saved | Prevents stale context from affecting the next conversation |
| DB access via parent plugin classes only | Child plugin never calls `$wpdb` directly — all queries go through `INPURSUIT_DB_*` classes from the parent plugin |
| Member search via `WP_Query` + `posts_where` filter | No parent class exposes name-based member search; using WordPress's query abstraction keeps it off raw SQL |
| `get_member_comments` returns a flat text block | Returning a structured array caused the model to bullet-list the notes; a single concatenated string with an embedded `instruction` field forces a paragraph summary |

---

## Deployment Steps

1. Copy `inpursuit-whatsapp-bot/` folder to `/wp-content/plugins/` on the WordPress server
2. Activate the plugin from WP Admin → Plugins
3. Create a Meta Developer account at https://developers.facebook.com
4. Create a new Meta App → Add **WhatsApp** product
5. Register a dedicated phone number (or use Meta's free test number for dev)
6. Copy the **Phone Number ID** from Meta App → WhatsApp → API Setup
7. Generate a **Permanent Access Token** via Meta Business Manager → System Users
8. Go to **WP Admin → InPursuit → WhatsApp Bot** and fill in all credentials including the OpenAI API key
9. In Meta dashboard → WhatsApp → Configuration:
   - Paste the Webhook URL
   - Paste your Verify Token
   - Click **Verify and Save**
   - Subscribe to the **`messages`** webhook field
10. Authorise admin team members via WP Admin → Users → Edit User → WhatsApp Number
11. Test by sending a message to the bot number on WhatsApp

---

## Still To Do

- [ ] User to create Meta Business account
- [ ] Obtain a dedicated phone number for the bot
- [ ] Deploy plugin to live WordPress server
- [ ] Test webhook verification with Meta dashboard
- [ ] Test agent end-to-end for all three user functions
