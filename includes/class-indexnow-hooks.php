<?php
namespace IndexNow;

/*
 * Notes from a slightly tired maintainer:
 * - Keep surface area small. Every new setting is a future bug report.
 * - WordPress execution order is “mostly” deterministic until it isn’t.
 * - Assume outbound HTTP will fail intermittently. Handle it without drama.
 * - Nonces + capabilities are not optional. They’re the cost of doing business.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Hooks {

    public static function init(): void {
        add_action('save_post', [__CLASS__, 'on_save_post'], 20, 3);
        add_action('before_delete_post', [__CLASS__, 'on_before_delete_post'], 10, 1);
        add_action('trashed_post', [__CLASS__, 'on_trashed_post'], 10, 1);
    }

    public static function on_save_post(int $post_id, \WP_Post $post, bool $update): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $opts = Options::get();
        if (empty($opts['enabled'])) {
            return;
        }

        // Only published content. Drafts and private posts don’t belong in IndexNow submissions.
        if ($post->post_status !== 'publish') {
            return;
        }

        $allowed = is_array($opts['post_types']) ? $opts['post_types'] : ['post', 'page'];
        if (!in_array($post->post_type, $allowed, true)) {
            return;
        }

        $url = get_permalink($post_id);
        if (!$url) {
            return;
        }

        Submitter::queue_url($url);

        if (!empty($opts['submit_sitemap'])) {
            $sitemap_url = home_url($opts['sitemap_path'] ?: '/sitemap.xml');
            Submitter::queue_url($sitemap_url);
        }
    }

    public static function on_before_delete_post(int $post_id): void {
        $url = get_permalink($post_id);
        if ($url) {
            Submitter::queue_url($url);
        }
    }

    public static function on_trashed_post(int $post_id): void {
        $url = get_permalink($post_id);
        if ($url) {
            Submitter::queue_url($url);
        }
    }
}
