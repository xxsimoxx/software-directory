<?php

namespace xxsimoxx\SoftwareDirectory;

if (!defined('ABSPATH')) {
	die();
}
?>
<dl class="software-directory-fields">
	<?php foreach ($custom_fields as $key => $field) : ?>
		<?php
		if (empty($field['display'])) {
			continue;
		}
		$value = get_post_meta(get_the_ID(), $key, true);
		if (!is_scalar($value) || (string) $value === '') {
			continue;
		}
		$is_url = isset($field['escape']) && $field['escape'] === 'esc_url';
		if ($is_url && esc_url($value) === '') {
			continue;
		}
		?>
		<dt class="software-directory-field-name"><?php echo esc_html($field['name'] ?? $key); ?></dt>
		<dd class="software-directory-field-value">
			<?php if ($is_url) : ?>
				<a href="<?php echo esc_url($value); ?>"><?php echo esc_html($value); ?></a>
			<?php else : ?>
				<?php echo esc_html($value); ?>
			<?php endif; ?>
		</dd>
	<?php endforeach; ?>
</dl>
