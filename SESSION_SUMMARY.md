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
| `help` | List all available commands |
| `member <name>` | Full member profile (status, group, age, last seen, etc.) |
| `status <name>` | Member follow-up status + last seen event |
| `members <group>` | List all members in a group |
| `events` | 5 most recent events |
| `event <name>` | Event detail + attendance stats |
| `attendance <event>` | Attendance count and percentage for an event |
| `birthday` | Birthdays and anniversaries in the next 30 days |
| `followup` | Members with pending/follow-up status |
| `stats` | Summary: total members, events, breakdown by status & group |

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
11. Test by sending `help` to the bot number on WhatsApp

---

## Still To Do

- [ ] User to create Meta Business account
- [ ] Obtain a dedicated phone number for the bot
- [ ] Deploy plugin to live WordPress server
- [ ] Test webhook verification with Meta dashboard
- [ ] Test all bot commands end-to-end
- [ ] Consider adding conversation state (e.g. multi-step member lookup)
- [ ] Consider logging incoming queries for audit trail
