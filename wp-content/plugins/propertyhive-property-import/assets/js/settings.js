jQuery( function ( $ ) {

	jQuery('.ph-property-import-admin-settings-import-settings input[name=\'email_reports\']').change(function()
	{
		ph_property_import_show_email_reports_settings();
	});

	jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li a').click(function(e)
	{
		e.preventDefault();

		var this_href = jQuery(this).attr('href');

		jQuery('.ph-property-import-admin-settings-import-settings .left-tabs ul li').removeClass('active');
		jQuery(this).parent().addClass('active');

		jQuery('.ph-property-import-admin-settings-import-settings .settings-panel').hide();
		jQuery(this_href).show();
	});

	ph_property_import_show_email_reports_settings();

});

function ph_property_import_show_email_reports_settings()
{
	jQuery('.ph-property-import-admin-settings-import-settings #email_reports_to_row').hide();

	if ( jQuery('.ph-property-import-admin-settings-import-settings input[name=\'email_reports\']').is(':checked') )
	{
		jQuery('.ph-property-import-admin-settings-import-settings #email_reports_to_row').show();
	}
}