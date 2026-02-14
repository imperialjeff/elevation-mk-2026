<?php

include('../../../wp-load.php');

global $wpdb;

if ( !isset($_GET['import_id']) || ( isset($_GET['import_id']) && empty($_GET['import_id']) ) )
{
	die("No import ID passed");
}

if ( !isset($_GET['file']) || ( isset($_GET['file']) && empty($_GET['file']) ) )
{
	die("No file passed");
}

if ( !current_user_can('manage_propertyhive') )
{
	wp_die( esc_html("Invalid permissions"), 403 );
}

$options = get_option( 'propertyhive_property_import' );
if (isset($options[$_GET['import_id']]))
{
	$options = $options[$_GET['import_id']];
}
else
{
	die("Import passed doesn't exist");
}

switch ( $options['format'] )
{
	case "blm_local":
	case "xml_estatesit":
	case "xml_decorus":
	case "reaxml_local":
	case "xml_rentman":
	case "xml_caldes":
	{
		$file_name = base64_decode(sanitize_text_field($_GET['file']));

		// Prevent directory traversal
		$file_name = basename($file_name);

		// Construct the absolute file path
		$allowed_dir = realpath($options['local_directory']);
		$file_path = realpath($allowed_dir . '/' . $file_name);

		// Ensure the file is within the allowed directory
		if ( strpos($file_path, $allowed_dir) !== 0 || !file_exists($file_path) ) 
		{
		    die("Invalid file path.");
		}

		header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
		header('Content-Length: ' . filesize($file_path));
		readfile($file_path);
    	exit;
	}
	default:
	{
		die('Unknown format: ' . $options['format']);
	}
}