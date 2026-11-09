<?php

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}

get_header();
$custom_fields = SoftwareDirectory::get_custom_fields();
$descriptive_field_links = true;
?>
<main class="software-directory-single">
	<?php while (have_posts()) : the_post(); ?>
		<?php
		$download_html = '';
		$fields_html = '';
		$term_links = [];
		if (!post_password_required()) {
			ob_start();
			include __DIR__.'/download.php';
			$download_html = trim(ob_get_clean());
			ob_start();
			include __DIR__.'/custom-fields.php';
			$fields_html = trim(ob_get_clean());
			if (strpos($fields_html, '<dt ') === false) {
				$fields_html = '';
			}
			$terms = get_the_terms(get_the_ID(), SoftwareDirectory::SLUG.'-kind');
			if ($terms && !is_wp_error($terms)) {
				foreach ($terms as $software_term) {
					$term_link = get_term_link($software_term);
					if (!is_wp_error($term_link)) {
						$term_links[] = '<li class="software-directory-term"><a href="'.esc_url($term_link).'">'.esc_html($software_term->name).'</a></li>';
					}
				}
			}
		}
		?>
		<article class="software-directory-item">
			<header class="software-directory-header">
				<h1 class="software-directory-title"><?php echo esc_html(get_the_title()); ?></h1>
			</header>
			<div class="software-directory-layout<?php echo $download_html || $fields_html || $term_links ? ' software-directory-has-sidebar' : ''; ?>">
				<section class="software-directory-description" aria-label="<?php esc_attr_e('Description', 'software-directory'); ?>">
					<?php if (apply_filters('software_directory_show_single_thumbnail', true, get_the_ID()) === true && has_post_thumbnail()) : ?>
						<?php the_post_thumbnail('large', ['class' => 'software-directory-image']); ?>
					<?php endif; ?>
					<?php the_content(); ?>
					<?php wp_link_pages(); ?>
				</section>
				<?php if ($download_html || $fields_html || $term_links) : ?>
					<aside class="software-directory-metadata" aria-label="<?php esc_attr_e('Software Details', 'software-directory'); ?>">
						<?php
						// Both partials escape their output in the appropriate context.
						echo $download_html; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						echo $fields_html; //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<?php if ($term_links) : ?>
							<nav class="software-directory-kinds" aria-label="<?php esc_attr_e('Kinds', 'software-directory'); ?>">
								<h2 class="software-directory-metadata-title"><?php esc_html_e('Kinds', 'software-directory'); ?></h2>
								<ul class="software-directory-terms">
									<?php echo implode("\n", $term_links); //phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</ul>
							</nav>
						<?php endif; ?>
					</aside>
				<?php endif; ?>
			</div>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
