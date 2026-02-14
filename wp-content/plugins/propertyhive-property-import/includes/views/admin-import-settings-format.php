<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Import Format', 'propertyhive' ); ?></h3>

<p><?php echo __( 'Select the CRM or format that you want to import using below', 'propertyhive' ); ?>:</p>

<table class="form-table">
	<tbody>
		<tr>
			<th><label for="format"><?php echo __( 'Choose Format', 'propertyhive' ); ?></label></th>
			<td>
				<select name="format" id="format" style="width:250px;">
					<option value=""></option>
					<?php
						foreach ( $formats as $key => $format )
						{
							$selected = false;
							if ( isset($import_settings['format']) && $import_settings['format'] == $key )
							{
								$selected = true;
							}
							elseif ( isset($_GET['format']) && sanitize_text_field($_GET['format']) == $key )
							{
								$selected = true;
							}
							echo '<option value="' . $key . '"';
							echo ( $selected === true ? ' selected' : '' );
							echo '>' . esc_html($format['name']) . '</option>';
						}
					?>
				</select>
				<input type="hidden" name="previous_format" value="<?php echo (isset($import_settings['format']) ? $import_settings['format'] : ''); ?>">
			</td>
		</tr>
	</tbody>
</table>

<?php
	foreach ( $formats as $key => $format )
	{
?>

<div id="import_settings_<?php echo esc_attr($key); ?>" class="import-settings-format" style="display:none">

<h3><?php echo esc_html($format['name']) . ' ' . esc_html(__( 'Settings', 'propertyhive' )); ?></h3>

<table class="form-table">
	<tbody>
		<?php
			foreach ( $format['fields'] as $field )
			{
				if ( $field['type'] == 'hidden' )
				{
					echo '<input 
						type="' . esc_attr($field['type']) . '" 
						name="' . esc_attr($key . '_' . $field['id']) . '" 
						value="' . ( ( isset($import_settings[$field['id']]) ) ? esc_attr($import_settings[$field['id']]) : ( isset($field['default']) ? esc_attr($field['default']) : '' ) ) . '" 
					>';
					continue;
				}

				if ( 
					$key == 'json_reapit_foundations' && 
					$field['type'] == 'html' && 
					$field['label'] == 'agreed' 
				)
				{
					if ( !isset($import_settings['agree_user_id']) || ( isset($import_settings['agree_user_id']) && empty($import_settings['agree_user_id']) ) )
					{
						continue;
					}

					$user = get_user_by( 'ID', $import_settings['agree_user_id'] );

					$field['label'] = '';
					$field['html'] = 'Agreed by ' . $user->display_name . ' on ' . date("H:i jS F Y", $import_settings['agree_datetime']);
				}
		?>
			<tr<?php if ( isset($field['id']) && !empty($field['id']) ) { ?> id="row_<?php echo esc_attr($key . '_' . $field['id']); ?>"<?php } ?>>
				<th><?php echo isset($field['label']) ? esc_html($field['label']) : ''; ?></th>
				<td><?php
					if ( isset($field['before']) )
					{
						echo esc_html($field['before']) . ' ';
					}

					switch ($field['type'])
					{
						case "text":
						case "number":
						case "password":
						{
							$type = $field['type'];
							if ( $field['type'] == 'password' ) { $type = 'text'; }
							echo '<input 
								type="' . esc_attr($type) . '" 
								name="' . esc_attr($key . '_' . $field['id']) . '" 
								value="' . ( ( isset($import_settings[$field['id']]) ) ? esc_attr($import_settings[$field['id']]) : ( isset($field['default']) ? esc_attr($field['default']) : '' ) ) . '" 
								placeholder="' . ( isset($field['placeholder']) ? esc_attr($field['placeholder']) : '' ) . '"
								style="width:100%; max-width:450px;' . ( isset($field['css']) ? ' ' . esc_attr($field['css']) : '' ) . '"
							>';
							echo ( isset($field['tooltip']) ? '<div style="color:#999; font-size:13px; margin-top:5px;">' . wp_kses($field['tooltip'], array('br' => array())) . '</div>' : '' );
							break;
						}
						case "checkbox":
						{
							echo '<input 
								type="checkbox" 
								name="' . esc_attr($key . '_' . $field['id']) . '" 
								value="yes"
								' . ( ( isset($import_settings[$field['id']]) && ( $import_settings[$field['id']] == 'yes' || $import_settings[$field['id']] == '1' ) ) ? 'checked' : ( ( !isset($import_settings[$field['id']]) && isset($field['default']) && ( $field['default'] == 'yes' || $field['default'] == '1' ) ) ? 'checked' : '' ) ) . '
							>';
							echo ( isset($field['tooltip']) ? '<div style="color:#999; font-size:13px; margin-top:5px;">' . wp_kses($field['tooltip'], array('br' => array())) . '</div>' : '' );
							break;
						}
						case "radio":
						{
							$options = array();
							if ( isset($field['options']) && is_array($field['options']) && !empty($field['options']) )
							{
								$options = $field['options'];
							}

							if ( !empty($options) )
							{
								foreach ( $options as $option_key => $option_value )
								{
									echo '<div style="margin-bottom:5px;"><label><input type="radio" name="' . esc_attr($key . '_' . $field['id']) . '" value="' . $option_key . '"';
									if (
										( isset($import_settings[$field['id']]) && $import_settings[$field['id']] == $option_key )
										||
										( !isset($import_settings[$field['id']]) && ( isset($field['default']) && $field['default'] == $option_key ) )
									)
									{
										echo ' checked';
									}
									echo '> ' . esc_html($option_value) . '</label></div>';
								}
							}
							echo ( isset($field['tooltip']) ? '<div style="color:#999; font-size:13px; margin-top:5px;">' . wp_kses($field['tooltip'], array('br' => array())) . '</div>' : '' );
							break;
						}
						case "select":
						case "multiselect":
						{
							echo '<select 
								name="' . esc_attr($key . '_' . $field['id']) . ( $field['type'] == 'multiselect' ? '[]' : '' ) . '" 
								' . ( $field['type'] == 'multiselect' ? 'multiple' : '' ) . '
							>';
							$options = array();
							if ( isset($field['options']) && is_array($field['options']) && !empty($field['options']) )
							{
								$options = $field['options'];
							}
							elseif ( isset($import_settings[$field['id'] . '_options']) && !empty($import_settings[$field['id'] . '_options']) )
							{
								$options = json_decode($import_settings[$field['id'] . '_options']);

								$new_options = array();
								if ( !empty($options) )
								{
									foreach ( $options as $option_key => $option_value )
									{
										$new_options[$option_value] = $option_value;
									}
								}

								$options = $new_options; 
							}

							if ( $field['id'] == 'property_id_node' )
							{
								if ( isset($import_settings['property_node_options']) && !empty($import_settings['property_node_options']) )
								{
									// use options from property_node_options
									$options = json_decode($import_settings['property_node_options']);

									$new_options = array();
									if ( !empty($options) )
									{
										foreach ( $options as $option_key => $option_value )
										{
											$node_name = $option_value;
											if ( isset($import_settings['property_node']) && !empty($import_settings['property_node']) )
											{
												if ( strpos($node_name, $import_settings['property_node']) === false )
												{
													continue;
												}

												$node_name = str_replace($import_settings['property_node'], '', $node_name);
											}

											$new_options[$node_name] = $node_name;
										}
									}

									$options = $new_options; 
								}
							}

							if ( $field['id'] == 'property_id_field' )
							{
								if ( isset($import_settings['property_field_options']) && !empty($import_settings['property_field_options']) )
								{
									// use options from property_field_options
									$options = json_decode($import_settings['property_field_options']);

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
								}
							}

							if ( !empty($options) )
							{
								foreach ( $options as $option_key => $option_value )
								{
									echo '<option value="' . $option_key . '"';
									if (
										(
											$field['type'] == 'select' &&
											(
												( isset($import_settings[$field['id']]) && $import_settings[$field['id']] == $option_key )
												||
												( !isset($import_settings[$field['id']]) && ( isset($field['default']) && $field['default'] == $option_key ) )
											)
										)
										||
										(
											$field['type'] == 'multiselect' &&
											(
												( isset($import_settings[$field['id']]) && is_array($import_settings[$field['id']]) && in_array($option_key, $import_settings[$field['id']]) )
												||
												( !isset($import_settings[$field['id']]) && ( isset($field['default']) && in_array($option_key, $field['default']) ) )
											)
										)
									)
									{
										echo ' selected';
									}
									echo '>' . esc_html($option_value) . '</option>';
								}
							}
							else
							{
								if ( isset($field['no_options_value']) && !empty($field['no_options_value']) )
								{
									echo '<option value="">' . $field['no_options_value'] . '</option>';
								}
							}
							echo '</select>';
							echo ( isset($field['tooltip']) ? '<div style="color:#999; font-size:13px; margin-top:5px;">' . wp_kses($field['tooltip'], array('br' => array())) . '</div>' : '' );
							break;
						}
						case "html":
						{
							echo $field['html'];
							break;
						}
					}

					if ( isset($field['after']) )
					{
						echo ' ' . esc_html($field['after']);
					}
				?></td>
			</tr>
		<?php
			}
			if ( isset($format['test_button']) && $format['test_button'] === true )
			{
				echo '<tr>
					<th>&nbsp;</th>
					<td>
						<a href="" data-format="' . $key . '" class="test-import-details button">Test Details</a>
						<div class="test-results-success notice notice-success inline" style="display:none; margin-top:20px"></div>
                        <div class="test-results-error notice notice-error inline" style="display:none; margin-top:20px"></div>
					</td>
				</tr>';
			}
		?>
	</tbody>
</table>

<?php
	if ( isset($format['infos']) && is_array($format['infos']) && !empty($format['infos']) )
	{
		foreach ( $format['infos'] as $info )
		{
		    $allowed_html = array(
		        'a' => array(
		            'href' => array(),
		            'target' => array(), // Allow target attribute if needed
		        ),
		    );

		    // Allow specific <a> tags through wp_kses
		    $info = wp_kses( $info, $allowed_html );

			echo '<div class="notice notice-info inline"><p>' . $info . '</p></div>';
		}
	}

	if ( isset($format['warnings']) && is_array($format['warnings']) && !empty($format['warnings']) )
	{
		foreach ( $format['warnings'] as $warning )
		{
		    $allowed_html = array(
		        'a' => array(
		            'href' => array(),
		            'target' => array(), // Allow target attribute if needed
		        ),
		    );

		    // Allow specific <a> tags through wp_kses
		    $warning = wp_kses( $warning, $allowed_html );

			echo '<div class="notice notice-error inline"><p>' . $warning . '</p></div>';
		}
	}
?>

<?php
	if ( isset($format['help_url']) && !empty($format['help_url']) )
	{
		echo '<p style="color:#999"><span class="dashicons dashicons-editor-help"></span> <strong>Need help?</strong> Read our documentation for instructions on <a href="' . esc_attr($format['help_url']) . '" target="_blank">setting up an import from ' . esc_html($format['name']) . '</a></p>';
	}
?>

</div>

<?php
	}
?>