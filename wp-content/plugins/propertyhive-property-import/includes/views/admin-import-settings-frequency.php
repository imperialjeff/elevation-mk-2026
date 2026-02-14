<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<h3><?php echo __( 'Import Frequency', 'propertyhive' ); ?></h3>

<p><?php echo __( 'Choose how often imports should run by selecting the frequency below', 'propertyhive' ); ?>:</p>

<table class="form-table">
	<tbody>
		<tr>
			<th><label for="frequency"><?php echo __( 'Frequency', 'propertyhive' ); ?></label></th>
			<td style="padding-top:20px;">
				<?php
					foreach ( $frequencies as $key => $frequency )
					{
						$checked = false;
						if ( isset($import_settings['import_frequency']) && $import_settings['import_frequency'] == $key )
						{
							$checked = true;
						}
						elseif ( isset($import_settings['import_frequency']) && $import_settings['import_frequency'] == 'every_15_minutes' && $key == 'every_fifteen_minutes' )
						{
							$checked = true;
						}
						elseif ( isset($import_settings['import_frequency']) && $import_settings['import_frequency'] == 'exact' && $key == 'exact_hours' )
						{
							$checked = true;
						}
						elseif( !isset($import_settings['import_frequency']) && $key == 'daily' )
						{
							$checked = true;
						}

						echo '<div style="padding:3px 0"><label><input type="radio" name="frequency" value="' . esc_attr($key) . '"' . ( $checked === true ? 'checked' : '' ) . '> ' . esc_html($frequency['name']) . '</label> ';
						if ( $key == 'exact_hours' )
						{
							$exact_hours = false;
                            
			                if ( isset($import_settings['exact_hours']) && !empty($import_settings['exact_hours']) )
			                {
			                    $exact_hours = $import_settings['exact_hours'];
			                }
			                elseif ( isset($import_settings['exact_times']) && !empty($import_settings['exact_times']) )
			                {
			                    $exact_hours = $import_settings['exact_times'];
			                }

			                if ( !empty($exact_hours) )
			                {
			                    if ( !is_array($exact_hours) )
			                    {
			                        $exact_hours = explode(",", $exact_hours);
			                        $exact_hours = array_map('trim', $exact_hours); // remove white spaces from around hours
			                        $exact_hours = array_filter($exact_hours); // remove empty array elements
			                    }
			                    sort($exact_hours, SORT_NUMERIC); 
			                }
							echo ': <input type="text" name="exact_hours" value="' . ( ( is_array($exact_hours) && !empty($exact_hours) ) ? implode(", ", $exact_hours) : '' ) . '" placeholder="Hours only (e.g. 8, 12, 16)">';
						}
						echo '</div>';
					}
				?>
			</td>
		</tr>
	</tbody>
</table>