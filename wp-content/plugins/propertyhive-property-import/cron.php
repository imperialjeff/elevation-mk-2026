<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( !function_exists('propertyhive_property_import_fatal_handler') ) 
{
	function propertyhive_property_import_fatal_handler() {

	    $error = error_get_last();

	    if ($error !== NULL) 
	    {
	    	if ( ($error['type'] === E_ERROR) || ($error['type'] === E_USER_ERROR)|| ($error['type'] === E_USER_NOTICE) ) 
	    	{
		        $errno   = $error["type"];
		        $errfile = $error["file"];
		        $errline = $error["line"];
		        $errstr  = $error["message"];

				$error_text = propertyhive_property_import_format_error( $errno, $errstr, $errfile, $errline );

				global $wpdb, $instance_id;

				$current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
				$current_date = $current_date->format("Y-m-d H:i:s");

				$instance_id = isset($instance_id) ? $instance_id : null;

				$message_chunks = str_split($error_text, 255);

		        foreach ( $message_chunks as $chunk )
		        {
					$wpdb->insert(
						$wpdb->prefix . "ph_propertyimport_instance_log_v3",
						array(
							'instance_id' => $instance_id,
							'post_id' => 0,
							'crm_id' => '',
							'severity' => 1,
							'entry' => $chunk,
							'log_date' => $current_date
						)
					);
				}
			}
	    }
	}

	register_shutdown_function( "propertyhive_property_import_fatal_handler" );

	// Returns a formatted version of the fatal error, showing the error message and number, filename and line number
	function propertyhive_property_import_format_error( $errno, $errstr, $errfile, $errline ) {
		$trace = print_r( debug_backtrace( false ), true );
		$file_split = explode('/', $errfile);
		$trimmed_filename = implode('/', array_slice($file_split, -2));
		$content = 'Error:' . $errstr . '|' . $errno . '|' . $trimmed_filename . '|' . $errline . '|' . $trace;
		return $content;
	}
}

error_reporting( 0 );
set_time_limit( 0 );

$instance_id = 0;

global $wpdb, $post, $instance_id;

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

