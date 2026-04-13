<?php
defined( 'ABSPATH' ) || exit;

/**
 * Checks whether an incoming WhatsApp number is allowed to use the bot.
 */
class INPURSUIT_WA_Auth {

    /**
     * @param string $phone  Phone number as provided by Meta (no + prefix, e.g. "447911123456")
     * @return bool
     */
    public static function is_allowed( $phone ) {
        $allowed = INPURSUIT_WA_Settings::get_allowed_numbers();

        // If whitelist is empty, allow anyone
        if ( empty( $allowed ) ) {
            return true;
        }

        // Normalise: strip leading + or spaces
        $phone = ltrim( trim( $phone ), '+' );

        foreach ( $allowed as $allowed_number ) {
            $normalised = ltrim( trim( $allowed_number ), '+' );
            if ( $normalised === $phone ) {
                return true;
            }
        }

        return false;
    }
}
