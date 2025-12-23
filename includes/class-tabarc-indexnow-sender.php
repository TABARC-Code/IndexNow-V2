<?php
namespace TABARC\IndexNow;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

final class Sender {

	/**
	 * Submit a batch of URLs (up to 10k per IndexNow docs, but we’ll be modest).
	 * Reference: IndexNow documentation (POST JSON to /indexnow). https://www.indexnow.org/documentation
	 */
	public static function submit_urls(array $urls): array|WP_Error {
		$opt = Settings::get();

		if (empty($opt['enabled'])) {
			return new WP_Error('tabarc_indexnow_disabled', 'IndexNow is disabled.');
		}

		if (empty($opt['indexnow_key'])) {
			return new WP_Error('tabarc_indexnow_no_key', 'IndexNow key not configured.');
		}

		$urls = array_values(array_unique(array_filter(array_map('esc_url_raw', $urls))));
		if (!$urls) {
			return new WP_Error('tabarc_indexnow_no_urls', 'No URLs to submit.');
		}

		// Don’t send infinite URLs. IndexNow says 10,000 per post; I’m not testing that on your production.
		$urls = array_slice($urls, 0, 500);

		$home = home_url('/');
		$host = wp_parse_url($home, PHP_URL_HOST);
		if (!$host) {
			return new WP_Error('tabarc_indexnow_bad_host', 'Unable to determine site host.');
		}

		$endpoint = $opt['endpoint'] ?: 'https://www.bing.com/indexnow';

		// If keyLocation is provided, append it as query parameter (supported by Bing’s IndexNow GET example).
		// For POST, the protocol still allows using endpoint query params; Bing supports keyLocation in practice.
		if (!empty($opt['key_location'])) {
			$endpoint = add_query_arg('keyLocation', rawurlencode($opt['key_location']), $endpoint);
		}

		$payload = [
			'host'    => $host,
			'key'     => $opt['indexnow_key'],
			'urlList' => $urls,
		];

		$args = [
			'timeout'     => 10,
			'redirection' => 2,
			'headers'     => [
				'Content-Type' => 'application/json; charset=utf-8',
			],
			'body'        => wp_json_encode($payload),
		];

		// Grey-hat-ish note:
		// We’re not retrying aggressively. If the endpoint is down, hammering it is just self-harm with extra steps.
		$resp = wp_remote_post($endpoint, $args);

		if (is_wp_error($resp)) {
			self::maybe_log('IndexNow request failed: ' . $resp->get_error_message());
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code($resp);
		$body = (string) wp_remote_retrieve_body($resp);

		self::maybe_log('IndexNow response: HTTP ' . $code . ' body=' . substr($body, 0, 500));

		if ($code < 200 || $code >= 300) {
			return new WP_Error('tabarc_indexnow_http_error', 'IndexNow HTTP error: ' . $code, [
				'status' => $code,
				'body'   => $body,
			]);
		}

		return [
			'status' => $code,
			'body'   => $body,
			'count'  => count($urls),
		];
	}

	public static function submit_sitemap_if_enabled(): void {
		$opt = Settings::get();
		if (empty($opt['enabled']) || empty($opt['submit_sitemap'])) {
			return;
		}

		// Rate limit: once per 12 hours. Don’t be that plugin.
		$key = 'tabarc_indexnow_sitemap_last';
		if (get_transient($key)) {
			return;
		}

		$sitemap_url = home_url($opt['sitemap_path'] ?: '/sitemap.xml');
		$res = self::submit_urls([$sitemap_url]);

		// If it fails, we still rate-limit a bit, otherwise you get a spam cannon on every request.
		set_transient($key, 1, 12 * HOUR_IN_SECONDS);

		if (is_wp_error($res)) {
			self::maybe_log('Sitemap submit failed: ' . $res->get_error_message());
		}
	}

	private static function maybe_log(string $msg): void {
		$opt = Settings::get();
		if (!empty($opt['debug_log'])) {
			error_log('[TABARC IndexNow] ' . $msg);
		}
	}
}
