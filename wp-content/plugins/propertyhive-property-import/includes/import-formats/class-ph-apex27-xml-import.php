<?php
/**
 * Class for managing the import process of an Apex27 XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Apex27_XML_Import extends PH_Property_Import_Process {

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

		$xml = simplexml_load_string($contents);

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			
            $statuses_to_exclude = apply_filters( 'propertyhive_import_properties_apex27_xml_exclude_statuses', array('Pending', 'Let', 'Sold') );
            
			foreach ($xml->Listing as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

				if ( !in_array((string)$property->Status, $statuses_to_exclude) )
				{
	                $this->properties[] = $property;
	            }
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

		$this->import_start();

        $geocoding_denied = false;

        do_action( "propertyhive_pre_import_properties_apex27_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_apex27_xml_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_apex27_xml", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->ID == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->ID );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->ID, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->ID, 0, (string)$property->ID, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->ID, $property, (string)$property->DisplayAddress, (string)$property->Summary );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->ID );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_apex27_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property->Updated) ||
						(
							isset($property->Updated) &&
							empty((string)$property->Updated)
						) ||
						$previous_update_date == '' ||
						(
							isset($property->Updated) &&
							(string)$property->Updated != '' &&
							$previous_update_date != '' &&
							strtotime((string)$property->Updated) > strtotime($previous_update_date)
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
					$country = 'GB';
					if ( isset($property->Country) && (string)$property->Country != 'GB' && (string)$property->Country != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower((string)$property->Country) == strtolower($country_details['name']) )
							{
								$country = $country_code;
								break;
							}
						}
					}

					// Coordinates
					if ( isset($property->Latitude) && isset($property->Longitude) && (string)$property->Latitude != '' && (string)$property->Longitude != '' && (string)$property->Latitude != '0' && (string)$property->Longitude != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property->Latitude) ) ? (string)$property->Latitude : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property->Longitude) ) ? (string)$property->Longitude : '' ) );
					}
					else
					{
						// No lat/lng passed. Let's go and get it if none entered
						$lat = get_post_meta( $post_id, '_latitude', TRUE);
						$lng = get_post_meta( $post_id, '_longitude', TRUE);

						if ( !$geocoding_denied && ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' ) )
						{
							// No lat lng. Let's get it
							$address_to_geocode = array();
							$address_to_geocode_osm = array();
							if ( trim($property->Address1) != '' ) { $address_to_geocode[] = (string)$property->Address1; }
							if ( trim($property->Address2) != '' ) { $address_to_geocode[] = (string)$property->Address2; }
							if ( trim($property->Address3) != '' ) { $address_to_geocode[] = (string)$property->Address3; }
							if ( trim($property->Address4) != '' ) { $address_to_geocode[] = (string)$property->Address4; }
							if ( trim($property->City) != '' ) { $address_to_geocode[] = (string)$property->City; }
							if ( trim($property->County) != '' ) { $address_to_geocode[] = (string)$property->County; }
							if ( trim($property->PostalCode) != '' ) { $address_to_geocode[] = (string)$property->PostalCode; $address_to_geocode_osm[] = (string)$property->PostalCode; }

							$return = $this->do_geocoding_lookup( $post_id, (string)$property->ID, $address_to_geocode, $address_to_geocode_osm, $country );
							if ( $return === 'denied' )
							{
								$geocoding_denied = true;
							}
						}
					}

					update_post_meta( $post_id, $imported_ref_key, (string)$property->ID );

					// Address
					update_post_meta( $post_id, '_reference_number', (string)$property->Reference );
					update_post_meta( $post_id, '_address_name_number', '' );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->Address1) ) ? (string)$property->Address1 : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->Address2) ) ? (string)$property->Address2 : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->City) ) ? (string)$property->City : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->County) ) ? (string)$property->County : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->PostalCode) ) ? (string)$property->PostalCode : '' ) );

					update_post_meta( $post_id, '_address_country', $country );

	            	// We didn't find a location by doing mapping. Let's just look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_apex27_xml_address_fields_to_check', array('Address1', 'Address2', 'Address3', 'Address4', 'City', 'County') );
					$location_term_ids = array();

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
								if ( strtolower($branch_code) == strtolower(trim((string)$property->Branch->Name)) )
								{
									$office_id = $ph_office_id;
									break;
								}
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = 'residential-sales';
					if ( (string)$property->TransactionType == 'rent' )
					{
						$department = 'residential-lettings';
					}
					elseif ( (string)$property->TransactionType == 'commercial_rent' || (string)$property->TransactionType == 'commercial_sale' )
					{
						$department = 'commercial';
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->Branch->Name . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->Branch->Name . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->Branch->Name . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->Branch->Name]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->Branch->Name] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->Branch->Name]);
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
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->Bedrooms) ) ? (string)$property->Bedrooms : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->Bathrooms) ) ? (string)$property->Bathrooms : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->Receptions) ) ? (string)$property->Receptions : '' ) );

					update_post_meta( $post_id, '_council_tax_band', ( ( isset($property->CouncilTax->Band) ) ? (string)$property->CouncilTax->Band : '' ) );

					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
					
					if ( isset($property->PropertyType) )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->PropertyType]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->PropertyType], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property->PropertyType . ') that is not mapped', $post_id, (string)$property->ID );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->PropertyType, $post_id );
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
						$price = preg_replace("/[^0-9.]/", '', (string)$property->Price);
						if ( !empty($price) )
						{
							$price = round($price);
						}

						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_poa', ( strtolower((string)$property->DisplayPrice) == 'poa' ? 'yes' : '' ) );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
						
						if ( !empty($mapping) && isset($property->PricePrefix) && isset($mapping[(string)$property->PricePrefix]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->PricePrefix], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

			            // Tenure
			            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();
						
						if ( !empty($mapping) && isset($property->Tenure) && isset($mapping[(string)$property->Tenure]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->Tenure], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }

			            if ( (string)$property->Tenure == 'Leasehold' )
			            {
				            update_post_meta( $post_id, '_leasehold_years_remaining', ( ( isset($property->LeaseRemaining) && !empty((string)$property->LeaseRemaining) ) ? (string)$property->LeaseRemaining : '' ) );
							update_post_meta( $post_id, '_service_charge', ( ( isset($property->ServiceChargeAnnualAmount) && !empty((string)$property->ServiceChargeAnnualAmount) && (string)$property->ServiceChargeAnnualAmount != '0.00' ) ? (string)$property->ServiceChargeAnnualAmount : '' ) );
							update_post_meta( $post_id, '_ground_rent', ( ( isset($property->GroundRentAnnualAmount) && !empty((string)$property->GroundRentAnnualAmount) && (string)$property->GroundRentAnnualAmount != '0.00' ) ? (string)$property->GroundRentAnnualAmount : '' ) );
							update_post_meta( $post_id, '_ground_rent_review_years', ( ( isset($property->GroundRentReviewPeriodYears) && !empty((string)$property->GroundRentReviewPeriodYears) ) ? (string)$property->GroundRentReviewPeriodYears : '' ) );
							//update_post_meta( $post_id, '_shared_ownership', ( (string)$property->shared_ownership == '1' ? 'yes' : '' ) );
							//update_post_meta( $post_id, '_shared_ownership_percentage', ( (string)$property->shared_ownership == '1' ? str_replace( "%", "", (string)$property->shared_ownership_percentage ) : '' ) );
						}
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->Price));

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						switch ((string)$property->RentFrequency)
						{
							case "W": { $rent_frequency = 'pw'; break; }
							case "Y":
							case "A": { $rent_frequency = 'pa'; break; }
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						
						update_post_meta( $post_id, '_poa', ( strtolower((string)$property->DisplayPrice) == 'poa' ? 'yes' : '' ) );

						update_post_meta( $post_id, '_deposit', (string)$property->SecurityDeposit );
	            		update_post_meta( $post_id, '_available_date', (string)$property->DateAvailableFrom );

	            		// Furnished - not provided in XML
	            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();
						
						if ( !empty($mapping) && isset($property->Furnished) && isset($mapping[(string)$property->Furnished]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->Furnished], 'furnished' );
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

	            		if ( (string)$property->TransactionType == 'commercial_sale' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->Price));
		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( strtolower((string)$property->DisplayPrice) == 'poa' ? 'yes' : '' ) );
		                }

		                if ( (string)$property->TransactionType == 'commercial_rent' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    $rent = round(preg_replace("/[^0-9.]/", '', (string)$property->Price));
		                    update_post_meta( $post_id, '_rent_from', $rent );
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    $rent_frequency = 'pcm';
							switch ((string)$property->RentFrequency)
							{
								case "W": { $rent_frequency = 'pw'; break; }
								case "Y":
								case "A": { $rent_frequency = 'pa'; break; }
							}
		                    update_post_meta( $post_id, '_rent_units', $rent_frequency);

		                    update_post_meta( $post_id, '_rent_poa', ( strtolower((string)$property->DisplayPrice) == 'poa' ? 'yes' : '' ) );
		                }

			            $size = '';
			            update_post_meta( $post_id, '_floor_area_from', $size );
			            update_post_meta( $post_id, '_floor_area_from_sqft', $size );
			            update_post_meta( $post_id, '_floor_area_to', $size );
			            update_post_meta( $post_id, '_floor_area_to_sqft', $size );
			            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

			            update_post_meta( $post_id, '_site_area_from', $size );
			            update_post_meta( $post_id, '_site_area_from_sqft', $size );
			            update_post_meta( $post_id, '_site_area_to', $size );
			            update_post_meta( $post_id, '_site_area_to_sqft', $size );
			            update_post_meta( $post_id, '_site_area_units', 'sqft' );
					}

					// Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

		            $departments_with_residential_details = apply_filters( 'propertyhive_departments_with_residential_details', array( 'residential-sales', 'residential-lettings' ) );
					if ( in_array($department, $departments_with_residential_details) )
					{
						// Electricity
						$utility_type = [];
						$utility_type_other = '';
						if ( isset( $property->Electricity->Feature ) ) 
						{
						    foreach ( $property->Electricity->Feature as $supply ) 
						    {
						        $supply_value = str_replace("Electricity Supply - ", "", (string)$supply);
						        switch ( $supply_value ) 
						        {
						            default: 
						            {
						            	$types = get_electricity_types();

						            	$key = array_search($supply_value, $types);

						            	if ($key !== false) 
						            	{
										    $utility_type[] = $key;
										}
										else
										{
							                $utility_type[] = 'other'; 
							                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
							            }
						                break;
						            }
						        }
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
						if ( isset( $property->Water->Feature ) ) 
						{
						    foreach ( $property->Water->Feature as $supply ) 
						    {
						        $supply_value = str_replace("Water Supply - ", "", (string)$supply);
						        switch ( $supply_value ) 
						        {
						            case 'Private Well': 
						            case 'Private Spring': 
						            case 'Private Borehole': 
						                $utility_type[] = 'private_supply'; break;
						            default: 
						            {
						            	$types = get_water_types();

						            	$key = array_search($supply_value, $types);

						            	if ($key !== false) 
						            	{
										    $utility_type[] = $key;
										}
										else
										{
							                $utility_type[] = 'other'; 
							                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
							            }
						                break;
						            }
						        }
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
						if ( isset( $property->Heating->Feature ) ) 
						{
						    foreach ( $property->Heating->Feature as $source ) 
						    {
						        $source_value = str_replace("Heating - ", "", (string)$source);
						        switch ( $source_value ) 
						        {
						        	case "Central": { $utility_type[] = 'central'; break; }
						        	case "Gas Central": { $utility_type[] = 'gas_central'; break; }
						        	case "Oil": { $utility_type[] = 'oil'; break; }
						            default: 
						            {
						            	$types = get_heating_types();

						            	$key = array_search($source_value, $types);

						            	if ($key !== false) 
						            	{
										    $utility_type[] = $key;
										}
										else
										{
							                $utility_type[] = 'other'; 
							                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
							            }
						                break;
						            }
						        }
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
						if ( isset( $property->Broadband->Feature ) ) 
						{
						    foreach ( $property->Broadband->Feature as $supply ) 
						    {
						        $supply_value = str_replace("Broadband Supply - ", "", (string)$supply);
						        switch ( $supply_value ) 
						        {
						            default: 
						            {
						            	$types = get_broadband_types();

						            	$key = array_search($supply_value, $types);

						            	if ($key !== false) 
						            	{
										    $utility_type[] = $key;
										}
										else
										{
							                $utility_type[] = 'other'; 
							                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
							            }
						                break;
						            }
						        }
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
						if ( isset( $property->Sewerage->Feature ) ) 
						{
						    foreach ( $property->Sewerage->Feature as $supply ) 
						    {
						        $supply_value = str_replace("Sewerage Supply - ", "", (string)$supply);
						        switch ( $supply_value ) 
						        {
						            default:
						            {
						            	$types = get_sewerage_types();

						            	$key = array_search($supply_value, $types);

						            	if ($key !== false) 
						            	{
										    $utility_type[] = $key;
										}
										else
										{
							                $utility_type[] = 'other'; 
							                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
							            }
						                break;
						            }
						        }
						    }
						    $utility_type = array_unique($utility_type);
						}
						update_post_meta( $post_id, '_sewerage_type', $utility_type );
						if ( in_array( 'other', $utility_type ) ) 
						{
						    update_post_meta( $post_id, '_sewerage_type_other', $utility_type_other );
						}

						// Accessibility
						$utility_type = [];
						$utility_type_other = '';
						if ( isset( $property->AccessibilityMeasures->Feature ) ) 
						{
						    foreach ( $property->AccessibilityMeasures->Feature as $accessibility ) 
						    {
						        $accessibility_value = str_replace("Accessibility - ", "", (string) $accessibility);
						        switch ( $accessibility_value ) 
						        {
						        	case "Not Suitable for Wheelchair Users": { $utility_type[] = 'unsuitableForWheelchairs'; break; }
						        	case "Wide Doorways": { $utility_type[] = 'wide_doorways'; break; }
						            default: 
						            {
						            	$types = get_accessibility_types();

						            	$key = array_search($accessibility_value, $types);

						            	if ($key !== false) 
						            	{
										    $utility_type[] = $key;
										}
										else
										{
							                $utility_type[] = 'other'; 
							                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $accessibility_value; 
							            }
						                break;
						            }
						        }
						    }
						    $utility_type = array_unique($utility_type);
						}
						update_post_meta( $post_id, '_accessibility', $utility_type );
						if ( in_array( 'other', $utility_type ) ) 
						{
						    update_post_meta( $post_id, '_accessibility_other', $utility_type_other );
						}

						// Restrictions
						/*$utility_type = [];
						$utility_type_other = '';
						if ( function_exists('get_restrictions') )
						{
							$restriction_types = get_restrictions();

							if ( isset( $property->features->restrictions ) ) 
							{
							    foreach ( $restriction_types as $restriction_key => $restriction_label ) 
							    {
							        if ( isset( $property->features->restrictions->$restriction_key ) && (string) $property->features->restrictions->$restriction_key === 'true' ) 
							        {
							            $utility_type[] = $restriction_key;
							        }
							    }
							    if ( isset( $property->features->restrictions->other ) && (string) $property->features->restrictions->other === 'true' ) 
							    {
							        $utility_type[] = 'other';
							        $utility_type_other = 'other';
							    }
							    $utility_type = array_unique($utility_type);
							}
							update_post_meta( $post_id, '_restriction', $utility_type );
							if ( in_array( 'other', $utility_type ) ) 
							{
							    update_post_meta( $post_id, '_restriction_other', $utility_type_other );
							}
						}

						// Rights
						$utility_type = [];
						$utility_type_other = '';
						if ( function_exists('get_rights') )
						{
							$restriction_types = get_rights();
							if ( isset( $property->features->rights_and_easements ) ) 
							{
							    foreach ( $rights_types as $rights_key => $rights_label ) 
							    {
							        if ( isset( $property->features->rights_and_easements->$rights_key ) && (string) $property->features->rights_and_easements->$rights_key === 'true' ) 
							        {
							            $utility_type[] = $rights_key;
							        }
							    }
							    if ( isset( $property->features->rights_and_easements->other ) && (string) $property->features->rights_and_easements->other === 'true' ) 
							    {
							        $utility_type[] = 'other';
							        $utility_type_other = 'other';
							    }
							    $utility_type = array_unique($utility_type);
							}
							update_post_meta( $post_id, '_right', $utility_type );
							if ( in_array( 'other', $utility_type ) ) 
							{
							    update_post_meta( $post_id, '_right_other', $utility_type_other );
							}
						}

						$flooded_in_last_five_years = '';
						if ( isset( $property->features->flooding_risks->flooded_within_last_5_years ) && (string)$property->features->flooding_risks->flooded_within_last_5_years === 'true' )
						{
							$flooded_in_last_five_years = 'yes';
						}
						update_post_meta($post_id, '_flooded_in_last_five_years', $flooded_in_last_five_years );

						$flood_defenses = '';
						if ( isset( $property->features->flooding_risks->flood_defenses_present ) && (string)$property->features->flooding_risks->flood_defenses_present === 'true' )
						{
							$flood_defenses = 'yes';
						}
						update_post_meta($post_id, '_flood_defences', $flooded_in_last_five_years );

						$utility_type = [];
						$utility_type_other = '';
						if ( isset( $property->features->flooding_risks->sources_of_flooding->source ) ) 
						{
						    foreach ( $property->features->flooding_risks->sources_of_flooding->source as $source ) 
						    {
						        $source_value = (string) $source;
						        switch ( $source_value ) 
						        {
						            case 'River': $utility_type[] = 'river'; break;
						            case 'Sea': $utility_type[] = 'sea'; break;
						            case 'Groundwater': $utility_type[] = 'groundwater'; break;
						            case 'Lake': $utility_type[] = 'lake'; break;
						            case 'Reservoir': $utility_type[] = 'reservoir'; break;
						            default: 
						                $utility_type[] = 'other'; 
						                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $accessibility_value; 
						                break;
						        }
						    }
						    $utility_type = array_unique($utility_type);
						}
						update_post_meta( $post_id, '_flood_source_type', $utility_type );
						if ( in_array( 'other', $utility_type ) ) 
						{
						    update_post_meta( $post_id, '_flood_source_type_other', $utility_type_other );
						}*/
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
						update_post_meta( $post_id, '_featured', ( isset($property->Featured) && (string)$property->Featured == '1' ) ? 'yes' : '' );
					}
					
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
							$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
							array();

					if ( !empty($mapping) && isset($property->Status) && isset($mapping[(string)$property->Status]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->Status], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					if ( isset($property->Features) && !empty($property->Features) )
					{
						foreach ( $property->Features->Feature as $feature )
						{
							$features[] = trim((string)$feature);
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
			            update_post_meta( $post_id, '_description_0', (string)$property->Description );
					}
					else
					{
						update_post_meta( $post_id, '_rooms', '1' );
						update_post_meta( $post_id, '_room_name_0', '' );
			            update_post_meta( $post_id, '_room_dimensions_0', '' );
			            update_post_meta( $post_id, '_room_description_0', (string)$property->Description );
			        }

			        // Media - Images
				    $media = array();
				    if (isset($property->Images) && !empty($property->Images))
	                {
	                    foreach ($property->Images as $images)
	                    {
	                        if (!empty($images->Image))
	                        {
	                            foreach ($images->Image as $image)
	                            {
									$url = str_replace("http://", "https://", (string)$image->URL);
									$explode_url = explode("?", $url);
									$url = $explode_url[0];

									$media[] = array(
										'url' => $url,
										'description' => ( ( isset($image->Caption) && (string)$image->Caption != '' ) ? (string)$image->Caption : '' ),
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->ID, 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if (isset($property->Floorplans) && !empty($property->Floorplans))
	                {
	                    foreach ($property->Floorplans as $floorplans)
	                    {
	                        if (!empty($floorplans->Floorplan))
	                        {
	                            foreach ($floorplans->Floorplan as $floorplan)
	                            {
									$url = str_replace("http://", "https://", (string)$floorplan->URL);
									$explode_url = explode("?", $url);
									$url = $explode_url[0];

									$media[] = array(
										'url' => $url,
										'description' => ( ( isset($floorplan->Caption) && (string)$floorplan->Caption != '' ) ? (string)$floorplan->Caption : '' ),
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->ID, 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if (isset($property->Brochures) && !empty($property->Brochures))
	                {
	                    foreach ($property->Brochures as $brochures)
	                    {
	                        if (!empty($brochures->Brochure))
	                        {
	                            foreach ($brochures->Brochure as $brochure)
	                            {
									$url = str_replace("http://", "https://", (string)$brochure->URL);

									$media[] = array(
										'url' => $url,
										'description' => ( ( isset($brochure->Caption) && (string)$brochure->Caption != '' ) ? (string)$brochure->Caption : '' ),
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->ID, 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if (isset($property->EPCs) && !empty($property->EPCs))
	                {
	                    foreach ($property->EPCs as $epcs)
	                    {
	                        if (!empty($epcs->EPC))
	                        {
	                            foreach ($epcs->EPC as $epc)
	                            {
									$url = str_replace("http://", "https://", (string)$epc->URL);

									$explode_url = explode('?', $url);

									$media[] = array(
										'url' => $url,
										'compare_url' => $explode_url[0],
										'filename' => basename( $explode_url[0] ),
										'description' => ( ( isset($epc->Caption) && (string)$epc->Caption != '' ) ? (string)$epc->Caption : '' ),
									);
								}
							}
						}
					}

					$epc_data = array();
					if ( 
						apply_filters( 'propertyhive_property_import_apex27_import_epc_ratings', TRUE ) === TRUE
						&&
						(
							( (string)$property->EPC->EnergyEfficiencyCurrent != '' && (string)$property->EPC->EnergyEfficiencyPotential != '' )
							||
							( (string)$property->EPC->EnvironmentalImpactCurrent != '' && (string)$property->EPC->EnvironmentalImpactPotential != '' )
						)
					)
			        {
			        	$epc_data = array(
			        		'eec' => (string)$property->EPC->EnergyEfficiencyCurrent,
			        		'eep' => (string)$property->EPC->EnergyEfficiencyPotential,
			        		'eic' => (string)$property->EPC->EnvironmentalImpactCurrent,
			        		'eip' => (string)$property->EPC->EnvironmentalImpactPotential,
			        	);
				    }

					$this->import_media( $post_id, (string)$property->ID, 'epc', $media, false, false, $epc_data );

					// Media - Virtual Tours
					$virtual_tours = array();
					if (isset($property->Videos) && !empty($property->Videos))
	                {
	                    foreach ($property->Videos as $virtualTours)
	                    {
	                        if (!empty($virtualTours->Video))
	                        {
	                            foreach ($virtualTours->Video as $virtualTour)
	                            {
	                            	$virtual_tours[] = array(
	                            		'url' => (string)$virtualTour->URL,
	                            		'label' => isset($virtualTour->Name) ? (string)$virtualTour->Name : ''
	                            	);
	                            }
	                        }
	                    }
	                }

	                if (isset($property->VirtualTours) && !empty($property->VirtualTours))
	                {
	                    foreach ($property->VirtualTours as $virtualTours)
	                    {
	                        if (!empty($virtualTours->VirtualTour))
	                        {
	                            foreach ($virtualTours->VirtualTour as $virtualTour)
	                            {
	                            	$virtual_tours[] = array(
	                            		'url' => (string)$virtualTour->URL,
	                            		'label' => isset($virtualTour->Name) ? (string)$virtualTour->Name : ''
	                            	);
	                            }
	                        }
	                    }
	                }

	                if (isset($property->VideoLinks) && !empty($property->VideoLinks))
	                {
	                    foreach ($property->VideoLinks as $virtualTours)
	                    {
	                        if (!empty($virtualTours->Link))
	                        {
	                            foreach ($virtualTours->Link as $virtualTour)
	                            {
	                            	$virtual_tours[] = array(
	                            		'url' => (string)$virtualTour->URL,
	                            		'label' => isset($virtualTour->Name) ? (string)$virtualTour->Name : ''
	                            	);
	                            }
	                        }
	                    }
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                    update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
	                    update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->ID );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->ID );
				}

				if ( isset($property->Updated) ) { update_post_meta( $post_id, '_apex27_update_date_' . $this->import_id, (string)$property->Updated ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_apex27_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->ID, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_apex27_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->ID;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'SSTC' => 'SSTC',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Let Agreed' => 'Let Agreed',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'SSTC' => 'SSTC',
                'Let Agreed' => 'Let Agreed',
            ),
            'property_type' => array(
                'Detached House' => 'Detached House',
                'Semi-detached House' => 'Semi-detached House',
                'Detached Bungalow' => 'Detached Bungalow',
                'Semi-detached Bungalow' => 'Semi-detached Bungalow',
                'Apartment / Flat' => 'Apartment / Flat',
            ),
            'commercial_property_type' => array(
                'Commercial Property' => 'Commercial Property',
                'Retail' => 'Retail',
            ),
            'price_qualifier' => array(
                'Asking Price' => 'Asking Price',
                'Offers in region of' => 'Offers in region of',
                'Guide Price' => 'Guide Price',
                'Offers Invited' => 'Offers Invited',
                'Offers in excess of' => 'Offers in excess of',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Part Furnished' => 'Part Furnished',
                'Unfurnished' => 'Unfurnished',
                'Furnished/Unfurnished' => 'Furnished/Unfurnished',
            ),
        );
	}
}

}