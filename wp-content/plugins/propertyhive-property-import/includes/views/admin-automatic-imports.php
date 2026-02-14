<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-property-import-admin-settings-body wrap">

	<div class="ph-property-import-admin-settings-automatic-imports">

		<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-notice.php' ); ?>

		<h1><?php echo __( 'Automatic Imports', 'propertyhive' ); ?></h1>

		<?php
			$all_imports_count = 0;
			$all_imports_active = 0;
			$all_imports_inactive = 0;
			$all_imports_running = 0;
			$format_counts = array();

	        foreach ( $imports as $key => $import )
	        {
	            if ( isset($imports[$key]['deleted']) && $imports[$key]['deleted'] == 1 )
	            {
	                unset( $imports[$key] );
	            }
	        }

	        foreach ( $imports as $key => $import )
	        {
	        	++$all_imports_count;

	        	if ( !isset($import['format']) )
	        	{
	        		continue;
	        	}

	        	if ( !isset($format_counts[$import['format']]) ) 
	        	{
	        		$format = propertyhive_property_import_get_import_format($import['format']);
	        		$format_counts[$import['format']] = array(
	        			'name' => ($format !== false ? $format['name'] : $import['format']),
	        			'count' => 0
	        		); 
	        	}
	        	++$format_counts[$import['format']]['count'];

	        	if ( isset($import['running']) && $import['running'] == 1 )
	        	{
	        		++$all_imports_active;

		        	$row = $wpdb->get_row( $wpdb->prepare("
		                SELECT 
		                    start_date, end_date
		                FROM 
		                    " .$wpdb->prefix . "ph_propertyimport_instance_v3
		                WHERE 
		                    import_id = %d
		                ORDER BY start_date DESC LIMIT 1
		            ", $key), ARRAY_A);
		            if ( null !== $row )
		            {
		                if ($row['start_date'] <= $row['end_date'])
		                {

		                }
		                elseif ($row['end_date'] == '0000-00-00 00:00:00')
		                {
		                    ++$all_imports_running;
		                }
		            }
	        	}
	        	else
	        	{
	        		++$all_imports_inactive;
	        	}
	        }
		?>

		<?php
			if ( !empty($imports) )
			{
		?>
		<ul class="subsubsub" style="margin-bottom:10px;">
			<li class="all"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties')); ?>"<?php if ( !isset($_GET['phpi_filter']) ) { echo ' class="current" aria-current="page"'; } ?>>All <span class="count">(<?php echo number_format($all_imports_count, 0); ?>)</span></a> |</li>
			<li class="active"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&phpi_filter=active')); ?>"<?php if ( isset($_GET['phpi_filter']) && $_GET['phpi_filter'] == 'active' ) { echo ' class="current" aria-current="page"'; } ?>>Active <span class="count">(<?php echo number_format($all_imports_active, 0); ?>)</span></a> |</li>
			<li class="inactive"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&phpi_filter=inactive')); ?>"<?php if ( isset($_GET['phpi_filter']) && $_GET['phpi_filter'] == 'inactive' ) { echo ' class="current" aria-current="page"'; } ?>>Inactive <span class="count">(<?php echo number_format($all_imports_inactive, 0); ?>)</span></a> |</li>
			<?php
				if ( !empty($format_counts) && count($format_counts) > 1 )
				{
					uasort($format_counts, 'propertyhive_property_import_compare_by_name');
					foreach ( $format_counts as $format_key => $format_details )
					{
			?>
						<li class="format"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&phpi_filter=format&phpi_filter_format=' . $format_key)); ?>"<?php if ( isset($_GET['phpi_filter']) && $_GET['phpi_filter'] == 'format' && isset($_GET['phpi_filter_format']) && $_GET['phpi_filter_format'] == $format_key ) { echo ' class="current" aria-current="page"'; } ?>><?php echo $format_details['name']; ?> <span class="count">(<?php echo number_format($format_details['count'], 0); ?>)</span></a> |</li>
			<?php
					}
				}
			?>
			<li class="running"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&phpi_filter=running')); ?>"<?php if ( isset($_GET['phpi_filter']) && $_GET['phpi_filter'] == 'running' ) { echo ' class="current" aria-current="page"'; } ?>>Running Now <span class="count">(<?php echo number_format($all_imports_running, 0); ?>)</span></a></li>
		</ul>
		<?php
				echo '<div class="automatic-imports-table">' . __('Loading', 'propertyhive') . '...</div>';

				if ( $run_now_button )
				{
					$orderby = (!empty($_REQUEST['orderby'])) ? sanitize_text_field($_REQUEST['orderby']) : '';
			        $order = (!empty($_REQUEST['order']) && in_array(strtolower($_REQUEST['order']), array('asc', 'desc')) ) ? sanitize_text_field($_REQUEST['order']) : '';

			        $phpi_filter = (!empty($_REQUEST['phpi_filter'])) ? sanitize_text_field($_REQUEST['phpi_filter']) : '';
			        $phpi_filter_format = (!empty($_REQUEST['phpi_filter_format'])) ? sanitize_text_field($_REQUEST['phpi_filter_format']) : '';
					
					$nonce = wp_create_nonce('propertyhive_property_import');

					echo '<a href="' . admin_url('admin.php?page=propertyhive_import_properties&custom_property_import_cron=phpropertyimportcronhook&orderby=' . $orderby . '&order=' . $order . '&phpi_filter=' . $phpi_filter . '&phpi_filter_format=' . $phpi_filter_format . '&_wpnonce=' . $nonce) . '" class="button button-manually-execute" onclick="phpi_click_run_now();" rel="nofollow noopener noreferrer">Manually Execute Import</a>';
				}
			}
			else
			{

				$assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', PH_PROPERTYIMPORT_PLUGIN_FILE ) ) ) . '/assets/';
		?>

		<div class="import-start-screens">

		    <div class="import-start-screen automatic">

		        <p style="font-size:15px; margin-top:0"><?php echo __( 'Select your CRM below to <strong>automatically import</strong> your property listings.', 'propertyhive' ); ?></p>
		        <br>
		        <div class="logos">
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=xml_vebra_api')); ?>" style="background-color:#f89d14"><img src="<?php echo $assets_path; ?>images/crm-logos/vebra-alto.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=json_street')); ?>"><img src="<?php echo $assets_path; ?>images/crm-logos/street.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=json_loop_v2')); ?>" style="background-color:#0d132e"><img src="<?php echo $assets_path; ?>images/crm-logos/loop.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=xml_10ninety')); ?>" style="background-color:#2c3e50"><img src="<?php echo $assets_path; ?>images/crm-logos/10ninety.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=json_dezrez')); ?>" style="background-color:#475763"><img src="<?php echo $assets_path; ?>images/crm-logos/dezrez.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=xml_sme_professional')); ?>"><img src="<?php echo $assets_path; ?>images/crm-logos/sme-professional.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=xml_expertagent')); ?>" style="background-color:#2ca9df"><img src="<?php echo $assets_path; ?>images/crm-logos/expertagent.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=xml_jupix')); ?>" style="background-color:#bd1b25"><img src="<?php echo $assets_path; ?>images/crm-logos/jupix.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=json_reapit_foundations')); ?>"><img src="<?php echo $assets_path; ?>images/crm-logos/reapit.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=json_kato')); ?>" style="background-color:#ff4a6b"><img src="<?php echo $assets_path; ?>images/crm-logos/kato.png" alt=""></a></div>
		            <div class="logo"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport&format=json_rex')); ?>"><img src="<?php echo $assets_path; ?>images/crm-logos/rex.png" alt=""></a></div>
		            <div class="logo other"><a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&action=addimport')); ?>"><span>Other...</span><span><strong>Don't see your CRM?</strong> Click here to view the full list of supported formats.</span></a></div>
		        </div>
		        <br>
		        <p class="help-link">Need help? Check out <a href="https://docs.wp-property-hive.com/category/294-property-import" target="_blank">our documentation</a> or <a href="https://wp-property-hive.com/support/" target="_blank">contact support</a>.</p>

		    </div>

		</div>

		<?php
			}
		?>

	</div>

</div>