<?php
defined( 'ABSPATH' ) || exit;

/**
 * Routes incoming text messages to the correct query handler method.
 *
 * Supported commands:
 *   /help                         — list available commands
 *   /member <name>                — search for a member by name
 *   /status <name>                — show member status (follow-up status)
 *   /attendance <event name>      — show attendance % for an event
 *   /events                       — list special events for the current month
 *   /followup                     — members needing follow-up (pending status)
 *   /stats                        — quick analytics summary
 */
class INPURSUIT_WA_Command_Parser {

    public static function handle( $text, WP_User $wp_user = null ) {
        $text  = trim( $text );
        $lower = strtolower( $text );
        $role  = $wp_user ? INPURSUIT_WA_Auth::get_role( $wp_user ) : 'subscriber';

        // help
        if ( $lower === '/help' || $lower === 'hi' || $lower === 'hello' ) {
            return self::help_message();
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
