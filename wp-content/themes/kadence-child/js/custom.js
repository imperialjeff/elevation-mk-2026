jQuery(document).ready(function( $ ) {
	var total_amount = 800;
	var checked = null;
	var input_laywer = null;
	var total_input = 0;
	var last_input = 0;
	
	//laywers
	$("#input_2_16").change(function() {
		input_laywer = $(this).val();
		last_input = (input_laywer <= 4) ? 0 : input_laywer;
		console.log('input: '+input_laywer);
		console.log('total_amount: '+total_amount);
		if(input_laywer <= 4){
			console.log('log1');
			if(checked) {
				console.log('log1.1');
				display(input_laywer, total_amount);
			}
		} else if(input_laywer == 0 || input_laywer == null) {
			$('#input_2_15').val(0);
		}else {
			console.log('log2');
			total_input = (total_amount - (last_input * 100)) + ((input_laywer - 4) * 100);
			console.log("last_input: "+last_input);
			console.log("Input-4: "+(input_laywer - 4));
			console.log(("Input-4*100: "+(input_laywer - 4) * 100));
			console.log("total_amount: "+total_amount);
			console.log("total_input: "+total_input);
// 			total_amount = total_amount + total_input;
			if(checked) {
				console.log('log2.1');
				$('#input_2_15').val(total_input);
			}
			total_amount = total_input;
		}
	});
	
	// Checkbox
	$(".gfield-choice-input").change(function() {
		console.log('checkbox total_amount: '+total_amount);
		checked = $('#gform_2').find('input[type=checkbox]:checked').length;
		
			if(this.checked) {
				if(checked <= 1){
					//if(input_laywer) { $('#input_2_15').val(total_amount); }
					display(input_laywer, total_amount);
				} else if(checked > 1 && checked <= 4){
					total_amount = total_amount + 400;
					display(input_laywer, total_amount);
				} else if (checked > 4 && checked <= 6){
					total_amount = total_amount + 300;
					display(input_laywer, total_amount);
				} else if (checked > 6){
					total_amount = total_amount + 200;
					display(input_laywer, total_amount);
				}
			} else {
				if(checked == 1){
					console.log('uncheck-1');
					total_amount = total_amount - 400;
					display(input_laywer, total_amount);
				} else if(checked > 1 && checked < 4){
					console.log('uncheck-2');
					total_amount = total_amount - 400;
					display(input_laywer, total_amount);
				} else if (checked >= 4 && checked < 6){
					console.log('uncheck-3');
					total_amount = total_amount - 300;
					display(input_laywer, total_amount);
				} else if (checked >= 6){
					console.log('uncheck-4');
					total_amount = total_amount - 200;
					display(input_laywer, total_amount);  
				} else if (checked == 0) {
					console.log('uncheck-5');
					$('#input_2_15').val(0);
				}
			}
	});
	
	
	function display(input_laywer, total_amount) {
	   if(input_laywer) { 
		   $('#input_2_15').val(total_amount); 
		   console.log('im function');
	   }
	}
	
});