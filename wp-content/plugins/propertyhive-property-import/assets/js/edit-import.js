var phpi_original_property_node = ''; // used for XML format
var phpi_original_property_id_node = ''; // used for XML format
var phpi_original_property_id_field = ''; // used for CSV format
var phpi_original_image_field = ''; // used for CSV format, comma-delimited images
var phpi_original_floorplan_field = ''; // used for CSV format, comma-delimited floorplans
var phpi_original_brochure_field = ''; // used for CSV format, comma-delimited brochures
var phpi_original_epc_field = ''; // used for CSV format, comma-delimited EPCs
var phpi_original_virtual_tour_field = ''; // used for CSV format, comma-delimited virtual tours

function phpi_init_field_mapping_sortable()
{
	jQuery( "#field_mapping_rules" ).sortable({
		handle: ".reorder-rule",
    });
}

function phpi_draw_mappings()
{
	var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();
	var previous_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel input[name=\'previous_format\']').val();

	// get taxonomies via AJAX
	jQuery.ajax({
    	url : ajaxurl,
    	type: 'POST',
    	dataType: 'html',
    	data : {
    		action: "propertyhive_property_import_get_format_default_mapping_values", 
    		format: selected_format, 
    		previous_format: previous_format, 
    		screen_action: phpi_admin_object.action, 
    		import_id: phpi_admin_object.import_id, 
    		ph_taxonomy_terms: phpi_admin_object.ph_taxonomy_terms, 
    		ajax_nonce: phpi_admin_object.ajax_nonce
    	},
    	success: function(response) 
    	{
    		jQuery('.ph-property-import-admin-settings-import-settings #phpi_taxonomy_values').html(response);

    		jQuery('.phpi-import-format-name').html(phpi_admin_object.formats[selected_format].name);
    	},
    	error: function(jqXHR, textStatus, errorThrown)
    	{
	        console.error('AJAX Error:', textStatus, errorThrown, jqXHR.status, jqXHR.responseText);
	        alert('Failed to obtain default field mapping values. Please refresh and try again, or check the console for more details. Error: ' + (errorThrown || 'Unknown error'));
	    }
    });
}

function phpi_expertagent_settings()
{
	var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();

	if ( selected_format == 'xml_expertagent' )
	{
		var data_source = jQuery('select[name=\'xml_expertagent_data_source\']').val();

		if ( data_source == 'local' )
		{
			jQuery('#row_xml_expertagent_ftp_host').hide();
			jQuery('#row_xml_expertagent_ftp_user').hide();
			jQuery('#row_xml_expertagent_ftp_pass').hide();
			jQuery('#row_xml_expertagent_ftp_passive').hide();
			jQuery('#row_xml_expertagent_xml_filename').hide();
			jQuery('#row_xml_expertagent_local_directory').show();
		}
		else
		{
			jQuery('#row_xml_expertagent_ftp_host').show();
			jQuery('#row_xml_expertagent_ftp_user').show();
			jQuery('#row_xml_expertagent_ftp_pass').show();
			jQuery('#row_xml_expertagent_ftp_passive').show();
			jQuery('#row_xml_expertagent_xml_filename').show();
			jQuery('#row_xml_expertagent_local_directory').hide();
		}
	}
}

