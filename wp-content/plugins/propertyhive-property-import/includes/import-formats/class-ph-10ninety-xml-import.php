<?php
/**
 * Class for managing the import process of a 10ninety XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_10ninety_XML_Import extends PH_Property_Import_Process {

	public function __construct( $instance_id = '', $import_id = '' ) 
	{
		parent::__construct();
		
		$this->instance_id = $instance_id;
		$this->import_id = $import_id;

		if ( isset($_GET['custom_property_import_cron']) )
	    {
	    	$current_user = wp_get_current_user();

	    	$this->log("Executed manually by " . ( ( isset($current_user->display_name) ) ? $current_user->display_name : '' ) );
	    }
	}

	public function parse( $test = false, $troubleshooting = false )
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		if ( $test === false || $troubleshooting === true )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
			$test = true;
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
			if ( isset( $_POST['xml_url'] ) ) 
			{
			    $import_settings['xml_url'] = sanitize_url( wp_unslash( $_POST['xml_url'] ) );
			}
		}
		
		$contents = '';

		$response = wp_remote_get( $import_settings['xml_url'], array( 'timeout' => 120 ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		if ( wp_remote_retrieve_response_code($response) !== 200 )
        {
            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
            return false;
        }

		$xml = simplexml_load_string($contents);

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			            
			foreach ($xml->property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

                $this->properties[] = $property;
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML: ' . print_r($contents, true) );

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

		$commercial_active = false;
		if ( get_option( 'propertyhive_active_departments_commercial', '' ) == 'yes' )
		{
			$commercial_active = true;
		}

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_10ninety_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_10ninety_xml_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_10ninety_xml", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->AGENT_REF == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->AGENT_REF );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->AGENT_REF, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->AGENT_REF, 0, (string)$property->AGENT_REF, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->AGENT_REF, $property, (string)$property->DISPLAY_ADDRESS, (string)$property->SUMMARY, (string)$property->DISPLAY_ADDRESS . '-' . (string)$property->PROPERTY_REF, '', '', ( isset($property->UPDATE_DATE) ? (string)$property->UPDATE_DATE : '' ), '_10ninety_update_date_' . $this->import_id );

			if ( $inserted_updated !== FALSE )
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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->AGENT_REF );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->AGENT_REF );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_10ninety_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property->UPDATE_DATE) ||
						(
							isset($property->UPDATE_DATE) &&
							empty((string)$property->UPDATE_DATE)
						) ||
						$previous_update_date == '' ||
						(
							isset($property->UPDATE_DATE) &&
							(string)$property->UPDATE_DATE != '' &&
							$previous_update_date != '' &&
							strtotime((string)$property->UPDATE_DATE) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', (string)$property->AGENT_REF );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property->ADDRESS_1) ) ? (string)$property->ADDRESS_1 : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->ADDRESS_2) ) ? (string)$property->ADDRESS_2 : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->ADDRESS_3) ) ? (string)$property->ADDRESS_3 : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->TOWN) ) ? (string)$property->TOWN : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->COUNTY) ) ? (string)$property->COUNTY : ( ( isset($property->ADDRESS_4) ) ? (string)$property->ADDRESS_4 : '' ) ) );
					update_post_meta( $post_id, '_address_postcode', trim( ( ( isset($property->POSTCODE1) ) ? (string)$property->POSTCODE1 : '' ) . ' ' . ( ( isset($property->POSTCODE2) ) ? (string)$property->POSTCODE2 : '' ) ) );

					$country = get_option( 'propertyhive_default_country', 'GB' );
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_10ninety_xml_address_fields_to_check', array('ADDRESS_2', 'ADDRESS_3', 'TOWN', 'ADDRESS_4', 'COUNTY') );
					$location_term_ids = array();

					if ( isset($property->SEARCHABLE_AREAS) )
					{
						foreach ( $property->SEARCHABLE_AREAS as $searchable_areas )
						{
							if ( isset($searchable_areas->SEARCHABLE_AREA) )
							{
								foreach ( $searchable_areas->SEARCHABLE_AREA as $searchable_area )
								{
									$term = term_exists( trim((string)$searchable_area), 'location');
									if ( $term !== 0 && $term !== null && isset($term['term_id']) )
									{
										$location_term_ids[] = (int)$term['term_id'];
									}
								}
							}
						}
					}

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property->{$address_field}) && trim((string)$property->{$address_field}) != '' ) 
						{
							$term = term_exists( trim((string)$property->{$address_field}), 'location');
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
					if ( isset($property->LATITUDE) && isset($property->LONGITUDE) && (string)$property->LATITUDE != '' && (string)$property->LONGITUDE != '' && (string)$property->LATITUDE != '0' && (string)$property->LONGITUDE != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property->LATITUDE) ) ? (string)$property->LATITUDE : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property->LONGITUDE) ) ? (string)$property->LONGITUDE : '' ) );
					}
					else
					{
						// No lat/lng passed. Let's go and get it if none entered
						$lat = get_post_meta( $post_id, '_latitude', TRUE);
						$lng = get_post_meta( $post_id, '_longitude', TRUE);

						if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
						{
							// No lat lng. Let's get it
							$address_to_geocode = array();
							$address_to_geocode_osm = array();
							if ( isset($property->ADDRESS_1) && trim((string)$property->ADDRESS_1) != '' ) { $address_to_geocode[] = (string)$property->ADDRESS_1; }
							if ( isset($property->ADDRESS_2) && trim((string)$property->ADDRESS_2) != '' ) { $address_to_geocode[] = (string)$property->ADDRESS_2; }
							if ( isset($property->TOWN) && trim((string)$property->TOWN) != '' ) { $address_to_geocode[] = (string)$property->TOWN; }
							if ( isset($property->ADDRESS_3) && trim((string)$property->ADDRESS_3) != '' ) { $address_to_geocode[] = (string)$property->ADDRESS_3; }
							if ( isset($property->ADDRESS_4) && trim((string)$property->ADDRESS_4) != '' ) { $address_to_geocode[] = (string)$property->ADDRESS_4; }
							if ( isset($property->POSTCODE1) && isset($property->POSTCODE2) ) { $address_to_geocode[] = (string)$property->POSTCODE1 . ' ' . (string)$property->POSTCODE2; $address_to_geocode_osm[] = (string)$property->POSTCODE1 . ' ' . (string)$property->POSTCODE2; }

							$return = $this->do_geocoding_lookup( $post_id, (string)$property->AGENT_REF, $address_to_geocode, $address_to_geocode_osm, $country );
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
							if ( $branch_code == (string)$property->BRANCH_ID )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = 'residential-sales';
					if ( (string)$property->TRANS_TYPE_ID == '2' )
					{
						$department = 'residential-lettings';
					}
					if ( $commercial_active )
					{
						// Commercial is active.
						// Does this property have any commecial characteristics
						if ( isset( $property->LET_TYPE_ID ) && (string)$property->LET_TYPE_ID == 4 )
						{
							$department = 'commercial';
						}
						elseif ( isset( $property->BUSINESS_CATEGORY_ID ) && (string)$property->BUSINESS_CATEGORY_ID == 2 )
						{
							$department = 'commercial';
						}
						else
						{
							// Check if the type is any of the commercial types
							$format_details = propertyhive_property_import_get_import_format('xml_10ninety');
							$mappings = isset($format_details['mappings']) ? $format_details['mappings'] : array();

							$commercial_property_types = array();
							if ( isset($mappings['commercial_property_type']) )
							{
								$commercial_property_types = $mappings['commercial_property_type'];
							}
							if ( isset($commercial_property_types[(string)$property->PROP_SUB_ID]) )
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
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->BRANCH_ID . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->BRANCH_ID . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->BRANCH_ID . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->BRANCH_ID]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->BRANCH_ID] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->BRANCH_ID]);
						}

						if ( !empty($explode_agent_branch) )
						{
							update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
							update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );

							$this->branch_ids_processed[] = $explode_agent_branch[1];
						}
	        		}

					update_post_meta( $post_id, '_department', $department );
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->BEDROOMS) ) ? (string)$property->BEDROOMS : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->BATHROOMS) ) ? (string)$property->BATHROOMS : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->RECEPTIONS) ) ? (string)$property->RECEPTIONS : '' ) );

					update_post_meta( $post_id, '_council_tax_band', ( ( isset($property->COUNCIL_TAX_BAND) ) ? (string)$property->COUNCIL_TAX_BAND : '' ) );

					if ( $department == 'residential-sales' || $department == 'residential-lettings' )
					{
						$taxonomy = 'property_type';
			        }
			        elseif ( $department == 'commercial' )
			        {
			        	$taxonomy = 'commercial_property_type';
			        }

					$mapping = isset($import_settings['mappings'][$taxonomy]) ? $import_settings['mappings'][$taxonomy] : array();
					
					if ( isset($property->PROP_SUB_ID) && (string)$property->PROP_SUB_ID != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->PROP_SUB_ID]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->PROP_SUB_ID], $taxonomy );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $taxonomy );

							$this->log( 'Property received with a type (' . (string)$property->propertyType . ') that is not mapped', $post_id, (string)$property->AGENT_REF );

							$import_settings = $this->add_missing_mapping( $mapping, $taxonomy, (string)$property->propertyType );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $taxonomy );
					}

					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->PRICE));

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );
						update_post_meta( $post_id, '_poa', ( ( isset($property->PRICE_QUALIFIER) && (string)$property->PRICE_QUALIFIER == '1' ) ? 'yes' : '') );

						update_post_meta( $post_id, '_currency', 'GBP' );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
						
						if ( !empty($mapping) && isset($property->PRICE_QUALIFIER) && isset($mapping[(string)$property->PRICE_QUALIFIER]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->PRICE_QUALIFIER], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

			            // Tenure
						$mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();
						
						if ( !empty($mapping) && isset($property->TENURE_TYPE_ID) && isset($mapping[(string)$property->TENURE_TYPE_ID]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->TENURE_TYPE_ID], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }
					}
					elseif ( $department == 'residential-lettings' )
					{
						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						$price_actual = $price;
						switch ((string)$property->LET_RENT_FREQUENCY)
						{
							case "0": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
							case "1": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
							case "2": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
							case "3": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
							case "5": 
							{
								$rent_frequency = 'pppw';
								$bedrooms = ( isset($property->BEDROOMS) ? (string)$property->BEDROOMS : '0' );
								if ( $bedrooms != '' && $bedrooms != 0 )
								{
									$price_actual = (($price * 52) / 12) * $bedrooms;
								}
								else
								{
									$price_actual = ($price * 52) / 12;
								}
								break; 
							}
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', $price_actual );
		
						update_post_meta( $post_id, '_currency', 'GBP' );

						update_post_meta( $post_id, '_poa', ( ( isset($property->PRICE_QUALIFIER) && (string)$property->PRICE_QUALIFIER == '1' ) ? 'yes' : '') );

						update_post_meta( $post_id, '_deposit', preg_replace( "/[^0-9.]/", '', ( ( isset($property->LET_BOND) ) ? (string)$property->LET_BOND : '' ) ) );
	            		update_post_meta( $post_id, '_available_date', ( (isset($property->LET_DATE_AVAILABLE) && (string)$property->LET_DATE_AVAILABLE != '') ? date("Y-m-d", strtotime((string)$property->LET_DATE_AVAILABLE)) : '' ) );

	            		// Furnished
						$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();
						
						if ( !empty($mapping) && isset($property->LET_FURN_ID) && isset($mapping[(string)$property->LET_FURN_ID]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->LET_FURN_ID], 'furnished' );
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

	            		if ( (string)$property->TRANS_TYPE_ID == '1' )
	            		{
	            			update_post_meta( $post_id, '_for_sale', 'yes' );

	            			update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	            			update_post_meta( $post_id, '_price_from', $price );
	            			update_post_meta( $post_id, '_price_to', $price );
	            			update_post_meta( $post_id, '_price_units', '' );
	            			update_post_meta( $post_id, '_price_poa', ( isset($property->PRICE_QUALIFIER) && (string)$property->PRICE_QUALIFIER == '1' ) ? 'yes' : '' );

	            			// Price Qualifier
							$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
							
							if ( !empty($mapping) && isset($property->PRICE_QUALIFIER) && isset($mapping[(string)$property->PRICE_QUALIFIER]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->PRICE_QUALIFIER], 'price_qualifier' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
				            }

				            // Tenure
							$mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();
							
				            if ( !empty($mapping) && isset($property->TENURE_TYPE_ID) && isset($mapping[(string)$property->TENURE_TYPE_ID]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->TENURE_TYPE_ID], 'commercial_tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
				            }
	            		}
	            		elseif ( (string)$property->TRANS_TYPE_ID == '2' )
	            		{
	            			update_post_meta( $post_id, '_to_rent', 'yes' );

	            			update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	            			update_post_meta( $post_id, '_rent_from', $price );
	            			update_post_meta( $post_id, '_rent_to', $price );
	            			$rent_units = '';
	            			switch ((string)$property->LET_RENT_FREQUENCY)
							{
		            			case "0": { $rent_units = 'pw'; break; }
								case "1": { $rent_units = 'pcm'; break; }
								case "2": { $rent_units = 'pq'; break; }
								case "3": { $rent_units = 'pa'; break; }
							}
							update_post_meta( $post_id, '_rent_units', $rent_units );
	            			update_post_meta( $post_id, '_rent_poa', ( isset($property->PRICE_QUALIFIER) && (string)$property->PRICE_QUALIFIER == '1' ) ? 'yes' : '' );
	            		}

	            		// Store price in common currency (GBP) used for ordering
			            $ph_countries = new PH_Countries();
			            $ph_countries->update_property_price_actual( $post_id );

	            		$size = '';
	            		$unit = 'sqft';
	            		if ( isset($property->MIN_SIZE_ENTERED) )
	            		{
		            		$size = preg_replace("/[^0-9.]/", '', (string)$property->MIN_SIZE_ENTERED);
		            		$size = str_replace(".00", "", $size);

				            if ( isset($property->AREA_SIZE_UNIT_ID) )
				            {
				            	switch ( (string)$property->AREA_SIZE_UNIT_ID )
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
				        if ( isset($property->MAX_SIZE_ENTERED) )
	            		{
		            		$size = preg_replace("/[^0-9.]/", '', (string)$property->MAX_SIZE_ENTERED);
		            		$size = str_replace(".00", "", $size);

				            if ( isset($property->AREA_SIZE_UNIT_ID) )
				            {
				            	switch ( (string)$property->AREA_SIZE_UNIT_ID )
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

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
					if ( $on_market_by_default === true )
					{
						update_post_meta( $post_id, '_on_market', ( !isset($property->PUBLISHED_FLAG) || ( isset($property->PUBLISHED_FLAG) && (string)$property->PUBLISHED_FLAG == '1' ) ) ? 'yes' : '' );
					}
					$featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', ( isset($property->FEATURE_ON_HOMEPAGE) && (string)$property->FEATURE_ON_HOMEPAGE == 'True' ) ? 'yes' : '' );
					}

					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					if ( !empty($mapping) && isset($property->STATUS_ID) && isset($mapping[(string)$property->STATUS_ID]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->STATUS_ID], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					for ( $i = 1; $i <= 10; ++$i )
					{
						if ( isset($property->{'FEATURE' . $i}) && trim((string)$property->{'FEATURE' . $i}) != '' )
						{
							$features[] = trim((string)$property->{'FEATURE' . $i});
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
			            update_post_meta( $post_id, '_room_description_0', (string)$property->DESCRIPTION );
			            if ( get_post_meta( $post_id, '_room_description_0', TRUE ) == '' )
			            {
				            update_post_meta( $post_id, '_room_description_0', utf8_encode((string)$property->DESCRIPTION) );
				        }
			        }
				    else
				    {
				    	// For now put the whole description in one description
				    	update_post_meta( $post_id, '_descriptions', '1' );
						update_post_meta( $post_id, '_description_name_0', '' );

						update_post_meta( $post_id, '_description_0', '' );
	            		update_post_meta( $post_id, '_description_0', (string)$property->DESCRIPTION );
	            		if ( get_post_meta( $post_id, '_description_0', TRUE ) == '' )
			            {
				            update_post_meta( $post_id, '_description_0', utf8_encode((string)$property->DESCRIPTION) );
				        }
				    }

				    // Media - Images
				    $media = array();
				    for ( $i = 0; $i <= 49; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property->{'MEDIA_IMAGE_' . $j}) && trim((string)$property->{'MEDIA_IMAGE_' . $j}) != '' )
						{
							$url = (string)$property->{'MEDIA_IMAGE_' . $j};
							$explode_url = explode('?', $url);

							$media[] = array(
								'url' => (string)$property->{'MEDIA_IMAGE_' . $j},
								'compare_url' => $explode_url[0],
								'filename' => basename( $url ) . '.jpg',
								'description' => ( ( isset($property->{'MEDIA_IMAGE_TEXT_' . $j}) && (string)$property->{'MEDIA_IMAGE_TEXT_' . $j} != '' ) ? (string)$property->{'MEDIA_IMAGE_TEXT_' . $j} : '' ),
							);
						}
					}

					$this->import_media( $post_id, (string)$property->AGENT_REF, 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    for ( $i = 0; $i <= 10; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property->{'MEDIA_FLOOR_PLAN_' . $j}) && trim((string)$property->{'MEDIA_FLOOR_PLAN_' . $j}) != '' )
						{
							$url = (string)$property->{'MEDIA_FLOOR_PLAN_' . $j};
							$explode_url = explode('?', $url);

							$media[] = array(
								'url' => (string)$property->{'MEDIA_FLOOR_PLAN_' . $j},
								'compare_url' => $explode_url[0],
								'filename' => basename( $url ) . '.jpg',
								'description' => ( ( isset($property->{'MEDIA_FLOOR_PLAN_TEXT_' . $j}) && (string)$property->{'MEDIA_FLOOR_PLAN_TEXT_' . $j} != '' ) ? (string)$property->{'MEDIA_FLOOR_PLAN_TEXT_' . $j} : '' ),
							);
						}
					}

					$this->import_media( $post_id, (string)$property->AGENT_REF, 'floorplan', $media, false );

					// Media - Brochure
				    $media = array();
				    for ( $i = 0; $i <= 10; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property->{'MEDIA_DOCUMENT_' . $j}) && trim((string)$property->{'MEDIA_DOCUMENT_' . $j}) != '' )
						{
							$url = (string)$property->{'MEDIA_DOCUMENT_' . $j};
							$explode_url = explode('?', $url);

							$media[] = array(
								'url' => (string)$property->{'MEDIA_DOCUMENT_' . $j},
								'compare_url' => $explode_url[0],
								'filename' => basename( $url ) . '.pdf',
								'description' => ( ( isset($property->{'MEDIA_DOCUMENT_TEXT_' . $j}) && (string)$property->{'MEDIA_DOCUMENT_TEXT_' . $j} != '' ) ? (string)$property->{'MEDIA_DOCUMENT_TEXT_' . $j} : '' ),
							);
						}
					}

					$this->import_media( $post_id, (string)$property->AGENT_REF, 'brochure', $media, false );

					// Media - EPC
				    $media = array();
				    for ( $i = 60; $i <= 61; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property->{'MEDIA_IMAGE_' . $j}) && trim((string)$property->{'MEDIA_IMAGE_' . $j}) != '' )
						{
							$url = (string)$property->{'MEDIA_IMAGE_' . $j};
							$explode_url = explode('?', $url);

							$media[] = array(
								'url' => (string)$property->{'MEDIA_IMAGE_' . $j},
								'compare_url' => $explode_url[0],
								'filename' => basename( $url ) . '.jpg',
								'description' => ( ( isset($property->{'MEDIA_IMAGE_TEXT_' . $j}) && (string)$property->{'MEDIA_IMAGE_TEXT_' . $j} != '' ) ? (string)$property->{'MEDIA_IMAGE_TEXT_' . $j} : '' ),
							);
						}
					}
					for ( $i = 50; $i <= 55; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property->{'MEDIA_DOCUMENT_' . $j}) && trim((string)$property->{'MEDIA_DOCUMENT_' . $j}) != '' )
						{
							$url = (string)$property->{'MEDIA_DOCUMENT_' . $j};
							$explode_url = explode('?', $url);

							$media[] = array(
								'url' => (string)$property->{'MEDIA_DOCUMENT_' . $j},
								'compare_url' => $explode_url[0],
								'filename' => basename( $url ) . '.pdf',
								'description' => ( ( isset($property->{'MEDIA_DOCUMENT_TEXT_' . $j}) && (string)$property->{'MEDIA_DOCUMENT_TEXT_' . $j} != '' ) ? (string)$property->{'MEDIA_DOCUMENT_TEXT_' . $j} : '' ),
							);
						}
					}

					$this->import_media( $post_id, (string)$property->AGENT_REF, 'epc', $media, false );

					// Media - Virtual Tours
					$urls = array();

					for ( $i = 0; $i <= 5; ++$i )
					{
						$j = str_pad( $i, 2, '0', STR_PAD_LEFT );

						if ( isset($property->{'MEDIA_VIRTUAL_TOUR_' . $j}) && trim((string)$property->{'MEDIA_VIRTUAL_TOUR_' . $j}) != '' )
						{
							if ( 
								substr( strtolower((string)$property->{'MEDIA_VIRTUAL_TOUR_' . $j}), 0, 2 ) == '//' || 
								substr( strtolower((string)$property->{'MEDIA_VIRTUAL_TOUR_' . $j}), 0, 4 ) == 'http'
							)
							{
								$urls[] = trim((string)$property->{'MEDIA_VIRTUAL_TOUR_' . $j});
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

				        $this->log( 'Imported ' . count($urls) . ' virtual tours', $post_id, (string)$property->AGENT_REF );
					}

					do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
					do_action( "propertyhive_property_imported_10ninety_xml", $post_id, $property, $this->import_id );

					$post = get_post( $post_id );
					do_action( "save_post_property", $post_id, $post, false );
					do_action( "save_post", $post_id, $post, false );

					if ( $inserted_updated == 'updated' )
					{
						$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->AGENT_REF, $metadata_before, $taxonomy_terms_before );
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->AGENT_REF );
				}

				if ( isset($property->UPDATE_DATE) ) { update_post_meta( $post_id, '_10ninety_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime((string)$property->UPDATE_DATE)) ); }
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_10ninety_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->AGENT_REF;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
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
                '3' => 'Feudal',
                '4' => 'Commonhold',
                '5' => 'Share of Freehold',
            ),
            'commercial_tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Feudal',
                '4' => 'Commonhold',
                '5' => 'Share of Freehold',
            ),
            'furnished' => array(
                '0' => 'Furnished',
                '2' => 'Unfurnished',
                '1' => 'Part Furnished',
                '3' => 'Not Specified',
                '4' => 'Furnished/Un Furnished',
            ),
        );
	}
}

}