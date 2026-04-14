<?php
defined( 'ABSPATH' ) || exit;

/**
 * Manages the wp_ip_wa_users table.
 *
 * Schema:
 *   ID       — auto-increment primary key
 *   user_id  — FK to wp_users.ID (one per WP user)
 *   phone    — WhatsApp number in international format, no + (unique)
 */
class INPURSUIT_WA_User_Table {

    const TABLE_SLUG = 'ip_wa_users';

    // -------------------------------------------------------------------------
    // Table management
    // -------------------------------------------------------------------------

    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SLUG;
    }

    /**
     * Create the table (called on plugin activation).
     */
    public static function create_table() {
        global $wpdb;
        $table           = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            ID       BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id  BIGINT(20) UNSIGNED NOT NULL,
            phone    VARCHAR(30)         NOT NULL,
            PRIMARY KEY (ID),
            UNIQUE KEY user_id (user_id),
            UNIQUE KEY phone   (phone)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    // -------------------------------------------------------------------------
    // Lookups
    // -------------------------------------------------------------------------

    /**
     * Resolve an incoming phone number to a WP_User.
     *
     * @param  string       $phone  Number as sent by Meta (no +, e.g. "447911123456")
     * @return WP_User|null
     */
    public static function get_user_by_phone( $phone ) {
        global $wpdb;
        $phone = ltrim( trim( $phone ), '+' );
        $table = self::get_table_name();

        $user_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE phone = %s LIMIT 1",
            $phone
        ) );

        if ( ! $user_id ) {
            return null;
        }

        $user = get_userdata( (int) $user_id );
        return $user ?: null;
    }

    // -------------------------------------------------------------------------
    // CRUD (used by profile page)
    // -------------------------------------------------------------------------

    /**
     * Get the phone number stored for a WP user, or empty string.
     */
    public static function get_phone_for_user( $user_id ) {
        global $wpdb;
        $table = self::get_table_name();
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT phone FROM {$table} WHERE user_id = %d LIMIT 1",
            (int) $user_id
        ) );
    }

    /**
     * Save (insert or update) the phone number for a WP user.
     * Clears the phone from any other user who currently holds it.
     *
     * @return true|WP_Error
     */
    public static function save_phone_for_user( $user_id, $phone ) {
        global $wpdb;
        $table   = self::get_table_name();
        $user_id = (int) $user_id;
        $phone   = ltrim( trim( $phone ), '+' );

        // Empty phone = remove record
        if ( $phone === '' ) {
            $wpdb->delete( $table, array( 'user_id' => $user_id ), array( '%d' ) );
            return true;
        }

        // Check if another user already owns this number
        $existing_owner = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_id FROM {$table} WHERE phone = %s AND user_id != %d LIMIT 1",
            $phone,
            $user_id
        ) );

        if ( $existing_owner ) {
            return new WP_Error( 'phone_taken', 'That WhatsApp number is already registered to another user.' );
        }

        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$table} WHERE user_id = %d LIMIT 1",
            $user_id
        ) );

        if ( $current ) {
            $wpdb->update( $table, array( 'phone' => $phone ), array( 'user_id' => $user_id ), array( '%s' ), array( '%d' ) );
        } else {
            $wpdb->insert( $table, array( 'user_id' => $user_id, 'phone' => $phone ), array( '%d', '%s' ) );
        }

        return true;
    }
}
