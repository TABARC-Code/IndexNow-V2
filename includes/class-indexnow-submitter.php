<?php
namespace IndexNow;

/*
 * Notes from a slightly tired maintainer:
 * - Keep surface area small. Every new setting is a future bug report.
 * - WordPress execution order is “mostly” deterministic until it isn’t.
 * - Assume outbound HTTP will fail intermittently. Handle it without drama.
 * - Nonces + capabilities are not optional. They’re the cost of doing business.
 */

use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

final class Submitter {

    private const QUEUE_KEY = 'indexnow_queue';
    private const LAST_SUBMIT_KEY = 'indexnow_last_submit';
    private const LAST_RESULT_KEY = 'indexnow_last_result';

    public static function init(): void {
        // Submit once per request, at the end. Keeps editor saves from turning into HTTP ping storms.
        add_action('shutdown', [__CLASS__, 'maybe_submit'], 1);

        // Cron fallback: when shutdown never fires (fatals, timeouts, server oddities).
        add_action('indexnow_cron_submit', [__CLASS__, 'maybe_submit']);
    }

    public static function queue_url(string $url): void {
        $url = esc_url_raw($url);
        if (!$url) {
            return;
        }

        $opts = Options::get();
        if (empty($opts['enabled'])) {
            return;
        }

        $queue = get_transient(self::QUEUE_KEY);
        $queue = is_array($queue) ? $queue : [];

        // Using the URL as the key gives dedupe “for free”. WordPress rarely gives anything for free.
        $queue[$url] = time();

        // Cap queue size. If you hit this, you’re generating more churn than IndexNow can fix anyway.
        if (count($queue) > 500) {
            $queue = array_slice($queue, -500, null, true);
        }

        set_transient(self::QUEUE_KEY, $queue, 6 * HOUR_IN_SECONDS);

        // Schedule fallback submit.
        if (!wp_next_scheduled('indexnow_cron_submit')) {
            wp_schedule_single_event(time() + 60, 'indexnow_cron_submit');
        }
    }

    public static function maybe_submit(): void {
        $opts = Options::get();
        if (empty($opts['enabled'])) {
            return;
        }

        $queue = get_transient(self::QUEUE_KEY);
        $queue = is_array($queue) ? $queue : [];

        if (empty($queue)) {
            return;
        }

        $last = (int) get_option(self::LAST_SUBMIT_KEY, 0);
        $min = (int) $opts['min_seconds_between_submits'];

        if ($min > 0 && (time() - $last) < $min) {
            // Too soon; leave the queue alone and try later.
            return;
        }

        $urls = array_keys($queue);

        $max = (int) $opts['max_urls_per_submit'];
        if (count($urls) > $max) {
            $urls = array_slice($urls, 0, $max);
        }

        $result = self::submit_urls($urls, $opts);

        if (!is_wp_error($result)) {
            foreach ($urls as $u) {
                unset($queue[$u]);
            }
            set_transient(self::QUEUE_KEY, $queue, 6 * HOUR_IN_SECONDS);
            update_option(self::LAST_SUBMIT_KEY, time(), false);
        }

        self::store_last_result($result);
    }

    public static function submit_urls(array $urls, array $opts) {
        $urls = array_values(array_filter(array_map('esc_url_raw', $urls)));
        $urls = array_values(array_unique($urls));

        if (empty($urls)) {
            return new WP_Error('indexnow_no_urls', __('No valid URLs to submit.', 'indexnow'));
        }

        $key = trim((string) ($opts['key'] ?? ''));
        if ($key === '') {
            return new WP_Error('indexnow_no_key', __('IndexNow key is not configured.', 'indexnow'));
        }

        $endpoint = esc_url_raw((string) ($opts['endpoint'] ?? ''));
        if ($endpoint === '') {
            return new WP_Error('indexnow_bad_endpoint', __('IndexNow endpoint is invalid.', 'indexnow'));
        }

        $keyLocation = '';
        if (!empty($opts['key_location'])) {
            $keyLocation = esc_url_raw((string) $opts['key_location']);
        } else {
            $keyLocation = home_url('/' . rawurlencode($key) . '.txt');
        }

        $payload = [
            'host' => wp_parse_url(home_url(), PHP_URL_HOST),
            'key' => $key,
            'keyLocation' => $keyLocation,
            'urlList' => $urls,
        ];

        $resp = wp_remote_post($endpoint, [
            'timeout' => 5,
            'headers' => [
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($resp)) {
            self::debug_log('Submit failed (transport): ' . $resp->get_error_message(), $opts);
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = (string) wp_remote_retrieve_body($resp);

        if ($code < 200 || $code >= 300) {
            self::debug_log('Submit failed (HTTP ' . $code . '): ' . substr($body, 0, 500), $opts);
            return new WP_Error('indexnow_http_error', sprintf(__('IndexNow returned HTTP %d.', 'indexnow'), $code), [
                'status' => $code,
                'body' => $body,
            ]);
        }

        self::debug_log('Submit OK: ' . count($urls) . ' URL(s)', $opts);

        return [
            'status' => $code,
            'submitted' => count($urls),
            'endpoint' => $endpoint,
            'time' => time(),
        ];
    }

    private static function store_last_result($result): void {
        $data = [
            'time' => time(),
            'ok' => !is_wp_error($result),
        ];

        if (is_wp_error($result)) {
            $data['error_code'] = $result->get_error_code();
            $data['error_message'] = $result->get_error_message();
        } else {
            $data = array_merge($data, (array) $result);
        }

        update_option(self::LAST_RESULT_KEY, $data, false);
    }

    public static function get_last_result(): array {
        $r = get_option(self::LAST_RESULT_KEY, []);
        return is_array($r) ? $r : [];
    }

    public static function get_queue_count(): int {
        $queue = get_transient(self::QUEUE_KEY);
        $queue = is_array($queue) ? $queue : [];
        return count($queue);
    }

    public static function clear_queue(): void {
        delete_transient(self::QUEUE_KEY);
    }

    public static function verify_key_location(array $opts) {
        $key = trim((string) ($opts['key'] ?? ''));
        if ($key === '') {
            return new WP_Error('indexnow_no_key', __('IndexNow key is not configured.', 'indexnow'));
        }

        $url = !empty($opts['key_location'])
            ? esc_url_raw((string) $opts['key_location'])
            : home_url('/' . rawurlencode($key) . '.txt');

        $resp = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($resp)) {
            return $resp;
        }

        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = trim((string) wp_remote_retrieve_body($resp));

        if ($code !== 200) {
            return new WP_Error('indexnow_key_not_found', sprintf(__('Key location returned HTTP %d.', 'indexnow'), $code), [
                'status' => $code,
                'url' => $url,
            ]);
        }

        $out = ['ok' => true, 'url' => $url];
        if ($body === '') {
            $out['note'] = __('Key file is reachable but empty. This may still work, but check your setup.', 'indexnow');
        }
        return $out;
    }

    private static function debug_log(string $msg, array $opts): void {
        if (empty($opts['debug'])) {
            return;
        }
        error_log('[IndexNow] ' . $msg);
    }
}
