<?php

/**
 * Plugin Name:  Software Directory
 * Description:  Build your own plugins and themes directory-
 * Version:      1.0.0
 * Requires PHP: 8.2
 * Requires CP:  2.0
 * Author:       Simone Fioravanti
 * Author URI:   https://software.gieffeedizioni.it
 * Plugin URI:   https://software.gieffeedizioni.it
 * Text Domain:  software-directory
 * Domain Path:  /languages
 * License:      GPLv2
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 */

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}

class SoftwareDirectory {

	const SLUG = 'xsxsd';

	public static function get_github_api_headers($headers = []) {
		$headers += ['Accept' => 'application/vnd.github+json'];
		if (defined('GITHUB_API_TOKEN') && is_string(GITHUB_API_TOKEN) && preg_match('#\A[A-Za-z0-9._~+/-]+=*\z#', GITHUB_API_TOKEN) === 1) {
			$headers['Authorization'] = 'Bearer '.GITHUB_API_TOKEN;
		}
		return $headers;
	}

	public function __construct() {
		add_action('init', [$this, 'register_custom_post_type']);
		add_action('init', [$this, 'register_taxonomy']);
		add_action('add_meta_boxes', [$this, 'register_meta_box_primary']);
		add_action('save_post', [$this, 'update_meta_box_primary'], 10, 2);
		add_filter('template_include', [$this, 'get_taxonomy_template']);
		add_filter('single_template', [$this, 'get_single_template']);
		add_action('init', [$this, 'register_download_endpoint']);
		add_action('template_redirect', [$this, 'download_latest_release'], 0);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_archive_styles']);
		add_action('wp_enqueue_scripts', [$this, 'enqueue_single_styles']);
	}

	public function enqueue_archive_styles() {
		if (!is_tax(self::SLUG.'-kind')) {
			return;
		}

		$this->enqueue_download_styles();
		wp_enqueue_style(
			'software-directory-archive',
			plugins_url('assets/css/archive.css', __FILE__),
			['software-directory-download'],
			(string) filemtime(__DIR__.'/assets/css/archive.css')
		);
	}

	public function enqueue_single_styles() {
		if (!is_singular(self::SLUG)) {
			return;
		}

		$this->enqueue_download_styles();
		wp_enqueue_style(
			'software-directory-single',
			plugins_url('assets/css/single.css', __FILE__),
			['software-directory-download'],
			(string) filemtime(__DIR__.'/assets/css/single.css')
		);
	}

	private function enqueue_download_styles() {
		wp_enqueue_style(
			'software-directory-download',
			plugins_url('assets/css/download.css', __FILE__),
			[],
			(string) filemtime(__DIR__.'/assets/css/download.css')
		);
	}

	public function register_download_endpoint() {
		add_rewrite_tag('%xsxsd_download%', '([^&]+)');
		add_rewrite_rule(
			'^software/([^/]+)/latest\.zip/?$',
			'index.php?post_type='.self::SLUG.'&name=$matches[1]&xsxsd_download=1',
			'top'
		);
	}

