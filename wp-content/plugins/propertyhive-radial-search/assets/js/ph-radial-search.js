jQuery(document).ready(function()
{
	jQuery('.current-location a').click(function(e)
	{
		e.preventDefault();

		if (navigator.geolocation) 
		{
          	navigator.geolocation.getCurrentPosition(function(position) 
          	{
          		jQuery('input[name=\'address_keyword\']').val('Current Location');
          		jQuery('input[name=\'lat\']').val(position.coords.latitude);
          		jQuery('input[name=\'lng\']').val(position.coords.longitude);
          	}, function() 
          	{
            	ph_handle_location_error(true);
          	});
        }
        else
        {
          	// Browser doesn't support Geolocation
          	ph_handle_location_error(false);
        }
	});
});

function ph_handle_location_error(browserHasGeolocation) 
{
    var message = (browserHasGeolocation ?
        'Error: The Geolocation service failed.' :
        'Error: Your browser doesn\'t support geolocation.');
    alert(message);
}