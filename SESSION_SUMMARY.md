# Session Summary — InPursuit WhatsApp Bot
**Date:** 2026-04-13

---

## Overview

Built a new WordPress plugin `inpursuit-whatsapp-bot` that acts as a WhatsApp bot for the InPursuit church management system. The bot allows the admin team to query church member data (follow-ups, attendance, birthdays, stats) directly from WhatsApp using Meta Cloud API.

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

## New Plugin Built

**Plugin name:** InPursuit WhatsApp Bot
**Local path:** `C:\Users\LH Media\ClaudeCode\inpursuit-whatsapp-bot`

### File Structure

```
inpursuit-whatsapp-bot/
├── inpursuit-whatsapp.php              ← Plugin entry point
├── admin/
│   └── class-wa-settings.php          ← WP admin settings page
└── includes/
    ├── class-wa-auth.php               ← Phone number whitelist
    ├── class-wa-api.php                ← Outgoing messages via Meta Graph API
    ├── class-wa-webhook.php            ← Incoming webhook (GET verify + POST messages)
    ├── class-wa-command-parser.php     ← Text command routing
    └── class-wa-query-handler.php      ← Database queries + response formatting
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
     │  checks whitelist
     ▼
INPURSUIT_WA_Auth
     │  routes command
     ▼
INPURSUIT_WA_Command_Parser
     │  queries DB
     ▼
INPURSUIT_WA_Query_Handler
     │  formats response
     ▼
INPURSUIT_WA_API  ──►  Meta Cloud API  ──►  WhatsApp User
```

---

## Bot Commands

| Command | Description |
|---|---|
| `/help` | List all available commands |
| `/members` | List 10 members with ID; filtered to user's assigned groups if set |
| `/member <name>` | Full member profile (status, group, age, last seen, etc.) |
| `/status <name>` | Member follow-up status + last seen event |
| `/events` | Special dates this month (birthdays & weddings) |
| `/attendance <event>` | Attendance count and percentage for an event |
| `/followup` | Members with pending/follow-up status |
| `/stats` | Summary: total members, events, breakdown by status & group |

---

## Key Design Decisions

| Decision | Reason |
|---|---|
| Meta Cloud API (not Twilio) | Free tier, official, no per-message cost |
| Dedicated bot number required | Personal WhatsApp numbers cannot run the API simultaneously |
| Add-on plugin (not modifying parent) | Clean separation; parent plugin stays unmodified |
| Checks `defined('INPURSUIT_VERSION')` | Prevents activation if parent plugin is missing |
| Phone whitelist in WP options | Admin controls who can query the bot; empty = open access |
| Reuses parent DB classes | No code duplication; stays in sync with parent data model |

---

## WhatsApp Number Options

| Option | Cost | Notes |
|---|---|---|
| Meta Cloud API + virtual number | Free API | Google Voice, Skype, or cheap SIM |
| Meta Cloud API + spare SIM | Free API + SIM cost | Recommended for production |
| Twilio Sandbox | Free for dev/test | Good for testing before Meta setup |
| Personal number (WhatsApp app) | Free | Cannot be automated — no bot possible |

---

## Settings Page

**WordPress Admin → InPursuit → WhatsApp Bot**

| Field | Description |
|---|---|
| Phone Number ID | From Meta App → WhatsApp → API Setup |
| Access Token | Permanent system user token from Meta Business Manager |
| Verify Token | Secret string you create; paste same value in Meta webhook config |
| Allowed Numbers | Whitelist (one per line, international format e.g. `447911123456`) |

**Webhook URL** (shown on settings page):
```
https://yoursite.com/wp-json/inpursuit-wa/v1/webhook
```

---

## Deployment Steps

