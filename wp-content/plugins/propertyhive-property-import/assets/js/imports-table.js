var phpi_draw_table_timeout;
var phpi_status_timeout;

function phpi_click_run_now()
{
	// One of the 'Run now' links/buttons has been clicked
	jQuery('a.link-manually-execute-import').text('Processing...');
	jQuery('a.link-manually-execute-import').css('pointerEvents', 'none');

	jQuery('a.button-manually-execute').text('Processing...');
	jQuery('a.button-manually-execute').css('pointerEvents', 'none');
	jQuery('a.button-manually-execute').css('opacity', '0.5');

	phpi_draw_table_timeout = setTimeout( function() { phpi_draw_automatic_imports_table(); }, 1000 );
}

function phpi_draw_automatic_imports_table()
{
	clearTimeout(phpi_draw_table_timeout);
	clearTimeout(phpi_status_timeout);

	jQuery.ajax({
    	url : ajaxurl,
    	data : {
    		action: "propertyhive_property_import_draw_automatic_imports_table", 
    		order: phpi_admin_object.table_order,
    		orderby: phpi_admin_object.table_orderby,
    		phpi_filter: phpi_admin_object.table_phpi_filter,
    		phpi_filter_format: phpi_admin_object.table_phpi_filter_format,
    		ajax_nonce: phpi_admin_object.ajax_nonce
    	},
    	success: function(response) 
    	{
    		jQuery('.automatic-imports-table').html(response);
    		phpi_show_running_status();
    	},
    	error: function(xhr, status, error)
    	{
    		console.error('Automatic imports table AJAX request failed:', status, error);
    	},
    	complete: function()
    	{
    		phpi_draw_table_timeout = setTimeout( phpi_draw_automatic_imports_table, phpi_admin_object.table_refresh_automatic_imports );
    	}
	});
}

function phpi_show_running_status()
{
	clearTimeout(phpi_status_timeout);

	var import_ids = new Array();
	jQuery('.running-now').each(function()
	{
		var import_id = jQuery(this).data('import-id');
		if ( import_id != '' )
		{
			import_ids.push(import_id);
		}
	});

	if ( import_ids.length > 0 )
	{
		jQuery.ajax({
	    	url : ajaxurl,
	    	data : {
	    		action: "propertyhive_property_import_get_running_status", 
	    		import_ids: import_ids,
	    		ajax_nonce: phpi_admin_object.ajax_nonce
	    	},
	    	dataType : "json",
	    	success: function(response) 
	    	{
	    		if (typeof response === 'object' && response !== null) 
	    		{
		            Object.keys(response).forEach(function(key) 
		            {
		                var status = response[key].status;

		                if ( status == 'finished' )
			    		{
			    			jQuery('.running-now[data-import-id="' + key + '"]').remove();
			    			jQuery('.running-now-status[data-import-id="' + key + '"]').remove();

			    			phpi_draw_automatic_imports_table();
			    		}
			    		else
			    		{
				    		jQuery('.running-now-status[data-import-id="' + key + '"]').html(status);

				    		if ( status.indexOf('Importing') !== false || status.indexOf('Parsing') !== false || status.indexOf('Removing') !== false )
				    		{
				    			jQuery('.link-manually-execute-import').css({
				    				'pointerEvents': 'none',
				    			}).text('Processing...');
				    			
				    			jQuery('.button-manually-execute').css({
				    				'pointerEvents': 'none',
				    				'opacity': 0.5
				    			}).text('Processing...');
				    		}
			    		}

			    		var queued_media = response[key].queued_media;

			    		if ( parseInt(queued_media) != 0 )
			    		{
			    			jQuery('.queued-media-items[data-import-id="' + key + '"]').html(queued_media);
			    		}

			    		var queued_properties = response[key].queued_properties;

			    		if ( parseInt(queued_properties) != 0 )
			    		{
			    			jQuery('.queued-properties[data-import-id="' + key + '"]').html(' (' + queued_properties + ' queued properties)');
			    		}
		            });
		        }
		        else
		        {
		            console.error('Response is not an object');
		            console.log(response);
		        }
	    	},
	    	error: function(xhr, status, error) {
	    		console.error('AJAX request failed:', status, error);
	    	},
	    	complete: function() {
	    		phpi_status_timeout = setTimeout( phpi_show_running_status, phpi_admin_object.table_refresh_status_interval );
	    	}
		});
	}
	else
	{
		jQuery('.button-manually-execute').css({
			'pointerEvents': 'auto',
			'opacity': 1
		}).text('Manually Execute Import');

		phpi_status_timeout = setTimeout( phpi_show_running_status, phpi_admin_object.table_refresh_status_interval );
	}
}