// Check Property Hive Plugin is active as we'll need this
if ( is_plugin_active( 'propertyhive/propertyhive.php' ) )
{
	do_action( 'propertyhive_before_property_import_cron' );

	if ( !defined('ALLOW_UNFILTERED_UPLOADS') ) { define( 'ALLOW_UNFILTERED_UPLOADS', true ); }

	$keep_logs_days = (string)apply_filters( 'propertyhive_property_import_keep_logs_days', '7' );

    // Revert back to 7 days if anything other than numbers has been passed
    // This prevent SQL injection and errors
    if ( !preg_match("/^\d+$/", $keep_logs_days) )
    {
        $keep_logs_days = '7';
    }

    // Delete logs older than 7 days
    $wpdb->query( "DELETE FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3 WHERE start_date < DATE_SUB(NOW(), INTERVAL " . $keep_logs_days . " DAY)" );
    $wpdb->query( "DELETE FROM " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 WHERE log_date < DATE_SUB(NOW(), INTERVAL " . $keep_logs_days . " DAY)" );

	do_action('phpropertydownloadimportmediacronhook');

	$import_options = get_option( 'propertyhive_property_import' );
    if ( is_array($import_options) && !empty($import_options) )
    {
	    $wp_upload_dir = wp_upload_dir();
	    $uploads_dir_ok = true;
	    if( $wp_upload_dir['error'] !== FALSE )
	    {
	        echo "Unable to create uploads folder. Please check permissions";
	        $uploads_dir_ok = false;
	    }
	    else
	    {
	        $uploads_dir = $wp_upload_dir['basedir'] . '/ph_import/';

	        if ( ! @file_exists($uploads_dir) )
	        {
	            if ( ! @mkdir($uploads_dir) )
	            {
	                echo "Unable to create directory " . $uploads_dir;
	                $uploads_dir_ok = false;
	            }
	        }
	        else
	        {
	            if ( ! @is_writeable($uploads_dir) )
	            {
	                echo "Directory " . $uploads_dir . " isn't writeable";
	                $uploads_dir_ok = false;
	            }
	        }
	    }

	    if ($uploads_dir_ok)
	    {
	    	// Sort imports into random order
	    	$shuffled_import_array = array();
	    	$import_id_keys = array_keys($import_options);

	    	shuffle($import_id_keys);

	    	foreach ( $import_id_keys as $import_id_key )
	    	{
		    	$shuffled_import_array[$import_id_key] = $import_options[$import_id_key];
	    	}

	    	$import_options = $shuffled_import_array;

	    	foreach ( $import_options as $import_id => $options )
	    	{
		    	$ok_to_run_import = true;

		    	if ( !isset($options['running']) || $options['running'] != 1 )
	            {
	            	continue;
	            }

	            if ( isset($options['deleted']) && $options['deleted'] == 1 )
	            {
	            	continue;
	            }

	            if ( in_array($options['format'], array('rtdf', 'xml_webedge')) )
	            {
	            	continue;
	            }

	            if ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) )
	            {
	            	if ( $import_id != (int)$_GET['import_id'] )
	            	{
	            		continue;
	            	}
	            }

	            if ( !isset($_GET['force']) )
	        	{
	        		// Make sure there's been no activity in the logs for at least 5 minutes for this feed as that indicates there's possible a feed running
		        	$row = $wpdb->get_row( "
		                SELECT 
		                    status_date
		                FROM 
		                    " . $wpdb->prefix . "ph_propertyimport_instance_v3
		                WHERE
		                    import_id = '" . $import_id . "'
		                AND
		                	end_date = '0000-00-00 00:00:00'
		                ORDER BY status_date DESC
		                LIMIT 1
		            ", ARRAY_A);
		            if ( null !== $row )
		            {
		                if ( ( ( time() - strtotime($row['status_date']) ) / 60 ) < 5 )
		                {
		                	$ok_to_run_import = false;

		                	$message = "There has been activity within the past 5 minutes on an unfinished import. To prevent multiple imports running at the same time and possible duplicate properties being created we won't currently allow manual execution. Please try again in a few minutes or check the logs to see the status of the current import.";
		                	
		                	// if we're running it manually
				            if ( isset($_GET['custom_property_import_cron']) )
				            {
				            	echo $message; die();
				            }
				            // if we're running it via CLI
				            if ( defined( 'WP_CLI' ) && WP_CLI )
		                	{
		                		WP_CLI::error( $message );
		                	}

		                	continue;
		                }
		            }
		        }

		        if ( isset($_GET['custom_property_import_cron']) || ( defined( 'WP_CLI' ) && WP_CLI && isset($assoc_args['force']) && $assoc_args['force'] === true ) )
	            {

	            }
	            else
	            {
		            // Work out if we need to send this portal by looking
		            // at the send frequency and the last date sent
		            $last_start_date = '2000-01-01 00:00:00';
		            $row = $wpdb->get_row( "
		                SELECT 
		                    start_date
		                FROM 
		                    " .$wpdb->prefix . "ph_propertyimport_instance_v3
		                WHERE
		                    import_id = '" . $import_id . "'
		                ORDER BY start_date DESC LIMIT 1
		            ", ARRAY_A);
		            if ( null !== $row )
		            {
		                $last_start_date = $row['start_date'];   
		            }

		            $diff_secs = time() - strtotime($last_start_date);

		            switch ($options['import_frequency'])
		            {
		            	case "every_15_minutes":
		            	case "every_fifteen_minutes":
		                {
		                    if (($diff_secs / 60 / 60) < 0.25)
		                    {
		                        $ok_to_run_import = false;
		                    }
		                    break;
		                }
		                case "hourly":
		                {
		                    if (($diff_secs / 60 / 60) < 1)
		                    {
		                        $ok_to_run_import = false;
		                    }
		                    break;
		                }
		                case "twicedaily":
		                {
		                    if (($diff_secs / 60 / 60) < 12)
		                    {
		                        $ok_to_run_import = false;
		                    }
		                    break;
		                }
		                case "daily":
		                {
		                    if (($diff_secs / 60 / 60) < 24)
		                    {
		                        $ok_to_run_import = false;
		                    }
		                    break;
		                }
		                case "exact":
		                case "exact_hours":
		                {
		                	$ok_to_run_import = false;

		                	$exact_hours = false;
		                	
		                	if ( isset($options['exact_hours']) && !empty($options['exact_hours']) )
		                	{
		                		$exact_hours = $options['exact_hours'];
		                	}
		                	elseif ( isset($options['exact_times']) && !empty($options['exact_times']) )
		                	{
		                		$exact_hours = $options['exact_times'];
		                	}

		                	if ( !empty($exact_hours) )
		                	{
		                		if ( !is_array($exact_hours) )
		                		{
			                		$exact_hours = explode(",", $exact_hours);
			                		$exact_hours = array_map('trim', $exact_hours); // remove white spaces from around hours
			                		$exact_hours = array_filter($exact_hours); // remove empty array elements
			                	}
		                		sort($exact_hours, SORT_NUMERIC); 

		                		if ( !empty($exact_hours) )
		                		{
		                			$current_date = current_datetime();
		                			$current_hour = $current_date->format('H');

                                    $last_start_date_to_check = new DateTimeImmutable( $last_start_date, new DateTimeZone('UTC') );
                                    $last_start_date_to_check = $last_start_date_to_check->getTimestamp();

		                			// get timestamp of today at next hour entered
		                			foreach ( $exact_hours as $hour_to_execute )
		                			{
		                				$hour_to_execute = explode(":", $hour_to_execute);
                                        $hour_to_execute = $hour_to_execute[0];

		                				if ( $current_hour >= $hour_to_execute )
                                        {
			                				$hour_to_execute = str_pad($hour_to_execute, 2, '0', STR_PAD_LEFT);

			                				$date_to_check = new DateTimeImmutable( $current_date->format('Y-m-d') . ' ' . $hour_to_execute . ':00:00', wp_timezone() );
                                            $date_to_check = $date_to_check->getTimestamp();

			                				if ( $current_date->getTimestamp() >= $date_to_check && $last_start_date_to_check < $date_to_check )
			                				{
			                					$ok_to_run_import = true;
			                					break;
			                				}
			                			}
		                			}
		                		}
		                	}
		                	
		                    break;
		                }
		                default: 
		                {
		                	$ok_to_run_import = apply_filters( 'propertyhive_property_ok_to_run_import', $ok_to_run_import, $diff_secs );
		                }
		            }
		        }

		        if ( $ok_to_run_import )
            	{
            		// log instance start
		            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
					$current_date = $current_date->format("Y-m-d H:i:s");

		            $wpdb->insert( 
		                $wpdb->prefix . "ph_propertyimport_instance_v3", 
		                array(
		                	'import_id' => $import_id,
		                    'start_date' => $current_date,
		                    'status' => json_encode(array('status' => 'starting')),
		                    'status_date' => $current_date,
		                    'media' => 0
		                )
		            );
		            $instance_id = $wpdb->insert_id;

		            do_action( 'propertyhive_property_import_cron_begin', $instance_id, $import_id );

			    	$format = $options['format'];

			    	$parsed_in_class = false;

			    	list($import_object, $parsed_in_class) = phpi_get_import_object_from_format($format, $instance_id, $import_id);

			    	if ( !$parsed_in_class && isset($import_object) && $import_object !== false && !empty($import_object) )
			    	{
			    		$import_object->ping(array('status' => 'parsing'));
			    		
				    	$parsed = $import_object->parse();

		                if ( $parsed !== false )
		                {
		                    $import_object->import();

		                    if ( 
		                    	$format != 'xml_vebra_api' 
		                    	|| 
		                    	( $format == 'xml_vebra_api' && ( !isset($options['only_updated']) || ( isset($options['only_updated']) && $options['only_updated'] == '' ) ) )
		                    )
		                    {
				                $import_object->remove_old_properties();
				            }
		                }

		                unset($import_object);
		            }

		            do_action( 'propertyhive_property_import_cron', $options, $instance_id, $import_id );

		            // log instance end
			    	$current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
					$current_date = $current_date->format("Y-m-d H:i:s");

			    	$wpdb->update( 
			            $wpdb->prefix . "ph_propertyimport_instance_v3", 
			            array( 
			                'end_date' => $current_date,
			                'status' => json_encode(array('status' => 'finished')),
		                	'status_date' => $current_date
			            ),
			            array( 'id' => $instance_id )
			        );

			        delete_transient("ph_featured_properties");

			        do_action( 'propertyhive_property_import_cron_end', $instance_id, $import_id );
            	}

	        } // end foreach import
	    }
	}

	do_action( 'propertyhive_after_property_import_cron' );

	do_action('phpropertydownloadimportmediacronhook');
}