jQuery( function ( $ ) {

	if ( jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').length > 0 )
	{
		jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').select2({ allowClear: true, placeholder:"Select..." });
	}
	if ( jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').length > 0 )
	{
		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2({ allowClear: true, placeholder:"Select..." });
	}

	jQuery('.settings-panels input[name=\'image_field_arrangement\']').change(function()
	{
		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li.active a').trigger('click');
	});
	jQuery('.settings-panels input[name=\'floorplan_field_arrangement\']').change(function()
	{
		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li.active a').trigger('click');
	});
	jQuery('.settings-panels input[name=\'brochure_field_arrangement\']').change(function()
	{
		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li.active a').trigger('click');
	});
	jQuery('.settings-panels input[name=\'epc_field_arrangement\']').change(function()
	{
		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li.active a').trigger('click');
	});
	jQuery('.settings-panels input[name=\'virtual_tour_field_arrangement\']').change(function()
	{
		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li.active a').trigger('click');
	});

	if ( jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').length > 0 )
	{
		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2({ allowClear: true, placeholder:"Select..." });
	}

	jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li a').click(function(e)
	{
		e.preventDefault();

		var this_href = jQuery(this).attr('href');

		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li').removeClass('active');
		jQuery(this).parent().addClass('active');

		jQuery('.ph-property-import-admin-settings-import-settings .settings-panel').hide();
		jQuery(this_href).show();

		var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();

		if ( selected_format == 'csv' )
		{
			phpi_create_csv_field_mapping_options();

			jQuery('.settings-panels .xml-tip').hide();
			jQuery('.settings-panels .csv-tip').show();

			if ( jQuery('.settings-panels input[name=\'image_field_arrangement\']:checked').val() == '' )
			{
				jQuery('.settings-panels .media-image-settings .media-comma-delimited-row').hide();
				jQuery('.settings-panels .media-image-settings .media-individual-row').show();
			}
			if ( jQuery('.settings-panels input[name=\'image_field_arrangement\']:checked').val() == 'comma_delimited' )
			{
				jQuery('.settings-panels .media-image-settings .media-comma-delimited-row').show();
				jQuery('.settings-panels .media-image-settings .media-individual-row').hide();
			}

			if ( jQuery('.settings-panels input[name=\'floorplan_field_arrangement\']:checked').val() == '' )
			{
				jQuery('.settings-panels .media-floorplan-settings .media-comma-delimited-row').hide();
				jQuery('.settings-panels .media-floorplan-settings .media-individual-row').show();
			}
			if ( jQuery('.settings-panels input[name=\'floorplan_field_arrangement\']:checked').val() == 'comma_delimited' )
			{
				jQuery('.settings-panels .media-floorplan-settings .media-comma-delimited-row').show();
				jQuery('.settings-panels .media-floorplan-settings .media-individual-row').hide();
			}

			if ( jQuery('.settings-panels input[name=\'brochure_field_arrangement\']:checked').val() == '' )
			{
				jQuery('.settings-panels .media-brochure-settings .media-comma-delimited-row').hide();
				jQuery('.settings-panels .media-brochure-settings .media-individual-row').show();
			}
			if ( jQuery('.settings-panels input[name=\'brochure_field_arrangement\']:checked').val() == 'comma_delimited' )
			{
				jQuery('.settings-panels .media-brochure-settings .media-comma-delimited-row').show();
				jQuery('.settings-panels .media-brochure-settings .media-individual-row').hide();
			}

			if ( jQuery('.settings-panels input[name=\'epc_field_arrangement\']:checked').val() == '' )
			{
				jQuery('.settings-panels .media-epc-settings .media-comma-delimited-row').hide();
				jQuery('.settings-panels .media-epc-settings .media-individual-row').show();
			}
			if ( jQuery('.settings-panels input[name=\'epc_field_arrangement\']:checked').val() == 'comma_delimited' )
			{
				jQuery('.settings-panels .media-epc-settings .media-comma-delimited-row').show();
				jQuery('.settings-panels .media-epc-settings .media-individual-row').hide();
			}

			if ( jQuery('.settings-panels input[name=\'virtual_tour_field_arrangement\']:checked').val() == '' )
			{
				jQuery('.settings-panels .media-virtual_tour-settings .media-comma-delimited-row').hide();
				jQuery('.settings-panels .media-virtual_tour-settings .media-individual-row').show();
			}
			if ( jQuery('.settings-panels input[name=\'virtual_tour_field_arrangement\']:checked').val() == 'comma_delimited' )
			{
				jQuery('.settings-panels .media-virtual_tour-settings .media-comma-delimited-row').show();
				jQuery('.settings-panels .media-virtual_tour-settings .media-individual-row').hide();
			}

			jQuery('#image_fields').attr('placeholder', jQuery('#image_fields').data('csv-placeholder'));
			jQuery('#floorplan_fields').attr('placeholder', jQuery('#floorplan_fields').data('csv-placeholder'));
			jQuery('#brochure_fields').attr('placeholder', jQuery('#brochure_fields').data('csv-placeholder'));
			jQuery('#epc_fields').attr('placeholder', jQuery('#epc_fields').data('csv-placeholder'));
			jQuery('#virtual_tour_fields').attr('placeholder', jQuery('#virtual_tour_fields').data('csv-placeholder'));
		}

		if ( selected_format == 'xml' )
		{
			jQuery('.settings-panels .xml-tip').show();
			jQuery('.settings-panels .csv-tip').hide();

			jQuery('.media-comma-delimited-row').hide();
			jQuery('.media-individual-row').show();

			jQuery('#image_fields').attr('placeholder', jQuery('#image_fields').data('xml-placeholder'));
			jQuery('#floorplan_fields').attr('placeholder', jQuery('#floorplan_fields').data('xml-placeholder'));
			jQuery('#brochure_fields').attr('placeholder', jQuery('#brochure_fields').data('xml-placeholder'));
			jQuery('#epc_fields').attr('placeholder', jQuery('#epc_fields').data('xml-placeholder'));
			jQuery('#virtual_tour_fields').attr('placeholder', jQuery('#virtual_tour_fields').data('xml-placeholder'));
		}

		// Show 'only import updated properties' warning
		jQuery('.only-updated-warning').hide();

		for ( var i in phpi_admin_object.formats )
		{
			if ( i == selected_format )
			{
				if ( phpi_admin_object.formats[i].hasOwnProperty('fields') )
				{
					for ( var j in phpi_admin_object.formats[i].fields )
					{
						if ( phpi_admin_object.formats[i].fields[j].hasOwnProperty('id') )
						{
							if ( phpi_admin_object.formats[i].fields[j].id == 'only_updated' )
							{
								if ( jQuery('input[name=\'' + selected_format + '_only_updated\']').is(':checked') )
								{
									jQuery('.only-updated-warning').show();
								}
							}
						}
					}
					
				}
			}
		}

		phpi_init_field_mapping_sortable();
		phpi_set_fields_size_properties();
	});

	if ( window.location.hash != '' )
	{
		if ( jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li a[href=\'' + window.location.hash + '\']').length > 0 )
		{
			jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li a[href=\'' + window.location.hash + '\']').trigger('click');
		}
	}

	jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').change(function()
	{
		ph_property_import_show_format_settings();
	});

	jQuery('select[name=\'xml_property_node\']').change(function()
	{
		phpi_create_xml_property_id_node_options();
		phpi_create_xml_field_mapping_options();
	});

	jQuery('body').on('click', '.settings-panels .rule-accordion-header > span:first-child', function()
	{
		if ( jQuery(this).parent().parent().find('.rule-accordion-contents').css('display') == 'none' )
		{
			jQuery(this).removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
		}
		else
		{
			jQuery(this).removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
		}
		jQuery(this).parent().parent().find('.rule-accordion-contents').slideToggle();
	});

	jQuery('.field-mapping-add-or-rule-button').click(function(e)
	{
		e.preventDefault();

		add_field_mapping_or_rule();
	});

	jQuery('body').on('click', '.rule-accordion-header .delete-rule', function(e)
	{
		e.preventDefault();

		var confirm_box = confirm( "Are you sure you want to delete this rule?" );

		if (!confirm_box)
		{
			return;
		}

		jQuery(this).parent().parent().parent().remove();

		last_safe_scroll = 0;
		phpi_build_field_mapping_rule_accordions();
		phpi_init_field_mapping_sortable();
		phpi_set_fields_size_properties();
	});

	jQuery('body').on('click', '.rule-accordion-header .duplicate-rule', function(e)
	{
		e.preventDefault();

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable( "destroy" );
		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable( "destroy" );

		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2("destroy");

		jQuery(this).parent().parent().parent().find("input").each(function()
		{
		    jQuery(this).attr("value", jQuery(this).val());
		});
		jQuery(this).parent().parent().parent().find('select option').each(function()
		{ 
			this.defaultSelected = this.selected; 
		});

		/// get rule count of rule being cloned
		var field_to_look_at_for_id = jQuery(this).parent().parent().parent().find('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']');
		var field_name = jQuery(field_to_look_at_for_id).attr('name');
		//alert(field_name);
		var arrStr = field_name.split(/\[(.*?)\]/);
		var existing_rule_count = arrStr[1];

		var rule_html = jQuery(this).parent().parent().parent().html();

		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");
		rule_html = rule_html.replace("field_mapping_rules[" + existing_rule_count + "]", "field_mapping_rules[" + phpi_rule_count + "]");

		phpi_rule_count = phpi_rule_count + 1;

		jQuery('<div class="rule-accordion">' + rule_html + '</div>').insertAfter(jQuery(this).parent().parent().parent());

		// close any existing rules and open the new one
		jQuery('.rule-accordion-header > span:first-child').removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
		jQuery(this).parent().parent().parent().next('.rule-accordion').find('.rule-accordion-header > span:first-child').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
		
		jQuery('.rule-accordion .rule-accordion-contents').slideUp();
		jQuery(this).parent().parent().parent().next('.rule-accordion').find('.rule-accordion-contents').slideDown();

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable({
		    drop: function (event, ui) {
		        this.value += jQuery(ui.draggable).text();
		    }
		});

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable({
		    drop: function (event, ui) {
		        this.value += '{' + jQuery(ui.draggable).text() + '}';
		    }
		});

		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2({ allowClear: true, placeholder:"Select..." });

		last_safe_scroll = 0;
		phpi_build_field_mapping_rule_accordions();
		phpi_init_field_mapping_sortable();
		phpi_set_fields_size_properties();
	});

	jQuery('body').on('click', '.field-mapping-rule .rule-actions a.delete-action', function(e)
	{
		e.preventDefault();
		jQuery(this).parent().parent().remove();

		// clean up any empty AND groups
		jQuery('#field_mapping_rules .field-mapping-rule').each(function()
		{
			if ( jQuery(this).find('.and-rules').html().trim() == '' )
			{
				jQuery(this).remove();
			}
			jQuery(this).find('.and-rules .or-rule:nth-child(1) .and-label').remove();
		});

		jQuery('#field_mapping_rule_template .field-mapping-rule').each(function()
		{
			jQuery(this).find('.and-rules .or-rule:nth-child(1) .and-label').remove();
		});

		jQuery('.and-rules .or-rule .delete-action').show();
		jQuery('.and-rules').each(function()
		{
			if ( jQuery(this).find('.or-rule').length == 1 )
			{
				jQuery(this).find('.delete-action').hide();
			}
		});

		last_safe_scroll = 0;
		phpi_build_field_mapping_rule_accordions();
		phpi_init_field_mapping_sortable();
		phpi_set_fields_size_properties();
	});

	jQuery('body').on('change', '.field-mapping-rule input', function()
	{
		phpi_build_field_mapping_rule_accordions();
	});

	jQuery('body').on('change', '.field-mapping-rule select', function()
	{
		phpi_build_field_mapping_rule_accordions();
	});

	jQuery('body').on('change', 'select[name*=\'[propertyhive_field]\']', function()
	{
		var selected_propertyhive_field = jQuery(this).val();
		var propertyhive_field_options = new Array();
		var propertyhive_field_delimited = false;

		if ( phpi_admin_object.propertyhive_fields_for_field_mapping )
		{
			for ( var i in phpi_admin_object.propertyhive_fields_for_field_mapping )
			{
				if ( i == selected_propertyhive_field )
				{
					if ( phpi_admin_object.propertyhive_fields_for_field_mapping[i].hasOwnProperty('options') )
					{
						propertyhive_field_options = phpi_admin_object.propertyhive_fields_for_field_mapping[i].options;
					}

					if ( phpi_admin_object.propertyhive_fields_for_field_mapping[i].hasOwnProperty('delimited') && phpi_admin_object.propertyhive_fields_for_field_mapping[i].delimited == true )
					{
						propertyhive_field_delimited = true;
					}
				}
			}
		}

		if (propertyhive_field_delimited)
		{
			jQuery(this).parent().parent().find('.delimited').show();
		}
		else
		{
			jQuery(this).parent().parent().find('.delimited').hide();
		}

		jQuery(this).parent().parent().find('.result-dropdown select').empty();

		if ( Object.keys(propertyhive_field_options).length > 0 )
		{
			jQuery(this).parent().parent().find('.result-dropdown select').append('<option value=""></option>');
			for ( var i in propertyhive_field_options )
			{
				jQuery(this).parent().parent().find('.result-dropdown select').append('<option value="' + i + '">' + propertyhive_field_options[i] + '</option>');
			}
			jQuery(this).parent().parent().find('.result-text').hide();
			jQuery(this).parent().parent().find('.result-dropdown').show();
			jQuery(this).parent().parent().find('input[name*=\'result_type\']').val('dropdown');
		}
		else
		{
			jQuery(this).parent().parent().find('.result-text').show();
			jQuery(this).parent().parent().find('.result-dropdown').hide();
			jQuery(this).parent().parent().find('input[name*=\'result_type\']').val('text');
		}

		phpi_build_field_mapping_rule_accordions();
		phpi_show_already_mapped_warning();
	});

	jQuery('body').on('change', 'select[name*=\'[operator]\']', function()
	{
		if ( jQuery(this).val() == 'exists' || jQuery(this).val() == 'not_exists' )
		{
			jQuery(this).next('input').hide();
		}
		else
		{
			jQuery(this).next('input').show();
		}

		phpi_build_field_mapping_rule_accordions();
	});

	jQuery('body').on('change', 'input[name*=\'[delimited]\']', function()
	{
		if ( jQuery(this).is(':checked') )
		{
			jQuery(this).parent().parent().find('.delimited-character').show();
		}
		else
		{
			jQuery(this).parent().parent().find('.delimited-character').hide();
		}
	});

	jQuery(this).find('.and-rules .or-rule:nth-child(1) .and-label').remove();

	jQuery('body').on('click', '.rule-actions a.add-and-rule-action', function(e)
	{
		e.preventDefault();

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable( "destroy" );
		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable( "destroy" );

		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2("destroy");

		// clone previous rule
		var previous_rule_html = jQuery(this).parent().parent().html();
		var and_html = '<div style="padding:20px 0; font-weight:600" class="and-label">AND</div>';
		jQuery(this).parent().parent().parent().append( '<div class="or-rule">' + ( previous_rule_html.indexOf('>AND<') == -1 ? and_html : '' ) + previous_rule_html + '</div>' );
		jQuery(this).parent().parent().parent().find('.or-rule:last-child').find('input').each(function()
		{
			jQuery(this).val('');
		});

		jQuery('.and-rules .or-rule .delete-action').show();
		jQuery('.and-rules').each(function()
		{
			if ( jQuery(this).find('.or-rule').length == 1 )
			{
				jQuery(this).find('.delete-action').hide();
			}
		});

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable({
		    drop: function (event, ui) {
		        this.value += jQuery(ui.draggable).text();
		    }
		});

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable({
		    drop: function (event, ui) {
		        this.value += '{' + jQuery(ui.draggable).text() + '}';
		    }
		});

		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2({ allowClear: true, placeholder:"Select..." });

		phpi_build_field_mapping_rule_accordions();
		phpi_set_fields_size_properties();
	});

	jQuery('.and-rules .or-rule .delete-action').show();
	jQuery('.and-rules').each(function()
	{
		if ( jQuery(this).find('.or-rule').length == 1 )
		{
			jQuery(this).find('.delete-action').hide();
		}
	});

	jQuery('body').on('click', '.ph-property-import-admin-settings-import-settings a.add-additional-mapping', function(e)
	{
		e.preventDefault();
		
		var taxonomy = jQuery(this).attr('href').replace("#", "");

		var taxonomy_options = new Array();

		if ( taxonomy in phpi_admin_object.ph_taxonomy_terms ) 
		{
		    taxonomy_options = phpi_admin_object.ph_taxonomy_terms[taxonomy];
		}

		var row_html_dropdown = '';
		row_html_dropdown += '<select name="custom_mapping_value[' + taxonomy + '][]">';
		row_html_dropdown += '<option value=""></option>';
		if ( Object.keys(taxonomy_options).length > 0 )
		{	
			for ( var j in taxonomy_options )
			{
				row_html_dropdown += '<option value="' + j + '">' + taxonomy_options[j] + '</option>';
			}
		}
		row_html_dropdown += '</select>';

		var row_html = '';
		row_html += '<tr>';
		row_html += '<td style="padding-left:0"><input type="text" name="custom_mapping[' + taxonomy + '][]" value=""></td>';
		row_html += '<td style="padding-left:0">' + row_html_dropdown + '</td>';
		row_html += '</tr>';

		jQuery('.ph-property-import-admin-settings-import-settings #taxonomy_mapping_table_' + taxonomy).append(row_html);

	});

	jQuery('a.phpi-fetch-xml-nodes').click(function(e)
	{
		e.preventDefault();

		phpi_original_property_node = jQuery('select[name=\'xml_property_node\']').val();
		phpi_original_property_id_node = jQuery('select[name=\'xml_property_id_node\']').val();
		
		jQuery('a.phpi-fetch-xml-nodes').text('Fetching...');
		jQuery('a.phpi-fetch-xml-nodes').attr('disabled', 'disabled');
		jQuery('select[name=\'xml_property_node\']').empty();
		jQuery('select[name=\'xml_property_node\']').append('<option value="">Fetching nodes...</option>');
		jQuery('select[name=\'xml_property_id_node\']').empty();
		jQuery('select[name=\'xml_property_id_node\']').append('<option value="">Fetching nodes...</option>');
		jQuery('input[name=\'xml_property_node_options\']').val('');

		jQuery.ajax({
        	url : ajaxurl,
        	data : {
        		action: "propertyhive_property_import_fetch_xml_nodes", 
        		url : jQuery(this).parent().parent().parent().find('input[name=\'xml_xml_url\']').val(), 
        		ajax_nonce: phpi_admin_object.ajax_nonce
        	},
        	dataType : "json",
        	success: function(response) 
        	{
        		jQuery('a.phpi-fetch-xml-nodes').text('Fetch XML');
				jQuery('a.phpi-fetch-xml-nodes').attr('disabled', false);
				jQuery('select[name=\'xml_property_node\']').empty();
				jQuery('select[name=\'xml_property_id_node\']').empty();

            	if ( response.success == true )
            	{
            		var nodes = new Array();
            		for ( var i in response.nodes )
            		{
            			var selected_html = '';
            			if ( phpi_original_property_node == response.nodes[i] )
            			{
            				selected_html = ' selected';
            			}
            			jQuery('select[name=\'xml_property_node\']').append('<option value="' + response.nodes[i] + '"' + selected_html + '>' + response.nodes[i] + '</option>');
            			nodes.push(response.nodes[i]);
            		}
            		jQuery('input[name=\'xml_property_node_options\']').val(JSON.stringify(nodes));
            		phpi_create_xml_property_id_node_options();
            		phpi_create_xml_field_mapping_options();
            	}
            	else
            	{
            		alert(response.error);
            	}
         	},
         	error: function(jqXHR, textStatus, errorThrown) {
		        jQuery('a.phpi-fetch-xml-nodes').text('Fetch XML');
		        jQuery('a.phpi-fetch-xml-nodes').attr('disabled', false);
		        console.error('AJAX Error:', textStatus, errorThrown);
		        alert('Failed to fetch XML nodes. Error: ' + (errorThrown || 'Unknown error'));
		    }
      	})  
	});

	jQuery('a.phpi-fetch-csv-fields').click(function(e)
	{
		e.preventDefault();

		phpi_original_property_id_field = jQuery('select[name=\'csv_property_id_field\']').val();
		phpi_original_image_field = jQuery('select[name=\'image_field\']').val();
		phpi_original_floorplan_field = jQuery('select[name=\'floorplan_field\']').val();
		phpi_original_brochure_field = jQuery('select[name=\'brochure_field\']').val();
		phpi_original_epc_field = jQuery('select[name=\'epc_field\']').val();
		phpi_original_virtual_tour_field = jQuery('select[name=\'virtual_tour_field\']').val();
		
		jQuery('a.phpi-fetch-csv-fields').text('Fetching...');
		jQuery('a.phpi-fetch-csv-fields').attr('disabled', 'disabled');
		jQuery('select[name=\'csv_property_id_field\']').empty();
		jQuery('select[name=\'csv_property_id_field\']').append('<option value="">Fetching fields...</option>');
		jQuery('input[name=\'csv_property_field_options\']').val('');

		jQuery.ajax({
        	url : ajaxurl,
        	data : {
        		action: "propertyhive_property_import_fetch_csv_fields", 
        		url : jQuery(this).parent().parent().parent().find('input[name=\'csv_csv_url\']').val(), 
        		delimiter : jQuery(this).parent().parent().parent().find('input[name=\'csv_csv_delimiter\']').val(), 
        		ajax_nonce: phpi_admin_object.ajax_nonce
        	},
        	dataType : "json",
        	success: function(response) 
        	{
        		jQuery('a.phpi-fetch-csv-fields').text('Fetch CSV');
				jQuery('a.phpi-fetch-csv-fields').attr('disabled', false);
				jQuery('select[name=\'csv_property_id_field\']').empty();

            	if ( response.success == true )
            	{
            		var fields = new Array();
            		for ( var i in response.fields )
            		{
            			fields.push(response.fields[i]);
            		}
            		jQuery('input[name=\'csv_property_field_options\']').val(JSON.stringify(fields));
            		phpi_create_csv_property_id_field_options();
            		phpi_create_csv_field_mapping_options();
            	}
            	else
            	{
            		alert(response.error);
            	}
         	}
      	})  
	});

	jQuery('body').on('click', '#xml-nodes-found a', function(e)
	{
		e.preventDefault();
	});

	ph_property_import_show_format_settings();
	phpi_build_field_mapping_rule_accordions();
	phpi_expertagent_settings();

	jQuery('.xml-rules-available-fields a').draggable({
	    revert: true,
	    helper: 'clone',
	    appendTo: 'body'
	});

	jQuery('.csv-rules-available-fields a').draggable({
	    revert: true,
	    helper: 'clone',
	    appendTo: 'body'
	});

	jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable({
	    drop: function (event, ui) {
	        this.value += jQuery(ui.draggable).text();
	    }
	});

	jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable({
	    drop: function (event, ui) {
	        this.value += '{' + jQuery(ui.draggable).text() + '}';
	    }
	});

	jQuery('body').on('change', 'select[name=\'xml_expertagent_data_source\']', function()
	{
		phpi_expertagent_settings();
	});

	$('a.test-import-details').click(function(e)
	{
		e.preventDefault();

		var test_button = $(this);

		test_button.parent().find('.test-results-success').hide();
		test_button.parent().find('.test-results-error').hide();

		$(this).html('Testing...');
		$(this).attr('disabled', 'disabled');

		var format = $(this).data('format');

		var parentTd = jQuery('#import_settings_' + format);

		var data = {
			'action': 'propertyhive_test_property_import_details',
			'format': format
		};

		// Find all input fields within that 'td'
        var inputs = parentTd.find('input, select');

        parentTd.find('input, select').each(function() 
        {
		    var element = $(this);
		    var element_name = element.attr('name').replace(format + "_", "");

		    // Handle checkboxes
		    if (element.attr('type') === 'checkbox') {
		        data[element_name] = element.is(':checked') ? element.val() : 'no';
		    // Handle select fields
		    } else if (element.is('select')) {
		        // Handle multi-select
		        if (element.prop('multiple')) {
		            data[element_name] = element.val() || [];
		        } else {
		            // Handle single select
		            data[element_name] = element.val();
		        }
		    // Handle other input fields (e.g., text, number, etc.)
		    } else {
		        data[element_name] = element.val();
		    }
		});

		$.post( ajaxurl, data, function(response) 
		{
			if ( response.success == true )
			{
				test_button.parent().find('.test-results-success').html('<p>Details appear valid. ' + response.properties + ' properties found for importing.</p>');
				test_button.parent().find('.test-results-success').show();
			}
			else
			{
				test_button.parent().find('.test-results-error').html('<p>' + response.error + '</p>');
				test_button.parent().find('.test-results-error').show();
			}

			test_button.html('Test Details');
			test_button.attr('disabled', false);
		});
	});

	if ( $('select#format').length > 0 )
	{
		$('select#format').select2({ placeholder:"Select..." });
	}
});

jQuery(window).on( "resize", function() 
{
	phpi_set_fields_size_properties();
});

jQuery(window).on( "scroll", function() 
{
	phpi_set_fields_size_properties();
});

function phpi_build_field_mapping_rule_accordions()
{
	if ( jQuery('#field_mapping_rules').length > 0 )
	{
		if ( jQuery('#field_mapping_rules').children().length == 0 )
		{
			jQuery('#no_field_mappings').show();
		}
		else
		{
			jQuery('#no_field_mappings').hide();

			// loop through accordions and set rule descriptions
			jQuery('.rule-accordion').each(function()
			{
				var rule_description = '<span>If</span>';

				var field_in_feed = jQuery(this).find('.and-rules .or-rule').eq(0).find('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').val();
				if ( field_in_feed == '' ) { field_in_feed = '<em>(no field specified)</em>'; }

				rule_description += '<span><code>' + field_in_feed + '</code></span>';

				var operator = jQuery(this).find('.and-rules .or-rule').eq(0).find('select[name*=\'field_mapping_rules\'][name*=\'[operator]\'] option:selected').text();
				rule_description += '<span><code>' + operator + '</code></span>';

				var operator = jQuery(this).find('.and-rules .or-rule').eq(0).find('select[name*=\'field_mapping_rules\'][name*=\'[operator]\'] option:selected').val();
				if ( operator != 'exists' && operator != 'not_exists' )
				{
					var value_in_feed = jQuery(this).find('.and-rules .or-rule').eq(0).find('input[name*=\'field_mapping_rules\'][name*=\'[equal]\']').val();
					if ( value_in_feed == '' ) { value_in_feed = '<em>(no value specified)</em>'; }
					rule_description += '<span><code>' + value_in_feed + '</code></span>';
				}

				var num_or_rules = jQuery(this).find('.and-rules .or-rule').length;
				if ( num_or_rules > 1 )
				{
					rule_description += '<span>(+ ' + (num_or_rules - 1) + ' rule' + ( (num_or_rules-1) != 1 ? 's' : '' ) + ')</span>';
				}

				rule_description += '<span>then set</span>';

				var field_in_propertyhive = jQuery(this).find('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\'] option:selected').text();
				if ( field_in_propertyhive == '' ) { field_in_propertyhive = '<em>(no field specified)</em>'; }
				rule_description += '<span><code>' + field_in_propertyhive + '</code></span>';

				rule_description += '<span>to</span>';

				var result_type = jQuery(this).find('input[name*=\'field_mapping_rules\'][name*=\'[result_type]\']').val();

				if ( result_type == 'dropdown' )
				{
					var value_in_propertyhive = jQuery(this).find('select[name*=\'field_mapping_rules\'][name*=\'[result_option]\'] option:selected').text();
				}
				else
				{
					var value_in_propertyhive = jQuery(this).find('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').val();
					value_in_propertyhive = value_in_propertyhive.replace(/</g, '&lt;').replace(/>/g, '&gt;');
				}

				if ( value_in_propertyhive == '' ) { value_in_propertyhive = '<em>(no value specified)</em>'; }
				rule_description += '<span><code>' + value_in_propertyhive + '</code></span>';

				jQuery(this).find('.rule-description').html(rule_description);
			});
		}
	}

	phpi_show_missing_mandatory_field_mapping();
}

var last_safe_scroll = 0;
function phpi_set_fields_size_properties()
{
	var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();

	if ( selected_format == 'xml' )
	{
		jQuery('.xml-rules-available-fields').css('transform', 'translateY(0)').height('auto');

		var max_available_space = jQuery(window).height() - 120;

		jQuery('.xml-rules-available-fields').hide();
		var available_fields_container_height = jQuery('.rules-table').outerHeight() - 40;
		if ( available_fields_container_height > max_available_space )
		{
			available_fields_container_height = max_available_space;
		}
		jQuery('.xml-rules-available-fields').show().outerHeight(available_fields_container_height);

		// set top position
		var window_scroll = jQuery(window).scrollTop();
		
		if ( window_scroll > ( jQuery('.rules-table-available-fields').offset().top - 50 ) )
		{
			var target_top = window_scroll - ( jQuery('.rules-table-available-fields').offset().top - 50 );

			// make sure it's not going to go off the bottom of the screen
			var difference = ( target_top + jQuery('.xml-rules-available-fields').outerHeight() ) - (jQuery('.rules-table').outerHeight());
			if ( difference < -40 )
			{
				last_safe_scroll = target_top;
			}
			else
			{
				
			}
			jQuery('.xml-rules-available-fields').css('transform', 'translateY(' + last_safe_scroll + 'px)');
		}
		else
		{
			jQuery('.xml-rules-available-fields').css('transform', 'translateY(0)');
			last_safe_scroll = 0;
		}
	}
	if ( selected_format == 'csv' )
	{
		jQuery('.csv-rules-available-fields').css('transform', 'translateY(0)').height('auto');

		var max_available_space = jQuery(window).height() - 120;

		jQuery('.csv-rules-available-fields').hide();
		var available_fields_container_height = jQuery('.rules-table').outerHeight() - 40;
		if ( available_fields_container_height > max_available_space )
		{
			available_fields_container_height = max_available_space;
		}
		jQuery('.csv-rules-available-fields').show().outerHeight(available_fields_container_height);

		// set top position
		var window_scroll = jQuery(window).scrollTop();
		
		if ( window_scroll > ( jQuery('.rules-table-available-fields').offset().top - 50 ) )
		{
			var target_top = window_scroll - ( jQuery('.rules-table-available-fields').offset().top - 50 );

			// make sure it's not going to go off the bottom of the screen
			var difference = ( target_top + jQuery('.csv-rules-available-fields').outerHeight() ) - (jQuery('.rules-table').outerHeight());
			if ( difference < -40 )
			{
				last_safe_scroll = target_top;
			}
			else
			{
				
			}
			jQuery('.csv-rules-available-fields').css('transform', 'translateY(' + last_safe_scroll + 'px)');
		}
		else
		{
			jQuery('.csv-rules-available-fields').css('transform', 'translateY(0)');
			last_safe_scroll = 0;
		}
	}
}

function phpi_create_xml_property_id_node_options()
{
	jQuery('select[name=\'xml_property_id_node\']').empty();

	var nodes = jQuery('input[name=\'xml_property_node_options\']').val();

	if ( nodes == '' )
	{
		return;
	}

	var property_node = jQuery('select[name=\'xml_property_node\']').val();

	if ( property_node != '' )
	{
		nodes = JSON.parse(nodes);

		for ( var i in nodes )
		{
			var selected_html = '';
			var node = nodes[i]/*.replace(/\/\//g, "/")*/;
			if ( phpi_original_property_id_node == node )
			{
				selected_html = ' selected';
			}

			if ( node.indexOf(property_node) == -1 )
			{
				continue;
			}

			node = node.replace(property_node, "");
			if ( node == '' )
			{
				continue;
			}

			jQuery('select[name=\'xml_property_id_node\']').append('<option value="' + node + '"' + selected_html + '>' + node + '</option>');
		}
	}
}

function phpi_create_csv_property_id_field_options()
{
	jQuery('select[name=\'csv_property_id_field\']').empty();
	jQuery('select[name=\'image_field\']').empty();
	jQuery('select[name=\'floorplan_field\']').empty();
	jQuery('select[name=\'brochure_field\']').empty();
	jQuery('select[name=\'epc_field\']').empty();
	jQuery('select[name=\'virtual_tour_field\']').empty();

	var fields = jQuery('input[name=\'csv_property_field_options\']').val();

	if ( fields == '' )
	{
		return;
	}

	fields = JSON.parse(fields);

	for ( var i in fields )
	{
		var selected_html = '';
		var field = fields[i];
		if ( phpi_original_property_id_field == field )
		{
			selected_html = ' selected';
		}
		jQuery('select[name=\'csv_property_id_field\']').append('<option value="' + field + '"' + selected_html + '>' + field + '</option>');

		var selected_html = '';
		var field = fields[i];
		if ( phpi_original_image_field == field )
		{
			selected_html = ' selected';
		}
		jQuery('select[name=\'image_field\']').append('<option value="' + field + '"' + selected_html + '>' + field + '</option>');

		var selected_html = '';
		var field = fields[i];
		if ( phpi_original_floorplan_field == field )
		{
			selected_html = ' selected';
		}
		jQuery('select[name=\'floorplan_field\']').append('<option value="' + field + '"' + selected_html + '>' + field + '</option>');

		var selected_html = '';
		var field = fields[i];
		if ( phpi_original_brochure_field == field )
		{
			selected_html = ' selected';
		}
		jQuery('select[name=\'brochure_field\']').append('<option value="' + field + '"' + selected_html + '>' + field + '</option>');

		var selected_html = '';
		var field = fields[i];
		if ( phpi_original_epc_field == field )
		{
			selected_html = ' selected';
		}
		jQuery('select[name=\'epc_field\']').append('<option value="' + field + '"' + selected_html + '>' + field + '</option>');

		var selected_html = '';
		var field = fields[i];
		if ( phpi_original_virtual_tour_field == field )
		{
			selected_html = ' selected';
		}
		jQuery('select[name=\'virtual_tour_field\']').append('<option value="' + field + '"' + selected_html + '>' + field + '</option>');
	}
}

function phpi_create_xml_field_mapping_options()
{	
	jQuery('.xml-rules-available-fields a').draggable( "destroy" );

	jQuery('#no_nodes_found').hide();
	jQuery('#xml-nodes-found').empty()

	var nodes = jQuery('input[name=\'xml_property_node_options\']').val();

	if ( nodes == '' )
	{
		jQuery('#no_nodes_found').show();
		return;
	}

	var property_node = jQuery('select[name=\'xml_property_node\']').val();

	if ( property_node == '' )
	{
		jQuery('#no_nodes_found').show();
		return;
	}

	nodes = JSON.parse(nodes);

	for ( var i in nodes )
	{
		var node = nodes[i]/*.replace(/\/\//g, "/")*/;

		if ( node.indexOf(property_node) == -1 )
		{
			continue;
		}

		node = node.replace(property_node, "");
		if ( node == '' )
		{
			continue;
		}

		jQuery('#xml-nodes-found').append('<a href="#">' + node + '</a>');
	}

	phpi_set_fields_size_properties();

	jQuery('.xml-rules-available-fields a').draggable({
	    revert: true,
	    helper: 'clone',
	    appendTo: 'body'
	});
}

function phpi_create_csv_field_mapping_options()
{	
	jQuery('.csv-rules-available-fields a').draggable( "destroy" );

	jQuery('#no_fields_found').hide();
	jQuery('#csv-fields-found').empty()

	var fields = jQuery('input[name=\'csv_property_field_options\']').val();

	if ( fields == '' )
	{
		jQuery('#no_fields_found').show();
		return;
	}

	fields = JSON.parse(fields);

	for ( var i in fields )
	{
		var field = fields[i];

		jQuery('#csv-fields-found').append('<a href="#">' + field + '</a>');
	}

	phpi_set_fields_size_properties();

	jQuery('.csv-rules-available-fields a').draggable({
	    revert: true,
	    helper: 'clone',
	    appendTo: 'body'
	});
}

function add_field_mapping_or_rule()
{
	if ( jQuery('#field_mapping_rules').length > 0 )
	{
		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable( "destroy" );
		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable( "destroy" );

		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2("destroy");

		jQuery("#field_mapping_rule_template input").each(function()
		{
		    jQuery(this).attr("value", jQuery(this).val());
		});
		jQuery('#field_mapping_rule_template select option').each(function()
		{ 
			this.defaultSelected = this.selected; 
		});

		var template_html = jQuery('#field_mapping_rule_template').html();

		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);
		template_html = template_html.replace("{rule_count}", phpi_rule_count);

		phpi_rule_count = phpi_rule_count + 1;

		jQuery('#field_mapping_rules').append('<div class="rule-accordion" style="display:none"><div class="rule-accordion-header"><span class="dashicons dashicons-arrow-down-alt2"></span>&nbsp; <span class="rule-description">Rule description here</span><div class="icons"><span class="reorder-rule dashicons dashicons-move" title="Reorder rule"></span> <span class="duplicate-rule dashicons dashicons-admin-page" title="Duplicate Rule"></span> <span class="delete-rule dashicons dashicons-trash" title="Delete Rule"></span></div></div><div class="rule-accordion-contents">' + template_html + '</div></div>');
		jQuery('#field_mapping_rules .rule-accordion:last-child').slideDown();

		// empty template fields
		jQuery("#field_mapping_rule_template input").each(function()
		{
		    jQuery(this).val('');
		});
		jQuery('#field_mapping_rule_template select').each(function()
		{ 
			jQuery(this).val('');
		});

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[field]\']').droppable({
		    drop: function (event, ui) {
		        this.value += jQuery(ui.draggable).text();
		    }
		});

		jQuery('input[name*=\'field_mapping_rules\'][name*=\'[result]\']').droppable({
		    drop: function (event, ui) {
		        this.value += '{' + jQuery(ui.draggable).text() + '}';
		    }
		});

		jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').select2({ allowClear: true, placeholder:"Select..." });
	}

	phpi_build_field_mapping_rule_accordions()
	phpi_set_fields_size_properties();
}

