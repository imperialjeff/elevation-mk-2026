<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-import-wizard-property-not-removed"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'property-not-removed' ) { }else{ echo ' style="display:none"'; } ?>>

	<form method="post">

		<input type="hidden" name="primary_issue" value="<?php echo esc_attr(sanitize_text_field($_POST['issue'])); ?>">
		<input type="hidden" name="import_id" value="<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>">
		<input type="hidden" name="post_id" value="<?php echo ( isset($_POST['post_id']) ? (int)$_POST['post_id'] : '' ); ?>">
		<input type="hidden" name="issue" value="other">

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Property not getting removed</strong></p>

		<div id="ph_checking_not_removed">Looking for property. Please wait one moment...</div>

		<div class="buttons">
			<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&tab=troubleshooting'); ?>">Back</a>
			<input type="submit" value="This didn't solve my issue" class="button button-primary">
		</div>

	</form>

</div>

<script>

jQuery(document).ready(function($)
{
	var data = {
		'action': 'propertyhive_check_for_property_not_removed',
		'import_id': '<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>',
		'post_id': '<?php echo ( isset($_POST['post_id']) ? (int)$_POST['post_id'] : '' ); ?>'
	};

	$.post( ajaxurl, data, function(response) 
	{
		if ( response.success )
		{
			$('#ph_checking_not_removed').html(response.message);
		}
		else
		{
			$('#ph_checking_not_removed').html(response.error);
		}
	});
});

</script>