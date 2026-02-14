function ph_ryc_add_commas(nStr)
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

function ph_ryc_calculate()
{
	var purchase_price = jQuery('.rental-yield-calculator input[name=\'purchase_price\']').val().replace(/,/g, '');
    var monthly_rent = jQuery('.rental-yield-calculator input[name=\'monthly_rent\']').val().replace(/,/g, '');

    if ( purchase_price != '' && monthly_rent != '' && purchase_price != '0' && monthly_rent != '0' )
    {
        var annual_rent = monthly_rent * 12;

        var rental_yield = (annual_rent / purchase_price) * 100;

        jQuery(".rental-yield-calculator #results input[name=\'annual_rent\']").val(ph_ryc_add_commas(annual_rent.toFixed(2)));
        jQuery(".rental-yield-calculator #results input[name=\'rental_yield\']").val(ph_ryc_add_commas(rental_yield.toFixed(2)));
        
        jQuery('.rental-yield-calculator #results').slideDown();
    }
}

jQuery(document).ready(function()
{
	jQuery("body").on('blur', '.rental-yield-calculator input', function() 
	{
		ph_ryc_calculate();
	});
    jQuery("body").on('change', '.rental-yield-calculator input', function() 
    {
        ph_ryc_calculate();
    });
	jQuery("body").on('click', '.rental-yield-calculator button', function() 
	{
		ph_ryc_calculate();
	});
});