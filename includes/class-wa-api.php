<?php
defined( 'ABSPATH' ) || exit;

/**
 * Handles outgoing messages to Meta Cloud API.
 */
class INPURSUIT_WA_API {

    const GRAPH_URL = 'https://graph.facebook.com/v19.0';

    /**
     * Send a plain text message to a WhatsApp number.
     *
     * @param string $to      Recipient phone number (e.g. "447911123456")
     * @param string $message Message body text
     * @return bool
     */
    public static function send_text( $to, $message ) {
        $phone_number_id = INPURSUIT_WA_Settings::get( 'phone_number_id' );
        $access_token    = INPURSUIT_WA_Settings::get( 'access_token' );

        if ( empty( $phone_number_id ) || empty( $access_token ) ) {
            error_log( 'InPursuit WA: phone_number_id or access_token not configured.' );
            return false;
        }

        $url  = self::GRAPH_URL . '/' . $phone_number_id . '/messages';
        $body = array(
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'text',
            'text'              => array(
                'preview_url' => false,
                'body'        => $message,
            ),
        );

        $response = wp_remote_post( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'InPursuit WA send error: ' . $response->get_error_message() );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            error_log( 'InPursuit WA send failed (' . $code . '): ' . wp_remote_retrieve_body( $response ) );
            return false;
        }

        return true;
    }

    /**
     * Mark an incoming message as read (shows double blue ticks).
     *
     * @param string $message_id  The wamid from the incoming message
     */
    public static function mark_as_read( $message_id ) {
        $phone_number_id = INPURSUIT_WA_Settings::get( 'phone_number_id' );
        $access_token    = INPURSUIT_WA_Settings::get( 'access_token' );

        if ( empty( $phone_number_id ) || empty( $access_token ) ) {
            return;
        }

        $url  = self::GRAPH_URL . '/' . $phone_number_id . '/messages';
        $body = array(
            'messaging_product' => 'whatsapp',
            'status'            => 'read',
            'message_id'        => $message_id,
        );

        wp_remote_post( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => 10,
        ) );
    }
}
