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

final class Admin {

    private const PAGE = 'indexnow';
    private const NONCE_ACTION = 'indexnow_admin_action';
    private const NONCE_NAME = '_indexnow_nonce';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'handle_post']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
    }

    public static function menu(): void {
        add_options_page(
            __('IndexNow', 'indexnow'),
            __('IndexNow', 'indexnow'),
            'manage_options',
            self::PAGE,
            [__CLASS__, 'render']
        );
    }

    public static function assets(string $hook): void {
        if ($hook !== 'settings_page_' . self::PAGE) {
            return;
        }

        wp_enqueue_style('indexnow-admin', INDEXNOW_URL . 'assets/admin.css', [], INDEXNOW_VERSION);
        wp_enqueue_script('indexnow-admin', INDEXNOW_URL . 'assets/admin.js', ['jquery'], INDEXNOW_VERSION, true);
    }

    public static function handle_post(): void {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        if (empty($_POST['indexnow_action'])) {
            return;
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $action = sanitize_key(wp_unslash($_POST['indexnow_action']));

        switch ($action) {
            case 'save':
                self::handle_save();
                break;

            case 'submit_now':
                self::handle_submit_now();
                break;

            case 'verify_key':
                self::handle_verify_key();
                break;

            case 'clear_queue':
                Submitter::clear_queue();
                add_settings_error('indexnow_messages', 'indexnow_queue_cleared', __('Queue cleared.', 'indexnow'), 'updated');
                break;
        }
    }

    private static function handle_save(): void {
        $raw = isset($_POST[Options::KEY]) ? (array) $_POST[Options::KEY] : [];
        $raw = wp_unslash($raw);

        $opts = Options::get();

        $opts['enabled'] = !empty($raw['enabled']) ? 1 : 0;
        $opts['key'] = isset($raw['key']) ? sanitize_text_field($raw['key']) : '';
        $opts['endpoint'] = isset($raw['endpoint']) ? esc_url_raw($raw['endpoint']) : Options::defaults()['endpoint'];
        $opts['key_location'] = isset($raw['key_location']) ? esc_url_raw($raw['key_location']) : '';
        $opts['submit_sitemap'] = !empty($raw['submit_sitemap']) ? 1 : 0;
        $opts['sitemap_path'] = isset($raw['sitemap_path']) ? '/' . ltrim(sanitize_text_field($raw['sitemap_path']), '/') : '/sitemap.xml';
        $opts['debug'] = !empty($raw['debug']) ? 1 : 0;
        $opts['purge_on_uninstall'] = !empty($raw['purge_on_uninstall']) ? 1 : 0;

        // Post types: only allow registered public types.
        $allowed_pts = get_post_types(['public' => true], 'names');
        $selected = isset($raw['post_types']) && is_array($raw['post_types']) ? array_map('sanitize_key', $raw['post_types']) : [];
        $selected = array_values(array_intersect($selected, $allowed_pts));
        $opts['post_types'] = !empty($selected) ? $selected : ['post', 'page'];

        $opts['max_urls_per_submit'] = isset($raw['max_urls_per_submit']) ? (int) $raw['max_urls_per_submit'] : $opts['max_urls_per_submit'];
        $opts['max_urls_per_submit'] = max(1, min(1000, $opts['max_urls_per_submit']));

        $opts['min_seconds_between_submits'] = isset($raw['min_seconds_between_submits']) ? (int) $raw['min_seconds_between_submits'] : $opts['min_seconds_between_submits'];
        $opts['min_seconds_between_submits'] = max(0, $opts['min_seconds_between_submits']);

        Options::update($opts);

        add_settings_error('indexnow_messages', 'indexnow_saved', __('Settings saved.', 'indexnow'), 'updated');
    }

    private static function handle_submit_now(): void {
        $queue_count = Submitter::get_queue_count();
        if ($queue_count <= 0) {
            add_settings_error('indexnow_messages', 'indexnow_no_queue', __('Queue is empty. Nothing to submit.', 'indexnow'), 'updated');
            return;
        }

        update_option('indexnow_last_submit', 0, false);
        Submitter::maybe_submit();

        $last = Submitter::get_last_result();
        if (!empty($last['ok'])) {
            add_settings_error('indexnow_messages', 'indexnow_submit_ok', __('Submission attempted. Check “Last Result” below.', 'indexnow'), 'updated');
        } else {
            add_settings_error('indexnow_messages', 'indexnow_submit_fail', __('Submission failed. Check “Last Result” below.', 'indexnow'), 'error');
        }
    }

    private static function handle_verify_key(): void {
        $r = Submitter::verify_key_location(Options::get());

        if (is_wp_error($r)) {
            add_settings_error('indexnow_messages', 'indexnow_key_bad', $r->get_error_message(), 'error');
            return;
        }

        $msg = __('Key file is reachable.', 'indexnow');
        if (!empty($r['note'])) {
            $msg .= ' ' . $r['note'];
        }

        add_settings_error('indexnow_messages', 'indexnow_key_ok', $msg, 'updated');
    }

    public static function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'indexnow'));
        }

        $opts = Options::get();
        $queue_count = Submitter::get_queue_count();
        $last = Submitter::get_last_result();

        settings_errors('indexnow_messages');

        $public_pts = get_post_types(['public' => true], 'objects');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('IndexNow', 'indexnow'); ?></h1>

            <p class="description">
                <?php echo esc_html__('Conservative IndexNow submission. Batches updates, avoids spam, and tries not to create new problems.', 'indexnow'); ?>
            </p>

            <form method="post">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="indexnow_action" value="save" />

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php echo esc_html__('Enabled', 'indexnow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Options::KEY); ?>[enabled]" value="1" <?php checked(1, (int) $opts['enabled']); ?> />
                                <?php echo esc_html__('Queue and submit URLs on publish/update/delete.', 'indexnow'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('IndexNow Key', 'indexnow'); ?></th>
                        <td>
                            <input class="regular-text" type="text" name="<?php echo esc_attr(Options::KEY); ?>[key]" value="<?php echo esc_attr($opts['key']); ?>" autocomplete="off" />
                            <p class="description">
                                <?php echo esc_html__('Paste the key value (not the filename, not a URL).', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Endpoint', 'indexnow'); ?></th>
                        <td>
                            <input class="regular-text" type="url" name="<?php echo esc_attr(Options::KEY); ?>[endpoint]" value="<?php echo esc_attr($opts['endpoint']); ?>" />
                            <p class="description">
                                <?php echo esc_html__('Default is Bing. Change only if you know exactly why you are changing it.', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Key Location URL (optional)', 'indexnow'); ?></th>
                        <td>
                            <input class="regular-text" type="url" name="<?php echo esc_attr(Options::KEY); ?>[key_location]" value="<?php echo esc_attr($opts['key_location']); ?>" />
                            <p class="description">
                                <?php echo esc_html__('Leave blank to use https://yoursite.com/<key>.txt', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Post Types', 'indexnow'); ?></th>
                        <td>
                            <?php foreach ($public_pts as $pt => $obj): ?>
                                <label style="display:block; margin: 2px 0;">
                                    <input
                                        type="checkbox"
                                        name="<?php echo esc_attr(Options::KEY); ?>[post_types][]"
                                        value="<?php echo esc_attr($pt); ?>"
                                        <?php checked(in_array($pt, (array) $opts['post_types'], true)); ?>
                                    />
                                    <?php echo esc_html($obj->labels->singular_name); ?>
                                    <code><?php echo esc_html($pt); ?></code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php echo esc_html__('Only published items are submitted.', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Sitemap (optional)', 'indexnow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Options::KEY); ?>[submit_sitemap]" value="1" <?php checked(1, (int) $opts['submit_sitemap']); ?> />
                                <?php echo esc_html__('Also queue sitemap URL when content changes.', 'indexnow'); ?>
                            </label>
                            <p>
                                <input class="regular-text" type="text" name="<?php echo esc_attr(Options::KEY); ?>[sitemap_path]" value="<?php echo esc_attr($opts['sitemap_path']); ?>" />
                            </p>
                            <p class="description">
                                <?php echo esc_html__('Path only. Example: /sitemap.xml or /sitemap_index.xml', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Rate Limits', 'indexnow'); ?></th>
                        <td>
                            <label>
                                <?php echo esc_html__('Max URLs per submit', 'indexnow'); ?>
                                <input type="number" min="1" max="1000" name="<?php echo esc_attr(Options::KEY); ?>[max_urls_per_submit]" value="<?php echo esc_attr((string) $opts['max_urls_per_submit']); ?>" style="width: 120px;" />
                            </label>
                            <p>
                                <label>
                                    <?php echo esc_html__('Minimum seconds between submits', 'indexnow'); ?>
                                    <input type="number" min="0" max="3600" name="<?php echo esc_attr(Options::KEY); ?>[min_seconds_between_submits]" value="<?php echo esc_attr((string) $opts['min_seconds_between_submits']); ?>" style="width: 120px;" />
                                </label>
                            </p>
                            <p class="description">
                                <?php echo esc_html__('Stops repeated admin saves from hammering remote endpoints.', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Debug Logging', 'indexnow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Options::KEY); ?>[debug]" value="1" <?php checked(1, (int) $opts['debug']); ?> />
                                <?php echo esc_html__('Write basic events to PHP error log.', 'indexnow'); ?>
                            </label>
                            <p class="description">
                                <?php echo esc_html__('Off by default. Logs become liabilities. Use sparingly.', 'indexnow'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php echo esc_html__('Uninstall Behaviour', 'indexnow'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Options::KEY); ?>[purge_on_uninstall]" value="1" <?php checked(1, (int) $opts['purge_on_uninstall']); ?> />
                                <?php echo esc_html__('Purge plugin settings on uninstall.', 'indexnow'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button class="button button-primary" type="submit"><?php echo esc_html__('Save Changes', 'indexnow'); ?></button>
                </p>
            </form>

            <hr />

            <h2><?php echo esc_html__('Tools', 'indexnow'); ?></h2>

            <p>
                <?php echo esc_html__('Queue size:', 'indexnow'); ?>
                <strong><?php echo esc_html((string) $queue_count); ?></strong>
            </p>

            <form method="post" style="display:inline-block; margin-right: 8px;">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="indexnow_action" value="verify_key" />
                <button class="button"><?php echo esc_html__('Verify Key File', 'indexnow'); ?></button>
            </form>

            <form method="post" style="display:inline-block; margin-right: 8px;">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="indexnow_action" value="submit_now" />
                <button class="button button-secondary"><?php echo esc_html__('Submit Queue Now', 'indexnow'); ?></button>
            </form>

            <form method="post" style="display:inline-block;">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="indexnow_action" value="clear_queue" />
                <button class="button"><?php echo esc_html__('Clear Queue', 'indexnow'); ?></button>
            </form>

            <h2 style="margin-top: 20px;"><?php echo esc_html__('Last Result', 'indexnow'); ?></h2>
            <?php if (empty($last)): ?>
                <p class="description"><?php echo esc_html__('No submissions attempted yet.', 'indexnow'); ?></p>
            <?php else: ?>
                <table class="widefat striped" style="max-width: 900px;">
                    <tbody>
                        <tr>
                            <th><?php echo esc_html__('Time', 'indexnow'); ?></th>
                            <td><?php echo esc_html(date_i18n('Y-m-d H:i:s', (int) ($last['time'] ?? time()))); ?></td>
                        </tr>
                        <tr>
                            <th><?php echo esc_html__('OK', 'indexnow'); ?></th>
                            <td><?php echo !empty($last['ok']) ? '<strong>Yes</strong>' : '<strong>No</strong>'; ?></td>
                        </tr>
                        <?php if (!empty($last['endpoint'])): ?>
                            <tr>
                                <th><?php echo esc_html__('Endpoint', 'indexnow'); ?></th>
                                <td><code><?php echo esc_html($last['endpoint']); ?></code></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (!empty($last['submitted'])): ?>
                            <tr>
                                <th><?php echo esc_html__('Submitted', 'indexnow'); ?></th>
                                <td><?php echo esc_html((string) $last['submitted']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if (empty($last['ok'])): ?>
                            <tr>
                                <th><?php echo esc_html__('Error', 'indexnow'); ?></th>
                                <td>
                                    <code><?php echo esc_html((string) ($last['error_code'] ?? 'unknown')); ?></code><br />
                                    <?php echo esc_html((string) ($last['error_message'] ?? '')); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}