	public function download_latest_release() {
		if (get_query_var('xsxsd_download') !== '1') {
			return;
		}

		nocache_headers();
		$post = get_queried_object();
		if (!($post instanceof \WP_Post) || $post->post_type !== self::SLUG || $post->post_status !== 'publish') {
			wp_die(esc_html__('Software not found.', 'software-directory'), '', ['response' => 404]);
		}

		$repository_url = get_post_meta($post->ID, 'git-repo', true);
		if (!is_string($repository_url) || !preg_match('~\Ahttps://github\.com/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)/([a-z0-9_.-]{1,100})/?\z~i', $repository_url, $matches)) {
			wp_die(esc_html__('Invalid GitHub repository.', 'software-directory'), '', ['response' => 404]);
		}
		$owner = $matches[1];
		$repo = preg_replace('/\.git$/i', '', $matches[2]);
		if ($repo === '' || $repo === '.' || $repo === '..') {
			wp_die(esc_html__('Invalid GitHub repository.', 'software-directory'), '', ['response' => 404]);
		}

		$cache_key = self::SLUG.'_latest_zip_'.md5(strtolower($owner.'/'.$repo));
		$result = get_transient($cache_key);
		if ($result === false) {
			$result = $this->get_latest_release_download($owner, $repo);
			set_transient($cache_key, $result, 120 * MINUTE_IN_SECONDS);
		}

		if (!empty($result['url']) && $this->is_repository_download($result['url'], $owner, $repo)) {
			// The destination is external, but restricted to this repository on github.com.
				if (wp_redirect($result['url'], 302, 'Software Directory')) { // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External GitHub URL validated against the expected repository above.
				exit;
			}
		}
			wp_die(esc_html__('No unambiguous ZIP download is available. Please try again later.', 'software-directory'), '', ['response' => $result['status'] ?? 502]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Message is escaped; response is an internally generated HTTP status, not HTML output.
	}

	private function is_repository_download($url, $owner, $repo) {
		if (!is_string($url)) {
			return false;
		}
		$pattern = '~\Ahttps://github\.com/'.preg_quote($owner, '~').'/'.preg_quote($repo, '~').'/releases/download/[^/?#]+/[^/?#]+\.zip\z~i';
		if (preg_match($pattern, $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
			return false;
		}
		foreach (array_slice(explode('/', wp_parse_url($url, PHP_URL_PATH)), -2) as $segment) {
			$segment = rawurldecode($segment);
			if ($segment === '.' || $segment === '..' || preg_match('~[/\\\\\x00-\x20?#]~', $segment)) {
				return false;
			}
		}
		return true;
	}

	private function get_latest_release_download($owner, $repo) {
		$response = wp_remote_get('https://api.github.com/repos/'.$owner.'/'.$repo.'/releases/latest', [
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => self::get_github_api_headers(),
		]);
		if (is_wp_error($response)) {
			return ['status' => 502];
		}
		$status = wp_remote_retrieve_response_code($response);
		if ($status !== 200) {
			return ['status' => $status === 404 ? 404 : 502];
		}

		$release = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($release) || !isset($release['assets']) || !is_array($release['assets']) || !empty($release['draft']) || !empty($release['prerelease'])) {
			return ['status' => 502];
		}

		$zips = [];
		$matching_zips = [];
		foreach ($release['assets'] as $asset) {
			if (!is_array($asset) || !isset($asset['name'], $asset['browser_download_url']) || !is_string($asset['name']) || !preg_match('/\.zip$/i', $asset['name'])) {
				continue;
			}
			if (!$this->is_repository_download($asset['browser_download_url'], $owner, $repo)) {
				return ['status' => 502];
			}
			$zips[] = $asset['browser_download_url'];
			if (strcasecmp($asset['name'], $repo.'.zip') === 0) {
				$matching_zips[] = $asset['browser_download_url'];
			}
		}
		if (count($matching_zips) === 1) {
			return ['url' => $matching_zips[0]];
		}
		if (count($zips) === 1) {
			return ['url' => $zips[0]];
		}
		return ['status' => empty($zips) ? 404 : 409];
	}


	public function register_custom_post_type() {

		$labels = [
			'name'                => esc_html__('Software',                   'software-directory'),
			'singular_name'       => esc_html__('Software Endpoint',          'software-directory'),
			'add_new'             => esc_html__('New Software',               'software-directory'),
			'add_new_item'        => esc_html__('Add New Software',           'software-directory'),
			'edit_item'           => esc_html__('Edit Software',              'software-directory'),
			'new_item'            => esc_html__('New Software',               'software-directory'),
			'all_items'           => esc_html__('Software',                   'software-directory'),
			'view_item'           => esc_html__('View Software',              'software-directory'),
			'search_items'        => esc_html__('Search Software',            'software-directory'),
			'not_found'           => esc_html__('No Software found',          'software-directory'),
			'not_found_in_trash'  => esc_html__('No Software found in Trash', 'software-directory'),
			'menu_name'           => esc_html__('Software',                   'software-directory'),
		];

		$args = [
			'public'        => true,
			'show_ui'       => true,
			'show_in_menu'  => true,
			'rewrite'       => false,
			'supports'      => ['title', 'editor', 'author', 'custom-fields', 'excerpt', 'thumbnail'],
			'labels'        => $labels,
			'rewrite'       => [
				'slug'       => 'software',
				'with_front' => false,
			],
		];

		register_post_type(self::SLUG, $args);

	}

	public function register_taxonomy() {

		$labels = [
			'name'              => _x('Kinds', 'taxonomy general name', 'software-directory'),
			'singular_name'     => _x('Kind', 'taxonomy singular name', 'software-directory'),
			'search_items'      => __('Search Kinds', 'software-directory'),
			'all_items'         => __('All Kinds', 'software-directory'),
			'edit_item'         => __('Edit Kind', 'software-directory'),
			'update_item'       => __('Update Kind', 'software-directory'),
			'add_new_item'      => __('Add New Kind', 'software-directory'),
			'new_item_name'     => __('New Kind Name', 'software-directory'),
			'menu_name'         => __('Kind', 'software-directory'),
		];

		$args = [
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_in_nav_menus' => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => ['slug' => 'kind'],
		];

		register_taxonomy(self::SLUG.'-kind', self::SLUG, $args);

	}

	public function get_taxonomy_template($template) {
		if (!is_tax(self::SLUG.'-kind')) {
			return $template;
		}

		$term = get_queried_object();
		$templates = [];
		$slug = urldecode($term->slug);
		if ($slug !== $term->slug) {
			$templates[] = 'taxonomy-'.self::SLUG.'-kind-'.$slug.'.php';
		}
		$templates[] = 'taxonomy-'.self::SLUG.'-kind-'.$term->slug.'.php';
		$templates[] = 'taxonomy-'.self::SLUG.'-kind.php';
		$templates[] = 'taxonomy.php';
		$theme_template = locate_template($templates);

		return $theme_template ?: __DIR__.'/templates/taxonomy-'.self::SLUG.'-kind.php';
	}

	public function get_single_template($template) {
		if (!is_singular(self::SLUG)) {
			return $template;
		}

		$post = get_queried_object();
		$templates = [];
		$assigned_template = get_page_template_slug($post);
		if ($assigned_template && validate_file($assigned_template) === 0) {
			$templates[] = $assigned_template;
		}
		$slug = urldecode($post->post_name);
		if ($slug !== $post->post_name) {
			$templates[] = 'single-'.self::SLUG.'-'.$slug.'.php';
		}
		$templates[] = 'single-'.self::SLUG.'-'.$post->post_name.'.php';
		$templates[] = 'single-'.self::SLUG.'.php';

		return locate_template($templates) ?: __DIR__.'/templates/single-'.self::SLUG.'.php';
	}

	public function register_meta_box_primary() {
		add_meta_box(
			self::SLUG.'-primary-editor',
			esc_html__('Software Details', 'software-directory'),
			[$this, 'render_meta_box_primary'],
			[self::SLUG],
			'normal',
			'high'
			);
	}

	public static function get_custom_fields() {
		$custom_fields = [
			'slug'     => [
				'name'     => __('slug', 'software-directory'),
				'display'  => false,
				'render'   => 'render_mb_text',
				'sanitize' => 'sanitize_key',
				'escape'   => 'esc_attr',
			],
			'git-repo' => [
				'name'     => __('GitHub repo', 'software-directory'),
				'display'  => true,
				'render'   => 'render_mb_text',
				'sanitize' => 'sanitize_url',
				'escape'   => 'esc_url',
			],
		];
		$custom_fields = apply_filters(self::SLUG.'-details', $custom_fields);
		return $custom_fields;
	}

	public function render_mb_text($key, $name, $value) {
		// Notice that $value is escaped in render_meta_box_primary method.
		echo '		<tr>'."\n";
		echo '			<th scope="row"><label for="'.esc_attr(self::SLUG.'-'.$key).'">'.esc_html($name).'</label></th>'."\n";
		echo '			<td>'."\n";
		echo '				<p>'."\n";
		echo '					<input class="widefat" name="'.esc_attr(self::SLUG.'-'.$key).'" type="text" id="'.esc_attr(self::SLUG.'-'.$key).'" value="'.$value.'">'."\n"; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '				</p>'."\n";
		echo '			</td>'."\n";
		echo '		</tr>'."\n";
	}

	public function render_meta_box_primary($post) {

		echo '<table class="form-table">'."\n";
		echo '	<tbody>'."\n";

		foreach ($this->get_custom_fields() as $key => $value) {
			$post_meta = call_user_func($value['escape'], get_post_meta($post->ID, $key, true));
			call_user_func([$this, $value['render']], $key, $value['name'], $post_meta);
		}
		echo '	</tbody>'."\n";
		echo '</table>'."\n";
		wp_nonce_field(self::SLUG.'_metabox_nonce', self::SLUG.'_metabox_nonce');

	}

	public function update_meta_box_primary($post_id, $post) {

		if (!isset($_REQUEST[self::SLUG.'_metabox_nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_REQUEST[self::SLUG.'_metabox_nonce'])), self::SLUG.'_metabox_nonce')) {

			return $post_id;
		}

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			return $post_id;
		}

		if ($post->post_type !== self::SLUG) {
			return $post_id;
		}

		if (!current_user_can('update_plugins', $post_id)) {
			return $post_id;
		}

		$request = wp_unslash($_POST);

		foreach ($this->get_custom_fields() as $key => $value) {
			if (!isset($request[self::SLUG.'-'.$key])) {
				continue;
			}
			$content = call_user_func($value['sanitize'], $request[self::SLUG.'-'.$key]);
			update_post_meta($post_id, $key, $content);
		}

		return $post_id;

	}


}


new SoftwareDirectory;

if (defined('WP_CLI') && WP_CLI && class_exists('\WP_CLI')) {
	require_once __DIR__.'/includes/class-software-directory-cli.php';
	\WP_CLI::add_command('software-directory insert', [new SoftwareDirectoryCLI(), 'insert']);
	\WP_CLI::add_command('software-directory update', [new SoftwareDirectoryCLI(), 'update']);
}
