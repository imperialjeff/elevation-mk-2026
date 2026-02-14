<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Advanced', 'propertyhive' ); ?></h3>

<p><?php echo __( 'Advanced import options', 'propertyhive' ); ?>.</p>

<table class="form-table">
	<tbody>
		<tr id="row_custom_name">
			<th><label for="custom_name"><?php echo __( 'Import Name', 'propertyhive' ); ?></label></th>
			<td style="padding-top:20px;">
				<input type="text" name="custom_name" id="custom_name" value="<?php echo isset($import_settings['custom_name']) ? esc_attr($import_settings['custom_name']) : '' ; ?>">
				<div style="color:#999; font-size:13px; margin-top:5px;"><?php echo __( 'Used to distinguish between imports when you have multiple running at once. Leave blank to use the format type as the name.', 'propertyhive' ); ?></div>
			</td>
		</tr>
		<tr id="row_limit">
			<th><label for="limit"><?php echo __( 'Limit Number of Properties Imported', 'propertyhive' ); ?></label></th>
			<td style="padding-top:20px;">
				<input type="number" name="limit" id="limit" min="1" value="<?php echo isset($import_settings['limit']) ? esc_attr($import_settings['limit']) : '' ; ?>">
				<div style="color:#999; font-size:13px; margin-top:5px;"><?php echo __( 'To restrict the number of properties imported, enter an amount here. Leave blank for no limit.', 'propertyhive' ); ?></div>
			</td>
		</tr>
		<tr id="row_limit_images">
			<th><label for="limit_images"><?php echo __( 'Limit Number of Images Imported Per Property', 'propertyhive' ); ?></label></th>
			<td style="padding-top:20px;">
				<input type="number" name="limit_images" id="limit_images" min="1" value="<?php echo isset($import_settings['limit_images']) ? esc_attr($import_settings['limit_images']) : '' ; ?>">
				<div style="color:#999; font-size:13px; margin-top:5px;"><?php echo __( 'To restrict the number of images imported per property, enter an amount here. Leave blank for no limit.', 'propertyhive' ); ?></div>
			</td>
		</tr>
	</tbody>
</table>