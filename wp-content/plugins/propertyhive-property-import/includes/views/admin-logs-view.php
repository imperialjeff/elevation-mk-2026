<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php 
	$extra_query_string = ( isset($_GET['paged']) ? '&paged=' . (int)$_GET['paged'] : '' );
	$extra_query_string .= ( isset($_GET['orderby']) ? '&orderby=' . sanitize_text_field($_GET['orderby']) : '' );
	$extra_query_string .= ( isset($_GET['order']) ? '&order=' . sanitize_text_field($_GET['order']) : '' );
?>

<div class="ph-property-import-admin-settings-body wrap">

	<div class="ph-property-import-admin-settings-logs">

		<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-notice.php' ); ?>

		<h1><?php echo esc_html(__( 'Import Logs', 'propertyhive' )); ?></h1>

		<div class="log-buttons log-buttons-top">
			<a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&tab=logs' . ( isset($_GET['import_id']) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . $extra_query_string )); ?>" class="button">Back To Logs</a>
		
			<?php
				if ( $previous_instance !== false )
				{
					echo ' <a href="' . esc_url(admin_url( 'admin.php?page=propertyhive_import_properties&tab=logs&action=view&log_id=' . (int)$previous_instance . ( isset($_GET['import_id']) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . $extra_query_string )) . '" class="button">Previous Log</a> ';
				}
				if ( $next_instance !== false )
				{
					echo ' <a href="' . esc_url(admin_url( 'admin.php?page=propertyhive_import_properties&tab=logs&action=view&log_id=' . (int)$next_instance . ( isset($_GET['import_id']) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . $extra_query_string)) . '" class="button">Next Log</a> ';
				}
			?>
		</div>

		<?php 
			echo '<div class="logs-table">';
				echo $logs_view_table->display(); 
			echo '</div>';
		?>

		<div class="log-buttons log-buttons-bottom">
			<a href="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&tab=logs' . ( isset($_GET['import_id']) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . $extra_query_string )); ?>" class="button">Back To Logs</a>
		
			<?php
				if ( $previous_instance !== false )
				{
					echo ' <a href="' . esc_url(admin_url( 'admin.php?page=propertyhive_import_properties&tab=logs&action=view&log_id=' . (int)$previous_instance . ( isset($_GET['import_id']) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . $extra_query_string )) . '" class="button">Previous Log</a> ';
				}
				if ( $next_instance !== false )
				{
					echo ' <a href="' . esc_url(admin_url( 'admin.php?page=propertyhive_import_properties&tab=logs&action=view&log_id=' . (int)$next_instance . ( isset($_GET['import_id']) ? '&import_id=' . (int)$_GET['import_id'] : '' ) . $extra_query_string )) . '" class="button">Next Log</a> ';
				}
			?>
		</div>

	</div>

</div>