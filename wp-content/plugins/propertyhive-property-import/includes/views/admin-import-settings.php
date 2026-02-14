<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<form method="POST" action="">

	<input type="hidden" name="import_id" value="<?php echo ( $import_id !== FALSE ? $import_id : '' ); ?>">
	<input type="hidden" name="save_import_settings" value="yes">
	<?php wp_nonce_field( 'save-import-settings' ); ?>

	<div class="ph-property-import-admin-settings-body wrap">

		<div class="ph-property-import-admin-settings-import-settings">

			<h1><?php echo ( $import_id !== false ) ? __( 'Edit Import', 'propertyhive' ) : __( 'Create Import', 'propertyhive' ); ?></h1>

			<div class="settings-area">

				<div class="left-tabs">
					<ul>
						<li id="import_setting_tab_format" class="active"><a href="#format"><span class="dashicons dashicons-editor-code"></span> <?php echo __( 'Import Format', 'propertyhive' ); ?></a></li>
						<li id="import_setting_tab_frequency"><a href="#frequency"><span class="dashicons dashicons-clock"></span> <?php echo __( 'Frequency', 'propertyhive' ); ?></a></li>
						<li id="import_setting_tab_taxonomies"><a href="#taxonomies"><span class="dashicons dashicons-tag"></span> <?php echo __( 'Custom Field Mapping', 'propertyhive' ); ?></a></li>
						<li id="import_setting_tab_offices"><a href="#offices"><span class="dashicons dashicons-building"></span> <?php echo __( 'Offices', 'propertyhive' ); ?></a></li>
						<li id="import_setting_tab_fieldmapping"><a href="#fieldmapping"><span class="dashicons dashicons-admin-links"></span></span> <?php echo __( 'Field Rules', 'propertyhive' ); ?><span id="field_mapping_warning" style="color:#999; display:none">&nbsp;&nbsp;<span class="dashicons dashicons-warning"></span></span></a></li>
						<li id="import_setting_tab_media"><a href="#media"><span class="dashicons dashicons-admin-media"></span> <?php echo __( 'Media', 'propertyhive' ); ?></a></li>
						<li id="import_setting_tab_advanced"><a href="#advanced"><span class="dashicons dashicons-admin-settings"></span> <?php echo __( 'Advanced', 'propertyhive' ); ?></a></li>
					</ul>
				</div>

				<div class="right-settings">

					<div class="buttons">

						<div class="running-status-toggle">
							
							Import Active

							<label class="ph-property-import-switch">
							  	<input type="checkbox" name="running" value="yes"<?php if ( isset($import_settings['running']) && $import_settings['running'] == 1 ) { echo ' checked'; } ?>>
							  	<span class="ph-property-import-slider"></span>
							</label>

						</div>

						<input type="submit" value="<?php echo __( 'Save changes', 'propertyhive' ); ?>" class="button button-primary">&nbsp;
						<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties'); ?>" class="button">Cancel</a>

					</div>

					<div class="settings-panels">

						<div class="settings-panel" id="format">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-format.php' ); ?>
						</div>

						<div class="settings-panel" id="frequency" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-frequency.php' ); ?>
						</div>

						<div class="settings-panel" id="taxonomies" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-taxonomies.php' ); ?>
						</div>

						<div class="settings-panel" id="offices" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-offices.php' ); ?>
						</div>

						<div class="settings-panel" id="fieldmapping" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-field-mapping.php' ); ?>
						</div>

						<div class="settings-panel" id="media" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-media.php' ); ?>
						</div>

						<div class="settings-panel" id="advanced" style="display:none">
							<?php include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings-advanced.php' ); ?>
						</div>

					</div>

					<div class="buttons bottom">

						<input type="submit" value="<?php echo __( 'Save changes', 'propertyhive' ); ?>" class="button button-primary">&nbsp;
						<a href="<?php echo admin_url('admin.php?page=propertyhive_import_properties'); ?>" class="button">Cancel</a>

					</div>

				</div>

			</div>

		</div>

	</div>

</form>