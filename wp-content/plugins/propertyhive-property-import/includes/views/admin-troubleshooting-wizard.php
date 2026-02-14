<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<form method="POST" action="">

	<input type="hidden" name="save_phpi_troubleshooting_wizard" value="yes">
	<?php wp_nonce_field( 'save-phpi-troubleshooting' ); ?>

	<div class="ph-property-import-admin-settings-body wrap">

		<div class="ph-property-import-admin-settings-import-settings">

			<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-notice.php' ); ?>

			<h1><?php echo __( 'Troubleshooting Wizard', 'propertyhive' ); ?></h1>

			<div class="ph-import-wizard">

				<?php 
					if ( isset($_POST['issue']) ) 
					{ 
				?>
					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-import-not-automatic.php' ); ?>

					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-import-not-completing.php' ); ?>

					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-import-wrong-frequency.php' ); ?>

					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-property-not-importing.php' ); ?>

					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-property-wrong-data.php' ); ?>

					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-property-not-removed.php' ); ?>

					<?php include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-other.php' ); ?>
				<?php 
					} 
					else
					{
						include(  dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard-issues.php' );
					}
				?>

			</div>

		</div>

	</div>

</form>