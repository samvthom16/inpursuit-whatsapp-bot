<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the REST webhook endpoint that Meta calls.
 *
 * GET  /wp-json/inpursuit-wa/v1/webhook  — Meta verification handshake
 * POST /wp-json/inpursuit-wa/v1/webhook  — Incoming messages
 */
class INPURSUIT_WA_Webhook {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
        register_rest_route( 'inpursuit-wa/v1', '/webhook', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'verify_webhook' ),
                'permission_callback' => '__return_true',
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_message' ),
                'permission_callback' => '__return_true',
            ),
        ) );
    }

    // -------------------------------------------------------------------------
    // GET — Meta webhook verification
    // -------------------------------------------------------------------------

    public function verify_webhook( WP_REST_Request $request ) {
        $mode      = $request->get_param( 'hub_mode' );
        $token     = $request->get_param( 'hub_verify_token' );
        $challenge = $request->get_param( 'hub_challenge' );

        $saved_token = INPURSUIT_WA_Settings::get( 'verify_token' );

        if ( $mode === 'subscribe' && $token === $saved_token ) {
            // Respond with challenge as plain integer (Meta requirement)
            return new WP_REST_Response( (int) $challenge, 200 );
        }

        return new WP_REST_Response( 'Forbidden', 403 );
    }

    // -------------------------------------------------------------------------
    // POST — Incoming WhatsApp messages
    // -------------------------------------------------------------------------

    public function handle_message( WP_REST_Request $request ) {
        $body = $request->get_json_params();

        // Only handle WhatsApp Business Account entries
        if ( empty( $body['object'] ) || $body['object'] !== 'whatsapp_business_account' ) {
            return new WP_REST_Response( 'ok', 200 );
        }

        foreach ( $body['entry'] ?? array() as $entry ) {
            foreach ( $entry['changes'] ?? array() as $change ) {
                $value = $change['value'] ?? array();

                foreach ( $value['messages'] ?? array() as $message ) {
                    $this->process_message( $message, $value['metadata'] ?? array() );
                }
            }
        }

        // Always return 200 quickly so Meta doesn't retry
        return new WP_REST_Response( 'ok', 200 );
    }

    // -------------------------------------------------------------------------
    // Process a single incoming message
    // -------------------------------------------------------------------------

    private function process_message( array $message, array $metadata ) {
        // We only handle text messages
        if ( ( $message['type'] ?? '' ) !== 'text' ) {
            return;
        }

        $from       = $message['from'];          // sender's phone number
        $message_id = $message['id'];
        $text       = trim( $message['text']['body'] ?? '' );

        // Mark as read immediately
        INPURSUIT_WA_API::mark_as_read( $message_id );

        // Check if sender is authorised
        if ( ! INPURSUIT_WA_Auth::is_allowed( $from ) ) {
            INPURSUIT_WA_API::send_text( $from, "Sorry, you are not authorised to use this bot." );
            return;
        }

        // Parse the command and get a response
        $response = INPURSUIT_WA_Command_Parser::handle( $text );

        INPURSUIT_WA_API::send_text( $from, $response );
    }
}
