<?php
namespace IndexNow;

/*
 * Notes from a slightly tired maintainer:
 * - Keep surface area small. Every new setting is a future bug report.
 * - WordPress execution order is “mostly” deterministic until it isn’t.
 * - Assume outbound HTTP will fail intermittently. Handle it without drama.
 * - Nonces + capabilities are not optional. They’re the cost of doing business.
 */

use WP_REST_Response;

if (!defined('ABSPATH')) {
    exit;
}

final class Rest {

    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes(): void {
        register_rest_route('indexnow/v1', '/status', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'status'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    public static function status(): WP_REST_Response {
        return new WP_REST_Response([
            'plugin' => 'IndexNow',
            'version' => INDEXNOW_VERSION,
            'queue' => Submitter::get_queue_count(),
            'last' => Submitter::get_last_result(),
        ], 200);
    }
}
