<?php if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly ?>

<?php
	if ( isset($_GET['phpisuccessmessage']) && !empty($_GET['phpisuccessmessage']) )
	{
		$message = $_GET['phpisuccessmessage'];
		$message = base64_decode($message, true);
		if ( $message === false ) 
		{
		    $message = $_GET['phpisuccessmessage'];
		}
		else
		{
			$message = urldecode($message);
		}

		$allowed_html = array(
	        'a' => array(
	            'href' => array()
	        ),
	    );

	    // Allow specific <a> tags through wp_kses
	    $message = wp_kses( $message, $allowed_html );

		echo '<div class="notice notice-success inline"><p>' . $message . '</p></div>';
	}
	if ( isset($_GET['phpierrormessage']) && !empty($_GET['phpierrormessage']) )
	{
		$message = $_GET['phpierrormessage'];
		$message = base64_decode($message, true);
		if ( $message === false ) 
		{
		    $message = urldecode($_GET['phpierrormessage']);
		}
		else
		{
			$message = urldecode($message);
		}
		
		$allowed_html = array(
	        'a' => array(
	            'href' => array()
	        ),
	    );

	    // Allow specific <a> tags through wp_kses
	    $message = wp_kses( $message, $allowed_html );
	    
		echo '<div class="notice notice-error inline"><p>' . $message . '</p></div>';
	}
?>