<?php
/**
 * Class for managing the import process of a Thesaurus file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Thesaurus_Import extends PH_Property_Import_Process {

	/**
	 * @var string
	 */
	private $target_file;

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

	public function parse( $test = false )
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}
		
		$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], isset($import_settings['ftp_passive']) ? $import_settings['ftp_passive'] : '');
		if ( $ftp_conn === null)
		{
			$this->log_error( 'Incorrect FTP details provided' );
			return false;
		}

		$wp_upload_dir = wp_upload_dir();
		if( $wp_upload_dir['error'] !== FALSE )
		{
			$this->log_error( 'Unable to create uploads folder. Please check permissions' );
			return false;
		}

		$xml_file = $wp_upload_dir['basedir'] . '/ph_import/' . $import_settings['filename'];

		// Get file
		if ( ftp_get( $ftp_conn, $xml_file, $import_settings['filename'], FTP_ASCII ) )
		{

		}
		else
		{
			$this->log_error( 'Failed to get file ' . $import_settings['filename'] . ' from FTP directory. Maybe try changing the FTP Passive option' );
			return false;
		}
		ftp_close( $ftp_conn );

		$this->target_file = $xml_file;

		$this->log("Parsing properties");

		$handle = fopen( $this->target_file, "r" );
		if ($handle) 
		{
			$limit = $this->get_property_limit();

		    while (($property = fgets($handle)) !== false) 
		    {
		        if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

		        $this->properties[] = explode("|", $property);
		    }

		    fclose($handle);
		} 
		else 
		{
		    $this->log_error( 'Error opening file' );
		    return false;
		}

		if ( $test === false )
        {
        	if ( empty($this->properties) && apply_filters( 'propertyhive_property_import_stop_if_no_properties', true, $this->import_id ) === true )
        	{
	        	$this->log_error('No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.');

	        	return false;
	        }
        }

		return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		// get lat/lngs from geocode.file
		$this->log( 'Getting geocode.file for the co-ordinates' );

		$lat_lngs = array();

		$media_processing = get_option( 'propertyhive_property_import_media_processing', '' );

		$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
		if ( $ftp_conn === null)
		{
			$this->log_error( 'Failed to connect to FTP' );
			return false;
		}
		else
		{
	    	$wp_upload_dir = wp_upload_dir();

	    	$xml_file = $wp_upload_dir['basedir'] . '/ph_import/geocode.file';

	    	// Get file
	    	if ( ftp_get( $ftp_conn, $xml_file, 'geocode.file', FTP_ASCII ) )
	    	{
	    		$handle = fopen( $xml_file, "r" );
				if ($handle) 
				{
				    while (($lat_lng_row = fgets($handle)) !== false) 
				    {
				        // process the line read.

				        $lat_lng_row = explode("|", $lat_lng_row);

				        if ( 
				        	isset($lat_lng_row[0]) && isset($lat_lng_row[3]) && isset($lat_lng_row[2]) 
				        	&&
				        	$lat_lng_row[0] != '' && $lat_lng_row[3] != '' && $lat_lng_row[2] != ''
				        )
				        {
				        	$lat_lngs[$lat_lng_row[0]] = array(
				        		'lat' => $lat_lng_row[3],
				        		'lng' => $lat_lng_row[2],
				        	);
				        }
				    }

				    fclose($handle);
				} 

	    		unset($xml_file);
	    	}
	    	else
	    	{
	    		$this->log_error( 'Failed to download geocode.file' );
	    	}
	    }

	    // get users from userdata.file
		$this->log( 'Getting userdata.file for the co-ordinates' );

		$users = array();

		$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
		if ( $ftp_conn === null)
		{
			$this->log_error( 'Failed to connect to FTP' );
			return false;
		}
		else
		{
	    	$wp_upload_dir = wp_upload_dir();

	    	$xml_file = $wp_upload_dir['basedir'] . '/ph_import/userdata.file';

	    	// Get file
	    	if ( ftp_get( $ftp_conn, $xml_file, 'userdata.file', FTP_ASCII ) )
	    	{
	    		$handle = fopen( $xml_file, "r" );
				if ($handle) 
				{
				    while (($lat_lng_row = fgets($handle)) !== false) 
				    {
				        // process the line read.

				        $user_row = explode("|", $lat_lng_row);

				        if ( 
				        	isset($user_row[0]) && isset($user_row[1])
				        	&&
				        	$user_row[0] != '' && $user_row[1] != ''
				        )
				        {
				        	$users[$user_row[0]] = $user_row[1];
				        }
				    }

				    fclose($handle);
				} 

	    		unset($xml_file);
	    	}
	    	else
	    	{
	    		$this->log_error( 'Failed to download userdata.file' );
	    	}
	    }

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_thesaurus", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_thesaurus_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_thesaurus", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property[0] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property[0] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property[0], false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property[0], 0, $property[0], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = $property[139];
	        if (trim($display_address) == '')
	        {
	        	$display_address = $property[8];
	        	if ($property[7] != '')
	        	{
	        		if ($display_address != '') { $display_address .= ', '; }
	        		$display_address .= $property[7];
	        	}
	        	elseif ($property[6] != '')
	        	{
	        		if ($display_address != '') { $display_address .= ', '; }
	        		$display_address .= $property[6];
	        	}
	        }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property[0], $property, $display_address, $property[25], '', ( $property[230] ) ? date( 'Y-m-d H:i:s', strtotime( $property[230] )) : '' );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property[0] );

				update_post_meta( $post_id, '_property_import_data', print_r($property, true) );

				$previous_update_date = get_post_meta( $post_id, '_thesaurus_update_date_' . $this->import_id, TRUE);

				$skip_property = false;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if ( $previous_update_date == $property[204] )
					{
						$skip_property = true;
					}
				}

				$country = get_option( 'propertyhive_default_country', 'GB' );

				// Coordinates
				if ( isset($lat_lngs[$property[0]]) )
				{
					update_post_meta( $post_id, '_latitude', $lat_lngs[$property[0]]['lat'] );
					update_post_meta( $post_id, '_longitude', $lat_lngs[$property[0]]['lng'] );
				}
				else
				{
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array();
						$address_to_geocode_osm = array();
						if ( $property[8] != '' ) { $address_to_geocode[] = $property[8]; }
						if ( $property[7] != '' ) { $address_to_geocode[] = $property[7]; }
						if ( $property[6] != '' ) { $address_to_geocode[] = $property[6]; }
						if ( $property[5] != '' ) { $address_to_geocode[] = $property[5]; }
						if ( $property[9] != '' ) { $address_to_geocode[] = $property[9]; $address_to_geocode_osm[] = $property[9]; }

						$return = $this->do_geocoding_lookup( $post_id, $property[0], $address_to_geocode, $address_to_geocode_osm, $country );
					}
				}

				if ( !$skip_property )
				{
					update_post_meta( $post_id, $imported_ref_key, $property[0] );

					// Address
					update_post_meta( $post_id, '_reference_number', $property[0] );
					update_post_meta( $post_id, '_address_name_number', '' );
					update_post_meta( $post_id, '_address_street', $property[8] );
					update_post_meta( $post_id, '_address_two', $property[7] );
					update_post_meta( $post_id, '_address_three', $property[6] );
					update_post_meta( $post_id, '_address_four', $property[5] );
					update_post_meta( $post_id, '_address_postcode', $property[9] );

					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_thesaurus_address_fields_to_check', array(5, 6, 7) );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property[$address_field]) && trim($property[$address_field]) != '' ) 
						{
							$term = term_exists( trim($property[$address_field]), 'location');
							if ( $term !== 0 && $term !== null && isset($term['term_id']) )
							{
								$location_term_ids[] = (int)$term['term_id'];
							}
						}
					}

					if ( !empty($location_term_ids) )
					{
						wp_set_object_terms( $post_id, $location_term_ids, 'location' );
					}
					else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'location' );
	                }

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					$new_negotiator_id = '';
					
					if ( !empty($users) && isset($property[266]) && isset($users[$property[266]]) )
					{
						foreach ( $this->negotiators as $negotiator_key => $negotiator )
                        {
                            if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim($users[$property[266]])) )
                            {
                                $new_negotiator_id = $negotiator_key;
                            }
                        }
					}

					if ( $new_negotiator_id == '' )
                    {
                        $new_negotiator_id = get_post_meta( $post_id, '_negotiator_id', TRUE );
                        if ( $new_negotiator_id == '' )
                        {
                            // no neg found and no existing neg
                            $new_negotiator_id = get_current_user_id();
                        }
                    }

					update_post_meta( $post_id, '_negotiator_id', $new_negotiator_id );
						
					$office_id = $this->primary_office_id;
					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( $branch_code == $property[265] )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = ( ( in_array($property[1], array('L','6','7','F','W')) ) ? 'residential-lettings' : 'residential-sales' );

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property[265] . '|' . $this-import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property[265] . '|' . $this-import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property[265] . '|' . $this-import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property[265]]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property[265]] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property[265]]);
						}

						if ( !empty($explode_agent_branch) )
						{
							update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
							update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );

							$this->branch_ids_processed[] = $explode_agent_branch[1];
						}
						else
						{
							update_post_meta( $post_id, '_agent_id', '' );
							update_post_meta( $post_id, '_branch_id', '' );
						}
	        		}

					// Residential Details
					update_post_meta( $post_id, '_department', $department );
					update_post_meta( $post_id, '_bedrooms', $property[17] );
					update_post_meta( $post_id, '_bathrooms', $property[18] );
					update_post_meta( $post_id, '_reception_rooms', $property[19] );

					// Property Type
		            $mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();

		            if ( isset($property[14]) && $property[14] != '' )
		            {
						if ( !empty($mapping) && isset($mapping[$property[14]]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property[14]], 'property_type' );
			            }
			            else
						{
							wp_delete_object_term_relationships( $post_id, 'property_type' );

							$this->log( 'Property received with a type (' . $property[14] . ') that is not mapped', $post_id, $property[0] );

							$import_settings = $this->add_missing_mapping( $mapping, 'property_type', $property[14], $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, 'property_type' );
					}

					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', $property[3]));

					// Residential Sales Details
					if ( !in_array($property[1], array('L','6','7','F','W') ) )
					{
						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );
						update_post_meta( $post_id, '_currency', 'GBP' );

						$poa = '';
						if ( strtolower(substr($property[231], 0, 1)) == 'y' )
						{
							$poa = 'yes';
						}
						update_post_meta( $post_id, '_poa', $poa );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						if ( !empty($mapping) && isset($property[4]) && isset($mapping[$property[4]]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property[4]], 'price_qualifier' );
			            }
			            elseif ( !empty($mapping) && isset($property[211]) && isset($mapping[$property[211]]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property[211]], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

			            // Tenure
			            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

						if ( !empty($mapping) && isset($property[2]) && isset($mapping[$property[2]]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property[2]], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }
					}
					elseif ( in_array($property[1], array('L','6','7','F','W') ) )
					{
						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						$price_actual = $price;

						switch ($property[4])
						{
							case "per week":
							{
								$rent_frequency = 'pw';
								$price_actual = ($price * 52) / 12;
								break;
							}
							case "per month":
							{
								$rent_frequency = 'pcm';
								$price_actual = $price;
								break;
							}
							case "per year":
							{
								$rent_frequency = 'pa';
								$price_actual = $price / 12;
								break;
							}
						}

						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', $price_actual );
						update_post_meta( $post_id, '_currency', 'GBP' );
						
						$poa = '';
						if ( strtolower(substr($property[231], 0, 1)) == 'y' )
						{
							$poa = 'yes';
						}
						update_post_meta( $post_id, '_poa', $poa );

						update_post_meta( $post_id, '_deposit', ( ($property[252] != '' && $property[252] > 0) ? $property[252] : '' ) );
	            		
						$available_date = ''; // Sometimes provided as 2015-04-24, other times as 24 Apr 2015
						if ( isset($property[138]) && $property[138] != '' )
						{
							$available_date = date("Y-m-d", strtotime($property[138]));
						}
	            		update_post_meta( $post_id, '_available_date', $available_date ); // Need to do. Provided as 24 Apr 2015

	            		// Furnished
	            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

						if ( !empty($mapping) && isset($property[16]) && isset($mapping[$property[16]]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property[16]], 'furnished' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'furnished' );
			            }
					}

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
	                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						$featured = '';
						if ( $property[255] != '' )
						{
							$featured = 'yes';
						}
						update_post_meta( $post_id, '_featured', $featured );
					}

					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                        array();

					if ( !empty($mapping) && isset($property[1]) && isset($mapping[$property[1]]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property[1]], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					if ($property[20] != '') { $features[] = $property[20]; }
					if ($property[21] != '') { $features[] = $property[21]; }
					if ($property[22] != '') { $features[] = $property[22]; }
					if ($property[129] != '') { $features[] = $property[129]; }
					if ($property[130] != '') { $features[] = $property[130]; }
					if ($property[137] != '') { $features[] = $property[137]; }
					if ($property[238] != '') { $features[] = $property[238]; }
					if ($property[239] != '') { $features[] = $property[239]; }
					if ($property[240] != '') { $features[] = $property[240]; }
					if ($property[241] != '') { $features[] = $property[241]; }

					update_post_meta( $post_id, '_features', count( $features ) );
	        		
	        		$i = 0;
			        foreach ( $features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }

			        // Rooms
			        $room_title_start = 26;
			        $room_dimensions_start = 140;
			        $room_desc_start = 90;

			        $new_room_count = 0;

			        for ( $i = 0; $i < 32; ++$i )
			        {
			        	$room_title = ( isset( $property[$room_title_start+$i] ) ? $property[$room_title_start+$i] : '' );
			        	$room_dimensions = ( isset( $property[$room_dimensions_start+$i] ) ? $property[$room_dimensions_start+$i] : '' );
			        	$room_desc = ( isset( $property[$room_desc_start+$i] ) ? $property[$room_desc_start+$i] : '' );

			        	if ( trim($room_title) != '' || trim($room_desc) != '' )
			        	{
			        		update_post_meta( $post_id, '_room_name_' . $new_room_count, trim(utf8_encode($room_title)) );
				            update_post_meta( $post_id, '_room_dimensions_' . $new_room_count, trim(utf8_encode($room_dimensions)) );
				            update_post_meta( $post_id, '_room_description_' . $new_room_count, trim(utf8_encode($room_desc)) );

			        		++$new_room_count;
			        	}
			        }

			        update_post_meta( $post_id, '_rooms', $new_room_count );

					$pictures = array();
					if ($property[23] != '') { $pictures[] = $property[23]; }
					for ($i = 58; $i <= 89; ++$i)
					{
						if ($property[$i] != '') { $pictures[] = $property[$i]; }
					}

					$floorplans = array();
					for ($i = 132; $i <= 135; ++$i)
					{
						if ($property[$i] != '') { $floorplans[] = $property[$i]; }
					}

					$image_ftp_dir = $import_settings['image_ftp_dir'];
					$image_ftp_dirs = explode(",", $image_ftp_dir);

					if (!empty($pictures) || !empty($floorplans))
					{
						if ( !is_array(ftp_nlist($ftp_conn, ".")) )
	            		{
	            			// Oops. FTP connection has disappeared. Re-open it
	            			$this->log_error( 'FTP connection was not available. Re-attempting to connect' );

	            			$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
							if ( $ftp_conn === null)
							{
								$this->log_error( 'Failed to connect to FTP' );
								return false;
							}
	            		}
	            		
		            	// Media - Images
		            	if ( get_option('propertyhive_images_stored_as', '') == 'urls' )
	        			{
	        				$media_urls = array();

							update_post_meta( $post_id, '_photo_urls', $media_urls );

							$this->log( 'Imported ' . count($media_urls) . ' photo URLs', $post_id, $property[0] );
	        			}
	        			else
	        			{
			            	$media_ids = array();
			            	$new = 0;
							$existing = 0;
							$deleted = 0;
							$queued = 0;
							$imageCount = 0;
							$previous_media_ids = get_post_meta( $post_id, '_photos', TRUE );

							if ( !empty($pictures) )
							{
								$i = 0;
								foreach ( $pictures as $picture )
								{
									$media_file_name = $picture;
									$media_folder = dirname( $this->target_file );

					            	// Get file
					            	$got_file = false;
					            	foreach ( $image_ftp_dirs as $image_ftp_dir )
					            	{
					            		if ( !is_array(ftp_nlist($ftp_conn, ".")) )
					            		{
					            			// Oops. FTP connection has disappeared. Re-open it
					            			$this->log_error( 'FTP connection was not available. Re-attempting to connect' );

					            			$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
											if ( $ftp_conn === null)
											{
												$this->log_error( 'Failed to connect to FTP' );
												return false;
											}
					            		}

					            		if ( ftp_get( $ftp_conn, $media_folder . '/' . $media_file_name, $image_ftp_dir . '/' . $media_file_name, FTP_BINARY ) )
						            	{
						            		$got_file = true;
						            		break;
						            	}
					            	}
					            	
					            	if ( $got_file )
					            	{
										$description = '';

										if ( file_exists( $media_folder . '/' . $media_file_name ) )
										{
											$upload = true;
			                                $replacing_attachment_id = '';
			                                if ( isset($previous_media_ids[$i]) ) 
			                                {
			                                    // get this attachment
			                                    $current_image_path = get_post_meta( $previous_media_ids[$i], '_imported_path', TRUE );
			                                    $current_image_size = filesize( $current_image_path );
			                                    
			                                    if ($current_image_size > 0 && $current_image_size !== FALSE)
			                                    {
			                                        $replacing_attachment_id = $previous_media_ids[$i];
			                                        
			                                        $new_image_size = filesize( $media_folder . '/' . $media_file_name );
			                                        
			                                        if ($new_image_size > 0 && $new_image_size !== FALSE)
			                                        {
			                                            if ($current_image_size == $new_image_size)
			                                            {
			                                                $upload = false;
			                                            }
			                                            else
			                                            {
			                                                
			                                            }
			                                        }
			                                        else
				                                    {
				                                    	$this->log_error( 'Failed to get filesize of new image file ' . $media_folder . '/' . $media_file_name, $post_id, $property[0] );
				                                    }
			                                        
			                                        unset($new_image_size);
			                                    }
			                                    else
			                                    {
			                                    	$this->log_error( 'Failed to get filesize of existing image file ' . $current_image_path, $post_id, $property[0] );
			                                    }
			                                    
			                                    unset($current_image_size);
			                                }

			                                if ($upload)
			                                {
			                                	$description = ( $description != '' ) ? $description : preg_replace('/\.[^.]+$/', '', trim($media_file_name, '_'));

												if ( $media_processing !== 'background' ) {
													// We've physically received the file
													$upload = wp_upload_bits(trim($media_file_name, '_'), null, file_get_contents($media_folder . '/' . $media_file_name));  
					                                
					                                if( isset($upload['error']) && $upload['error'] !== FALSE )
					                                {
					                                	$this->log_error( print_r($upload['error'], TRUE), $post_id, $property[0] );
					                                }
					                                else
					                                {
					                                	// We don't already have a thumbnail and we're presented with an image
				                                        $wp_filetype = wp_check_filetype( $upload['file'], null );
				                                    
				                                        $attachment = array(
				                                             //'guid' => $wp_upload_dir['url'] . '/' . trim($media_file_name, '_'), 
				                                             'post_mime_type' => $wp_filetype['type'],
				                                             'post_title' => $description,
				                                             'post_content' => '',
				                                             'post_status' => 'inherit'
				                                        );
				                                        $attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
				                                        
				                                        if ( $attach_id === FALSE || $attach_id == 0 )
				                                        {    
				                                        	$this->log_error( 'Failed inserting image attachment ' . $upload['file'] . ' - ' . print_r($attachment, TRUE), $post_id, $property[0] );
				                                        }
				                                        else
				                                        {  
					                                        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
					                                        wp_update_attachment_metadata( $attach_id,  $attach_data );

						                                	update_post_meta( $attach_id, '_imported_path', $upload['file']);

						                                	$media_ids[] = $attach_id;

						                                	++$new;
						                                }
					                                }

					                                unlink($media_folder . '/' . $media_file_name);
					                            } else {
													$file_data = array(
														'name' => trim($media_file_name, '_'),
														'path' => $media_folder . '/' . $media_file_name
													);
													$this->add_media_to_download_queue($post_id, serialize($file_data), 'photos', $imageCount, $description);
													++$queued;
												}
				                            }
				                            else
				                            {
				                            	if ( isset($previous_media_ids[$i]) ) 
			                                	{
			                                		$media_ids[] = $previous_media_ids[$i];

			                                		++$existing;
			                                	}

			                                	unlink($media_folder . '/' . $media_file_name);
				                            }
										}
										else
										{
											if ( isset($previous_media_ids[$i]) ) 
					                    	{
					                    		$media_ids[] = $previous_media_ids[$i];

					                    		++$existing;
					                    	}
										}
									}
									else
									{
										if ( isset($previous_media_ids[$i]) ) 
				                    	{
				                    		$media_ids[] = $previous_media_ids[$i];

				                    		++$existing;
				                    	}

										$this->log_error( 'Failed to get file ' . $image_ftp_dir . '/' . $media_file_name . ' as ' . $media_folder . '/' . $media_file_name, $post_id, $property[0] );
									}

									++$imageCount;
									++$i;
								}
							}
							update_post_meta( $post_id, '_photos', $media_ids );

							// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
							if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
							{
								foreach ( $previous_media_ids as $previous_media_id )
								{
									if ( !in_array($previous_media_id, $media_ids) )
									{
										if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
										{
											++$deleted;
										}
									}
								}
							}

							$this->log( 'Imported ' . count($media_ids) . ' photos (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, $property[0] );
							if ($queued > 0) {
								$this->log( $queued . ' photos added to download queue', $post_id, $property[0] );
							}
						}

						// Media - Floorplans
						if ( get_option('propertyhive_floorplans_stored_as', '') == 'urls' )
	        			{
	        				$media_urls = array();

							update_post_meta( $post_id, '_floorplan_urls', $media_urls );

							$this->log( 'Imported ' . count($media_urls) . ' floorplan URLs', $post_id, $property[0] );
	        			}
	        			else
	        			{
							$media_ids = array();
							$new = 0;
							$existing = 0;
							$deleted = 0;
							$queued = 0;
							$floorplanCount = 0;
							$previous_media_ids = get_post_meta( $post_id, '_floorplans', TRUE );
							if ( !empty($floorplans) )
							{
								$i = 0;
								foreach ( $floorplans as $floorplan )
								{
									$media_file_name = $floorplan;
									$media_folder = dirname( $this->target_file );

					            	// Get file
					            	$got_file = false;
					            	foreach ( $image_ftp_dirs as $image_ftp_dir )
					            	{
					            		if ( !is_array(ftp_nlist($ftp_conn, ".")) )
					            		{
					            			// Oops. FTP connection has disappeared. Re-open it
					            			$this->log_error( 'FTP connection was not available. Re-attempting to connect' );

					            			$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
											if ( $ftp_conn === null)
											{
												$this->log_error( 'Failed to connect to FTP' );
												return false;
											}
					            		}

					            		if ( ftp_get( $ftp_conn, $media_folder . '/' . $media_file_name, $image_ftp_dir . '/' . $media_file_name, FTP_BINARY ) )
						            	{
						            		$got_file = true;
						            		break;
						            	}
					            	}
					            	
					            	if ( $got_file )
					            	{
										$description = '';

										if ( file_exists( $media_folder . '/' . $media_file_name ) )
										{
											$upload = true;
			                                $replacing_attachment_id = '';
			                                if ( isset($previous_media_ids[$i]) ) 
			                                {                                    
			                                    // get this attachment
			                                    $current_image_path = get_post_meta( $previous_media_ids[$i], '_imported_path', TRUE );
			                                    $current_image_size = filesize( $current_image_path );
			                                    
			                                    if ($current_image_size > 0 && $current_image_size !== FALSE)
			                                    {
			                                        $replacing_attachment_id = $previous_media_ids[$i];
			                                        
			                                        $new_image_size = filesize( $media_folder . '/' . $media_file_name );
			                                        
			                                        if ($new_image_size > 0 && $new_image_size !== FALSE)
			                                        {
			                                            if ($current_image_size == $new_image_size)
			                                            {
			                                                $upload = false;
			                                            }
			                                            else
			                                            {
			                                                
			                                            }
			                                        }
			                                        else
				                                    {
				                                    	$this->log_error( 'Failed to get filesize of new image file ' . $media_folder . '/' . $media_file_name, $post_id, $property[0] );
				                                    }
			                                        
			                                        unset($new_image_size);
			                                    }
			                                    else
			                                    {
			                                    	$this->log_error( 'Failed to get filesize of existing image file ' . $current_image_path, $post_id, $property[0] );
			                                    }
			                                    
			                                    unset($current_image_size);
			                                }

			                                if ($upload)
			                                {
			                                	$description = ( $description != '' ) ? $description : preg_replace('/\.[^.]+$/', '', trim($media_file_name, '_'));

												if ( $media_processing !== 'background' ) {
													// We've physically received the file
													$upload = wp_upload_bits(trim($media_file_name, '_'), null, file_get_contents($media_folder . '/' . $media_file_name));  
					                                
					                                if( isset($upload['error']) && $upload['error'] !== FALSE )
					                                {
					                                	$this->log_error( print_r($upload['error'], TRUE), $post_id, $property['AGENT_REF'] );
					                                }
					                                else
					                                {
					                                	// We don't already have a thumbnail and we're presented with an image
				                                        $wp_filetype = wp_check_filetype( $upload['file'], null );
				                                    
				                                        $attachment = array(
				                                             //'guid' => $wp_upload_dir['url'] . '/' . trim($media_file_name, '_'), 
				                                             'post_mime_type' => $wp_filetype['type'],
				                                             'post_title' => $description,
				                                             'post_content' => '',
				                                             'post_status' => 'inherit'
				                                        );
				                                        $attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
				                                        
				                                        if ( $attach_id === FALSE || $attach_id == 0 )
				                                        {    
				                                        	$this->log_error( 'Failed inserting floorplan attachment ' . $upload['file'] . ' - ' . print_r($attachment, TRUE), $post_id, $property[0] );
				                                        }
				                                        else
				                                        {  
					                                        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
					                                        wp_update_attachment_metadata( $attach_id,  $attach_data );

						                                	update_post_meta( $attach_id, '_imported_path', $upload['file']);

						                                	$media_ids[] = $attach_id;

						                                	++$new;
						                                }
					                                }

					                                unlink($media_folder . '/' . $media_file_name);
					                            } else {
													$file_data = array(
														'name' => trim($media_file_name, '_'),
														'path' => $media_folder . '/' . $media_file_name,
													);
													$this->add_media_to_download_queue($post_id, serialize($file_data), 'floorplans', $floorplanCount, $description);
													++$queued;
												}
				                            }
				                            else
				                            {
				                            	if ( isset($previous_media_ids[$i]) ) 
			                                	{
			                                		$media_ids[] = $previous_media_ids[$i];

			                                		++$existing;
			                                	}

			                                	unlink($media_folder . '/' . $media_file_name);
				                            }
										}
										else
										{
											if ( isset($previous_media_ids[$i]) ) 
					                    	{
					                    		$media_ids[] = $previous_media_ids[$i];

					                    		++$existing;
					                    	}
										}
									}
									else
									{
										if ( isset($previous_media_ids[$i]) ) 
				                    	{
				                    		$media_ids[] = $previous_media_ids[$i];

				                    		++$existing;
				                    	}

										$this->log_error( 'Failed to get file ' . $image_ftp_dir . '/' . $media_file_name . ' as ' . $media_folder . '/' . $media_file_name, $post_id, $property[0] );
									}

									++$floorplanCount;
									++$i;
								}
							}
							update_post_meta( $post_id, '_floorplans', $media_ids );

							// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
							if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
							{
								foreach ( $previous_media_ids as $previous_media_id )
								{
									if ( !in_array($previous_media_id, $media_ids) )
									{
										if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
										{
											++$deleted;
										}
									}
								}
							}

							$this->log( 'Imported ' . count($media_ids) . ' floorplans (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, $property[0] );
							if ($queued > 0) {
								$this->log( $queued . ' floorplans added to download queue', $post_id, $property[0] );
							}
						}
					}

					// Media - Brochures
					if ( get_option('propertyhive_brochures_stored_as', '') == 'urls' )
	    			{
	    				$media_urls = array();

						update_post_meta( $post_id, '_brochure_urls', $media_urls );

						$this->log( 'Imported ' . count($media_urls) . ' brochure URLs', $post_id, $property[0] );
	    			}
	    			else
	    			{
						$media_ids = array();
						$new = 0;
						$existing = 0;
						$deleted = 0;
						$queued = 0;
						$brochureCount = 0;
						$previous_media_ids = get_post_meta( $post_id, '_brochures', TRUE );

						if ($property[237] != '')
						{
				            if ( !is_array(ftp_nlist($ftp_conn, ".")) )
		            		{
		            			// Oops. FTP connection has disappeared. Re-open it
		            			$this->log_error( 'FTP connection was not available. Re-attempting to connect' );

		            			$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
								if ( $ftp_conn === null)
								{
									$this->log_error( 'Failed to connect to FTP' );
									return false;
								}
		            		}

							$media_file_name = $property[237];
							$media_folder = dirname( $this->target_file );

			            	// Get file
			            	if ( ftp_get( $ftp_conn, $media_folder . '/' . $media_file_name, $import_settings['brochure_ftp_dir'] . '/' . $media_file_name, FTP_BINARY ) )
			            	{	
			            		$i = 0;

								$description = '';

								if ( file_exists( $media_folder . '/' . $media_file_name ) )
								{
									$upload = true;
		                            $replacing_attachment_id = '';
		                            if ( isset($previous_media_ids[$i]) ) 
		                            {                                    
		                                // get this attachment
		                                $current_image_path = get_post_meta( $previous_media_ids[$i], '_imported_path', TRUE );
		                                $current_image_size = filesize( $current_image_path );
		                                
		                                if ($current_image_size > 0 && $current_image_size !== FALSE)
		                                {
		                                    $replacing_attachment_id = $previous_media_ids[$i];
		                                    
		                                    $new_image_size = filesize( $media_folder . '/' . $media_file_name );
		                                    
		                                    if ($new_image_size > 0 && $new_image_size !== FALSE)
		                                    {
		                                        if ($current_image_size == $new_image_size)
		                                        {
		                                            $upload = false;
		                                        }
		                                        else
		                                        {
		                                            
		                                        }
		                                    }
		                                    else
		                                    {
		                                    	$this->log_error( 'Failed to get filesize of new image file ' . $media_folder . '/' . $media_file_name, $post_id, $property[0] );
		                                    }
		                                    
		                                    unset($new_image_size);
		                                }
		                                else
		                                {
		                                	$this->log_error( 'Failed to get filesize of existing image file ' . $current_image_path, $post_id, $property[0] );
		                                }
		                                
		                                unset($current_image_size);
		                            }

		                            if ($upload)
		                            {
		                            	$description = ( $description != '' ) ? $description : preg_replace('/\.[^.]+$/', '', trim($media_file_name, '_'));

										if ( $media_processing !== 'background' ) {
											// We've physically received the file
											$upload = wp_upload_bits(trim($media_file_name, '_'), null, file_get_contents($media_folder . '/' . $media_file_name));  
			                                
			                                if( isset($upload['error']) && $upload['error'] !== FALSE )
			                                {
			                                	$this->log_error( print_r($upload['error'], TRUE), $post_id, $property['AGENT_REF'] );
			                                }
			                                else
			                                {
			                                	// We don't already have a thumbnail and we're presented with an image
			                                    $wp_filetype = wp_check_filetype( $upload['file'], null );
			                                
			                                    $attachment = array(
			                                         //'guid' => $wp_upload_dir['url'] . '/' . trim($media_file_name, '_'), 
			                                         'post_mime_type' => $wp_filetype['type'],
			                                         'post_title' => $description,
			                                         'post_content' => '',
			                                         'post_status' => 'inherit'
			                                    );
			                                    $attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
			                                    
			                                    if ( $attach_id === FALSE || $attach_id == 0 )
			                                    {    
			                                    	$this->log_error( 'Failed inserting floorplan attachment ' . $upload['file'] . ' - ' . print_r($attachment, TRUE), $post_id, $property[0] );
			                                    }
			                                    else
			                                    {  
			                                        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
			                                        wp_update_attachment_metadata( $attach_id,  $attach_data );

				                                	update_post_meta( $attach_id, '_imported_path', $upload['file']);

				                                	$media_ids[] = $attach_id;

				                                	++$new;
				                                }
			                                }

			                                unlink($media_folder . '/' . $media_file_name);
			                            } else {
											$file_data = array(
												'name' => trim($media_file_name, '_'),
												'path' => $media_folder . '/' . $media_file_name,
											);
											$this->add_media_to_download_queue($post_id, serialize($file_data), 'brochures', $brochureCount, $description);
											++$queued;
										}
		                            }
		                            else
		                            {
		                            	if ( isset($previous_media_ids[$i]) ) 
		                            	{
		                            		$media_ids[] = $previous_media_ids[$i];

		                            		++$existing;
		                            	}

		                            	unlink($media_folder . '/' . $media_file_name);
		                            }
								}
								else
								{
									if ( isset($previous_media_ids[$i]) ) 
			                    	{
			                    		$media_ids[] = $previous_media_ids[$i];

			                    		++$existing;
			                    	}
								}
							}
							else
							{
								$this->log_error( 'Failed to get file ' . $import_settings['brochure_ftp_dir'] . '/' . $media_file_name . ' as ' . $media_folder . '/' . $media_file_name, $post_id, $property[0] );
							}

							++$brochureCount;
						}

						update_post_meta( $post_id, '_brochures', $media_ids );

						// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
						if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
						{
							foreach ( $previous_media_ids as $previous_media_id )
							{
								if ( !in_array($previous_media_id, $media_ids) )
								{
									if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
									{
										++$deleted;
									}
								}
							}
						}

						$this->log( 'Imported ' . count($media_ids) . ' brochures (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, $property[0] );
						if ($queued > 0) {
							$this->log( $queued . ' brochures added to download queue', $post_id, $property[0] );
						}
					}

					// Media - EPCs
					if ( get_option('propertyhive_epcs_stored_as', '') == 'urls' )
	    			{
	    				$media_urls = array();

	    				if ( 
							substr( strtolower($property[269]), 0, 2 ) == '//' || 
							substr( strtolower($property[269]), 0, 4 ) == 'http'
						)
						{
							// This is a URL
							$url = str_replace(" ", "%20", (string)$property->epc);

							$media_urls[] = array('url' => $url);
						}
						if ( 
							(!empty($property[257]) && !empty($property[258])) ||
							(!empty($property[259]) && !empty($property[260]))
						)
						{
							// We've received EER and EIR numbers. 
							// This is a URL
							$url = 'https://www2.housescape.org.uk/cgi-bin/epc.aspx?epc1=' . $property[257] . '&epc2=' . $property[258] . '&epc3=' . $property[259] . '&epc4=' . $property[260];
							
							$media_urls[] = array('url' => $url);
						}

						update_post_meta( $post_id, '_epc_urls', $media_urls );

						$this->log( 'Imported ' . count($media_urls) . ' EPC URLs', $post_id, $property[0] );
	    			}
	    			else
	    			{
						$media_ids = array();
						$new = 0;
						$existing = 0;
						$deleted = 0;
						$epcCount = 0;
						$queued = 0;
						$previous_media_ids = get_post_meta( $post_id, '_epcs', TRUE );

						if ( 
							substr( strtolower($property[269]), 0, 2 ) == '//' || 
							substr( strtolower($property[269]), 0, 4 ) == 'http'
						)
						{
							// This is a URL
							$url = str_replace(" ", "%20", (string)$property->epc);
							$description = 'EPC';
						    
							$filename = basename( $url );

							// Check, based on the URL, whether we have previously imported this media
							$imported_previously = false;
							$imported_previously_id = '';
							if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
							{
								foreach ( $previous_media_ids as $previous_media_id )
								{
									$previous_url = get_post_meta( $previous_media_id, '_imported_url', TRUE );

									if ( $previous_url == $url )
									{
										$imported_previously = true;
										$imported_previously_id = $previous_media_id;
										break;
									}
								}
							}
							
							if ($imported_previously)
							{
								$media_ids[] = $imported_previously_id;

								if ( $description != '' )
								{
									$my_post = array(
								    	'ID'          	 => $imported_previously_id,
								    	'post_title'     => $description,
								    );

								 	// Update the post into the database
								    wp_update_post( $my_post );
								}

								++$existing;
							}
							else
							{
								if ( $media_processing !== 'background' ) {
								    $tmp = download_url( $url );
								    $file_array = array(
								        'name' => $filename,
								        'tmp_name' => $tmp
								    );

								    // Check for download errors
								    if ( is_wp_error( $tmp ) ) 
								    {
								        $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), $post_id, $property[0] );
								    }
								    else
								    {
									    $id = media_handle_sideload( $file_array, $post_id, $description );

									    // Check for handle sideload errors.
									    if ( is_wp_error( $id ) ) 
									    {
									        @unlink( $file_array['tmp_name'] );
									        
									        $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), $post_id, $property[0] );
									    }
									    else
									    {
									    	$media_ids[] = $id;

									    	update_post_meta( $id, '_imported_url', $url);

									    	++$new;
									    }
									}
								} else {
									$this->add_media_to_download_queue($post_id, $url, 'epcs', $epcCount, $description, $url);
									++$queued;
								}
							}
							++$epcCount;
						}

						if ( 
							(!empty($property[257]) && !empty($property[258])) ||
							(!empty($property[259]) && !empty($property[260]))
						)
						{
							// We've received EER and EIR numbers. 
							// This is a URL
							$url = 'https://www2.housescape.org.uk/cgi-bin/epc.aspx?epc1=' . $property[257] . '&epc2=' . $property[258] . '&epc3=' . $property[259] . '&epc4=' . $property[260];
							$description = 'EPC';
						    
							$filename = basename( $url );

							// Check, based on the URL, whether we have previously imported this media
							$imported_previously = false;
							$imported_previously_id = '';
							if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
							{
								foreach ( $previous_media_ids as $previous_media_id )
								{
									$previous_url = get_post_meta( $previous_media_id, '_imported_url', TRUE );

									if ( $previous_url == $url )
									{
										$imported_previously = true;
										$imported_previously_id = $previous_media_id;
										break;
									}
								}
							}
							
							if ($imported_previously)
							{
								$media_ids[] = $imported_previously_id;

								if ( $description != '' )
								{
									$my_post = array(
								    	'ID'          	 => $imported_previously_id,
								    	'post_title'     => $description,
								    );

								 	// Update the post into the database
								    wp_update_post( $my_post );
								}

								++$existing;
							}
							else
							{
								if ( $media_processing !== 'background' ) {
								    $tmp = download_url( $url );
								    $file_array = array(
								        'name' => $filename . '.jpg',
								        'tmp_name' => $tmp
								    );

								    // Check for download errors
								    if ( is_wp_error( $tmp ) ) 
								    {
								        $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), $post_id, $property[0] );
								    }
								    else
								    {
									    $id = media_handle_sideload( $file_array, $post_id, $description );

									    // Check for handle sideload errors.
									    if ( is_wp_error( $id ) ) 
									    {
									        @unlink( $file_array['tmp_name'] );
									        
									        $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), $post_id, $property[0] );
									    }
									    else
									    {
									    	$media_ids[] = $id;

									    	update_post_meta( $id, '_imported_url', $url);

									    	++$new;
									    }
									}
								} else {
									$this->add_media_to_download_queue($post_id, $url, 'epcs', $epcCount, $description, $url);
									++$queued;
								}
							}
							++$epcCount;
						}

						update_post_meta( $post_id, '_epcs', $media_ids );

						// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
						if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
						{
							foreach ( $previous_media_ids as $previous_media_id )
							{
								if ( !in_array($previous_media_id, $media_ids) )
								{
									if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
									{
										++$deleted;
									}
								}
							}
						}

						$this->log( 'Imported ' . count($media_ids) . ' epcs (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, $property[0] );
						if ($queued > 0) {
							$this->log( $queued . ' epcs added to download queue', $post_id, $property[0] );
						}
					}

					// Media - Virtual Tours
					$virtual_tours = array();
					for ($i = 245; $i <= 247; ++$i)
					{
						if (
							$property[$i] != '' &&
							(
								strpos(strtolower($property[$i]), 'yout') !== FALSE ||
								strpos(strtolower($property[$i]), 'vimeo') !== FALSE ||
								strpos(strtolower($property[$i]), 'matterport') !== FALSE ||
								strpos(strtolower($property[$i]), 'tour') !== FALSE ||
								strpos(strtolower($property[$i]), '360') !== FALSE
							)
						) 
						{ 
							$virtual_tours[] = array(
								'url' => $property[$i],
								'label' => $property[$i - 3]
							); 
						}
					}

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour['url'] );
	                	update_post_meta( $post_id, '_virtual_tour_label_' . $i, (string)$virtual_tour['label'] );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property[0] );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property[0] );
				}
				
				update_post_meta( $post_id, '_thesaurus_update_date_' . $this->import_id, $property[204] );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_thesaurus", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property[0], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		unlink( $this->target_file );

		do_action( "propertyhive_post_import_properties_thesaurus" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property[0];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'S' => 'For Sale',
                '1' => 'Sold',
                '2' => 'Exchanged',
                '3' => 'Withdrawn',
                '4' => 'Under Offer',
                '5' => 'Sold STC',
                'A' => 'Archived',
                'D' => 'Draft',
            ),
            'lettings_availability' => array(
                'L' => 'To Let',
                '6' => 'Under Offer',
                '7' => 'Rented',
                'A' => 'Archived',
                'F' => 'Draft',
                'W' => 'Withdrawn',
            ),
            'property_type' => array(
                'Terraced' => 'Terraced',
                'Mews' => 'Mews',
                'Semi' => 'Semi',
                'Detached' => 'Detached',
                'Bungalow' => 'Bungalow',
                'Flat' => 'Flat',
                'Maisonette' => 'Maisonette',
                'Commercial' => 'Commercial',
                'Land' => 'Land',
            ),
            'price_qualifier' => array(
                'offers around' => 'offers around',
                'offer over' => 'offer over',
                'fixed price' => 'fixed price',
                'offers invited' => 'offers invited',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
                'Feudal' => 'Feudal',
                'Commonhold' => 'Commonhold',
                'Share of Freehold' => 'Share of Freehold',
            ),
            'furnished' => array(
                'Y' => 'Yes',
                'N' => 'No',
            ),
        );
	}
}

}