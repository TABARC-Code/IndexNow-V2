<?php
namespace IndexNow;

if (!defined('ABSPATH')) {
    exit;
}

final class Plugin {

    private static $instance;

    public static function instance(): self {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        $this->maybe_upgrade();

        require_once INDEXNOW_DIR . 'includes/class-indexnow-options.php';
        require_once INDEXNOW_DIR . 'includes/class-indexnow-admin.php';
        require_once INDEXNOW_DIR . 'includes/class-indexnow-submitter.php';
        require_once INDEXNOW_DIR . 'includes/class-indexnow-hooks.php';
        require_once INDEXNOW_DIR . 'includes/class-indexnow-rest.php';

        Options::init();
        Admin::init();
        Submitter::init();
        Hooks::init();
        Rest::init();

        if (defined('WP_CLI') && WP_CLI) {
            require_once INDEXNOW_DIR . 'includes/class-indexnow-cli.php';
            CLI::init();
        }
    }

    private function maybe_upgrade(): void {
        require_once INDEXNOW_DIR . 'includes/class-indexnow-upgrades.php';
        Upgrades::maybe_run();
    }
}
