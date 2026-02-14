<?php
/**
 * Class for managing the import process of a Muven XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Muven_XML_Import extends PH_Property_Import_Process {

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

		$properties_url = 'http://api.clarkscomputers.co.uk/service.svc/getproperties?api_key=' . $import_settings['api_key'];
		$statuses = array(0, 1, 2, 3, 4, 5, 20, 21);
		$statuses = apply_filters( 'propertyhive_clarks_computers_xml_statuses', $statuses );
		$statuses = apply_filters( 'propertyhive_muven_xml_statuses', $statuses );
		if ( !empty($statuses) )
		{
			foreach ($statuses as $status)
			{	
				$properties_url .= '&status=' . $status;
			}
		}
		$property_url = 'http://api.clarkscomputers.co.uk/service.svc/getproperty?api_key=' . $import_settings['api_key'];

		$department_params = array('sale=1', 'sale=2'); // sales (1) and lettings (2) based on RM trans_type_id

		$max_pages = 100;

		$limit = $this->get_property_limit();

		foreach ( $department_params as $department_param )
		{
			$this->log("Parsing " .  $department_param . " properties");

			$current_page = 1;
			$total_pages = 1;
			$more_properties = true;

			while ( $more_properties )
			{
				if ( $current_page > $max_pages ) 
				{
				    $this->log_error('Exceeded maximum allowed pages. Stopping to avoid infinite loop.');
				    
				    return false;
				}

				$response = wp_remote_get( $properties_url . '&' . $department_param . '&per_page=100&page=' . $current_page , array( 'timeout' => 120 ) );
				if ( !is_wp_error($response) && is_array( $response ) ) 
				{
					$xml = simplexml_load_string( $response['body'] );
					
					if ($xml !== FALSE)
					{
						$this->log("Parsing properties on page " . $current_page);
						
			            if ( !isset($xml->count) || !is_numeric( (string)$xml->count ) )
						{
							$this->log_error('Failed to obtain valid property count on page ' . $current_page);
						    
						    return false;
						}
						else
						{
							if ( (string)$xml->count == 0 )
							{
								break;
							}
							$total_pages = ceil($xml->count / 100);

							if ( $current_page == $total_pages )
							{
								$more_properties = false;
							}
						}

						foreach ($xml->property_records->property_record as $property)
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    return true;
			                }

			                if ($test === false)
			                {
								$response = wp_remote_get( $property_url . '&property_id=' . (string)$property->agent_ref, array( 'timeout' => 120 ) );
								if ( !is_wp_error($response) && is_array( $response ) ) 
								{
									$xml = simplexml_load_string( $response['body'] );

									if ($xml !== FALSE)
									{
										if ( isset($xml->property_detail) )
										{
						            		$this->properties[] = $xml->property_detail;
						            	}
						            	else
								        {
								        	// Failed to parse XML
								        	$this->log_error( 'No property detail node in XML for property ' . (string)$property->agent_ref );

								        	return false;
								        }
					            	}
					            	else
							        {
							        	// Failed to parse XML
							        	$this->log_error( 'Failed to parse XML file for property ' . (string)$property->agent_ref . '. Possibly invalid XML' );

							        	return false;
							        }
					            }
					            else
								{
									$this->log_error( "Failed to obtain XML for property " . (string)$property->agent_ref . ". Dump of response as follows: " . print_r($response, TRUE) );

							        return false;
								}
							}
							else
							{
								$this->properties[] = $property;
							}
			            } // end foreach property
			        }
			        else
			        {
			        	// Failed to parse XML
			        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML: ' . print_r($response['body'], true) );

			        	return false;
			        }
				}
				else
				{
					$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

			        return false;
				}

				++$current_page;
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

        $geocoding_denied = false;

        do_action( "propertyhive_pre_import_properties_clarks_computers_xml", $this->properties, $this->import_id );
        do_action( "propertyhive_pre_import_properties_muven_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_clarks_computers_xml_properties_due_import", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_muven_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_muven_xml", $property, $this->import_id, $this->instance_id );

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

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->agent_ref, 0, (string)$property->agent_ref, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$summary = str_replace(array("\r\n", "\n"), "", html_entity_decode((string)$property->summary));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->agent_ref, $property, (string)$property->display_address, $summary );

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

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_clarks_computers_xml_update_date_' . $this->import_id, TRUE);

				$country = 'GB';

				$skip_property = false;
				if (isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$previous_update_date == (string)$property->last_updated
					)
					{
						$skip_property = true;
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
						if ( trim($property->postcode) != '' ) { $address_to_geocode[] = (string)$property->postcode; $address_to_geocode_osm[] = (string)$property->postcode; }

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->agent_ref, $address_to_geocode, $address_to_geocode_osm, $country );
						if ( $return === 'denied' )
						{
							$geocoding_denied = true;
						}
					}
				}

				if ( !$skip_property )
				{
					update_post_meta( $post_id, $imported_ref_key, (string)$property->agent_ref );

					// Address
					update_post_meta( $post_id, '_reference_number', (string)$property->agent_ref );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->town) ) ? (string)$property->town : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->county) ) ? (string)$property->county : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

					update_post_meta( $post_id, '_address_country', $country );

					// Let's just look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_clarks_computers_xml_address_fields_to_check', array('town', 'county') );
	            	$address_fields_to_check = apply_filters( 'propertyhive_muven_xml_address_fields_to_check', $address_fields_to_check );
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
							$explode_branch_code = explode(",", $branch_code);
							if ( in_array((string)$property->branch, $explode_branch_code) )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = 'residential-sales';
					if ( (string)$property->sale == '2' )
					{
						$department = 'residential-lettings';
					}
					if ( (string)$property->commercial == '1' )
					{
						$department = 'commercial';
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch]);
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
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedrooms) ) ? (string)$property->bedrooms : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathrooms) ) ? (string)$property->bathrooms : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->living_rooms) ) ? (string)$property->living_rooms : '' ) );

					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property->prop_type) )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->prop_type]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->prop_type], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property->prop_type . ') that is not mapped', $post_id, (string)$property->agent_ref );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->prop_type, $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					$council_tax_band = '';
					$tax_band_options = apply_filters( 'propertyhive_property_residential_tax_bands',
			            array(
			                '' => '',
			                'A' => 'A',
			                'B' => 'B',
			                'C' => 'C',
			                'D' => 'D',
			                'E' => 'E',
			                'F' => 'F',
			                'G' => 'G',
			                'H' => 'H',
			                'I' => 'I',
			            )
			        );
					if ( in_array((string)$property->council_tax_band, $tax_band_options) )
					{
						$council_tax_band = (string)$property->council_tax_band;
					}
					update_post_meta( $post_id, '_council_tax_band', $council_tax_band );

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						$price = '';
						if ( isset($property->price_numerical) && !empty((string)$property->price_numerical) )
						{
							$price = (string)$property->price_numerical;
						}
						if ( empty($price) && isset($property->price) && !empty((string)$property->price) )
						{
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
						}
						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_poa', ( isset($property->price_qualifier) && (string)$property->price_qualifier == 1 ? 'yes' : '' ) );
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

						if ( !empty($mapping) && isset($property->tenure) && isset($mapping[(string)$property->tenure]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->tenure], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }

			            if ( (string)$property->tenure == 2 )
			            {
				            update_post_meta( $post_id, '_leasehold_years_remaining', ( ( isset($property->tenure_unexpired_years) && !empty((string)$property->tenure_unexpired_years) ) ? (string)$property->tenure_unexpired_years : '' ) );
							update_post_meta( $post_id, '_service_charge', ( ( isset($property->annual_service_charge) && !empty((string)$property->annual_service_charge) ) ? (string)$property->annual_service_charge : '' ) );
							update_post_meta( $post_id, '_ground_rent', ( ( isset($property->annual_ground_rent) && !empty((string)$property->annual_ground_rent) ) ? (string)$property->annual_ground_rent : '' ) );
							update_post_meta( $post_id, '_ground_rent_review_years', ( ( isset($property->ground_rent_review_period_years) && !empty((string)$property->ground_rent_review_period_years) ) ? (string)$property->ground_rent_review_period_years : '' ) );
							update_post_meta( $post_id, '_shared_ownership', ( (string)$property->shared_ownership == '1' ? 'yes' : '' ) );
							update_post_meta( $post_id, '_shared_ownership_percentage', ( (string)$property->shared_ownership == '1' ? str_replace( "%", "", (string)$property->shared_ownership_percentage ) : '' ) );
						}
					}
					elseif ( $department == 'residential-lettings' )
					{
						$price = '';
						if ( isset($property->price_numerical) && !empty((string)$property->price_numerical) )
						{
							$price = (string)$property->price_numerical;
						}
						if ( empty($price) && isset($property->price) && !empty((string)$property->price) )
						{
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
						}

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						$explode_price = explode(" ", (string)$property->price);
						switch ($explode_price[count($explode_price) - 1])
						{
							case "pw": { $rent_frequency = 'pw'; break; }
							case "pa": { $rent_frequency = 'pa'; break; }
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						
						update_post_meta( $post_id, '_poa', ( isset($property->price_qualifier) && (string)$property->price_qualifier == 1 ? 'yes' : '' ) );

						update_post_meta( $post_id, '_deposit', '' );
						update_post_meta( $post_id, '_currency', 'GBP' );

						$let_date_available = '';
						if ( isset($property->let_date_available) && (string)$property->let_date_available != '' )
						{
							$explode_let_date_available = explode(" ", (string)$property->let_date_available);
							if ( count($explode_let_date_available) == 2 )
							{
								$let_date_available = $explode_let_date_available[0];
							}
						}
	            		update_post_meta( $post_id, '_available_date', $let_date_available );

	            		// Furnished
	            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

						if ( !empty($mapping) && isset($property->furnished_type) && isset($mapping[(string)$property->furnished_type]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->furnished_type], 'furnished' );
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

	            		if ( (string)$property->forSale == '1' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    $price = '';
							if ( isset($property->price_numerical) && !empty((string)$property->price_numerical) )
							{
								$price = (string)$property->price_numerical;
							}
							if ( empty($price) && isset($property->price) && !empty((string)$property->price) )
							{
								$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
							}
		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( isset($property->price_qualifier) && (string)$property->price_qualifier == 1 ? 'yes' : '' ) );
		                }

		                if ( (string)$property->toLet == '1' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    $rent = '';
							if ( isset($property->price_numerical) && !empty((string)$property->price_numerical) )
							{
								$rent = (string)$property->price_numerical;
							}
							if ( empty($rent) && isset($property->price) && !empty((string)$property->price) )
							{
								$rent = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
							}
		                    update_post_meta( $post_id, '_rent_from', $rent );
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    update_post_meta( $post_id, '_rent_units', '');

		                    update_post_meta( $post_id, '_rent_poa', ( isset($property->price_qualifier) && (string)$property->price_qualifier == 1 ? 'yes' : '' ) );
		                }
		               
			            $size = preg_replace("/[^0-9.]/", '', (string)$property->min_size_entered);
			            if ( $size == '' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', (string)$property->max_size_entered);
			            }
			            if ( (string)$property->min_size_entered == '0.00' && (string)$property->max_size_entered == '0.00' )
			            {
			            	$size = '';
			            }
			            update_post_meta( $post_id, '_floor_area_from', $size );

			            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->area_size_unit ) ) );

			            $size = preg_replace("/[^0-9.]/", '', (string)$property->max_size_entered);
			            if ( $size == '' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', (string)$property->min_size_entered);
			            }
			            if ( (string)$property->min_size_entered == '0.00' && (string)$property->max_size_entered == '0.00' )
			            {
			            	$size = '';
			            }
			            update_post_meta( $post_id, '_floor_area_to', $size );

			            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->area_size_unit ) ) );

			            update_post_meta( $post_id, '_floor_area_units', str_replace(" ", "", (string)$property->area_size_unit ) );

			            $size = '';
			            update_post_meta( $post_id, '_site_area_from', $size );

			            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->area_size_unit ) ) );

			            update_post_meta( $post_id, '_site_area_to', $size );

			            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, str_replace(" ", "", (string)$property->area_size_unit ) ) );

			            update_post_meta( $post_id, '_site_area_units', str_replace(" ", "", (string)$property->area_size_unit ) );
					}

					 // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
	                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', ( isset($property->featured) && (string)$property->featured == '1' ) ? 'yes' : '' );
					}
					
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
							$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
							array();

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
					for ( $i = 1; $i <= 10; ++$i )
					{
						if ( isset($property->{'feature' . $i}) && trim((string)$property->{'feature' . $i}) != '' )
						{
							$features[] = trim((string)$property->{'feature' . $i});
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
			            update_post_meta( $post_id, '_description_0', str_replace(array("\r\n", "\n"), "", html_entity_decode((string)$property->description)) );
					}
					else
					{
						update_post_meta( $post_id, '_rooms', '1' );
						update_post_meta( $post_id, '_room_name_0', '' );
			            update_post_meta( $post_id, '_room_dimensions_0', '' );
			            update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", html_entity_decode((string)$property->description)) );
			        }

			        // Media - Images
				    $media = array();
				    if (isset($property->images->image) && !empty($property->images->image))
	                {
	                    foreach ($property->images->image as $image)
	                    {
							$media[] = array(
								'url' => (string)$image->url,
								'description' => ( ( isset($image->caption) && (string)$image->caption != '' ) ? (string)$image->caption : '' ),
								'modified' => ( ( isset($property->last_updated) && (string)$property->last_updated != '' ) ? (string)$property->last_updated : '' ),
							);
						}
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'photo', $media, ( ( isset($property->last_updated) && (string)$property->last_updated != '' ) ? true : false ) );
	
					// Media - Floorplans
				    $media = array();
				    if (isset($property->floor_plans->floor_plan->url))
		            {
						$media[] = array(
							'url' => (string)$property->floor_plans->floor_plan->url,
							'description' => ( ( isset($property->floor_plans->floor_plan->caption) && (string)$property->floor_plans->floor_plan->caption != '' ) ? (string)$property->floor_plans->floor_plan->caption : '' ),
							'modified' => ( ( isset($property->last_updated) && (string)$property->last_updated != '' ) ? (string)$property->last_updated : '' ),
						);
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'floorplan', $media, ( ( isset($property->last_updated) && (string)$property->last_updated != '' ) ? true : false ) );

					// Media - Brochures
				    $media = array();
				    if (isset($property->documents) && !empty($property->documents))
	                {
	                    foreach ($property->documents as $documents)
	                    {
	                        if (!empty($documents->document))
	                        {
	                            foreach ($documents->document as $document)
	                            {
									$media[] = array(
										'url' => (string)$document->url,
										'description' => ( ( isset($document->caption) && (string)$document->caption != '' ) ? (string)$document->caption : '' ),
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'brochure', $media, false );
	
					// Media - EPCs
				    $media = array();
				    if (isset($property->epcs->epc->url))
		            {
						$media[] = array(
							'url' => (string)$property->epcs->epc->url,
							'description' => ( ( isset($property->epcs->epc->caption) && (string)$property->epcs->epc->caption != '' ) ? (string)$property->epcs->epc->caption : '' ),
							'modified' => ( ( isset($property->last_updated) && (string)$property->last_updated != '' ) ? (string)$property->last_updated : '' ),
						);
					}

					$this->import_media( $post_id, (string)$property->agent_ref, 'epc', $media, ( ( isset($property->last_updated) && (string)$property->last_updated != '' ) ? true : false ) );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->agent_ref );
				}
				
				if ( isset($property->last_updated) ) { update_post_meta( $post_id, '_clarks_computers_xml_update_date_' . $this->import_id, (string)$property->last_updated ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_clarks_computers_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_clarks_computers_xml" );
		do_action( "propertyhive_post_import_properties_muven_xml" );

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
                'Under Offer' => 'Under Offer',
                'SSTC' => 'SSTC',
                'SSTCM' => 'SSTCM',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Reserved' => 'Reserved',
                'Let Agreed' => 'Let Agreed',
                'Let' => 'Let',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'SSTC' => 'SSTC',
                'SSTCM' => 'SSTCM',
                'Sold' => 'Sold',
                'Reserved' => 'Reserved',
                'Let' => 'Let',
            ),
            'property_type' => array(
                'House' => 'House',
                'Terraced' => 'Terraced',
                'End of Terrace' => 'End of Terrace',
                'Semi-Detached' => 'Semi-Detached',
                'Detached' => 'Detached',
                'Mews' => 'Mews',
                'Ground Flat' => 'Ground Flat',
                'Flat' => 'Flat',
                'Studio' => 'Studio',
                'Studio Flat' => 'Studio Flat',
                'Ground Maisonette' => 'Ground Maisonette',
                'Maisonette' => 'Maisonette',
                'Bungalow' => 'Bungalow',
                'Terraced Bungalow' => 'Terraced Bungalow',
                'Semi-Detached Bungalow' => 'Semi-Detached Bungalow',
                'Detached Bungalow' => 'Detached Bungalow',
                'Land' => 'Land',
                'Link Detached House' => 'Link Detached House',
                'Town House' => 'Town House',
                'Cottage' => 'Cottage',
            ),
            'commercial_property_type' => array(
                'Restaurant' => 'Restaurant',
                'Cafe' => 'Cafe',
                'Heavy Industrial' => 'Heavy Industrial',
                'Light Industrial' => 'Light Industrial',
                'Warehouse' => 'Warehouse',
                'Land' => 'Land',
            ),
            'price_qualifier' => array(
                '2' => 'Guide Price',
                '3' => 'Fixed Price',
                '4' => 'Offers in Excess of',
                '5' => 'OIRO',
                '6' => 'Sale by Tender',
                '7' => 'From',
                '9' => 'Shared Ownership',
                '10' => 'Offers Over',
                '11' => 'Part Buy, Part Rent',
                '12' => 'Shared Equity',
                '15' => 'Offers Invited',
                '16' => 'Coming Soon',
            ),
            'tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Feudal',
                '5' => 'Share of Freehold',
                '4' => 'Commonhold',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Part Furnished' => 'Part Furnished',
                'Unfurnished' => 'Unfurnished',
                'Not Specified' => 'Not Specified',
                'Furnished / UnFurnished' => 'Furnished / UnFurnished',
            ),
        );
	}
}

}