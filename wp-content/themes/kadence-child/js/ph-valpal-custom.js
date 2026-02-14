var geocoder;
var original_submit_value = '';

jQuery(document).ready(function()
{
    // Form submitted
    jQuery('#frmValPal').submit(function()
    {
    	if ( ph_valpal.address_lookup != '1' )
    	{
    		if ( jQuery('#frmValPal input[name=\'line1\']').val() == '' )
	        {
	            alert('Please enter a valid property name/number');
	            return false;
	        }

	        if ( jQuery('#frmValPal input[name=\'line2\']').val() == '' )
	        {
	            alert('Please enter a valid street');
	            return false;
	        }
    	}

        if ( jQuery('#frmValPal input[name=\'postcode\']').val() == '' )
        {
            alert('Please enter a valid postcode');
            return false;
        }

        if ( jQuery('#frmValPal input[name=\'name\']').val() == '' )
        {
            alert('Please enter your name');
            return false;
        }

        if ( jQuery('#frmValPal input[name=\'email\']').val() == '' )
        {
            alert('Please enter a valid email address');
            return false;
        }

        if ( jQuery('#frmValPal input[name=\'telephone\']').val() == '' )
        {
            alert('Please enter a valid telephone number');
            return false;
        }

        if ( jQuery('#frmValPal input[name=\'disclaimer\']').length > 0 && jQuery('#frmValPal input[name=\'disclaimer\']:checked').length == 0 )
        {
            alert('Please agree to our privacy terms by checking the tickbox if you wish to proceed');
            return false;
        }

        original_submit_value = jQuery('#frmValPal input[type=\'submit\']').val();

        jQuery('#frmValPal input[type=\'submit\']').val('Retrieving Valuation...');
        jQuery('#frmValPal input[type=\'submit\']').attr('disabled', 'disabled');

        var data = { 
            'action': 'do_val_request',
            'type': jQuery('#frmValPal select[name=\'type\']').val(),
            'bedrooms': jQuery('#frmValPal select[name=\'bedrooms\']').val(),
            'propertytype': jQuery('#frmValPal select[name=\'propertytype\']').val(),
            'name': jQuery('#frmValPal input[name=\'name\']').val(),
            'email': jQuery('#frmValPal input[name=\'email\']').val(),
            'telephone': jQuery('#frmValPal input[name=\'telephone\']').val(),
            'comments': jQuery('#frmValPal textarea[name=\'comments\']').val(),
            'postcode': jQuery('#frmValPal input[name=\'postcode\']').val()
        };

        if ( ph_valpal.address_lookup == '1' )
        {
            data.property = jQuery('#frmValPal select[name=\'property\']').val();
        }
        else
        {
            data.number = jQuery('#frmValPal input[name=\'number\']').val();
            data.street = jQuery('#frmValPal input[name=\'street\']').val();
            data.postcode = jQuery('#frmValPal input[name=\'postcode\']').val();
        }

        jQuery.ajax({
          type: "POST",
          url: ph_valpal.ajax_url,
          data: data,
          success: function(response)
          {
                jQuery('#frmValPal input[type=\'submit\']').val(original_submit_value);
                jQuery('#frmValPal input[type=\'submit\']').attr('disabled', false);

                if (response.error && response.error != '')
                {
                    alert(response.error);
                    return false;
                }

                jQuery('html,body').animate({
                    scrollTop: jQuery('#frmValPal').offset().top - jQuery('header#header').outerHeight()
                });

                // Process min amount
                var amount = response.minvaluation.replace("£", "");
                if ( jQuery('#frmValPal select[name=\'type\']').val() == 'sales' && ph_valpal.sales_min_amount_percentage_modifier != 0 )
                {
                    amount = parseInt(amount.replace(/[^0-9]/g, ''));
                    amount = Math.round( amount + ( amount * ( ph_valpal.sales_min_amount_percentage_modifier / 100 ) ) );
                }
                
                if ( jQuery('#frmValPal select[name=\'type\']').val() == 'lettings' && ph_valpal.lettings_min_amount_percentage_modifier != 0 )
                {
                    amount = parseInt(amount.replace(/[^0-9]/g, ''));
                    amount = Math.round( amount + ( amount * ( ph_valpal.lettings_min_amount_percentage_modifier / 100 ) ) );
                }
                amount = '&pound;' + ph_valpal_add_commas(amount);
                jQuery('#valuation_results .min-amount span').html( amount );

                var min_amount = amount;

                // Process actual amount
                var amount = response.valuation.replace("£", "");
                if ( jQuery('#frmValPal select[name=\'type\']').val() == 'sales' && ph_valpal.sales_actual_amount_percentage_modifier != 0 )
                {
                    amount = parseInt(amount.replace(/[^0-9]/g, ''));
                    amount = Math.round( amount + ( amount * ( ph_valpal.sales_actual_amount_percentage_modifier / 100 ) ) );
                }
                if ( jQuery('#frmValPal select[name=\'type\']').val() == 'lettings' && ph_valpal.lettings_actual_amount_percentage_modifier != 0 )
                {
                    amount = parseInt(amount.replace(/[^0-9]/g, ''));
                    amount = Math.round( amount + ( amount * ( ph_valpal.lettings_actual_amount_percentage_modifier / 100 ) ) );
                }
                amount = '&pound;' + ph_valpal_add_commas(amount);
                jQuery('#valuation_results .actual-amount span').html( amount );
                
                var actual_amount = amount;

                // Process max amount
                var amount = response.maxvaluation.replace("£", "");
                if ( jQuery('#frmValPal select[name=\'type\']').val() == 'sales' && ph_valpal.sales_max_amount_percentage_modifier != 0 )
                {
                    amount = parseInt(amount.replace(/[^0-9]/g, ''));
                    amount = Math.round( amount + ( amount * ( ph_valpal.sales_max_amount_percentage_modifier / 100 ) ) );
                    
                }
                if ( jQuery('#frmValPal select[name=\'type\']').val() == 'lettings' && ph_valpal.lettings_max_amount_percentage_modifier != 0 )
                {
                    amount = parseInt(amount.replace(/[^0-9]/g, ''));
                    amount = Math.round( amount + ( amount * ( ph_valpal.lettings_max_amount_percentage_modifier / 100 ) ) );
                }
                amount = '&pound;' + ph_valpal_add_commas(amount);
                jQuery('#valuation_results .max-amount span').html( amount );
                
                var max_amount = amount;

                var resultsObj = {
                    "min_amount": min_amount,
                    "actual_amount": actual_amount,
                    "max_amount": max_amount
                }

                // Helper function to transform data - removes empty values and renames keys
                const transformData = (obj) => {
                    return Object.fromEntries(
                        Object.entries(obj)
                            .filter(([_, value]) => value !== "" && value !== null && value !== undefined)
                            .map(([key, value]) => {
                                // Rename keys for consistency
                                if (key === "name") key = "fullname";
                                if (key === "property") key = "property_id";
                                return [key, value];
                            })
                    );
                };

                // Build the full_address from ValPal API response data
                const parts = [];

                if (response.subBname) parts.push(response.subBname);

                let numberBlock = '';
                if (response.number) {
                    if (response.depstreet && response.street) {
                        // e.g., "123 Dependent Street"
                        numberBlock = response.number + ' ' + response.depstreet;
                    } else if (!response.depstreet && response.street) {
                        // e.g., "123 Main Street"
                        numberBlock = response.number + ' ' + response.street;
                    } else if (response.depstreet && !response.street) {
                        // e.g., "123 Dependent Street"
                        numberBlock = response.number + ' ' + response.depstreet;
                    } else {
                        // Just the number
                        numberBlock = response.number;
                    }
                }

                if (numberBlock) parts.push(numberBlock);

                // Add street if not already included in numberBlock
                if (response.street && !(response.number && !response.depstreet)) {
                    parts.push(response.street);
                }

                if (response.postcode) parts.push(response.postcode);

                const full_address = parts.join(', ');

                // Collect all form submission data
                const formData = {
                    type: jQuery('#frmValPal select[name=\'type\']').val(),
                    bedrooms: jQuery('#frmValPal select[name=\'bedrooms\']').val(),
                    propertytype: jQuery('#frmValPal select[name=\'propertytype\']').val(),
                    name: jQuery('#frmValPal input[name=\'name\']').val(),
                    email: jQuery('#frmValPal input[name=\'email\']').val(),
                    telephone: jQuery('#frmValPal input[name=\'telephone\']').val(),
                    comments: jQuery('#frmValPal textarea[name=\'comments\']').val(),
                    postcode: jQuery('#frmValPal input[name=\'postcode\']').val()
                };

                // Build comprehensive URL data object with all information
                const url_data = { 
                    ...transformData(formData), 
                    ...transformData(response),
                    utc_date_now: new Date().toISOString(),
                    channel: "Instant Valuation",
                    gid: 1895768378,
                    full_address: full_address,
                    min_amount: min_amount,
                    actual_amount: actual_amount,
                    max_amount: max_amount
                };

                // Google Sheets integration function
                function sendDataToGoogleSheet(payload) {
                    
                    // Ensure timestamp exists
                    if (!payload.utc_date_now) {
                        payload.utc_date_now = new Date().toISOString();
                    }
                    
                    // Add the correct GID for the sheet
                    payload.gid = '1895768378';
                    
                    // Your Google Apps Script Web App URL
                    const scriptUrl = 'https://script.google.com/macros/s/AKfycbwEeHug6T-nY4anhPvIlPFiLPlqtyN6IHygdrTTk1HikB2jKy0u-Pb8iwEMtqxQYIu5/exec';
                    
                    console.log('Sending data to Google Sheets:', payload);
                    
                    // Create form data instead of JSON
                    const formData = new FormData();
                    for (const key in payload) {
                        if (payload.hasOwnProperty(key)) {
                            formData.append(key, payload[key]);
                        }
                    }
                    
                    return fetch(scriptUrl, {
                        method: 'POST',
                        body: formData,
                        mode: 'no-cors' // Required for cross-origin requests
                    })
                    .then(function(response) {
                        // With no-cors, we can't read the response
                        console.log('Data sent to Google Sheets (no-cors mode)');
                        return { success: true };
                    })
                    .catch(function(error) {
                        console.error('Error sending data to Google Sheet:', error);
                        // Don't throw error - continue with redirect even if Google Sheets fails
                        return { success: false, error: error.message };
                    });
                }

                jQuery('#valuation_results .area-info').html( response.areainformation );

                jQuery('#frmValPal').fadeOut('fast', function()
                {
                    jQuery('#valuation_results').fadeIn('fast', function()
                    {
                        // Console logging for debugging
                        console.log('Valuation Results:', resultsObj);
                        console.log('Full Address:', full_address);
                        console.log('Complete URL Data:', url_data);

                        // Build query string with ALL data
                        const queryString = new URLSearchParams(url_data).toString();
                        const redirectUrl = window.location.origin + '/instant-valuation-results/?' + queryString;

                        console.log('Redirecting to:', redirectUrl);

                        // Send to Google Sheets, then redirect
                        sendDataToGoogleSheet(url_data).then(function() {
                            window.location.assign(redirectUrl);
                        }).catch(function() {
                            // Redirect anyway even if Google Sheets fails
                            window.location.assign(redirectUrl);
                        });
                    });
                });
          },
          dataType: 'json'
        });

        return false;
    });

    jQuery('#cancel_find_address').click(function()
    {
        jQuery('#buildname').val('');
        jQuery('#subBname').val('');
        jQuery('#line1').val('');
        jQuery('#line2').val('');
        jQuery('#depstreet').val('');

        jQuery('#address_results_control').fadeOut('fast', function()
        {
            jQuery('#postcode_control').fadeIn();
        });
        return false;
    });

    jQuery('#postcode').keyup(function(e){
        if(e.keyCode == 13)
        {
            e.preventDefault();
            doPostcodeLookup();
        }
    });

    jQuery('#find_address').click(function(e)
    {
        e.preventDefault();
        doPostcodeLookup();
    });
});

