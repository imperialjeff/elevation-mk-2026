<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

	<div class="ph-import-wizard-property-wrong-data"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'property-wrong-data' ) { }else{ echo ' style="display:none"'; } ?>>

		<form method="post">

			<input type="hidden" name="primary_issue" value="<?php echo esc_attr(sanitize_text_field($_POST['issue'])); ?>">
			<input type="hidden" name="import_id" value="<?php echo ( isset($_POST['import_id']) ? (int)$_POST['import_id'] : '' ); ?>">
			<input type="hidden" name="crm_id" value="<?php echo ( isset($_POST['crm_id']) ? $_POST['crm_id'] : '' ); ?>">
			<input type="hidden" name="issue" value="other">

			<p style="margin-bottom:18px; font-size:1.1em"><strong>Property importing with missing or wrong data</strong></p>

			<p>If the property in question is importing with missing data for a field such as availability, property type, price qualifier etc, it's likely you don't have the import mappings completed correctly.</p>

			<p>Please ensure that all mappings have been completed and that a full feed is re-ran:</p>

			<p><a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . (int)$_POST['import_id']); ?>#taxonomies" class="button button-primary">Edit Custom Field Mappings</a></p>

			<hr style="margin-top:20px;">

			<p style="margin-bottom:18px; font-size:1.1em"><strong>Importing additional fields</strong></p>

			<p>If the CRM is sending data for a field that we don't have by default in Property Hive you'll need to perform a few steps to get this data importing:</p>

			<p><strong>Step 1: Create an additional field in Property Hive</strong></p>

			<p>First you'll need to create an additional field to store the data provided. The easiest way to do this is using our Template Assistant add on:</p>

			<?php
				if ( class_exists('PH_Template_Assistant') )
				{
					echo '<p><a href="' . admin_url('admin.php?page=ph-settings&tab=template-assistant&section=custom-fields') . '" class="button button-primary">Create Additional Field</a></p>';
				}
				else
				{
					echo '<p><a href="' . admin_url('admin.php?page=ph-settings&tab=features') . '" class="button button-primary">Activate Template Assisant</a></p>';
				}
			?>

			<p><strong>Step 2: Import data into this new field</strong></p>

			<p>There are two ways you can do this. The easiest is to create a field rule in the <a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . (int)$_POST['import_id']); ?>#fieldmapping">import settings</a>:</p>

			<?php $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', PH_PROPERTYIMPORT_PLUGIN_FILE ) ) ) . '/assets/'; ?>
			<p><img src="<?php echo $assets_path; ?>images/troubleshooting/field-rule.png" alt=""></p>

			<p><a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . (int)$_POST['import_id']); ?>#fieldmapping" class="button button-primary">Add Field Rule</a></p>

			<p>Alternatively, you can use a PHP snippet like so to import the data from the CRM into this field you've created</p>

			<?php
				$format = '';
				$options = get_option( 'propertyhive_property_import' );
	            if ( is_array($options) && !empty($options) )
	            {
	                if ( isset($options[(int)$_POST['import_id']]) )
	                {
	                    $option = $options[(int)$_POST['import_id']];

	                    $format = isset($option['format']) ? $option['format'] : '';
	                }
	            }

				$hook_name = '';
				$type = 'array';
				switch ($format)
				{
					default:
					{
						$hook_name = 'propertyhive_property_imported_' . $format;
						if ( strpos($format, 'xml') !== false )
						{
							$type = 'object';
						}
					}
				}
			?>
			<pre style="overflow:auto; padding:10px; background:#EEE; border-top:1px solid #CCC; border-bottom:1px solid #CCC">do_action( "<?php echo $hook_name ; ?>", "import_additional_field", 10, 2 );
function import_additional_field( $post_id, $property )
{
  update_post_meta( $post_id, '_your_field_name_here', <?php echo ( $type == 'object' ? '(string)$property->field_name_in_crm_data' : '$property[\'field_name_in_crm_data\']' ); ?> );
}</pre>

			<p>The above snippet should be amended accordingly and can be placed in your themes <code>functions.php</code> file, or added using a plugin like <a href="https://wordpress.org/plugins/code-snippets/" target="_blank">Code Snippets</a>.

				<p>Once you've done the above steps, don't forget to re-run a full feed.</p>

			<div class="buttons">
				<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&tab=troubleshooting'); ?>">Back</a>
				<input type="submit" value="This didn't solve my issue" class="button button-primary">
			</div>

		</form>

	</div>