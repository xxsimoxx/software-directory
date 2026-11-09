<?php

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}

class SoftwareDirectoryCLI {

	/**
	 * Import a public GitHub repository using its README.md.
	 *
	 * ## OPTIONS
	 *
	 * <kind-slug>
	 * : Slug of an existing xsxsd-kind term.
	 *
	 * <repository-url>
	 * : Public GitHub repository URL.
	 *
	 * ## EXAMPLES
	 *
	 *     wp software-directory insert plugin https://github.com/xxsimoxx/example
	 */
	public function insert($args) {
		if (count($args) !== 2) {
			\WP_CLI::error('Usage: wp software-directory insert <kind-slug> <repository-url>');
		}
		[$kind_slug, $repository_url] = $args;
		$term = get_term_by('slug', $kind_slug, SoftwareDirectory::SLUG.'-kind');
		if (!$term || $term->slug !== $kind_slug) {
			\WP_CLI::error('The requested software kind does not exist. No term was created.');
		}
		$repository_url = $this->canonical_repository_url($repository_url);
		if ($repository_url === false) {
			\WP_CLI::error('Expected a GitHub repository URL: https://github.com/{owner}/{repo}');
		}
		$this->check_duplicate($repository_url);

		$repository = $this->import_repository($repository_url);
		$slug = sanitize_title($repository['name']);
		if ($slug === '') {
			\WP_CLI::error('The repository name cannot be used as a post slug.');
		}
		// All remote operations and validation finish before creating a post.
		$this->check_duplicate($repository_url);
		$post_id = wp_insert_post(wp_slash([
			'post_type'    => SoftwareDirectory::SLUG,
			'post_title'   => $repository['name'],
			'post_name'    => $slug,
			'post_content' => $repository['content'],
			'post_status'  => 'publish',
		]), true);
		if (is_wp_error($post_id) || !$post_id) {
			\WP_CLI::error(is_wp_error($post_id) ? $post_id->get_error_message() : 'Post insertion failed.');
		}
		$assigned = wp_set_object_terms($post_id, [(int) $term->term_id], SoftwareDirectory::SLUG.'-kind');
		if (is_wp_error($assigned) || !$assigned || !update_post_meta($post_id, 'git-repo', $repository_url)) {
			$deleted = wp_delete_post($post_id, true);
			$message = is_wp_error($assigned) ? $assigned->get_error_message() : 'Could not save the software kind or GitHub repository.';
			\WP_CLI::error($message.($deleted ? ' The incomplete post was removed.' : ' Could not remove incomplete post '.$post_id.'.'));
		}
		\WP_CLI::success('Created software '.$post_id.': '.get_permalink($post_id));
	}

