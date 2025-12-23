<?php
namespace TABARC\IndexNow;

if (!defined('ABSPATH')) {
	exit;
}

final class Queue {

	/**
	 * Request-scoped queue. We keep it in memory to avoid option writes on every save_post.
	 * If you need durability, you’re in “background jobs” territory. That’s a different plugin.
	 */
	private static array $urls = [];

	public static function init(): void {
		// Nothing yet. Placeholder so we can evolve without touching bootstrap again.
	}

	public static function on_transition_post_status(string $new_status, string $old_status, \WP_Post $post): void {
		$opt = Settings::get();

		if (empty($opt['enabled'])) {
			return;
		}

		// Don’t submit for autosaves/revisions. WP does enough weird stuff without our help.
		if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
			return;
		}

		// Only publish transitions and published updates.
		$publishish = ($new_status === 'publish' || $new_status === 'private');
		$was_publishish = ($old_status === 'publish' || $old_status === 'private');

		if (!$publishish && !$was_publishish) {
			return;
		}

		// Only allowed post types.
		$post_type = $post->post_type ?: '';
		if (!in_array($post_type, $opt['post_types'], true)) {
			return;
		}

		// Capability check: if current user can’t edit it, they shouldn’t trigger indexing.
		// Edge case: programmatic updates (CLI, cron) may have no current user. We allow those.
		if (is_user_logged_in() && !current_user_can('edit_post', $post->ID)) {
			return;
		}

		$url = get_permalink($post->ID);
		if (!$url) {
			return;
		}

		self::enqueue($url);

		// Optional sitemap submit (rate-limited inside Sender).
		Sender::submit_sitemap_if_enabled();
	}

	public static function on_deleted_post(int $post_id, \WP_Post $post): void {
		$opt = Settings::get();
		if (empty($opt['enabled'])) {
			return;
		}

		$post_type = $post->post_type ?: '';
		if (!in_array($post_type, $opt['post_types'], true)) {
			return;
		}

		// For deletions, get_permalink may fail because the post is gone-ish.
		// We try to build the URL from the old data if possible.
		$url = get_permalink($post_id);
		if (!$url) {
			// Last-ditch: if it had a GUID, it’s better than nothing.
			$url = !empty($post->guid) ? esc_url_raw($post->guid) : '';
		}

		if ($url) {
			self::enqueue($url);
		}

		Sender::submit_sitemap_if_enabled();
	}

	public static function enqueue(string $url): void {
		$url = esc_url_raw($url);
		if ($url === '') {
			return;
		}

		// Dedupe in memory.
		self::$urls[$url] = true;
	}

	public static function flush(): void {
		if (!self::$urls) {
			return;
		}

		$urls = array_keys(self::$urls);
		self::$urls = [];

		$res = Sender::submit_urls($urls);

		// If it fails, we don’t requeue. It’s a deliberate choice.
		// Otherwise the site becomes a queue that never drains, and you’ll hate me later.
		// TODO (if needed): persist failed URLs with an upper bound and manual retry button.
		(void) $res;
	}
}
