<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-import-wizard-property-not-importing"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'property-not-importing' ) { }else{ echo ' style="display:none"'; } ?>>

	<form method="post">

		<input type="hidden" name="primary_issue" value="<?php echo esc_attr(sanitize_text_field($_POST['issue'])); ?>">
		<input type="hidden" name="import_id" value="<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>">
		<input type="hidden" name="crm_id" value="<?php echo ( isset($_POST['crm_id']) ? $_POST['crm_id'] : '' ); ?>">
		<input type="hidden" name="issue" value="other">

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Property not importing</strong></p>

		<div id="ph_checking_not_importing">Looking for property. Please wait one moment...</div>

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
		'action': 'propertyhive_check_for_property_in_import',
		'import_id': '<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>',
		'crm_id': '<?php echo ( isset($_POST['crm_id']) ? $_POST['crm_id'] : '' ); ?>'
	};

	$.post( ajaxurl, data, function(response) 
	{
		if ( response.success )
		{
			$('#ph_checking_not_importing').html(response.message);
		}
		else
		{
			$('#ph_checking_not_importing').html(response.error);
		}
	});
});

</script>