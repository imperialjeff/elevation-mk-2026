<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-property-import-admin-settings-body wrap">

	<div class="ph-property-import-admin-settings-logs">

		<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-notice.php' ); ?>

		<form method="post" action="<?php echo esc_url(admin_url('admin.php?page=propertyhive_import_properties&tab=logs&action=search')); ?>">
			<p class="search-box">
				<label class="screen-reader-text" for="logs-search-input">Search Logs:</label>
				<input type="search" id="logs-search-input" name="log_search" value="<?php if ( isset($_POST['log_search']) ) { echo esc_attr($_POST['log_search']); } ?>">
				<input type="submit" id="search-submit" class="button" value="Search Logs">
			</p>
		</form>

		<h1><?php echo __( 'Import Logs', 'propertyhive' ); ?></h1>

		<?php 
			echo '<div class="logs-table">';
				echo $logs_table->display(); 
			echo '</div>';
		?>

	</div>

</div>