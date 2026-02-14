<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<div class="notice notice-error no-format-notice inline"><p>Please select an import format in order to configure the following page.</p></div>

<?php if ( isset($_GET['import_id']) ) { ?><div class="notice notice-info only-updated-warning inline" style="display:none"><p><?php echo esc_html(__( 'You currently have the \'Only Import Updated Properties\' setting checked. For changes below to take effect for existing properties you\'ll need to unselect this option.', 'propertyhive' )); ?></p></div><?php } ?>

<h3><?php echo __( 'Field Rules', 'propertyhive' ); ?></h3>

<p><?php 
	echo __( 'Here you can create custom field rules to cater for non-standard mapping', 'propertyhive' ); 
	if ( class_exists('PH_Template_Assistant') ) { echo __( ' or to import into any custom fields you\'ve set up in the <a href="' . admin_url('admin.php?page=ph-settings&tab=template-assistant&section=custom-fields') . '" target="_blank">Template Assistant add on</a>', 'propertyhive' ); } ?>.
</p>

<?php
	$propertyhive_fields = propertyhive_property_import_get_fields_for_field_mapping();
?>

<div class="rules-table-available-fields">
	<div class="rules-table">
		<div class="notice notice-info inline" id="missing_mandatory_field_mapping" style="display:none"><p><?php echo __( 'No title, excerpt or content fields mapped. At least one of these is mandatory for a property to import.', 'propertyhive' ); ?></p></div>
		
		<br>
		
		<div id="no_field_mappings" style="display:none; border:3px dashed #CCC; text-align:center; font-size:1.1em; padding:40px 30px">
			<p style="font-size:inherit;">No field rules exist. Create your first one below.</p>
			<p style="font-size:inherit;"><a href="https://docs.wp-property-hive.com/article/640-field-rules" target="_blank">Need help?</a></p>
		</div>
			
		<div id="field_mapping_rules">
			<?php
				if ( isset($import_settings['field_mapping_rules']) && !empty($import_settings['field_mapping_rules']) )
				{
					foreach ( $import_settings['field_mapping_rules'] as $i => $and_rules )
					{
			?>
			<div class="rule-accordion">
				<div class="rule-accordion-header">

					<span class="dashicons dashicons-arrow-down-alt2"></span>
					&nbsp; 
					<span class="rule-description">
						Rule description here
					</span>

					<div class="icons">
						<span class="reorder-rule dashicons dashicons-move" title="<?php echo esc_html(__( 'Reorder Rule', 'propertyhive' )); ?>"></span>
						<span class="duplicate-rule dashicons dashicons-admin-page" title="<?php echo esc_html(__( 'Duplicate Rule', 'propertyhive' )); ?>"></span>
						<span class="delete-rule dashicons dashicons-trash" title="<?php echo esc_html(__( 'Delete Rule', 'propertyhive' )); ?>"></span>
					</div>

				</div>
				<div class="rule-accordion-contents">
					<div class="field-mapping-rule no-border no-margin">
						<div class="and-rules">
							<?php $rule_i = 0; foreach ( $and_rules['rules'] as $or_rule ) { ?>
							<div class="or-rule">
								<div style="padding:20px 0; font-weight:600" class="and-label">AND</div>
								<div>
									If 
									<input type="text" name="field_mapping_rules[<?php echo $i; ?>][field][]" value="<?php echo esc_attr($or_rule['field']); ?>">
									field in <span class="phpi-import-format-name"></span> feed
								</div>
								<div>
									<select name="field_mapping_rules[<?php echo $i; ?>][operator][]">
										<option value="="<?php if ( !isset($or_rule['operator']) || ( isset($or_rule['operator']) && $or_rule['operator'] == '=' ) ) { echo ' selected'; } ?>>Is equal to</option>
										<option value="!="<?php if ( isset($or_rule['operator']) && $or_rule['operator'] == '!=' ) { echo ' selected'; } ?>>Is not equal to</option>
										<option value="like"<?php if ( isset($or_rule['operator']) && $or_rule['operator'] == 'like' ) { echo ' selected'; } ?>>Contains</option>
										<option value="begins"<?php if ( isset($or_rule['operator']) && $or_rule['operator'] == 'begins' ) { echo ' selected'; } ?>>Begins with</option>
										<option value="ends"<?php if ( isset($or_rule['operator']) && $or_rule['operator'] == 'ends' ) { echo ' selected'; } ?>>Ends with</option>
										<option value="exists"<?php if ( isset($or_rule['operator']) && $or_rule['operator'] == 'exists' ) { echo ' selected'; } ?>>Exists</option>
										<option value="not_exists"<?php if ( isset($or_rule['operator']) && $or_rule['operator'] == 'not_exists' ) { echo ' selected'; } ?>>Does not exist</option>
									</select>
									<input type="text" name="field_mapping_rules[<?php echo $i; ?>][equal][]" value="<?php echo esc_attr($or_rule['equal']); ?>" placeholder="Value in feed, or use * wildcard">
								</div>
								<div class="rule-actions">
									<a href="" class="add-and-rule-action"><span class="dashicons dashicons-plus-alt2"></span> Add AND Rule</a><a href="" class="delete-action"><span class="dashicons dashicons-trash"></span> Delete Rule</a>
								</div>
							</div>
							<?php ++$rule_i; } // end foreach AND rules ?>
						</div>
						<div class="then">
							<div style="padding:20px 0; font-weight:600">THEN</div>
							<div>
								<?php echo esc_html( __( 'Set Property Hive field', 'propertyhive' ) ); ?>
								<select name="field_mapping_rules[<?php echo $i; ?>][propertyhive_field]" style="width:250px;">
									<option value=""></option>
									<?php
										$propertyhive_field_delimited = false;
										$propertyhive_field_options = array();
										if ( !empty($propertyhive_fields) )
										{
											foreach ( $propertyhive_fields as $key => $value )
											{
												echo '<option value="' . esc_attr($key) . '"';
												if ( $key == $and_rules['propertyhive_field'] ) 
												{ 
													echo ' selected'; 
													if ( isset($value['options']) && is_array($value['options']) && !empty($value['options']) )
													{
														$property_field_options = $value['options'];
													}
													if ( isset($value['delimited']) && $value['delimited'] === true )
													{
														$property_field_delimited = true;
													}
												}
												echo '>' . esc_html($value['label']) . '</option>';
											}
										}
									?>
								</select> 
								<div class="notice notice-info inline already-mapped-warning" style="margin-top:15px; display:none"><p>The <span class="already-mapped-field"></span> field is already mapped by default in the <span class="phpi-import-format-name"></span> feed. Creating a mapping here will overwrite this.</p></div>
							</div>
							<div>
								To
								<span class="result-text"<?php if ( !empty($propertyhive_field_options) ) { echo ' style="display:none"'; } ?>>
									<input type="text" name="field_mapping_rules[<?php echo $i; ?>][result]" style="width:100%; max-width:340px;" value="<?php echo esc_attr($and_rules['result']); ?>" placeholder="Enter value or {field_name_here} to use value sent">
								</span>
								<span class="result-dropdown"<?php if ( empty($propertyhive_field_options) ) { echo ' style="display:none"'; } ?>>
									<select name="field_mapping_rules[<?php echo $i; ?>][result_option]"><?php
										$result_type = 'text';
										if ( !empty($propertyhive_field_options) )
										{
											$result_type = 'dropdown';
											echo '<option value=""></option>';
											foreach ( $propertyhive_field_options as $key => $value )
											{
												echo '<option value="' . $key . '"';
												if ( $and_rules['result'] == $key )
												{
													echo ' selected';
												}
												echo '>' . $value . '</option>';
											}
										}
									?></select>
								</span>
								<input type="hidden" name="field_mapping_rules[<?php echo $i; ?>][result_type]" value="<?php echo esc_attr($result_type); ?>">
							</div>
							<div style="display:<?php if ( $propertyhive_field_delimited ) { echo 'block'; }else{ echo 'none'; } ?>" class="delimited">
								<label><input type="checkbox" name="field_mapping_rules[<?php echo $i; ?>][delimited]" value="1"<?php if ( isset($and_rules['delimited']) && $and_rules['delimited'] === true ) { echo ' checked'; } ?>> Delimited?</label>
								<span class="delimited-character" style="display:<?php if ( isset($and_rules['delimited']) && $and_rules['delimited'] === true ) { echo 'inline'; }else{ echo 'none'; } ?>;">By character <input type="text" name="field_mapping_rules[<?php echo $i; ?>][delimited_character]" style="max-width:50px;" value="<?php echo ( isset($and_rules['delimited_character']) ? esc_attr($and_rules['delimited_character']) : ',' ); ?>"></span>
								<div style="font-style:italic; margin-top:6px; color:#AAA"><span class="dashicons dashicons-info"></span> Tick 'Delimited' if all features are provided in one single field separated by a specific character. If features are provided as individual fields in the third party data use the 'Property Feature [1-9]' field(s)</div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php
					}
				}
			?>
		</div>

		<br>

		<hr>
		<h3>Create New Field Rule</h3>

		<div id="field_mapping_rule_template">
			
			<div class="field-mapping-rule no-border no-margin">

				<div class="and-rules">
					<div class="or-rule">
						<div>
							If 
							<input type="text" name="field_mapping_rules[{rule_count}][field][]" value="">
							field in <span class="phpi-import-format-name"></span> feed
						</div>
						<div>
							<select name="field_mapping_rules[{rule_count}][operator][]">
								<option value="=">Is equal to</option>
								<option value="!=">Is not equal to</option>
								<option value="like">Contains</option>
								<option value="begins">Begins with</option>
								<option value="ends">Ends with</option>
								<option value="exists">Exists</option>
								<option value="not_exists">Does not exist</option>
							</select>
							<input type="text" name="field_mapping_rules[{rule_count}][equal][]" placeholder="Value in feed, or use * wildcard">
						</div>
						<div class="rule-actions">
							<a href="" class="add-and-rule-action"><span class="dashicons dashicons-plus-alt2"></span> Add AND Rule</a><a href="" class="delete-action"><span class="dashicons dashicons-trash"></span> Delete Rule</a>
						</div>
					</div>
				</div>
				<div class="then">
					<div style="padding:20px 0; font-weight:600">THEN</div>
					<div>
						<?php echo esc_html( __( 'Set Property Hive field', 'propertyhive' ) ); ?>
						<select name="field_mapping_rules[{rule_count}][propertyhive_field]" style="width:250px;">
							<option value=""></option>
							<?php
								if ( !empty($propertyhive_fields) )
								{
									foreach ( $propertyhive_fields as $key => $value )
									{
										echo '<option value="' . esc_attr($key) . '">' . esc_html($value['label']) . '</option>';
									}
								}
							?>
						</select>
						<div class="notice notice-info inline already-mapped-warning" style="margin-top:15px; display:none"><p>The <span class="already-mapped-field"></span> field is already mapped by default in the <span class="phpi-import-format-name"></span> feed. Creating a mapping here will overwrite this.</p></div>
					</div>
					<div>
						To
						<span class="result-text"><input type="text" name="field_mapping_rules[{rule_count}][result]" style="width:100%; max-width:340px;" value="" placeholder="Enter value or {field_name_here} to use value sent"></span>
						<span class="result-dropdown" style="display:none"><select name="field_mapping_rules[{rule_count}][result_option]"></select></span>
						<input type="hidden" name="field_mapping_rules[{rule_count}][result_type]" value="text">
					</div>
					<div style="display:none" class="delimited">
						<label><input type="checkbox" name="field_mapping_rules[{rule_count}][delimited]" value="1"> Delimited?</label>
						<span class="delimited-character" style="display:none;">By character <input type="text" name="field_mapping_rules[{rule_count}][delimited_character]" style="max-width:50px;" value=","></span>
						<div style="font-style:italic; margin-top:6px; color:#AAA"><span class="dashicons dashicons-info"></span> Tick 'Delimited' if all features are provided in one single field separated by a specific character. If features are provided as individual fields in the third party data use the 'Property Feature [1-9]' field(s)</div>
					</div>
				</div>
				
			</div>

		</div>

		<br>
		<a href="" class="button button-primary field-mapping-add-or-rule-button">Add Rule</a>
		<a href="https://docs.wp-property-hive.com/category/294-property-import" class="button" style="background:none; border:1px solid transparent;" target="_blank">Need help?</a>

	</div>

	<div class="xml-rules-available-fields" style="display:none">
		<h3 style="margin-top:0">Fields found in the XML</h3>
		<p>Below is a list of fields found in the XML using the <a href="https://www.w3schools.com/xml/xpath_syntax.asp" target="_blank">XPath syntax</a>.</p>
		<p>You can <strong>click and drag</strong> the fields below into the rule.</p>
		<hr>
		<?php echo '<p id="no_nodes_found"' . ( ( !isset($import_settings['property_node_options']) || ( isset($import_settings['property_node_options']) && empty($import_settings['property_node_options']) ) ) ? '' : ' style="display:none"' ) . '><em>' . __( 'No XML fields found. Please go to the \'Import Format\' tab and click \'Fetch XML\' to obtain a list of these.', 'propertyhive' ) . '</em></p>'; ?>
		<div id="xml-nodes-found">
			<?php
			if ( isset($import_settings['property_node_options']) && !empty($import_settings['property_node_options']) )
			{
				$options = json_decode($import_settings['property_node_options']);

				if ( !empty($options) )
				{
					foreach ( $options as $option )
					{
						$node_name = $option;
						if ( isset($import_settings['property_node']) && !empty($import_settings['property_node']) )
						{
							if ( strpos($node_name, $import_settings['property_node']) === false )
							{
								continue;
							}

							$node_name = str_replace($import_settings['property_node'], '', $node_name);
						}

						if ( !empty($node_name) )
						{
							echo '<a href="#">' . $node_name . '</a>';
						}
					}	
				}
			}
		?></div>
	</div>

	<div class="csv-rules-available-fields" style="display:none">
		<h3 style="margin-top:0">Fields found in the CSV</h3>
		<p>Below is a list of the fields we found in the CSV provided.</p>
		<p>You can <strong>click and drag</strong> the fields below into the rule.</p>
		<hr>
		<?php echo '<p id="no_fields_found"' . ( ( !isset($import_settings['property_field_options']) || ( isset($import_settings['property_field_options']) && empty($import_settings['property_node_options']) ) ) ? '' : ' style="display:none"' ) . '><em>' . __( 'No CSV fields found. Please go to the \'Import Format\' tab and click \'Fetch CSV\' to obtain a list of these.', 'propertyhive' ) . '</em></p>'; ?>
		<div id="csv-fields-found">
			<?php
			if ( isset($import_settings['property_field_options']) && !empty($import_settings['property_field_options']) )
			{
				$options = json_decode($import_settings['property_field_options']);

				if ( !empty($options) )
				{
					foreach ( $options as $option )
					{
						$field_name = $option;

						if ( !empty($field_name) )
						{
							echo '<a href="#">' . $field_name . '</a>';
						}
					}	
				}
			}
		?></div>
	</div>
</div>

<script>
	var phpi_rule_count = <?php echo ( isset($import_settings['field_mapping_rules']) && !empty($import_settings['field_mapping_rules']) ) ? count($import_settings['field_mapping_rules']) : 0; ?>;
</script>