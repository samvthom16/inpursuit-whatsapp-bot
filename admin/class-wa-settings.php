<?php
defined( 'ABSPATH' ) || exit;

/**
 * Registers the admin settings page under the InPursuit menu.
 * Stores: Phone Number ID, Access Token, Verify Token, allowed phone numbers.
 */
class INPURSUIT_WA_Settings {

    private static $instance = null;
    const OPTION_KEY = 'inpursuit_wa_settings';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function register_menu() {
        add_submenu_page(
            'inpursuit',                        // parent slug (InPursuit main menu)
            'WhatsApp Bot',
            'WhatsApp Bot',
            'manage_options',
            'inpursuit-whatsapp',
            array( $this, 'render_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'inpursuit_wa_group',
            self::OPTION_KEY,
            array( $this, 'sanitize_settings' )
        );
    }

    public function sanitize_settings( $input ) {
        $clean = array();

        $clean['phone_number_id']  = sanitize_text_field( $input['phone_number_id'] ?? '' );
        $clean['access_token']     = sanitize_text_field( $input['access_token'] ?? '' );
        $clean['verify_token']     = sanitize_text_field( $input['verify_token'] ?? '' );
        $clean['allowed_numbers']  = sanitize_textarea_field( $input['allowed_numbers'] ?? '' );

        return $clean;
    }

    public function render_page() {
        $options      = self::get_options();
        $webhook_url  = rest_url( 'inpursuit-wa/v1/webhook' );
        ?>
        <div class="wrap">
            <h1>InPursuit — WhatsApp Bot Settings</h1>

            <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:12px 16px;margin-bottom:20px;">
                <strong>Setup steps:</strong>
                <ol style="margin:8px 0 0 16px;">
                    <li>Create a Meta App at <a href="https://developers.facebook.com" target="_blank">developers.facebook.com</a></li>
                    <li>Add <em>WhatsApp</em> product to your app</li>
                    <li>Register a phone number and copy the <strong>Phone Number ID</strong></li>
                    <li>Generate a <strong>Permanent Access Token</strong> (System User in Meta Business Manager)</li>
                    <li>In WhatsApp &rarr; Configuration, set the webhook URL below and paste your <strong>Verify Token</strong></li>
                    <li>Subscribe to the <strong>messages</strong> webhook field</li>
                </ol>
            </div>

            <div style="background:#e8f5e9;border-left:4px solid #4caf50;padding:12px 16px;margin-bottom:24px;">
                <strong>Your Webhook URL:</strong><br/>
                <code style="user-select:all;"><?php echo esc_html( $webhook_url ); ?></code>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'inpursuit_wa_group' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="wa_phone_number_id">Phone Number ID</label></th>
                        <td>
                            <input type="text" id="wa_phone_number_id" name="<?php echo self::OPTION_KEY; ?>[phone_number_id]"
                                value="<?php echo esc_attr( $options['phone_number_id'] ); ?>" class="regular-text" />
                            <p class="description">Found in Meta App &rarr; WhatsApp &rarr; API Setup.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wa_access_token">Access Token</label></th>
                        <td>
                            <input type="password" id="wa_access_token" name="<?php echo self::OPTION_KEY; ?>[access_token]"
                                value="<?php echo esc_attr( $options['access_token'] ); ?>" class="regular-text" />
                            <p class="description">Permanent system user token from Meta Business Manager.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wa_verify_token">Verify Token</label></th>
                        <td>
                            <input type="text" id="wa_verify_token" name="<?php echo self::OPTION_KEY; ?>[verify_token]"
                                value="<?php echo esc_attr( $options['verify_token'] ); ?>" class="regular-text" />
                            <p class="description">A secret string you invent — paste this same value in Meta's webhook configuration.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="wa_allowed_numbers">Allowed Phone Numbers</label></th>
                        <td>
                            <textarea id="wa_allowed_numbers" name="<?php echo self::OPTION_KEY; ?>[allowed_numbers]"
                                rows="5" class="large-text"><?php echo esc_textarea( $options['allowed_numbers'] ); ?></textarea>
                            <p class="description">
                                One number per line in international format (e.g. <code>447911123456</code>).<br/>
                                Only these numbers can query the bot. Leave blank to allow anyone.
                            </p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Static helpers used by other classes
    // -------------------------------------------------------------------------

    public static function get_options() {
        $defaults = array(
            'phone_number_id' => '',
            'access_token'    => '',
            'verify_token'    => '',
            'allowed_numbers' => '',
        );
        return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
    }

    public static function get( $key ) {
        $options = self::get_options();
        return $options[ $key ] ?? '';
    }

    /**
     * Returns an array of allowed phone numbers, or empty array if unrestricted.
     */
    public static function get_allowed_numbers() {
        $raw = self::get( 'allowed_numbers' );
        if ( empty( trim( $raw ) ) ) {
            return array();
        }
        $lines = explode( "\n", $raw );
        return array_filter( array_map( 'trim', $lines ) );
    }
}
