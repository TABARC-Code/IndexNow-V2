<?php
namespace TABARC\IndexNow;

if (!defined('ABSPATH')) {
	exit;
}

final class Settings {

	private const OPTION_KEY   = 'tabarc_indexnow_options';
	private const NONCE_ACTION = 'tabarc_indexnow_save';
	private const NONCE_NAME   = '_tabarc_indexnow_nonce';

	public static function init(): void {
		add_action('admin_menu', [__CLASS__, 'admin_menu']);
		add_action('admin_init', [__CLASS__, 'handle_post']);
		add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
	}

	public static function defaults(): array {
		return [
			'enabled'          => 1,
			'indexnow_key'     => '',
			'key_location'     => '', // optional URL; if empty, we assume root {key}.txt exists
			'endpoint'         => 'https://www.bing.com/indexnow',
			'submit_sitemap'   => 1,
			'sitemap_path'     => '/sitemap.xml',
			'post_types'       => ['post', 'page'],
			'debug_log'        => 0,
			'purge_uninstall'  => 0,
		];
	}

	public static function get(): array {
		$stored = get_option(self::OPTION_KEY, []);
		$stored = is_array($stored) ? $stored : [];
		$out = wp_parse_args($stored, self::defaults());

		// Normalise.
		$out['enabled']        = (int) !empty($out['enabled']);
		$out['submit_sitemap'] = (int) !empty($out['submit_sitemap']);
		$out['debug_log']      = (int) !empty($out['debug_log']);
		$out['purge_uninstall']= (int) !empty($out['purge_uninstall']);

		$out['post_types'] = is_array($out['post_types']) ? array_values(array_unique(array_map('sanitize_key', $out['post_types']))) : ['post','page'];

		$out['indexnow_key'] = is_string($out['indexnow_key']) ? trim($out['indexnow_key']) : '';
		$out['endpoint']     = is_string($out['endpoint']) ? trim($out['endpoint']) : self::defaults()['endpoint'];
		$out['key_location'] = is_string($out['key_location']) ? trim($out['key_location']) : '';

		$out['sitemap_path']  = is_string($out['sitemap_path']) ? trim($out['sitemap_path']) : self::defaults()['sitemap_path'];
		$out['sitemap_path']  = $out['sitemap_path'] !== '' ? (str_starts_with($out['sitemap_path'], '/') ? $out['sitemap_path'] : '/' . $out['sitemap_path']) : '/sitemap.xml';

		return $out;
	}

	public static function update(array $options): void {
		update_option(self::OPTION_KEY, $options, false);
	}

	public static function admin_menu(): void {
		add_options_page(
			__('TABARC IndexNow', 'tabarc-indexnow'),
			__('TABARC IndexNow', 'tabarc-indexnow'),
			'manage_options',
			'tabarc-indexnow',
			[__CLASS__, 'render_page']
		);
	}

	public static function enqueue_assets(string $hook): void {
		if ($hook !== 'settings_page_tabarc-indexnow') {
			return;
		}

		wp_enqueue_style(
			'tabarc-indexnow-admin',
			TABARC_INDEXNOW_URL . 'assets/admin.css',
			[],
			TABARC_INDEXNOW_VERSION
		);

		wp_enqueue_script(
			'tabarc-indexnow-admin',
			TABARC_INDEXNOW_URL . 'assets/admin.js',
			['jquery'],
			TABARC_INDEXNOW_VERSION,
			true
		);
	}

	public static function handle_post(): void {
		if (!is_admin()) {
			return;
		}

		if (!current_user_can('manage_options')) {
			return;
		}

		if (empty($_POST['tabarc_indexnow_submit'])) {
			return;
		}

		check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

		$raw = $_POST[self::OPTION_KEY] ?? [];
		$raw = is_array($raw) ? $raw : [];

		$opt = self::get();

		// Enabled toggles.
		$opt['enabled']        = !empty($raw['enabled']) ? 1 : 0;
		$opt['submit_sitemap'] = !empty($raw['submit_sitemap']) ? 1 : 0;
		$opt['debug_log']      = !empty($raw['debug_log']) ? 1 : 0;
		$opt['purge_uninstall']= !empty($raw['purge_uninstall']) ? 1 : 0;

		// Key: IndexNow key rules are fairly strict; still, treat it as text and validate lightly.
		$key = isset($raw['indexnow_key']) ? sanitize_text_field(wp_unslash($raw['indexnow_key'])) : '';
		$key = trim($key);
		if ($key !== '' && !preg_match('/^[A-Za-z0-9\-]{8,128}$/', $key)) {
			add_settings_error('tabarc_indexnow', 'tabarc_indexnow_bad_key', __('IndexNow key looks invalid (expected 8–128 chars: letters, numbers, dashes).', 'tabarc-indexnow'));
			// Keep the old key instead of saving junk. People copy/paste weird stuff.
		} else {
			$opt['indexnow_key'] = $key;
		}

		$endpoint = isset($raw['endpoint']) ? esc_url_raw(wp_unslash($raw['endpoint'])) : $opt['endpoint'];
		$endpoint = trim((string) $endpoint);
		$opt['endpoint'] = $endpoint !== '' ? $endpoint : self::defaults()['endpoint'];

		$key_location = isset($raw['key_location']) ? esc_url_raw(wp_unslash($raw['key_location'])) : '';
		$opt['key_location'] = trim((string) $key_location);

		$sitemap_path = isset($raw['sitemap_path']) ? sanitize_text_field(wp_unslash($raw['sitemap_path'])) : $opt['sitemap_path'];
		$sitemap_path = trim($sitemap_path);
		if ($sitemap_path === '') {
			$sitemap_path = self::defaults()['sitemap_path'];
		}
		$opt['sitemap_path'] = str_starts_with($sitemap_path, '/') ? $sitemap_path : '/' . $sitemap_path;

		$post_types = $raw['post_types'] ?? [];
		$post_types = is_array($post_types) ? array_map('sanitize_key', array_map('wp_unslash', $post_types)) : [];
		$post_types = array_values(array_filter(array_unique($post_types)));
		$opt['post_types'] = $post_types ?: ['post', 'page'];

		self::update($opt);

		add_settings_error('tabarc_indexnow', 'tabarc_indexnow_saved', __('Settings saved.', 'tabarc-indexnow'), 'updated');
	}

