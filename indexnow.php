<?php
/**
 * Plugin Name: IndexNow
 * Plugin URI: https://github.com/TABARC-Code/
 * Description: Hardened IndexNow submission plugin for WordPress. Batching, queueing, manual tools, and paranoid defaults.
 * Version: 2.0.0.1
 * Author: TABARC-Code
 * Text Domain: tabarc-indexnow
 * Domain Path: /languages
 *
 * Branding:
 * <p align="center">
 *   <img src=".branding/tabarc-icon.svg" width="180" alt="TABARC-Code Icon">
 * </p>
 *
 * Notes (for future me, who will pretend this was all obvious):
 * - This is intentionally conservative. If you want it to be “smart”, make it opt-in smart.
 * - IndexNow wants you to host a UTF-8 key file (usually {key}.txt) on the same host.
 * - REST exposure is not needed here. Less surface area, fewer headaches.
 */

if (!defined('ABSPATH')) {
	exit;
}

define('TABARC_INDEXNOW_VERSION', '1.0.0.2');
define('TABARC_INDEXNOW_FILE', __FILE__);
define('TABARC_INDEXNOW_DIR', plugin_dir_path(__FILE__));
define('TABARC_INDEXNOW_URL', plugin_dir_url(__FILE__));

require_once TABARC_INDEXNOW_DIR . 'includes/class-tabarc-indexnow-plugin.php';

add_action('plugins_loaded', static function () {
	\TABARC\IndexNow\Plugin::instance()->init();
}, 1);

register_activation_hook(__FILE__, static function () {
	require_once TABARC_INDEXNOW_DIR . 'includes/class-tabarc-indexnow-upgrades.php';
	\TABARC\IndexNow\Upgrades::activate();
});

register_deactivation_hook(__FILE__, static function () {
	require_once TABARC_INDEXNOW_DIR . 'includes/class-tabarc-indexnow-upgrades.php';
	\TABARC\IndexNow\Upgrades::deactivate();
});
