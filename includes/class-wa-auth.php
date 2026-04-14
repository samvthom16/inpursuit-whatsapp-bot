<?php
defined( 'ABSPATH' ) || exit;

/**
 * Authenticates incoming WhatsApp numbers against the wp_ip_wa_users table.
 */
class INPURSUIT_WA_Auth {

    /**
     * Resolve an incoming phone number to its WP_User.
     *
     * @param  string       $phone  Number as sent by Meta (no +, e.g. "447911123456")
     * @return WP_User|null  Returns the user on success, null if not registered.
     */
    public static function get_user( $phone ) {
        return INPURSUIT_WA_User_Table::get_user_by_phone( $phone );
    }

    /**
     * Get the primary role of a WP_User.
     *
     * @param  WP_User $user
     * @return string  e.g. 'administrator', 'editor', 'subscriber'
     */
    public static function get_role( WP_User $user ) {
        return ! empty( $user->roles ) ? $user->roles[0] : 'subscriber';
    }
}
