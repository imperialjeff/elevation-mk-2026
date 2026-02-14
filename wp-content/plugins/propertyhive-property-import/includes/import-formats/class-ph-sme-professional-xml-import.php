<?php
/**
 * Class for managing the import process of a SME Professional XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_SME_Professional_XML_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['xml_url'] ) ) 
			{
			    $import_settings['xml_url'] = wp_unslash( $_POST['xml_url'] );
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

		if ( empty($contents) )
		{
			$this->log_error( 'Data returned empty. Likely invalid URL' );

        	return false;
		}

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			$this->log("Parsing properties");

			$limit = $this->get_property_limit();
			
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
        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML' );

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

        do_action( "propertyhive_pre_import_properties_sme_professional_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_sme_professional_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_sme_professional_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->agent_ref == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->agent_ref );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->agent_ref, false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . (string)$property->agent_ref, 0, (string)$property->agent_ref, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->agent_ref, $property, (string)$property->p_addr_short, (string)$property->p_sdetails );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->agent_ref );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->agent_ref );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_sme_professional_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property->last_modified) ||
						(
							isset($property->last_modified) &&
							empty((string)$property->last_modified)
						) ||
						$previous_update_date == '' ||
						(
							isset($property->last_modified) &&
							(string)$property->last_modified != '' &&
							$previous_update_date != '' &&
							strtotime((string)$property->last_modified) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', ( ( isset($property->my_unique_id) ) ? (string)$property->my_unique_id : (string)$property->agent_ref ) );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property->flat_number) ) ? (string)$property->flat_number : '' ) . ' ' . ( ( isset($property->street_number) ) ? (string)$property->street_number : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->p_addr_short) ) ? (string)$property->p_addr_short : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->p_addr2) ) ? (string)$property->p_addr2 : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->area) ) ? (string)$property->area : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->city) ) ? (string)$property->city : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->p_postcode) ) ? (string)$property->p_postcode : '' ) );

					$country = 'GB';
					update_post_meta( $post_id, '_address_country', $country );

					$address_fields_to_check = apply_filters( 'propertyhive_sme_professional_xml_address_fields_to_check', array('p_addr2', 'area', 'city') );
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

					if ( isset($property->p_geocode_lat) && isset($property->p_geocode_lon) && (string)$property->p_geocode_lat != '' && (string)$property->p_geocode_lon != '' && (string)$property->p_geocode_lat != '0' && (string)$property->p_geocode_lon != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property->p_geocode_lat) ) ? (string)$property->p_geocode_lat : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property->p_geocode_lon) ) ? (string)$property->p_geocode_lon : '' ) );
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
							if ( isset($property->Address1) && trim((string)$property->Address1) != '' ) { $address_to_geocode[] = (string)$property->Address1; }
							if ( isset($property->Address2) && trim((string)$property->Address2) != '' ) { $address_to_geocode[] = (string)$property->Address2; }
							if ( isset($property->Area) && trim((string)$property->Area) != '' ) { $address_to_geocode[] = (string)$property->Area; }
							if ( isset($property->City) && trim((string)$property->City) != '' ) { $address_to_geocode[] = (string)$property->City; }
							if ( isset($property->Postcode) && trim((string)$property->Postcode) != '' ) { $address_to_geocode[] = (string)$property->Postcode; $address_to_geocode_osm[] = (string)$property->Postcode; }

							$return = $this->do_geocoding_lookup( $post_id, (string)$property->agent_ref, $address_to_geocode, $address_to_geocode_osm, $country );
						}
					}

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
						
					$office_id = $this->primary_office_id;
					if ( isset($property->branch_id) )
					{
						if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
						{
							foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
							{
								if ( $branch_code == (string)$property->branch_id )
								{
									$office_id = $ph_office_id;
									break;
								}
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'residential-lettings';
					if ( isset($property->sale_price) )
					{
						$department = 'residential-sales';
					}
					if ( 
						isset($property->is_commercial) && 
						(string)$property->is_commercial === 'true' &&
						get_option( 'propertyhive_active_departments_commercial' ) == 'yes' 
					)
					{
						$department = 'commercial';
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
					{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_id . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_id . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_id . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_id] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_id]);
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
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->p_rooms) ) ? (string)$property->p_rooms : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathrooms) ) ? (string)$property->bathrooms : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->public_rooms) ) ? (string)$property->public_rooms : '' ) );
					update_post_meta( $post_id, '_council_tax_band', ( ( isset($property->council_tax_band) && (string)$property->council_tax_band != 'X' ) ? (string)$property->council_tax_band : '' ) );

					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
					
					if ( isset($property->p_type) && (string)$property->p_type != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->p_type]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->p_type], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property->p_type . ') that is not mapped', $post_id, (string)$property->agent_ref );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->p_type );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					if ( $department == 'residential-sales' )
					{
						// Clean price
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->sale_price));
						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );

						update_post_meta( $post_id, '_poa', '' );

						update_post_meta( $post_id, '_currency', 'GBP' );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
						
						if ( !empty($mapping) && isset($property->price_qualifier) && isset($mapping[(string)$property->price_qualifier]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->price_qualifier], 'price_qualifier' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
						}

						// Tenure
						$mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();
						
						if ( !empty($mapping) && isset($property->business_tenure) && isset($mapping[(string)$property->business_tenure]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->business_tenure], 'tenure' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, 'tenure' );
						}
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->p_pcm));

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						$price_actual = $price;
						
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', $price_actual );
						
						update_post_meta( $post_id, '_poa', '' );

						update_post_meta( $post_id, '_currency', 'GBP' );

						update_post_meta( $post_id, '_deposit', ( ( isset($property->deposit) ) ? (string)$property->deposit : '' ) );
						
						$available_date = ( ( isset($property->p_avail) ) ? (string)$property->p_avail : '' );
						if ( $available_date != '' )
						{ 
							$explode_available_date = explode("/", $available_date);
							if ( count($explode_available_date) )
							{
								$available_date = $explode_available_date[2] . '-' . $explode_available_date[1] . '-' . $explode_available_date[0];
							}
						}
						update_post_meta( $post_id, '_available_date', $available_date );

						// Furnished
						$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

						if ( isset($property->furnished) && (string)$property->furnished != '' )
						{
							if ( !empty($mapping) && isset($mapping[(string)$property->furnished]) )
							{
								wp_set_object_terms( $post_id, (int)$mapping[(string)$property->furnished], 'furnished' );
							}
							else
							{
								wp_delete_object_term_relationships( $post_id, 'furnished' );

								$this->log( 'Property received with a furnished (' . (string)$property->furnished . ') that is not mapped', $post_id, (string)$property->agent_ref );

								$import_settings = $this->add_missing_mapping( $mapping, 'furnished', (string)$property->furnished );
							}
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

	            		if ( isset($property->sale_price) )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->sale_price));
		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', '' );

		                    // Tenure
							$mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();
							
							if ( !empty($mapping) && isset($property->business_tenure) && isset($mapping[(string)$property->business_tenure]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->business_tenure], 'commercial_tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
				            }
		                }

		                if ( !isset($property->sale_price) )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    $rent = round(preg_replace("/[^0-9.]/", '', (string)$property->p_pa));
		                    update_post_meta( $post_id, '_rent_from', $rent );
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    update_post_meta( $post_id, '_rent_units', 'pa');

		                    update_post_meta( $post_id, '_rent_poa', '');
		                }

		                // Store price in common currency (GBP) used for ordering
			            $ph_countries = new PH_Countries();
			            $ph_countries->update_property_price_actual( $post_id );

			            $size = preg_replace("/[^0-9.]/", '', (string)$property->floorArea);
			            update_post_meta( $post_id, '_floor_area_from', $size );

			            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, 'sqm' ) );

			            update_post_meta( $post_id, '_floor_area_to', $size );

			            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, 'sqm' ) );

			            update_post_meta( $post_id, '_floor_area_units', 'sqm' );

			            $size = '';
			            update_post_meta( $post_id, '_site_area_from', $size );
			            update_post_meta( $post_id, '_site_area_from_sqft', $size );
			            update_post_meta( $post_id, '_site_area_to', $size );
			            update_post_meta( $post_id, '_site_area_to_sqft', $size );
			            update_post_meta( $post_id, '_site_area_units', 'sqft' );
					}

		            // Parking
					$mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();
					
					if ( isset($property->parking) && (string)$property->parking != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->parking]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->parking], 'parking' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, 'parking' );

							$this->log( 'Property received with a parking (' . (string)$property->parking . ') that is not mapped', $post_id, (string)$property->agent_ref );

							$import_settings = $this->add_missing_mapping( $mapping, 'parking', (string)$property->parking );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, 'parking' );
					}

					// Outside Space
					$mapping = isset($import_settings['mappings']['outside_space']) ? $import_settings['mappings']['outside_space'] : array();
					
					if ( isset($property->garden) && (string)$property->garden != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->garden]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->garden], 'outside_space' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, 'outside_space' );
							
							$this->log( 'Property received with an outside_space (' . (string)$property->garden . ') that is not mapped', $post_id, (string)$property->agent_ref );

							$import_settings = $this->add_missing_mapping( $mapping, 'outside_space', (string)$property->garden );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, 'outside_space' );
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
						update_post_meta( $post_id, '_featured', ( ( isset($property->featured) && (string)$property->featured == 'true' ) ? 'yes' : '' ) );
					}
				
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] :
						array();
					
					if ( isset($property->status) && (string)$property->status != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->status]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->status], 'availability' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, 'availability' );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, 'availability' );
					}

		            // Features
					$features = array();
					foreach ($property->features->children() as $key => $value) 
					{
						if ( trim((string)$value) != '' )
						{
							$features[] = trim((string)$value);
						}
					}
					update_post_meta( $post_id, '_features', count( $features ) );
	        		
	        		$i = 0;
			        foreach ( $features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }

			        // Rooms
			        // For now put the whole description in one room
			        if ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_descriptions', '1' );
						update_post_meta( $post_id, '_description_name_0', '' );
			            update_post_meta( $post_id, '_description_0', (string)$property->p_details );
					}
					else
					{
						update_post_meta( $post_id, '_rooms', '1' );
						update_post_meta( $post_id, '_room_name_0', '' );
			            update_post_meta( $post_id, '_room_dimensions_0', '' );
			            update_post_meta( $post_id, '_room_description_0', (string)$property->p_details );
			        }

		            // Media - Images
		            $media = array();
				    if (isset($property->photos) && !empty($property->photos))
	                {
		                foreach ($property->photos as $images)
		                {
		                    if (!empty($images->photo))
		                    {
		                        foreach ($images->photo as $image)
		                        {
		                        	$url = (string)$image;

		                        	$explode_url = explode('?', $url);
									$filename = basename( $explode_url[0] );

									$media[] = array(
										'url' => $url,
										'filename' => $filename,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'photo', $media, false );

					// Media - Floorplans
		            $media = array();
				    if (isset($property->floor_plan_pdf_file_url) && (string)$property->floor_plan_pdf_file_url != '')
	                {
	                	$url = (string)$property->floor_plan_pdf_file_url;

	                	$explode_url = explode('?', $url);
						$filename = basename( $explode_url[0] );

						$media[] = array(
							'url' => $url,
							'filename' => $filename,
						);
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'floorplan', $media, false );

					// Media - EPCs
		            $media = array();
				    if (isset($property->epc_pdf_file_url) && (string)$property->epc_pdf_file_url != '')
	                {
	                	$url = (string)$property->epc_pdf_file_url;

	                	$explode_url = explode('?', $url);
						$filename = basename( $explode_url[0] );
						
						$media[] = array(
							'url' => $url,
							'filename' => $filename,
						);
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'epc', $media, false );

					// Media - Virtual Tours
					$virtual_tours = array();
					if (isset($property->vir) && (string)$property->vir != '')
	                {
	                    $virtual_tours[] = (string)$property->vir;          
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->agent_ref );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->agent_ref );
				}

				if ( isset($property->last_modified) ) { update_post_meta( $post_id, '_sme_professional_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime((string)$property->last_modified)) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_sme_professional_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->agent_ref, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_sme_professional_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->agent_ref;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'let' => 'To Let',
                'underoffer' => 'Under Offer',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Sold' => 'Sold',
                'let' => 'To Let',
                'underoffer' => 'Under Offer',
            ),
            'property_type' => array(
                'F' => 'Flat',
                'S' => 'Studio',
                'H' => 'Detached House',
                'SH' => 'Semi Detached House',
                'TH' => 'Terraced House',
                'M' => 'Mews',
                'T' => 'Town House',
                'B' => 'Bungalow',
                'P' => 'Penthouse',
                'SA' => 'Serviced Apartment',
                'D' => 'Double Upper',
                'I' => 'Single Room',
                'J' => 'Double Room',
                'V' => 'Villa',
                'C' => 'Cottage',
                'G' => 'Garage',
                'Q' => 'Parking Space',
            ),
            'commercial_property_type' => array(
                'CA1' => 'Commercial 1',
                'CA2' => 'Commercial 2',
                'CA3' => 'Commercial 3',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Unfurnished' => 'Unfurnished',
                'Part furnished' => 'Part furnished',
            ),
            'parking' => array(
                'Private' => 'Private',
                'Driveway' => 'Driveway',
                'Garage' => 'Garage',
                'On street parking' => 'On street parking',
            ),
            'outside_space' => array(
                'Private Garden' => 'Private Garden',
            ),
            'price_qualifier' => array(
                'Default' => 'Default',
                'Guide price' => 'Guide price',
                'OIEO' => 'OIEO',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
        );
	}
}

}