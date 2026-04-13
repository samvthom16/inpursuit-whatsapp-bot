<?php
defined( 'ABSPATH' ) || exit;

/**
 * Routes incoming text messages to the correct query handler method.
 *
 * Supported commands:
 *   help                         — list available commands
 *   member <name>                — search for a member by name
 *   status <name>                — show member status (follow-up status)
 *   attendance <event name>      — show attendance % for an event
 *   members <group>              — list members in a group
 *   events                       — list upcoming/recent events
 *   event <name>                 — show details for a specific event
 *   birthday                     — members with birthdays today / this month
 *   followup                     — members needing follow-up (pending status)
 *   stats                        — quick analytics summary
 */
class INPURSUIT_WA_Command_Parser {

    public static function handle( $text ) {
        $text  = trim( $text );
        $lower = strtolower( $text );

        // help
        if ( $lower === 'help' || $lower === 'hi' || $lower === 'hello' ) {
            return self::help_message();
        }

        // stats
        if ( $lower === 'stats' || $lower === 'statistics' ) {
            return INPURSUIT_WA_Query_Handler::get_stats();
        }

        // birthday / birthdays
        if ( strpos( $lower, 'birthday' ) === 0 ) {
            return INPURSUIT_WA_Query_Handler::get_birthdays();
        }

        // followup / follow up / pending
        if ( strpos( $lower, 'followup' ) === 0 || strpos( $lower, 'follow up' ) === 0 || $lower === 'pending' ) {
            return INPURSUIT_WA_Query_Handler::get_followup_members();
        }

        // events (no argument = list recent)
        if ( $lower === 'events' ) {
            return INPURSUIT_WA_Query_Handler::get_recent_events();
        }

        // event <name>
        if ( strpos( $lower, 'event ' ) === 0 ) {
            $name = trim( substr( $text, 6 ) );
            return INPURSUIT_WA_Query_Handler::get_event_by_name( $name );
        }

        // attendance <event name>
        if ( strpos( $lower, 'attendance ' ) === 0 ) {
            $name = trim( substr( $text, 11 ) );
            return INPURSUIT_WA_Query_Handler::get_event_attendance( $name );
        }

        // members <group>
        if ( strpos( $lower, 'members ' ) === 0 ) {
            $group = trim( substr( $text, 8 ) );
            return INPURSUIT_WA_Query_Handler::get_members_by_group( $group );
        }

        // status <name>
        if ( strpos( $lower, 'status ' ) === 0 ) {
            $name = trim( substr( $text, 7 ) );
            return INPURSUIT_WA_Query_Handler::get_member_status( $name );
        }

        // member <name>
        if ( strpos( $lower, 'member ' ) === 0 ) {
            $name = trim( substr( $text, 7 ) );
            return INPURSUIT_WA_Query_Handler::get_member( $name );
        }

        // Fallback: try treating the whole message as a member name search
        if ( strlen( $text ) >= 3 ) {
            return INPURSUIT_WA_Query_Handler::get_member( $text );
        }

        return self::help_message();
    }

    private static function help_message() {
        return implode( "\n", array(
            "*InPursuit Bot* — Available commands:",
            "",
            "🔍 *member <name>*          — Search for a member",
            "📋 *status <name>*          — Member follow-up status",
            "👥 *members <group>*        — List members in a group",
            "📅 *events*                 — Recent/upcoming events",
            "📅 *event <name>*           — Event details",
            "📊 *attendance <event>*     — Event attendance",
            "🎂 *birthday*               — Birthdays this month",
            "🔔 *followup*               — Members needing follow-up",
            "📈 *stats*                  — Summary statistics",
            "❓ *help*                   — Show this message",
        ) );
    }
}