function phpi_show_missing_mandatory_field_mapping()
{
	jQuery('#missing_mandatory_field_mapping').hide();
	jQuery('#field_mapping_warning').hide();

	var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();

	if ( selected_format == 'xml' )
	{
		var found_title_excerpt_or_content = false;
		
		// loop through field mapping and ensure at least title, excerpt or content set
		jQuery('select[name*=\'field_mapping_rules\'][name*=\'propertyhive_field\']').each(function()
		{
			if ( jQuery(this).attr('name') != 'field_mapping_rules[{rule_count}][propertyhive_field]' )
			{
				if ( jQuery(this).val() == 'post_title' || jQuery(this).val() == 'post_excerpt' || jQuery(this).val() == 'post_content' )
				{
					found_title_excerpt_or_content = true;
				}
			}
		});

		if ( !found_title_excerpt_or_content )
		{
			jQuery('#missing_mandatory_field_mapping').show();
			jQuery('#field_mapping_warning').show();
		}
	}
	if ( selected_format == 'csv' )
	{
		var found_title_excerpt_or_content = false;
		
		// loop through field mapping and ensure at least title, excerpt or content set
		jQuery('select[name*=\'field_mapping_rules\'][name*=\'propertyhive_field\']').each(function()
		{
			if ( jQuery(this).attr('name') != 'field_mapping_rules[{rule_count}][propertyhive_field]' )
			{
				if ( jQuery(this).val() == 'post_title' || jQuery(this).val() == 'post_excerpt' || jQuery(this).val() == 'post_content' )
				{
					found_title_excerpt_or_content = true;
				}
			}
		});

		if ( !found_title_excerpt_or_content )
		{
			jQuery('#missing_mandatory_field_mapping').show();
			jQuery('#field_mapping_warning').show();
		}
	}
}