	/**
	 * Update software content from its GitHub README.md.
	 *
	 * ## OPTIONS
	 *
	 * <post-id>
	 * : ID of an existing xsxsd post.
	 *
	 * ## EXAMPLES
	 *
	 *     wp software-directory update 693
	 */
	public function update($args) {
		if (count($args) !== 1) {
			\WP_CLI::error('Usage: wp software-directory update <post-id>');
		}
		$post_id = filter_var($args[0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
		if ($post_id === false) {
			\WP_CLI::error('The post ID must be a positive integer.');
		}
		$post = get_post($post_id);
		if (!$post || $post->post_type !== SoftwareDirectory::SLUG) {
			\WP_CLI::error('Post '.$post_id.' does not exist or is not software.');
		}

		$repository = $this->import_repository(get_post_meta($post_id, 'git-repo', true));
		if ($repository['content'] === $post->post_content) {
			\WP_CLI::success('Software '.$post_id.' is already up to date. No changes were made.');
			return;
		}
		$result = wp_update_post(wp_slash([
			'ID'           => $post_id,
			'post_content' => $repository['content'],
		]), true);
		if (is_wp_error($result) || !$result) {
			\WP_CLI::error(is_wp_error($result) ? $result->get_error_message() : 'Content update failed for software '.$post_id.'.');
		}
		\WP_CLI::success('Updated content for software '.$post_id.'.');
	}

	private function import_repository($repository_url) {
		$repository_url = $this->canonical_repository_url($repository_url);
		if ($repository_url === false) {
			\WP_CLI::error('Expected a GitHub repository URL: https://github.com/{owner}/{repo}');
		}
		$repository_path = substr($repository_url, strlen('https://github.com/'));
		$api_path = '/repos/'.$repository_path;
		$repository = json_decode($this->github_request($api_path, 'Repository lookup'), true);
		if (!is_array($repository) || ($repository['private'] ?? null) !== false || empty($repository['name']) || !is_string($repository['name']) || empty($repository['default_branch']) || !is_string($repository['default_branch']) || empty($repository['html_url']) || $this->canonical_repository_url($repository['html_url']) !== $repository_url) {
			\WP_CLI::error('GitHub did not return a valid public repository.');
		}
		$readme = json_decode($this->github_request(
			$api_path.'/contents/README.md?ref='.rawurlencode($repository['default_branch']),
			'README.md lookup on the default branch'
		), true);
		if (!is_array($readme) || ($readme['type'] ?? '') !== 'file' || ($readme['encoding'] ?? '') !== 'base64' || !isset($readme['content']) || !is_string($readme['content'])) {
			\WP_CLI::error('README.md is missing or GitHub returned unsupported file content.');
		}
		$markdown = base64_decode($readme['content'], true);
		if ($markdown === false) {
			\WP_CLI::error('GitHub returned invalid README.md data.');
		}
		try {
			$content = $this->remove_images($this->render_markdown($markdown, $repository_path));
		} catch (\Throwable $error) {
			\WP_CLI::error('README conversion failed: '.$error->getMessage());
		}

		return ['name' => $repository['name'], 'content' => $content];
	}

	private function canonical_repository_url($url) {
		if (!is_string($url) || !preg_match('~\Ahttps?://github\.com/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)/([a-z0-9_.-]{1,100})/?\z~i', $url, $matches)) {
			return false;
		}
		$repo = preg_replace('/\.git$/i', '', $matches[2]);
		if (in_array($repo, ['', '.', '..'], true)) {
			return false;
		}
		return 'https://github.com/'.strtolower($matches[1].'/'.$repo);
	}

	private function check_duplicate($repository_url) {
		$post_ids = get_posts([
			'post_type'        => SoftwareDirectory::SLUG,
			'post_status'      => array_keys(get_post_stati()),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => 'git-repo',
			'suppress_filters' => true,
		]);
		update_meta_cache('post', $post_ids);
		foreach ($post_ids as $post_id) {
			foreach (get_post_meta($post_id, 'git-repo', false) as $url) {
				if ($this->canonical_repository_url($url) === $repository_url) {
					\WP_CLI::error('Repository already associated with software '.$post_id.'. No duplicate was created.');
				}
			}
		}
	}

	private function github_request($path, $operation, $markdown = null) {
		$args = [
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => ['Accept' => 'application/vnd.github+json'],
		];
		if ($markdown === null) {
			$response = wp_remote_get('https://api.github.com'.$path, $args);
		} else {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = wp_json_encode($markdown);
			$response = wp_remote_post('https://api.github.com'.$path, $args);
		}
		if (is_wp_error($response)) {
			\WP_CLI::error($operation.': '.$response->get_error_message());
		}
		$status = wp_remote_retrieve_response_code($response);
		if ($status !== 200) {
			\WP_CLI::error($operation.' failed (GitHub HTTP '.$status.'). Check repository visibility, README.md and API rate limits.');
		}
		return wp_remote_retrieve_body($response);
	}

	private function render_markdown($markdown, $repository_path) {
		if (class_exists('\League\CommonMark\GithubFlavoredMarkdownConverter')) {
			$converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['allow_unsafe_links' => false]);
			return (string) $converter->convert($markdown);
		}
		foreach (['\Parsedown', '\CodePotent\UpdateManager\Parsedown'] as $parser_class) {
			if (class_exists($parser_class)) {
				$parser = new $parser_class();
				return $parser->text($markdown);
			}
		}
		// GitHub rendering avoids installing a parser or depending on another plugin.
		return $this->github_request('/markdown', 'Markdown rendering', [
			'text'    => $markdown,
			'mode'    => 'gfm',
			'context' => $repository_path,
		]);
	}

	private function remove_images($html) {
		if (!class_exists('\DOMDocument')) {
			throw new \RuntimeException('The PHP DOM extension is required to remove images safely.');
		}
		$document = new \DOMDocument();
		$previous_errors = libxml_use_internal_errors(true);
		try {
			$loaded = $document->loadHTML('<?xml encoding="UTF-8"><html><body>'.wp_kses_post($html).'</body></html>', LIBXML_NONET);
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors($previous_errors);
		}
		if (!$loaded) {
			throw new \RuntimeException('Could not parse README HTML.');
		}
		$images = $document->getElementsByTagName('img');
		while ($images->length) {
			$image = $images->item(0);
			$alt = trim($image->getAttribute('alt'));
			$image->parentNode->replaceChild($document->createTextNode($alt), $image);
		}
		$content = '';
		foreach ($document->getElementsByTagName('body')->item(0)->childNodes as $node) {
			$content .= $document->saveHTML($node);
		}
		$allowed_html = wp_kses_allowed_html('post');
		unset($allowed_html['img'], $allowed_html['picture'], $allowed_html['source']);
		return wp_kses($content, $allowed_html);
	}
}
