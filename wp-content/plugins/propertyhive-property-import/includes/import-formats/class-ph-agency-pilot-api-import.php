<?php
/**
 * Class for managing the import process using the Agency Pilot REST API
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Agency_Pilot_API_Import extends PH_Property_Import_Process {

	/**
	 * @var string
	 */
	private $token;

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

	public function get_token( $test = false )
	{
		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

		$token = '';

		$response = wp_remote_post(
			$import_settings['url'] . '/api/version1_0_1/Token',
			array(
				'method' => 'POST',
				'timeout' => 45,
				'headers' => array( 
					'Content-Type' => 'application/xwww-form-urlencoded', 
					'Accept' => 'application/json' 
				),
				'body' => array( 
					'grant_type' => 'client_credentials', 
					'client_id' => ( ( isset($import_settings['client_id']) ) ? $import_settings['client_id'] : '' ), 
					'client_secret' => ( ( isset($import_settings['client_secret']) ) ? $import_settings['client_secret'] : '' ) 
				),
		    )
		);

		if ( is_wp_error( $response ) ) 
		{
			$this->log_error( 'Failed to request token: ' . $response->get_error_message() );
			return false;
		}
		else
		{
			$body = json_decode($response['body'], TRUE);

			if ( $body === false )
			{
				$this->log_error( 'Failed to decode token request body: ' . $response['body'] );
				return false;
			}
			else
			{
				if ( isset($body['access_token']) )
				{
					$token = $body['access_token'];

					$this->log("Got token " . $token );

					return $token;
				}
				else
				{
					$this->log_error( 'Failed to get access_token part of response body: ' . $response['body'] );
					return false;
				}
			}
		}
	}

	public function parse( $test = false )
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		$this->token = $this->get_token( $test );

		if ( $this->token === false )
		{
			return false;
		}

		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

		$body = array(
			"DisplayOptions" => array(
				"Additional" => true,
				"Photos" => true,
				"DocumentMedia" => true,
				"Floors" => true,
				"Agents" => true,
				"Auctions" => false,
				"SystemDetails" => true,
				"Categories" => true
			),
			"FilterOptions" => array(
				"ActiveOnly" => true,
				"ShowOnInternet" => true
			)
		);

		$body = apply_filters( "propertyhive_agency_pilot_api_request_body", $body );

		$response = wp_remote_post(
			$import_settings['url'] . '/api/version1_0_1/PropertyFeed/Property',
			array(
				'method' => 'POST',
				'timeout' => 45,
				'headers' => array( 
					'Authorization' => 'Bearer ' . $this->token, 
					'Content-Type' => 'application/json', 
					'Accept' => 'application/json' 
				),
				'body' => json_encode($body),
		    )
		);

		if ( is_wp_error( $response ) ) 
		{
			$this->log_error( 'Failed to request properties: ' . $response->get_error_message() );
			return false;
		}
		else
		{
			$body = json_decode($response['body'], TRUE);

			if ( $body === false )
			{
				$this->log_error( 'Failed to decode properties request body: ' . $response['body'] );
				return false;
			}
			else
			{
				$limit = $this->get_property_limit();

				foreach ( $body as $property ) 
				{
					if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
	                {
	                    return true;
	                }

					$this->properties[] = $property;
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

        do_action( "propertyhive_pre_import_properties_agency_pilot_api", $this->properties, $this->import_id, $this->token );
        $this->properties = apply_filters( "propertyhive_agency_pilot_api_properties_due_import", $this->properties, $this->import_id, $this->token );

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
			do_action( "propertyhive_property_importing_agency_pilot_api", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['ID'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['ID'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['ID'], false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['ID'], 0, $property['ID'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = ( ( isset($property['Address']['DisplayAddress']) ) ? $property['Address']['DisplayAddress'] : '' );
            if ($display_address == '')
            {
                $display_address = $property['Address']['Street'];
                if ($property['Address']['Town'] != '')
                {
                    if ($display_address != '')
                    {
                        $display_address .= ', ';
                    }
                    $display_address .= $property['Address']['Town'];
                }
            }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['ID'], $property, $display_address, $property['Description'], '', ( $property['SystemDetail']['DateRegistered'] ) ? date( 'Y-m-d H:i:s', strtotime( $property['SystemDetail']['DateRegistered'] )) : '' );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['ID'] );

				update_post_meta( $post_id, $imported_ref_key, $property['ID'] );

				update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

				$previous_update_date = get_post_meta( $post_id, '_agency_pilot_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['SystemDetail']['DateUpdated']) ||
						(
							isset($property['SystemDetail']['DateUpdated']) &&
							empty($property['SystemDetail']['DateUpdated'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['SystemDetail']['DateUpdated']) &&
							!empty($property['SystemDetail']['DateUpdated']) &&
							$previous_update_date != '' &&
							strtotime($property['SystemDetail']['DateUpdated']) > $previous_update_date
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
					update_post_meta( $post_id, '_reference_number',  ( ( isset($property['FileRef']) ) ? $property['FileRef'] : '' ) );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['Address']['BuildingName']) ) ? $property['Address']['BuildingName'] : '' ) . ' ' . ( ( isset($property['Address']['SecondaryName']) ) ? $property['Address']['SecondaryName'] : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property['Address']['Street']) ) ? $property['Address']['Street'] : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property['Address']['District']) ) ? $property['Address']['District'] : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['Address']['Town']) ) ? $property['Address']['Town'] : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property['Address']['County']) ) ? $property['Address']['County'] : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property['Address']['Postcode']) ) ? $property['Address']['Postcode'] : '' ) );

					$country = get_option( 'propertyhive_default_country', 'GB' );
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_agency_pilot_api_address_fields_to_check', array('District', 'Town', 'County') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property['Address'][$address_field]) && trim($property['Address'][$address_field]) != '' ) 
						{
							$term = term_exists( trim($property['Address'][$address_field]), 'location' );
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
					update_post_meta( $post_id, '_latitude', ( ( isset($property['Address']['Latitude']) ) ? $property['Address']['Latitude'] : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property['Address']['Longitude']) ) ? $property['Address']['Longitude'] : '' ) );

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					$negotiator_id = false;
					if ( isset($property['SystemDetail']['AccountManagers']) && is_array($property['SystemDetail']['AccountManagers']) && !empty($property['SystemDetail']['AccountManagers']) )
					{
						foreach ( $property['SystemDetail']['AccountManagers'] as $account_manager )
						{
							if ( $negotiator_id !== false )
							{
								continue;
							}

							$negotiator_row = $wpdb->get_row( $wpdb->prepare(
						        "SELECT `ID` FROM $wpdb->users WHERE `display_name` = %s", $account_manager['Name']
						    ) );
						    if ( null !== $negotiator_row )
						    {
						    	$negotiator_id = $negotiator_row->ID;
						    }
						}
					}
					if ( $negotiator_id === false )
					{
						$negotiator_id = get_current_user_id();
					}
					update_post_meta( $post_id, '_negotiator_id', (int)$negotiator_id );

					$office_id = $this->primary_office_id;
					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( isset($property['SystemDetail']['Partner']['ID']) && $branch_code == $property['SystemDetail']['Partner']['ID'] )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'commercial';
					if ( isset($property['Residential']) && $property['Residential'] === true )
					{
						if ( $property['Tenure']['ForSale'] == true )
						{
							$department = 'residential-sales';
						}
						elseif ( $property['Tenure']['ForRent'] == true )
						{
							$department = 'residential-lettings';
						}
					}

					update_post_meta( $post_id, '_department', $department );

					if ( $department == 'residential-sales' || $department == 'residential-lettings' )
					{
						update_post_meta( $post_id, '_bedrooms', ( ( isset($property['Size']['TotalSize']) ) ? $property['Size']['TotalSize'] : '' ) );
						update_post_meta( $post_id, '_bathrooms', ( ( isset($property['Size']['Bathrooms']) ) ? $property['Size']['Bathrooms'] : '' ) );
						update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['Size']['ReceptionRooms']) ) ? $property['Size']['ReceptionRooms'] : '' ) );
					}

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['SystemDetail']['Partner']['ID'] . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['SystemDetail']['Partner']['ID'] . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['SystemDetail']['Partner']['ID'] . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['SystemDetail']['Partner']['ID']]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['SystemDetail']['Partner']['ID']] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['SystemDetail']['Partner']['ID']]);
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

	        		if ( $department == 'residential-sales' )
	        		{
	        			$price = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForSalePriceFrom']);
	                    if ( $price == '' )
	                    {
	                        $price = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForSalePriceTo']);
	                    }
	                    if ( $price != '' )
			            {
				            $price = str_replace(".00", "", number_format($price, 2, '.', ''));
				        }

	        			update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );
						update_post_meta( $post_id, '_poa', ( isset($property['Tenure']['ForSaleTerm']['Name']) && ( strpos(strtolower($property['Tenure']['ForSaleTerm']['Name']), 'application') !== FALSE || strpos(strtolower($property['Tenure']['ForSaleTerm']['Name']), 'poa') !== FALSE ) ) ? 'yes' : '' );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

			            if ( !empty($mapping) && isset($property['Tenure']['ForSaleTerm']['Name']) && isset($mapping[$property['Tenure']['ForSaleTerm']['Name']]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property['Tenure']['ForSaleTerm']['Name']], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

	                    // Tenure
			            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();
						
						if ( !empty($mapping) && isset($property['Tenure']['Tenure']) && isset($mapping[$property['Tenure']['Tenure']]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$property['Tenure']['Tenure']], 'tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'tenure' );
			            }
	        		}
	        		elseif ( $department == 'residential-lettings' )
	        		{
	        			$rent = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForRentPriceFrom']);
	                    if ( $rent == '' )
	                    {
	                        $rent = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForRentPriceTo']);
	                    }
	                    if ( $rent != '' )
			            {
				            $rent = str_replace(".00", "", number_format($rent, 2, '.', ''));
				        }

						update_post_meta( $post_id, '_rent', $rent );

						$rent_frequency = 'pa';
		           		if ( isset($property['Tenure']['ForRentTerm']['Name']) )
			            {
			            	switch ( strtolower($property['Tenure']['ForRentTerm']['Name']) )
			            	{
			            		case "per month":
			            		case "pcm": { $rent_frequency = 'pcm'; break; }
			            	}
			            }
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						
						update_post_meta( $post_id, '_poa', ( isset($property['Tenure']['ForRentTerm']['Name']) && ( strpos(strtolower($property['Tenure']['ForRentTerm']['Name']), 'application') !== FALSE || strpos(strtolower($property['Tenure']['ForRentTerm']['Name']), 'poa') !== FALSE ) ) ? 'yes' : '' );

						update_post_meta( $post_id, '_deposit', '' );
	            		update_post_meta( $post_id, '_available_date', '' );
	        		}
	        		elseif ( $department == 'commercial' )
	        		{
						update_post_meta( $post_id, '_for_sale', '' );
		        		update_post_meta( $post_id, '_to_rent', '' );

		        		if ( $property['Tenure']['ForSale'] == true )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    $price = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForSalePriceFrom']);
		                    if ( $price == '' )
		                    {
		                        $price = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForSalePriceTo']);
		                    }
		                    if ( $price != '' )
				            {
					            $price = str_replace(".00", "", number_format($price, 2, '.', ''));
					        }
		                    update_post_meta( $post_id, '_price_from', $price );

		                    $price = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForSalePriceTo']);
		                    if ( $price == '' || $price == '0' )
		                    {
		                        $price = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForSalePriceFrom']);
		                    }
		                    if ( $price != '' )
				            {
					            $price = str_replace(".00", "", number_format($price, 2, '.', ''));
					        }
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( isset($property['Tenure']['ForSaleTerm']['Name']) && ( strpos(strtolower($property['Tenure']['ForSaleTerm']['Name']), 'application') !== FALSE || strpos(strtolower($property['Tenure']['ForSaleTerm']['Name']), 'poa') !== FALSE ) ) ? 'yes' : '' );

		                    // Price Qualifier
				            $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
							
				            if ( !empty($mapping) && isset($property['Tenure']['ForSaleTerm']['Name']) && isset($mapping[$property['Tenure']['ForSaleTerm']['Name']]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[$property['Tenure']['ForSaleTerm']['Name']], 'price_qualifier' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
				            }

		                    // Tenure
				            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();
							
							if ( !empty($mapping) && isset($property['Tenure']['Tenure']) && isset($mapping[$property['Tenure']['Tenure']]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[$property['Tenure']['Tenure']], 'commercial_tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
				            }
		                }

		                if ( $property['Tenure']['ForRent'] == true )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    $rent = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForRentPriceFrom']);
		                    if ( $rent == '' )
		                    {
		                        $rent = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForRentPriceTo']);
		                    }
		                    if ( $rent != '' )
				            {
					            $rent = str_replace(".00", "", number_format($rent, 2, '.', ''));
					        }
		                    update_post_meta( $post_id, '_rent_from', $rent );

		                    $rent = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForRentPriceTo']);
		                    if ( $rent == '' || $rent == '0' )
		                    {
		                        $rent = preg_replace("/[^0-9.]/", '', $property['Tenure']['ForRentPriceFrom']);
		                    }
		                    if ( $rent != '' )
				            {
					            $rent = str_replace(".00", "", number_format($rent, 2, '.', ''));
					        }
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    $rent_units = 'pa';
			           		if ( isset($property['Tenure']['ForRentTerm']['Name']) )
				            {
				            	switch ( strtolower($property['Tenure']['ForRentTerm']['Name']) )
				            	{
				            		case "per month":
				            		case "pcm": { $rent_units = 'pcm'; break; }
				            		case "per sq ft": { $rent_units = 'psqft'; break; }
				            		case "per sq m": { $rent_units = 'psqm'; break; }
				            	}
				            }
		                    update_post_meta( $post_id, '_rent_units', $rent_units); // look at ForRentTerm field

		                    update_post_meta( $post_id, '_rent_poa', ( isset($property['Tenure']['ForRentTerm']['Name']) && ( strpos(strtolower($property['Tenure']['ForRentTerm']['Name']), 'application') !== FALSE || strpos(strtolower($property['Tenure']['ForRentTerm']['Name']), 'poa') !== FALSE ) ) ? 'yes' : '' );
		                }

			            $units = 'sqft';
			            if ( isset($property['Size']['Dimension']['Name']) )
			            {
			            	switch ( $property['Size']['Dimension']['Name'] )
			            	{
			            		case "Sq M": { $units = 'sqm'; break; }
			            		case "Acres": { $units = 'acre'; break; }
			            	}
			            }

			            $size = preg_replace("/[^0-9.]/", '', $property['Size']['MinSize']);
			            if ( $size == '' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', $property['Size']['MaxSize']);
			            }
			            if ( $size != '' )
			            {
				            $size = str_replace(".00", "", number_format($size, 2, '.', ''));
				        }
			            update_post_meta( $post_id, '_floor_area_from', $size );

			            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $units ) );

			            $size = preg_replace("/[^0-9.]/", '', $property['Size']['MaxSize']);
			            if ( $size == '' || $size == '0' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', $property['Size']['MinSize']);
			            }
			            if ( $size != '' )
			            {
				            $size = str_replace(".00", "", number_format($size, 2, '.', ''));
				        }
			            update_post_meta( $post_id, '_floor_area_to', $size );

			            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $units ) );

			            update_post_meta( $post_id, '_floor_area_units', $units );

			            $size = '';

			            update_post_meta( $post_id, '_site_area_from', $size );

			            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

			            update_post_meta( $post_id, '_site_area_to', $size );

			            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, 'sqft' ) );

			            update_post_meta( $post_id, '_site_area_units', $units );
			        }

			        // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

					// Property Type
					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
					
					if ( isset($property['PropertyTypes']) && is_array($property['PropertyTypes']) && !empty($property['PropertyTypes']) )
					{
						$term_ids = array();

						foreach ( $property['PropertyTypes'] as $property_type )
						{
							if ( !empty($mapping) && isset($mapping[$property_type['ID']]) )
							{
								$term_ids[] = (int)$mapping[$property_type['ID']];
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

		            $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
					if ( $on_market_by_default === true )
					{
						update_post_meta( $post_id, '_on_market', 'yes' );
					}
					$featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', ( isset($property['Featured']) && $property['Featured'] == true ) ? 'yes' : '' );
					}
					
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();
					
	        		if ( isset($property['MarketStatus']) && is_array($property['MarketStatus']) )
	        		{
						if ( !empty($mapping) && isset($mapping[$property['MarketStatus']['ID']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['MarketStatus']['ID']], 'availability' );
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

					$features = array();
					if ( isset($property['Additional']['Bullets']) && is_array($property['Additional']['Bullets']) && !empty($property['Additional']['Bullets']) )
					{
						foreach ( $property['Additional']['Bullets'] as $bullet )
						{
							if ( isset($bullet['BulletPoint']) && $bullet['BulletPoint'] != '' )
							{
								$features[] = $bullet['BulletPoint'];
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

			        if ( $department == 'commercial' )
                    {
                        $rooms_count = 0;
                        if ( isset( $property['Description'] ) && !empty( $property['Description'] ) )
                        {
                            update_post_meta( $post_id, '_description_name_0', '' );
                            update_post_meta( $post_id, '_description_0', $property['Description'] );

                            ++$rooms_count;
                        }

                        if ( isset( $property['Additional']['Info'] ) && is_array( $property['Additional']['Info'] ) )
                        {
                            foreach( $property['Additional']['Info'] as $room )
                            {
                            	if ( !empty($room['Description']) && !empty($room['Information']) )
                            	{
	                                update_post_meta( $post_id, '_description_name_' . $rooms_count, $room['Description'] );
	                                update_post_meta( $post_id, '_description_' . $rooms_count, $room['Information'] );

	                                ++$rooms_count;
	                            }
                            }
                        }

                        if ( $rooms_count > 0 )
                        {
                            update_post_meta( $post_id, '_descriptions', $rooms_count );
                        }
                    }
                    else
                    {
                        $rooms_count = 0;
                        if ( isset( $property['Description'] ) && !empty( $property['Description'] ) )
                        {
                            update_post_meta( $post_id, '_room_name_0', '' );
                            update_post_meta( $post_id, '_room_dimensions_0', '' );
                            update_post_meta( $post_id, '_room_description_0', $property['Description'] );

                            ++$rooms_count;
                        }

                        if ( isset( $property['Additional']['Info'] ) && is_array( $property['Additional']['Info'] ) )
                        {
                            foreach( $property['Additional']['Info'] as $room )
                            {
                            	if ( !empty($room['Description']) && !empty($room['Information']) )
                            	{
	                                update_post_meta( $post_id, '_room_name_' . $rooms_count, $room['Description'] );
	                                update_post_meta( $post_id, '_room_dimensions_' . $rooms_count, '' );
	                                update_post_meta( $post_id, '_room_description_' . $rooms_count, $room['Information'] );

	                                ++$rooms_count;
	                            }
                            }
                        }

                        if ( $rooms_count > 0 )
                        {
                            update_post_meta( $post_id, '_rooms', $rooms_count );
                        }
                    }

			        // Media - Images
				    $media = array();
				    if ( isset($property['Photos']) && is_array($property['Photos']) && !empty($property['Photos']) )
	                {
						foreach ( $property['Photos'] as $image )
						{
							$url = $image['URL'];
							$url = str_replace("_sm.", ".", $url);
							$url = str_replace("_web.", ".", $url);

							$media[] = array(
								'url' => $url,
								'description' => ( ( isset($image['Name']) && !empty($image['Name']) ) ? $image['Name'] : '' ),
							);
						}
					}

					$this->import_media( $post_id, $property['ID'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property['DocumentMedia']) && is_array($property['DocumentMedia']) && !empty($property['DocumentMedia']) )
					{
						foreach ( $property['DocumentMedia'] as $floorplan )
						{
							if ( !isset($floorplan['Description']) || ( isset($floorplan['Description']) && strpos(strtolower($floorplan['Description']), 'plan') === FALSE ) )
							{
								continue;
							}

							if ( isset($floorplan['URLs']) && is_array($floorplan['URLs']) && !empty($floorplan['URLs']) )
							{
								foreach ($floorplan['URLs'] as $url)
								{
									$media[] = array(
										'url' => $url,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, $property['ID'], 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if ( isset($property['DocumentMedia']) && is_array($property['DocumentMedia']) && !empty($property['DocumentMedia']) )
					{
						foreach ( $property['DocumentMedia'] as $brochure )
						{
							if ( !isset($brochure['Description']) || ( isset($brochure['Description']) && strpos(strtolower($brochure['Description']), 'brochure') === FALSE ) )
							{
								continue;
							}

							if ( isset($brochure['URLs']) && is_array($brochure['URLs']) && !empty($brochure['URLs']) )
							{
								foreach ($brochure['URLs'] as $url)
								{
									$media[] = array(
										'url' => $url,
										'modified' => date("Y-m-d H:i:s", strtotime($property['SystemDetail']['DateUpdated'])),
									);
								}
							}
						}
					}

					$this->import_media( $post_id, $property['ID'], 'brochure', $media, true );

					// Media - EPCs
				    $media = array();
				    if ( isset($property['DocumentMedia']) && is_array($property['DocumentMedia']) && !empty($property['DocumentMedia']) )
					{
						foreach ( $property['DocumentMedia'] as $epc )
						{
							if ( !isset($epc['Description']) || ( isset($epc['Description']) && strpos(strtolower($epc['Description']), 'epc') === FALSE) )
							{
								continue;
							}

							if ( isset($epc['URLs']) && is_array($epc['URLs']) && !empty($epc['URLs']) )
							{
								foreach ($epc['URLs'] as $url)
								{
									$media[] = array(
										'url' => $url,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, $property['ID'], 'epc', $media, false );

					if ( isset($property['Additional']['VirtualTourUrl']) && $property['Additional']['VirtualTourUrl'] != '' )
					{
						update_post_meta($post_id, '_virtual_tours', 1 );
				        update_post_meta($post_id, '_virtual_tour_0', $property['Additional']['VirtualTourUrl']);

				        $this->log( 'Imported 1 virtual tour', $post_id, $property['ID'] );
					}
					else
					{
						update_post_meta($post_id, '_virtual_tours', 0 );
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['ID'] );
				}

				if ( isset($property['SystemDetail']['DateUpdated']) ) { update_post_meta( $post_id, '_agency_pilot_update_date_' . $this->import_id, strtotime($property['SystemDetail']['DateUpdated']) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_agency_pilot_api", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['ID'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_agency_pilot_api" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['ID'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values() 
	{
		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->token = $this->get_token();

		if ( $this->token === false )
		{
			return array();
		}

		$return = array();

        $options = array();

    	$response = wp_remote_get(
			$import_settings['url'] . '/api/version1_0_1/PropertyFeed/MarketStatus',
			array(
				'headers' => array( 
					'Authorization' => 'Bearer ' . $this->token, 
					'Content-Type' => 'application/json', 
					'Accept' => 'application/json' 
				),
				'body' => '',
		    )
		);

		if ( !is_wp_error( $response ) ) 
		{
			$json = json_decode($response['body'], TRUE);

			if ( $json !== false )
			{
				foreach ( $json as $value )
				{
					$options[$value['ID']] = $value['Name'];
				}
				ksort($options);
			}
		}

		$return['sales_availability'] = $options;
		$return['lettings_availability'] = $options;
		$return['commercial_availability'] = $options;

		$options = array();

    	$response = wp_remote_get(
			$import_settings['url'] . '/api/version1_0_1/PropertyFeed/PropertyTypes',
			array(
				'headers' => array( 
					'Authorization' => 'Bearer ' . $this->token, 
					'Content-Type' => 'application/json', 
					'Accept' => 'application/json' 
				),
				'body' => '',
		    )
		);

		if ( !is_wp_error( $response ) ) 
		{
			$json = json_decode($response['body'], TRUE);

			if ( $json !== FALSE )
			{
				foreach ( $json as $value )
				{
					$options[$value['ID']] = $value['Name'];
				}
				ksort($options);
			}
		}

		$return['property_type'] = $options;
		$return['commercial_property_type'] = $options;
        
    	$return['price_qualifier'] = array(
    		'Offers in the Region of' => 'Offers in the Region of',
    		'Offer in Excess of' => 'Offer in Excess of'
    	);

    	$return['tenure'] = array(
    		'Freehold' => 'Freehold',
            'Leasehold' => 'Leasehold',
    	);
    	$return['commercial_tenure'] = array(
    		'Freehold' => 'Freehold',
            'Leasehold' => 'Leasehold',
    	);

        return $return;
    }
}

}