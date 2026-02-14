<?php
/**
 * Class for managing the import process of a Loop JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Loop_JSON_Import extends PH_Property_Import_Process {

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

		$limit = $this->get_property_limit();

		// List endpoints for getting both sales and lettings properties
		$loop_endpoints = array(
			'property/residential/sales/listed/100',
			'property/residential/lettings/listed/100',
		);

		$loop_endpoints = apply_filters( 'propertyhive_loop_endpoints', $loop_endpoints );

		foreach ( $loop_endpoints as $loop_endpoint )
		{
			$response = wp_remote_get( 'https://api.loop.software/' . $loop_endpoint, array( 'timeout' => 120, 'headers' => array(
				'Content-Type' => 'application/json',
				'x-api-key' => $import_settings['client_id'],
			) ) );

			if ( !is_wp_error($response) && is_array( $response ) )
			{
				if ( wp_remote_retrieve_response_code($response) !== 200 )
	            {
	                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties: ' . wp_remote_retrieve_response_message($response) );
	                return false;
	            }
	            
				$contents = $response['body'];

				$json = json_decode( $contents, TRUE );

				if ( isset($json['statusCode']) && $json['statusCode'] === 'error') 
				{
			        $this->log_error( 'Error returned for ' . $loop_endpoint . ': ' . print_r($json, TRUE) );
			        return false;
			    }
			    else
			    {
					if ( $json !== FALSE && is_array($json) && isset($json['data']) )
					{
						$this->log("Found " . count($json['data']) . " properties in JSON from " . $loop_endpoint . " ready for parsing");

						foreach ($json['data'] as $property)
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    return true;
			                }

							$this->properties[] = $property;
						}
					}
					else
					{
						// Failed to parse JSON
						$this->log_error( 'Failed to parse JSON file for ' . $loop_endpoint . ': ' . print_r($json, TRUE) );
						return false;
					}
				}
			}
			else
			{
				$this->log_error( 'Failed to obtain JSON from ' . $loop_endpoint . ': ' . print_r($response, TRUE) );
				return false;
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

        do_action( "propertyhive_pre_import_properties_loop_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_loop_json_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$mappings = $this->get_default_mapping_values();
		$commercial_property_types = array();
		if ( isset($mappings['commercial_property_type']) )
		{
			$commercial_property_types = $mappings['commercial_property_type'];
		}

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_loop_json", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['listingId'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['listingId'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['listingId'], false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['listingId'], 0, $property['listingId'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = '';
			if ( isset($property['displayAddress']) && trim($property['displayAddress']) != '' )
	        {
	        	$display_address = trim($property['displayAddress']);
	        }
	        else
	        {
		        $display_address = array();
		        if ( isset($property['address_Street']) && trim($property['address_Street']) != '' )
		        {
		        	$display_address[] = trim($property['address_Street']);
		        }
		        if ( isset($property['address_Locality']) && trim($property['address_Locality']) != '' )
		        {
		        	$display_address[] = trim($property['address_Locality']);
		        }
		        elseif ( isset($property['address_Town']) && trim($property['address_Town']) != '' )
		        {
		        	$display_address[] = trim($property['address_Town']);
		        }
		        elseif ( isset($property['address_District']) && trim($property['address_District']) != '' )
		        {
		        	$display_address[] = trim($property['address_District']);
		        }
		        $display_address = implode(", ", $display_address);
	       	}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['listingId'], $property, $display_address, ( isset($property['shortDescription']) && !is_null($property['shortDescription']) ) ? $property['shortDescription'] : '' );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['listingId'] );

				update_post_meta( $post_id, $imported_ref_key, $property['listingId'] );

				update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

				// Address
				update_post_meta( $post_id, '_reference_number', ( ( isset($property['propertyRefId']) ) ? $property['propertyRefId'] : '' ) );
				update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['address_HouseNameOrNumber']) ) ? $property['address_HouseNameOrNumber'] : '' ) . ' ' . ( ( isset($property['address_HouseSecondaryNameOrNumber']) ) ? $property['address_HouseSecondaryNameOrNumber'] : '' ) ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property['address_Street']) ) ? $property['address_Street'] : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property['address_Locality']) ) ? $property['address_Locality'] : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property['address_Town']) ) ? $property['address_Town'] : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property['address_County']) ) ? $property['address_County'] : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address_Postcode']) ) ? $property['address_Postcode'] : '' ) );

				$country = get_option( 'propertyhive_default_country', 'GB' );
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = apply_filters( 'propertyhive_loop_json_address_fields_to_check', array('address_Locality', 'address_Town', 'address_District', 'address_County') );
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

				// Coordinates
				if ( isset($property['latitude']) && isset($property['longitude']) && $property['latitude'] != '' && $property['longitude'] != '' && $property['latitude'] != '0' && $property['longitude'] != '0' )
				{
					update_post_meta( $post_id, '_latitude', $property['latitude'] );
					update_post_meta( $post_id, '_longitude', $property['longitude'] );
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
						if ( isset($property['address_Street']) && trim($property['address_Street']) != '' ) { $address_to_geocode[] = $property['address_Street']; }
						if ( isset($property['address_Locality']) && trim($property['address_Locality']) != '' ) { $address_to_geocode[] = $property['address_Locality']; }
						if ( isset($property['address_Town']) && trim($property['address_Town']) != '' ) { $address_to_geocode[] = $property['address_Town']; }
						if ( isset($property['address_County']) && trim($property['address_County']) != '' ) { $address_to_geocode[] = $property['address_County']; }
						if ( isset($property['address_Postcode']) && trim($property['address_Postcode']) != '' ) { $address_to_geocode[] = $property['address_Postcode']; $address_to_geocode_osm[] = $property['address_Postcode']; }

						$return = $this->do_geocoding_lookup( $post_id, $property['listingId'], $address_to_geocode, $address_to_geocode_osm, $country );
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
						if ( $branch_code == $import_settings['client_id'] )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$prefix = '';
				$department = isset( $property['price'] ) ? 'residential-sales' : 'residential-lettings';
				if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
				{
					if ( isset($property['propertyType']) && in_array($property['propertyType'], $commercial_property_types) )
					{
						$department = 'commercial';
						$prefix = 'commercial_';
					}
				}
				update_post_meta( $post_id, '_department', $department );

				// Is the property portal add on activated
				if (class_exists('PH_Property_Portal'))
        		{
					// Use the branch code to map this property to the correct agent and branch
					$explode_agent_branch = array();
					if (
						isset($this->branch_mappings[str_replace("residential-", "", $department)][$import_settings['client_id'] . '|' . $this->import_id]) &&
						$this->branch_mappings[str_replace("residential-", "", $department)][$import_settings['client_id'] . '|' . $this->import_id] != ''
					)
					{
						// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
						$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$import_settings['client_id'] . '|' . $this->import_id]);
					}
					elseif (
						isset($this->branch_mappings[str_replace("residential-", "", $department)][$import_settings['client_id']]) &&
						$this->branch_mappings[str_replace("residential-", "", $department)][$import_settings['client_id']] != ''
					)
					{
						// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
						$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$import_settings['client_id']]);
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
				
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property['bedrooms']) ) ? $property['bedrooms'] : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bathrooms']) ) ? $property['bathrooms'] : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['receptionRooms']) ) ? $property['receptionRooms'] : '' ) );

				update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['councilTaxBand']) ) ? $property['councilTaxBand'] : '' ) );

				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property['propertyType']) )
				{
					if ( !empty($mapping) && isset($mapping[$property['propertyType']]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[$property['propertyType']], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . $property['propertyType'] . ') that is not mapped', $post_id, $property['listingId'] );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['propertyType'], $post_id );
					}
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
				}

				// Residential Sales Details
				if ( $department == 'residential-sales' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', $property['price']));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', ( ( isset($property['priceQualifier']) && ( strpos(strtolower($property['priceQualifier']), 'application') !== FALSE || strpos(strtolower($property['priceQualifier']), 'priceOnRequest') !== FALSE || strpos(strtolower($property['priceQualifier']), 'poa') !== FALSE ) ) ? 'yes' : '') );
					update_post_meta( $post_id, '_currency', 'GBP' );
					
					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($property['priceQualifier']) && isset($mapping[$property['priceQualifier']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['priceQualifier']], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }

		            // Tenure
		            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

					if ( !empty($mapping) && isset($property['tenure']) && isset($mapping[$property['tenure']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['tenure']], 'tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'tenure' );
		            }

		            // Sale By
		            $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

					if ( !empty($mapping) && isset($property['saleBy']) && isset($mapping[$property['saleBy']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['saleBy']], 'sale_by' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'sale_by' );
		            }

		            if ( isset($property['tenure']) && $property['tenure'] == 'Leasehold' )
		            {
		            	$ground_rent = '';
		            	if ( isset($property['groundRent']) && !empty($property['groundRent']) ) 
		            	{ 
		            		$ground_rent = $property['groundRent'];
		            	}
		            	update_post_meta( $post_id, '_ground_rent', $ground_rent );
		            	
		            	$service_charge = '';
		            	if ( isset($property['serviceCharge']) && !empty($property['serviceCharge']) ) 
		            	{ 
		            		$service_charge = $property['serviceCharge'];
		            	}
		            	update_post_meta( $post_id, '_service_charge', $service_charge );
		            	
		            	$leasehold_years_remaining = '';
                        if ( isset($property['leaseExpiryDate']) && !empty($property['leaseExpiryDate']) )
                        {
                            $date1 = new DateTime();
                            $date2 = new DateTime($property['leaseExpiryDate']);
                            $interval = $date1->diff($date2);
                            $leasehold_years_remaining = $interval->y;
                        }
                        update_post_meta( $post_id, '_leasehold_years_remaining', $leasehold_years_remaining );
		            }
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', $property['rent']));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					/*switch ($property['rentalType'])
					{
						case "1":
						{
							// RentalType 1 = "OneTime" - Not sure exactly how to deal with this, so setting it to PA
							$rent_frequency = 'pa'; $price_actual = $price / 12; break;
						}
						case "2":
						{
							$rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break;
						}
						case "3":
						{
							$rent_frequency = 'pcm'; $price_actual = $price; break;
						}
					}*/
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					update_post_meta( $post_id, '_currency', 'GBP' );

					update_post_meta( $post_id, '_poa', ( $property['hideRentOnWebsite'] == true ) ? 'yes' : '');

					update_post_meta( $post_id, '_deposit', $property['deposit'] );
					update_post_meta( $post_id, '_available_date', ( $property['dateFirstAvailable'] != '' ) ? date("Y-m-d", strtotime($property['dateFirstAvailable'])) : '' );

					// Furnished
		            $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

					if ( !empty($mapping) && isset($property['furnishings']) && isset($mapping[$property['furnishings']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['furnishings']], 'furnished' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'furnished' );
		            }
				}
				elseif ( $department == 'commercial' )
				{
					update_post_meta( $post_id, '_for_sale', '' );
            		update_post_meta( $post_id, '_to_rent', '' );

					if ( isset( $property['price'] ) )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	                    $price = round(preg_replace("/[^0-9.]/", '', $property['price']));

	                    update_post_meta( $post_id, '_price_from', $price );
	                    update_post_meta( $post_id, '_price_to', $price );

	                    update_post_meta( $post_id, '_price_units', '' );

	                    update_post_meta( $post_id, '_price_poa', ( $property['hideRentOnWebsite'] == true ) ? 'yes' : '' );
					}
					
					if ( isset( $property['rent'] ) )
					{
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	                    $price = round(preg_replace("/[^0-9.]/", '', $property['rent']));

	                    update_post_meta( $post_id, '_rent_from', $price );
	                    update_post_meta( $post_id, '_rent_to', $price );

	                    $rent_frequency = 'pcm';
	                    update_post_meta( $post_id, '_rent_units', $rent_frequency );

	                    update_post_meta( $post_id, '_rent_poa', ( $property['hideRentOnWebsite'] == true ) ? 'yes' : '' );
					}

					// Store price in common currency (GBP) used for ordering
                    $ph_countries = new PH_Countries();
                    $ph_countries->update_property_price_actual( $post_id );

					update_post_meta( $post_id, '_floor_area_from', '' );
					update_post_meta( $post_id, '_floor_area_from_sqft', '' );
					update_post_meta( $post_id, '_floor_area_to', '' );
					update_post_meta( $post_id, '_floor_area_to_sqft', '' );
					update_post_meta( $post_id, '_floor_area_units', 'sqft');

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($property['priceQualifier']) && isset($mapping[$property['priceQualifier']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['priceQualifier']], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }

		            // Tenure
		            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

					if ( !empty($mapping) && isset($property['tenure']) && isset($mapping[$property['tenure']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['tenure']], 'commercial_tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
		            }

		            // Sale By
		            $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

					if ( !empty($mapping) && isset($property['saleBy']) && isset($mapping[$property['saleBy']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['saleBy']], 'sale_by' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'sale_by' );
		            }
				}

				// Parking
                $mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();

                if ( isset($property['parking']) && !empty($property['parking']) )
                {
                    if ( !empty($mapping) && isset($mapping[$property['parking']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['parking']], 'parking' );
                    }
                    else
                    {
                    	wp_delete_object_term_relationships( $post_id, 'parking' );

                        $this->log( 'Property received with a parking (' . $property['parking'] . ') that is not mapped', $post_id, $property['listingId'] );

                        $import_settings = $this->add_missing_mapping( $mapping, 'parking', $property['parking'], $post_id );
                    }
                }
                else
                {
                	wp_delete_object_term_relationships( $post_id, 'parking' );
                }

                // Outside Space
                $mapping = isset($import_settings['mappings']['outside_space']) ? $import_settings['mappings']['outside_space'] : array();

                if ( isset($property['outsideSpace']) && !empty($property['outsideSpace']) )
                {
                    if ( !empty($mapping) && isset($mapping[$property['outsideSpace']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['outsideSpace']], 'outside_space' );
                    }
                    else
                    {
                    	wp_delete_object_term_relationships( $post_id, 'outside_space' );

                        $this->log( 'Property received with an outside_space (' . $property['outsideSpace'] . ') that is not mapped', $post_id, $property['listingId'] );

                        $import_settings = $this->add_missing_mapping( $mapping, 'outside_space', $property['outsideSpace'], $post_id );
                    }
                }
                else
                {
                	wp_delete_object_term_relationships( $post_id, 'outside_space' );
                }

                $departments_with_residential_details = apply_filters( 'propertyhive_departments_with_residential_details', array( 'residential-sales', 'residential-lettings' ) );
				if ( in_array($department, $departments_with_residential_details) )
				{
					// Electricity
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property['materialInformation']['electricitySupply'] ) && !empty( $property['materialInformation']['electricitySupply'] ) ) 
					{
				        $supply_value = $property['materialInformation']['electricitySupply'];
				        switch ( $supply_value ) 
				        {
				            case 'mainsSupply': $utility_type[] = 'mains_supply'; break;
				            case 'privateSupply': $utility_type[] = 'private_supply'; break;
				            case 'solarPVPanels': $utility_type[] = 'solar_pv_panels'; break;
				            case 'windTurbine': $utility_type[] = 'wind_turbine'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_electricity_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_electricity_type_other', $utility_type_other );
					}

                    // Water
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property['materialInformation']['waterSupply'] ) && !empty( $property['materialInformation']['waterSupply'] ) ) 
					{
				        $supply_value = $property['materialInformation']['waterSupply'];
				        switch ( $supply_value ) 
				        {
				            case 'mainsSupply': $utility_type[] = 'mains_supply'; break;
				            case 'privateSupply': $utility_type[] = 'private_supply'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_water_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_water_type_other', $utility_type_other );
					}
					
					// Heating
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property['materialInformation']['heating'] ) && !empty( $property['materialInformation']['heating'] ) ) 
					{
				        $source_value = $property['materialInformation']['heating'];
				        switch ( $source_value ) 
						{
						    case 'lpg': $utility_type[] = 'gas'; break;
						    case 'oil': $utility_type[] = 'oil'; break;
						    case 'groundSource': $utility_type[] = 'ground_source_heat_pump'; break;
						    case 'electric': $utility_type[] = 'electric'; break;
						    case 'biomass': $utility_type[] = 'biomass_boiler'; break;
						    case 'mainsGas': $utility_type[] = 'gas'; break;
						    case 'none': $utility_type[] = 'other'; break;
						    case 'airSource': $utility_type[] = 'air_source_heat_pump'; break;
						    case 'airConditioning': $utility_type[] = 'air_conditioning'; break;
						    case 'central': $utility_type[] = 'central'; break;
						    case 'ecoFriendly': $utility_type[] = 'eco_friendly'; break;
						    case 'gasCentral': $utility_type[] = 'gas_central'; break;
						    case 'nightStorage': $utility_type[] = 'night_storage'; break;
						    case 'solar': $utility_type[] = 'solar'; break;
						    case 'solarWater': $utility_type[] = 'solar_water'; break;
						    case 'underFloor': $utility_type[] = 'under_floor'; break;
						    case 'woodBurner': $utility_type[] = 'wood_burner'; break;
						    case 'openFire': $utility_type[] = 'open_fire'; break;
						    case 'solarPhotovoltaicThermal': $utility_type[] = 'solar_pv_thermal'; break;
						    case 'underfloorHeating': $utility_type[] = 'under_floor'; break;
						    case 'solarThermal': $utility_type[] = 'solar_thermal'; break;
						    default: 
						        $utility_type[] = 'other'; 
						        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
						        break;
						}

					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_heating_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_heating_type_other', $utility_type_other );
					}

					// Broadband
					$utility_type = [];
					$utility_type_other = '';
					if ( 
						isset( $property['materialInformation']['broadband'] ) 
						&&
						!empty( $property['materialInformation']['broadband'] )
					) 
					{
				        $supply_value = $property['materialInformation']['broadband'];
				        switch ( $supply_value ) 
				        {
				        	case 'ads':
				            case 'adsl': $utility_type[] = 'adsl'; break;
				            case 'cable': $utility_type[] = 'cable'; break;
				            case 'fttc': $utility_type[] = 'fttc'; break;
				            case 'fttp': $utility_type[] = 'fttp'; break;
				            case 'none': $utility_type[] = 'none'; break;
				            default: 
						        $utility_type[] = 'other'; 
						        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
						        break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_broadband_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_broadband_type_other', $utility_type_other );
					}

					// Sewerage
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property['materialInformation']['sewerage'] ) && !empty( $property['materialInformation']['sewerage'] ) ) 
					{
				        $supply_value = $property['materialInformation']['sewerage'];
				        switch ( $supply_value ) 
				        {
				            case 'mainsSupply': $utility_type[] = 'mains_supply'; break;
				            case 'privateSupply': $utility_type[] = 'private_supply'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_sewerage_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_sewerage_type_other', $utility_type_other );
					}

					// Accessbility
					$utility_type = [];
					$utility_type_other = '';
					if ( 
						isset( $property['materialInformation']['accessibility'] ) 
						&&
						!empty( $property['materialInformation']['accessibility'] )
					) 
					{
						foreach ( $property['materialInformation']['accessibility'] as $supply_value )
						{
					        switch ( $supply_value ) 
					        {
					        	case 'notSuitableForWheelchairUsers': $utility_type[] = 'unsuitableForWheelchairs'; break;
					            case 'levelAccess': $utility_type[] = 'level_access'; break;
					            case 'liftAccess': $utility_type[] = 'lift_access'; break;
					            case 'rampedAccess': $utility_type[] = 'ramped_access'; break;
					            case 'wetRoom': $utility_type[] = 'wet_room'; break;
					            case 'wideDoorways': $utility_type[] = 'wide_doorways'; break;
					            case 'stepFreeAccess': $utility_type[] = 'step_free_access'; break;
					            case 'levelAccessShower': $utility_type[] = 'level_access_shower'; break;
					            case 'lateralLiving': $utility_type[] = 'lateral_living'; break;
					            default: 
							        $utility_type[] = 'other'; 
							        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
							        break;
					        }
					    }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_accessibility', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_accessibility_other', $utility_type_other );
					}

					$flooded_in_last_five_years = '';
					if ( 
						isset( $property['materialInformation']['floodedLast5Years'] ) && 
						strtolower($property['materialInformation']['floodedLast5Years']) !== 'unknown' 
					)
					{
						$flooded_in_last_five_years = strtolower($property['materialInformation']['floodedLast5Years']);
					}
					update_post_meta($post_id, '_flooded_in_last_five_years', $flooded_in_last_five_years );

					$flood_defenses = '';
					if ( 
						isset( $property['materialInformation']['has_flood_defences'] ) && 
						strtolower($property['materialInformation']['floodDefenses']) !== 'unknown' 
					)
					{
						$flood_defenses = strtolower($property['materialInformation']['floodDefenses']);
					}
					update_post_meta($post_id, '_flood_defences', $flooded_in_last_five_years );

					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property['materialInformation']['floodingSourceRiver'] ) && strtolower($property['materialInformation']['floodingSourceRiver']) === 'yes'  ) 
					{
					    $utility_type[] = 'river';
					}
					if ( isset( $property['materialInformation']['floodingSourceSea'] ) && strtolower($property['materialInformation']['floodingSourceSea']) === 'yes'  ) 
					{
					    $utility_type[] = 'sea';
					}
					if ( isset( $property['materialInformation']['floodingSourceGroundWater'] ) && strtolower($property['materialInformation']['floodingSourceGroundWater']) === 'yes'  ) 
					{
					    $utility_type[] = 'groundwater';
					}
					if ( isset( $property['materialInformation']['floodingSourceLake'] ) && strtolower($property['materialInformation']['floodingSourceLake']) === 'yes'  ) 
					{
					    $utility_type[] = 'lake';
					}
					if ( isset( $property['materialInformation']['floodingSourceResevoir'] ) && strtolower($property['materialInformation']['floodingSourceResevoir']) === 'yes'  ) 
					{
					    $utility_type[] = 'reservoir';
					}
					if ( isset( $property['materialInformation']['floodingSourceOther'] ) && strtolower($property['materialInformation']['floodingSourceOther']) === 'yes'  ) 
					{
					    $utility_type[] = 'other';
					    $utility_type_other = 'Other';
					}
					update_post_meta( $post_id, '_flood_source_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_flood_source_type_other', $utility_type_other );
					}
				}

				// Marketing
				$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', 'yes' );
                }
				add_post_meta( $post_id, '_featured', '', true );

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] :
					array();
				
				$status = '';
				if (isset($property['status']))
				{
					$status = $property['status'];
				}
				if ( $status == 'underOffer' && isset($property['displayAsUnderOffer']) && $property['displayAsUnderOffer'] !== true )
				{
					if (isset($mapping['soldSTC']) && !empty($mapping['soldSTC']))
					{
						$status = 'soldSTC';
					}
				}

				if ( !empty($mapping) && isset($mapping[$status]) )
				{
					wp_set_object_terms( $post_id, (int)$mapping[$status], 'availability' );
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, 'availability' );
				}

	            // Features
				$features = array();
				if ( isset($property['features']) && is_array($property['features']) && !empty($property['features']) )
				{
					foreach ( $property['features'] as $feature )
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
		        if ( $department == 'commercial' )
		        {
		        	update_post_meta( $post_id, '_descriptions', '1' );
					update_post_meta( $post_id, '_description_name_0', '' );
		            update_post_meta( $post_id, '_description_0', str_replace(array("\r\n", "\n"), "", $property['fullDescription']) );
		        }
		        else
		        {
					update_post_meta( $post_id, '_rooms', '1' );
					update_post_meta( $post_id, '_room_name_0', '' );
		            update_post_meta( $post_id, '_room_dimensions_0', '' );
		            update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", $property['fullDescription']) );
		        }

		        // Media - Images
			    $media = array();
			    $image_width = apply_filters( 'propertyhive_loop_image_width', 1150 );
			    if ( isset($property['images']) && is_array($property['images']) && !empty($property['images']) )
				{
					foreach ( $property['images'] as $image )
					{
						$url = $image['url'];
						$modified = $image['dateUpdated'];
						if ( !empty($modified) )
						{
							$dateTime = new DateTime($modified);
							$modified = $dateTime->format('Y-m-d H:i:s');
						}

						$filename = basename( $url );

						$media[] = array(
							'url' => $url . '?width=' . $image_width,
							'compare_url' => $url,
							'filename' => $filename,
							'modified' => $modified,
						);
					}
				}

				$this->import_media( $post_id, $property['listingId'], 'photo', $media, true );

				// Media - Floorplans
			    $media = array();
			    if ( isset($property['floorPlans']) && is_array($property['floorPlans']) && !empty($property['floorPlans']) )
				{
					foreach ( $property['floorPlans'] as $image )
					{
						$url = $image['url'];
						$modified = $image['dateUpdated'];
						if ( !empty($modified) )
						{
							$dateTime = new DateTime($modified);
							$modified = $dateTime->format('Y-m-d H:i:s');
						}
					    
						$filename = basename( $url );

						$media[] = array(
							'url' => $url,
							'filename' => $filename,
							'modified' => $modified,
						);
					}
				}

				$this->import_media( $post_id, $property['listingId'], 'floorplan', $media, true );

				// Media - Brochures
			    $media = array();
			    if ( isset($property['brochure']) && is_array($property['brochure']) && !empty($property['brochure']) )
				{
					foreach ( $property['brochure'] as $brochure )
					{
						$url = $brochure['url'];
						$description = '';
						$modified = $brochure['dateUpdated'];
						if ( !empty($modified) )
						{
							$dateTime = new DateTime($modified);
							$modified = $dateTime->format('Y-m-d H:i:s');
						}
					    
						$filename = basename( $url );

						$media[] = array(
							'url' => $url,
							'filename' => $filename,
							'modified' => $modified,
						);
					}
				}

				$this->import_media( $post_id, $property['listingId'], 'brochure', $media, true );

				// Media - EPCs
			    $media = array();
			    if ( isset($property['epc']) && is_array($property['epc']) && !empty($property['epc']) )
				{
					foreach ( $property['epc'] as $epc )
					{
						$url = $epc['url'];
						$description = '';
						$modified = $epc['dateUpdated'];
						if ( !empty($modified) )
						{
							$dateTime = new DateTime($modified);
							$modified = $dateTime->format('Y-m-d H:i:s');
						}
					    
						$filename = basename( $url );

						$media[] = array(
							'url' => $url,
							'filename' => $filename,
							'modified' => $modified,
						);
					}
				}

				$this->import_media( $post_id, $property['listingId'], 'epc', $media, true );

				// Media - Virtual Tours
				$virtual_tours = array();
				if ( isset($property['virtualTourUrls']) && is_array($property['virtualTourUrls']) && !empty($property['virtualTourUrls']) )
				{
					foreach ( $property['virtualTourUrls'] as $virtual_tour )
					{
						if ( 
							$virtual_tour != ''
							&&
							(
								substr( strtolower($virtual_tour), 0, 2 ) == '//' || 
								substr( strtolower($virtual_tour), 0, 4 ) == 'http'
							)
						)
						{
							// This is a URL
							$url = $virtual_tour;

							$virtual_tours[] = $url;
						}
					}
				}

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ( $virtual_tours as $i => $virtual_tour )
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['listingId'] );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_loop_json", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['listingId'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_loop_json" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['listingId'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'forSale' => 'forSale',
                'underOffer' => 'underOffer',
                'soldSTC' => 'soldSTC',
                'exchanged' => 'exchanged',
                'completed' => 'completed',
            ),
            'lettings_availability' => array(
                'toLet' => 'toLet',
                'let' => 'let',
            ),
            'commercial_availability' => array(
                'forSale' => 'forSale',
                'underOffer' => 'underOffer',
                'exchanged' => 'exchanged',
                'completed' => 'completed',
                'toLet' => 'toLet',
                'let' => 'let',
            ),
            'property_type' => array(
                'house' => 'house',
                'terraced' => 'terraced',
                'endOfTerrace' => 'endOfTerrace',
                'semiDetached' => 'semiDetached',
                'detached' => 'detached',
                'linkDetachedHouse' => 'linkDetachedHouse',
                'mewsHouse' => 'mewsHouse',
                'townHouse' => 'townHouse',
                'countryHouse' => 'countryHouse',
                'clusterHouse' => 'clusterHouse',
                'caveHouse' => 'caveHouse',
                'villageHouse' => 'villageHouse',
                'flat' => 'flat',
                'apartment' => 'apartment',
                'penthouse' => 'penthouse',
                'groundFloorFlat' => 'groundFloorFlat',
                'maisonette' => 'maisonette',
                'blockOfFlats' => 'blockOfFlats',
                'studio' => 'studio',
                'bungalow' => 'bungalow',
                'terracedBungalow' => 'terracedBungalow',
                'semiDetachedBungalow' => 'semiDetachedBungalow',
                'houseOfMultipleOccupation' => 'houseOfMultipleOccupation',
                'characterProperty' => 'characterProperty',
                'coachHouse' => 'coachHouse',
                'cottage' => 'cottage',
                'cortijo' => 'cortijo',
                'stoneHouse' => 'stoneHouse',
                'barn' => 'barn',
                'farmOrBarn' => 'farmOrBarn',
                'mobileOrStatic' => 'mobileOrStatic',
                'parkHome' => 'parkHome',
                'land' => 'land',
                'parking' => 'parking',
                'ruins' => 'ruins',
            ),
            'commercial_property_type' => apply_filters( 'propertyhive_property_import_loop_v2_commercial_property_types', array(
                'barNightclub' => 'barNightclub',
                'businessPark' => 'businessPark',
                'cafe' => 'cafe',
                'campsiteHolidayVillage' => 'campsiteHolidayVillage',
                'childcareFacility' => 'childcareFacility',
                'commercialDevelopment' => 'commercialDevelopment',
                'commercialFarm' => 'commercialFarm',
                'commercialLand' => 'commercialLand',
                'commercialOffice' => 'commercialOffice',
                'commercialProperty' => 'commercialProperty',
                'convenienceStore' => 'convenienceStore',
                'dataCentre' => 'dataCentre',
                'distributionWarehouse' => 'distributionWarehouse',
                'factory' => 'factory',
                'guestHouse' => 'guestHouse',
                'hairdresserBarberShop' => 'hairdresserBarberShop',
                'healthcareFacility' => 'healthcareFacility',
                'heavyIndustrial' => 'heavyIndustrial',
                'hospitality' => 'hospitality',
                'hotel' => 'hotel',
                'hotelRoom' => 'hotelRoom',
                'houseBoat' => 'houseBoat',
                'industrialDevelopment' => 'industrialDevelopment',
                'industrialPark' => 'industrialPark',
                'leisureFacility' => 'leisureFacility',
                'lightIndustrial' => 'lightIndustrial',
                'marineProperty' => 'marineProperty',
                'mixedUse' => 'mixedUse',
                'petrolStation' => 'petrolStation',
                'placeOfWorship' => 'placeOfWorship',
                'postOffice' => 'postOffice',
                'privateHalls' => 'privateHalls',
                'pub' => 'pub',
                'researchDevelopmentFacility' => 'researchDevelopmentFacility',
                'residentialDevelopment' => 'residentialDevelopment',
                'restaurant' => 'restaurant',
                'retailPropertyHighStreet' => 'retailPropertyHighStreet',
                'retailPropertyOutOfTown' => 'retailPropertyOutOfTown',
                'retailPropertyPark' => 'retailPropertyPark',
                'retailPropertyPopUp' => 'retailPropertyPopUp',
                'retailPropertyShoppingCentre' => 'retailPropertyShoppingCentre',
                'riad' => 'riad',
                'sciencePark' => 'sciencePark',
                'servicedOffice' => 'servicedOffice',
                'shop' => 'shop',
                'showroom' => 'showroom',
                'smallholding' => 'smallholding',
                'spa' => 'spa',
                'sportsFacilities' => 'sportsFacilities',
                'storage' => 'storage',
                'takeaway' => 'takeaway',
                'tradeCounter' => 'tradeCounter',
                'warehouse' => 'warehouse',
                'workshopRetailSpace' => 'workshopRetailSpace',
            ) ),
            'price_qualifier' => array(
                'offersOver' => 'offersOver',
                'offersInRegionOf' => 'offersInRegionOf',
                'partBuyPartRent' => 'partBuyPartRent',
                'comingSoon' => 'comingSoon',
                'from' => 'from',
                'fixedPrice' => 'fixedPrice',
                'sharedEquity' => 'sharedEquity',
                'guidePrice' => 'guidePrice',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'sale_by' => array(
                'Private Treaty' => 'Private Treaty',
                'Auction' => 'Auction',
            ),
            'parking' => array(
                'Allocated Parking' => 'Allocated Parking',
                'Driveway' => 'Driveway',
                'Off-Street Parking' => 'Off-Street Parking',
                'On-Street Parking' => 'On-Street Parking',
                'Secure Gated Parking' => 'Secure Gated Parking',
                'Single Garage' => 'Single Garage',
                'Double Garage' => 'Double Garage'
            ),
            'outside_space' => array(
                'Balcony' => 'Balcony',
                'Garden' => 'Garden',
                'Large Garden' => 'Large Garden',
                'Patio' => 'Patio',
            ),
            'furnished' => array(
                'furnished' => 'furnished',
                'furnishedOptional' => 'furnishedOptional',
                'partFurnished' => 'partFurnished',
                'unfurnished' => 'unfurnished',
            ),
        );
	}
}

}