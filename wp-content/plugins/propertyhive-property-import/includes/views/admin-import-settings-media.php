<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Media', 'propertyhive' ); ?></h3>

<p><?php echo __( 'Specify which fields should be used for media', 'propertyhive' ); ?>:</p>

<?php
$media_types = array( 
	'image' => __( 'Image', 'propertyhive' ), 
	'floorplan' => __( 'Floorplan', 'propertyhive' ), 
	'brochure' => __( 'Brochure', 'propertyhive' ), 
	'epc' => __( 'EPC', 'propertyhive' ), 
	'virtual_tour' => __( 'Virtual Tour', 'propertyhive' ), 
);
$media_types = apply_filters( 'propertyhive_property_import_media_types', $media_types );

foreach ( $media_types as $media_type => $label )
{
?>

<h3><?php echo $label . 's'; ?></h3>

<table class="form-table media-<?php echo esc_attr($media_type); ?>-settings">
	<tbody>
		<tr class="csv-tip">
			<th><label for="<?php echo esc_attr($media_type); ?>_field_arrangement_individual"><?php echo esc_html($label . ' ' . __( 'Arrangement', 'propertyhive' )); ?></label></th>
			<td>
				<label style="display:block; padding:3px 0">
					<input type="radio" name="<?php echo $media_type; ?>_field_arrangement" id="<?php echo esc_attr($media_type); ?>_field_arrangement_individual" value=""<?php echo ( !isset($import_settings[$media_type . '_field_arrangement']) || ( isset($import_settings[$media_type . '_field_arrangement']) && $import_settings[$media_type . '_field_arrangement'] == '' ) ) ? ' checked' : ''; ?>>
					Each URL in a separate field
				</label>
				<label style="display:block; padding:3px 0">
					<input type="radio" name="<?php echo $media_type; ?>_field_arrangement" id="<?php echo esc_attr($media_type); ?>_field_arrangement_comma_delimited" value="comma_delimited"<?php echo ( isset($import_settings[$media_type . '_field_arrangement']) && $import_settings[$media_type . '_field_arrangement'] == 'comma_delimited' ) ? ' checked' : ''; ?>>
					All URL's in one field
				</label>
			</td>
		</tr>
		<tr class="media-comma-delimited-row">
			<th><label for="<?php echo esc_attr($media_type); ?>_field"><?php echo esc_html(__( 'Field Containing', 'propertyhive' ) . ' ' . $label . 's'); ?></label></th>
			<td>
				<select name="<?php echo esc_attr($media_type); ?>_field" id="<?php echo esc_attr($media_type); ?>_field">
					<?php 
						$options = ( isset($import_settings['property_field_options']) && !empty($import_settings['property_field_options']) ) ? json_decode($import_settings['property_field_options']) : array();

						$new_options = array();
						if ( !empty($options) )
						{
							foreach ( $options as $option_key => $option_value )
							{
								$field_name = $option_value;

								$new_options[$field_name] = $field_name;
							}
						}

						$options = $new_options; 

						foreach ( $options as $option_key => $option_value )
						{
							echo '<option value="' . esc_attr($option_key) . '"';
							if ( isset($import_settings[$media_type . '_field']) && $import_settings[$media_type . '_field'] == $option_key ) { echo ' selected'; }
							echo '>' . esc_html($option_value) . '</option>';
						} 
					?>
				</select>
			</td>
		</tr>
		<tr class="media-comma-delimited-row">
			<th><label for="<?php echo esc_attr($media_type); ?>_field_delimiter"><?php echo esc_html(__( 'Delimiter Character', 'propertyhive' )); ?></label></th>
			<td>
				<input type="text" name="<?php echo esc_attr($media_type); ?>_field_delimiter" id="<?php echo esc_attr($media_type); ?>_field_delimiter" style="width:50px;" value="<?php echo isset($import_settings[$media_type . '_field_delimiter']) ? $import_settings[$media_type . '_field_delimiter'] : ','; ?>">
			</td>
		</tr>
		<tr class="media-individual-row">
			<th><label for="<?php echo $media_type; ?>_fields"><?php echo esc_html(__( 'Fields Containing', 'propertyhive' ) . ' ' . $label); ?></label></th>
			<td>
				<textarea name="<?php echo esc_attr($media_type); ?>_fields" id="<?php echo esc_attr($media_type); ?>_fields" 
					data-xml-placeholder="{/<?php echo $media_type; ?>s/<?php echo $media_type; ?>[1]}&#10;{/<?php echo $media_type; ?>s/<?php echo $media_type; ?>[2]}&#10;{/<?php echo $media_type; ?>s/<?php echo $media_type; ?>[3]/url}|{/<?php echo $media_type; ?>s/<?php echo $media_type; ?>[3]/caption}&#10;{/<?php echo $media_type; ?>[0]}.jpg" 
					data-csv-placeholder="{<?php echo $label; ?> 1}|{<?php echo $label; ?> 1 Caption}&#10;{<?php echo $label; ?> 2}" 
					style="width:100%; height:120px; max-width:500px;"><?php echo isset($import_settings[$media_type . '_fields']) ? $import_settings[$media_type . '_fields'] : ''; ?></textarea>
				<div style="color:#999; font-size:13px; margin-top:5px;">
					Enter one <?php echo esc_html(strtolower($label)); ?> URL per line.<br>
					Separate with a pipe (|) character to specify the <?php echo esc_html(strtolower($label)); ?> caption.<span class="xml-tip"><br>
					Note: Uses the <a href="https://www.w3schools.com/xml/xpath_syntax.asp" target="_blank">XPath syntax</a>.</span>
				</div>
			</td>
		</tr>
	</tbody>
</table>

<hr>

<?php } ?>

<h3><?php echo __( 'Media Options', 'propertyhive' ); ?></h3>

<table class="form-table">
	<tbody>
		<tr>
			<th><label for="media_download_clause_url_change"><?php echo esc_html(__( 'Download Media', 'propertyhive' )); ?></label></th>
			<td style="padding-top:20px;">
				<div style="padding:3px 0"><label><input type="radio" name="media_download_clause" id="media_download_clause_always" value="always"<?php echo ( isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'always' ) ? ' checked' : ''; ?>> Every time an import runs</label></div>
				<div style="padding:3px 0"><label><input type="radio" name="media_download_clause" id="media_download_clause_url_change" value="url_change"<?php echo ( !isset($import_settings['media_download_clause']) || ( isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ) ) ? ' checked' : ''; ?>> Only if media URL changes (recommended)</label></div>
			</td>
		</tr>
	</tbody>
</table>