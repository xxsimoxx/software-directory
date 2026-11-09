<?php

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}

get_header();
$term = get_queried_object(); //phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$custom_fields = SoftwareDirectory::get_custom_fields();
$descriptive_field_links = true;
?>
<main class="software-directory-archive">
	<header class="software-directory-header">
		<h1 class="software-directory-title"><?php echo esc_html($term->name); ?></h1>
		<?php if (term_description()) : ?>
			<div class="software-directory-description"><?php echo wp_kses_post(term_description()); ?></div>
		<?php endif; ?>
	</header>

	<?php if (have_posts()) : ?>
		<ul class="software-directory-items">
			<?php while (have_posts()) : the_post(); ?>
				<li class="software-directory-item">
					<article class="software-directory-card">
						<?php if (has_post_thumbnail()) : ?>
							<a class="software-directory-image-link" href="<?php echo esc_url(get_permalink()); ?>" aria-label="<?php echo esc_attr(get_the_title()); ?>">
								<?php the_post_thumbnail('medium_large', ['class' => 'software-directory-image']); ?>
							</a>
						<?php endif; ?>
						<h2 class="software-directory-item-title"><a href="<?php echo esc_url(get_permalink()); ?>"><?php echo esc_html(get_the_title()); ?></a></h2>
						<div class="software-directory-excerpt"><?php echo wp_kses_post(wpautop(get_the_excerpt())); ?></div>
						<?php include __DIR__.'/custom-fields.php'; ?>
						<?php include __DIR__.'/download.php'; ?>
					</article>
				</li>
			<?php endwhile; ?>
		</ul>
		<div class="software-directory-pagination">
			<?php the_posts_pagination(); ?>
		</div>
	<?php else : ?>
		<p class="software-directory-empty"><?php esc_html_e('No Software found', 'software-directory'); ?></p>
	<?php endif; ?>
</main>
<?php
get_footer();