	public static function render_page(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have permission to access this page.', 'tabarc-indexnow'));
		}

		$opt = self::get();
		$all_post_types = get_post_types(['public' => true], 'objects');

		settings_errors('tabarc_indexnow');

		?>
		<div class="wrap">
			<h1><?php echo esc_html__('TABARC IndexNow', 'tabarc-indexnow'); ?></h1>

			<p>
				<?php echo esc_html__('This plugin queues URLs on publish/update and submits them to IndexNow in a single request at shutdown.', 'tabarc-indexnow'); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__('Enabled', 'tabarc-indexnow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[enabled]" value="1" <?php checked(1, (int) $opt['enabled']); ?>>
								<?php echo esc_html__('Queue and submit URLs', 'tabarc-indexnow'); ?>
							</label>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('IndexNow Key', 'tabarc-indexnow'); ?></th>
						<td>
							<input type="text" class="regular-text" autocomplete="off"
								name="<?php echo esc_attr(self::OPTION_KEY); ?>[indexnow_key]"
								value="<?php echo esc_attr($opt['indexnow_key']); ?>"
								placeholder="<?php echo esc_attr__('e.g. 7ae1f6c10bc648c1bf556d1e51496722', 'tabarc-indexnow'); ?>"
							/>
							<p class="description">
								<?php echo esc_html__('You must host {key}.txt on your site (UTF-8) containing the key. Optional keyLocation URL can be provided below.', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Key Location URL (optional)', 'tabarc-indexnow'); ?></th>
						<td>
							<input type="url" class="regular-text" autocomplete="off"
								name="<?php echo esc_attr(self::OPTION_KEY); ?>[key_location]"
								value="<?php echo esc_attr($opt['key_location']); ?>"
								placeholder="<?php echo esc_attr__('https://example.com/mykeyfile.txt', 'tabarc-indexnow'); ?>"
							/>
							<p class="description">
								<?php echo esc_html__('If empty, IndexNow will expect https://your-site/{key}.txt.', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Endpoint', 'tabarc-indexnow'); ?></th>
						<td>
							<input type="url" class="regular-text" autocomplete="off"
								name="<?php echo esc_attr(self::OPTION_KEY); ?>[endpoint]"
								value="<?php echo esc_attr($opt['endpoint']); ?>"
							/>
							<p class="description">
								<?php echo esc_html__('Default is Bing’s IndexNow endpoint. Change only if you know why you’re doing it.', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Submit sitemap too', 'tabarc-indexnow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[submit_sitemap]" value="1" <?php checked(1, (int) $opt['submit_sitemap']); ?>>
								<?php echo esc_html__('Also submit sitemap URL occasionally (not on every single edit).', 'tabarc-indexnow'); ?>
							</label>
							<p class="description">
								<?php echo esc_html__('We rate-limit sitemap submission via a transient. Constantly pinging a sitemap is just noisy.', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Sitemap path', 'tabarc-indexnow'); ?></th>
						<td>
							<input type="text" class="regular-text" autocomplete="off"
								name="<?php echo esc_attr(self::OPTION_KEY); ?>[sitemap_path]"
								value="<?php echo esc_attr($opt['sitemap_path']); ?>"
							/>
							<p class="description">
								<?php echo esc_html__('Example: /sitemap.xml (Yoast/RankMath may use /sitemap_index.xml).', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Post types', 'tabarc-indexnow'); ?></th>
						<td>
							<?php foreach ($all_post_types as $pt => $obj): ?>
								<label style="display:block; margin: 2px 0;">
									<input type="checkbox"
										name="<?php echo esc_attr(self::OPTION_KEY); ?>[post_types][]"
										value="<?php echo esc_attr($pt); ?>"
										<?php checked(in_array($pt, $opt['post_types'], true)); ?>
									/>
									<?php echo esc_html($obj->labels->singular_name ?: $pt); ?>
									<code style="opacity:0.7;"><?php echo esc_html($pt); ?></code>
								</label>
							<?php endforeach; ?>
							<p class="description">
								<?php echo esc_html__('Only these post types will be queued for IndexNow submission.', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Debug logging', 'tabarc-indexnow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[debug_log]" value="1" <?php checked(1, (int) $opt['debug_log']); ?>>
								<?php echo esc_html__('Log responses to error_log()', 'tabarc-indexnow'); ?>
							</label>
							<p class="description">
								<?php echo esc_html__('Only enable temporarily. Logs are where secrets go to die.', 'tabarc-indexnow'); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php echo esc_html__('Purge on uninstall', 'tabarc-indexnow'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY); ?>[purge_uninstall]" value="1" <?php checked(1, (int) $opt['purge_uninstall']); ?>>
								<?php echo esc_html__('Delete plugin options when the plugin is deleted', 'tabarc-indexnow'); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" name="tabarc_indexnow_submit" class="button button-primary">
						<?php echo esc_html__('Save Changes', 'tabarc-indexnow'); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
