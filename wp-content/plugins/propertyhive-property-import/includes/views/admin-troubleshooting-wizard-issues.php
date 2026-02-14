<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="ph-import-wizard-one">

	<form method="post">

		<p style="margin-bottom:18px; font-size:1.1em"><strong>Please select the primary issue you're facing from the list below:</strong></p>

		<label>
			<input type="radio" name="issue" value="import-not-automatic" checked> Imports are not running automatically
		</label>

		<label>
			<input type="radio" name="issue" value="import-not-completing"> Imports are running but not completing
		</label>

		<label>
			<input type="radio" name="issue" value="import-wrong-frequency"> Imports are running and completing, but not at the frequency I've specified
		</label>

		<label>
			<input type="radio" name="issue" value="property-not-importing"> One or more properties are not importing at all
		</label>

		<label>
			<input type="radio" name="issue" value="property-wrong-data"> Properties are importing but with incorrect or missing data
		</label>

		<label>
			<input type="radio" name="issue" value="property-not-removed"> Properties are not getting removed
		</label>

		<label>
			<input type="radio" name="issue" value="other"> Other
		</label>

		<?php
			$options = get_option( 'propertyhive_property_import' );
		    if ( is_array($options) && !empty($options) )
		    {
		    	foreach ( $options as $import_id => $option )
				{
					if ( isset($option['deleted']) && $option['deleted'] == 1 )
		            {
		                unset($options[$import_id]);
		            }
				}
			}

			if ( is_array($options) && !empty($options) )
		    {
		    	if ( count($options) > 1 )
		    	{
		?>
		<p style="margin-top:28px; margin-bottom:18px; font-size:1.1em"><strong>Please select which import this relates to:</strong></p>

		<?php
			$dropdown_options = array();
			foreach ( $options as $import_id => $option )
			{
				$name = $option['format'];

				$format = propertyhive_property_import_get_import_format($option['format']);
				if ( $format !== false )
				{
					$name = $format['name'];
				}
				
				if ( isset($option['custom_name']) && $option['custom_name'] != '' )
	            {
	                $name .= ' (' . $option['custom_name'] . ')';
	            }
	            else
	            {
	            	$name .= ' (' . $import_id . ')';
	            }

				$dropdown_options[] = array(
					'import_id' => $import_id,
					'name' => $name
				);
			}

			usort($dropdown_options, function($a, $b) 
			{
			    return strcasecmp($a['name'], $b['name']);
			});
		?>
		<select name="import_id" style="width:100%;">
			<?php
				foreach ($dropdown_options as $dropdown_option )
				{
					$import_id = $dropdown_option['import_id'];
					$name = $dropdown_option['name'];

					echo '<option value="' . (int)$import_id . '">' . esc_html($name) . '</option>';
				}
			?>
		</select>
		<?php
				}
				else
				{
					foreach ( $options as $import_id => $option )
					{
						echo '<input type="hidden" name="import_id" value="' . (int)$import_id . '">';
					}
				}
			}
		?>

		<div id="property-in-question-existing" style="display:none">

			<p style="margin-top:28px; margin-bottom:11px; font-size:1.1em"><strong>Please select the property in question below. If you're issue relates to multiple properties please enter one as an example.</strong></p>

			<select name="post_id" style="width:100%; max-width:25rem">
				<option value="">Search properties</option>
			</select>

		</div>

		<div id="property-in-question-missing" style="display:none">

			<p style="margin-top:28px; margin-bottom:18px; font-size:1.1em"><strong>Please enter the unique ID of the property from the CRM. If your issue relates to multiple properties please enter one as an example.</strong></p>

			<input type="text" name="crm_id" value="" style="width:100%; max-width:25rem">
		</div>

		<div class="buttons">
			<input type="submit" value="Next" class="button button-primary">
		</div>

	</form>

</div>