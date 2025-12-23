<?php
namespace TABARC\IndexNow;

if (!defined('ABSPATH')) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if (self::$instance === null) {
			self::$instance = new Plugin();
		}
		return self::$instance;
	}

	public function init(): void {
		require_once TABARC_INDEXNOW_DIR . 'includes/class-tabarc-indexnow-settings.php';
		require_once TABARC_INDEXNOW_DIR . 'includes/class-tabarc-indexnow-sender.php';
		require_once TABARC_INDEXNOW_DIR . 'includes/class-tabarc-indexnow-queue.php';

		Settings::init();
		Queue::init();

		// I keep this hook list short on purpose. Every hook is a new way to be wrong.
		add_action('transition_post_status', [Queue::class, 'on_transition_post_status'], 10, 3);
		add_action('deleted_post', [Queue::class, 'on_deleted_post'], 10, 2);

		// Flush queue late. If the request dies early, that’s life. We are not a bank.
		add_action('shutdown', [Queue::class, 'flush'], 0);

		add_filter('plugin_action_links_' . plugin_basename(TABARC_INDEXNOW_FILE), [$this, 'plugin_action_links']);
	}

	public function plugin_action_links(array $links): array {
		if (current_user_can('manage_options')) {
			$url = admin_url('options-general.php?page=tabarc-indexnow');
			array_unshift($links, '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'tabarc-indexnow') . '</a>');
		}
		return $links;
	}
}
