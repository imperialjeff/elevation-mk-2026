jQuery( function ( $ ) {

	$( '#toggle_import_data_div' ).click(function(e)
	{
		e.preventDefault();

		if ( $( '#import_data_div' ).is(":visible") )
		{
			$( '#import_data_div' ).hide();
			$(this).text('Show Import Data');
		}
		else
		{
			$( '#import_data_div' ).show();
			$(this).text('Hide Import Data');
		}
	});

});