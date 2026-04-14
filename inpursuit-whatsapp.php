<?php
/**
 * Plugin Name: InPursuit WhatsApp Bot
 * Plugin URI:  https://github.com/samvthom16/InPursuit
 * Description: WhatsApp bot add-on for InPursuit. Allows the admin team to query church member data via WhatsApp using Meta Cloud API.
 * Version:     1.0.0
 * Author:      Samuel Thomas
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

define( 'INPURSUIT_WA_VERSION', '1.0.0' );
define( 'INPURSUIT_WA_FILE', __FILE__ );
define( 'INPURSUIT_WA_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Check that the parent InPursuit plugin is active before loading.
 */
function inpursuit_wa_check_dependencies() {
    if ( ! defined( 'INPURSUIT_VERSION' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>InPursuit WhatsApp Bot</strong> requires the <strong>InPursuit</strong> plugin to be installed and active.</p></div>';
        } );
        return false;
    }
    return true;
}

function inpursuit_wa_init() {
    if ( ! inpursuit_wa_check_dependencies() ) {
        return;
    }

    require_once INPURSUIT_WA_DIR . 'includes/class-wa-logger.php';
    require_once INPURSUIT_WA_DIR . 'includes/class-wa-auth.php';
    require_once INPURSUIT_WA_DIR . 'includes/class-wa-api.php';
    require_once INPURSUIT_WA_DIR . 'includes/class-wa-query-handler.php';
    require_once INPURSUIT_WA_DIR . 'includes/class-wa-command-parser.php';
    require_once INPURSUIT_WA_DIR . 'includes/class-wa-webhook.php';
    require_once INPURSUIT_WA_DIR . 'admin/class-wa-settings.php';

    INPURSUIT_WA_Webhook::get_instance();
    INPURSUIT_WA_Settings::get_instance();
}
add_action( 'plugins_loaded', 'inpursuit_wa_init' );