1. Copy `inpursuit-whatsapp-bot/` folder to `/wp-content/plugins/` on the WordPress server
2. Activate the plugin from WP Admin → Plugins
3. Create a Meta Developer account at https://developers.facebook.com
4. Create a new Meta App → Add **WhatsApp** product
5. Register a dedicated phone number (or use Meta's free test number for dev)
6. Copy the **Phone Number ID** from Meta App → WhatsApp → API Setup
7. Generate a **Permanent Access Token** via Meta Business Manager → System Users
8. Go to **WP Admin → InPursuit → WhatsApp Bot** and fill in all credentials
9. In Meta dashboard → WhatsApp → Configuration:
   - Paste the Webhook URL
   - Paste your Verify Token
   - Click **Verify and Save**
   - Subscribe to the **`messages`** webhook field
10. Add admin team phone numbers to the whitelist
11. Test by sending `/help` to the bot number on WhatsApp

---

## Webhook Logging (Added 2026-04-14)

### New File: `includes/class-wa-logger.php`
File-based logger writing to `wp-content/uploads/inpursuit-wa-logs/webhook.log`.
- Directory auto-created with `.htaccess` (blocks direct browser access) and `index.php`
- Rotates at 512 KB
- Methods: `INPURSUIT_WA_Logger::info()`, `::warning()`, `::error()`
- `get_recent( $n )` — returns last N lines as a string
- `clear()` — empties the log file

### What Gets Logged

| Event | Level |
|---|---|
| Webhook GET verified by Meta | INFO |
| Webhook verification failed | WARN |
| Incoming POST received | INFO |
| Unexpected object type ignored | WARN |
| Status update (delivered/read tick) | INFO |
| Non-text message type ignored | INFO |
| Message received (from, id, text) | INFO |
| Unauthorised sender blocked | WARN |
| Command handled + reply length | INFO |
| Reply sent successfully | INFO |
| Failed to send reply | ERROR |
| Meta API HTTP error | ERROR |
| Credentials not configured | ERROR |

### Admin Pages

- **WP Admin → InPursuit → WhatsApp Bot** — settings page now includes a "Recent Activity" section showing the last 50 log entries
- **WP Admin → InPursuit → WhatsApp Logs** — dedicated full log viewer showing last 150 entries, auto-scrolls to newest, with a "Clear Logs" button

---

---

## Authentication Overhaul (2026-04-14)

### New Table — `wp_ip_wa_users`
Replaces the old plaintext "Allowed Numbers" whitelist in settings.

| Column | Type | Notes |
|---|---|---|
| `ID` | BIGINT | Auto-increment PK |
| `user_id` | BIGINT | Unique FK → `wp_users` |
| `phone` | VARCHAR(30) | Unique, no + prefix |

Created automatically via `register_activation_hook`.

### New Files
- **`includes/class-wa-user-table.php`** — table creation + CRUD (`get_user_by_phone`, `get_phone_for_user`, `save_phone_for_user`)
- **`admin/class-wa-profile.php`** — adds a *WhatsApp Number* field to every WP user profile page (show + save with nonce + duplicate check)

### Updated Auth Flow
```
Incoming phone
    → INPURSUIT_WA_Auth::get_user()      looks up wp_ip_wa_users
    → returns WP_User (or blocks if null)
    → INPURSUIT_WA_Auth::get_role()      reads $user->roles[0]
    → passed into Command_Parser::handle($text, $wp_user)
    → passed as $role into every Query_Handler method
```

### Unauthenticated Response
```
⛔ *Access Denied*

Your number is not registered to use this bot.
Please contact your administrator to get access.
```

### To Authorise a User
WP Admin → Users → Edit User → *InPursuit WhatsApp Bot* section → enter number → Save.

### Removed
- "Allowed Numbers" textarea from the settings page
- `get_allowed_numbers()` helper from `class-wa-settings.php`
- `is_allowed()` method from `class-wa-auth.php`

---

## Commands Update (2026-04-14)

### Removed Commands
- `members <group>`
- `event <name>`
- `birthday`

### Current Commands
| Command | Description |
|---|---|
| `/members` | List 10 members with ID; filtered to user's assigned groups if set |
| `/member <name>` | Search for a member |
| `/status <name>` | Member follow-up status |
| `/comment <name> \| <text>` | Add a plain comment to a member |
| `/comment <name> \| <text> \| <category>` | Add a comment with a category |
| `/categories` | List all available comment categories |
| `/events` | Special dates this month (from `wp_ip_member_dates`) |
| `/attendance <event>` | Event attendance |
| `/followup` | Members needing follow-up |
| `/stats` | Summary statistics |
| `/help` | Show command list |

### `events` Command
Queries `wp_ip_member_dates` directly for birthdays and weddings in the current calendar month, from today onwards, ordered by day ASC.

---

## Commands — Input Modes (2026-04-14)

Three ways to send commands, in priority order:

1. **Slash commands** — always work (e.g. `/stats`, `/member John`)
2. **AI natural language** — works when OpenAI API key is configured (e.g. `"who needs follow up?"`)
3. **Keyword fallback** — always works, no API key needed (e.g. `stats`, `find John`, `follow up`)

`hi` / `hello` greetings still return the help message.

---

---

## Cleanup (2026-04-14)

- Removed log preview section from the settings page — logs are now only on the dedicated **WhatsApp Logs** page (WP Admin → InPursuit → WhatsApp Logs)

---

---

## ChatGPT / AI Command Routing (2026-04-14)

### New File: `includes/class-wa-ai-router.php`

Optional AI layer that lets users send **plain English** instead of typed slash commands.

### How It Works

```
User: "who needs a follow up?"
  → not a slash command
  → INPURSUIT_WA_AI_Router::route()
  → OpenAI gpt-4o-mini (function calling)
  → returns: { command: "/followup" }
  → Command Parser handles "/followup" normally
  → Result sent back to user
```

### Key Details

| Detail | Value |
|---|---|
| Model | `gpt-4o-mini` (cheap, fast) |
| Method | OpenAI function calling with forced `route_command` tool |
| HTTP | `wp_remote_post` — no extra dependencies |
| Timeout | 15 seconds |
| Fallback | If API key missing or call fails → returns `null` → keyword fallback runs |
| Loop guard | `$ai_resolved` flag on `Command_Parser::handle()` prevents recursive AI calls |

### Configuration

**WP Admin → InPursuit → WhatsApp Bot** → new **OpenAI API Key** field.
- Leave empty to disable AI routing (slash commands still work)
- Get a key at `platform.openai.com/api-keys`

### Example Natural Language Inputs

| User types | Resolved to |
|---|---|
| "show stats" | `/stats` |
| "who needs follow up?" | `/followup` |
| "look up John Smith" | `/member John Smith` |
| "show me the members" | `/members` |
| "attendance for Sunday Service" | `/attendance Sunday Service` |
| "any birthdays this month?" | `/events` |

### AI-Powered Comment Parsing (two-step)

When AI is enabled and intent is `/comment`, a **second OpenAI call** runs to extract structured fields from the freeform message:

```
Input: "Kajal asked prayer request for her mother. She needs to meet the dentist."

Step 1 — Intent detection
  OpenAI → command = /comment

Step 2 — parse_comment_fields()
  Categories fetched from wp_ip_comments_category → sent to OpenAI
  OpenAI extracts:
    member_name     = "Kajal"
    comment_summary = "Prayer request for mother's dental appointment"
    category_name   = "Prayer Request"   ← best match from DB list

Canonical command built:
  /comment Kajal | Prayer request for mother's dental appointment | Prayer Request

add_member_comment() resolves:
  member_id  → DB search "Kajal"  (with group filter)
  user_id    → $wp_user->ID       (from phone number auth)
  INSERT wp_ip_comments + wp_ip_comments_category_relation
```

**Fallback:** if `parse_comment_fields()` fails, returns `null` → keyword router handles it.

### Logging
- `AI Router: "..." → "..."` — INFO on every successful AI resolution
- `AI Router (comment): "..." → "..."` — INFO on AI-parsed comment commands
- `AI Router (comment parse): member="..." category="..." summary="..."` — INFO on extracted fields
- `AI Router: HTTP error` / `OpenAI returned status NNN` — ERROR on failures
- `Keyword Router: "..." → "..."` — INFO on every keyword match

---

## Keyword Fallback Routing (2026-04-14)

Works without an OpenAI API key. Added to `INPURSUIT_WA_AI_Router::keyword_route()`.

### No-argument keywords

| User types | Resolves to |
|---|---|
| `stats` / `statistics` / `summary` / `overview` | `/stats` |
| `followup` / `follow up` / `follow-up` / `pending` | `/followup` |
| `members` / `list members` / `all members` / `show members` | `/members` |
| `events` / `birthdays` / `anniversaries` / `special dates` | `/events` |
| `help` / `commands` | `/help` |

### Prefix patterns (with argument)

| User types | Resolves to |
|---|---|
| `member <name>` / `find <name>` / `search <name>` / `look up <name>` | `/member <name>` |
| `status <name>` | `/status <name>` |
| `attendance <event>` | `/attendance <event>` |

---

---

## Comment Commands (2026-04-14)

Allows authenticated bot users to add follow-up comments to members directly from WhatsApp, using the parent plugin's existing comment tables.

### New Commands

| Command | Description |
|---|---|
| `/comment <name> \| <text>` | Add a plain comment to a member |
| `/comment <name> \| <text> \| <category>` | Add a comment with a category |
| `/categories` | List all comment categories with IDs |

### DB Tables Used (parent plugin)

| Table | Purpose |
|---|---|
| `wp_ip_comments` | Stores the comment (`comment`, `post_id`, `user_id`) |
| `wp_ip_comments_category` | Category taxonomy (`term_id`, `name`) |
| `wp_ip_comments_category_relation` | Links a comment to a category (`term_id`, `comment_id`) |

### Group Filtering
Member lookup for `/comment` applies the same group filter as `/members` — users can only comment on members in their assigned groups.

### Behaviour

| Scenario | Response |
|---|---|
| `/comment John \| Great progress` | ✅ Comment added to *John Smith*, Category: None |
| `/comment John \| Called today \| Follow-up` | ✅ Comment added, category linked |
| `/comment John \| Called today \| BadCat` | Comment saved, note: category not found |
| `/comment John` (no pipe) | ⚠️ Usage hint shown |
| Member not found / ambiguous | Error or list of matches |
| `/categories` | Bulleted list of all categories |

### Keyword Fallback
- `categories` / `comment categories` / `list categories` → `/categories`
- `comment <name> | text` / `add comment <name> | text` → `/comment`

---

---

## AI Agent Mode — Planned (not yet implemented)

A full conversational AI agent that lets users ask anything in plain English and have the AI query the database directly, rather than routing to fixed commands.

### Architecture
```
User: "How many people in the Youth group haven't attended in 3 months?"
         ↓
INPURSUIT_WA_AI_Agent::handle()
         ↓
OpenAI receives message + tool definitions + system prompt (with user's groups)
         ↓
AI calls: get_members({ group: "Youth", ... })
         ↓
PHP executes query — group filter ALWAYS enforced in code
         ↓
Result returned to OpenAI → AI composes natural language answer
         ↓
WhatsApp reply
```

### Files to Create
| File | Purpose |
|---|---|
| `includes/class-wa-db-tools.php` | 9 DB query tools, each enforcing group access; returns raw arrays for AI to process |
| `includes/class-wa-ai-agent.php` | Agentic loop (max 5 iterations), system prompt, OpenAI tool-use handler |

### DB Tools Planned
| Tool | Description |
|---|---|
| `get_members` | Filter by group, status, gender, location, limit |
| `get_member_details` | Full profile by name |
| `get_member_history` | Comment + event history for a member |
| `get_stats` | Total members/events, breakdown by status & group |
| `get_followup_members` | Members with pending/follow-up status |
| `get_events` | Birthdays & anniversaries this month |
| `get_event_attendance` | Attendance stats for a named event |
| `add_member_comment` | Save a comment (with optional category) |
| `get_comment_categories` | List all comment categories |

### Settings Change Needed
- Add **AI Agent Mode** toggle to WP Admin → InPursuit → WhatsApp Bot
- When ON: plain-English messages go through the agent
- When OFF: existing AI router + keyword fallback used (current behaviour)

### Group Safety
Group access enforced in PHP inside each tool — AI cannot bypass it even if it tries.

### Example Queries That Would Work
- *"How many members are in the Youth group?"*
- *"Who hasn't attended in 3 months?"*
- *"Show me all female members in the Connect group"*
- *"Which event had the best attendance this year?"*
- *"List members with no comments recorded"*

---

## Still To Do

- [ ] User to create Meta Business account
- [ ] Obtain a dedicated phone number for the bot
- [ ] Deploy plugin to live WordPress server
- [ ] Test webhook verification with Meta dashboard
- [ ] Test all bot commands end-to-end
- [ ] **Build AI Agent Mode** (see planned section above)
- [ ] Consider adding conversation state (e.g. multi-step member lookup)