function phpi_show_already_mapped_warning()
{
	var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();

	jQuery('.already-mapped-warning').hide();

	for ( var i in phpi_admin_object.formats )
	{
		if ( i == selected_format )
		{
			if ( phpi_admin_object.formats[i].hasOwnProperty('propertyhive_fields_imported_by_default') )
			{
				var propertyhive_fields_imported_by_default = phpi_admin_object.formats[i].propertyhive_fields_imported_by_default;

				jQuery('select[name*=\'field_mapping_rules\'][name*=\'[propertyhive_field]\']').each(function()
				{
					if ( Object.values(propertyhive_fields_imported_by_default).indexOf(jQuery(this).val()) !== -1 ) 
					{
						if ( jQuery(this).val() != '' )
						{
							// We found it already mapped. Show warning
							jQuery(this).parent().find('.already-mapped-field').html( jQuery(this).children(':selected').text().toLowerCase() );
							jQuery(this).parent().find('.already-mapped-warning').show();
						}
					}
				});
			}
		}
	}
}

function ph_property_import_show_format_settings()
{
	var selected_format = jQuery('.ph-property-import-admin-settings-import-settings .settings-panel #format').val();

	jQuery('.ph-property-import-admin-settings-import-settings .settings-panel .import-settings-format').hide();
	jQuery('#import_settings_' + selected_format).fadeIn('fast');

	jQuery('.no-format-notice').hide();
	jQuery('.phpi-import-format-name').html('');

	jQuery('.ph-property-import-admin-settings-import-settings div[id^=\'taxonomy_mapping_\']').hide();

	jQuery('.ph-property-import-admin-settings-import-settings .xml-rules-available-fields').hide();
	jQuery('.ph-property-import-admin-settings-import-settings .csv-rules-available-fields').hide();

	jQuery('#import_setting_tab_taxonomies').show();
	jQuery('#import_setting_tab_offices').show();
	jQuery('#import_setting_tab_media').hide();

	jQuery('#missing_mandatory_field_mapping').hide();

	//jQuery('#row_background_mode').hide();

	jQuery('.ph-property-import-admin-settings-import-settings #location_address_field').empty();

	jQuery('.ph-property-import-admin-settings-import-settings #taxonomy_mapping_table_property_type tr').not(':nth-child(1)').remove();

	if ( selected_format == '' )
	{
		jQuery('.no-format-notice').show();
	}
	else
	{
		jQuery('.phpi-import-format-name').html(phpi_admin_object.formats[selected_format].name);

		phpi_show_missing_mandatory_field_mapping();
		phpi_show_already_mapped_warning();

		if ( selected_format == 'xml' )
		{
			jQuery('#import_setting_tab_taxonomies').hide();
			jQuery('#import_setting_tab_offices').hide();
			jQuery('#import_setting_tab_media').show();
			jQuery('.ph-property-import-admin-settings-import-settings .xml-rules-available-fields').show();
		}

		if ( selected_format == 'csv' )
		{
			jQuery('#import_setting_tab_taxonomies').hide();
			jQuery('#import_setting_tab_offices').hide();
			jQuery('#import_setting_tab_media').show();
			jQuery('.ph-property-import-admin-settings-import-settings .csv-rules-available-fields').show();
		}

		if ( selected_format != 'xml' && selected_format != 'csv' )
		{
			phpi_draw_mappings();
		}
		
		jQuery('#row_limit').show();
		if ( phpi_admin_object.formats[selected_format].hasOwnProperty('limit_properties') )
		{
			if ( phpi_admin_object.formats[selected_format].limit_properties == false )
			{
				jQuery('#row_limit').hide();
			}
		}

		jQuery('#row_limit_images').show();
		if ( phpi_admin_object.formats[selected_format].hasOwnProperty('limit_images') )
		{
			if ( phpi_admin_object.formats[selected_format].limit_images == false )
			{
				jQuery('#row_limit_images').hide();
			}
		}
	}

	phpi_expertagent_settings();
}