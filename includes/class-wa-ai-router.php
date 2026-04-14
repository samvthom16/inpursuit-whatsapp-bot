<?php
defined( 'ABSPATH' ) || exit;

/**
 * Uses the OpenAI Chat Completions API to map a natural-language WhatsApp
 * message to a canonical bot command (e.g. "/member John" or "/stats").
 *
 * Only called when the user's message is NOT already a slash command.
 * Returns null on failure so the caller can fall back gracefully.
 */
class INPURSUIT_WA_AI_Router {

    const API_URL = 'https://api.openai.com/v1/chat/completions';
    const MODEL   = 'gpt-4o-mini';

    /**
     * Resolve a natural-language message to a canonical command string.
     * Tries OpenAI first; if the API key is not set or the call fails, returns null
     * so the caller can fall through to keyword_route().
     *
     * @param  string $text  The raw user message.
     * @return string|null   e.g. "/stats", "/member John Smith", or null on failure.
     */
    public static function route( $text ) {
        $api_key = INPURSUIT_WA_Settings::get( 'openai_api_key' );
        if ( empty( $api_key ) ) {
            return null; // no key — silently skip; keyword_route() will handle it
        }

        $payload = array(
            'model'       => self::MODEL,
            'messages'    => array(
                array( 'role' => 'system', 'content' => self::system_prompt() ),
                array( 'role' => 'user',   'content' => $text ),
            ),
            'tools'        => array( self::tool_definition() ),
            'tool_choice'  => array(
                'type'     => 'function',
                'function' => array( 'name' => 'route_command' ),
            ),
            'temperature'  => 0,
        );

        $response = wp_remote_post( self::API_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            INPURSUIT_WA_Logger::error( 'AI Router: HTTP error — ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 || empty( $body ) ) {
            INPURSUIT_WA_Logger::error( 'AI Router: OpenAI returned status ' . $status );
            return null;
        }

        // Extract the function call arguments
        $tool_calls = $body['choices'][0]['message']['tool_calls'] ?? array();
        if ( empty( $tool_calls ) ) {
            INPURSUIT_WA_Logger::warning( 'AI Router: No tool call in OpenAI response.' );
            return null;
        }

        $args = json_decode( $tool_calls[0]['function']['arguments'] ?? '{}', true );
        if ( empty( $args['command'] ) ) {
            INPURSUIT_WA_Logger::warning( 'AI Router: Empty command in tool call arguments.' );
            return null;
        }

        $command = $args['command'];

        // ── Special handling for /comment ─────────────────────────────────────
        // Run a second AI call to extract member name, summary, and category
        // from the freeform message rather than passing raw text through.
        if ( $command === '/comment' ) {
            $parsed = self::parse_comment_fields( $text, $api_key );
            if ( $parsed ) {
                $resolved = '/comment ' . $parsed['member_name'] . ' | ' . $parsed['comment_summary'];
                if ( ! empty( $parsed['category_name'] ) ) {
                    $resolved .= ' | ' . $parsed['category_name'];
                }
                INPURSUIT_WA_Logger::info( 'AI Router (comment): "' . $text . '" → "' . $resolved . '"' );
                return $resolved;
            }
            // Parsing failed — fall back so keyword_route or help handles it
            INPURSUIT_WA_Logger::warning( 'AI Router: comment field parsing failed for: "' . $text . '"' );
            return null;
        }

        // ── All other commands ────────────────────────────────────────────────
        $arg      = isset( $args['arg'] ) ? trim( $args['arg'] ) : '';
        $resolved = $arg ? $command . ' ' . $arg : $command;

        INPURSUIT_WA_Logger::info( 'AI Router: "' . $text . '" → "' . $resolved . '"' );

        return $resolved;
    }

    /**
     * Keyword-based fallback routing — no API key required.
     * Handles plain-text variants of every command so the bot is still usable
     * when OpenAI is not configured.
     *
     * @param  string $text
     * @return string|null  Canonical slash command, or null if no match.
     */
    public static function keyword_route( $text ) {
        $lower = strtolower( trim( $text ) );

        // ── No-argument commands ──────────────────────────────────────────────
        $no_arg = array(
            // /stats
            'stats'            => '/stats',
            'stat'             => '/stats',
            'statistics'       => '/stats',
            'summary'          => '/stats',
            'overview'         => '/stats',

            // /followup
            'followup'         => '/followup',
            'follow up'        => '/followup',
            'follow-up'        => '/followup',
            'pending'          => '/followup',
            'needs follow up'  => '/followup',
            'needs followup'   => '/followup',

            // /members
            'members'          => '/members',
            'list members'     => '/members',
            'member list'      => '/members',
            'all members'      => '/members',
            'show members'     => '/members',

            // /events
            'events'           => '/events',
            'birthdays'        => '/events',
            'birthday'         => '/events',
            'anniversaries'    => '/events',
            'special dates'    => '/events',
            'this month'       => '/events',

            // /categories
            'categories'            => '/categories',
            'comment categories'    => '/categories',
            'list categories'       => '/categories',

            // /help
            'help'             => '/help',
            'commands'         => '/help',
            'what can you do'  => '/help',
        );

        if ( isset( $no_arg[ $lower ] ) ) {
            INPURSUIT_WA_Logger::info( 'Keyword Router: "' . $text . '" → "' . $no_arg[ $lower ] . '"' );
            return $no_arg[ $lower ];
        }

        // ── Commands that take a name / event argument ────────────────────────

        // /member <name>  — "member John", "find John", "search John", "look up John"
        foreach ( array( 'member ', 'find ', 'search ', 'look up ', 'lookup ', 'show me ', 'who is ' ) as $prefix ) {
            if ( strpos( $lower, $prefix ) === 0 ) {
                $arg = trim( substr( $text, strlen( $prefix ) ) );
                if ( $arg ) {
                    $resolved = '/member ' . $arg;
                    INPURSUIT_WA_Logger::info( 'Keyword Router: "' . $text . '" → "' . $resolved . '"' );
                    return $resolved;
                }
            }
        }

        // /status <name>  — "status John"
        if ( strpos( $lower, 'status ' ) === 0 ) {
            $arg = trim( substr( $text, 7 ) );
            if ( $arg ) {
                $resolved = '/status ' . $arg;
                INPURSUIT_WA_Logger::info( 'Keyword Router: "' . $text . '" → "' . $resolved . '"' );
                return $resolved;
            }
        }

        // /attendance <event>  — "attendance Sunday Service"
        if ( strpos( $lower, 'attendance ' ) === 0 ) {
            $arg = trim( substr( $text, 11 ) );
            if ( $arg ) {
                $resolved = '/attendance ' . $arg;
                INPURSUIT_WA_Logger::info( 'Keyword Router: "' . $text . '" → "' . $resolved . '"' );
                return $resolved;
            }
        }

        // /comment <name> | <text> | <category>  — "comment John | text" or "add comment John | text"
        foreach ( array( 'comment ', 'add comment ' ) as $prefix ) {
            if ( strpos( $lower, $prefix ) === 0 ) {
                $arg = trim( substr( $text, strlen( $prefix ) ) );
                if ( $arg ) {
                    $resolved = '/comment ' . $arg;
                    INPURSUIT_WA_Logger::info( 'Keyword Router: "' . $text . '" → "' . $resolved . '"' );
                    return $resolved;
                }
            }
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private static function system_prompt() {
        return <<<PROMPT
You are a routing assistant for a church management WhatsApp bot.
Your only job is to call the route_command function with the correct command and optional argument.

Available commands:
  /members       — List members (no argument needed)
  /member        — Search for a member by name (arg = member name)
  /status        — Get a member's follow-up status (arg = member name)
  /comment       — Add a comment to a member (arg = "name | comment text" or "name | comment text | category")
  /categories    — List available comment categories (no argument)
  /events        — Show birthdays and weddings this month (no argument)
  /attendance    — Get attendance stats for an event (arg = event name)
  /followup      — List members who need follow-up (no argument)
  /stats         — Show overall statistics summary (no argument)
  /help          — Show the list of available commands (no argument)

Rules:
- Always call route_command — never reply with plain text.
- If you cannot confidently match the intent, use /help.
- Names and event names go in the "arg" field exactly as the user typed them.
PROMPT;
    }

    /**
     * Second AI call: extract member name, comment summary, and category
     * from a freeform message. Categories are fetched from the DB and passed
     * to OpenAI so it can match the closest one by name.
     *
     * @param  string $text     The raw user message.
     * @param  string $api_key  OpenAI API key (already validated by caller).
     * @return array|null  ['member_name', 'comment_summary', 'category_name'] or null on failure.
     */
    private static function parse_comment_fields( $text, $api_key ) {
        // Fetch available categories from the DB
        $cat_db         = INPURSUIT_DB_COMMENTS_CATEGORY::getInstance();
        $category_rows  = $cat_db->get_results( $cat_db->getResultsQuery( array() ) );
        $category_names = wp_list_pluck( $category_rows, 'name' );
        $categories_str = ! empty( $category_names )
            ? implode( ', ', $category_names )
            : 'No categories available';

        $system = "You are a data extraction assistant for a church management system.\n"
            . "Extract structured comment data from the user's message.\n\n"
            . "Available comment categories: {$categories_str}\n\n"
            . "Rules:\n"
            . "- member_name: the name of the church member the comment is about.\n"
            . "- comment_summary: a concise 1–2 sentence factual summary of the comment.\n"
            . "- category_name: pick the best matching category name exactly as listed above, or leave empty if none fit.";

        $payload = array(
            'model'       => self::MODEL,
            'messages'    => array(
                array( 'role' => 'system', 'content' => $system ),
                array( 'role' => 'user',   'content' => $text ),
            ),
            'tools'       => array( self::comment_parse_tool() ),
            'tool_choice' => array(
                'type'     => 'function',
                'function' => array( 'name' => 'parse_comment' ),
            ),
            'temperature' => 0,
        );

        $response = wp_remote_post( self::API_URL, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $payload ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            INPURSUIT_WA_Logger::error( 'AI Router (comment parse): HTTP error — ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 || empty( $body ) ) {
            INPURSUIT_WA_Logger::error( 'AI Router (comment parse): OpenAI returned status ' . $status );
            return null;
        }

        $tool_calls = $body['choices'][0]['message']['tool_calls'] ?? array();
        if ( empty( $tool_calls ) ) {
            INPURSUIT_WA_Logger::warning( 'AI Router (comment parse): No tool call returned.' );
            return null;
        }

        $parsed = json_decode( $tool_calls[0]['function']['arguments'] ?? '{}', true );

        if ( empty( $parsed['member_name'] ) || empty( $parsed['comment_summary'] ) ) {
            INPURSUIT_WA_Logger::warning( 'AI Router (comment parse): Missing required fields in response.' );
            return null;
        }

        INPURSUIT_WA_Logger::info(
            'AI Router (comment parse): member="' . $parsed['member_name'] . '"'
            . ' category="' . ( $parsed['category_name'] ?? '' ) . '"'
            . ' summary="' . $parsed['comment_summary'] . '"'
        );

        return array(
            'member_name'     => trim( $parsed['member_name'] ),
            'comment_summary' => trim( $parsed['comment_summary'] ),
            'category_name'   => trim( $parsed['category_name'] ?? '' ),
        );
    }

    /**
     * OpenAI function calling tool definition for comment field extraction.
     */
    private static function comment_parse_tool() {
        return array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'parse_comment',
                'description' => 'Extract structured comment fields from a freeform message about a church member.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'member_name' => array(
                            'type'        => 'string',
                            'description' => 'The name of the church member this comment is about.',
                        ),
                        'comment_summary' => array(
                            'type'        => 'string',
                            'description' => 'A concise 1–2 sentence summary of the comment content.',
                        ),
                        'category_name' => array(
                            'type'        => 'string',
                            'description' => 'The most appropriate category from the provided list. Leave empty if none match.',
                        ),
                    ),
                    'required' => array( 'member_name', 'comment_summary' ),
                ),
            ),
        );
    }

    private static function tool_definition() {
        return array(
            'type'     => 'function',
            'function' => array(
                'name'        => 'route_command',
                'description' => 'Route the user message to the correct bot command.',
                'parameters'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'command' => array(
                            'type'        => 'string',
                            'enum'        => array( '/members', '/member', '/status', '/comment', '/categories', '/events', '/attendance', '/followup', '/stats', '/help' ),
                            'description' => 'The command to execute.',
                        ),
                        'arg' => array(
                            'type'        => 'string',
                            'description' => 'Argument for the command (member name, event name, etc.). Omit if not needed.',
                        ),
                    ),
                    'required' => array( 'command' ),
                ),
            ),
        );
    }
}
