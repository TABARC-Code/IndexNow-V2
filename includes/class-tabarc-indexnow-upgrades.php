<?php
namespace TABARC\IndexNow;

if (!defined('ABSPATH')) {
	exit;
}

final class Upgrades {

	private const DB_KEY = 'tabarc_indexnow_db_version';

	public static function activate(): void {
		self::maybe_run(true);
	}

	public static function deactivate(): void {
		// No teardown. Deactivation should be reversible.
	}

	public static function maybe_run(bool $force = false): void {
		$installed = (string) get_option(self::DB_KEY, '0');
		$current   = (string) TABARC_INDEXNOW_VERSION;

		if (!$force && version_compare($installed, $current, '>=')) {
			return;
		}

		self::migrate($installed, $current);

		update_option(self::DB_KEY, $current, false);
	}

	private static function migrate(string $from, string $to): void {
		// v1.0.0.2: first “real” version. Nothing to migrate.
		// The boring part is the best part. Migrations mean you messed up. (We all do.)
	}
}
