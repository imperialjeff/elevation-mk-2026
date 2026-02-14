<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<form method="POST" action="">

	<input type="hidden" name="save_phpi_settings" value="yes">
	<?php wp_nonce_field( 'save-phpi-settings' ); ?>

	<div class="ph-property-import-admin-settings-body wrap">

		<div class="ph-property-import-admin-settings-import-settings">

			<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-notice.php' ); ?>

			<h1><?php echo __( 'Settings', 'propertyhive' ); ?></h1>

			<div class="settings-area">

				<div class="left-tabs">
					<ul>
						<li class="active"><a href="#emailreports"><span class="dashicons dashicons-email"></span> <?php echo __( 'Email Reports', 'propertyhive' ); ?></a></li>
						<li><a href="#offmarket"><span class="dashicons dashicons-trash"></span> <?php echo __( 'Removing Properties', 'propertyhive' ); ?></a></li>
						<li><a href="#media"><span class="dashicons dashicons-admin-media"></span> <?php echo __( 'Media Processing', 'propertyhive' ); ?></a></li>
					</ul>
				</div>

				<div class="right-settings">

					<div class="buttons">

						<input type="submit" value="<?php echo __( 'Save changes', 'propertyhive' ); ?>" class="button button-primary">

					</div>

					<div class="settings-panels">

						<div class="settings-panel" id="emailreports">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-settings-email-reports.php' ); ?>
						</div>

						<div class="settings-panel" id="offmarket" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-settings-off-market.php' ); ?>
						</div>

						<div class="settings-panel" id="media" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-settings-media.php' ); ?>
						</div>
						
					</div>

					<div class="buttons bottom">

						<input type="submit" value="<?php echo __( 'Save changes', 'propertyhive' ); ?>" class="button button-primary">

					</div>

				</div>

			</div>

		</div>

	</div>

</form>