<?php
/**
 * Class for managing the import process of an Agentbox JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Agentbox_JSON_Import extends PH_Property_Import_Process {

	/**
	 * @var int
	 */
	private $max_requests = 20;

	/**
	 * @var int
	 */
	private $time_window = 5;

	/**
	 * @var array
	 */
	private $api_requests = array();

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

	private function check_rate_limit()
	{
	    $current_time = time();
	    
	    // Remove timestamps older than the defined time window (5 seconds)
	    $this->api_requests = array_filter($this->api_requests, function($timestamp) use ($current_time) 
	    {
	        return ($current_time - $timestamp) <= $this->time_window;
	    });
	    
	    // If the number of requests is greater than or equal to $this->max_requests, we must wait
	    if (count($this->api_requests) >= $this->max_requests) {
	        // Find the time of the oldest request within the last 5 seconds
	        $oldest_request_time = min($this->api_requests);
	        $time_to_wait = $this->time_window - ($current_time - $oldest_request_time);
	        
	        // Wait for the remaining time until we can send a new request
	        if ($time_to_wait > 0) 
	        {
	            //echo "Rate limit reached. Waiting for {$time_to_wait} seconds...\n";
	            sleep($time_to_wait);
	        }
	    }
	    
	    // Record the current time for the new request
	    $this->api_requests[] = time();
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

		$marketing_statuses = apply_filters( 'propertyhive_agentbox_marketing_statuses', array( 'Available' ) );

		$this->log("Parsing properties");

		$current_page = 1;
		$more_properties = true;

		$limit = $this->get_property_limit();

		while ( $more_properties )
		{
			$url = 'https://api.agentboxcrm.com.au/listings?version=2&page=' . $current_page . '&limit=100&filter%5BhiddenListing%5D=false&filter%5BoffMarketListing%5D=false';
			if ( is_array($marketing_statuses) && !empty($marketing_statuses) )
			{
				$url .= '&filter%5BmarketingStatus%5D=' . implode(',', $marketing_statuses);
			}

			$headers = array(
				'Accept' => 'application/json',
				'X-Client-ID' => $import_settings['client_id'],
				'X-API-Key' => $import_settings['api_key'],
			);

			$headers = apply_filters( 'propertyhive_agentbox_listings_headers', $headers );

			$this->check_rate_limit();

			$response = wp_remote_request(
				$url,
				array(
					'method' => 'GET',
					'timeout' => 60,
					'headers' => $headers
				)
			);

			if ( is_wp_error( $response ) )
			{
				$this->log_error( 'Response: ' . $response->get_error_message() );

				return false;
			}

			$json = json_decode( $response['body'], TRUE );

			if ($json !== FALSE)
			{
				if ( isset($json['response']['errors']) && !empty($json['response']['errors']) )
				{
					foreach ( $json['response']['errors'] as $error )
					{
						$this->log_error( 'Error returned by Agentbox: ' . print_r($error, TRUE) );
					}
					return false;
				}

				$total_pages = '';
				if ( isset($json['response']['last']) )
				{
					if ( $current_page == $json['response']['last'] )
					{
						$more_properties = false;
					}

					$total_pages = $json['response']['last'];
				}
				else
				{
					$this->log_error( 'No pagination element found in response. This should always exist so likely something went wrong. As a result we\'ll play it safe and not continue further.' );
					return false;
				}

				$this->log("Parsing properties on page " . $current_page . " out of " . $total_pages);

				if ( isset($json['response']['listings']) )
				{
					foreach ($json['response']['listings'] as $property)
					{
						if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
		                {
		                    return true;
		                }

		                if ( $test === false )
		                {
							$url = 'https://api.agentboxcrm.com.au/listings/' . $property['id'] . '?include=%2Cimages%2CfloorPlans%2Cdocuments%2CrelatedStaffMembers&version=2';

							$headers = array(
								'Accept' => 'application/json',
								'X-Client-ID' => $import_settings['client_id'],
								'X-API-Key' => $import_settings['api_key'],
							);

							$headers = apply_filters( 'propertyhive_agentbox_listing_headers', $headers );

							$this->check_rate_limit();
							
							$response = wp_remote_request(
								$url,
								array(
									'method' => 'GET',
									'timeout' => 60,
									'headers' => $headers
								)
							);

							if ( is_wp_error( $response ) )
							{
								$this->log_error( 'Response: ' . $response->get_error_message() );

								return false;
							}

							$property_json = json_decode( $response['body'], TRUE );

							if ($property_json !== FALSE)
							{
								if ( isset($property_json['response']['errors']) && !empty($property_json['response']['errors']) )
								{
									foreach ( $property_json['response']['errors'] as $error )
									{
										$this->log_error( 'Error returned by Agentbox: ' . print_r($error, TRUE) );
									}
									return false;
								}

								if ( !isset($property_json['response']['listing']) )
								{
									$this->log_error( 'Listing data missing in response: ' . print_r($property_json, TRUE) );
									return false;
								}

								$this->properties[] = $property_json['response']['listing'];
							}
							else
							{
								// Failed to parse JSON
								$this->log_error( 'Failed to parse listing JSON: ' . print_r($response['body'], true) );

								return false;
							}
						}
						else
						{
							$this->properties[] = $property;
						}
					}
				}
				
				++$current_page;
			}
			else
			{
				// Failed to parse JSON
				$this->log_error( 'Failed to parse listings JSON: ' . print_r($response['body'], true) );

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

		do_action( "propertyhive_pre_import_properties_agentbox_json", $this->properties, $this->import_id );
		$this->properties = apply_filters( "propertyhive_agentbox_json_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_agentbox_json", $property, $this->import_id, $this->instance_id );
			
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

			$this->log( 'Importing property ' . $property_row .' with reference ' . $property['id'], 0, $property['id'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = $property['mainHeadline'];

			$date = '';
			if ( isset($property['onMarketDate']) && !empty($property['onMarketDate']) )
			{
				$date = new DateTime($property['onMarketDate']);
			}
			elseif ( isset($property['firstCreated']) && !empty($property['firstCreated']) )
			{
				$date = new DateTime($property['firstCreated']);
			}
			elseif ( isset($property['lastModified']) && !empty($property['lastModified']) )
			{
				$date = new DateTime($property['lastModified']);
			}
			if ( !empty($date) )
			{
				$date = $date->format('Y-m-d H:i:s');
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, ( ( isset($property['mainDescription']) ) ? $property['mainDescription'] : '' ), '', $date );

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

				$previous_agentbox_json_update_date = get_post_meta( $post_id, '_agentbox_json_update_date_' . $this->import_id, TRUE);

				$skip_property = false;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if ( !empty($previous_agentbox_json_update_date) && $previous_agentbox_json_update_date == $property['lastModified'] )
					{
						$skip_property = true;
					}
				}

				if ( !$skip_property )
				{
					// Address
					update_post_meta( $post_id, '_reference_number', ( ( isset($property['externalId']) ) ? $property['externalId'] : $property['id'] ) );

					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['property']['address']['streetNum']) ) ? $property['property']['address']['streetNum'] : '' ) . ' ' .( ( isset($property['property']['address']['streetNum']) ) ? $property['property']['address']['streetNum'] : '' ) ) );
					update_post_meta( $post_id, '_address_street', trim( ( ( isset($property['property']['address']['streetName']) ) ? $property['property']['address']['streetName'] : '' ) . ' ' . ( ( isset($property['property']['address']['streetType']) ) ? $property['property']['address']['streetType'] : '' ) ) );
					update_post_meta( $post_id, '_address_two', '' );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['property']['address']['suburb']) ) ? $property['property']['address']['suburb'] : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property['property']['address']['state']) ) ? $property['property']['address']['state'] : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property['property']['address']['postcode']) ) ? $property['property']['address']['postcode'] : '' ) );

					$country = get_option( 'propertyhive_default_country', 'AU' );
					$currency = 'AUD';
					if ( isset($property['property']['address']['country']) && $property['property']['address']['country'] != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower($property['property']['address']['country']) == strtolower($country_details['name']) )
							{
								$country = $country_code;
								$currency = $country_details['currency_code'];
								break;
							}
						}
					}
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_agentbox_json_address_fields_to_check', array('suburb', 'state', 'region') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property['property']['address'][$address_field]) && trim($property['property']['address'][$address_field]) != '' )
						{
							$term = term_exists( trim($property['property']['address'][$address_field]), 'location');
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
					if ( isset($property['property']['location']['lat']) && isset($property['property']['location']['long']) && $property['property']['location']['lat'] != '' && $property['property']['location']['long'] != '' && $property['property']['location']['lat'] != '0' && $property['property']['location']['long'] != '0' )
					{
						update_post_meta( $post_id, '_latitude', (string)$property['property']['location']['lat'] );
						update_post_meta( $post_id, '_longitude', (string)$property['property']['location']['long'] );
					}
					else
					{
						// Get lat long from address if possible
						$lat = get_post_meta( $post_id, '_latitude', TRUE);
						$lng = get_post_meta( $post_id, '_longitude', TRUE);

						if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
						{
							// No lat lng. Let's get it
							$address_to_geocode = array();
							$address_to_geocode_osm = array();
							if ( isset($property['property']['address']['streetAddress']) && trim($property['property']['address']['streetAddress']) != '' ) { $address_to_geocode[] = $property['property']['address']['streetAddress']; }
							if ( isset($property['property']['address']['state']) && trim($property['property']['address']['state']) != '' ) { $address_to_geocode[] = $property['property']['address']['state']; }
							if ( isset($property['property']['address']['suburb']) && trim($property['property']['address']['suburb']) != '' ) { $address_to_geocode[] = $property['property']['address']['suburb']; }
							if ( isset($property['property']['address']['postcode']) && trim($property['property']['address']['postcode']) != '' ) { $address_to_geocode[] = $property['property']['address']['postcode']; $address_to_geocode_osm[] = $property['property']['address']['postcode']; }

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
								if ( $branch_code == $property['officeId'] || strtolower($branch_code) == strtolower($property['officeName']) )
								{
									$office_id = $ph_office_id;
									break;
								}
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'residential-sales';
					if ( $property['type'] == 'Lease' )
					{
						$department = 'residential-lettings';
					}
					if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' && $property['property']['type'] == 'Commercial' )
					{
						$department = 'commercial';
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['officeId'] . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['officeId'] . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['officeId'] . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['officeId']]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['officeId']] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['officeId']]);
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
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property['property']['bedrooms']) ) ? (string)$property['property']['bedrooms'] : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property['property']['bathrooms']) ) ? (string)$property['property']['bathrooms'] : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['property']['loungeRooms']) ) ? (string)$property['property']['loungeRooms'] : '' ) );

					// Property Type
					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
					
					if ( isset($property['property']['category']) && $property['property']['category'] != '' )
					{
						if ( !empty($mapping) && isset($mapping[$property['property']['category']]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[$property['property']['category']], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . $property['property']['category'] . ') that is not mapped', $post_id, $property['id'] );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['property']['category'] );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						$price = round(preg_replace("/[^0-9.]/", '', $property['searchPrice']));
						update_post_meta( $post_id, '_price', $price );

						update_post_meta( $post_id, '_currency', $currency );

						update_post_meta( $post_id, '_poa', ( ( isset($property['displayPrice']) && strtolower($property['displayPrice']) == 'poa' ) ? '' : '' ) );
					}
					elseif ( $department == 'residential-lettings' )
					{
						//Price
						$rent = preg_replace("/[^0-9.]/", '', $property['searchPrice']);
						if ( empty($rent) )
						{
							$rent = preg_replace("/[^0-9.]/", '', $property['searchWeeklyRent']);
						}
						$rent_frequency = 'pcm';
						if ( isset($property['displayRent']['period']) && !empty($property['displayRent']['period']) )
						{
							switch (strtolower($property['displayRent']['period']))
							{
								case "week": { $rent_frequency = 'pw'; break; }
							}
						}
						elseif ( isset($property['listedRent']['period']) && !empty($property['listedRent']['period']) )
						{
							switch (strtolower($property['listedRent']['period']))
							{
								case "week": { $rent_frequency = 'pw'; break; }
							}
						}
						update_post_meta( $post_id, '_rent', $rent );
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

						update_post_meta( $post_id, '_currency', $currency );

						update_post_meta( $post_id, '_available_date', ( ( isset($property['availableDate']) && !empty($property['availableDate']) ) ? $property['availableDate'] : '' ) );

						$bond = ( ( isset($property['availableDate']) && !empty($property['bond']) ) ? $property['bond']  : '' );
						update_post_meta( $post_id, '_deposit', $bond );
						
						update_post_meta( $post_id, '_poa', ( ( isset($property['displayPrice']) && strtolower($property['displayPrice']) == 'poa' ) ? '' : '' ) );
					}
					elseif ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

	            		if ( $property['type'] == 'Sale' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', $currency );

		                    $price = round(preg_replace("/[^0-9.]/", '', $property['searchPrice']));
		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( ( isset($property['displayPrice']) && strtolower($property['displayPrice']) == 'poa' ) ? '' : '' ) );
		                }

		                if ( $property['type'] == 'Lease' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', $currency );

							$rent = preg_replace("/[^0-9.]/", '', $property['searchPrice']);
		                    update_post_meta( $post_id, '_rent_from', $rent );
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    $rent_frequency = 'pcm';
							if ( isset($property['displayRent']['period']) && !empty($property['displayRent']['period']) )
							{
								switch (strtolower($property['displayRent']['period']))
								{
									case "week": { $rent_frequency = 'pw'; break; }
								}
							}
							elseif ( isset($property['listedRent']['period']) && !empty($property['listedRent']['period']) )
							{
								switch (strtolower($property['listedRent']['period']))
								{
									case "week": { $rent_frequency = 'pw'; break; }
								}
							}
		                    update_post_meta( $post_id, '_rent_units', $rent_frequency);

		                    update_post_meta( $post_id, '_rent_poa', ( ( isset($property['displayPrice']) && strtolower($property['displayPrice']) == 'poa' ) ? '' : '' ) );
		                }

			            $size = preg_replace("/[^0-9.]/", '', $property['property']['buildingArea']['value']);
			            if ( empty($size) )
			            {
			            	$size = '';
			            }
			            update_post_meta( $post_id, '_floor_area_from', $size );
			            update_post_meta( $post_id, '_floor_area_from_sqft', $size );
			            update_post_meta( $post_id, '_floor_area_to', $size );
			            update_post_meta( $post_id, '_floor_area_to_sqft', $size );

			            $units = '';
			            if ( isset($property['property']['buildingArea']['unit']) )
			            {
			            	$units = $property['property']['buildingArea']['unit'];
			            }
			            update_post_meta( $post_id, '_floor_area_units', $units );

			            $size = preg_replace("/[^0-9.]/", '', $property['property']['externalArea']['value']);
			            if ( empty($size) )
			            {
			            	$size = '';
			            }
			            update_post_meta( $post_id, '_site_area_from', $size );
			            update_post_meta( $post_id, '_site_area_from_sqft', $size );
			            update_post_meta( $post_id, '_site_area_to', $size );
			            update_post_meta( $post_id, '_site_area_to_sqft', $size );

			            $units = '';
			            if ( isset($property['property']['externalArea']['unit']) )
			            {
			            	$units = $property['property']['externalArea']['unit'];
			            }
			            update_post_meta( $post_id, '_site_area_units', 'sqft' );
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
						update_post_meta( $post_id, '_featured', '' );
					}

					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					if ( !empty($mapping) && isset($property['marketingStatus']) && isset($mapping[$property['marketingStatus']]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[$property['marketingStatus']], 'availability' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, 'availability' );
					}

					// Features
					$i = 0;
					if ( isset($property['property']['features']) && is_array($property['property']['features']) )
					{
						foreach ( $property['property']['features'] as $feature )
						{
							if ( !empty($feature) )
							{
								update_post_meta( $post_id, '_feature_' . $i, $feature );
								++$i;
							}
						}
					}
					update_post_meta( $post_id, '_features', $i );

					// Rooms / Descriptions
					// For now put the whole description in one room / description
					if ( $department == 'commercial' )
					{
						$descriptions = 0;

						if ( isset($property['mainDescription']) && !empty($property['mainDescription']) )
						{
							update_post_meta( $post_id, '_description_name_' . $descriptions, '' );
							update_post_meta( $post_id, '_description_' . $descriptions, str_replace(array("\r\n", "\n"), "", $property['mainDescription']) );

							++$descriptions;
						}

						update_post_meta( $post_id, '_descriptions', $descriptions );
					}
					else
					{
						$rooms = 0;

						if ( isset($property['mainDescription']) && !empty($property['mainDescription']) )
						{
							update_post_meta( $post_id, '_room_name_' . $rooms, '' );
							update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
							update_post_meta( $post_id, '_room_description_' . $rooms, str_replace(array("\r\n", "\n"), "", $property['mainDescription']) );

							++$rooms;
						}

						update_post_meta( $post_id, '_rooms', $rooms );
					}

					// Media - Images
				    $media = array();
				    if (isset($property['images']) && !empty($property['images']))
					{
						foreach ($property['images'] as $photo)
						{
							if ( $photo['public'] === true )
							{
								$media[] = array(
									'url' => $photo['url'],
									'description' => isset($photo['title']) ? $photo['title'] : '',
									'modified' => isset($photo['lastModified']) ? date("Y-m-d H:i:s", strtotime($photo['lastModified'])) : ''
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if (isset($property['floorPlans']) && !empty($property['floorPlans']))
					{
						foreach ($property['floorPlans'] as $floorplan)
						{
							$url = $floorplan['url'];

							$explode_url = explode("?", $url);
							$filename = basename( $explode_url[0] );

							$media[] = array(
								'url' => $url,
								'filename' => $filename,
								'description' => isset($floorplan['title']) ? $floorplan['title'] : '',
								'modified' => isset($floorplan['lastModified']) ? date("Y-m-d H:i:s", strtotime($floorplan['lastModified'])) : ''
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'floorplan', $media, true );

					// Media - Brochures
				    $media = array();
				    if (isset($property['documents']) && !empty($property['documents']))
					{
						foreach ($property['documents'] as $document)
						{
							if ( 
								$document['webDisplay'] == true
								&&
								$document['private'] == false
							)
							{
								$url = $document['url'];

								$explode_url = explode("?", $url);
								$filename = basename( $explode_url[0] );

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
									'description' => isset($document['customTitle']) ? $document['customTitle'] : ( isset($document['title']) ? $document['title'] : '' ),
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'brochure', $media, false );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['lastModified']) ) { update_post_meta( $post_id, '_agentbox_json_update_date_' . $this->import_id, $property['lastModified'] ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_agentbox_json", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_agentbox_json" );

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
                'Available' => 'Available',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'House' => 'House',
            ),
            'commercial_property_type' => array(
                'Office' => 'Office',
				'Retail' => 'Retail',
				'Warehouse' => 'Warehouse',
            )
        );
	}
}

}