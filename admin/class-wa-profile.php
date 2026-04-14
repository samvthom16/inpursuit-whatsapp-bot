<?php
defined( 'ABSPATH' ) || exit;

/**
 * Adds a WhatsApp phone number field to the WordPress user profile page.
 * Admins can also edit it on another user's profile.
 */
class INPURSUIT_WA_Profile {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Show the field
        add_action( 'show_user_profile',   array( $this, 'render_field' ) );
        add_action( 'edit_user_profile',   array( $this, 'render_field' ) );

        // Save the field
        add_action( 'personal_options_update',  array( $this, 'save_field' ) );
        add_action( 'edit_user_profile_update', array( $this, 'save_field' ) );
    }

    public function render_field( WP_User $user ) {
        $phone = INPURSUIT_WA_User_Table::get_phone_for_user( $user->ID );
        ?>
        <h2>InPursuit WhatsApp Bot</h2>
        <table class="form-table">
            <tr>
                <th><label for="ip_wa_phone">WhatsApp Number</label></th>
                <td>
                    <?php wp_nonce_field( 'ip_wa_save_phone_' . $user->ID, 'ip_wa_phone_nonce' ); ?>
                    <input type="text"
                           id="ip_wa_phone"
                           name="ip_wa_phone"
                           value="<?php echo esc_attr( $phone ); ?>"
                           class="regular-text"
                           placeholder="e.g. 447911123456" />
                    <p class="description">
                        International format, no + prefix (e.g. <code>447911123456</code>).<br/>
                        This number will be authorised to query the WhatsApp bot.<br/>
                        Each user can only have one number, and each number can only belong to one user.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function save_field( $user_id ) {
        // Only proceed if our nonce is present (field was actually submitted)
        if ( empty( $_POST['ip_wa_phone_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['ip_wa_phone_nonce'], 'ip_wa_save_phone_' . $user_id ) ) {
            return;
        }

        // Must be able to edit this user
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $phone  = sanitize_text_field( $_POST['ip_wa_phone'] ?? '' );
        $result = INPURSUIT_WA_User_Table::save_phone_for_user( $user_id, $phone );

        if ( is_wp_error( $result ) ) {
            // Store error in transient so we can display it after redirect
            set_transient( 'ip_wa_phone_error_' . $user_id, $result->get_error_message(), 30 );
        }
    }
}
