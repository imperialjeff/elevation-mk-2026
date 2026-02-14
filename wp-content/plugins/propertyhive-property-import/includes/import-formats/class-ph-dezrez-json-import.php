<?php
/**
 * Class for managing the import process of an Dezrez JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Dezrez_JSON_Import extends PH_Property_Import_Process {

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

		$page_size = 500;

		$limit = $this->get_property_limit();

		$api_calls = array(
			'sales' => array(
				'PageSize' => $page_size,
				'PageNumber' => 1,
				'IncludeStc' => 'true',
				'RoleTypes' => array('Selling'),
				'MarketingFlags' => array('ApprovedForMarketingWebsite')
			),
			'lettings' => array(
				'PageSize' => $page_size,
				'PageNumber' => 1,
				'IncludeStc' => 'true',
				'RoleTypes' => array('Letting'),
				'MarketingFlags' => array('ApprovedForMarketingWebsite')
			)
		);
		
		$api_calls = apply_filters( 'propertyhive_dezrez_json_api_calls', $api_calls, $this->import_id );

		foreach ( $api_calls as $department => $params )
		{
			$this->log("Parsing " . $department . " properties");

			$more_properties = true;
			$page_number = 1;
			$total_pages = 1;
			$max_pages = 100;
			while ( $more_properties )
			{
				if ( $page_number > $max_pages ) 
				{
				    $this->log_error('Exceeded maximum allowed pages. Stopping to avoid infinite loop.');
				    return false;
				}

				$params['PageNumber'] = $page_number;

				$this->log("Parsing " . $department . " properties on page " . $page_number);

				$search_url = 'https://api.dezrez.com/api/simplepropertyrole/search';
				$fields = array(
					'APIKey' => urlencode($import_settings['api_key']),
				);

				$fields_string = '';
				foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
				$fields_string = rtrim($fields_string, '&');

				$search_url = $search_url . '?' . $fields_string;
				$contents = '';

				$post_fields = $params;
				if ( isset($import_settings['branch_ids']) && trim($import_settings['branch_ids']) != '' )
				{
					$post_fields['BranchIdList'] = array();
					$branch_ids = explode(",", $import_settings['branch_ids']);
					foreach ( $branch_ids as $branch_id )
					{
						$post_fields['BranchIdList'][] = trim($branch_id);
					}
				}
				if ( isset($import_settings['tags']) && trim($import_settings['tags']) != '' )
				{
					$post_fields['Tags'] = array();
					$tags = explode(",", $import_settings['tags']);
					foreach ( $tags as $tag )
					{
						$post_fields['Tags'][] = trim($tag);
					}
				}

				$contents = '';

				$response = wp_remote_post( 
					$search_url, 
					array(
						'method' => 'POST',
						'timeout' => 120,
						'headers' => array(
							'Rezi-Api-Version' => '1.0',
							'Content-Type' => 'application/json'
						),
						'body' => json_encode( $post_fields ),
				    )
				);

				usleep(500000);

				if ( !is_wp_error( $response ) && is_array( $response ) ) 
				{
					if ( wp_remote_retrieve_response_code($response) !== 200 )
		            {
		                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties: ' . wp_remote_retrieve_response_message($response) );
		                return false;
		            }

					$contents = $response['body'];

					$json = json_decode( $contents, TRUE );

					if ( $json !== FALSE )
					{
						if ( isset($json['TotalCount']) && isset($json['PageSize']) )
						{
							$total_pages = ceil($json['TotalCount'] / $page_size);
						}
						else
						{
							$this->log_error( 'Missing pagination data: ' . print_r($json, true) );
			        		return false;
						}

						if ( isset($json['Collection']) && !empty($json['Collection']) )
						{
				            $properties_imported = 0;

				            $properties_array = $json['Collection'];

							$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

							$this->log("Found " . count($properties_array) . " " . $department . " properties in JSON ready for parsing");

							foreach ($properties_array as $property)
							{
								if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
				                {
				                    return true;
				                }

								$property_id = $property['RoleId'];

								$agent_ref = $property_id;

								$ok_to_import = true;

								if ( $test !== true && ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' ) )
								{
									$args = array(
							            'post_type' => 'property',
							            'posts_per_page' => 1,
							            'post_status' => 'any',
							            'meta_query' => array(
							            	array(
								            	'key' => $imported_ref_key,
								            	'value' => $property_id
								            )
							            )
							        );
							        $property_query = new WP_Query($args);
							        
							        if ($property_query->have_posts())
							        {
							        	while ($property_query->have_posts())
							        	{
							        		$property_query->the_post();

						                	$dezrez_last_updated = trim( $property['LastUpdated'], 'Z' );
						                	$last_imported_date = trim( get_post_meta( get_the_ID(), '_dezrez_json_update_date_' . $this->import_id, TRUE ), 'Z' );
						                	if ($last_imported_date == $dezrez_last_updated)
						                	{
						                		$ok_to_import = false;
						                	}
						                }
					                }
					            }

				                if ( $test !== true && $ok_to_import )
				                {
									$property_url = 'https://api.dezrez.com/api/simplepropertyrole/' . $property_id;
									$fields = array(
										'APIKey' => urlencode($import_settings['api_key']),
									);

									//url-ify the data for the POST
									$fields_string = '';
									foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
									$fields_string = rtrim($fields_string, '&');

									$property_url = $property_url . '?' . $fields_string;
									
									$response = wp_remote_get( 
										$property_url, 
										array(
											'timeout' => 120,
											'headers' => array(
												'Rezi-Api-Version' => '1.0',
												'Content-Type' => 'application/json'
											),
									    )
									);

									usleep(500000);

									if ( !is_wp_error( $response ) && is_array( $response ) ) 
									{
										if ( wp_remote_retrieve_response_code($response) !== 200 )
							            {
							                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting property with ID ' . $property_id . '. Error message: ' . wp_remote_retrieve_response_message($response) );
							                return false;
							            }

										$contents = $response['body'];

										$property_json = json_decode($contents, TRUE);
										if ($property_json !== FALSE)
										{
											$property_json['RoleId'] = $property_id;
											$property_json['SummaryTextDescription'] = ( ( isset($property['SummaryTextDescription']) && !empty($property['SummaryTextDescription']) ) ? $property['SummaryTextDescription'] : '' );
											$this->properties[] = $property_json;
										}
										else
										{
											$this->log_error( 'Failed to decode property JSON: ' . print_r($contents, TRUE) );
											return false;
										}
									}
									else
									{
										$this->log_error( 'Failed to obtain property JSON. Dump of response as follows: ' . print_r($response, TRUE) );
										return false;
									}
								}
								else
								{
									// Property not been updated.
									// Lets create our own array so at least the property gets put into the $this->properties array
									$property_json = array(
										'RoleId' => $property_id,
										'fake' => 'yes'
									);
									$this->properties[] = $property_json;
								}
							}
						}
						else
				        {
				        	// Empty properties
				        	$this->log_error( 'No ' . $department . ' properties to import: ' . print_r($contents, true) );
				        	$more_properties = false;
				        }

				        if ( $total_pages == $page_number )
				        {
				        	$more_properties = false;
				        }
			        }
			        else
			        {
			        	// Failed to parse JSON
			        	$this->log_error( 'Failed to parse ' . $department . ' JSON file: ' . print_r($contents, true) );
			        	return false;
			        }
			    }
		        else
		        {
		        	$this->log_error( 'Failed to obtain ' . $department . ' JSON. Dump of response as follows: ' . print_r($response, TRUE) );
		        	return false;
		        }

		        ++$page_number;
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

        do_action( "propertyhive_pre_import_properties_dezrez_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_dezrez_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_dezrez_json", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['RoleId'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['RoleId'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['RoleId'], false );

			if ( !isset($property['fake']) )
			{
				$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['RoleId'], 0, $property['RoleId'], '', false );

				$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

		        $display_address = array();
		        if ( isset($property['Address']['Street']) && trim($property['Address']['Street']) != '' )
		        {
		        	$display_address[] = trim($property['Address']['Street']);
		        }
		        if ( isset($property['Address']['Town']) && trim($property['Address']['Town']) != '' )
		        {
		        	$display_address[] = trim($property['Address']['Town']);
		        }
		        elseif ( isset($property['Address']['Locality']) && trim($property['Address']['Locality']) != '' )
		        {
		        	$display_address[] = trim($property['Address']['Locality']);
		        }
		        elseif ( isset($property['Address']['County']) && trim($property['Address']['County']) != '' )
		        {
		        	$display_address[] = trim($property['Address']['County']);
		        }
		        $display_address = implode(", ", $display_address);

		        list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['RoleId'], $property, $display_address, $property['SummaryTextDescription'] );
		        
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

					$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['RoleId'] );

					update_post_meta( $post_id, $imported_ref_key, $property['RoleId'] );

					update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

					// Address
					update_post_meta( $post_id, '_reference_number', $property['RoleId'] );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['Address']['BuildingName']) ) ? $property['Address']['BuildingName'] : '' ) . ' ' . ( ( isset($property['Address']['Number']) ) ? $property['Address']['Number'] : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property['Address']['Street']) ) ? $property['Address']['Street'] : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property['Address']['Locality']) ) ? $property['Address']['Locality'] : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['Address']['Town']) ) ? $property['Address']['Town'] : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property['Address']['County']) ) ? $property['Address']['County'] : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property['Address']['Postcode']) ) ? $property['Address']['Postcode'] : '' ) );

					$country = get_option( 'propertyhive_default_country', 'GB' );
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_dezrez_json_address_fields_to_check', array('Locality', 'Town', 'County') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property['Address'][$address_field]) && trim($property['Address'][$address_field]) != '' ) 
						{
							$term = term_exists( trim($property['Address'][$address_field]), 'location');
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
					update_post_meta( $post_id, '_latitude', ( ( isset($property['Address']['Location']['Latitude']) ) ? $property['Address']['Location']['Latitude'] : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property['Address']['Location']['Longitude']) ) ? $property['Address']['Location']['Longitude'] : '' ) );

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					$negotiator_id = get_current_user_id();
					// Check if negotiator exists with this name
					if ( isset($property['OwningTeam']['Name']) )
					{
						foreach ( $this->negotiators as $negotiator_key => $negotiator )
						{
							if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim( $property['OwningTeam']['Name'] )) )
							{
								$negotiator_id = $negotiator_key;
							}
						}
					}
					update_post_meta( $post_id, '_negotiator_id', $negotiator_id );
					
					$office_id = $this->primary_office_id;
					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( 
								strtolower(trim($branch_code)) == strtolower(trim($property['BranchDetails']['Name']))
								|| 
								$branch_code == $property['BranchDetails']['Id']
							)
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = ( (strtolower($property['RoleType']['SystemName']) != 'selling') ? 'residential-lettings' : 'residential-sales' );

					if ( isset($property['PropertyType']['SystemName']) && $property['PropertyType']['SystemName'] != '' )
					{
						if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
						{
							$commercial_types = array( 'commercial', 'retail', 'restaurant', 'office', 'industrial', 'guest' );
							$commercial_types = apply_filters( 'propertyhive_dezrez_json_commercial_property_types', $commercial_types );

							foreach ( $commercial_types as $commercial_type )
							{
								if ( strpos( strtolower($property['PropertyType']['SystemName']), $commercial_type) !== FALSE )
								{
									$department = 'commercial';
								}
							}
						}
			        }

			        // Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['BranchDetails']['Id'] . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['BranchDetails']['Id'] . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['BranchDetails']['Id'] . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['BranchDetails']['Id']]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][$property['BranchDetails']['Id']] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['BranchDetails']['Id']]);
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

					if ( isset($property['Descriptions']) && is_array($property['Descriptions']) && !empty($property['Descriptions']) )
					{
						foreach ( $property['Descriptions'] as $description )
						{
							// Room Counts
							if ( 
								$description['Name'] == 'Room Counts' ||  
								( isset($description['DescriptionType']['SystemName']) && $description['DescriptionType']['SystemName'] == 'RoomCount' )
							)
							{
								update_post_meta( $post_id, '_bedrooms', ( ( isset($description['Bedrooms']) ) ? $description['Bedrooms'] : '' ) );
								update_post_meta( $post_id, '_bathrooms', ( ( isset($description['Bathrooms']) ) ? $description['Bathrooms'] : '' ) );
								update_post_meta( $post_id, '_reception_rooms', ( ( isset($description['Receptions']) ) ? $description['Receptions'] : '' ) );
							}

							if ( 
								$description['Name'] == 'StyleAge' ||
								( isset($description['DescriptionType']['SystemName']) && $description['DescriptionType']['SystemName'] == 'StyleAge' )
							)
							{
								// Property Type
								$prefix = '';
								if ( $department == 'commercial' )
								{
									$prefix = 'commercial_';
								}

								$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

								if ( isset($description['PropertyType']['SystemName']) && $description['PropertyType']['SystemName'] != '' )
								{
									if ( !empty($mapping) && isset($mapping[$description['PropertyType']['SystemName']]) )
									{
							            wp_set_object_terms( $post_id, (int)$mapping[$description['PropertyType']['SystemName']], $prefix . 'property_type' );
						            }
						            else
						            {
						            	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						            	$this->log( 'Property received with a type (' . $description['PropertyType']['SystemName'] . ') that is not mapped', $post_id, $property['RoleId'] );

						            	$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $description['PropertyType']['SystemName'], $post_id );
						            }
						        }
						        else
						        {
						        	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
						        }

					            // Tenure
					            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

								if ( !empty($mapping) && isset($description['LeaseType']['SystemName']) && isset($mapping[$description['LeaseType']['SystemName']]) )
								{
						            wp_set_object_terms( $post_id, (int)$mapping[$description['LeaseType']['SystemName']], 'tenure' );
					            }
					            else
					            {
					            	wp_delete_object_term_relationships( $post_id, 'tenure' );
					            }
							}

							if ( 
								( isset($description['DescriptionType']['SystemName']) && $description['DescriptionType']['SystemName'] == 'LocalAuthority' )
								&&
								( isset($description['TaxBand']['SystemName']) && $description['TaxBand']['SystemName'] != '' )
							)
							{
								update_post_meta( $post_id, '_council_tax_band', $description['TaxBand']['SystemName'] );
							}

							// Features
							if ( $description['Name'] == 'Feature Description' ||  $description['DescriptionType']['SystemName'] == 'Feature' )
							{
								$features = array();
								if ( isset($description['Features']) && is_array($description['Features']) && !empty($description['Features']) )
								{
									foreach ( $description['Features'] as $feature )
									{
										if ( isset($feature['Feature']) && trim($feature['Feature']) != '' )
										{
											$features[] = $feature['Feature'];
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
							}

							// Rooms
							if ( isset($description['Rooms']) && is_array($description['Rooms']) && !empty($description['Rooms']) )
							{
								if ( $department != 'commercial' )
								{
							        $new_room_count = 0;
									foreach ($description['Rooms'] as $room)
									{
										update_post_meta( $post_id, '_room_name_' . $new_room_count, $room['Name'] );
							            update_post_meta( $post_id, '_room_dimensions_' . $new_room_count, '' );
							            update_post_meta( $post_id, '_room_description_' . $new_room_count, $room['Text'] );

								        ++$new_room_count;
									}
									update_post_meta( $post_id, '_rooms', $new_room_count );
								}
								else
								{
									$new_room_count = 0;
									foreach ($description['Rooms'] as $room)
									{
										update_post_meta( $post_id, '_description_name_' . $new_room_count, $room['Name'] );
							            update_post_meta( $post_id, '_description_' . $new_room_count, $room['Text'] );

								        ++$new_room_count;
									}
									update_post_meta( $post_id, '_descriptions', $new_room_count );
								}
							}
						}
					}

					$poa = '';
					$featured = '';
					$on_market = '';

					if ( isset($property['Flags']) && is_array($property['Flags']) && !empty($property['Flags']) )
					{
						foreach ( $property['Flags'] as $flag )
						{
							if ( isset($flag['SystemName']) && !empty($flag['SystemName']) )
							{
								// Availability
								$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
										$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
										array();

								if ( !empty($mapping) && isset($mapping[$flag['SystemName']]) )
								{
					                wp_set_object_terms( $post_id, (int)$mapping[$flag['SystemName']], 'availability' );
					            }

					            if ( $flag['SystemName'] == 'ApprovedForMarketingWebsite' || $flag['SystemName'] == 'OnMarket' )
					            {
					            	$on_market = 'yes';
					            }

					            if ( $flag['SystemName'] == 'Featured' )
					            {
					            	$featured = 'yes';
					            }

					            if ( $flag['SystemName'] == 'PriceOnApplication' )
					            {
					            	$poa = 'yes';
					            }
							}
						}
					}

					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', $on_market );
	                }
	                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', $featured );
					}

		            $price = preg_replace("/[^0-9.]/", '', $property['Price']['PriceValue']);

					if (
						isset($property['Price']['PriceQualifierType']['SystemName']) && 
						( strtolower($property['Price']['PriceQualifierType']['SystemName']) == 'priceonapplication' || strtolower($property['Price']['PriceQualifierType']['SystemName']) == 'poa' )
					)
					{
						$poa = 'yes';
					}

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						update_post_meta( $post_id, '_price', $price );

						update_post_meta( $post_id, '_currency', 'GBP' );

						update_post_meta( $post_id, '_poa', $poa );

						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
						
						if ( isset($property['Price']['PriceQualifierType']['SystemName']) && isset($mapping[$property['Price']['PriceQualifierType']['SystemName']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['Price']['PriceQualifierType']['SystemName']], 'price_qualifier' );
			            }
			            elseif ( isset($property['Price']['PriceQualifierType']['DisplayName']) && isset($mapping[$property['Price']['PriceQualifierType']['DisplayName']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['Price']['PriceQualifierType']['DisplayName']], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }
					}
					elseif ( $department == 'residential-lettings' )
					{
						$rent_frequency = 'pcm';

						if ( isset($property['Price']['PriceType']['SystemName']) )
						{
							switch ($property['Price']['PriceType']['SystemName'])
							{
								case "Daily": { $rent_frequency = 'pw'; $price = ($price * 365) / 52; break; }
								case "Weekly": { $rent_frequency = 'pw'; break; }
								case "Fortnightly": { $rent_frequency = 'pw'; $price = ($price / 2); break; }
								case "FourWeekly": { $rent_frequency = 'pcm'; $price = ($price * 13) / 12; break; }
								case "Quarterly": { $rent_frequency = 'pq';break; }
								case "SixMonthly": { $rent_frequency = 'pa'; $price = ($price * 2); break; }
								case "Yearly": { $rent_frequency = 'pa'; break; }
							}
						}

						update_post_meta( $post_id, '_rent', $price );
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

						update_post_meta( $post_id, '_currency', 'GBP' );

						update_post_meta( $post_id, '_poa', $poa );

						update_post_meta( $post_id, '_deposit', '' );
	            		update_post_meta( $post_id, '_available_date', ( (isset($property['AvailableDate']) && $property['AvailableDate'] != '') ? date("Y-m-d", strtotime($property['AvailableDate'])) : '' ) );

	            		// We don't receive furnished options in the feed
					}
					elseif ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

						if ( strtolower($property['RoleType']['SystemName']) == 'selling' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', $poa );

		                    // Price Qualifier
							$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
							
							if ( isset($property['Price']['PriceQualifierType']['SystemName']) && isset($mapping[$property['Price']['PriceQualifierType']['SystemName']]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[$property['Price']['PriceQualifierType']['SystemName']], 'price_qualifier' );
				            }
				            elseif ( isset($property['Price']['PriceQualifierType']['DisplayName']) && isset($mapping[$property['Price']['PriceQualifierType']['DisplayName']]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[$property['Price']['PriceQualifierType']['DisplayName']], 'price_qualifier' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
				            }
						}
						else
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    update_post_meta( $post_id, '_rent_from', $price );
		                    update_post_meta( $post_id, '_rent_to', $price );

		                    $rent_frequency = 'pcm';
		                    if ( isset($property['Price']['PriceType']['SystemName']) )
							{
								switch ($property['Price']['PriceType']['SystemName'])
								{
									case "Daily": { $rent_frequency = 'pw'; break; }
									case "Weekly": { $rent_frequency = 'pw'; break; }
									case "Fortnightly": { $rent_frequency = 'pw'; break; }
									case "FourWeekly": { $rent_frequency = 'pcm'; break; }
									case "Quarterly": { $rent_frequency = 'pq'; break; }
									case "SixMonthly": { $rent_frequency = 'pa'; break; }
									case "Yearly": { $rent_frequency = 'pa'; break; }
								}
							}
		                    update_post_meta( $post_id, '_rent_units', $rent_frequency );

		                    update_post_meta( $post_id, '_rent_poa', $poa );
						}

						update_post_meta( $post_id, '_floor_area_from', '' );
						update_post_meta( $post_id, '_floor_area_from_sqft', '' );
						update_post_meta( $post_id, '_floor_area_to', '' );
						update_post_meta( $post_id, '_floor_area_to_sqft', '' );
						update_post_meta( $post_id, '_floor_area_units', 'sqft');
					}

					// Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );
				
		            // Media - Images
				    $media = array();
				    if ( isset($property['Images']) && !empty($property['Images']) )
					{
						foreach ( $property['Images'] as $image )
						{
							if (
								isset($image['DocumentType']['SystemName']) && $image['DocumentType']['SystemName'] == 'Image'
								&&
								isset($image['DocumentSubType']['SystemName']) && $image['DocumentSubType']['SystemName'] == 'Photo'
							)
							{
								$url = $image['Url'];
								if ( strpos(strtolower($url), 'width=') === FALSE )
								{
									// If no width passed then set to 2048
									$url .= ( ( strpos($url, '?') === FALSE ) ? '?' : '&' ) . 'width=';
									$url .= apply_filters( 'propertyhive_dezrez_json_image_width', '2048' );
								}

								$filename = basename( $url );
								$exploded_filename = explode(".", $filename);
							    $ext = 'jpg';
							    if (strlen($exploded_filename[count($exploded_filename)-1]) == 3)
							    {
							    	$ext = $exploded_filename[count($exploded_filename)-1];
							    }
							    $filename = $property['RoleId'] . '_' . $image['Id'] . '.' . $ext;

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['RoleId'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property['Documents']) && !empty($property['Documents']) )
					{
						foreach ( $property['Documents'] as $document )
						{
							if ( 
								isset($document['DocumentType']['SystemName']) && $document['DocumentType']['SystemName'] == 'Image'
								&&
								isset($document['DocumentSubType']['SystemName']) && $document['DocumentSubType']['SystemName'] == 'Floorplan'
							)
							{
								$url = $document['Url'];
								if ( strpos(strtolower($url), 'width=') === FALSE )
								{
									// If no width passed then set to 2048
									$url .= ( ( strpos($url, '?') === FALSE ) ? '?' : '&' ) . 'width=';
									$url .= apply_filters( 'propertyhive_dezrez_json_floorplan_width', '2048' );
								}

								$filename = basename( $url );
								$exploded_filename = explode(".", $filename);
							    $ext = 'jpg';
							    if (strlen($exploded_filename[count($exploded_filename)-1]) == 3)
							    {
							    	$ext = $exploded_filename[count($exploded_filename)-1];
							    }
							    $filename = $property['RoleId'] . '_' . $document['Id'] . '.' . $ext;

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['RoleId'], 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if ( isset($property['Documents']) && !empty($property['Documents']) )
					{
						foreach ( $property['Documents'] as $document )
						{
							if ( 
								isset($document['DocumentType']['SystemName']) && $document['DocumentType']['SystemName'] == 'Document'
		                        &&
		                        isset($document['DocumentSubType']['SystemName']) && $document['DocumentSubType']['SystemName'] == 'Brochure'
							)
							{
								$url = $document['Url'];

								$filename = basename( $url );
								$exploded_filename = explode(".", $filename);
							    $ext = 'pdf';
							    if (strlen($exploded_filename[count($exploded_filename)-1]) == 3)
							    {
							    	$ext = $exploded_filename[count($exploded_filename)-1];
							    }
							    $filename = $property['RoleId'] . '_' . $document['Id'] . '.' . $ext;

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['RoleId'], 'brochure', $media, false );

					// Media - EPCs
					$existing_epc_urls = array();
				    $media = array();
				    if ( 
						isset($property['EPC']['Image']['Url']) && 
						!empty($property['EPC']['Image']['Url'])  && 
						(
							substr( strtolower($property['EPC']['Image']['Url']), 0, 2 ) == '//' || 
							substr( strtolower($property['EPC']['Image']['Url']), 0, 4 ) == 'http'
						)
					)
            		{
						$url = $property['EPC']['Image']['Url'];

						$filename = basename( $url );
						$exploded_filename = explode(".", $filename);
					    $ext = 'jpg';
					    if (strlen($exploded_filename[count($exploded_filename)-1]) == 3)
					    {
					    	$ext = $exploded_filename[count($exploded_filename)-1];
					    }
					    $filename = $property['RoleId'] . '_epc.' . $ext;

						$media[] = array(
							'url' => $url,
							'filename' => $filename,
						);

						$existing_epc_urls[] = $url;
					}
					if ( isset($property['Documents']) && !empty($property['Documents']) )
					{
						foreach ( $property['Documents'] as $document )
						{
							if ( 
								isset($document['DocumentSubType']['SystemName']) && $document['DocumentSubType']['SystemName'] == 'EPC'
							)
							{
								$url = $document['Url'];

								if ( in_array($url, $existing_epc_urls) )
								{
									continue;
								}

								$filename = basename( $url );
								$exploded_filename = explode(".", $filename);
							    $ext = 'pdf';
							    if (strlen($exploded_filename[count($exploded_filename)-1]) == 3)
							    {
							    	$ext = $exploded_filename[count($exploded_filename)-1];
							    }
							    $filename = $property['RoleId'] . '_' . $document['Id'] . '.' . $ext;

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
								);

								$existing_epc_urls[] = $url;
							}
						}
					}

					$this->import_media( $post_id, $property['RoleId'], 'epc', $media, false );

					// Media - Virtual Tours
					$virtual_tours = array();
					if ( isset($property['Documents']) && !empty($property['Documents']) )
		            {
		                foreach ( $property['Documents'] as $document )
		                {
		                    if ( 
		                        isset($document['Url']) && $document['Url'] != ''
		                        &&
		                        (
		                            substr( strtolower($document['Url']), 0, 2 ) == '//' || 
		                            substr( strtolower($document['Url']), 0, 4 ) == 'http'
		                        )
		                        &&
		                        ( isset($document['DocumentType']['SystemName']) && ( $document['DocumentType']['SystemName'] == 'Link' || $document['DocumentType']['SystemName'] == 'Video' ) )
		                        &&
		                        isset($document['DocumentSubType']['SystemName']) && $document['DocumentSubType']['SystemName'] == 'VirtualTour'
		                    )
		                    {
		                        $virtual_tours[] = $document['Url'];
		                    }
		                }
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ( $virtual_tours as $i => $virtual_tour )
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['RoleId'] );

					update_post_meta( $post_id, '_dezrez_json_update_date_' . $this->import_id, $property['LastUpdated'] );

					do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
					do_action( "propertyhive_property_imported_dezrez_json", $post_id, $property, $this->import_id );

					$post = get_post( $post_id );
					do_action( "save_post_property", $post_id, $post, false );
					do_action( "save_post", $post_id, $post, false );

					if ( $inserted_updated == 'updated' )
					{
						$this->compare_meta_and_taxonomy_data( $post_id, $property['RoleId'], $metadata_before, $taxonomy_terms_before );
					}
				}

				++$property_row;
			}

		} // end foreach property

		do_action( "propertyhive_post_import_properties_dezrez_json" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['RoleId'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Reduced' => 'Reduced',
                'OnMarket' => 'OnMarket',
                'UnderOffer' => 'UnderOffer',
                'OfferAccepted' => 'OfferAccepted',
            ),
            'lettings_availability' => array(
                'Reduced' => 'Reduced',
                'OnMarket' => 'OnMarket',
                'UnderOffer' => 'UnderOffer',
                'OfferAccepted' => 'OfferAccepted',
            ),
            'commercial_availability' => array(
                'Reduced' => 'Reduced',
                'OnMarket' => 'OnMarket',
                'UnderOffer' => 'UnderOffer',
                'OfferAccepted' => 'OfferAccepted',
            ),
            'property_type' => array(
                'TerracedHouse' => 'TerracedHouse',
                'EndTerraceHouse' => 'EndTerraceHouse',
                'MidTerraceHouse' => 'MidTerraceHouse',
                'SemiDetachedHouse' => 'SemiDetachedHouse',
                'DetachedHouse' => 'DetachedHouse',
                'RemoteDetachedHouse' => 'RemoteDetachedHouse',
                'EndLinkHouse' => 'EndLinkHouse',
                'MidLinkHouse' => 'MidLinkHouse',
                'Flat' => 'Flat',
                'Apartment' => 'Apartment',
                'TerracedBungalow' => 'TerracedBungalow',
                'EndTerraceBungalow' => 'EndTerraceBungalow',
                'MidTerraceBungalow' => 'MidTerraceBungalow',
                'SemiDetachedBungalow' => 'SemiDetachedBungalow',
                'DetachedBungalow' => 'DetachedBungalow',
                'RemoteDetachedBungalow' => 'RemoteDetachedBungalow',
                'EndLinkBungalow' => 'EndLinkBungalow',
                'MidLinkBungalow' => 'MidLinkBungalow',
                'Cottage' => 'Cottage',
                'TerracedCottage' => 'TerracedCottage',
                'EndTerraceCottage' => 'EndTerraceCottage',
                'MidTerraceCottage' => 'MidTerraceCottage',
                'SemiDetachedCottage' => 'SemiDetachedCottage',
                'DetachedCottage' => 'DetachedCottage',
                'RemoteDetachedCottage' => 'RemoteDetachedCottage',
                'TerracedTownHouse' => 'TerracedTownHouse',
                'EndTerraceTownHouse' => 'EndTerraceTownHouse',
                'MidTerraceTownHouse' => 'MidTerraceTownHouse',
                'SemiDetachedTownHouse' => 'SemiDetachedTownHouse',
                'DetachedTownHouse' => 'DetachedTownHouse',
                'DetachedCountryHouse' => 'DetachedCountryHouse',
                'NorthWingCountryHouse' => 'NorthWingCountryHouse',
                'SouthWingCountryHouse' => 'SouthWingCountryHouse',
                'EastWingCountryHouse' => 'EastWingCountryHouse',
                'WestWingCountryHouse' => 'WestWingCountryHouse',
                'TerracedChalet' => 'TerracedChalet',
                'EndTerraceChalet' => 'EndTerraceChalet',
                'MidTerraceChalet' => 'MidTerraceChalet',
                'SemiDetachedChalet' => 'SemiDetachedChalet',
                'DetachedChalet' => 'DetachedChalet',
                'DetachedBarnConversion' => 'DetachedBarnConversion',
                'RemoteDetachedBarnConversion' => 'RemoteDetachedBarnConversion',
                'MewsStyleBarnConversion' => 'MewsStyleBarnConversion',
                'GroundFloorPurposeBuiltFlat' => 'GroundFloorPurposeBuiltFlat',
                'FirstFloorPurposeBuiltFlat' => 'FirstFloorPurposeBuiltFlat',
                'GroundFloorConvertedFlat' => 'GroundFloorConvertedFlat',
                'FirstFloorConvertedFlat' => 'FirstFloorConvertedFlat',
                'SecondAndFloorConvertedFlat' => 'SecondAndFloorConvertedFlat',
                'GroundAndFirstFloorMaisonette' => 'GroundAndFirstFloorMaisonette',
                'FirstandSecondFloorMaisonette' => 'FirstandSecondFloorMaisonette',
                'PenthouseApartment' => 'PenthouseApartment',
                'DuplexApartment' => 'DuplexApartment',
                'Mansion' => 'Mansion',
                'QType' => 'QType',
                'TType' => 'TType',
                'Cluster' => 'Cluster',
                'BuildingPlot' => 'BuildingPlot',
                'ApartmentLowDensity' => 'ApartmentLowDensity',
                'ApartmentStudio' => 'ApartmentStudio',
                'Business' => 'Business',
                'CornerTownhouse' => 'CornerTownhouse',
                'VillaDetached' => 'VillaDetached',
                'VillaLinkdetached' => 'VillaLinkdetached',
                'VillaSemidetached' => 'VillaSemidetached',
                'VillageHouse' => 'VillageHouse',
                'LinkDetached' => 'LinkDetached',
                'Studio' => 'Studio',
                'Maisonette' => 'Maisonette',
                'Shell' => 'Shell',
                'Commercial' => 'Commercial',
                'RetirementFlat' => 'RetirementFlat',
                'Bedsit' => 'Bedsit',
                'ParkHome' => 'ParkHome',
                'ParkHomeMobileHome' => 'ParkHomeMobileHome',
                'CommercialLand' => 'CommercialLand',
                'Land' => 'Land',
                'FarmLand' => 'FarmLand',
            ),
            'commercial_property_type' => array(
                'Commercial' => 'Commercial',
                'CommercialLand' => 'CommercialLand',
            ),
            'price_qualifier' => array(
                'NotSpecified' => 'NotSpecified',
                'PriceOnApplication' => 'PriceOnApplication',
                'GuidePrice' => 'GuidePrice',
                'FixedPrice' => 'FixedPrice',
                'OffersInExcessOf' => 'OffersInExcessOf',
                'OffersInRegionOf' => 'OffersInRegionOf',
                'SaleByTender' => 'SaleByTender',
                'From' => 'From',
                'SharedOwnership' => 'SharedOwnership',
                'OffersOver' => 'OffersOver',
                'PartBuyPartRent' => 'PartBuyPartRent',
                'SharedEquity' => 'SharedEquity',
            ),
            'tenure' => array(
                'Leasehold' => 'Leasehold',
                'Freehold' => 'Freehold',
                'NotApplicable' => 'NotApplicable',
                'FreeholdToBeConfirmed' => 'FreeholdToBeConfirmed',
                'LeaseholdToBeConfirmed' => 'LeaseholdToBeConfirmed',
                'ToBeAdvised' => 'ToBeAdvised',
                'ShareofLeasehold' => 'ShareofLeasehold',
                'ShareofFreehold' => 'ShareofFreehold',
                'FlyingFreehold' => 'FlyingFreehold',
                'LeaseholdShareofFreehold' => 'LeaseholdShareofFreehold',
            ),
            'furnished' => array(
                
            ),
        );
	}
}

}