<?php
/**
 * Class for managing the import process of a BLM file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_BLM_Import extends PH_Property_Import_Process {

	/**
	 * @var string
	 */
	private $eof = '';

	/**
	 * @var string
	 */
	private $eor = '';

	/**
	 * @var array
	 */
	private $definitions;

	public function __construct( $instance_id = '', $import_id = '' ) 
	{
		parent::__construct();
		
		$this->instance_id = $instance_id;
		$this->import_id = $import_id;

		if ( $this->instance_id != '' && isset($_GET['custom_property_import_cron']) )
	    {
	    	$current_user = wp_get_current_user();

	    	$this->log("Executed manually by " . ( ( isset($current_user->display_name) ) ? $current_user->display_name : '' ) );
	    }
	}

	public function parse()
	{
		$this->properties = array(); // Reset properties in the event we're importing multiple files

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		if ( $import_settings['format'] == 'blm_remote' )
		{
			$wp_upload_dir = wp_upload_dir();
		    $uploads_dir_ok = true;
		    if ( $wp_upload_dir['error'] !== FALSE )
		    {
		        $this->log_error("Unable to create uploads folder. Please check permissions");
		        return false;
		    }

	        $local_directory = $wp_upload_dir['basedir'] . '/ph_import/' . $import_id . '/';

			$blm_file = $local_directory . 'blm_properties.blm';

			$contents = '';
			$unzipped_file = '';

			$this->log_error( 'Retrieving URL contents' );

			$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 120 ) );

			if ( !is_wp_error($response) && is_array( $response ) ) 
			{
				if ( ! @file_exists($local_directory) )
				{
					if ( ! @mkdir($local_directory) )
					{
						 $this->log_error("Unable to create directory " . $local_directory);
						 return false;
					}
				}
				else
				{
					if ( ! @is_writeable($local_directory) )
					{
						 $this->log_error("Directory " . $local_directory . " isn't writeable");
						 return false;
					}
				}
				
				// Remote file is a zip file
				if ( 
					wp_remote_retrieve_header( $response, 'content-type' ) == 'application/zip' || 
					wp_remote_retrieve_header( $response, 'content-type' ) == 'application/x-zip-compressed'
				)
				{
					$zip_file = $local_directory . 'blm_properties.zip';

					$handle = @fopen($zip_file, 'w+');
					if ($handle)
					{
						$zip_contents = $response['body'];

						// Write the remote zip file to a local zip file
						fwrite($handle, $zip_contents);
						fclose($handle);

						if ( !class_exists('ZipArchive') ) 
						{ 
							$this->log_error('The ZipArchive class does not exist but is needed to extract the zip files provided'); 
							return false;
						}

						$this->log_error( 'Extracting ZIP file contents' );

						// Unzip local zip file, then remove it
						$zip = new ZipArchive;
						if ($zip->open($zip_file) === TRUE)
						{
							$zip->extractTo($local_directory);
							$zip->close();
						}

						unlink($zip_file);

						// Loop through files to find the BLM and save the contents to $contents
						// If any media files are in the zip, they will get saved to the local directory
						foreach (scandir($local_directory) as $unzipped_file)
						{
							if ( $unzipped_file != "." && $unzipped_file != ".." )
							{
								if ( substr(strtolower($unzipped_file), -3) == 'blm' )
								{
									$contents = file_get_contents($local_directory . $unzipped_file);
									break;
								}
							}
						}

						if ( $contents == '' )
						{
							$this->log_error( 'No BLM file found in target ZIP' );
							return false;
						}

						foreach (scandir( $local_directory ) as $file)
						{
							if ( substr(strtolower($file), -3) == 'blm' )
							{
								unlink($local_directory . $file);
							}
						}
					}
					else
					{
						$this->log_error( "Failed to write ZIP file locally. Please check file permissions" );
						return false;
					}
				}
				else
				{
					$contents = $response['body'];
				}
			}
    		else
    		{
    			$this->log_error("Failed to obtain URL contents. Dump of response as follows: " . print_r($response, TRUE));
    			return false;
    		}

    		$this->log_error( 'Parsing BLM' );

			$parsed_header = $this->parse_header($contents);

	        if ( !$parsed_header ) return false;

	        $parsed_definitions = $this->parse_definitions($contents);

	        if ( !$parsed_definitions ) return false;

	        $parsed_data = $this->parse_data($contents);

	        if ( !$parsed_data ) return false;
		}

		if ( empty($this->properties) )
		{
			$this->log_error( 'No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.' );

			return false;
		}
	}

	public function parse_and_import()
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		if ( $import_settings['format'] == 'blm_local' )
		{
			$local_directory = $import_settings['local_directory'];

			// Get all zip files in date order
			$zip_files = array();
			if ($handle = opendir($local_directory)) 
			{
			    while (false !== ($file = readdir($handle))) 
			    {
			        if (
			        	$file != "." && $file != ".." && 
			        	substr(strtolower($file), -3) == 'zip'
			        ) 
			        {
			           $zip_files[filemtime($local_directory . '/' . $file)] = $local_directory . '/' . $file;
			        }
			    }
			    closedir($handle);
			}
			else
			{
				$this->log_error( 'Failed to read from directory ' . $local_directory . '. Please ensure the local directory specified exists, is the full server path and is readable.' );
				return false;
			}

			if (!empty($zip_files))
			{
				$this->log('Found ' . count($zip_files) . ' ZIPs ready to extract'); 

				if ( !class_exists('ZipArchive') ) 
				{ 
					$this->log_error('The ZipArchive class does not exist but is needed to extract the zip files provided'); 
					return false; 
				}

				ksort($zip_files);

				foreach ($zip_files as $mtime => $zip_file)
				{
					$zip = new ZipArchive;
					if ($zip->open($zip_file) === TRUE) 
					{
					    $zip->extractTo($local_directory);
					    $zip->close();
					    sleep(1); // We sleep to ensure each BLM has a different modified time in the same order

					    $this->log('Extracted ZIP ' . $zip_file); 
					}
					else
					{
						$this->log_error('Failed to open the ZIP ' . $zip_file); 
						return false; 
					}
					unlink($zip_file);
				}
			}

			unset($zip_files);

			// Now they've all been extracted, get BLM files in date order
			$blm_files = array();
			if ($handle = opendir($local_directory)) 
			{
			    while (false !== ($file = readdir($handle))) 
			    {
			        if (
			        	$file != "." && $file != ".." && 
			        	substr(strtolower($file), -3) == 'blm'
			        ) 
			        {
			           $blm_files[filemtime($local_directory . '/' . $file)] = $local_directory . '/' . $file;
			        }
			    }
			    closedir($handle);
			}

			if (!empty($blm_files))
			{
				ksort($blm_files); // sort by date modified

				// We've got at least one BLM to process

                foreach ($blm_files as $mtime => $blm_file)
                {
                	$this->properties = array();
                	$this->branch_ids_processed = array();

                	$this->log("Parsing properties");

                	$parsed = false;

                	// Get BLM contents into memory
                	if ( file_exists($blm_file) && filesize($blm_file) > 0 ) 
                	{
						$handle = fopen($blm_file, "r");
				        $blm_contents = fread($handle, filesize($blm_file));
				        fclose($handle);

				        $parsed_header = $this->parse_header($blm_contents);

				        if ( !$parsed_header ) return false;

				        $parsed_definitions = $this->parse_definitions($blm_contents);

				        if ( !$parsed_definitions ) return false;

				        $parsed_data = $this->parse_data($blm_contents);

				        if ( !$parsed_data ) return false;

	                	// Parsed it succesfully. Ok to continue
	                	if ( empty($this->properties) )
						{
							$this->log_error( 'No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.' );
						}
						else
						{
		                    $this->import();

		                    $this->remove_old_properties();
		                }
		            }
		            else
		            {
		            	$this->log_error( 'File doesn\'t exist or is empty' );
		            }

	                $this->archive( $blm_file );
                }
			}
			else
			{
				$this->log_error( 'No BLM\'s found to process' );
			}

			$this->clean_up_old_blms();
		}

		if ( $import_settings['format'] == 'blm_remote' )
		{
			$this->log( 'This is a BLM remote file' );
		}

		return true;
	}

	private function parse_header( $blm_contents )
	{
		if ( strpos($blm_contents, '#HEADER#') !== FALSE )
		{
			$header = trim(substr($blm_contents, strpos($blm_contents, '#HEADER#')+8, strpos($blm_contents, '#DEFINITION#')-8));
	        $header_data = explode("\n", $header);

	        foreach ( $header_data as $header_row ) 
	        {
	            // get end of field character
	            if ( strpos($header_row, "EOF") !== FALSE ) 
	            {
	                $replace_array = array("EOF", " ", ":", "'", "\n", "\r");
	                $this->eof = str_replace($replace_array, "", $header_row);
	            }

	            // get end of record character
	            if ( strpos($header_row, "EOR") !== FALSE ) 
	            {
	                $replace_array = array("EOR", " ", ":", "'", "\n", "\r");
	                $this->eor = str_replace($replace_array, "", $header_row);
	            }
	        }

	        if ( $this->eof == '' )
		    {
		    	$this->log_error( 'The #HEADER# section does not specify an EOF character' );
		    	return false;
		    }
		    if ( $this->eor == '' )
		    {
		    	$this->log_error( 'The #HEADER# section does not specify an EOR character' );
		    	return false;
		    }
	    }
	    else
	    {
	    	$this->log_error( 'The uploaded BLM file is missing a #HEADER# section' );
	    	return false;
	    }

	    return true;
	}

	private function parse_definitions( $blm_contents )
	{
		if ( strpos($blm_contents, '#DEFINITION#') !== FALSE )
		{
			$definition_length = strpos($blm_contents, $this->eor, strpos($blm_contents,'#DEFINITION#'))-strpos($blm_contents,'#DEFINITION#')-12;
	        $definition = trim( substr($blm_contents, strpos($blm_contents, '#DEFINITION#') + 12, $definition_length) );
	        $definitions = explode($this->eof, $definition);
	        
	        array_pop($definitions); // remove last blank definition field

	        $this->definitions = $definitions;
	    }
	    else
	    {
	    	$this->log_error( 'The uploaded BLM file is missing a #DEFINITION# section' );

	    	return false;
	    }

	    return true;
	}

	private function parse_data( $blm_contents )
	{
		if ( strpos($blm_contents, '#DATA#') !== FALSE && strpos($blm_contents, '#END#') !== FALSE )
		{
			$limit = $this->get_property_limit();

			$data_length = strpos($blm_contents, '#END#')-strpos($blm_contents, '#DATA#')-6;
	        $data = trim(substr($blm_contents, strpos($blm_contents, '#DATA#')+6, $data_length)); 
	        $data = explode($this->eor, $data);

	        // Loop through properties 
	        $i = 1;
	        foreach ($data as $property) 
	        {
	        	if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }
                
	            $property = trim($property); // Remove any new lines from beginning of property row

	            if ( $property != '' )
	            {
		            $field_values = explode($this->eof, $property);
		                            
		            array_pop($field_values); // Remove last blank data field

		            if (count($this->definitions) == count($field_values)) 
		            {
		            	// If the correct number of fields expected
		                                
		                $property = array();
		            
		                // Loop through property fields
		                foreach ($field_values as $field_number=>$field) 
		                {
		                    // Standard fields
		                    $property[$this->definitions[$field_number]] = utf8_encode($field); // set by default to value in .blm
		                
		                } // Finish looping through property fields 

		                $this->properties[] = $property;
		            }
		            else
		            {
		            	// Invalid number of fields
		            	$this->log_error( 'Property on row ' . $i . ' contains an invalid number of fields. Received: ' . count($field_values) . ', Expected: ' . count($this->definitions) );

		            	return false;
		            }
		        }

	            ++$i;
	        }
	    }
	    else
	    {
	    	$this->log_error( 'The uploaded BLM file is missing a #DATA# and/or #END# section' );

	    	return false;
	    }

	    return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$commercial_active = false;
		if ( get_option( 'propertyhive_active_departments_commercial', '' ) == 'yes' )
		{
			$commercial_active = true;
		}

		$this->import_start();

		$local_directory = $import_settings['local_directory'];

        $geocoding_denied = apply_filters( 'propertyhive_blm_import_prevent_geocoding', false, $this->import_id );

        do_action( "propertyhive_pre_import_properties_blm", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_blm_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
			do_action( "propertyhive_property_importing_blm", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['AGENT_REF'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['AGENT_REF'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['AGENT_REF'], false );

			$this->log( 'Importing property ' . $property_row, 0, $property['AGENT_REF'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['AGENT_REF'], $property, $property['DISPLAY_ADDRESS'], $property['SUMMARY'], '', ( isset($property['CREATE_DATE']) && !empty($property['CREATE_DATE']) ) ? date( 'Y-m-d H:i:s', strtotime( $property['CREATE_DATE'] )) : '' );

			if ( $inserted_updated !== false )
			{
				// Inserted property ok. Continue

				if ( $inserted_updated == 'updated' )
				{
					// Get all meta data so we can compare before and after to see what's changed
					$metadata_before = get_metadata('post', $post_id, '', true);

					// Get all taxonomy/term data
					$taxonomy_terms_before = array();
					$taxonomy_names = get_post_taxonomies( $post_id );
					foreach ( $taxonomy_names as $taxonomy_name )
					{
						$taxonomy_terms_before[$taxonomy_name] = wp_get_post_terms( $post_id, $taxonomy_name, array('fields' => 'ids') );
					}
				}

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['AGENT_REF'] );

				update_post_meta( $post_id, '_property_import_data', print_r($property, true) );

				$previous_blm_update_date = get_post_meta( $post_id, '_blm_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if (
					isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes'
				)
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['UPDATE_DATE']) ||
						(
							isset($property['UPDATE_DATE']) &&
							trim($property['UPDATE_DATE']) == ''
						) ||
						$previous_blm_update_date == '' ||
						(
							isset($property['UPDATE_DATE']) &&
							$property['UPDATE_DATE'] != '' &&
							$previous_blm_update_date != '' &&
							strtotime($property['UPDATE_DATE']) > strtotime($previous_blm_update_date)
						)
					)
					{
						$skip_property = false;
					}
				}
				else
				{
					$skip_property = false;
				}

				$country = ( ( isset($property['COUNTRY_CODE']) && $property['COUNTRY_CODE'] != '' ) ? $property['COUNTRY_CODE'] : get_option( 'propertyhive_default_country', 'GB' ) );

				if ( isset($property['LATITUDE']) && isset($property['LONGITUDE']) && $property['LATITUDE'] != '' && $property['LONGITUDE'] != '' && $property['LATITUDE'] != '0' && $property['LONGITUDE'] != '0' )
				{
					update_post_meta( $post_id, '_latitude', $property['LATITUDE'] );
					update_post_meta( $post_id, '_longitude', $property['LONGITUDE'] );
				}
				elseif ( isset($property['EXACT_LATITUDE']) && isset($property['EXACT_LONGITUDE']) && $property['EXACT_LATITUDE'] != '' && $property['EXACT_LONGITUDE'] != '' && $property['EXACT_LATITUDE'] != '0' && $property['EXACT_LONGITUDE'] != '0' )
				{
					update_post_meta( $post_id, '_latitude', $property['EXACT_LATITUDE'] );
					update_post_meta( $post_id, '_longitude', $property['EXACT_LONGITUDE'] );
				}
				else
				{
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( !$geocoding_denied && ($lat == '' || $lng == '' || $lat == '0' || $lng == '0') )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array();
						$address_to_geocode_osm = array();
						if ( isset($property['OS_TOWN_CITY']) && isset($property['OS_REGION']) )
						{
							if ( isset($property['HOUSE_NAME_NUMBER']) && trim($property['HOUSE_NAME_NUMBER']) != '' ) { $address_to_geocode[] = $property['HOUSE_NAME_NUMBER']; }
							if ( isset($property['STREET_NAME']) && trim($property['STREET_NAME']) != '' ) { $address_to_geocode[] = $property['STREET_NAME']; }
							if ( isset($property['OS_TOWN_CITY']) && trim($property['OS_TOWN_CITY']) != '' ) { $address_to_geocode[] = $property['OS_TOWN_CITY']; }
							if ( isset($property['OS_REGION']) && trim($property['OS_REGION']) != '' ) { $address_to_geocode[] = $property['OS_REGION']; }
							if ( isset($property['ZIPCODE']) && trim($property['ZIPCODE']) != '' ) { $address_to_geocode[] = $property['ZIPCODE']; }
						}
						else
						{
							if ( isset($property['ADDRESS_1']) && trim($property['ADDRESS_1']) != '' ) { $address_to_geocode[] = $property['ADDRESS_1']; }
							if ( isset($property['ADDRESS_2']) && trim($property['ADDRESS_2']) != '' ) { $address_to_geocode[] = $property['ADDRESS_2']; }
							if ( isset($property['ADDRESS_3']) && trim($property['ADDRESS_3']) != '' ) { $address_to_geocode[] = $property['ADDRESS_3']; }
							if ( isset($property['TOWN']) && trim($property['TOWN']) != '' ) { $address_to_geocode[] = $property['TOWN']; }
							if ( isset($property['ADDRESS_4']) && trim($property['ADDRESS_4']) != '' ) { $address_to_geocode[] = $property['ADDRESS_4']; }
							if ( isset($property['POSTCODE1']) && isset($property['POSTCODE2']) ) { $address_to_geocode[] = trim($property['POSTCODE1'] . ' ' . $property['POSTCODE2']); $address_to_geocode_osm[] = trim($property['POSTCODE1'] . ' ' . $property['POSTCODE2']); }
						}

						$address_to_geocode = apply_filters( 'propertyhive_blm_import_address_to_geocode', $address_to_geocode, $property );

						$geocoding_return = $this->do_geocoding_lookup( $post_id, $property['AGENT_REF'], $address_to_geocode, $address_to_geocode_osm, $country );
						if ( $geocoding_return === 'denied' )
						{
							$geocoding_denied = true;
						}
					}
				}

				if ( !$skip_property )
				{
					update_post_meta( $post_id, $imported_ref_key, $property['AGENT_REF'] );

					// Address
					update_post_meta( $post_id, '_reference_number', $property['AGENT_REF'] );

					if ( isset($property['OS_TOWN_CITY']) && isset($property['OS_REGION']) )
					{
						// This is an overseas feed
						update_post_meta( $post_id, '_address_name_number', ( ( isset($property['HOUSE_NAME_NUMBER']) ) ? $property['HOUSE_NAME_NUMBER'] : '' ) );
						update_post_meta( $post_id, '_address_street', ( ( isset($property['STREET_NAME']) ) ? $property['STREET_NAME'] : '' ) );
						update_post_meta( $post_id, '_address_two', '' );
						update_post_meta( $post_id, '_address_three', ( ( isset($property['OS_TOWN_CITY']) ) ? $property['OS_TOWN_CITY'] : '' ) );
						update_post_meta( $post_id, '_address_four', ( ( isset($property['OS_REGION']) ) ? $property['OS_REGION'] : '' ) );
						update_post_meta( $post_id, '_address_postcode', ( ( isset($property['ZIPCODE']) ) ? $property['ZIPCODE'] : '' ) );
					}
					else
					{
						update_post_meta( $post_id, '_address_name_number', ( ( isset($property['ADDRESS_1']) ) ? $property['ADDRESS_1'] : '' ) );
						update_post_meta( $post_id, '_address_street', ( ( isset($property['ADDRESS_2']) ) ? $property['ADDRESS_2'] : '' ) );
						update_post_meta( $post_id, '_address_two', ( (isset($property['TOWN']) && isset($property['ADDRESS_3'])) ? $property['ADDRESS_3'] : '' ) );
						update_post_meta( $post_id, '_address_three', ( ( ( isset($property['TOWN']) ) ? $property['TOWN'] : ( ( isset($property['ADDRESS_3']) ) ? $property['ADDRESS_3'] : '' ) ) ) );
						update_post_meta( $post_id, '_address_four', ( ( isset($property['ADDRESS_4']) ) ? $property['ADDRESS_4'] : '' ) );
						update_post_meta( $post_id, '_address_postcode', trim( ( ( isset($property['POSTCODE1']) ) ? $property['POSTCODE1'] : '' ) . ' ' . ( ( isset($property['POSTCODE2']) ) ? $property['POSTCODE2'] : '' ) ) );
					}

					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_blm_address_fields_to_check', array('ADDRESS_2', 'ADDRESS_3', 'TOWN', 'ADDRESS_4', 'OS_TOWN_CITY', 'OS_REGION') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property[$address_field]) && trim($property[$address_field]) != '' ) 
						{
							$location_terms = get_terms(array(
								'taxonomy' => 'location',
								'hide_empty' => 0,
								'name' => trim($property[$address_field]),
							));

							if ( $location_terms !== null && count($location_terms) > 0 )
							{
								foreach($location_terms as $location_term)
								{
									if ( isset($location_term->term_id) )
									{
										$location_term_ids[] = (int)$location_term->term_id;
									}
								}
							}
						}
					}

					$location_term_ids = array_unique($location_term_ids);

					if ( !empty($location_term_ids) )
					{
						if ( count($location_term_ids) === 1 )
						{
							// If address matches exactly one existing location, set it
							wp_set_object_terms( $post_id, $location_term_ids, 'location' );
						}
						else
						{
							// Address matches multiple existing locations
							// Create new array of any of those locations where the parent location is also in the address
							$terms_with_parent_present = array();
							foreach ( $location_term_ids as $term_id )
							{
								$selected_term = get_term($term_id, 'location');
								if ( $selected_term->parent !== 0 && in_array((int)$selected_term->parent, $location_term_ids) )
								{
									$terms_with_parent_present[] = (int)$term_id;
								}
							}

							if ( !empty($terms_with_parent_present) && count($terms_with_parent_present) !== count($location_term_ids) )
							{
								// Some of our matched locations also have matching parent locations. Set only these as the property location(s)
								wp_set_object_terms( $post_id, $terms_with_parent_present, 'location' );
							}
							else
							{
								// If parent locations aren't matched, just set all of the matching locations
								wp_set_object_terms( $post_id, $location_term_ids, 'location' );
							}
						}
					}
					else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'location' );
	                }

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );

					$office_id = $this->primary_office_id;
					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( $branch_code == $property['BRANCH_ID'] )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'residential-sales';
					if ( $property['TRANS_TYPE_ID'] == '2' )
					{
						$department = 'residential-lettings';
					}
					if ( $commercial_active )
					{
						// Commercial is active.
						// Does this property have any commecial characteristics
						if ( isset( $property['LET_TYPE_ID'] ) && $property['LET_TYPE_ID'] == 4 )
						{
							$department = 'commercial';
						}
						else
						{
							// Check if the type is any of the commercial types
							//$commercial_property_types = $this->get_blm_mapping_values('commercial_property_type');

							$format = propertyhive_property_import_get_import_format( 'blm_local' );
							$commercial_property_types['taxonomy_values']['commercial_property_type'];
							if ( isset($commercial_property_types[$property['PROP_SUB_ID']]) )
							{
								$department = 'commercial';
							}
						}
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['BRANCH_ID'] . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['BRANCH_ID'] . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['BRANCH_ID'] . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['BRANCH_ID']]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['BRANCH_ID']] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['BRANCH_ID']]);
						}

						if ( !empty($explode_agent_branch) )
						{
							update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
							update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );

							$this->branch_ids_processed[] = $explode_agent_branch[1];
						}
	        		}

					// Residential Details
					update_post_meta( $post_id, '_department', $department );
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property['BEDROOMS']) ) ? $property['BEDROOMS'] : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property['BATHROOMS']) ) ? $property['BATHROOMS'] : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['LIVING_ROOMS']) ) ? $property['LIVING_ROOMS'] : '' ) );

					if ( isset($property['COUNCIL_TAX_BAND']) ) { update_post_meta( $post_id, '_council_tax_band', $property['COUNCIL_TAX_BAND'] ); }

					// Property Type
					if ( $department == 'residential-sales' || $department == 'residential-lettings' )
					{
						$taxonomy = 'property_type';
			        }
			        elseif ( $department == 'commercial' )
			        {
			        	$taxonomy = 'commercial_property_type';
			        }

			        $mapping = isset($import_settings['mappings'][$taxonomy]) ? $import_settings['mappings'][$taxonomy] : array();
					
					if ( isset($property['PROP_SUB_ID']) && $property['PROP_SUB_ID'] != '' )
					{
						if ( !empty($mapping) && isset($mapping[$property['PROP_SUB_ID']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['PROP_SUB_ID']], $taxonomy );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, $taxonomy );

			            	$this->log( 'Property received with a type (' . $property['PROP_SUB_ID'] . ') that is not mapped', $post_id, $property['AGENT_REF'] );

			            	$import_settings = $this->add_missing_mapping( $mapping, $taxonomy, $property['PROP_SUB_ID'], $post_id );
			            }
			        }
			        else
			        {
			        	wp_delete_object_term_relationships( $post_id, $taxonomy );
			        }

					// Clean price
					$property['PRICE'] = str_replace(".00", "", preg_replace("/[^0-9.]/", '', $property['PRICE']));

					$currency = 'GBP';
					if ( isset($property['COUNTRY_CODE']) && $property['COUNTRY_CODE'] != '' )
					{
						$ph_countries = new PH_Countries();
						$country = $ph_countries->get_country( $property['COUNTRY_CODE'] );
						if ( $country !== FALSE )
						{
							$currency = $country['currency_code'];
						}
					}

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						update_post_meta( $post_id, '_price', $property['PRICE'] );
						update_post_meta( $post_id, '_poa', ( isset($property['PRICE_QUALIFIER']) && $property['PRICE_QUALIFIER'] == '1' ) ? 'yes' : '' );
						
						update_post_meta( $post_id, '_currency', $currency );
						
						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						if ( !empty($mapping) && isset($property['PRICE_QUALIFIER']) && isset($mapping[$property['PRICE_QUALIFIER']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['PRICE_QUALIFIER']], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

			            // Tenure
			            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

			            if ( !empty($mapping) && isset($property['TENURE_TYPE_ID']) && isset($mapping[$property['TENURE_TYPE_ID']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['TENURE_TYPE_ID']], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }

			            if ( $property['TENURE_TYPE_ID'] == 2 )
			            {
			            	// Leasehold
			            	
			            	if ( isset($property['SHARED_OWNERSHIP']) ) { update_post_meta( $post_id, '_shared_ownership', ( $property['SHARED_OWNERSHIP'] == '1' ? 'yes' : '' ) ); }
			            	if ( isset($property['SHARED_OWNERSHIP_PERCENTAGE']) ) { update_post_meta( $post_id, '_shared_ownership_percentage', ( $property['SHARED_OWNERSHIP'] == '1' ? $property['SHARED_OWNERSHIP_PERCENTAGE'] : '' ) ); }
			            	if ( isset($property['ANNUAL_GROUND_RENT']) ) { update_post_meta( $post_id, '_ground_rent', $property['ANNUAL_GROUND_RENT'] ); }
			            	if ( isset($property['GROUND_RENT_REVIEW_PERIOD_YEARS']) ) { update_post_meta( $post_id, '_ground_rent_review_years', $property['GROUND_RENT_REVIEW_PERIOD_YEARS'] ); }
			            	if ( isset($property['ANNUAL_SERVICE_CHARGE']) ) { update_post_meta( $post_id, '_service_charge', $property['ANNUAL_SERVICE_CHARGE'] ); }
			            	if ( isset($property['TENURE_UNEXPIRED_YEARS']) ) { update_post_meta( $post_id, '_leasehold_years_remaining', $property['TENURE_UNEXPIRED_YEARS'] ); }
			            }
					}

					// Residential Lettings Details
					if ( $department == 'residential-lettings' )
					{
						update_post_meta( $post_id, '_rent', $property['PRICE'] );

						$rent_frequency = 'pcm';
						$price_actual = $property['PRICE'];
						switch ($property['LET_RENT_FREQUENCY'])
						{
							case "0": { $rent_frequency = 'pw'; $price_actual = ($property['PRICE'] * 52) / 12; break; }
							case "1": { $rent_frequency = 'pcm'; $price_actual = $property['PRICE']; break; }
							case "2": { $rent_frequency = 'pq'; $price_actual = ($property['PRICE'] * 4) / 12; break; }
							case "3": { $rent_frequency = 'pa'; $price_actual = $property['PRICE'] / 12; break; }
							case "5": 
							{
								$rent_frequency = 'pppw';
								$bedrooms = ( isset($property['BEDROOMS']) ? $property['BEDROOMS'] : '0' );
								if ( $bedrooms != '' && $bedrooms != 0 )
								{
									$price_actual = (($property['PRICE'] * 52) / 12) * $bedrooms;
								}
								else
								{
									$price_actual = ($property['PRICE'] * 52) / 12;
								}
								break; 
							}
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

						update_post_meta( $post_id, '_currency', $currency );
						
						update_post_meta( $post_id, '_poa', ( isset($property['PRICE_QUALIFIER']) && $property['PRICE_QUALIFIER'] == '1' ) ? 'yes' : '' );

						update_post_meta( $post_id, '_deposit', preg_replace( "/[^0-9.]/", '', ( ( isset($property['LET_BOND']) ) ? $property['LET_BOND'] : '' ) ) );
	            		update_post_meta( $post_id, '_available_date', ( (isset($property['LET_DATE_AVAILABLE']) && $property['LET_DATE_AVAILABLE'] != '') ? date("Y-m-d", strtotime($property['LET_DATE_AVAILABLE'])) : '' ) );

	            		// Furnished
	            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

						if ( !empty($mapping) && isset($property['LET_FURN_ID']) && isset($mapping[$property['LET_FURN_ID']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['LET_FURN_ID']], 'furnished' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'furnished' );
			            }
					}

					// Commercial Details
					if ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

	            		if ( $property['TRANS_TYPE_ID'] == '1' )
	            		{
	            			update_post_meta( $post_id, '_for_sale', 'yes' );

	            			update_post_meta( $post_id, '_commercial_price_currency', $currency );

	            			update_post_meta( $post_id, '_price_from', $property['PRICE'] );
	            			update_post_meta( $post_id, '_price_to', $property['PRICE'] );
	            			update_post_meta( $post_id, '_price_units', '' );
	            			update_post_meta( $post_id, '_price_poa', ( isset($property['PRICE_QUALIFIER']) && $property['PRICE_QUALIFIER'] == '1' ) ? 'yes' : '' );

	            			// Price Qualifier
							$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

							if ( !empty($mapping) && isset($property['PRICE_QUALIFIER']) && isset($mapping[$property['PRICE_QUALIFIER']]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[$property['PRICE_QUALIFIER']], 'price_qualifier' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
				            }

				            // Tenure
				            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

				            if ( !empty($mapping) && isset($property['TENURE_TYPE_ID']) && isset($mapping[$property['TENURE_TYPE_ID']]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[$property['TENURE_TYPE_ID']], 'commercial_tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
				            }
	            		}
	            		elseif ( $property['TRANS_TYPE_ID'] == '2' )
	            		{
	            			update_post_meta( $post_id, '_to_rent', 'yes' );

	            			update_post_meta( $post_id, '_commercial_rent_currency', $currency );

	            			update_post_meta( $post_id, '_rent_from', $property['PRICE'] );
	            			update_post_meta( $post_id, '_rent_to', $property['PRICE'] );
	            			$rent_units = '';
	            			switch ($property['LET_RENT_FREQUENCY'])
							{
		            			case "0": { $rent_units = 'pw'; break; }
								case "1": { $rent_units = 'pcm'; break; }
								case "2": { $rent_units = 'pq'; break; }
								case "3": { $rent_units = 'pa'; break; }
							}
							update_post_meta( $post_id, '_rent_units', $rent_units );
	            			update_post_meta( $post_id, '_rent_poa', ( isset($property['PRICE_QUALIFIER']) && $property['PRICE_QUALIFIER'] == '1' ) ? 'yes' : '' );
	            		}

	            		$size = '';
	            		$unit = 'sqft';
	            		if ( isset($property['MIN_SIZE_ENTERED']) )
	            		{
		            		$size = preg_replace("/[^0-9.]/", '', $property['MIN_SIZE_ENTERED']);
		            		$size = str_replace(".00", "", $size);

				            if ( isset($property['AREA_SIZE_UNIT_ID']) )
				            {
				            	switch ( $property['AREA_SIZE_UNIT_ID'] )
				            	{
				            		case "1": { $unit = 'sqft'; break; }
				            		case "2": { $unit = 'sqm'; break; }
				            		case "3": { $unit = 'acre'; break; }
				            		case "4": { $unit = 'hectare'; break; }
				            	}
				            }
				        }
				        update_post_meta( $post_id, '_floor_area_from', $size );
				        update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $unit ) );

				        $size = '';
	            		$unit = 'sqft';
				        if ( isset($property['MAX_SIZE_ENTERED']) )
	            		{
		            		$size = preg_replace("/[^0-9.]/", '', $property['MAX_SIZE_ENTERED']);
		            		$size = str_replace(".00", "", $size);

				            if ( isset($property['AREA_SIZE_UNIT_ID']) )
				            {
				            	switch ( $property['AREA_SIZE_UNIT_ID'] )
				            	{
				            		case "1": { $unit = 'sqft'; break; }
				            		case "2": { $unit = 'sqm'; break; }
				            		case "3": { $unit = 'acre'; break; }
				            		case "4": { $unit = 'hectare'; break; }
				            	}
				            
				            }
				        }
				        update_post_meta( $post_id, '_floor_area_to', $size );
				        update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $unit ) );

				        update_post_meta( $post_id, '_floor_area_units', $unit );
					}

					// Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
					if ( $on_market_by_default === true )
					{
						update_post_meta( $post_id, '_on_market', ( $property['PUBLISHED_FLAG'] == '1' ) ? 'yes' : '' );
					}
					
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					if ( !empty($mapping) && isset($property['STATUS_ID']) && isset($mapping[$property['STATUS_ID']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['STATUS_ID']], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

					// Features
					$features = array();
					for ( $i = 1; $i <= 10; ++$i )
					{
						if ( isset($property['FEATURE' . $i]) && trim($property['FEATURE' . $i]) != '' )
						{
							$features[] = trim($property['FEATURE' . $i]);
						}
					}

					update_post_meta( $post_id, '_features', count( $features ) );
	        		
	        		$i = 0;
			        foreach ( $features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }

			        if ( $department != 'commercial' )
					{
						// For now put the whole description in one room
						update_post_meta( $post_id, '_rooms', '1' );
						update_post_meta( $post_id, '_room_name_0', '' );
						update_post_meta( $post_id, '_room_dimensions_0', '' );
			            update_post_meta( $post_id, '_room_description_0', $property['DESCRIPTION'] );
				    }
				    else
				    {
				    	// For now put the whole description in one description
				    	update_post_meta( $post_id, '_descriptions', '1' );
						update_post_meta( $post_id, '_description_name_0', '' );
	            		update_post_meta( $post_id, '_description_0', $property['DESCRIPTION'] );
				    }

				    // Media - Images
				    $media = array();
				    for ( $i = 0; $i <= 49; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_IMAGE_' . $j]) && trim($property['MEDIA_IMAGE_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_IMAGE_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_IMAGE_' . $j]), 0, 4 ) == 'http'
							)
							{
								$url = $property['MEDIA_IMAGE_' . $j];
								$explode_url = explode('?', $url);

								$media[] = array(
									'url' => $url,
									'compare_url' => basename( $explode_url[0] ),
									'description' => ( ( isset($property['MEDIA_IMAGE_TEXT_' . $j]) && $property['MEDIA_IMAGE_TEXT_' . $j] != '' ) ? $property['MEDIA_IMAGE_TEXT_' . $j] : '' ),
								);
							}
							else
							{
								$media[] = array(
									'url' => $property['MEDIA_IMAGE_' . $j],
									'description' => ( ( isset($property['MEDIA_IMAGE_TEXT_' . $j]) && $property['MEDIA_IMAGE_TEXT_' . $j] != '' ) ? $property['MEDIA_IMAGE_TEXT_' . $j] : '' ),
									'local' => true,
									'local_directory' => $local_directory
								);
							}
						}
					}
					for ( $i = 70; $i <= 99; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_IMAGE_' . $j]) && trim($property['MEDIA_IMAGE_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_IMAGE_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_IMAGE_' . $j]), 0, 4 ) == 'http'
							)
							{
								$url = $property['MEDIA_IMAGE_' . $j];
								$explode_url = explode('?', $url);

								$media[] = array(
									'url' => $url,
									'compare_url' => basename( $explode_url[0] ),
									'description' => ( ( isset($property['MEDIA_IMAGE_TEXT_' . $j]) && $property['MEDIA_IMAGE_TEXT_' . $j] != '' ) ? $property['MEDIA_IMAGE_TEXT_' . $j] : '' ),
								);
							}
							else
							{
								$media[] = array(
									'url' => $property['MEDIA_IMAGE_' . $j],
									'description' => ( ( isset($property['MEDIA_IMAGE_TEXT_' . $j]) && $property['MEDIA_IMAGE_TEXT_' . $j] != '' ) ? $property['MEDIA_IMAGE_TEXT_' . $j] : '' ),
									'local' => true,
									'local_directory' => $local_directory
								);
							}
						}
					}

					$this->import_media( $post_id, $property['AGENT_REF'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
			    	for ( $i = 0; $i <= 49; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_FLOOR_PLAN_' . $j]) && trim($property['MEDIA_FLOOR_PLAN_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_FLOOR_PLAN_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_FLOOR_PLAN_' . $j]), 0, 4 ) == 'http'
							)
							{
								$url = $property['MEDIA_FLOOR_PLAN_' . $j];
								$explode_url = explode('?', $url);

								$media[] = array(
									'url' => $url,
									'compare_url' => basename( $explode_url[0] ),
									'description' => ( ( isset($property['MEDIA_FLOOR_PLAN_TEXT_' . $j]) && $property['MEDIA_FLOOR_PLAN_TEXT_' . $j] != '' ) ? $property['MEDIA_FLOOR_PLAN_TEXT_' . $j] : '' ),
								);
							}
							else
							{
								$media[] = array(
									'url' => $property['MEDIA_FLOOR_PLAN_' . $j],
									'description' => ( ( isset($property['MEDIA_FLOOR_PLAN_TEXT_' . $j]) && $property['MEDIA_FLOOR_PLAN_TEXT_' . $j] != '' ) ? $property['MEDIA_FLOOR_PLAN_TEXT_' . $j] : '' ),
									'local' => true,
									'local_directory' => $local_directory
								);
							}
						}
					}

					$this->import_media( $post_id, $property['AGENT_REF'], 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
			    	for ( $i = 0; $i <= 10; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_DOCUMENT_' . $j]) && trim($property['MEDIA_DOCUMENT_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_DOCUMENT_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_DOCUMENT_' . $j]), 0, 4 ) == 'http'
							)
							{
								$media[] = array(
									'url' => $property['MEDIA_DOCUMENT_' . $j],
									'description' => ( ( isset($property['MEDIA_DOCUMENT_TEXT_' . $j]) && $property['MEDIA_DOCUMENT_TEXT_' . $j] != '' ) ? $property['MEDIA_FLOOR_PLAN_TEXT_' . $j] : '' ),
									'modified' => ( isset($property['UPDATE_DATE']) && $property['UPDATE_DATE'] != '' ) ? $property['UPDATE_DATE'] : '',
								);
							}
							else
							{
								$media[] = array(
									'url' => $property['MEDIA_DOCUMENT_' . $j],
									'description' => ( ( isset($property['MEDIA_DOCUMENT_TEXT_' . $j]) && $property['MEDIA_DOCUMENT_TEXT_' . $j] != '' ) ? $property['MEDIA_FLOOR_PLAN_TEXT_' . $j] : '' ),
									'local' => true,
									'local_directory' => $local_directory
								);
							}
						}
					}

					$this->import_media( $post_id, $property['AGENT_REF'], 'brochure', $media, ( isset($property['UPDATE_DATE']) && $property['UPDATE_DATE'] != '' ) ? true : false );

					// Media - EPCs
				    $media = array();
			    	for ( $i = 60; $i <= 61; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_IMAGE_' . $j]) && trim($property['MEDIA_IMAGE_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_IMAGE_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_IMAGE_' . $j]), 0, 4 ) == 'http'
							)
							{
								$media[] = array(
									'url' => $property['MEDIA_IMAGE_' . $j],
									'description' => ( ( isset($property['MEDIA_IMAGE_TEXT_' . $j]) && $property['MEDIA_IMAGE_TEXT_' . $j] != '' ) ? $property['MEDIA_IMAGE_TEXT_' . $j] : '' ),
								);
							}
							else
							{
								$media[] = array(
									'url' => $property['MEDIA_IMAGE_' . $j],
									'description' => ( ( isset($property['MEDIA_IMAGE_TEXT_' . $j]) && $property['MEDIA_IMAGE_TEXT_' . $j] != '' ) ? $property['MEDIA_IMAGE_TEXT_' . $j] : '' ),
									'local' => true,
									'local_directory' => $local_directory
								);
							}
						}
					}
					for ( $i = 50; $i <= 55; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_DOCUMENT_' . $j]) && trim($property['MEDIA_DOCUMENT_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_DOCUMENT_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_DOCUMENT_' . $j]), 0, 4 ) == 'http'
							)
							{
								$media[] = array(
									'url' => $property['MEDIA_DOCUMENT_' . $j],
									'description' => ( ( isset($property['MEDIA_DOCUMENT_TEXT_' . $j]) && $property['MEDIA_DOCUMENT_TEXT_' . $j] != '' ) ? $property['MEDIA_DOCUMENT_TEXT_' . $j] : '' ),
								);
							}
							else
							{
								$media[] = array(
									'url' => $property['MEDIA_DOCUMENT_' . $j],
									'description' => ( ( isset($property['MEDIA_DOCUMENT_TEXT_' . $j]) && $property['MEDIA_DOCUMENT_TEXT_' . $j] != '' ) ? $property['MEDIA_DOCUMENT_TEXT_' . $j] : '' ),
									'local' => true,
									'local_directory' => $local_directory
								);
							}
						}
					}

					$this->import_media( $post_id, $property['AGENT_REF'], 'epc', $media, false );

					// Media - Virtual Tours
					$urls = array();

					for ( $i = 0; $i <= 5; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property['MEDIA_VIRTUAL_TOUR_' . $j]) && trim($property['MEDIA_VIRTUAL_TOUR_' . $j]) != '' )
						{
							if ( 
								substr( strtolower($property['MEDIA_VIRTUAL_TOUR_' . $j]), 0, 2 ) == '//' || 
								substr( strtolower($property['MEDIA_VIRTUAL_TOUR_' . $j]), 0, 4 ) == 'http'
							)
							{
								$urls[] = trim($property['MEDIA_VIRTUAL_TOUR_' . $j]);
							}
						}
					}

					if ( !empty($urls) )
					{
						update_post_meta($post_id, '_virtual_tours', count($urls) );
	        
				        foreach ($urls as $i => $url)
				        {
				            update_post_meta($post_id, '_virtual_tour_' . $i, $url);
				        }

				        $this->log( 'Imported ' . count($urls) . ' virtual tours', $post_id, $property['AGENT_REF'] );
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['AGENT_REF'] );
				}

				if ( isset($property['UPDATE_DATE']) ) { update_post_meta( $post_id, '_blm_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['UPDATE_DATE'])) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_blm", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['AGENT_REF'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;
			
		} // end foreach

		do_action( "propertyhive_post_import_properties_blm" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['AGENT_REF'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

    private function clean_up_old_blms()
    {
    	$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

    	$local_directory = $import_settings['local_directory'];

    	// Clean up processed .BLMs and unused media older than 7 days old (7 days = 604800 seconds)
		if ($handle = opendir($local_directory)) 
		{
		    while (false !== ($file = readdir($handle))) 
		    {
		        if (
		        	$file != "." && $file != ".." && 
		        	(
		        		substr($file, -9) == 'processed' || 
		        		substr(strtolower($file), -4) == '.jpg' || 
		        		substr(strtolower($file), -4) == '.gif' || 
		        		substr(strtolower($file), -5) == '.jpeg' || 
		        		substr(strtolower($file), -4) == '.png' || 
		        		substr(strtolower($file), -4) == '.bmp' || 
		        		substr(strtolower($file), -4) == '.pdf'
		        	)
		        ) 
		        {
		        	if ( filemtime($local_directory . '/' . $file) !== FALSE && filemtime($local_directory . '/' . $file) < (time() - 604800) )
		        	{
		        		unlink($local_directory . '/' . $file);
		        	}
		        }
		    }
		    closedir($handle);
		}
		else
		{
			$this->log_error( 'Failed to read from directory ' . $local_directory . '. Please ensure the local directory specified exists, is the full server path and is readable.' );
			return false;
		}
	}

    public function archive($blm_file)
    {
    	// Rename to append the date and '.processed' as to not get picked up again. Will be cleaned up every 7 days
    	$new_target_file = $blm_file . '-' . time() .'.processed';
		rename( $blm_file, $new_target_file );
		
		$this->log( "Archived BLM. Available for download for 7 days: " . str_replace("/includes/import-formats", "", plugin_dir_url( __FILE__ )) . "/download.php?import_id=" . $this->import_id . "&file=" . base64_encode(basename($new_target_file)));
	}

	public function get_default_mapping_values()
	{
		$commercial_property_types = array(
	        '19' => 'Commercial Property',
	        '80' => 'Restaurant',
	        '83' => 'Cafe',
	        '86' => 'Mill',
	        '134' => 'Bar / Nightclub',
	        '137' => 'Shop',
	        '178' => 'Office',
	        '181' => 'Business Park',
	        '184' => 'Serviced Office',
	        '187' => 'Retail Property (High Street)',
	        '190' => 'Retail Property (Out of Town)',
	        '193' => 'Convenience Store',
	        '196' => 'Garages',
	        '199' => 'Hairdresser/Barber Shop',
	        '202' => 'Hotel',
	        '205' => 'Petrol Station',
	        '208' => 'Post Office',
	        '211' => 'Pub',
	        '214' => 'Workshop & Retail Space',
	        '217' => 'Distribution Warehouse',
	        '220' => 'Factory',
	        '223' => 'Heavy Industrial',
	        '226' => 'Industrial Park',
	        '229' => 'Light Industrial',
	        '232' => 'Storage',
	        '235' => 'Showroom',
	        '238' => 'Warehouse',
	        '241' => 'Land (Commercial)',
	        '244' => 'Commercial Development',
	        '247' => 'Industrial Development',
	        '250' => 'Residential Development',
	        '253' => 'Commercial Property',
	        '256' => 'Data Centre',
	        '259' => 'Farm',
	        '262' => 'Healthcare Facility',
	        '265' => 'Marine Property',
	        '268' => 'Mixed Use',
	        '271' => 'Research & Development Facility',
	        '274' => 'Science Park',
	        '277' => 'Guest House',
	        '280' => 'Hospitality',
	        '283' => 'Leisure Facility',
	    );

	    $property_types = array(
	        '0' => 'Not Specified',
	        '1' => 'Terraced',
	        '2' => 'End of Terrace',
	        '3' => 'Semi-Detached ',
	        '4' => 'Detached',
	        '5' => 'Mews',
	        '6' => 'Cluster House',
	        '7' => 'Ground Flat',
	        '8' => 'Flat',
	        '9' => 'Studio',
	        '10' => 'Ground Maisonette',
	        '11' => 'Maisonette',
	        '12' => 'Bungalow',
	        '13' => 'Terraced Bungalow',
	        '14' => 'Semi-Detached Bungalow',
	        '15' => 'Detached Bungalow',
	        '16' => 'Mobile Home',
	        '17' => 'Hotel',
	        '18' => 'Guest House',
	        '20' => 'Land',
	        '21' => 'Link Detached House',
	        '22' => 'Town House',
	        '23' => 'Cottage',
	        '24' => 'Chalet',
	        '27' => 'Villa',
	        '28' => 'Apartment',
	        '29' => 'Penthouse',
	        '30' => 'Finca',
	        '43' => 'Barn Conversion',
	        '44' => 'Serviced Apartments',
	        '45' => 'Parking',
	        '46' => 'Sheltered Housing',
	        '47' => 'Retirement Property',
	        '48' => 'House Share',
	        '49' => 'Flat Share',
	        '51' => 'Garages',
	        '52' => 'Farm House',
	        '53' => 'Equestrian',
	        '56' => 'Duplex',
	        '59' => 'Triplex',
	        '62' => 'Longere',
	        '65' => 'Gite',
	        '68' => 'Barn',
	        '71' => 'Trulli',
	        '74' => 'Mill',
	        '77' => 'Ruins',
	        '89' => 'Trulli',
	        '92' => 'Castle',
	        '95' => 'Village House',
	        '101' => 'Cave House',
	        '104' => 'Cortijo',
	        '107' => 'Farm Land',
	        '110' => 'Plot',
	        '113' => 'Country House',
	        '116' => 'Stone House',
	        '117' => 'Caravan',
	        '118' => 'Lodge',
	        '119' => 'Log Cabin',
	        '120' => 'Manor House',
	        '121' => 'Stately Home',
	        '125' => 'Off-Plan',
	        '128' => 'Semi-detached Villa',
	        '131' => 'Detached Villa',
	        '140' => 'Riad',
	        '141' => 'House Boat',
	        '142' => 'Hotel Room',
	        '143' => 'Block of Apartments',
	        '144' => 'Private Halls',
	        '253' => 'Commercial Property',
	    );

	    // If commercial department not active then add commercial types to normal list of types
	    if ( get_option( 'propertyhive_active_departments_commercial', '' ) == '' )
	    {
	        $property_types = array_merge( $property_types, $commercial_property_types );
	    }

	    return array(
            'sales_availability' => array(
                '0' => 'Available',
                '1' => 'SSTC',
                '2' => 'SSTCM (Scotland only)',
                '3' => 'Under Offer',
                '6' => 'Sold',
            ),
            'lettings_availability' => array(
                '0' => 'Available',
                '4' => 'Reserved',
                '5' => 'Let Agreed',
                '7' => 'Let',
            ),
            'commercial_availability' => array(
                '0' => 'Available',
                '1' => 'SSTC',
                '2' => 'SSTCM (Scotland only)',
                '3' => 'Under Offer',
                '4' => 'Reserved',
                '5' => 'Let Agreed',
                '6' => 'Sold',
                '7' => 'Let',
            ),
            'property_type' => $property_types,
            'commercial_property_type' => $commercial_property_types,
            'price_qualifier' => array(
                '0' => 'Default',
                '1' => 'POA',
                '2' => 'Guide Price',
                '3' => 'Fixed Price',
                '4' => 'Offers in Excess of',
                '5' => 'OIRO',
                '6' => 'Sale by Tender',
                '7' => 'From',
                '9' => 'Shared Ownership',
                '10' => 'Offers Over',
                '11' => 'Part Buy Part Rent',
                '12' => 'Shared Equity',
            ),
            'tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '5' => 'Share of Freehold',
                '3' => 'Feudal',
                '4' => 'Commonhold',
            ),
            'commercial_tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '5' => 'Share of Freehold',
                '3' => 'Feudal',
                '4' => 'Commonhold',
            ),
            'furnished' => array(
                '0' => 'Furnished',
                '1' => 'Part Furnished',
                '2' => 'Unfurnished',
                '4' => 'Furnished/Un Furnished',
                '3' => 'Not Specified',
            )
        );
	}
}

}