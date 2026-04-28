<?php
defined( 'ABSPATH' ) || exit;

/**
 * AI Agent Mode — full conversational agent using OpenAI tool calling.
 * The AI can call DB query tools directly to answer arbitrary plain-English questions.
 * Group access is always enforced in PHP inside each tool — the AI cannot bypass it.
 */
class INPURSUIT_WA_AI_Agent {

    const API_URL   = 'https://api.openai.com/v1/chat/completions';
    const MODEL     = 'gpt-4o-mini';
    const MAX_STEPS = 5;

    /**
     * Handle a plain-English message.
     * Loads session history from a transient, sends it to OpenAI, then saves the updated history.
     * Returns a formatted string reply, or null if the API key is not configured or the call fails.
     *
     * @param  string       $text
     * @param  WP_User|null $wp_user
     * @param  string       $phone    Sender phone number — used to key the session transient.
     * @return string|null
     */
    public static function handle( $text, WP_User $wp_user = null, $phone = '' ) {
        $api_key = INPURSUIT_WA_Settings::get( 'openai_api_key' );
        if ( empty( $api_key ) ) {
            return null;
        }

        $history  = self::load_session( $phone );
        $messages = array_merge(
            array( array( 'role' => 'system', 'content' => self::system_prompt( $wp_user ) ) ),
            $history,
            array( array( 'role' => 'user', 'content' => $text ) )
        );

        $comment_added = false;

        for ( $step = 0; $step < self::MAX_STEPS; $step++ ) {
            $response = wp_remote_post( self::API_URL, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode( array(
                    'model'       => self::MODEL,
                    'messages'    => $messages,
                    'tools'       => self::tool_definitions(),
                    'temperature' => 0.2,
                ) ),
                'timeout' => 20,
            ) );

            if ( is_wp_error( $response ) ) {
                INPURSUIT_WA_Logger::error( 'AI Agent: HTTP error — ' . $response->get_error_message() );
                return null;
            }

            $status = wp_remote_retrieve_response_code( $response );
            $body   = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( $status !== 200 || empty( $body ) ) {
                $oai_error = isset( $body['error']['message'] ) ? $body['error']['message'] : wp_remote_retrieve_body( $response );
                INPURSUIT_WA_Logger::error( 'AI Agent: OpenAI returned status ' . $status . ' — ' . $oai_error );
                return null;
            }

            $message = $body['choices'][0]['message'] ?? null;
            if ( ! $message ) {
                INPURSUIT_WA_Logger::error( 'AI Agent: No message in response.' );
                return null;
            }

            // Text reply — done (may be an answer or a clarifying question)
            if ( ! empty( $message['content'] ) && empty( $message['tool_calls'] ) ) {
                INPURSUIT_WA_Logger::info( 'AI Agent: resolved in ' . ( $step + 1 ) . ' step(s) for: "' . $text . '"' );
                $history[] = array( 'role' => 'user',      'content' => $text );
                $history[] = array( 'role' => 'assistant', 'content' => $message['content'] );

                if ( $comment_added ) {
                    self::clear_session( $phone );
                } else {
                    self::save_session( $phone, $history );
                }

                return $message['content'];
            }

            // Tool calls — execute and continue loop
            if ( ! empty( $message['tool_calls'] ) ) {
                $messages[] = $message;

                foreach ( $message['tool_calls'] as $tc ) {
                    $fn_name = $tc['function']['name'] ?? '';
                    $fn_args = json_decode( $tc['function']['arguments'] ?? '{}', true );
                    $tool_id = $tc['id'] ?? '';

                    INPURSUIT_WA_Logger::info( 'AI Agent: calling tool "' . $fn_name . '"' );

                    $result = self::dispatch_tool( $fn_name, (array) $fn_args, $wp_user );

                    if ( $fn_name === 'add_member_comment' && ! empty( $result['success'] ) ) {
                        $comment_added = true;
                    }

                    $messages[] = array(
                        'role'         => 'tool',
                        'tool_call_id' => $tool_id,
                        'content'      => wp_json_encode( $result ),
                    );
                }

                continue;
            }

            break; // Unexpected response shape
        }

