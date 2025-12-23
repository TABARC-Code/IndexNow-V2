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

final class Upgrades {

    private const DB_KEY = 'indexnow_db_version';

    public static function activate(): void {
        self::maybe_run(true);
    }

    public static function deactivate(): void {
        // Stop scheduled fallback events.
        wp_clear_scheduled_hook('indexnow_cron_submit');
    }

    public static function maybe_run(bool $force = false): void {
        $installed = get_option(self::DB_KEY, '0');

        if (!$force && version_compare($installed, INDEXNOW_VERSION, '>=')) {
            return;
        }

        self::migrate($installed, INDEXNOW_VERSION);
        update_option(self::DB_KEY, INDEXNOW_VERSION, false);
    }

    private static function migrate(string $from, string $to): void {
        // 2.0.0.0:
        // - introduces transient-backed queue + last result option
        // - introduces cron fallback hook
        // Nothing destructive. No schema. No tables. Just less drama.
    }
}
