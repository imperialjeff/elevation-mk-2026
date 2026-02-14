<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

error_reporting( 0 );
set_time_limit( 0 );
ini_set('memory_limit','20000M');

global $wpdb, $post;

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );

// Check Property Hive Plugin is active as we'll need this
if( is_plugin_active( 'propertyhive/propertyhive.php' ) )
{
	if ( !defined('ALLOW_UNFILTERED_UPLOADS') ) { define( 'ALLOW_UNFILTERED_UPLOADS', true ); }
	
	$wpdb->query( "DELETE FROM " . $wpdb->prefix . "ph_propertyimport_media_queue WHERE date_queued < DATE_SUB(NOW(), INTERVAL 30 DAY)" );

	$wp_upload_dir = wp_upload_dir();
	$ok_to_run_import = true;
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
				$ok_to_run_import = false;
			}
		}
		else
		{
			if ( ! @is_writeable($uploads_dir) )
			{
				echo "Directory " . $uploads_dir . " isn't writeable";
				$ok_to_run_import = false;
			}
		}
	}

	$imports_with_media_to_import = $wpdb->get_results(
		"
		SELECT
			DISTINCT `import_id`
		FROM
			" . $wpdb->prefix . "ph_propertyimport_media_queue
		"
	);
	if (count($imports_with_media_to_import) == 0) 
	{
		$ok_to_run_import = false;
	}

	$media_processing = get_option( 'propertyhive_property_import_media_processing', '' );
	if ( $media_processing !== 'background' )
	{
		$ok_to_run_import = false;
	}

	if ( $ok_to_run_import )
	{
		// Make sure there's been no activity in the logs for at least 10 minutes for the media download process as that indicates it may already be running
		$row = $wpdb->get_row( "
			SELECT
				status_date
			FROM
				" . $wpdb->prefix . "ph_propertyimport_instance_v3
			WHERE
				end_date = '0000-00-00 00:00:00'
			ORDER BY status_date DESC
			LIMIT 1
			", ARRAY_A);
		if ( null !== $row )
		{
			if ( ( ( time() - strtotime($row['status_date']) ) / 60 ) < 10 )
			{
				$ok_to_run_import = false;

				$message = "There has been activity on the media download queue in the past 10 minutes. To prevent possible duplicate errors, we won't currently allow manual execution. Please try again in a few minutes or check the logs to see the status of the current process.";

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
			}
		}
	}

	if ( $ok_to_run_import )
	{
		$imports_with_media_to_import = $wpdb->get_results(
			"
			SELECT
				DISTINCT `import_id`
			FROM
				" . $wpdb->prefix . "ph_propertyimport_media_queue
			"
		);
		if (count($imports_with_media_to_import) > 0) 
		{
			foreach( $imports_with_media_to_import as $import_row ) 
			{
				$media_to_import = $wpdb->get_results(
					"
					SELECT
						GROUP_CONCAT(`id`) as `ids`,
						`import_id`,
						`property_id`,
						`media_type`,
						`media_order`,
						SUBSTRING_INDEX(GROUP_CONCAT(`media_location` ORDER BY `media_modified` DESC SEPARATOR '~'), '~', 1 ) as `media_location`,
						SUBSTRING_INDEX(GROUP_CONCAT(`media_description` ORDER BY `media_modified` DESC SEPARATOR '~'), '~', 1 ) as `media_description`,
						SUBSTRING_INDEX(GROUP_CONCAT(`media_compare_url` ORDER BY `media_modified` DESC SEPARATOR '~'), '~', 1 ) as `media_compare_url`,
						MAX(`media_modified`) as `media_modified`,
						MAX(`attachment_id`) as `attachment_id`
					FROM
						" . $wpdb->prefix . "ph_propertyimport_media_queue
					WHERE
					 	`import_id` = '" . (int)$import_row->import_id . "'
					GROUP BY
						property_id,
						media_type,
						media_order
					ORDER BY
						property_id,
						media_type,
						media_order
					"
				);
				if (count($media_to_import) > 0) {

					if ( !function_exists('media_handle_upload') ) 
					{
						require_once(ABSPATH . "wp-admin" . '/includes/image.php');
						require_once(ABSPATH . "wp-admin" . '/includes/file.php');
						require_once(ABSPATH . "wp-admin" . '/includes/media.php');
					}

					$current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
					$current_date = $current_date->format("Y-m-d H:i:s");
		
					// log instance start
					$wpdb->insert(
						$wpdb->prefix . "ph_propertyimport_instance_v3",
						array(
							'import_id' => $import_row->import_id,
							'start_date' => $current_date,
							'media' => 1
						)
					);
					$instance_id = $wpdb->insert_id;

					$uploadedCount = 0;
					$previous_property_id = 0;
					$previous_media_type  = '';
					$files_to_unlink = array();
					foreach ( $media_to_import as $media_row ) 
					{
						// We've moved onto a different property or media type, or are starting the loop for the first time
						if ( $media_row->property_id !== $previous_property_id || $media_row->media_type !== $previous_media_type ) 
						{
							if ( !empty($files_to_unlink) )
							{
								foreach ( $files_to_unlink as $file_to_unlink )
								{
									unlink($file_to_unlink);
								}
								$files_to_unlink = array();
							}

							// If this isn't our first time through the loop
							if ( $previous_property_id !== 0 ) 
							{
								// And at least one item of media was successfully uploaded
								if ( $uploadedCount > 0 )
								{
									// Log how many media items were uploaded
									phpi_media_cron_add_log( $instance_id, 0, 'Uploaded ' . $uploadedCount . ' queued ' . $previous_media_type, (int)$previous_property_id);

									do_action( 'propertyhive_property_import_queued_media_imported', (int)$previous_property_id, $previous_media_type );

									$uploadedCount = 0;

									$query = "
										DELETE FROM
											" . $wpdb->prefix . "ph_propertyimport_media_queue
										WHERE
								 			`import_id` = '" . (int)$import_row->import_id . "'
								 		AND
								 			`processed` = 1
								 		AND
								 			post_id = '" . (int)$previous_property_id . "'
								 		AND
								 			media_type = '" . esc_sql($previous_media_type) . "'
									";

									$wpdb->query($query);
								}
							}

							// Get the post_ids of the media for the new property
							$media_ids = get_post_meta( $media_row->property_id, '_' . $media_row->media_type, TRUE );
							if ( !is_array($media_ids) ) {
								$media_ids = array();
							}
						}

						// Check we haven't imported this attachment before
						if ( !empty($media_row->attachment_id) )
						{
							// WE HAVE IMPORTED ALREADY
							array_splice( $media_ids, $media_row->media_order, 0, $media_row->attachment_id );

							++$uploadedCount;
						}
						else
						{
							$media_post_id = 0;
							$file_data = @unserialize($media_row->media_location);
							if ($file_data !== false) {
								// File is a serialized array of physical file data
								$upload = wp_upload_bits($file_data['name'], null, file_get_contents($file_data['path']));

								if( isset($upload['error']) && $upload['error'] !== FALSE ) {
									phpi_media_cron_add_log( $instance_id, 1, print_r($upload['error'], TRUE), (string)$media_row->property_id );
								} else {
									// We don't already have a thumbnail and we're presented with an image
									$wp_filetype = wp_check_filetype( $upload['file'], null );

									$attachment = array(
										'post_mime_type' => $wp_filetype['type'],
										'post_title' => $media_row->media_description,
										'post_content' => '',
										'post_status' => 'inherit'
									);
									$media_post_id = wp_insert_attachment( $attachment, $upload['file'], $media_row->property_id );

									if ( $media_post_id === FALSE || $media_post_id == 0 ) {
										phpi_media_cron_add_log( $instance_id, 1, 'Failed inserting brochure attachment ' . $upload['file'] . ' - ' . print_r($attachment, TRUE), (string)$media_row->property_id );
									} else {
										$attach_data = wp_generate_attachment_metadata( $media_post_id, $upload['file'] );
										wp_update_attachment_metadata( $media_post_id,  $attach_data );

										update_post_meta( $media_post_id, '_imported_path', $upload['file']);

										array_splice( $media_ids, $media_row->media_order, 0, $media_post_id );
										update_post_meta( $media_row->property_id, '_' . $media_row->media_type, $media_ids );
										++$uploadedCount;
									}
								}
								$files_to_unlink[] = $file_data['path'];
							} else {
								// File is a remote url
								$tmp = download_url( $media_row->media_location );
								$fileBasename = basename( $media_row->media_location );
								$fileBasename = explode("?", $fileBasename);
								$fileBasename = $fileBasename[0];
								if ( strpos($fileBasename, '.') === FALSE )
								{
									// No extension. Let's put one on as a worst case scenario
									$fileBasename .= $media_row->media_type == 'brochures' ? '.pdf' : '.jpg';
								}
								if ( strpos($fileBasename, '.php') !== FALSE || strpos($fileBasename, '.asp') !== FALSE )
								{
									// This is a file with a PHP/ASP extension so WP will complain. Give it a better name (i.e. from Reapit Foundations)
									$fileBasename = trim($media_row->media_type, 's') . '-' . sanitize_file_name( $media_row->property_id ) . ($media_row->media_type == 'brochures' ? '.pdf' : '.jpg');
								}
								$file_array = array(
									'name' => $fileBasename,
									'tmp_name' => $tmp
								);

								// Check for download errors
								if ( is_wp_error( $tmp ) )
								{
									phpi_media_cron_add_log( $instance_id, 1, 'Error whilst downloading queued media ' . $media_row->media_location . ': ' . $tmp->get_error_message(), (string)$media_row->property_id );
								}
								else
								{
									$media_post_id = media_handle_sideload( $file_array, $media_row->property_id, $media_row->media_description, array('post_title' => $fileBasename, 'post_excerpt' => $media_row->media_description) );

									// Check for handle sideload errors.
									if ( is_wp_error( $media_post_id ) )
									{
										@unlink( $file_array['tmp_name'] );

										phpi_media_cron_add_log( $instance_id, 1, 'Error whilst sideloading downloaded queued media ' . $media_row->media_location . ': ' . $media_post_id->get_error_message(), (string)$media_row->property_id );

										$wpdb->query("
										    DELETE FROM
										        " . $wpdb->prefix . "ph_propertyimport_media_queue
										    WHERE
										        `id` IN (" . $media_row->ids . ")
										");
									}
									else
									{
										update_post_meta( $media_post_id, '_imported_url', $media_row->media_compare_url );
										if ($media_row->media_modified !== '0000-00-00 00:00:00') {
											update_post_meta( $media_post_id, '_modified', $media_row->media_modified );
										}

										array_splice( $media_ids, $media_row->media_order, 0, $media_post_id );
										update_post_meta( $media_row->property_id, '_' . $media_row->media_type, $media_ids );
										++$uploadedCount;
									}
								}
							}

							if ( !is_wp_error( $media_post_id ) )
							{
								$wpdb->query("
								    UPDATE
								        " . $wpdb->prefix . "ph_propertyimport_media_queue
								    SET
								        `processed` = 1,
								        `attachment_id` = '" . $media_post_id . "'
								    WHERE
								        `id` IN (" . $media_row->ids . ")
								");
							}
						}

						$previous_property_id = $media_row->property_id;
						$previous_media_type  = $media_row->media_type;
					}

					if ( $uploadedCount > 0 ) 
					{
						// Log how many media items were uploaded for the final row
						phpi_media_cron_add_log( $instance_id, 0, 'Uploaded ' . $uploadedCount . ' queued ' . $previous_media_type, (string)$previous_property_id);

						do_action( 'propertyhive_property_import_queued_media_imported', (string)$previous_property_id, $previous_media_type );

						if ( !empty($files_to_unlink) )
						{
							foreach ( $files_to_unlink as $file_to_unlink )
							{
								unlink($file_to_unlink);
							}
						}
					}

					$current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
					$current_date = $current_date->format("Y-m-d H:i:s");
					
					// log instance end
					$wpdb->update(
						$wpdb->prefix . "ph_propertyimport_instance_v3",
						array(
							'end_date' => $current_date
						),
						array( 'id' => $instance_id )
					);

					$wpdb->query("
						DELETE FROM
							" . $wpdb->prefix . "ph_propertyimport_media_queue
						WHERE
				 			`import_id` = '" . (int)$import_row->import_id . "'
				 		AND
				 			`processed` = 1
					");
				}
			}
		}
	}
}