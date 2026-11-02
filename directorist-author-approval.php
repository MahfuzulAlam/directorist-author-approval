<?php
/**
 * Plugin Name: Directorist Author Registration Approval
 * Description: Adds administrator approval to Directorist Author requests and listing submission.
 * Version: 1.0.1
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Requires Plugins: directorist
 * Author: Booking Local
 * Text Domain: directorist-author-approval
 */

defined( 'ABSPATH' ) || exit;

define( 'DAA_PLUGIN_FILE', __FILE__ );
define( 'DAA_PLUGIN_VERSION', '1.0.1' );

require_once __DIR__ . '/includes/class-daa-plugin.php';
require_once __DIR__ . '/includes/class-daa-admin.php';
require_once __DIR__ . '/includes/class-daa-settings.php';

add_action( 'plugins_loaded', array( 'DAA_Plugin', 'boot' ), 30 );
