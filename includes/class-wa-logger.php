<?php
defined( 'ABSPATH' ) || exit;

/**
 * Simple file-based logger for the InPursuit WhatsApp Bot.
 *
 * Writes to wp-content/uploads/inpursuit-wa-logs/webhook.log
 * Accessible only via WP Admin (never served directly — protected by .htaccess).
 */
class INPURSUIT_WA_Logger {

    const LOG_DIR  = 'inpursuit-wa-logs';
    const LOG_FILE = 'webhook.log';
    const MAX_BYTES = 512000; // 512 KB — rotate when exceeded

    // -------------------------------------------------------------------------
    // Public log levels
    // -------------------------------------------------------------------------

    public static function info( $message ) {
        self::write( 'INFO', $message );
    }

    public static function warning( $message ) {
        self::write( 'WARN', $message );
    }

    public static function error( $message ) {
        self::write( 'ERROR', $message );
    }

    // -------------------------------------------------------------------------
    // Core write
    // -------------------------------------------------------------------------

    private static function write( $level, $message ) {
        $path = self::get_log_path();
        if ( ! $path ) {
            return;
        }

        // Rotate if the file is getting too large
        if ( file_exists( $path ) && filesize( $path ) > self::MAX_BYTES ) {
            rename( $path, $path . '.1' );
        }

        $line = '[' . current_time( 'Y-m-d H:i:s' ) . '] [' . $level . '] ' . $message . PHP_EOL;

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
    }

    // -------------------------------------------------------------------------
    // Helpers used by the admin page
    // -------------------------------------------------------------------------

    /**
     * Return the last $count lines of the log as a single string.
     */
    public static function get_recent( $count = 150 ) {
        $path = self::get_log_path();
        if ( ! $path || ! file_exists( $path ) ) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        $contents = file_get_contents( $path );
        if ( $contents === false ) {
            return '';
        }

        $lines = explode( PHP_EOL, rtrim( $contents ) );
        $lines = array_slice( $lines, -$count );
        return implode( PHP_EOL, $lines );
    }

    /**
     * Clear the log file.
     */
    public static function clear() {
        $path = self::get_log_path();
        if ( $path && file_exists( $path ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $path, '' );
        }
        $old = $path . '.1';
        if ( file_exists( $old ) ) {
            unlink( $old );
        }
    }

    /**
     * Absolute path to the log file, creating the directory on first call.
     *
     * @return string|false
     */
    public static function get_log_path() {
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return false;
        }

        $dir = trailingslashit( $upload['basedir'] ) . self::LOG_DIR;

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
            // Protect the directory from direct browser access
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
        }

        return $dir . '/' . self::LOG_FILE;
    }
}
