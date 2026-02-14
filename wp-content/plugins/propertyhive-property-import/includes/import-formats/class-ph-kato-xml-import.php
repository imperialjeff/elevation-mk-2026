<?php
/**
 * Class for managing the import process of a Kato (previously agentsinsight) XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Kato_XML_Import extends PH_Property_Import_Process {

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

		$xml = simplexml_load_string( $contents );

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

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_agentsinsight_xml", $this->properties, $this->import_id );
        do_action( "propertyhive_pre_import_properties_kato_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_agentsinsight_xml_properties_due_import", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_kato_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_kato_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->id == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->id );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->id, false );
			
			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->id, 0, (string)$property->id, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = array();
			if ( (string)$property->address1 != '' )
			{
				$display_address[] = (string)$property->address1;
			}
			if ( (string)$property->address2 != '' )
			{
				$display_address[] = (string)$property->address2;
			}
			if ( (string)$property->town != '' )
			{
				$display_address[] = (string)$property->town;
			}
			$display_address = implode(", ", $display_address);

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, $display_address, (string)$property->specification_summary );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->id );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$skip_property = false;

				$previous_update_date = get_post_meta( $post_id, '_kato_xml_update_date_' . $this->import_id, TRUE);

				/*if (
					( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				)
				{
					if (
						$previous_update_date == (string)$property->dateLastModified . ' ' . (string)$property->timeLastModified
					)
					{
						$skip_property = true;
					}
				}*/

				// Coordinates
				if ( isset($property->lat) && isset($property->lon) && (string)$property->lat != '' && (string)$property->lon != '' && (string)$property->lat != '0' && (string)$property->lon != '0' )
				{
					update_post_meta( $post_id, '_latitude', ( ( isset($property->lat) ) ? (string)$property->lat : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property->lon) ) ? (string)$property->lon : '' ) );
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
						if ( trim($property->name) != '' ) { $address_to_geocode[] = (string)$property->name; }
						if ( trim($property->address1) != '' ) { $address_to_geocode[] = (string)$property->address1; }
						if ( trim($property->address2) != '' ) { $address_to_geocode[] = (string)$property->address2; }
						if ( trim($property->town) != '' ) { $address_to_geocode[] = (string)$property->town; }
						if ( trim($property->county) != '' ) { $address_to_geocode[] = (string)$property->county; }
						if ( trim($property->postcode) != '' ) { $address_to_geocode[] = (string)$property->postcode; $address_to_geocode_osm[] = (string)$property->postcode; }

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode_osm, 'GB' );
					}
				}

				if ( !$skip_property )
				{
					update_post_meta( $post_id, $imported_ref_key, (string)$property->id );

					// Address
					update_post_meta( $post_id, '_reference_number', (string)$property->id );
					update_post_meta( $post_id, '_address_name_number', ( ( isset($property->name) ) ? (string)$property->name : '' ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->address1) ) ? (string)$property->address1 : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->address2) ) ? (string)$property->address2 : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->town) ) ? (string)$property->town : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->county) ) ? (string)$property->county : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

					$country = 'GB';
					/*if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
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
					}*/
					update_post_meta( $post_id, '_address_country', $country );

	            	// Let's look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_agentsinsight_xml_address_fields_to_check', array('town', 'county') );
	            	$address_fields_to_check = apply_filters( 'propertyhive_kato_xml_address_fields_to_check', $address_fields_to_check );
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

					if ( isset($property->submarkets) )
					{
						foreach ( $property->submarkets as $submarkets )
						{
							if ( isset($submarkets->submarket) )
							{
								foreach ( $submarkets->submarket as $submarket )
								{
									$term = term_exists( trim((string)$submarket->name), 'location');
									if ( $term !== 0 && $term !== null && isset($term['term_id']) )
									{
										$location_term_ids[] = (int)$term['term_id'];
									}
								}
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

                    // Check if negotiator exists with this name
                    if ( isset($property->contacts) && isset($property->contacts->contact) )
					{
						foreach ( $property->contacts->contact as $contact )
						{
                            if ( empty($new_negotiator_id) && isset($contact->name) && !empty((string)$contact->name) )
							{
	                            foreach ( $this->negotiators as $negotiator_key => $negotiator )
	                            {
	                                if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim( (string)$contact->name )) )
	                                {
	                                    $new_negotiator_id = $negotiator_key;
	                                }
	                            }
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

					$branch_name = '';
					if ( isset($property->contacts) && isset($property->contacts->contact) )
					{
						foreach ( $property->contacts->contact as $contact )
						{
							if ( isset($contact->branch) && !empty((string)$contact->branch) )
							{
								$branch_name = (string)$contact->branch;
								break;
							}
						}
					}

					if ( !empty($branch_name) )
					{
						if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
						{
							foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
							{
								if ( strtolower($branch_code) == strtolower($branch_name) )
								{
									$office_id = $ph_office_id;
									break;
								}
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'commercial';

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$branch_name . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$branch_name . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$branch_name . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$branch_name]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$branch_name] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$branch_name]);
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

					$prefix = 'commercial_';

					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
					
					if ( isset($property->types) && isset($property->types->type) )
					{
						$property_types = $property->types->type;
						if ( !is_array($property_types) )
						{
							$property_types = array($property_types);
						}

						$term_ids = array();
						
						foreach ( $property->types->type as $type )
						{
							$propertyType = (string)$type;
							if ( !empty($mapping) && isset($mapping[$propertyType]) )
							{
								$term_ids[] = (int)$mapping[$propertyType];
							}
							else
							{
								$this->log( 'Property received with a type (' . $propertyType . ') that is not mapped', $post_id, (string)$property->id );

								$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $propertyType );
							}
						}

						if ( !empty($term_ids) )
						{
							wp_set_object_terms( $post_id, $term_ids, $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					update_post_meta( $post_id, '_for_sale', '' );
            		update_post_meta( $post_id, '_to_rent', '' );

            		if ( isset($property->price) && (string)$property->price != '' )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	                    $price = preg_replace("/[^0-9.]/", '', (string)$property->price_components->value);
	                    if ( empty($price) )
	                    {
		                    $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
		                }
	                    update_post_meta( $post_id, '_price_from', $price );
	                    update_post_meta( $post_id, '_price_to', $price );

	                    update_post_meta( $post_id, '_price_units', '' );

	                    update_post_meta( $post_id, '_price_poa', ( (string)$property->price_components->on_application == '1' ? 'yes' : '' ) );

	                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
						$mapping = array_change_key_case($mapping, CASE_LOWER);

						if ( !empty($mapping) && isset($property->price_components->qualifier) && isset($mapping[strtolower((string)$property->price_components->qualifier)]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[strtolower((string)$property->price_components->qualifier)], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }
	                }

	                if ( isset($property->rent) && (string)$property->rent != '' )
	                {
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->rent_components->from);
	                    $rent_to = preg_replace("/[^0-9.]/", '', (string)$property->rent_components->to);
	                    update_post_meta( $post_id, '_rent_from', $rent );
	                    update_post_meta( $post_id, '_rent_to', $rent_to );

	                    $rent_frequency = 'pcm';
	                    if ( strpos(strtolower((string)$property->rent), 'annum') !== false )
	                    {
	                    	$rent_frequency = 'pa';
	                    }
	                    elseif ( strpos(strtolower((string)$property->rent), 'ft') !== false )
	                    {
	                    	$rent_frequency = 'psqft';
	                    }

	                    update_post_meta( $post_id, '_rent_units', $rent_frequency);

	                    update_post_meta( $post_id, '_rent_poa', ( (string)$property->rent_components->on_application == '1' ? 'yes' : '' ) );
	                }

	                // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

		            $size = preg_replace("/[^0-9.]/", '', (string)$property->size_from);
		            update_post_meta( $post_id, '_floor_area_from', $size );

		            $size = preg_replace("/[^0-9.]/", '', (string)$property->size_to);
		            update_post_meta( $post_id, '_floor_area_to', $size );

		            $size = preg_replace("/[^0-9.]/", '', (string)$property->size_from_sqft);
		            update_post_meta( $post_id, '_floor_area_from_sqft', $size );

		            $size = preg_replace("/[^0-9.]/", '', (string)$property->size_to_sqft);
		            update_post_meta( $post_id, '_floor_area_to_sqft', $size );

		            $units = 'sqft';
		            switch ( (string)$property->area_size_unit )
		            {
		            	case "acres": { $units = 'acre'; break; }
		            }
		            update_post_meta( $post_id, '_floor_area_units', $units );

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
					if ( $on_market_by_default === true )
					{
						update_post_meta( $post_id, '_on_market', 'yes' );
					}
					$featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', ( isset($property->featured) && (string)$property->featured == 't' ) ? 'yes' : '' );
					}
					
					// Availability
					$prefix = 'commercial_';
					
					$mapping = isset($import_settings['mappings'][$prefix . 'availability']) ? $import_settings['mappings'][$prefix . 'availability'] : array();
					
					if ( !empty($mapping) && isset($property->status) && isset($mapping[(string)$property->status]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->status], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
		            $features = array();
		            if ( isset($property->key_selling_points) && !empty($property->key_selling_points) )
		            {
		            	foreach ( $property->key_selling_points as $key_selling_points )
		            	{
		            		if ( isset($key_selling_points->key_selling_point) && !empty($key_selling_points->key_selling_point) )
		            		{
			            		foreach ( $key_selling_points->key_selling_point as $feature )
				            	{
				            		$features[] = trim((string)$feature);
				            	}
				            }
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
			        $description_i = 0;

			        $descriptions = array(
			        	'specification_description',
			        	'location',
			        	'marketing_text_1',
			        	'marketing_text_2',
			        	'marketing_text_3',
			        	'marketing_text_4',
			        	'marketing_text_5',
			        	'marketing_text_6',
			        	'marketing_text_7',
			        	'marketing_text_8',
			        	'marketing_text_9',
			        	'marketing_text_10',
			        	'marketing_text_transport',
			        	'fitted_comment',
			        	'units',
			        );

			        $descriptions = apply_filters( 'propertyhive_kato_xml_descriptions', $descriptions, $this->import_id );

			        foreach ( $descriptions as $description )
			        {
			        	if ( ( isset($property->{$description}) && (string)$property->{$description} != '' ) || $description == 'units' )
				        {
				        	$title = '';
				        	if ( $description == 'location' )
				        	{
				        		$title = __( 'Location', 'propertyhive' );
				        	}
				        	elseif ( $description == 'units' )
				        	{
				        		$title = __( 'Available Area', 'propertyhive' );
				        	}
				        	elseif ( $description == 'fitted_comment' )
				        	{
				        		if ( isset($property->fitted) && (string)$property->fitted == 't' )
				        		{
				        			$title = __( 'Fitting Information', 'propertyhive' );
				        		}
				        		else
				        		{
				        			continue;
				        		}
				        	}
				        	elseif ( strpos($description, "_text_") !== false && isset($property->{str_replace("_text_", "_title_", $description)}) && (string)$property->{str_replace("_text_", "_title_", $description)} != '' )
				        	{
				        		$title = (string)$property->{str_replace("_text_", "_title_", $description)};
				        	}
				        	update_post_meta( $post_id, '_description_name_' . $description_i, $title );

				        	$description_contents = '';
				        	if ( $description == 'units' )
				        	{
				        		if ( 
									(
										apply_filters( 'propertyhive_import_agentsinsight_units_description_table', true) === true
										||
										apply_filters( 'propertyhive_import_kato_units_description_table', true) === true
									) && 
									isset($property->floor_units) && 
									!empty($property->floor_units) 
								)
								{
									$header_columns = array(
										'name' => 'Name/Floor',
										'sqft' => 'Sq Ft',
										'sqm' => 'Sq M',
									);
									if ( isset($property->rent) && (string)$property->rent != '' )
				                	{
				                		$header_columns['rent'] = 'Rent';
				                	}
				                	if ( isset($property->price) && (string)$property->price != '' )
				                	{
				                		$header_columns['price'] = 'Price';
				                	}
				                	$header_columns['availability'] = 'Availability';

				                	$header_columns = apply_filters( 'propertyhive_import_agentsinsight_units_description_table_header_columns', $header_columns);
				                	$header_columns = apply_filters( 'propertyhive_import_kato_units_description_table_header_columns', $header_columns);

									$units_table_html = '<table>
											<thead>
												<tr>';
									foreach ( $header_columns as $header_column )
									{
										$units_table_html .= '<th>' . __( $header_column, 'propertyhive' ) . '</th>';
									}
									$units_table_html .= '</tr>
											</thead>
											<tbody>';
									$total_size_sqft = 0;
									$total_size_m = 0;

									$floor_units = $property->floor_units->children();

									$num_units = $floor_units->count();

									foreach ($floor_units as $floor_unit)
									{
										$unit_name_parts = array_filter(array(
											isset($floor_unit->floorunit) ? $floor_unit->floorunit : '',
											isset($floor_unit->description) ? $floor_unit->description : '',
										));
										$unit_name = implode( ' ', $unit_name_parts );

										$size_sqft_float = (float)preg_replace("/[^0-9.]/", '', (string)$floor_unit->size_sqft);
										$size_sqm_float = $size_sqft_float * 0.09290304;

										$data_columns = array(
											'name' => $unit_name,
											'sqft' => str_replace(".00", "", (string)number_format($size_sqft_float, 2)),
											'sqm' => str_replace(".00", "", (string)number_format($size_sqm_float, 2)),
										);

										if ( isset($property->rent) && (string)$property->rent != '' )
					                	{
					                		$rent = '-';
					                		if ( isset($floor_unit->rent_available) && (string)$floor_unit->rent_available == 't' )
					                		{
					                			if ( isset($floor_unit->rent_price) && (string)$floor_unit->rent_price != '' )
						                		{
						                			if ( (string)$floor_unit->rent_price == 'On Application' )
						                			{
						                				$rent = (string)$floor_unit->rent_price;
						                			}
						                			else
						                			{
							                			$rent = ( (string)$floor_unit->rent_prefix != '' ? (string)$floor_unit->rent_prefix . ' ' : '' ) . '&pound;' . number_format((string)$floor_unit->rent_price) . ( (string)$floor_unit->rent_metric != '' ? ' / ' . (string)$floor_unit->rent_metric : '' ) . ( (string)$floor_unit->rent_suffix != '' ? ' ' . (string)$floor_unit->rent_suffix : '' );
							                		}
						                		}
					                		}
					                		$data_columns['rent'] = $rent;
					                	}
					                	if ( isset($property->price) && (string)$property->price != '' )
					                	{
					                		$price = '-';
					                		if ( isset($floor_unit->freehold_available) && (string)$floor_unit->freehold_available == 't' )
					                		{
					                			if ( isset($floor_unit->freehold_price) && (string)$floor_unit->freehold_price != '' )
						                		{
						                			if ( (string)$floor_unit->freehold_price == 'On Application' )
						                			{
						                				$price = (string)$floor_unit->freehold_price;
						                			}
						                			else
						                			{
							                			$price = ( (string)$floor_unit->freehold_prefix != '' ? (string)$floor_unit->freehold_prefix . ' ' : '' ) . '&pound;' . number_format((string)$floor_unit->freehold_price) . ( (string)$floor_unit->freehold_metric != '' ? ' / ' . (string)$floor_unit->freehold_metric : '' ) . ( (string)$floor_unit->freehold_suffix != '' ? ' ' . (string)$floor_unit->freehold_suffix : '' );
							                		}
						                		}
					                		}
					                		if ( isset($floor_unit->leasehold_available) && (string)$floor_unit->leasehold_available == 't' )
					                		{
					                			if ( isset($floor_unit->leasehold_price) && (string)$floor_unit->leasehold_price != '' )
						                		{
						                			if ( $price != '-' )
						                			{
						                				$price .= '<br>';
						                			}
						                			if ( (string)$floor_unit->leasehold_price == 'On Application' )
						                			{
						                				$price = (string)$floor_unit->leasehold_price;
						                			}
						                			else
						                			{
							                			$price = ( (string)$floor_unit->leasehold_prefix != '' ? (string)$floor_unit->leasehold_prefix . ' ' : '' ) . '&pound;' . number_format((string)$floor_unit->leasehold_price) . ( (string)$floor_unit->leasehold_metric != '' ? ' / ' . (string)$floor_unit->leasehold_metric : '' ) . ( (string)$floor_unit->leasehold_suffix != '' ? ' ' . (string)$floor_unit->leasehold_suffix : '' );
							                		}
						                		}
					                		}
					                		$data_columns['price'] = $price;
					                	}
					                	$data_columns['availability'] = (string)$floor_unit->status;

					                	$data_columns = apply_filters( 'propertyhive_import_agentsinsight_units_description_table_data_columns', $data_columns, $post_id, $property);
					                	$data_columns = apply_filters( 'propertyhive_import_kato_units_description_table_data_columns', $data_columns, $post_id, $property);

										$units_table_html .= '<tr>';
										foreach ( $data_columns as $data_column )
										{
											$units_table_html .= '<td>' . $data_column . '</td>';
										}
										$units_table_html .= '</tr>';

										$total_size_sqft += $size_sqft_float;
										$total_size_m += $size_sqm_float;
									}
									if ( 
										$num_units > 1 && 
										(
											apply_filters( 'propertyhive_import_agentsinsight_units_description_table_total', true) === true 
											||
											apply_filters( 'propertyhive_import_kato_units_description_table_total', true) === true 
										)
									)
									{
										$units_table_html .= '<tr>';
										$data_column_i = 0;
										$total_data_columns = array();
										foreach ( $data_columns as $data_column_key => $data_column )
										{
											$column_value = '&nbsp;';
											if ( $data_column_i == 0 )
											{
												$column_value = 'Total';
											}
											elseif ($data_column_key == 'sqft')
											{
												$column_value = str_replace(".00", "", number_format($total_size_sqft, 2));
											}
											elseif ($data_column_key == 'sqm')
											{
												$column_value = str_replace(".00", "", number_format($total_size_m, 2));
											}

											$total_data_columns[$data_column_key] = $column_value;

											++$data_column_i;
										}
										$total_data_columns = apply_filters( 'propertyhive_import_agentsinsight_units_description_table_total_data_columns', $total_data_columns, $post_id, $property);
										$total_data_columns = apply_filters( 'propertyhive_import_kato_units_description_table_total_data_columns', $total_data_columns, $post_id, $property);
										
										foreach ( $total_data_columns as $data_column )
										{
											$units_table_html .= '<td>' . $data_column . '</td>';
										}
										$units_table_html .= '</tr>';
									}
									$units_table_html .= '</tbody></table>';

									$description_contents = trim(preg_replace('/>\s+</', '><', $units_table_html));
								}
				        	}
				        	else
				        	{
					        	$description_contents = (string)$property->{$description};
					        }
			            	update_post_meta( $post_id, '_description_' . $description_i, $description_contents );

			            	++$description_i;
				        }
			        }

					update_post_meta( $post_id, '_descriptions', $description_i );
					
					// Media - Images
				    $media = array();

				    $image_width = apply_filters( 'propertyhive_agentsinsight_image_width', 1200 );
				    $image_width = apply_filters( 'propertyhive_kato_image_width', $image_width );

				    if (isset($property->images) && !empty($property->images))
	                {
	                    foreach ($property->images as $images)
	                    {
	                        if (!empty($images->image))
	                        {
	                            foreach ($images->image as $image)
	                            {
									$url = str_replace("http://", "https://", (string)$image);

									if ( strpos($url, 'width=') == false ) { $url .= '?width=' . $image_width; }

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

					$this->import_media( $post_id, (string)$property->id, 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    
				    $floorplan_file_types = array('15', '23');
					$floorplan_file_types = apply_filters( 'propertyhive_property_import_agentsinsight_xml_floorplan_file_types', $floorplan_file_types );
					$floorplan_file_types = apply_filters( 'propertyhive_property_import_kato_xml_floorplan_file_types', $floorplan_file_types );

				    if (isset($property->files) && !empty($property->files))
	                {
	                    foreach ($property->files as $files)
	                    {
	                        if (!empty($files->file))
	                        {
	                            foreach ($files->file as $file)
	                            {
									if ( in_array((string)$file->type, $floorplan_file_types) )
									{
										$media[] = array(
											'url' => (string)$file->url,
											'description' => (string)$file->description,
										);
									}
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    
				    $brochure_file_types = array('11');
					$brochure_file_types = apply_filters( 'propertyhive_property_import_agentsinsight_xml_brochure_file_types', $brochure_file_types );
					$brochure_file_types = apply_filters( 'propertyhive_property_import_kato_xml_brochure_file_types', $brochure_file_types );
					
				    if (isset($property->files) && !empty($property->files))
	                {
	                    foreach ($property->files as $files)
	                    {
	                        if (!empty($files->file))
	                        {
	                            foreach ($files->file as $file)
	                            {
									if ( in_array((string)$file->type, $brochure_file_types) )
									{
										$media[] = array(
											'url' => (string)$file->url,
											'description' => (string)$file->description,
										);
									}
								}
							}
						}
					}
					if ( isset($property->particulars_url) && (string)$property->particulars_url != '' )
					{
						$media[] = array(
							'url' => (string)$property->particulars_url,
						);
					}

					$this->import_media( $post_id, (string)$property->id, 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if (isset($property->epcs) && !empty($property->epcs))
	                {
	                    foreach ($property->epcs as $epcs)
	                    {
	                        if (!empty($epcs->epc))
	                        {
	                            foreach ($epcs->epc as $epc)
	                            {
	                            	$url = str_replace("http://", "https://", (string)$epc->url);

									$media[] = array(
										'url' => $url,
										'description' => (string)$epc->description,
										'post_title' => (string)$epc->name
									);
									
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->id, 'epc', $media, false );

					// Media - Virtual Tours
					$virtual_tours = array();
					if (isset($property->videos) && !empty($property->videos))
	                {
	                    foreach ($property->videos as $videos)
	                    {
	                        if (!empty($videos->url))
	                        {
	                            foreach ($videos->url as $url)
	                            {
	                            	$virtual_tours[] = $url;
	                            }
	                        }
	                    }
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->id );

					if ( 
						(
							apply_filters( 'propertyhive_import_agentsinsight_units', false) === true 
							||
							apply_filters( 'propertyhive_import_kato_units', false) === true 
						) && 
						isset($property->floor_units) && 
						!empty($property->floor_units) 
					)
					{
						$floor_units = $property->floor_units->children();

						$num_units = $floor_units->count();
						$unit_i = 0;
						foreach ($floor_units as $floor_unit)
						{
							$this->log( 'Importing unit ' . ($unit_i + 1) . ' of ' . $num_units . ' with reference ' . (string)$floor_unit->meta_id, 0, (string)$property->id . '-' . (string)$floor_unit->meta_id );

							$inserted_updated_unit = false;

							$unit_display_address = $display_address;

							$unit_name_parts = array_filter(array(
								isset($floor_unit->floorunit) ? $floor_unit->floorunit : '',
								isset($floor_unit->description) ? $floor_unit->description : '',
							));

							$unit_name = implode( ' ', $unit_name_parts );
							if ( $unit_name != '' )
							{
								$unit_display_address = $unit_name . ' - ' . $unit_display_address;
							}

							list( $inserted_updated_unit, $unit_post_id ) = $this->insert_update_property_post( (string)$property->id . '-' . (string)$floor_unit->meta_id, $floor_unit, $unit_display_address, '', '', '', $post_id );

							if ( $inserted_updated_unit !== false )
							{
								$this->log( 'Successfully ' . $inserted_updated_unit . ' unit', $unit_post_id, (string)$property->id . '-' . (string)$floor_unit->meta_id );

								if ( $inserted_updated_unit == 'updated' )
								{
									// Get all meta data so we can compare before and after to see what's changed
									$unit_metadata_before = get_metadata('post', $unit_post_id, '', true);

									// Get all taxonomy/term data
									$unit_taxonomy_terms_before = array();
									$taxonomy_names = get_post_taxonomies( $unit_post_id );
									foreach ( $taxonomy_names as $taxonomy_name )
									{
										$unit_taxonomy_terms_before[$taxonomy_name] = wp_get_post_terms( $unit_post_id, $taxonomy_name, array('fields' => 'ids') );
									}
								}

								update_post_meta( $unit_post_id, '_property_import_data', json_encode($floor_unit, JSON_PRETTY_PRINT) );

								// Get all post meta and taxonomies for parent property and copy to this unit
								$parent_metadata = get_metadata('post', $post_id, '', true);
								foreach ( $parent_metadata as $key => $value)
								{
									if (
										in_array(
											$key,
											array(
												'_address_name_number',
												'_address_street',
												'_address_two',
												'_address_three',
												'_address_four',
												'_address_postcode',
												'_address_country',
												'_latitude',
												'_longitude',
												'_owner_contact_id',
												'_negotiator_id',
												'_office_id',
											)
										)
									)
									{
										$value = $value[0];
										if ( $key == '_address_name_number' )
										{
											$value = trim( $unit_name . ( $value != '' ? ' - ' . $value : '' ) );
										}
										update_post_meta( $unit_post_id, $key, $value );
									}
								}

								update_post_meta( $unit_post_id, $imported_ref_key, (string)$property->id . '-' . (string)$floor_unit->meta_id );

								update_post_meta( $unit_post_id, '_department', 'commercial' );
								$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
								if ( $on_market_by_default === true )
								{
									update_post_meta( $unit_post_id, '_on_market', 'yes' );
								}

								// Availability
								$prefix = 'commercial_';
								
								$mapping = isset($options['mappings'][$prefix . 'availability']) ? $options['mappings'][$prefix . 'availability'] : array();
								
								if ( !empty($mapping) && isset($floor_unit->status) && isset($mapping[(string)$floor_unit->status]) )
								{
									wp_set_object_terms( $unit_post_id, (int)$mapping[(string)$floor_unit->status], 'availability' );
								}
								else
								{
									wp_delete_object_term_relationships( $unit_post_id, 'availability' );
								}

								// Unit Floor Area
								$size = preg_replace("/[^0-9.]/", '', (string)$floor_unit->size);

								update_post_meta( $unit_post_id, '_floor_area_from', $size );
								update_post_meta( $unit_post_id, '_floor_area_to', $size );

								$units = 'sqft';
								$size_sqft = $size;

								if ( (string)$floor_unit->area_size_unit == 'acres' )
								{
									$units = 'acre';
									$size_sqft = $size * 43560;
								}

								update_post_meta( $unit_post_id, '_floor_area_from_sqft', $size_sqft );
								update_post_meta( $unit_post_id, '_floor_area_to_sqft', $size_sqft );

								update_post_meta( $unit_post_id, '_floor_area_units', $units );

								update_post_meta( $unit_post_id, '_kato_xml_update_date_' . $this->import_id, (string)$property->last_updated );

								do_action( "propertyhive_property_unit_imported_agentsinsight_xml", $unit_post_id, $floor_unit );
								do_action( "propertyhive_property_unit_imported_kato_xml", $unit_post_id, $floor_unit );

								$post = get_post( $unit_post_id );
								do_action( "save_post_property", $unit_post_id, $post, false );
								do_action( "save_post", $unit_post_id, $post, false );

								if ( $inserted_updated_unit == 'updated' )
								{
									$this->compare_meta_and_taxonomy_data( $unit_post_id, (string)$property->id . '-' . (string)$floor_unit->meta_id, $unit_metadata_before, $unit_taxonomy_terms_before );
								}
							}

							++$unit_i;
						}
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->id );
				}
				
				if ( isset($property->last_updated) ) { update_post_meta( $post_id, '_kato_xml_update_date_' . $this->import_id, (string)$property->last_updated ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_agentsinsight_xml", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_kato_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->id, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_agentsinsight_xml" );
		do_action( "propertyhive_post_import_properties_kato_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->id;

			if ( 
				(
					apply_filters( 'propertyhive_import_agentsinsight_units', false) === true
					||
					apply_filters( 'propertyhive_import_kato_units', false) === true
				) && 
				isset($property->floor_units) && 
				!empty($property->floor_units)
			)
			{
				$floor_units = $property->floor_units->children();

				foreach ($floor_units as $floor_unit)
				{
					$import_refs[] = (string)$property->id . '-' . (string)$floor_unit->meta_id;
				}
			}
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'commercial_availability' => array(
                'Coming Soon' => 'Coming Soon',
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Sold' => 'Sold',
            ),
            'commercial_property_type' => array(
                'Office' => 'Office',
                'Serviced Office' => 'Serviced Office',
                'Industrial' => 'Industrial',
                'Retail' => 'Retail',
                'Residential' => 'Residential',
                'Leisure' => 'Leisure',
                'D1 (Non Residential Institutions)' => 'D1 (Non Residential Institutions)',
                'D2 (Assembly and Leisure)' => 'D2 (Assembly and Leisure)',
                'Land' => 'Land',
                'Development' => 'Development',
                'Investment' => 'Investment',
                'Trade Counter' => 'Trade Counter',
                'Storage' => 'Storage',
                'Other' => 'Other',
            ),
            'price_qualifier' => array(
                'Fixed Price' => 'Fixed Price',
                'Guide Price' => 'Guide Price',
            ),
        );
	}
}

}