<?php
/**
 * Concurrency Count page controller.
 *
 * Follows Frogman's pattern of delegating to a showPage() method on the
 * BMO class, which returns rendered HTML via load_view().
 */

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$content = \FreePBX::Concurrencycount()->showPage();
?>
<div class="container-fluid">
	<div class="display no-border">
		<?php echo $content; ?>
	</div>
</div>
