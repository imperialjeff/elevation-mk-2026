<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

	<div class="ph-import-wizard-other"<?php if ( isset($_POST['issue']) && esc_attr(sanitize_text_field($_POST['issue'])) == 'other' ) { }else{ echo ' style="display:none"'; } ?>>

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Get in touch</strong></p>

		<p>Our friendly UK-based support team are on hand to assist with any issues you're experiencing with property imports.</p>

		<p>Simply <a href="mailto:info@wp-property-hive.com">email us at info@wp-property-hive.com</a>, <strong>attaching the status report below</strong> and any additional information, and we'll do our best to assist.</p>

		<pre style="max-height:300px; overflow-y:auto; background:#EEE; border:1px solid #CCC; padding:15px 20px;"><?php

			// Ensure the WP_Debug_Data class is loaded
		    if ( !class_exists('WP_Debug_Data') ) 
		    {
		        require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		    }

		    $report  = '';

		    if ( isset($_POST['primary_issue']) && !empty($_POST['primary_issue']) )
		    {
		    	$report .= "Primary Issue: " . ucwords(str_replace("-", " ", ph_clean($_POST['primary_issue']))) . "\n\n";
		    }
		    if ( isset($_POST['post_id']) && !empty($_POST['post_id']) )
		    {
		    	$report .= "Property: " . get_the_title((int)$_POST['post_id']) . " (" . (int)$_POST['post_id'] . ")\n\n";
		    }
		    if ( isset($_POST['crm_id']) && !empty($_POST['crm_id']) )
		    {
		    	$report .= "CRM ID: " . ph_clean($_POST['crm_id']) . "\n\n";
		    }

			$options = get_option( 'propertyhive_property_import' );
		    if ( is_array($options) && !empty($options) )
		    {
		    	foreach ( $options as $import_id => $option )
				{
					if ( $import_id == (int)$_POST['import_id']  )
		            {
		            	$report .= "Import Details\n---\n";
		            	foreach ( $option as $key => $value )
		            	{
		            		$report  .= ucfirst(str_replace("_", " ", $key)) . ": " . ( is_array($value) ? json_encode($value) : $value) . "\n";
		            	}
		            	$report .= "\n";
		            }
				}
			}

			// Get the Site Health Debug Data
			$debug_data = WP_Debug_Data::debug_data();

			foreach ($debug_data as $section_name => $section_data) 
			{
		        $report .= strtoupper($section_name) . "\n---\n";
		        foreach ($section_data['fields'] as $field_name => $field_data) 
		        {
		            $label = isset($field_data['label']) ? $field_data['label'] : ucfirst($field_name);
		            $value = isset($field_data['value']) ? $field_data['value'] : '';
		            
		            // Handle array or complex data values
		            if (is_array($value)) 
		            {
		                $value = implode(', ', $value);
		            }
		            
		            $report .= $label . ': ' . $value . "\n";
		        }
		        $report .= "\n";
		    }

		    echo trim($report);

		?></pre>

		<a href="data:text/plain;charset=utf-8,<?php echo rawurlencode($report); ?>" download="status-report.txt" class="button">Download Status Report</a>

		<div class="buttons">
			<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties&tab=troubleshooting'); ?>">Back</a>
			<a href="mailto:info@wp-property-hive.com" class="button button-primary">Email info@wp-property-hive.com</a>
		</div>

	</div>