<?php

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}

$repository_url = get_post_meta(get_the_ID(), 'git-repo', true);
$has_download = false;
// Match the repository URLs accepted by the download endpoint.
if (is_string($repository_url) && preg_match('~\Ahttps://github\.com/([a-z0-9](?:[a-z0-9-]{0,37}[a-z0-9])?)/([a-z0-9_.-]{1,100})/?\z~i', $repository_url, $repository_matches)) {
	$repository_name = preg_replace('/\.git$/i', '', $repository_matches[2]);
	$has_download = !in_array($repository_name, ['', '.', '..'], true);
}
?>
<?php if ($has_download) : ?>
	<a class="software-directory-download" href="<?php echo esc_url(home_url('/software/'.get_post_field('post_name', get_the_ID()).'/latest.zip')); ?>">
		<svg class="software-directory-download-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
			<path d="M12 3v12m-5-5 5 5 5-5M5 16v5h14v-5" />
		</svg>
		<span class="software-directory-download-label"><?php esc_html_e('Download', 'software-directory'); ?></span>
	</a>
<?php endif; ?>
