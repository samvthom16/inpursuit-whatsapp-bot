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

        // Plain-English routing: AI first, keyword fallback if AI is unavailable/fails
        if ( ! $ai_resolved && strpos( $lower, '/' ) !== 0 && $lower !== 'hi' && $lower !== 'hello' ) {
            $resolved = INPURSUIT_WA_AI_Router::route( $text );          // OpenAI (needs API key)
            if ( ! $resolved ) {
                $resolved = INPURSUIT_WA_AI_Router::keyword_route( $text ); // keyword fallback (always works)
            }
            if ( $resolved ) {
                return self::handle( $resolved, $wp_user, true );
            }
        }

        // help
        if ( $lower === '/help' || $lower === 'hi' || $lower === 'hello' ) {
            return self::help_message();
        }

        // members list
        if ( $lower === '/members' ) {
            return INPURSUIT_WA_Query_Handler::get_members_list( $wp_user );
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

        return self::help_message();
    }

    private static function help_message() {
        return implode( "\n", array(
            "*InPursuit Bot* — Available commands:",
            "",
            "👥 */members*                — List members (filtered by your groups)",
            "🔍 */member <name>*          — Search for a member",
            "📋 */status <name>*          — Member follow-up status",
            "📅 */events*                 — Special dates this month",
            "📊 */attendance <event>*     — Event attendance",
            "🔔 */followup*               — Members needing follow-up",
            "📈 */stats*                  — Summary statistics",
            "❓ */help*                   — Show this message",
        ) );
    }
}
