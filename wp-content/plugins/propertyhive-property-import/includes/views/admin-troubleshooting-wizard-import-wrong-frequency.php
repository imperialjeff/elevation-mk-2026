<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-import-wizard-import-wrong-frequency"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'import-wrong-frequency' ) { }else{ echo ' style="display:none"'; } ?>>

	<form method="post">

		<input type="hidden" name="primary_issue" value="<?php echo esc_attr(sanitize_text_field($_POST['issue'])); ?>">
		<input type="hidden" name="import_id" value="<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>">
		<input type="hidden" name="issue" value="other">

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Imports running at the wrong frequency</strong></p>

		<p>If imports aren't executing at the frequency specific it's normally to do with how automated tasks in WordPress operate and the fact they rely on visitors to the site to execute.</p>

		<p>We have some docs below about how automated tasks in WordPress work and how to troubleshoot these:</p>

		<a href="https://docs.wp-property-hive.com/article/295-requirements#heading-0" target="_blank" class="button button-primary button-hero">View Troubleshooting Documentation <span class="dashicons dashicons-external" style="vertical-align:middle; margin-top:-2px;"></span></a>
		
		<div class="buttons">
			<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&tab=troubleshooting'); ?>">Back</a>
			<input type="submit" value="This didn't solve my issue" class="button button-primary">
		</div>

	</form>

</div>