        INPURSUIT_WA_Logger::warning( 'AI Agent: max steps reached for: "' . $text . '"' );
        return null;
    }

    // -------------------------------------------------------------------------
    // Session state — WordPress transients, 5-minute expiry
    // -------------------------------------------------------------------------

    /**
     * Load prior conversation turns for this phone number.
     * Returns an empty array if no session exists or the transient has expired.
     */
    private static function load_session( $phone ) {
        if ( empty( $phone ) ) {
            return array();
        }
        $history = get_transient( 'inpursuit_wa_sess_' . md5( $phone ) );
        return is_array( $history ) ? $history : array();
    }

    /**
     * Persist conversation turns for this phone number.
     * Capped at the last 20 entries (10 user+assistant pairs) to keep transient size bounded.
     * TTL is reset to 5 minutes from now on every save.
     */
    private static function clear_session( $phone ) {
        if ( ! empty( $phone ) ) {
            delete_transient( 'inpursuit_wa_sess_' . md5( $phone ) );
        }
    }

    private static function save_session( $phone, $history ) {
        if ( empty( $phone ) ) {
            return;
        }
        if ( count( $history ) > 20 ) {
            $history = array_slice( $history, -20 );
        }
        set_transient( 'inpursuit_wa_sess_' . md5( $phone ), $history, 300 );
    }

    // -------------------------------------------------------------------------
    // Tool dispatch
    // -------------------------------------------------------------------------

    private static function dispatch_tool( $name, $args, $wp_user ) {
        switch ( $name ) {
            case 'get_member_details':
                return INPURSUIT_WA_DB_Tools::get_member_details( $args, $wp_user );
            case 'get_events':
                return INPURSUIT_WA_DB_Tools::get_events( $args, $wp_user );
            case 'add_member_comment':
                return INPURSUIT_WA_DB_Tools::add_member_comment( $args, $wp_user );
            case 'get_member_comments':
                return INPURSUIT_WA_DB_Tools::get_member_comments( $args, $wp_user );
            case 'get_comment_categories':
                return INPURSUIT_WA_DB_Tools::get_comment_categories( $args, $wp_user );
            default:
                return array( 'error' => "Unknown tool: {$name}" );
        }
    }

    // -------------------------------------------------------------------------
    // System prompt
    // -------------------------------------------------------------------------

    private static function system_prompt( $wp_user ) {
        $prompt = "You are a helpful assistant for InPursuit, a church management system. "
            . "You help church administrators with four tasks via WhatsApp: "
            . "looking up member details, checking upcoming special dates (birthdays and anniversaries in the next 30 days), viewing a member's follow-up note history, and adding follow-up notes to members. "
            . "Use the provided tools to answer questions. Always call a tool before answering data questions — never invent data. "
            . "Keep replies concise and formatted for WhatsApp (use *bold* for names and headings, bullet points with •). "
            . "If a query returns no results, say so clearly. "
            . "Never expose internal IDs, table names, or technical details in your reply. "
            . "If the user asks about something outside these four tasks, politely let them know what you can help with.\n\n"
            . "When you call get_member_comments, do not list every note individually. Instead, write a 3-4 line summary of the key themes, patterns, or recent developments from those notes — as if briefing a pastor before a visit.\n\n"
            . "IMPORTANT: The only write operation permitted is add_member_comment. "
            . "Never attempt to modify, delete, or create any other data. "
            . "All member retrieval is automatically scoped to this user's permitted groups — you must never try to access members outside these groups.\n\n"
            . "If you do not have enough information to call a tool accurately — for example, the user said \"tell me about him\" "
            . "but no name has been mentioned — ask a short, focused question to get the missing detail. "
            . "Do not guess. Do not call a tool with an empty or made-up argument. Keep the question to one sentence.\n\n"
            . "IMPORTANT: Before calling add_member_comment, you must have all three of the following confirmed:\n"
            . "1. The member's name\n"
            . "2. The comment category (call get_comment_categories to get the list, then pick the best match)\n"
            . "3. The note text\n"
            . "If any of these are missing or unclear, ask the user for the missing detail before proceeding. "
            . "Ask for one missing piece at a time. Never invent a category name — always pick from the list returned by get_comment_categories.";

        if ( $wp_user ) {
            $group_term_ids = get_user_meta( $wp_user->ID, 'inpursuit-group', true );
            if ( is_array( $group_term_ids ) && ! empty( $group_term_ids ) ) {
                $group_names = array();
                foreach ( $group_term_ids as $tid ) {
                    $term = get_term( (int) $tid, 'inpursuit-group' );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $group_names[] = $term->name;
                    }
                }
                if ( ! empty( $group_names ) ) {
                    $prompt .= "\n\nThis user is restricted to the following group(s): " . implode( ', ', $group_names ) . ". "
                             . "The tools automatically enforce this restriction — you do not need to filter manually.";
                }
            } else {
                $prompt .= "\n\nThis user has access to all groups.";
            }
        }

        return $prompt;
    }

    // -------------------------------------------------------------------------
    // Tool definitions
    // -------------------------------------------------------------------------

    private static function tool_definitions() {
        return array(
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'get_member_details',
                    'description' => 'Get the full profile of a single member by name.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'name' => array( 'type' => 'string', 'description' => 'The member\'s name (partial names supported).' ),
                        ),
                        'required' => array( 'name' ),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'get_events',
                    'description' => 'Get all upcoming special dates (birthdays and anniversaries) in the next 30 days. Use this for any question about birthdays, anniversaries, wedding anniversaries, or special dates.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => new stdClass(),
                        'required'   => array(),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'add_member_comment',
                    'description' => 'Save a follow-up note or comment for a member, with a category.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'member_name'   => array( 'type' => 'string', 'description' => 'The member\'s name.' ),
                            'comment_text'  => array( 'type' => 'string', 'description' => 'The note or comment text.' ),
                            'category_name' => array( 'type' => 'string', 'description' => 'Category name from get_comment_categories.' ),
                        ),
                        'required' => array( 'member_name', 'comment_text' ),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'get_member_comments',
                    'description' => 'Get the 10 most recent follow-up notes for a specific member, with dates and categories. Use this when the user asks to see a member\'s notes, follow-up history, or comment history. After receiving the results, summarise the key themes and patterns in 3-4 lines.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => array(
                            'name' => array( 'type' => 'string', 'description' => 'The member\'s name (partial names supported).' ),
                        ),
                        'required' => array( 'name' ),
                    ),
                ),
            ),
            array(
                'type'     => 'function',
                'function' => array(
                    'name'        => 'get_comment_categories',
                    'description' => 'Get all available comment categories. Call this before add_member_comment to pick the right category.',
                    'parameters'  => array(
                        'type'       => 'object',
                        'properties' => new stdClass(),
                        'required'   => array(),
                    ),
                ),
            ),
        );
    }
}
