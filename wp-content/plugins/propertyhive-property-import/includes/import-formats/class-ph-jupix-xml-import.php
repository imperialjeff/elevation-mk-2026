<?php
/**
 * Class for managing the import process of a Jupix XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Jupix_XML_Import extends PH_Property_Import_Process {

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

		if ( empty($contents) )
		{
			$this->log_error( 'Data returned empty. Likely invalid URL' );

        	return false;
		}

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			
            $properties_imported = 0;
            
			foreach ($xml->property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

            	$department = (string)$property->department;
                
                if ($department == 'Sales' || $department == 'Lettings' || $department == 'Commercial' || $department == 'Agricultural' )
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

        do_action( "propertyhive_pre_import_properties_jupix_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_jupix_xml_properties_due_import", $this->properties, $this->import_id );

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_jupix_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->propertyID == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->propertyID );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->propertyID, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->propertyID, 0, (string)$property->propertyID, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->propertyID, $property, (string)$property->displayAddress, (string)$property->mainSummary, '', '', '', (string)$property->dateLastModified . ' ' . (string)$property->timeLastModified, '_jupix_xml_update_date_' . $this->import_id );

			if ( $inserted_updated !== false )
			{
				// Inserted property ok. Continue

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->propertyID );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->propertyID );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_jupix_xml_update_date_' . $this->import_id, TRUE);

				$skip_property = false;
				if (
					( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				)
				{
					if (
						$previous_update_date == (string)$property->dateLastModified . ' ' . (string)$property->timeLastModified
					)
					{
						$skip_property = true;
					}
				}

				if ( !$skip_property )
				{
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

					$country = 'GB';
					if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower((string)$property->country) == strtolower($country_details['name']) )
							{
								$country = $country_code;
								break;
							}
						}
					}

					// Coordinates
					if ( isset($property->latitude) && isset($property->longitude) && (string)$property->latitude != '' && (string)$property->longitude != '' && (string)$property->latitude != '0' && (string)$property->longitude != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property->latitude) ) ? (string)$property->latitude : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property->longitude) ) ? (string)$property->longitude : '' ) );
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
							if ( trim($property->addressName) != '' ) { $address_to_geocode[] = (string)$property->addressName; }
							if ( trim($property->addressNumber) != '' ) { $address_to_geocode[] = (string)$property->addressNumber; }
							if ( trim($property->addressStreet) != '' ) { $address_to_geocode[] = (string)$property->addressStreet; }
							if ( trim($property->address2) != '' ) { $address_to_geocode[] = (string)$property->address2; }
							if ( trim($property->address3) != '' ) { $address_to_geocode[] = (string)$property->address3; }
							if ( trim($property->address4) != '' ) { $address_to_geocode[] = (string)$property->address4; }
							if ( trim($property->addressPostcode) != '' ) { $address_to_geocode[] = (string)$property->addressPostcode; $address_to_geocode_osm[] = (string)$property->addressPostcode; }

							$return = $this->do_geocoding_lookup( $post_id, (string)$property->propertyID, $address_to_geocode, $address_to_geocode_osm, $country );
							if ( $return === 'denied' )
							{
								$geocoding_denied = true;
							}
						}
					}

					// Address
					update_post_meta( $post_id, '_reference_number', (string)$property->referenceNumber );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property->addressName) ) ? (string)$property->addressName : '' ) . ' ' . ( ( isset($property->addressNumber) ) ? (string)$property->addressNumber : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->addressStreet) ) ? (string)$property->addressStreet : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->address2) ) ? (string)$property->address2 : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->address3) ) ? (string)$property->address3 : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->address4) ) ? (string)$property->address4 : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->addressPostcode) ) ? (string)$property->addressPostcode : '' ) );

					update_post_meta( $post_id, '_address_country', $country );

					$mapping = isset($import_settings['mappings']['location']) ? $import_settings['mappings']['location'] : array();

	        		$found_location = false;
					if ( !empty($mapping) && isset($property->regionID) )
					{
						$region_id = ( ( (string)$property->regionID != '' ) ? (string)$property->regionID : '0' );
						if ( isset($mapping[$region_id]) && $mapping[$region_id] != '' )
						{
		                	wp_set_object_terms( $post_id, (int)$mapping[$region_id], 'location' );
		                	$found_location = true;
						}
		            }

		            if ( !$found_location )
		            {
		            	// We didn't find a location by doing mapping. Let's just look at address fields to see if we find a match
		            	$address_fields_to_check = apply_filters( 'propertyhive_jupix_xml_address_fields_to_check', array('address2', 'address3', 'address4') );
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
							$found_location = true;
						}
		            }

		            if ( !$found_location )
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
							$explode_branch_code = explode(",", $branch_code);
							if ( in_array((string)$property->branchID, $explode_branch_code) || in_array((string)$property->branchName, $explode_branch_code) )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = 'residential-sales';
					if ( (string)$property->department == 'Lettings' )
					{
						$department = 'residential-lettings';
					}
					elseif ( (string)$property->department == 'Commercial' )
					{
						$department = 'commercial';
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branchID . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branchID . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branchID . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branchID]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branchID] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branchID]);
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
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->propertyBedrooms) ) ? (string)$property->propertyBedrooms : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->propertyBathrooms) ) ? (string)$property->propertyBathrooms : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->propertyReceptionRooms) ) ? (string)$property->propertyReceptionRooms : '' ) );

					update_post_meta( $post_id, '_council_tax_band', ( ( isset($property->councilTaxBand) ) ? (string)$property->councilTaxBand : '' ) );

					$prefix = '';
					if ( (string)$property->department == 'Commercial' )
					{
						$prefix = 'commercial_';
					}
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
					
					wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

					if ( (string)$property->department == 'Agricultural' )
					{
						// Find type with name 'Land' or 'Agricultural'
						// In future we would add mapping for this but scenario is so rare
						$term = get_term_by('name', 'Land', 'property_type');
						if ( $term !== FALSE )
						{
							wp_set_object_terms( $post_id, (int)$term->term_id, 'property_type' );
						}
						else
						{
							$term = get_term_by('name', 'Agricultural', 'property_type');
							if ( $term !== FALSE )
							{
								wp_set_object_terms( $post_id, (int)$term->term_id, 'property_type' );
							}
						}
					}
					elseif ( (string)$property->department == 'Commercial' )
					{
						if ( isset($property->propertyTypes) && isset($property->propertyTypes->propertyType) )
						{
							$property_types = $property->propertyTypes->propertyType;
							if ( !is_array($property_types) )
							{
								$property_types = array($property_types);
							}
							
							foreach ( $property->propertyTypes->propertyType as $propertyType )
							{
								$propertyType = (string)$propertyType;
								if ( !empty($mapping) && isset($mapping[$propertyType]) )
								{
									wp_set_object_terms( $post_id, (int)$mapping[$propertyType], $prefix . 'property_type', TRUE );
								}
								else
								{
									$this->log( 'Property received with a type (' . $propertyType . ') that is not mapped', $post_id, (string)$property->propertyID );

									$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $propertyType, $post_id );
								}
							}
						}
					}
					else
					{
						if ( isset($property->propertyType) && isset($property->propertyStyle) )
						{
							if ( !empty($mapping) && isset($mapping[(string)$property->propertyType . ' - ' . (string)$property->propertyStyle]) )
							{
								wp_set_object_terms( $post_id, (int)$mapping[(string)$property->propertyType . ' - ' . (string)$property->propertyStyle], $prefix . 'property_type' );
							}
							else
							{
								$this->log( 'Property received with a type (' . (string)$property->propertyType . ' - ' . (string)$property->propertyStyle . ') that is not mapped', $post_id, (string)$property->propertyID );

								$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->propertyType . ' - ' . (string)$property->propertyStyle, $post_id );
							}
						}
					}

					// Residential Sales Details
					if ( (string)$property->department == 'Sales' || (string)$property->department == 'Agricultural' )
					{
						// Clean price
						$price = '';
						if ( (string)$property->department == 'Agricultural' )
						{
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->priceTo));
						}
						else
						{
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
						}
						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );
						update_post_meta( $post_id, '_poa', ( ( isset($property->forSalePOA) && $property->forSalePOA == '1' ) ? 'yes' : '') );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						if ( !empty($mapping) && isset($property->priceQualifier) && isset($mapping[(string)$property->priceQualifier]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->priceQualifier], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

			            // Tenure
			            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

						if ( !empty($mapping) && isset($property->propertyTenure) && isset($mapping[(string)$property->propertyTenure]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->propertyTenure], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }

			            // Sale By
			            $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

						if ( !empty($mapping) && isset($property->saleBy) && isset($mapping[(string)$property->saleBy]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->saleBy], 'sale_by' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'sale_by' );
			            }

			            if ( isset($property->propertyTenure) && (string)$property->propertyTenure == '2' )
			            {
				            //update_post_meta( $post_id, '_leasehold_years_remaining', ( ( isset($property->tenure_unexpired_years) && !empty((string)$property->tenure_unexpired_years) ) ? (string)$property->tenure_unexpired_years : '' ) );
							update_post_meta( $post_id, '_service_charge', ( ( isset($property->serviceCharge) && !empty((string)$property->serviceCharge) ) ? (string)$property->serviceCharge : '' ) );
							update_post_meta( $post_id, '_ground_rent', ( ( isset($property->groundRent->amount) && !empty((string)$property->groundRent->amount) ) ? (string)$property->groundRent->amount : '' ) );
							//update_post_meta( $post_id, '_ground_rent_review_years', ( ( isset($property->ground_rent_review_period_years) && !empty((string)$property->ground_rent_review_period_years) ) ? (string)$property->ground_rent_review_period_years : '' ) );
							//update_post_meta( $post_id, '_shared_ownership', ( (string)$property->shared_ownership == '1' ? 'yes' : '' ) );
							//update_post_meta( $post_id, '_shared_ownership_percentage', ( (string)$property->shared_ownership == '1' ? str_replace( "%", "", (string)$property->shared_ownership_percentage ) : '' ) );
						}
					}
					elseif ( (string)$property->department == 'Lettings' )
					{
						// Clean price
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->rent));

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						$price_actual = $price;
						switch ((string)$property->rentFrequency)
						{
							case "1": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
							case "2": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
							case "3": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', $price_actual );
						
						update_post_meta( $post_id, '_poa', ( ( isset($property->toLetPOA) && $property->toLetPOA == '1' ) ? 'yes' : '') );

						update_post_meta( $post_id, '_deposit', '' );
	            		update_post_meta( $post_id, '_available_date', '' );
					}
					elseif ( (string)$property->department == 'Commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

	            		if ( (string)$property->forSale == '1' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    $price = preg_replace("/[^0-9.]/", '', (string)$property->priceFrom);
		                    if ( $price == '' || $price == '0' )
		                    {
		                        $price = preg_replace("/[^0-9.]/", '', (string)$property->priceTo);
		                    }
		                    update_post_meta( $post_id, '_price_from', $price );

		                    $price = preg_replace("/[^0-9.]/", '', (string)$property->priceTo);
		                    if ( $price == '' || $price == '0' )
		                    {
		                        $price = preg_replace("/[^0-9.]/", '', (string)$property->priceFrom);
		                    }
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( (string)$property->forSalePOA == '1' ? 'yes' : '' ) );

		                    // Tenure
				            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

							if ( !empty($mapping) && isset($property->propertyTenure) && isset($mapping[(string)$property->propertyTenure]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->propertyTenure], 'commercial_tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
				            }

				            // Sale By
				            $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

							if ( !empty($mapping) && isset($property->saleBy) && isset($mapping[(string)$property->saleBy]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->saleBy], 'sale_by' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'sale_by' );
				            }
		                }

		                if ( (string)$property->toLet == '1' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->rentFrom);
		                    if ( $rent == '' || $rent == '0' )
		                    {
		                        $rent = preg_replace("/[^0-9.]/", '', (string)$property->rentTo);
		                    }
		                    update_post_meta( $post_id, '_rent_from', $rent );

		                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->rentTo);
		                    if ( $rent == '' || $rent == '0' )
		                    {
		                        $rent = preg_replace("/[^0-9.]/", '', (string)$property->rentFrom);
		                    }
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    update_post_meta( $post_id, '_rent_units', (string)$property->rentFrequency);

		                    update_post_meta( $post_id, '_rent_poa', ( (string)$property->toLetPOA == '1' ? 'yes' : '' ) );
		                }

		                // Store price in common currency (GBP) used for ordering
			            $ph_countries = new PH_Countries();
			            $ph_countries->update_property_price_actual( $post_id );

			            $size = preg_replace("/[^0-9.]/", '', (string)$property->floorAreaFrom);
			            if ( $size == '' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', (string)$property->floorAreaTo);
			            }
			            if ( (string)$property->floorAreaFrom == '0.00' && (string)$property->floorAreaTo == '0.00' )
			            {
			            	$size = '';
			            }
			            update_post_meta( $post_id, '_floor_area_from', $size );

			            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->floorAreaUnits ) ) );

			            $size = preg_replace("/[^0-9.]/", '', (string)$property->floorAreaTo);
			            if ( $size == '' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', (string)$property->floorAreaFrom);
			            }
			            if ( (string)$property->floorAreaFrom == '0.00' && (string)$property->floorAreaTo == '0.00' )
			            {
			            	$size = '';
			            }
			            update_post_meta( $post_id, '_floor_area_to', $size );

			            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->floorAreaUnits ) ) );

			            update_post_meta( $post_id, '_floor_area_units', str_replace(" ", "", (string)$property->floorAreaUnits ) );

			            $size = preg_replace("/[^0-9.]/", '', (string)$property->siteArea);
			            if ( (string)$property->siteArea == '0.00' )
			            {
			            	$size = '';
			            }

			            update_post_meta( $post_id, '_site_area_from', $size );

			            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->siteAreaUnits ) ) );

			            update_post_meta( $post_id, '_site_area_to', $size );

			            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->siteAreaUnits ) ) );

			            update_post_meta( $post_id, '_site_area_units', str_replace(" ", "", (string)$property->siteAreaUnits ) );
					}

					$departments_with_residential_details = apply_filters( 'propertyhive_departments_with_residential_details', array( 'residential-sales', 'residential-lettings' ) );
	                if ( in_array($department, $departments_with_residential_details) )
	                {
	                    // Electricity
	                    $utility_type = [];
	                    $utility_type_other = '';
	                    if ( isset( $property->utilities->electric->type ) ) 
	                    {
                            $supply_value = (string)$property->utilities->electric->type;
                            if ( !empty($supply_value) )
                            {
	                            switch ( $supply_value ) 
	                            {
	                                default: 
	                                    $utility_type[] = 'other'; 
	                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
	                                    break;
	                            }
	                            $utility_type = array_unique($utility_type);
	                        }
	                    }
	                    update_post_meta( $post_id, '_electricity_type', $utility_type );
	                    if ( in_array( 'other', $utility_type ) ) 
	                    {
	                        update_post_meta( $post_id, '_electricity_type_other', $utility_type_other );
	                    }

	                    // Water
	                    $utility_type = [];
	                    $utility_type_other = '';
	                    if ( isset( $property->utilities->water->type ) ) 
	                    {
                            $supply_value = (string)$property->utilities->water->type;
                            if ( !empty($supply_value) )
                            {
	                            switch ( $supply_value ) 
	                            {
	                                default: 
	                                    $utility_type[] = 'other'; 
	                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
	                                    break;
	                            }
		                        $utility_type = array_unique($utility_type);
		                    }
	                    }
	                    update_post_meta( $post_id, '_water_type', $utility_type );
	                    if ( in_array( 'other', $utility_type ) ) 
	                    {
	                        update_post_meta( $post_id, '_water_type_other', $utility_type_other );
	                    }
	                    
	                    // Heating
	                    $utility_type = [];
	                    $utility_type_other = '';
	                    if ( isset( $property->heating->type ) ) 
	                    {
                            $source_value = (string)$property->heating->type;
                            if ( !empty($source_value) )
                            {
	                            switch ( $source_value ) 
	                            {
	                                default: 
	                                    $utility_type[] = 'other'; 
	                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
	                                    break;
	                            }
		                        $utility_type = array_unique($utility_type);
		                    }
	                    }
	                    update_post_meta( $post_id, '_heating_type', $utility_type );
	                    if ( in_array( 'other', $utility_type ) ) 
	                    {
	                        update_post_meta( $post_id, '_heating_type_other', $utility_type_other );
	                    }

	                    // Broadband
	                    $utility_type = [];
	                    $utility_type_other = '';
	                    if ( isset( $property->utilities->broadband->type ) ) 
	                    {
                            $supply_value = (string)$property->utilities->broadband->type;
                            if ( !empty($supply_value) )
                            {
	                            switch ( $supply_value ) 
	                            {
	                                default: 
	                                    $utility_type[] = 'other'; 
	                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
	                                    break;
	                            }
		                        $utility_type = array_unique($utility_type);
		                    }
	                    }
	                    update_post_meta( $post_id, '_broadband_type', $utility_type );
	                    if ( in_array( 'other', $utility_type ) ) 
	                    {
	                        update_post_meta( $post_id, '_broadband_type_other', $utility_type_other );
	                    }

	                    // Sewerage
	                    $utility_type = [];
	                    $utility_type_other = '';
	                    if ( isset( $property->utilities->sewerage->type ) ) 
	                    {
                            $supply_value = (string)$property->utilities->sewerage->type;
                            if ( !empty($supply_value) )
                            {
	                            switch ( $supply_value ) 
	                            {
	                                default: 
	                                    $utility_type[] = 'other'; 
	                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
	                                    break;
	                            }
		                        $utility_type = array_unique($utility_type);
		                    }
	                    }
	                    update_post_meta( $post_id, '_sewerage_type', $utility_type );
	                    if ( in_array( 'other', $utility_type ) ) 
	                    {
	                        update_post_meta( $post_id, '_sewerage_type_other', $utility_type_other );
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
						update_post_meta( $post_id, '_featured', ( isset($property->featuredProperty) && (string)$property->featuredProperty == '1' ) ? 'yes' : '' );
					}
					
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					if ( !empty($mapping) && isset($property->availability) && isset($mapping[(string)$property->availability]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->availability], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					for ( $i = 1; $i <= 20; ++$i )
					{
						if ( isset($property->{'propertyFeature' . $i}) && trim((string)$property->{'propertyFeature' . $i}) != '' )
						{
							$features[] = trim((string)$property->{'propertyFeature' . $i});
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
			        if ( (string)$property->department == 'Commercial' )
					{
						update_post_meta( $post_id, '_descriptions', '1' );
						update_post_meta( $post_id, '_description_name_0', '' );
			            update_post_meta( $post_id, '_description_0', str_replace(array("\r\n", "\n"), "", (string)$property->fullDescription) );
					}
					else
					{
						update_post_meta( $post_id, '_rooms', '1' );
						update_post_meta( $post_id, '_room_name_0', '' );
			            update_post_meta( $post_id, '_room_dimensions_0', '' );
			            update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", (string)$property->fullDescription) );
			        }

			        // Media - Images
			        $media = array();
				    if (isset($property->images) && !empty($property->images))
	                {
	                    foreach ($property->images as $images)
	                    {
	                        if (!empty($images->image))
	                        {
	                            foreach ($images->image as $image)
	                            {
	                            	$url = str_replace("http://", "https://", (string)$image);
									$url = apply_filters('propertyhive_jupix_image_url', $url);

									$media_attributes = $image->attributes();
									$modified = (string)$media_attributes['modified'];

									$media[] = array(
										'url' => $url,
										'modified' => $modified,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->propertyID, 'photo', $media, true );

					// Media - Floorplans
					$media = array();
				    if (isset($property->floorplans) && !empty($property->floorplans))
	                {
	                    foreach ($property->floorplans as $floorplans)
	                    {
	                        if (!empty($floorplans->floorplan))
	                        {
	                            foreach ($floorplans->floorplan as $floorplan)
	                            {
	                            	$url = str_replace("http://", "https://", (string)$floorplan);
									$url = apply_filters('propertyhive_jupix_floorplan_url', $url);

									$media_attributes = $floorplan->attributes();
									$modified = (string)$media_attributes['modified'];

									$media[] = array(
										'url' => $url,
										'modified' => $modified,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->propertyID, 'floorplan', $media, true );

					// Media - Brochures
					$media = array();
				    if (isset($property->brochures) && !empty($property->brochures))
	                {
	                    foreach ($property->brochures as $brochures)
	                    {
	                        if (!empty($brochures->brochure))
	                        {
	                            foreach ($brochures->brochure as $brochure)
	                            {
	                            	$url = str_replace("http://", "https://", (string)$brochure);

									$media_attributes = $brochure->attributes();
									$modified = (string)$media_attributes['modified'];

									$media[] = array(
										'url' => $url,
										'modified' => $modified,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->propertyID, 'brochure', $media, true );

					// Media - EPCs
					$media = array();
					if ( apply_filters( 'propertyhive_property_import_jupix_import_epc_graphs', TRUE ) === TRUE )
					{
					    if (isset($property->epcGraphs) && !empty($property->epcGraphs))
		                {
		                    foreach ($property->epcGraphs as $epcGraphs)
		                    {
		                        if (!empty($epcGraphs->epcGraph))
		                        {
		                            foreach ($epcGraphs->epcGraph as $epcGraph)
		                            {
		                            	$url = str_replace("http://", "https://", (string)$epcGraph);

										$media_attributes = $epcGraph->attributes();
										$modified = (string)$media_attributes['modified'];

										$media[] = array(
											'url' => $url,
											'modified' => $modified,
										);
									}
								}
							}
						}
					}
					if ( apply_filters( 'propertyhive_property_import_jupix_import_epc_front_pages', TRUE ) === TRUE )
					{
						if (isset($property->epcFrontPages) && !empty($property->epcFrontPages))
		                {
		                    foreach ($property->epcFrontPages as $epcFrontPages)
		                    {
		                        if (!empty($epcFrontPages->epcFrontPage))
		                        {
		                            foreach ($epcFrontPages->epcFrontPage as $epcFrontPage)
		                            {
		                            	$url = str_replace("http://", "https://", (string)$epcFrontPage);

										$media_attributes = $epcFrontPage->attributes();
										$modified = (string)$media_attributes['modified'];

										$filename = basename( $url );

										// Make sure it has an extension
										if ( strpos($filename, '.') === FALSE )
										{
											$filename .= '.pdf';
										}

										$media[] = array(
											'url' => $url,
											'filename' => $filename,
											'modified' => $modified,
										);
									}
								}
							}
						}
					}
					$epc_data = array();
					if ( 
						apply_filters( 'propertyhive_property_import_jupix_import_epc_ratings', TRUE ) === TRUE
						&&
						(
							(
								isset($property->epc->eerCurrentValue) && (string)$property->epc->eerCurrentValue != '' && 
								isset($property->epc->eerPotentialValue) && (string)$property->epc->eerPotentialValue != '' 
							)
							||
							( 
								isset($property->epc->eirCurrentValue) && (string)$property->epc->eirCurrentValue != '' && 
								isset($property->epc->eirPotentialValue) && (string)$property->epc->eirPotentialValue != '' 
							)
						)
					)
			        {
			        	$epc_data = array(
			        		'eec' => (string)$property->epc->eerCurrentValue,
			        		'eep' => (string)$property->epc->eerPotentialValue,
			        		'eic' => (string)$property->epc->eirCurrentValue,
			        		'eip' => (string)$property->epc->eirPotentialValue,
			        	);
				    }

					$this->import_media( $post_id, (string)$property->propertyID, 'epc', $media, true, false, $epc_data );

					// Media - Virtual Tours
					$virtual_tours = array();
					if (isset($property->virtualTours) && !empty($property->virtualTours))
	                {
	                    foreach ($property->virtualTours as $virtualTours)
	                    {
	                        if (!empty($virtualTours->virtualTour))
	                        {
	                            foreach ($virtualTours->virtualTour as $virtualTour)
	                            {
	                            	$virtual_tours[] = $virtualTour;
	                            }
	                        }
	                    }
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->propertyID );

					do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
					do_action( "propertyhive_property_imported_jupix_xml", $post_id, $property, $this->import_id );

					$post = get_post( $post_id );
					do_action( "save_post_property", $post_id, $post, false );
					do_action( "save_post", $post_id, $post, false );

					if ( $inserted_updated == 'updated' )
					{
						$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->propertyID, $metadata_before, $taxonomy_terms_before );
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->propertyID );
				}
				
				update_post_meta( $post_id, '_jupix_xml_update_date_' . $this->import_id, (string)$property->dateLastModified . ' ' . (string)$property->timeLastModified );			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_jupix_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->propertyID;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                '1' => 'On Hold',
                '2' => 'For Sale',
                '3' => 'Under Offer',
                '4' => 'Sold STC',
                '5' => 'Sold',
            ),
            'lettings_availability' => array(
                '1' => 'On Hold',
                '2' => 'To Let',
                '3' => 'References Pending',
                '4' => 'Let Agreed',
                '5' => 'Let',
            ),
            'commercial_availability' => array(
                '1' => 'On Hold',
                '2' => 'For Sale',
                '3' => 'To Let',
                '4' => 'For Sale / To Let',
                '5' => 'Under Offer',
                '6' => 'Sold STC',
                '7' => 'Exchanged',
                '8' => 'Completed',
                '9' => 'Let Agreed',
                '10' => 'Let',
                '11' => 'Withdrawn',
            ),
            'property_type' => array(
                '1 - 1' => 'House - Barn Conversion',
                '1 - 2' => 'House - Cottage',
                '1 - 3' => 'House - Chalet',
                '1 - 4' => 'House - Detached House',
                '1 - 5' => 'House - Semi-Detached House',
                '1 - 28' => 'House - Link Detached',
                '1 - 6' => 'House - Farm House',
                '1 - 7' => 'House - Manor House',
                '1 - 8' => 'House - Mews',
                '1 - 9' => 'House - Mid Terraced House',
                '1 - 10' => 'House - End Terraced House',
                '1 - 11' => 'House - Town House',
                '1 - 12' => 'House - Villa',
                '1 - 29' => 'House - Shared House',
                '1 - 31' => 'House - Sheltered Housing',
                '2 - 13' => 'Flat - Apartment',
                '2 - 14' => 'Flat - Bedsit',
                '2 - 15' => 'Flat - Ground Floor Flat',
                '2 - 16' => 'Flat - Flat',
                '2 - 17' => 'Flat - Ground Floor Maisonette',
                '2 - 18' => 'Flat - Maisonette',
                '2 - 19' => 'Flat - Penthouse',
                '2 - 20' => 'Flat - Studio',
                '2 - 30' => 'Flat - Shared Flat',
                '3 - 21' => 'Bungalow - Detached Bungalow',
                '3 - 22' => 'Bungalow - Semi-Detached Bungalow',
                '3 - 34' => 'Bungalow - Mid Terraced Bungalow',
                '3 - 35' => 'Bungalow - End Terraced Bungalow',
                '4 - 23' => 'Other - Building Plot / Land',
                '4 - 24' => 'Other - Garage',
                '4 - 25' => 'Other - House Boat',
                '4 - 26' => 'Other - Mobile Home',
                '4 - 27' => 'Other - Parking',
                '4 - 32' => 'Other - Equestrian',
                '4 - 33' => 'Other - Unconverted Barn',
            ),
            'commercial_property_type' => array(
                '1' => 'Offices',
                '2' => 'Serviced Offices',
                '3' => 'Business Park',
                '4' => 'Science / Tech / R and D',
                '5' => 'A1 - High Street',
                '6' => 'A1 - Centre',
                '7' => 'A1 - Out Of Town',
                '8' => 'A1 - Other',
                '9' => 'A2 - Financial Services',
                '10' => 'A3 - Restaurants / Cafes',
                '11' => 'A4 - Pubs / Bars / Clubs',
                '12' => 'A5 - Take Away',
                '13' => 'B1 - Light Industrial',
                '14' => 'B2 - Heavy Industrial',
                '15' => 'B8 - Warehouse / Distribution',
                '16' => 'Science / Tech / R and D',
                '17' => 'Other Industrial',
                '18' => 'Caravan Park',
                '19' => 'Cinema',
                '20' => 'Golf Property',
                '21' => 'Guest  House / Hotel',
                '22' => 'Leisure Park',
                '23' => 'Leisure Other',
                '24' => 'Day Nursery / Child Care',
                '25' => 'Nursing & Care Homes',
                '26' => 'Surgeries',
                '27' => 'Petrol Stations',
                '28' => 'Show Room',
                '29' => 'Garage',
                '30' => 'Industrial (land)',
                '31' => 'Office (land)',
                '32' => 'Residential (land)',
                '33' => 'Retail (land)',
                '34' => 'Leisure (land)',
                '35' => 'Commercial / Other (land)',
            ),
            'price_qualifier' => array(
                '1' => 'Asking Price Of',
                '7' => 'Auction Guide Price',
                '2' => 'Fixed Price',
                '3' => 'From',
                '4' => 'Guide Price',
                '10' => 'Offers In Excess Of',
                '5' => 'Offers In Region Of',
                '11' => 'Offers Invited',
                '6' => 'Offers Over',
                '8' => 'Sale By Tender',
                '9' => 'Shared Ownership',
                '12' => 'Starting Bid',
            ),
            'tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Commonhold',
                '4' => 'Share of Freehold',
                '6' => 'Share Transfer',
                '5' => 'Flying Freehold',
                '7' => 'Unknown',
            ),
            'commercial_tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Commonhold',
                '4' => 'Share of Freehold',
                '6' => 'Share Transfer',
                '5' => 'Flying Freehold',
                '7' => 'Unknown',
            ),
            'sale_by' => array(
                '1' => 'Private Treaty',
                '2' => 'By Auction',
                '3' => 'Confidential',
                '5' => 'Offers Invited',
                '4' => 'By Tender',
            ),
            'furnished' => array(
                '1' => 'Furnished',
                '3' => 'Part Furnished',
                '4' => 'Unfurnished',
                '2' => 'Furnished Optional',
            ),
            'location' => array(
                '0' => '(blank)'
            ),
        );
	}
}

}