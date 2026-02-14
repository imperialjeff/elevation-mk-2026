<?php
/**
 * Class for managing the import process of a LetMC JSON file
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_AgentOS_JSON_Import extends PH_Property_Import_Process {

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

	    add_filter( 'propertyhive_property_import_validate_epc_links_to_file', array( $this, 'validate_epc_links_to_file' ), 10, 2 );
	}

	public function validate_epc_links_to_file($validate, $import_id)
	{
		return false;
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

		$requests = 0;
		$requests_per_chunk = apply_filters( 'propertyhive_let_mc_requests_per_chunk', 5 );
		$sleep_seconds = apply_filters( 'propertyhive_let_mc_sleep_seconds', 6 );

		$limit = $this->get_property_limit();

		$branches_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/company/branches/0/1000';
		$fields = array(
			'api_key' => urlencode($import_settings['api_key']),
		);

		$fields_string = '';
		foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
		$fields_string = rtrim($fields_string, '&');

		$branches_url = $branches_url . '?' . $fields_string;

		$response = wp_remote_get( $branches_url, array( 'timeout' => 120 ) );

		if ( wp_remote_retrieve_response_code($response) !== 200 )
        {
            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
            return false;
        }

		++$requests;
		if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

		if ( is_array($response) && isset($response['body']) ) 
		{
			$branches_json = json_decode($response['body'], TRUE);

			if ( $branches_json === FALSE || is_null($branches_json) )
			{
				$this->log_error("Failed to parse branches JSON: " . print_r($response['body'], true));
				return false;
			}

			$branches = $branches_json['Data'];

			$this->log("Found " . count($branches) . " branches");

			foreach ( $branches as $branch )
			{
				$this->log("Obtaining properties for branch " . $branch['Name'] . " (" . $branch['OID'] . ")");
				
				// Sales Properties
				$sales_instructions = array();
				$sales_instructions_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/sales/advertised/0/1000';
				$fields = array(
					'api_key' => urlencode($import_settings['api_key']),
					'branchID' => $branch['OID'],
					'onlyDevelopement' => 'false',
					'onlyInvestements' => 'false',
				);

				$fields_string = '';
				foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
				$fields_string = rtrim($fields_string, '&');

				$sales_instructions_url = $sales_instructions_url . '?' . $fields_string;

				$response = wp_remote_get( $sales_instructions_url, array( 'timeout' => 120 ) );

				if ( wp_remote_retrieve_response_code($response) !== 200 )
		        {
		            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
		            return false;
		        }

				++$requests;
				if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

				if ( is_array($response) && isset($response['body']) ) 
				{
					$sales_instructions_json = json_decode($response['body'], TRUE);

					if ( $sales_instructions_json === FALSE || is_null($sales_instructions_json) )
					{
						$this->log_error("Failed to parse sales properties summary JSON: " . $response['body']);
						return false;
					}
					else
					{
						$sales_instructions = $sales_instructions_json['Data'];

						$this->log("Found " . count($sales_instructions) . " sales instructions");

						foreach ( $sales_instructions as $property )
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    return true;
			                }

			                if ( $test === false )
			                {
								// Get sales instruction data
								$property_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/sales/salesinstructions/' . $property['OID'];
								$fields = array(
									'api_key' => urlencode($import_settings['api_key']),
								);

								$fields_string = '';
								foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
								$fields_string = rtrim($fields_string, '&');

								$property_url = $property_url . '?' . $fields_string;

								$response = wp_remote_get( $property_url, array( 'timeout' => 120 ) );

								if ( wp_remote_retrieve_response_code($response) !== 200 )
						        {
						            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
						            return false;
						        }

								++$requests;
								if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

								if ( is_array($response) && isset($response['body']) ) 
								{
									$property_json = json_decode($response['body'], TRUE);

									if ( $property_json === FALSE || is_null($property_json) )
									{
										$this->log_error("Failed to parse full sales data JSON: " . $response['body'], 0, $property['OID']);
										return false;
									}
									else
									{
										$property = array_merge($property, $property_json);
										//$property['State'] = $property_json['State'];

										$property['department'] = 'residential-sales';
									}
								}
								else
								{
									$this->log_error("Failed to obtain full sales JSON: " . print_r($response, TRUE), 0, $property['OID']);
									return false;
								}

								// Get features
								$features = array();

								$property_features_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/sales/salesinstructions/' . $property['OID'] . '/features/0/1000';
								$fields = array(
									'api_key' => urlencode($import_settings['api_key']),
								);

								$fields_string = '';
								foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
								$fields_string = rtrim($fields_string, '&');

								$property_features_url = $property_features_url . '?' . $fields_string;

								$response = wp_remote_get( $property_features_url, array( 'timeout' => 120 ) );

								if ( wp_remote_retrieve_response_code($response) !== 200 )
						        {
						            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
						            return false;
						        }

								++$requests;
								if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

								if ( is_array($response) && isset($response['body']) ) 
								{
									$property_features_json = json_decode($response['body'], TRUE);

									if ( $property_features_json === FALSE || is_null($property_features_json) )
									{
										$this->log_error("Failed to parse property features JSON: " . $response['body'], 0, $property['OID']);
										return false;
									}
									else
									{
										$property_features = $property_features_json['Data'];
										
										foreach ( $property_features as $property_feature )
										{
											$features[] = $property_feature['Name'];
										}
									}
								}
								else
								{
									$this->log_error("Failed to obtain property features JSON: " . print_r($response, TRUE), 0, $property['OID']);
									return false;
								}

								// Get floorplans
								$floorplans = array();
								if ( get_option('propertyhive_floorplans_stored_as', '') != 'urls' )
				    			{
									$property_floorplans_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/sales/salesinstructions/' . $property['OID'] . '/floorplans/0/1000';
									$fields = array(
										'api_key' => urlencode($import_settings['api_key']),
									);

									$fields_string = '';
									foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
									$fields_string = rtrim($fields_string, '&');

									$property_floorplans_url = $property_floorplans_url . '?' . $fields_string;

									$response = wp_remote_get( $property_floorplans_url, array( 'timeout' => 120 ) );

									if ( wp_remote_retrieve_response_code($response) !== 200 )
							        {
							            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
							            return false;
							        }

									++$requests;
									if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

									if ( is_array($response) && isset($response['body']) ) 
									{
										$property_floorplans_json = json_decode($response['body'], TRUE);

										if ( $property_floorplans_json === FALSE || is_null($property_floorplans_json) )
										{
											$this->log_error("Failed to parse property floorplans JSON: " . $response['body'], 0, $property['OID']);
											return false;
										}
										else
										{
											$property_floorplans = $property_floorplans_json['Data'];

											foreach ( $property_floorplans as $property_floorplan )
											{
												$floorplans[] = $property_floorplan;
											}
										}
									}
									else
									{
										$this->log_error("Failed to obtain property floorplans JSON: " . print_r($response, TRUE), 0, $property['OID']);
										return false;
									}
								}

								// Get photos
								$photos = array();
								if ( get_option('propertyhive_images_stored_as', '') != 'urls' )
				    			{
									$property_photos_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/sales/salesinstructions/' . $property['OID'] . '/photos/0/1000';
									$fields = array(
										'api_key' => urlencode($import_settings['api_key']),
									);

									$fields_string = '';
									foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
									$fields_string = rtrim($fields_string, '&');

									$property_photos_url = $property_photos_url . '?' . $fields_string;

									$response = wp_remote_get( $property_photos_url, array( 'timeout' => 120 ) );

									if ( wp_remote_retrieve_response_code($response) !== 200 )
							        {
							            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
							            return false;
							        }

									++$requests;
									if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

									if ( is_array($response) && isset($response['body']) ) 
									{
										$property_photos_json = json_decode($response['body'], TRUE);

										if ( $property_photos_json === FALSE || is_null($property_photos_json) )
										{
											$this->log_error("Failed to parse property photos JSON: " . $response['body'], 0, $property['OID']);
											return false;
										}
										else
										{
											$property_photos = $property_photos_json['Data'];

											foreach ( $property_photos as $property_photo )
											{
												$photos[] = $property_photo;
											}
										}
									}
									else
									{
										$this->log_error("Failed to obtain property photos JSON: " . print_r($response, TRUE), 0, $property['OID']);
										return false;
									}
								}

								// Get rooms
								$rooms = array();

								$property_rooms_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/sales/salesinstructions/' . $property['OID'] . '/rooms/0/1000';
								$fields = array(
									'api_key' => urlencode($import_settings['api_key']),
								);

								$fields_string = '';
								foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
								$fields_string = rtrim($fields_string, '&');

								$property_rooms_url = $property_rooms_url . '?' . $fields_string;

								$response = wp_remote_get( $property_rooms_url, array( 'timeout' => 120 ) );

								if ( wp_remote_retrieve_response_code($response) !== 200 )
						        {
						            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
						            return false;
						        }

								++$requests;
								if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

								if ( is_array($response) && isset($response['body']) ) 
								{
									$property_rooms_json = json_decode($response['body'], TRUE);

									if ( $property_rooms_json === FALSE || is_null($property_rooms_json) )
									{
										$this->log_error("Failed to parse property rooms JSON: " . $response['body'], 0, $property['OID']);
										return false;
									}
									else
									{
										$property_rooms = $property_rooms_json['Data'];

										foreach ( $property_rooms as $property_room )
										{
											$rooms[] = $property_room;
										}
									}
								}
								else
								{
									$this->log_error("Failed to obtain property rooms JSON: " . print_r($response, TRUE), 0, $property['OID']);
									return false;
								}

								$property['features'] = $features;
								$property['floorplans'] = $floorplans;
								$property['photos'] = $photos;
								$property['rooms'] = $rooms;

								if (!isset($property['BranchOID'])) { $property['BranchOID'] = $branch['OID']; }
							}

							$this->properties[] = $property;
						}
					}
				}
				else
				{
					$this->log_error("Failed to obtain sales properties summary JSON: " . print_r($response, TRUE));
					return false;
				}

				// Lettings Properties
				$lettings_instructions_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/lettings/advertised/0/1000';
				$fields = array(
					'api_key' => urlencode($import_settings['api_key']),
					'branchID' => $branch['OID'],
				);

				$fields_string = '';
				foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
				$fields_string = rtrim($fields_string, '&');

				$lettings_instructions_url = $lettings_instructions_url . '?' . $fields_string;

				$response = wp_remote_get( $lettings_instructions_url, array( 'timeout' => 120 ) );

				if ( wp_remote_retrieve_response_code($response) !== 200 )
		        {
		            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
		            return false;
		        }

				++$requests;
				if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

				if ( is_array($response) && isset($response['body']) ) 
				{
					$lettings_instructions_json = json_decode($response['body'], TRUE);

					if ( $lettings_instructions_json === FALSE || is_null($lettings_instructions_json) )
					{
						$this->log_error("Failed to parse lettings properties summary JSON: " . print_r($response['body'], true));
						return false;
					}
					else
					{
						$lettings_instructions = $lettings_instructions_json['Data'];

						$this->log("Found " . count($lettings_instructions) . " lettings properties");

						foreach ( $lettings_instructions as $property )
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    return true;
			                }

			                if ( $test === false )
			                {
								// Get full lettings data
								$property_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/lettings/advertised/' . $property['OID'];
								$fields = array(
									'api_key' => urlencode($import_settings['api_key']),
								);

								$fields_string = '';
								foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
								$fields_string = rtrim($fields_string, '&');

								$property_url = $property_url . '?' . $fields_string;

								$response = wp_remote_get( $property_url, array( 'timeout' => 120 ) );

								if ( wp_remote_retrieve_response_code($response) !== 200 )
						        {
						            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
						            return false;
						        }

								++$requests;
								if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

								if ( is_array($response) && isset($response['body']) ) 
								{
									$property_json = json_decode($response['body'], TRUE);

									if ( $property_json === FALSE || is_null($property_json) )
									{
										$this->log_error("Failed to parse full lettings data JSON: " . $response['body'], 0, $property['PropertyID']);
										return false;
									}
									else
									{
										$property = array_merge($property, $property_json);

										$property['department'] = 'residential-lettings';
									}
								}
								else
								{
									$this->log_error("Failed to obtain full lettings JSON: " . print_r($response, TRUE), 0, $property['PropertyID']);
									return false;
								}

								// Get full property data
								$original_id = $property['OID'];
								$property_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/lettings/properties/' . $property['PropertyID'];
								$fields = array(
									'api_key' => urlencode($import_settings['api_key']),
								);

								$fields_string = '';
								foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
								$fields_string = rtrim($fields_string, '&');

								$property_url = $property_url . '?' . $fields_string;

								$response = wp_remote_get( $property_url, array( 'timeout' => 120 ) );

								if ( wp_remote_retrieve_response_code($response) !== 200 )
						        {
						            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
						            return false;
						        }

								++$requests;
								if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

								if ( is_array($response) && isset($response['body']) ) 
								{
									$property_json = json_decode($response['body'], TRUE);

									if ( $property_json === FALSE || is_null($property_json) )
									{
										$this->log_error("Failed to parse full property JSON: " . $response['body'], 0, $property['PropertyID']);
										return false;
									}
									else
									{
										$property = array_merge($property, $property_json);
										$property['OID'] = $original_id;
									}
								}
								else
								{
									$this->log_error("Failed to obtain full property JSON: " . print_r($response, TRUE), 0, $property['PropertyID']);
									return false;
								}

								// Get features
								$features = array();

								$property_features_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/lettings/properties/' . $property['PropertyID'] . '/facilities/0/1000';
								$fields = array(
									'api_key' => urlencode($import_settings['api_key']),
								);

								$fields_string = '';
								foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
								$fields_string = rtrim($fields_string, '&');

								$property_features_url = $property_features_url . '?' . $fields_string;

								$response = wp_remote_get( $property_features_url, array( 'timeout' => 120 ) );

								if ( wp_remote_retrieve_response_code($response) !== 200 )
						        {
						            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
						            return false;
						        }

								++$requests;
								if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

								if ( is_array($response) && isset($response['body']) ) 
								{
									$property_features_json = json_decode($response['body'], TRUE);

									if ( $property_features_json === FALSE || is_null($property_features_json) )
									{
										$this->log_error("Failed to parse property features JSON: " . $response['body'], 0, $property['PropertyID']);
										return false;
									}
									else
									{
										$property_features = $property_features_json['Data'];

										foreach ( $property_features as $property_feature )
										{
											$features[] = $property_feature['Name'];
										}
									}
								}
								else
								{
									$this->log_error("Failed to obtain property features JSON: " . print_r($response, TRUE), 0, $property['PropertyID']);
									return false;
								}

								// Get photos
								$photos = array();
								if ( get_option('propertyhive_images_stored_as', '') != 'urls' )
				    			{
									$property_photos_url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/lettings/properties/' . $property['PropertyID'] . '/photos/0/1000';
									$fields = array(
										'api_key' => urlencode($import_settings['api_key']),
									);

									$fields_string = '';
									foreach ($fields as $key => $value) { $fields_string .= $key . '=' . $value . '&'; }
									$fields_string = rtrim($fields_string, '&');

									$property_photos_url = $property_photos_url . '?' . $fields_string;

									$response = wp_remote_get( $property_photos_url, array( 'timeout' => 120 ) );

									if ( wp_remote_retrieve_response_code($response) !== 200 )
							        {
							            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting branches from ' . $branches_url . '. Error message: ' . wp_remote_retrieve_response_message($response) );
							            return false;
							        }

									++$requests;
									if ( $requests >= $requests_per_chunk ) { sleep($sleep_seconds); $requests = 0; }

									if ( is_array($response) && isset($response['body']) ) 
									{
										$property_photos_json = json_decode($response['body'], TRUE);

										if ( $property_photos_json === FALSE || is_null($property_photos_json) )
										{
											$this->log_error("Failed to parse property photos JSON: " . $response['body'], 0, $property['PropertyID']);
											return false;
										}
										else
										{
											$property_photos = $property_photos_json['Data'];

											foreach ( $property_photos as $property_photo )
											{
												$photos[] = $property_photo;
											}
										}
									}
									else
									{
										$this->log_error("Failed to obtain property photos JSON: " . print_r($response, TRUE), 0, $property['PropertyID']);
										return false;
									}
								}

								$property['features'] = $features;
								$property['photos'] = $photos;

								if (!isset($property['BranchOID'])) { $property['BranchOID'] = $branch['OID']; }
							}

							$this->properties[] = $property;
						}
					}
				}
				else
				{
					$this->log_error("Failed to obtain lettings properties summary JSON: " . print_r($response, TRUE));
					return false;
				}
			}
		}
		else
		{
			$this->log_error("Failed to obtain branches JSON: " . print_r($response, TRUE));
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

        $requests = 0;
        $requests_per_chunk = apply_filters( 'propertyhive_let_mc_requests_per_chunk', 5 );
		$sleep_seconds = apply_filters( 'propertyhive_let_mc_sleep_seconds', 6 );

        $geocoding_denied = false;

        do_action( "propertyhive_pre_import_properties_agentos_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_agentos_json_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_agentos_json", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['OID'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['OID'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['OID'], false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['OID'], 0, $property['OID'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = array();
	        if ( isset($property['Address1']) && trim($property['Address1']) != '' )
	        {
	        	$display_address[] = trim($property['Address1']);
	        }
	        if ( isset($property['Address2']) && trim($property['Address2']) != '' )
	        {
	        	$display_address[] = trim($property['Address2']);
	        }
	        if ( isset($property['Address3']) && trim($property['Address3']) != '' )
	        {
	        	$display_address[] = trim($property['Address3']);
	        }
	        $display_address = implode(", ", $display_address);

	        $summary_description = isset($property['Summary']) ? $property['Summary'] : '';
	        if ( empty($summary_description) )
	        {
		        $summary_description = substr( strip_tags($property['Description']), 0, 300 );
		        if ( strlen(strip_tags($property['Description'])) > 300 )
		        {
		        	$summary_description .= '...';
		        }
		    }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['OID'], $property, $display_address, $summary_description );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['OID'] );

				update_post_meta( $post_id, $imported_ref_key, $property['OID'] );

				update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

				// Address
				$reference_number = $property['GlobalReference'];
				if ( empty($reference_number) )
				{
					$reference_number = $property['OID'];
				}
				update_post_meta( $post_id, '_reference_number', $reference_number );
				update_post_meta( $post_id, '_address_name_number', ( ( isset($property['AddressNumber']) ) ? $property['AddressNumber'] : '' ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property['Address1']) ) ? $property['Address1'] : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property['Address2']) ) ? $property['Address2'] : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property['Address3']) ) ? $property['Address3'] : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property['Address4']) ) ? $property['Address4'] : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property['Postcode']) ) ? $property['Postcode'] : '' ) );

				$country = get_option( 'propertyhive_default_country', 'GB' );
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = apply_filters( 'propertyhive_agentos_json_address_fields_to_check', array('Address2', 'Address3', 'Address4') );
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
				$lat = get_post_meta( $post_id, '_latitude', TRUE);
				$lng = get_post_meta( $post_id, '_longitude', TRUE);

				if ( !$geocoding_denied && ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' ) )
				{
					// No lat lng. Let's get it
					$address_to_geocode = array();
					$address_to_geocode_osm = array();
					if ( isset($property['AddressNumber']) && $property['AddressNumber'] != '' ) { $address_to_geocode[] = $property['AddressNumber']; }
					if ( isset($property['Address1']) && $property['Address1'] != '' ) { $address_to_geocode[] = $property['Address1']; }
					if ( isset($property['Address2']) && $property['Address2'] != '' ) { $address_to_geocode[] = $property['Address2']; }
					if ( isset($property['Address3']) && $property['Address3'] != '' ) { $address_to_geocode[] = $property['Address3']; }
					if ( isset($property['Address4']) && $property['Address4'] != '' ) { $address_to_geocode[] = $property['Address4']; }
					if ( isset($property['Postcode']) && $property['Postcode'] != '' ) { $address_to_geocode[] = $property['Postcode']; $address_to_geocode_osm[] = $property['Postcode']; }

					$return = $this->do_geocoding_lookup( $post_id, $property['OID'], $address_to_geocode, $address_to_geocode_osm, $country );
					if ( $return === 'denied' )
					{
						$geocoding_denied = true;
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
						if ( $branch_code == $property['BranchOID'] )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$commercial_property_types = apply_filters( 'propertyhive_letmc_json_commercial_property_types', array('CommercialProperty') );
				if (
					!empty($commercial_property_types) &&
					isset($property['PropertyType']) &&
					in_array($property['PropertyType'], $commercial_property_types) &&
					get_option( 'propertyhive_active_departments_commercial' ) == 'yes'
				)
				{
					$original_department = $property['department'];
					$property['department'] = 'commercial';
				}

				update_post_meta( $post_id, '_department', $property['department'] );

				$bedrooms = isset($property['Bedrooms']) ? $property['Bedrooms'] : ( isset($property['BedroomCount']) ? $property['BedroomCount'] : '' );
				update_post_meta( $post_id, '_bedrooms', $bedrooms );
				$bathrooms = isset($property['Bathrooms']) ? $property['Bathrooms'] : ( isset($property['BathroomCount']) ? $property['BathroomCount'] : '' );
				update_post_meta( $post_id, '_bathrooms', $bathrooms );
				$reception_rooms = isset($property['ReceptionRooms']) ? $property['ReceptionRooms'] : ( isset($property['ReceptionCount']) ? $property['ReceptionCount'] : '' );
				update_post_meta( $post_id, '_reception_rooms', $reception_rooms );

				update_post_meta( $post_id, '_council_tax_band', ( isset($property['CouncilTaxBand']) ? $property['CouncilTaxBand'] : '' ) );
	
				// Property Type
				$mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();
				
				if ( isset($property['PropertyType']) && $property['PropertyType'] != '' )
				{
					if ( !empty($mapping) && isset($mapping[$property['PropertyType']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['PropertyType']], 'property_type' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'property_type' );

		            	$this->log( 'Property received with a type (' . $property['PropertyType'] . ') that is not mapped', $post_id, $property['OID'] );

		            	$import_settings = $this->add_missing_mapping( $mapping, 'property_type', $property['PropertyType'], $post_id );
		            }
		        }
		        elseif ( isset($property['PropertyOwnableType']) && $property['PropertyOwnableType'] != '' )
				{
					if ( !empty($mapping) && isset($mapping[$property['PropertyOwnableType']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['PropertyOwnableType']], 'property_type' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'property_type' );

		            	$this->log( 'Property received with a type (' . $property['PropertyOwnableType'] . ') that is not mapped', $post_id, $property['OID'] );

		            	$import_settings = $this->add_missing_mapping( $mapping, 'property_type', $property['PropertyOwnableType'], $post_id );
		            }
		        }
		        else
		        {
		        	wp_delete_object_term_relationships( $post_id, 'property_type' );
		        }

				// Residential Sales Details
				if ( $property['department'] == 'residential-sales' )
				{
					$price = round(preg_replace("/[^0-9.]/", '', $property['Price']));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );

					update_post_meta( $post_id, '_poa', ( ( isset($property['POA']) && $property['POA'] === true ) ? 'yes' : '' ) );

		            // Tenure
		            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();
					
					if ( !empty($mapping) && isset($property['Tenure']) && isset($mapping[$property['Tenure']]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[$property['Tenure']], 'tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'tenure' );
		            }

		            if ( isset($property['TenureDetails']['Tenure']) && strtolower($property['TenureDetails']['Tenure']) == 'leasehold' )
		            {
		            	// Leasehold
		            	if ( isset($property['TenureDetails']['SharePercent']) && !empty($property['TenureDetails']['SharePercent']) )
		            	{
		            		update_post_meta( $post_id, '_shared_ownership', 'yes' );
		            		update_post_meta( $post_id, '_shared_ownership_percentage', $property['TenureDetails']['SharePercent'] );
		            	}
		            	else
		            	{
		            		update_post_meta( $post_id, '_shared_ownership', '' );
		            	}
		            	if ( isset($property['TenureDetails']['GroundRent']) && !empty($property['TenureDetails']['GroundRent']) ) { update_post_meta( $post_id, '_ground_rent', $property['TenureDetails']['GroundRent'] ); }
		            	if ( isset($property['TenureDetails']['GroundRentReviewPeriod']) && !empty($property['TenureDetails']['GroundRentReviewPeriod']) ) { update_post_meta( $post_id, '_ground_rent_review_years', $property['TenureDetails']['GroundRentReviewPeriod'] ); }
		            	if ( isset($property['TenureDetails']['ServiceCharge']) && !empty($property['TenureDetails']['ServiceCharge']) ) { update_post_meta( $post_id, '_service_charge', $property['TenureDetails']['ServiceCharge'] ); }
		            	if ( isset($property['TenureDetails']['LeaseRemaining']) && !empty($property['TenureDetails']['LeaseRemaining']) ) { update_post_meta( $post_id, '_leasehold_years_remaining', $property['TenureDetails']['LeaseRemaining'] ); }
		            }

		            // Sale By
		            $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();
					
					if ( !empty($mapping) && isset($property['SalesBy']) && isset($mapping[$property['SalesBy']]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[$property['SalesBy']], 'sale_by' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'sale_by' );
		            }
				}
				elseif ( $property['department'] == 'residential-lettings' )
				{
					$price = round(preg_replace("/[^0-9.]/", '', $property['RentAdvertised']));

					$price_actual = $price;
					$rent_frequency = 'pcm';
					switch ($property['RentSchedule'])
					{
						case "Weekly": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
						case "Monthly": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						case "Quarterly": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
						case "Yearly": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
					}

					update_post_meta( $post_id, '_rent', $price );
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );

					$deposit = round(preg_replace("/[^0-9.]/", '', $property['BondRequired']));
					update_post_meta( $post_id, '_deposit', $deposit );
            		update_post_meta( $post_id, '_available_date', ( isset($property['TermStart']) && $property['TermStart'] != '' ) ? date("Y-m-d", strtotime($property['TermStart'])) : '' );

            		// Furnished
		            $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

					if ( !empty($mapping) && isset($property['Furnished']) && isset($mapping[$property['Furnished']]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[$property['Furnished']], 'furnished' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'furnished' );
		            }
				}
				elseif ( $property['department'] == 'commercial' )
				{
					if ( $original_department == 'residential-sales' )
					{
						$price = round(preg_replace("/[^0-9.]/", '', $property['Price']));

						update_post_meta( $post_id, '_for_sale', 'yes' );

						update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

						update_post_meta( $post_id, '_price_from', $price );
						update_post_meta( $post_id, '_price_to', $price );

						update_post_meta( $post_id, '_price_units', '' );

						update_post_meta( $post_id, '_price_poa', ( ( isset($property['POA']) && $property['POA'] === true ) ? 'yes' : '' ) );
					}
					elseif ( $original_department == 'residential-lettings' )
					{
						$price = round(preg_replace("/[^0-9.]/", '', $property['RentAdvertised']));

						update_post_meta( $post_id, '_to_rent', 'yes' );

						update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

						update_post_meta( $post_id, '_rent_from', $price );
						update_post_meta( $post_id, '_rent_to', $price );

						$rent_frequency = 'pcm';
						if ( isset($property['RentSchedule']) )
						{
							switch ( (string)$property['RentSchedule'] )
							{
								case "Weekly": { $rent_frequency = 'pw'; break; }
								case "Monthly": { $rent_frequency = 'pcm'; break; }
								case "Quarterly": { $rent_frequency = 'pq'; break; }
								case "Yearly": { $rent_frequency = 'pa'; break; }
							}
						}
						update_post_meta( $post_id, '_rent_units', $rent_frequency );

						update_post_meta( $post_id, '_rent_poa', $poa );
					}

					// Store price in common currency (GBP) used for ordering
					$ph_countries = new PH_Countries();
					$ph_countries->update_property_price_actual( $post_id );

					update_post_meta( $post_id, '_floor_area_from', '' );
					update_post_meta( $post_id, '_floor_area_from_sqft', '' );
					update_post_meta( $post_id, '_floor_area_to', '' );
					update_post_meta( $post_id, '_floor_area_to_sqft', '' );
					update_post_meta( $post_id, '_floor_area_units', 'sqft');
				}

				$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', 'yes' );
                }

				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $property['department']) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $property['department']) . '_availability'] : 
					array();

				$availability = '';
				if ( $property['department'] == 'residential-sales' || ( $property['department'] == 'commercial' && $original_department == 'residential-sales' ) )
				{
					$availability = 'For Sale';
					if ( $property['State'] == 'UnderOffer' )
					{
						$availability = 'Under Offer';
					}
				}
				elseif ( $property['department'] == 'residential-lettings' || ( $property['department'] == 'commercial' && $original_department == 'residential-lettings' ) )
				{
					$availability = 'To Let';
					if ( $property['IsTenancyProposed'] === TRUE )
					{
						$availability = 'Let Agreed';
					}
				}

				if ( !empty($mapping) && isset($mapping[$availability]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[$availability], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

				// Features
				update_post_meta( $post_id, '_features', count( $property['features'] ) );
        		
        		$i = 0;
		        foreach ( $property['features'] as $feature )
		        {
		            update_post_meta( $post_id, '_feature_' . $i, $feature );
		            ++$i;
		        }

				if ( $property['department'] != 'commercial' )
				{
					// For now put the whole description in one room
					update_post_meta( $post_id, '_rooms', '1' );
					update_post_meta( $post_id, '_room_name_0', '' );
					update_post_meta( $post_id, '_room_dimensions_0', '' );

					// Attempt to solve an encoding issue. Set to blank first, insert, and if blank, utf8encode and insert again
					update_post_meta( $post_id, '_room_description_0', '' );
					update_post_meta( $post_id, '_room_description_0', $property['Description'] );
					if ( get_post_meta( $post_id, '_room_description_0', TRUE ) == '' )
					{
						update_post_meta( $post_id, '_room_description_0', utf8_encode($property['Description']) );
					}
				}
				else
				{
					update_post_meta( $post_id, '_descriptions', '1' );
					update_post_meta( $post_id, '_description_name_0', '' );

					// Attempt to solve an encoding issue. Set to blank first, insert, and if blank, utf8encode and insert again
					update_post_meta( $post_id, '_description_0', '' );
					update_post_meta( $post_id, '_description_0', $property['Description'] );
					if ( get_post_meta( $post_id, '_description_0', TRUE ) == '' )
					{
						update_post_meta( $post_id, '_description_0', utf8_encode($property['Description']) );
					}
				}

				// Media - Images
			    $media = array();
			    if ( get_option('propertyhive_images_stored_as', '') == 'urls' )
    			{
    				$this->log( 'The AgentOS API format can\'t support storing photos as URLs', $post_id, $property['OID'] );
    			}
    			else
    			{
				    if ( isset($property['photos']) && !empty($property['photos']) )
					{
						foreach ( $property['photos'] as $image )
						{
							if ( isset($image['PhotoType']) && strtolower($image['PhotoType']) == 'photo' )
							{
								$url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/download/' . $image['OID'] . '?api_key=' . urlencode($import_settings['api_key']);
								$etag = $image['ETag'];

								$media[] = array(
									'url' => $url,
									'compare_url' => $url . $etag,
									'filename' => $image['OID'] . '.jpg',
									'description' => $image['Name'],
								);
							}
						}
					}
				}
				$this->import_media( $post_id, $property['OID'], 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if ( get_option('propertyhive_floorplans_stored_as', '') == 'urls' )
    			{
    				$this->log( 'The AgentOS API format can\'t support storing floorplan as URLs', $post_id, $property['OID'] );
    			}
    			else
    			{
				    if ( isset($property['photos']) && !empty($property['photos']) )
					{
						foreach ( $property['photos'] as $image )
						{
							if ( isset($image['PhotoType']) && strtolower($image['PhotoType']) == 'floorplan' )
							{
								$url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/download/' . $image['OID'] . '?api_key=' . urlencode($import_settings['api_key']);
								$etag = $image['ETag'];

								$media[] = array(
									'url' => $url,
									'compare_url' => $url . $etag,
									'filename' => $image['OID'] . '.jpg',
									'description' => $image['Name'],
								);
							}
						}
					}
					if ( isset($property['floorplans']) && !empty($property['floorplans']) )
					{
						foreach ( $property['floorplans'] as $image )
						{
							if ( 
								isset($image['PhotoType']) && strtolower($image['PhotoType']) == 'floorplan'
							)
							{
								$url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/download/' . $image['OID'] . '?api_key=' . urlencode($import_settings['api_key']);
								$etag = $image['ETag'];

								$media[] = array(
									'url' => $url,
									'compare_url' => $url . $etag,
									'filename' => $image['OID'] . '.jpg',
									'description' => $image['Name'],
								);
							}
						}
					}
				}
				$this->import_media( $post_id, $property['OID'], 'floorplan', $media, false );

				// Media - EPCs
			    $media = array();
			    if ( get_option('propertyhive_epcs_stored_as', '') == 'urls' )
    			{
    				$this->log( 'The AgentOS API format can\'t support storing EPCs as URLs', $post_id, $property['OID'] );
    			}
    			else
    			{
				    if ( 
						isset($property['EPCCurrentEER']) && !empty($property['EPCCurrentEER']) &&
						isset($property['EPCPotentialEER']) && !empty($property['EPCPotentialEER'])
					)
					{
						$unique_id_to_use_for_epcs = $property['OID'];
						if ( $property['department'] == 'residential-lettings' || ( $property['department'] == 'commercial' && $original_department == 'residential-lettings' )  )
						{
							$unique_id_to_use_for_epcs = $property['PropertyID'];
						}
						
						$url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/download/' . $unique_id_to_use_for_epcs . '/epc/EnergyEfficiency?api_key=' . urlencode($import_settings['api_key']);

						$media[] = array(
							'url' => $url,
							'compare_url' => $url . $property['EPCCurrentEER'] . $property['EPCPotentialEER'],
							'filename' => $property['OID'] . '-eer.jpg',
							'description' => 'EnergyEfficiency',
						);
					}
				}
				$this->import_media( $post_id, $property['OID'], 'epc', $media, false );

				// Media - Brochures
			    $media = array();
			    if ( get_option('propertyhive_brochures_stored_as', '') == 'urls' )
    			{
    				$this->log( 'The AgentOS API format can\'t support storing brochures as URLs', $post_id, $property['OID'] );
    			}
    			else
    			{
					$url = 'https://live-api.letmc.com/v4/advertising/' . urlencode($import_settings['short_name']) . '/download/' . $property['OID'] . '/brochure?api_key=' . urlencode($import_settings['api_key']);

					$media[] = array(
						'url' => $url,
						'filename' => $property['OID'] . '-brochure.pdf',
						'description' => 'Brochure',
					);
				}
				$this->import_media( $post_id, $property['OID'], 'brochure', $media, false );

				if ( isset($property['VideoURL']) && trim($property['VideoURL']) != '' )
				{
					update_post_meta($post_id, '_virtual_tours', 1);
				    update_post_meta($post_id, '_virtual_tour_0', $property['VideoURL']);

				    $this->log( 'Imported 1 virtual tours', $post_id, $property['OID'] );
				}
				else
				{
					$this->log( 'Imported 0 virtual tours', $post_id, $property['OID'] );
				}

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_agentos_json", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['OID'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;
		} // end foreach property

		do_action( "propertyhive_post_import_properties_agentos_json" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['OID'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
            ),
            'commercial_availability' => array(
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
            ),
            'property_type' => array(
                'House' => 'House',
                'DetachedHouse' => 'DetachedHouse',
                'SemiDetachedHouse' => 'SemiDetachedHouse',
                'TerracedHouse' => 'TerracedHouse',
                'EndTerraceHouse' => 'EndTerraceHouse',
                'Cottage' => 'Cottage',
                'Bungalow' => 'Bungalow',
                'FlatApartment' => 'FlatApartment',
                'HouseFlatShare' => 'HouseFlatShare',
            ),
            'tenure' => array(
                'Leasehold' => 'Leasehold',
                'Freehold' => 'Freehold',
            ),
            'sale_by' => array(
                'PrivateTreaty' => 'PrivateTreaty',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Unfurnished' => 'Unfurnished',
            ),
        );
	}
}

}