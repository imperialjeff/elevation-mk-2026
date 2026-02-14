<?php
/**
 * Class for managing the import process of a SME Professional JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_SME_Professional_JSON_Import extends PH_Property_Import_Process {

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

		$this->log("Parsing properties");

		$departments = array( 'residential-sales', 'residential-lettings' );
		$departments = apply_filters( 'propertyhive_sme_professional_json_departments', $departments, $this->import_id );

		$branch_ids = array( '' );
		$branch_ids = apply_filters( 'propertyhive_sme_professional_json_branch_ids', $branch_ids, $this->import_id );

		$limit = $this->get_property_limit();

		foreach ( $branch_ids as $branch_id )
		{
			// Lettings properties
			if ( in_array('residential-lettings', $departments) )
			{
				$response = wp_remote_get(
					'https://home.smelogin.co.uk/CustomerData/' . $import_settings['company_id'] . '/GeneratedDocuments/Marketing/waas/all_marketed_properties' . $branch_id . '.json',
					array( 'timeout' => 120 )
				);

				if ( !is_wp_error($response) && is_array( $response ) ) 
				{
					if ( isset($response['response']['code']) && $response['response']['code'] == 404 )
					{
						// do nothing. This scenario is fine
					}
					else
					{
						$contents = $response['body'];

						$properties_json = json_decode( $contents, TRUE );

						if ($properties_json !== FALSE && !is_null($properties_json))
						{
							$this->log("Found " . count($properties_json) . " lettings properties in JSON ready for parsing");

							foreach ($properties_json as $id => $property)
							{
								if ( $test === true )
								{
									$this->properties[] = $property;
								}
								else
								{
									if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
						                {
						                    return true;
						                }

									$response = wp_remote_get(
										$property['file'],
										array( 'timeout' => 120 )
									);

									if ( !is_wp_error($response) && is_array( $response ) ) 
									{
										$property_contents = $response['body'];

										$property_json = json_decode( $property_contents, TRUE );

										if ($property_json !== FALSE && !is_null($property_json))
										{
											$property_json['property']['department'] = 'residential-lettings';
											$this->properties[] = $property_json['property'];
								        }
								        else
								        {
								        	// Failed to parse JSON
								        	$this->log_error( 'Failed to parse letting property JSON file: ' . print_r($property_contents, true) );
								        	return false;
								        }
									}
									else
									{

										$this->log_error( 'Failed to obtain letting property response: ' . print_r($response, TRUE) );
										return false;
									}
								}
							}
				        }
				        else
				        {
				        	// Failed to parse JSON
				        	$this->log_error( 'Failed to parse lettings JSON file: ' . print_r($contents, true) );
				        	return false;
				        }
			        }
				}
				else
				{

					$this->log_error( 'Failed to obtain lettings response: ' . print_r($response, TRUE) );
					return false;
				}
			}

			// Sales properties
			if ( in_array('residential-sales', $departments) )
			{
				$response = wp_remote_get(
					'https://home.smelogin.co.uk/CustomerData/' . $import_settings['company_id'] . '/GeneratedDocuments/Marketing/waas_s/all_marketed_properties' . $branch_id . '.json',
					array( 'timeout' => 120 )
				);

				if ( !is_wp_error($response) && is_array( $response ) ) 
				{
					if ( isset($response['response']['code']) && $response['response']['code'] == 404 )
					{
						// do nothing. This scenario is fine
					}
					else
					{
						$contents = $response['body'];

						$properties_json = json_decode( $contents, TRUE );

						if ($properties_json !== FALSE && !is_null($properties_json))
						{
							$this->log("Found " . count($properties_json) . " sales properties in JSON ready for parsing");

							foreach ($properties_json as $property)
							{
								if ( $test === true )
								{
									$this->properties[] = $property;
								}
								else
								{
									if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
					                {
					                    return true;
					                }

									$response = wp_remote_get(
										$property['file'],
										array( 'timeout' => 120 )
									);

									if ( !is_wp_error($response) && is_array( $response ) ) 
									{
										$property_contents = $response['body'];

										$property_json = json_decode( $property_contents, TRUE );

										if ($property_json !== FALSE && !is_null($property_json))
										{
											$property_json['property']['department'] = 'residential-sales';
											$this->properties[] = $property_json['property'];
								        }
								        else
								        {
								        	// Failed to parse JSON
								        	$this->log_error( 'Failed to parse sales property JSON file: ' . print_($property_contents, true) );
								        	return false;
								        }
									}
									else
									{

										$this->log_error( 'Failed to obtain sales property response: ' . print_r($response, TRUE) );
										return false;
									}
								}
							}
				        }
				        else
				        {
				        	// Failed to parse JSON
				        	$this->log_error( 'Failed to parse sales JSON file: ' . print_r($contents, true) );
				        	return false;
				        }
				    }
				}
				else
				{

					$this->log_error( 'Failed to obtain sales response: ' . print_r($response, TRUE) );
					return false;
				}
			}
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

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_sme_professional_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_sme_professional_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_sme_professional_json", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['id'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['id'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['id'], false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['id'], 0, $property['id'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = '';
	        if ( isset($property['address']['display_address']) && trim($property['address']['display_address']) != '' )
	        {
	        	$display_address = trim($property['address']['display_address']);
	        }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $property['details']['summary'] );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['id'] );

				update_post_meta( $post_id, $imported_ref_key, $property['id'] );

				update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

				$previous_update_date = get_post_meta( $post_id, '_sme_professional_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['update_date']) ||
						(
							isset($property['update_date']) &&
							empty($property['update_date'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['update_date']) &&
							$property['update_date'] != '' &&
							$previous_update_date != '' &&
							strtotime($property['update_date']) > strtotime($previous_update_date)
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

				if ( !$skip_property )
				{
					// Address
					update_post_meta( $post_id, '_reference_number', $property['id'] );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['address']['house_name_number']) ) ? $property['address']['house_name_number'] : '' ) . ' ' . ( ( isset($property['address']['saon']) ) ? $property['address']['saon'] : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['address_2']) ) ? $property['address']['address_2'] : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['address_3']) ) ? $property['address']['address_3'] : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['town']) ) ? $property['address']['town'] : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['address_4']) ) ? $property['address']['address_4'] : '' ) );
					update_post_meta( $post_id, '_address_postcode', trim( ( ( isset($property['address']['postcode_1']) ) ? $property['address']['postcode_1'] : '' ) . ' ' . ( ( isset($property['address']['postcode_2']) ) ? $property['address']['postcode_2'] : '' ) ) );

					$country = get_option( 'propertyhive_default_country', 'GB' );
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_sme_professional_json_address_fields_to_check', array('address_2', 'address_3', 'address_4', 'town') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property['address'][$address_field]) && trim($property['address'][$address_field]) != '' ) 
						{
							$term = term_exists( trim($property['address'][$address_field]), 'location');
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

					// Coordinates
					if ( isset($property['address']['latitude']) && isset($property['address']['longitude']) && $property['address']['latitude'] != '' && $property['address']['longitude'] != '' && $property['address']['latitude'] != '0' && $property['address']['longitude'] != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property['address']['latitude']) ) ? $property['address']['latitude'] : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property['address']['longitude']) ) ? $property['address']['longitude'] : '' ) );
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
							if ( isset($property['address']['street']) && trim($property['address']['street']) != '' ) { $address_to_geocode[] = $property['address']['street']; }
							if ( isset($property['address']['locality']) && trim($property['address']['locality']) != '' ) { $address_to_geocode[] = $property['address']['locality']; }
							if ( isset($property['address']['town']) && trim($property['address']['town']) != '' ) { $address_to_geocode[] = $property['address']['town']; }
							if ( isset($property['address']['county']) && trim($property['address']['county']) != '' ) { $address_to_geocode[] = $property['address']['county']; }
							if ( isset($property['address']['postcode']) && trim($property['address']['postcode']) != '' ) { $address_to_geocode[] = $property['address']['postcode']; $address_to_geocode_osm[] = $property['address']['postcode']; }

							$return = $this->do_geocoding_lookup( $post_id, $property['id'], $address_to_geocode, $address_to_geocode_osm, $country );
						}
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
							$explode_branch_codes = explode(",", $branch_code);
							$explode_branch_codes = array_map('trim', $explode_branch_codes);
							foreach ( $explode_branch_codes as $branch_code )
							{
								if ( $branch_code == $property['branch'] )
								{
									$office_id = $ph_office_id;
									break;
								}
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = $property['department'];

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['branch'] . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['branch'] . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['branch'] . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['branch']]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['branch']] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['branch']]);
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

					update_post_meta( $post_id, '_department', $department );
					
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property['details']['bedrooms']) ) ? $property['details']['bedrooms'] : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property['details']['bathrooms']) ) ? $property['details']['bathrooms'] : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['details']['reception_rooms']) ) ? $property['details']['reception_rooms'] : '' ) );

					update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['price_information']['council_tax_band']) ) ? $property['price_information']['council_tax_band'] : '' ) );

					$prefix = '';
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property['property_type']) )
					{
						if ( !empty($mapping) && isset($mapping[$property['property_type']]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[$property['property_type']], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . $property['property_type'] . ') that is not mapped', $post_id, $property['id'] );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['property_type'], $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					if ( $department == 'residential-sales' )
					{
						// Clean price
						$price = round(preg_replace("/[^0-9.]/", '', $property['price_information']['price']));

						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );
						update_post_meta( $post_id, '_poa', ( ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == '1' ) ? 'yes' : '') );
						update_post_meta( $post_id, '_currency', 'GBP' );
						
						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						if ( !empty($mapping) && isset($property['price_information']['price_qualifier']) && isset($mapping[$property['price_information']['price_qualifier']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['price_information']['price_qualifier']], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

			            // Tenure
			            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

						if ( !empty($mapping) && isset($property['price_information']['tenure_type']) && isset($mapping[$property['price_information']['tenure_type']]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property['price_information']['tenure_type']], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = round(preg_replace("/[^0-9.]/", '', $property['price_information']['price']));

						update_post_meta( $post_id, '_rent', $price );
						
						$rent_frequency = 'pcm';
						$price_actual = $price;
						switch ((string)$property->rentFrequency)
						{
							case 52: { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
							case 4: { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
							case 1: { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', $price_actual );

						update_post_meta( $post_id, '_poa', ( ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == '1' ) ? 'yes' : '') );
						update_post_meta( $post_id, '_currency', 'GBP' );

						update_post_meta( $post_id, '_deposit', ( isset($property['price_information']['deposit']) ? $property['price_information']['deposit'] : '' ) );
		            	update_post_meta( $post_id, '_available_date', ( ( isset($property['date_available']) && $property['date_available'] != '' ) ? date("Y-m-d", strtotime($property['date_available'])) : '' ) );

						// Furnished
			            $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

						if ( !empty($mapping) && isset($property['details']['furnished_type']) && isset($mapping[$property['details']['furnished_type']]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property['details']['furnished_type']], 'furnished' );
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
						update_post_meta( $post_id, '_featured', isset($property['details']['featured']) && $property['details']['featured'] === true ? 'yes' : '' );
					}
				
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : array();

					if ( !empty($mapping) && isset($property['status']) && isset($mapping[$property['status']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['status']], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					if ( isset($property['details']['features']) && is_array($property['details']['features']) && !empty($property['details']['features']) )
					{
						foreach ( $property['details']['features'] as $feature )
						{
							$features[] = trim($feature);
						}
					}
					update_post_meta( $post_id, '_features', count( $features ) );
	        		
	        		$i = 0;
			        foreach ( $features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }

			        // Rooms / Descriptions
			        // For now put the whole description in one room / description
					update_post_meta( $post_id, '_rooms', '1' );
					update_post_meta( $post_id, '_room_name_0', '' );
		            update_post_meta( $post_id, '_room_dimensions_0', '' );
		            update_post_meta( $post_id, '_room_description_0', $property['details']['description'] );
					
		            // Media - Images
				    $media = array();
				    if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
					{
						foreach ( $property['media'] as $image )
						{
							if ( isset($image['media_type']) && $image['media_type'] == '1' )
							{
								$url = $image['media_url'];

								$explode_url = explode('?', $url);
								$filename = basename( $explode_url[0] );

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
					{
						foreach ( $property['media'] as $image )
						{
							if ( isset($image['media_type']) && $image['media_type'] == '2' )
							{
								$url = $image['media_url'];

								$explode_url = explode('?', $url);
								$filename = basename( $explode_url[0] );

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
					{
						foreach ( $property['media'] as $image )
						{
							if ( isset($image['media_type']) && $image['media_type'] == '3' )
							{
								$url = $image['media_url'];

								$explode_url = explode('?', $url);
								$filename = basename( $explode_url[0] );

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
					{
						foreach ( $property['media'] as $image )
						{
							if ( isset($image['media_type']) && ( $image['media_type'] == '6' || $image['media_type'] == '7' ) )
							{
								$url = $image['media_url'];

								$explode_url = explode('?', $url);
								$filename = basename( $explode_url[0] );

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'epc', $media, false );

					// Media - Virtual Tours
					$virtual_tours = array();
					if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
					{
						foreach ( $property['media'] as $image )
						{
							if ( 
								isset($image['media_url']) && $image['media_url'] != ''
								&&
								(
									substr( strtolower($image['media_url']), 0, 2 ) == '//' || 
									substr( strtolower($image['media_url']), 0, 4 ) == 'http'
								)
								&&
								isset($image['media_type']) && $image['media_type'] == '4'
							)
							{
								// This is a URL
								$url = $image['media_url'];

								$virtual_tours[] = $url;
							}
						}
					}

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ( $virtual_tours as $i => $virtual_tour )
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['update_date']) ) { update_post_meta( $post_id, '_sme_professional_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['update_date'])) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_sme_professional_json", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_sme_professional_json" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['id'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                '1' => 'Available',
                '2' => 'SSTC',
                '3' => 'SSTCM',
                '4' => 'Under Offer',
            ),
            'lettings_availability' => array(
                '1' => 'Available',
                '5' => 'Reserved',
                '6' => 'Let Agreed',
            ),
            'property_type' => array(
                '0' => 'Not Specified',
                '1' => 'Terraced House',
                '2' => 'End of terrace house',
                '3' => 'Semi-detached house',
                '4' => 'Detached house',
                '5' => 'Mews house',
                '6' => 'Cluster house',
                '7' => 'Ground floor flat',
                '8' => 'Flat',
                '9' => 'Studio flat',
                '10' => 'Ground floor maisonette',
                '11' => 'Maisonette',
                '12' => 'Bungalow',
                '13' => 'Terraced bungalow',
                '14' => 'Semi-detached bungalow',
                '15' => 'Detached bungalow',
                '16' => 'Mobile home',
                '20' => 'Land (Residential)',
                '21' => 'Link detached house',
                '22' => 'Town house',
                '23' => 'Cottage',
                '24' => 'Chalet',
                '25' => 'Character Property',
                '26' => 'House (unspecified)',
                '27' => 'Villa',
                '28' => 'Apartment',
                '29' => 'Penthouse',
                '30' => 'Finca',
                '43' => 'Barn Conversion',
                '44' => 'Serviced apartment',
                '45' => 'Parking',
                '46' => 'Sheltered Housing',
                '47' => 'Retirement property',
                '48' => 'House share',
                '49' => 'Flat share',
                '50' => 'Park home',
                '51' => 'Garages',
                '52' => 'Farm House',
                '53' => 'Equestrian facility',
                '56' => 'Duplex',
                '59' => 'Triplex',
                '62' => 'Longere',
                '65' => 'Gite',
                '68' => 'Barn',
                '71' => 'Trulli',
                '74' => 'Mill',
                '77' => 'Ruins',
                '80' => 'Restaurant',
                '83' => 'Cafe',
                '86' => 'Mill',
                '92' => 'Castle',
                '95' => 'Village House',
                '101' => 'Cave House',
                '104' => 'Cortijo',
                '107' => 'Farm Land',
                '113' => 'Country House',
                '117' => 'Caravan',
                '118' => 'Lodge',
                '119' => 'Log Cabin',
                '120' => 'Manor House',
                '121' => 'Stately Home',
                '125' => 'Off-Plan',
                '128' => 'Semi-detached Villa',
                '131' => 'Detached Villa',
                '134' => 'Bar/Nightclub',
                '137' => 'Shop',
                '140' => 'Riad',
                '141' => 'House Boat',
                '142' => 'Hotel Room',
                '143' => 'Block of Apartments',
                '144' => 'Private Halls',
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
                '214' => 'Workshop & Retail Space,',
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
                '298' => 'Takeaway',
                '301' => 'Childcare Facility',
                '304' => 'Smallholding',
                '307' => 'Place of Worship',
                '310' => 'Trade Counter',
                '511' => 'Coach House',
                '512' => 'House of Multiple Occupation',
                '535' => 'Sports facilities',
                '538' => 'Spa',
                '541' => 'Campsite & Holiday Village',
            ),
        );
	}
}

}