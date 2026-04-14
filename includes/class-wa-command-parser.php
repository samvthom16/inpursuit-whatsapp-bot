<?php
defined( 'ABSPATH' ) || exit;

/**
 * Routes incoming text messages to the correct query handler method.
 *
 * Supported commands (slash prefix, plain English via AI, or plain English via keyword fallback):
 *   /help                         — list available commands
 *   /members                      — list 10 members (filtered by user groups)
 *   /member <name>                — search for a member by name
 *   /status <name>                — show member status (follow-up status)
 *   /attendance <event name>      — show attendance % for an event
 *   /events                       — list special events for the current month
 *   /followup                     — members needing follow-up (pending status)
 *   /stats                        — quick analytics summary
 */
class INPURSUIT_WA_Command_Parser {

    /**
     * @param string       $text
     * @param WP_User|null $wp_user
     * @param bool         $ai_resolved  True when called recursively after AI routing (prevents loops).
     */
    public static function handle( $text, WP_User $wp_user = null, $ai_resolved = false ) {
        $text  = trim( $text );
        $lower = strtolower( $text );
        $role  = $wp_user ? INPURSUIT_WA_Auth::get_role( $wp_user ) : 'subscriber';

        // Plain-English routing
        if ( ! $ai_resolved && strpos( $lower, '/' ) !== 0 && $lower !== 'hi' && $lower !== 'hello' ) {
            // AI Agent Mode: conversational agent with direct DB tool access
            if ( INPURSUIT_WA_Settings::get( 'ai_agent_mode' ) === '1' ) {
                $reply = INPURSUIT_WA_AI_Agent::handle( $text, $wp_user );
                if ( $reply ) {
                    return $reply;
                }
                // Fall through to keyword fallback if agent fails
            } else {
                // Standard: OpenAI router first, then keyword fallback
                $resolved = INPURSUIT_WA_AI_Router::route( $text );
                if ( ! $resolved ) {
                    $resolved = INPURSUIT_WA_AI_Router::keyword_route( $text );
                }
                if ( $resolved ) {
                    return self::handle( $resolved, $wp_user, true );
                }
            }

            // Keyword fallback always runs if nothing else matched
            if ( INPURSUIT_WA_Settings::get( 'ai_agent_mode' ) === '1' ) {
                $resolved = INPURSUIT_WA_AI_Router::keyword_route( $text );
                if ( $resolved ) {
                    return self::handle( $resolved, $wp_user, true );
                }
            }
        }

        // help / greeting
        if ( $lower === '/help' || $lower === 'hi' || $lower === 'hello' ) {
            return INPURSUIT_WA_Settings::get( 'ai_agent_mode' ) === '1'
                ? self::agent_help_message()
                : self::help_message();
        }

        // members list
        if ( $lower === '/members' ) {
            return INPURSUIT_WA_Query_Handler::get_members_list( $wp_user );
        }

        // categories
        if ( $lower === '/categories' ) {
            return INPURSUIT_WA_Query_Handler::get_comment_categories();
        }

        // /comment <name> | <text> | <category (optional)>
        if ( strpos( $lower, '/comment ' ) === 0 ) {
            $raw   = trim( substr( $text, 9 ) );
            $parts = array_map( 'trim', explode( '|', $raw, 3 ) );

            if ( count( $parts ) < 2 || empty( $parts[1] ) ) {
                return "⚠️ *Usage:* /comment <name> | <text> | <category (optional)>\n\nExample:\n/comment John Smith | Called him today | Follow-up";
            }

            $member_name   = $parts[0];
            $comment_text  = $parts[1];
            $category_name = $parts[2] ?? '';

            return INPURSUIT_WA_Query_Handler::add_member_comment( $member_name, $comment_text, $category_name, $wp_user );
        }

        // stats
        if ( $lower === '/stats' ) {
            return INPURSUIT_WA_Query_Handler::get_stats( $role );
        }

        // followup
        if ( $lower === '/followup' ) {
            return INPURSUIT_WA_Query_Handler::get_followup_members( $role );
        }

        // events (no argument = list recent)
        if ( $lower === '/events' ) {
            return INPURSUIT_WA_Query_Handler::get_recent_events( $role );
        }

        // /attendance <event name>
        if ( strpos( $lower, '/attendance ' ) === 0 ) {
            $name = trim( substr( $text, 12 ) );
            return INPURSUIT_WA_Query_Handler::get_event_attendance( $name, $role );
        }

        // /status <name>
        if ( strpos( $lower, '/status ' ) === 0 ) {
            $name = trim( substr( $text, 8 ) );
            return INPURSUIT_WA_Query_Handler::get_member_status( $name, $role );
        }

        // /member <name>
        if ( strpos( $lower, '/member ' ) === 0 ) {
            $name = trim( substr( $text, 8 ) );
            return INPURSUIT_WA_Query_Handler::get_member( $name, $role );
        }

        return INPURSUIT_WA_Settings::get( 'ai_agent_mode' ) === '1'
            ? self::agent_help_message()
            : self::help_message();
    }

    private static function agent_help_message() {
        return implode( "\n", array(
            "*Hi! I'm the InPursuit assistant.* Just ask me anything in plain English — no commands needed.",
            "",
            "Here are some things you can ask:",
            "",
            "👥 *Members*",
            "• \"Show me the members in the Youth group\"",
            "• \"List all female members\"",
            "• \"Tell me about John Smith\"",
            "",
            "🔔 *Follow-up*",
            "• \"Who needs a follow-up?\"",
            "• \"Which members are pending?\"",
            "",
            "📅 *Special Dates*",
            "• \"Any birthdays this month?\"",
            "• \"Show me anniversaries coming up\"",
            "",
            "📊 *Attendance & Stats*",
            "• \"What was the attendance for Sunday Service?\"",
            "• \"Give me an overview of the church\"",
            "",
            "💬 *Comments*",
            "• \"Add a note for Sarah — she called today asking for prayer\"",
            "• \"Log a follow-up comment for Peter\"",
        ) );
    }

    private static function help_message() {
        return implode( "\n", array(
            "*InPursuit Bot* — Available commands:",
            "",
            "👥 */members*                      — List members (filtered by your groups)",
            "🔍 */member <name>*               — Search for a member",
            "📋 */status <name>*               — Member follow-up status",
            "💬 */comment <name> | <text>*     — Add a comment to a member",
            "🏷️ */categories*                 — List comment categories",
            "📅 */events*                      — Special dates this month",
            "📊 */attendance <event>*          — Event attendance",
            "🔔 */followup*                    — Members needing follow-up",
            "📈 */stats*                       — Summary statistics",
            "❓ */help*                        — Show this message",
        ) );
    }
}