function doPostcodeLookup()
{
    jQuery('#find_address').attr('disabled', 'disabled');
    jQuery('#find_address').html('Finding Address...');

    jQuery.ajax({
        type: "POST",
        url: ph_valpal.ajax_url,
        data: {
            'action': 'do_postcode_lookup',
            'postcode': jQuery('#frmValPal input[name=\'postcode\']').val()
        },
        success: function(data)
        {
            jQuery('#address_results').empty();
            jQuery('#address_results').append(jQuery('<option>', {
                value: '',
                text: 'Select address...'
            }));

            if ( typeof data[2] != 'undefined' && typeof data[2].results != 'undefined' && data[2].results.length > 0 )
            {
                for ( var i in data[2].results )
                {
                    jQuery('#address_results').append(jQuery('<option>', {
                        value: data[2].results[i].id,
                        text: data[2].results[i].address,
                    }));
                }

                jQuery('#postcode_control').fadeOut('fast', function()
                {
                    jQuery('#address_results_control').fadeIn();

                    jQuery('#find_address').attr('disabled', false);
                    jQuery('#find_address').html('Find Address');
                });
            }
            else
            {
                jQuery('#find_address').attr('disabled', false);
                jQuery('#find_address').html('Find Address');
            }
        },
        dataType: 'json'
    });
}

function ph_valpal_add_commas(nStr)
{
    nStr += '';
    x = nStr.split('.');
    x1 = x[0];
    x2 = x.length > 1 ? '.' + x[1] : '';
    var rgx = /(\d+)(\d{3})/;
    while (rgx.test(x1)) {
        x1 = x1.replace(rgx, '$1' + ',' + '$2');
    }
    return x1 + x2;
}