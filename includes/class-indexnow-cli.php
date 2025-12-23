<?php
namespace IndexNow;

if (!defined('ABSPATH')) {
    exit;
}

final class CLI {

    public static function init(): void {
        \WP_CLI::add_command('indexnow', [__CLASS__, 'root']);
        \WP_CLI::add_command('indexnow submit', [__CLASS__, 'submit']);
        \WP_CLI::add_command('indexnow queue', [__CLASS__, 'queue']);
        \WP_CLI::add_command('indexnow clear', [__CLASS__, 'clear']);
        \WP_CLI::add_command('indexnow verify', [__CLASS__, 'verify']);
    }

    public static function root(): void {
        \WP_CLI::log('IndexNow ' . INDEXNOW_VERSION);
    }

    public static function submit(): void {
        update_option('indexnow_last_submit', 0, false);
        Submitter::maybe_submit();

        $last = Submitter::get_last_result();
        if (!empty($last['ok'])) {
            \WP_CLI::success('Submit OK.');
        } else {
            \WP_CLI::error('Submit failed: ' . ($last['error_message'] ?? 'unknown'));
        }
    }

    public static function queue(): void {
        \WP_CLI::log('Queue size: ' . Submitter::get_queue_count());
    }

    public static function clear(): void {
        Submitter::clear_queue();
        \WP_CLI::success('Queue cleared.');
    }

    public static function verify(): void {
        $r = Submitter::verify_key_location(Options::get());
        if (is_wp_error($r)) {
            \WP_CLI::error($r->get_error_message());
        }
        \WP_CLI::success('Key file reachable: ' . ($r['url'] ?? ''));
    }
}