jQuery( function ( $ ) {

	if ( jQuery('.automatic-imports-table').length > 0 )
	{
		phpi_draw_automatic_imports_table();
		phpi_draw_table_timeout = setTimeout( phpi_draw_automatic_imports_table, phpi_admin_object.table_refresh_automatic_imports );
		phpi_status_timeout = setTimeout( phpi_show_running_status, phpi_admin_object.table_refresh_status_interval );
	}

	jQuery('body').on('click', '.ph-property-import-admin-settings-automatic-imports .automatic-imports-table .trash a', function(e)
	{
		var confirm_box = confirm( "Are you sure you want to delete this import?\n\nPLEASE NOTE: If any properties have been imported via this import they will remain in place and will need to be deleted manually" );

		return confirm_box;
	});

	// troubleshooting
	$( '.ph-import-wizard .ph-import-wizard-one input[type=\'radio\'][name=\'issue\']' ).change(function(e)
	{
		var selected_issue = $( '.ph-import-wizard .ph-import-wizard-one input[type=\'radio\'][name=\'issue\']:checked' ).val();

		$('#property-in-question-existing').hide();
		$('#property-in-question-missing').hide();

		if ( /*selected_issue == 'property-wrong-data' || */selected_issue == 'property-not-removed' )
		{
			$('#property-in-question-existing').show();
			
			$('#property-in-question-existing select').select2({ 
				placeholder:"Search property...",
				ajax: {
				    url: ajaxurl, // Use the global ajaxurl variable provided by WordPress
			        dataType: 'json',
			        delay: 250,  // Optional: Delay to prevent excessive requests
			        data: function (params) {
			            return {
			                action: 'propertyhive_property_import_search_properties', // Specify the action to call in PHP
			                search: params.term, // Pass the search term to the PHP handler
			                import_id: $('#import_id').val()
			            };
			        },
			        processResults: function (data) {
			            return {
			                results: data // Data should be formatted in JSON on the backend
			            };
			        },
			        cache: true
			  	}
			});
		}
		if ( selected_issue == 'property-not-importing' )
		{
			$('#property-in-question-missing').show();
		}
	});

	$('body').on('click', 'a.kill-import', function()
	{
		var import_id = $(this).data('import-id');

		if ( import_id != '' )
		{
			$(this).text('Stopping...');
			$(this).css('pointerEvents', 'none');
			$(this).attr('disabled', 'disabled');

			jQuery.ajax({
	        	url : ajaxurl,
	        	data : {
	        		action: "propertyhive_property_import_kill_import", 
	        		import_id : import_id, 
	        		ajax_nonce: phpi_admin_object.ajax_nonce
	        	},
	        	success: function(response) 
	        	{

	        	},
	        	error: function(jqXHR, textStatus, errorThrown) 
	        	{
			        console.error('AJAX Error:', textStatus, errorThrown);
			        alert('Failed to kill import. Error: ' + (errorThrown || 'Unknown error'));

			        $(this).text('Stop Import');
			        $(this).css('pointerEvents', 'auto');
					$(this).prop('disabled', false);
			    }
	        });
		}
	});

});