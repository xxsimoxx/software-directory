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

	public function __construct() {
		add_action('init', [$this, 'register_custom_post_type']);
		add_action('init', [$this, 'register_taxonomy']);
		add_action('add_meta_boxes', [$this, 'register_meta_box_primary']);
		add_action('save_post', [$this, 'update_meta_box_primary'], 10, 2);
		add_filter('template_include', [$this, 'get_taxonomy_template']);
		add_filter('single_template', [$this, 'get_single_template']);
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
			'supports'      => ['title', 'editor', 'author', 'custom-fields', 'excerpt'],
			'labels'        => $labels,
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
		if ($assigned_template && 0 === validate_file($assigned_template)) {
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
		$custom_fields = apply_filters(self::SLUG.'-datails', $custom_fields);
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
