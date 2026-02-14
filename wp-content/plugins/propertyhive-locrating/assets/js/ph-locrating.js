var locrating_schools_frame_id = 'propertyhive_locrating_schools_frame';
var locrating_amenities_frame_id = 'propertyhive_locrating_amenities_frame';
var locrating_transport_frame_id = 'propertyhive_locrating_transport_frame';
var locrating_broadband_checker_frame_id = 'propertyhive_locrating_broadband_checker_frame';
var locrating_all_in_one_frame_id = 'propertyhive_locrating_all_in_one_frame';

jQuery( function($){

    $('li.action-locrating-schools a').click(function() {
       	try{
       	  	var lat = $('#'+locrating_schools_frame_id).data('lat');
       	  	var lng = $('#'+locrating_schools_frame_id).data('lng');

       	  	jQuery('#' + locrating_schools_frame_id).width(jQuery(window).width() - 100).height(jQuery(window).height() - 200);

            setLocratingIFrameProperties({'id': locrating_schools_frame_id, 'lat': lat, 'lng' : lng});

       	} catch (err) { 
       	   	console.log(err); 
       	};

    });

    $('li.action-locrating-amenities a').click(function() {
        try{
            var lat = $('#'+locrating_amenities_frame_id).data('lat');
            var lng = $('#'+locrating_amenities_frame_id).data('lng');

            jQuery('#' + locrating_amenities_frame_id).width(jQuery(window).width() - 100).height(jQuery(window).height() - 200);

            setLocratingIFrameProperties({'id': locrating_amenities_frame_id, 'lat': lat, 'lng' : lng, 'type':'localinfo'});

        } catch (err) { 
            console.log(err); 
        };

    });

    $('li.action-locrating-transport a').click(function() {
        try{
            var lat = $('#'+locrating_transport_frame_id).data('lat');
            var lng = $('#'+locrating_transport_frame_id).data('lng');

            jQuery('#' + locrating_transport_frame_id).width(jQuery(window).width() - 100).height(jQuery(window).height() - 200);

            setLocratingIFrameProperties({'id': locrating_transport_frame_id, 'lat': lat, 'lng' : lng, 'type':'transport'});

        } catch (err) { 
            console.log(err); 
        };

    });

    $('li.action-locrating-broadband-checker a').click(function() {
        try{
            var lat = $('#'+locrating_broadband_checker_frame_id).data('lat');
            var lng = $('#'+locrating_broadband_checker_frame_id).data('lng');

            jQuery('#' + locrating_broadband_checker_frame_id).width(jQuery(window).width() - 100).height(jQuery(window).height() - 200);

            setLocratingIFrameProperties({'id': locrating_broadband_checker_frame_id, 'lat': lat, 'lng' : lng, 'type' : 'broadband', showmap : 'true'});

        } catch (err) { 
            console.log(err); 
        };

    });

    $('li.action-locrating-all-in-one a').click(function() {
        try{
            var lat = $('#'+locrating_all_in_one_frame_id).data('lat');
            var lng = $('#'+locrating_all_in_one_frame_id).data('lng');

            jQuery('#' + locrating_all_in_one_frame_id).width(jQuery(window).width() - 100).height(jQuery(window).height() - 200);

            setLocratingIFrameProperties({'id': locrating_all_in_one_frame_id, 'lat': lat, 'lng' : lng, 'type':'all'});

        } catch (err) { 
            console.log(err); 
        };

    });
});

jQuery(window).on('load', function()
{
    if (  jQuery('.locrating-schools-shortcode #'+locrating_schools_frame_id).length > 0 )
    {
        var lat = jQuery('#'+locrating_schools_frame_id).data('lat');
        var lng = jQuery('#'+locrating_schools_frame_id).data('lng');

        setLocratingIFrameProperties({'id': locrating_schools_frame_id, 'lat': lat, 'lng' : lng});
    }

    if ( jQuery('.locrating-amenities-shortcode #'+locrating_amenities_frame_id).length > 0 )
    {
        var lat = jQuery('#'+locrating_amenities_frame_id).data('lat');
        var lng = jQuery('#'+locrating_amenities_frame_id).data('lng');

        setLocratingIFrameProperties({'id': locrating_amenities_frame_id, 'lat': lat, 'lng' : lng, 'type':'localinfo'});
    }

    if ( jQuery('.locrating-transport-shortcode #'+locrating_transport_frame_id).length > 0 )
    {
        var lat = jQuery('#'+locrating_transport_frame_id).data('lat');
        var lng = jQuery('#'+locrating_transport_frame_id).data('lng');

        setLocratingIFrameProperties({'id': locrating_transport_frame_id, 'lat': lat, 'lng' : lng, 'type':'transport'});
    }

    if ( jQuery('.locrating-broadband-checker-shortcode #'+locrating_broadband_checker_frame_id).length > 0 )
    {
        var lat = jQuery('#'+locrating_broadband_checker_frame_id).data('lat');
        var lng = jQuery('#'+locrating_broadband_checker_frame_id).data('lng');

        setLocratingIFrameProperties({'id': locrating_broadband_checker_frame_id, 'lat': lat, 'lng' : lng, 'type' : 'broadband', showmap : 'true'});
    }

    if ( jQuery('.locrating-all-in-one-shortcode #'+locrating_all_in_one_frame_id).length > 0 )
    {
        var lat = jQuery('#'+locrating_all_in_one_frame_id).data('lat');
        var lng = jQuery('#'+locrating_all_in_one_frame_id).data('lng');

        setLocratingIFrameProperties({'id': locrating_all_in_one_frame_id, 'lat': lat, 'lng' : lng, 'type':'all'});
    }
});