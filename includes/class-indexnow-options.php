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

final class Options {

    public const KEY = 'indexnow_options';

    public static function init(): void {
        // Centralised defaults + normalisation. Nothing clever. Nothing hidden.
    }

    public static function defaults(): array {
        return [
            'enabled' => 1,
            'key' => '',
            'endpoint' => 'https://www.bing.com/indexnow',
            'key_location' => '',
            'submit_sitemap' => 0,
            'sitemap_path' => '/sitemap.xml',
            'post_types' => ['post', 'page'],
            'debug' => 0,
            'purge_on_uninstall' => 0,
            'max_urls_per_submit' => 100,
            'min_seconds_between_submits' => 10,
        ];
    }

    public static function get(): array {
        $stored = get_option(self::KEY, []);
        $stored = is_array($stored) ? $stored : [];
        $opts = wp_parse_args($stored, self::defaults());

        $opts['enabled'] = (int) !empty($opts['enabled']);
        $opts['debug'] = (int) !empty($opts['debug']);
        $opts['submit_sitemap'] = (int) !empty($opts['submit_sitemap']);
        $opts['purge_on_uninstall'] = (int) !empty($opts['purge_on_uninstall']);
        $opts['max_urls_per_submit'] = max(1, min(1000, (int) $opts['max_urls_per_submit']));
        $opts['min_seconds_between_submits'] = max(0, (int) $opts['min_seconds_between_submits']);

        if (!is_array($opts['post_types'])) {
            $opts['post_types'] = ['post', 'page'];
        }

        // Trim key (we don’t want invisible whitespace ruining someone’s day).
        $opts['key'] = trim((string) $opts['key']);

        return $opts;
    }

    public static function update(array $opts): void {
        update_option(self::KEY, $opts, false);
    }
}
