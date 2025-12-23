<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$opts = get_option('indexnow_options', []);
$purge = is_array($opts) && !empty($opts['purge_on_uninstall']);

if (!$purge) {
    // Default: keep settings. “Delete plugin” shouldn’t also mean “delete evidence”.
    return;
}

delete_option('indexnow_options');
delete_option('indexnow_db_version');
delete_option('indexnow_last_submit');
delete_option('indexnow_last_result');

delete_transient('indexnow_queue');
