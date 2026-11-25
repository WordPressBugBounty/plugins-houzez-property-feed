<?php

include('../../../wp-load.php');

if ( !current_user_can( 'manage_options' ) )
{
    wp_die(
        'You do not have permission to download import files',
        '',
        array( 'response' => 403 )
    );
}

global $wpdb;

if ( !isset($_GET['import_id']) || ( isset($_GET['import_id']) && empty($_GET['import_id']) ) )
{
	wp_die(
	    'No import ID passed',
	    '',
	    array( 'response' => 400 )
	);
}

if ( !isset($_GET['file']) || ( isset($_GET['file']) && empty($_GET['file']) ) )
{
	wp_die(
	    'No file passed',
	    '',
	    array( 'response' => 400 )
	);
}

$import_settings = houzez_property_feed_get_import_settings_from_id( (int)$_GET['import_id'] );

if ( $import_settings === false )
{
	wp_die(
	    'Import passed doesn\'t exist',
	    '',
	    array( 'response' => 404 )
	);
}

switch ( $import_settings['format'] )
{
	case "blm_local":
	case "openimmo_local":
	case "reaxml_local":
	case "rentman":
	{
		$file_name = base64_decode(sanitize_text_field($_GET['file']));

		// Prevent directory traversal
		$file_name = basename($file_name);

		// Construct the absolute file path
		$allowed_dir = realpath($import_settings['local_directory']);
		$file_path = realpath($allowed_dir . '/' . $file_name);

		// Ensure the file is within the allowed directory
		if ( strpos($file_path, $allowed_dir) !== 0 || !file_exists($file_path) ) 
		{
		    wp_die(
			    'Invalid file path',
			    '',
			    array( 'response' => 404 )
			);
		}

		header('Content-Disposition: attachment; filename="' . basename($file_name) . '"');
		header('Content-Length: ' . filesize($file_path));
		readfile($file_path);
    	exit;
	}
	default:
	{
		wp_die(
		    'Unknown format: ' . esc_html($import_settings['format']),
		    '',
		    array( 'response' => 400 )
		);
	}
}