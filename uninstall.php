<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$opts = get_option('tabarc_indexnow_options', []);
$purge = is_array($opts) && !empty($opts['purge_uninstall']);

if (!$purge) {
    // Default: keep settings. “Delete plugin” shouldn’t also mean “delete evidence”.
    return;
}

delete_option('tabarc_indexnow_options');
delete_option('tabarc_indexnow_db_version');
delete_transient('tabarc_indexnow_sitemap_last');

// Keys used by the older, unloaded class set.
delete_option('indexnow_options');
delete_option('indexnow_db_version');
delete_option('indexnow_last_submit');
delete_option('indexnow_last_result');

delete_transient('indexnow_queue');
