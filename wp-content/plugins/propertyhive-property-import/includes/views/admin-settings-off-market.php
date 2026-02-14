<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Removing Properties', 'propertyhive' ); ?></h3>

<p>Here you can control what happens when a property is removed from the CRM feed.</p>

<p><strong>Note:</strong> Changing this option only effects properties removed from imports going forward.</p>

<table class="form-table">
	<tbody>
		<tr>
			<th><label for="remove_action"><?php echo __( 'When properties are removed from imports', 'propertyhive' ); ?></label></th>
			<td style="padding-top:20px;">

				<?php $remove_action = get_option( 'propertyhive_property_import_remove_action', 'remove_all_media_except_first_image' ); ?>

				<div style="padding:3px 0">
					<label>
						<input type="radio" name="remove_action" id="remove_action" value="nothing" <?php if ( $remove_action == 'nothing' ) { echo ' checked'; } ?>> 
						Do nothing and leave on the market
					</label>
				</div>

				<div style="padding:3px 0">
					<label>
						<input type="radio" name="remove_action" id="remove_action" value="" <?php if ( $remove_action == '' ) { echo ' checked'; } ?>> 
						Take off of the market
					</label>
				</div>

				<div style="padding:3px 0">
					<label>
						<input type="radio" name="remove_action" id="remove_action_remove_all_media" value="remove_all_media" <?php if ( $remove_action == 'remove_all_media' ) { echo ' checked'; } ?>> 
						Take off of the market and remove all media (images, floorplans etc) to free up disk space
					</label>
				</div>

				<div style="padding:3px 0">
					<label>
						<input type="radio" name="remove_action" id="remove_action_remove_all_media_except_first_image" value="remove_all_media_except_first_image" <?php if ( $remove_action == 'remove_all_media_except_first_image' ) { echo ' checked'; } ?>> 
						Take off of the market and remove all media (images, floorplans etc) to free up disk space, except the first image
					</label>
				</div>

				<div style="padding:3px 0">
					<label>
						<input type="radio" name="remove_action" id="remove_action_draft_property" value="draft_property" <?php if ( $remove_action == 'draft_property' ) { echo ' checked'; } ?>> 
						Take off of the market and draft the property record
					</label>
				</div>

				<div style="padding:3px 0">
					<label>
						<input type="radio" name="remove_action" id="remove_action_remove_property" value="remove_property" <?php if ( $remove_action == 'remove_property' ) { echo ' checked'; } ?>> 
						Take off of the market and delete the property record and all associated media
					</label>
				</div>

			</td>
		</tr>
	</tbody>
</table>