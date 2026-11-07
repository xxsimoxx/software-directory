<?php

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}

get_header();
$custom_fields = SoftwareDirectory::get_custom_fields();
?>
<main class="software-directory-single">
	<?php while (have_posts()) : the_post(); ?>
		<article class="software-directory-item">
			<header class="software-directory-header">
				<h1 class="software-directory-title"><?php echo esc_html(get_the_title()); ?></h1>
			</header>
			<section class="software-directory-description" aria-label="<?php esc_attr_e('Description', 'software-directory'); ?>">
				<?php the_content(); ?>
				<?php wp_link_pages(); ?>
			</section>
			<?php if (!post_password_required()) : ?>
				<section class="software-directory-metadata" aria-label="<?php esc_attr_e('Software Details', 'software-directory'); ?>">
					<?php $terms = get_the_terms(get_the_ID(), SoftwareDirectory::SLUG.'-kind'); ?>
					<?php if ($terms && !is_wp_error($terms)) : ?>
						<nav class="software-directory-kinds" aria-label="<?php esc_attr_e('Kinds', 'software-directory'); ?>">
							<ul class="software-directory-terms">
								<?php foreach ($terms as $term) :  //phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
									<?php
									$term_link = get_term_link($term);
									if (is_wp_error($term_link)) {
										continue;
									}
									?>
									<li class="software-directory-term"><a href="<?php echo esc_url($term_link); ?>"><?php echo esc_html($term->name); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</nav>
					<?php endif; ?>
					<?php include __DIR__.'/custom-fields.php'; ?>
				</section>
			<?php endif; ?>
		</article>
	<?php endwhile; ?>
</main>
<?php
get_footer();
