<?php
/**
 * Class for managing the import process of an Arthur JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Arthur_JSON_Import extends PH_Property_Import_Process {

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

	public function get_access_token_from_authorization_code($code)
	{
		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $client_id = '';
        $client_secret = '';

        if ( $import_settings['format'] == 'json_arthur' )
        {
            $client_id = $import_settings['client_id'];
            $client_secret = $import_settings['client_secret'];
        }
        else
        {
        	die("Trying to work with a non-Arthur import");
        }

        // we got a code, now use this to get an access token
        $response = wp_remote_post( 
            'https://auth.arthuronline.co.uk/oauth/token', 
            array(
                'method' => 'POST',
                'headers' => array(),
                'body' => array( 
                    'grant_type' => 'authorization_code', 
                    'code' => $code,
                    'client_id' => $client_id, 
                    'client_secret' => $client_secret,
                    'redirect_uri' => admin_url('admin.php?page=propertyhive_import_properties&arthur_callback=1&import_id=' . $this->import_id),
                    'state' => uniqid(),
                ),
                'cookies' => array()
            )
        );

        if ( !is_wp_error( $response ) && isset($response['body']) ) 
        {
            $response = json_decode($response['body'], TRUE);

            if ( isset($response['access_token']) )
            {
                $import_settings['access_token'] = $response['access_token'];
                $import_settings['access_token_expires'] = time() + $response['expires_in'];
                $import_settings['refresh_token'] = $response['refresh_token'];

                $previous_options = get_option('propertyhive_property_import' );
                $previous_options[$this->import_id] = $import_settings;
                update_option('propertyhive_property_import', $previous_options );

                return true;
            }
            else 
            {
                die( 'No access token in response: ' . print_r($response, TRUE) );
            }
        } 
        else 
        {
            die( 'Something went wrong getting the access token: ' . print_r($response, TRUE) );
        }
      
        return false;
	}

	public function refresh_access_token( $test = false )
	{
		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

        if ( $import_settings['format'] != 'json_arthur' )
        {
        	die("Trying to work with a non-Arthur import");
        }

        // we got a code, now use this to get an access token
        $response = wp_remote_post( 
            'https://auth.arthuronline.co.uk/oauth/token', 
            array(
                'method' => 'POST',
                'headers' => array(),
                'body' => array( 
                    'grant_type' => 'refresh_token', 
                    'refresh_token' => $import_settings['refresh_token'],
                    'client_id' => $import_settings['client_id'],
                    'client_secret' => $import_settings['client_secret'],
                    'state' => uniqid(),
                ),
                'cookies' => array(),
            )
        );

        if ( !is_wp_error( $response ) && isset($response['body']) ) 
        {
            $response = json_decode($response['body'], TRUE);

            if ( isset($response['access_token']) )
            {
                $import_settings['access_token'] = $response['access_token'];
                $import_settings['access_token_expires'] = time() + $response['expires_in'];
                $import_settings['refresh_token'] = $response['refresh_token'];

                $previous_options = get_option('propertyhive_property_import' );
                $previous_options[$this->import_id] = $import_settings;
                update_option('propertyhive_property_import', $previous_options );

                return true;
            }
            else 
            {
                // It's possible the refresh token has expired. Try and get access token again from scratch
                $this->log_error( 'No access token in response. ' . print_r($response, TRUE) );
            }
        } 
        else 
        {
            $this->log_error( 'Something went wrong getting the access token from refresh token: ' . print_r($response->get_error_message(), TRUE) );
        }

        return false;
	}

	public function parse( $test = false )
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		$this->log("Parsing properties");

		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

    	// Check that access token hasn't expired...
    	if ( $import_settings['access_token_expires'] < time() )
    	{	
    		$this->log("Access token has expired. Trying to get a new one using refresh token");
    		$got_access_token = $this->refresh_access_token( $test );
    		if ( $got_access_token === false )
    		{
    			$this->log_error("Failed to get new access token");
    			return false;
    		}

    		$this->log("Got new access token");

    		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
    	}

    	$import_structure = isset($import_settings['import_structure']) ? $import_settings['import_structure'] : '';

    	$total_pages = 999;
    	$page = 1;

    	while ( $page <= $total_pages )
    	{
			$response = wp_remote_get( 
				apply_filters( 'propertyhive_arthur_properties_url', 'https://api.arthuronline.co.uk/v2/properties?unit_status=Available%20to%20Let,Under%20Offer&limit=100&page=' . $page ),
				array(
	                'headers' => array(
	                	'Authorization' => 'Bearer ' . $import_settings['access_token'],
	                	'X-EntityID' => $import_settings['entity_id']
	                ),
	                'body' => array(),
	                'cookies' => array(),
	                'timeout' => 360,
	            )
			);

			if ( !is_wp_error( $response ) && is_array( $response ) && isset($response['body']) ) 
			{
				$header = $response['headers']; // array of http header lines
				$body = $response['body']; // use the content
			
				$json = json_decode( $body, TRUE );

				if ($json !== FALSE && is_array($json['data']) && !empty($json['data']))
				{
					if ( $total_pages == 999 && isset($json['pagination']['pageCount']) )
					{
						$total_pages = $json['pagination']['pageCount'];
					}

					$this->log("Found " . count($json['data']) . " properties on page " . $page . " / " . $total_pages . " in JSON ready for parsing");

					foreach ($json['data'] as $property)
					{
						$property['units'] = array();
						$property['epcs'] = array();

						if ( $import_structure != 'units_only' )
						{
							// get certificates belonging to property
							$response = wp_remote_get( 
								'https://api.arthuronline.co.uk/v2/properties/' . $property['id'] . '/certificates',
								array(
					                'headers' => array(
					                	'Authorization' => 'Bearer ' . $import_settings['access_token'],
					                	'X-EntityID' => $import_settings['entity_id']
					                ),
					                'body' => array(),
					                'cookies' => array(),
					                'timeout' => 360,
					            )
							);

							if ( !is_wp_error( $response ) && is_array( $response ) && isset($response['body']) ) 
							{
								$header = $response['headers']; // array of http header lines
								$body = $response['body']; // use the content
							
								$certificate_json = json_decode( $body, TRUE );

								if ($certificate_json !== FALSE)
								{
									if ( is_array($certificate_json['data']) && !empty($certificate_json['data']) )
									{
										foreach ( $certificate_json['data'] as $certificate )
										{
											if ( isset($certificate['type']) && strpos(strtolower($certificate['type']), 'epc') !== FALSE )
											{
												// Yes this is an EPC
												if ( 
													isset($certificate['file']) && 
													isset($certificate['file']['download_url'])
												)
												{
													$property['epcs'][] = array(
														'url' => $certificate['file']['download_url'],
														'modified' => $certificate['modified'],
														'extension' => ( strpos($certificate['mime_type'], 'pdf') !== FALSE ? 'pdf' : '' )
													);
												}
											}
										}
									}
								}
						        else
						        {
						        	// Failed to parse JSON
						        	$this->log_error( 'Failed to parse certificate JSON:' . print_r($body, TRUE) );
						        	return false;
						        }
					        }
					        else
					        {
					        	// Request failed
					        	$this->log_error( 'Certificate request failed. Response: ' . print_r($response, TRUE) );
					        	return false;
					        }
					    }

						$this->properties[$property['id']] = $property;
					}
		        }
		        else
		        {
		        	// Failed to parse JSON
		        	$this->log_error( 'Failed to parse JSON:' . print_r($body, TRUE) );
		        	return false;
		        }
	        }
	        else
	        {
	        	// Request failed
	        	$this->log_error( 'Request failed. Response: ' . print_r($response, TRUE) );
	        	return false;
	        }

	        ++$page;
	    }

	    if ( 
			class_exists( 'PH_Rooms' ) ||
			( !class_exists( 'PH_Rooms' ) && in_array($import_structure, array('no_children', 'units_only')) )
		)
		{
			$total_pages = 999;
    		$page = 1;

    		while ( $page <= $total_pages )
    		{
				$response = wp_remote_get( 
					apply_filters( 'propertyhive_arthur_units_url', 'https://api.arthuronline.co.uk/v2/units?unit_status=Available%20to%20Let,Under%20Offer&limit=100&page=' . $page ),
					array(
		                'headers' => array(
		                	'Authorization' => 'Bearer ' . $import_settings['access_token'],
		                	'X-EntityID' => $import_settings['entity_id']
		                ),
		                'body' => array(),
		                'cookies' => array(),
        				'timeout' => 360,
		            )
				);

				if ( !is_wp_error( $response ) && is_array( $response ) && isset($response['body']) ) 
				{
					$header = $response['headers']; // array of http header lines
					$body = $response['body']; // use the content

					$property_unit_json = json_decode( $body, TRUE );

					if ($property_unit_json !== FALSE && isset($property_unit_json['data']) && is_array($property_unit_json['data']) )
					{
						if ( $total_pages == 999 && isset($property_unit_json['pagination']['pageCount']) )
						{
							$total_pages = $property_unit_json['pagination']['pageCount'];
						}

						if ( !empty($property_unit_json['data']) )
						{
							$this->propertyhive_property_import_add_log("Found " . count($property_unit_json['data']) . " units on page " . $page . " / " . $total_pages . " in JSON ready for parsing");

							foreach ( $property_unit_json['data'] as $unit )
							{
								if ( !isset($this->properties[$unit['property_id']]['units']) )
								{
									continue;
								}

								$unit['epcs'] = array();

								// get certificates belonging to unit
								$response = wp_remote_get( 
									'https://api.arthuronline.co.uk/v2/units/' . $unit['id'] . '/certificates',
									array(
						                'headers' => array(
						                	'Authorization' => 'Bearer ' . $import_settings['access_token'],
						                	'X-EntityID' => $import_settings['entity_id']
						                ),
						                'body' => array(),
						                'cookies' => array(),
						                'timeout' => 60,
						            )
								);

								if ( !is_wp_error( $response ) && is_array( $response ) && isset($response['body']) ) 
								{
									$header = $response['headers']; // array of http header lines
									$body = $response['body']; // use the content
								
									$certificate_json = json_decode( $body, TRUE );

									if ($certificate_json !== FALSE)
									{
										if ( is_array($certificate_json['data']) && !empty($certificate_json['data']) )
										{
											foreach ( $certificate_json['data'] as $certificate )
											{
												if ( isset($certificate['type']) && strpos(strtolower($certificate['type']), 'epc') !== FALSE )
												{
													// Yes this is an EPC
													if ( 
														isset($certificate['file']) && 
														isset($certificate['file']['download_url'])
													)
													{
														$unit['epcs'][] = array(
															'url' => $certificate['file']['download_url'],
															'modified' => $certificate['modified'],
															'extension' => ( strpos($certificate['mime_type'], 'pdf') !== FALSE ? 'pdf' : '' )
														);
													}
												}
											}
										}
									}
							        else
							        {
							        	// Failed to parse JSON
							        	$this->propertyhive_property_import_add_error( 'Failed to parse certificate JSON:' . print_r($body, TRUE) );
							        	return false;
							        }
						        }
						        else
						        {
						        	// Request failed
						        	$this->propertyhive_property_import_add_error( 'Certificate request failed. Response: ' . print_r($response, TRUE) );
						        	return false;
						        }

						        $this->properties[$unit['property_id']]['units'][] = $unit;
							}
						}
					}
					else
					{
						// Failed to parse JSON
			        	$this->propertyhive_property_import_add_error( 'Failed to parse units JSON file: ' . print_r($body, true) );
			        	return false;
					}
				}
				else
		        {
		        	// Request failed
		        	$this->propertyhive_property_import_add_error( 'Request failed. Response: ' . print_r($response, TRUE) );
		        	return false;
		        }

				++$page;
		    }
		}

	    // get property images so we can get descriptions
	    $total_pages = 999;
    	$page = 1;

    	$images = array();

    	while ( $page <= $total_pages )
    	{
		    $response = wp_remote_get( 
				'https://api.arthuronline.co.uk/v2/assets?model=Property&is_image=true&limit=100&page=' . $page,
				array(
	                'headers' => array(
	                	'Authorization' => 'Bearer ' . $import_settings['access_token'],
	                	'X-EntityID' => $import_settings['entity_id']
	                ),
	                'body' => array(),
	                'cookies' => array(),
	                'timeout' => 360,
	            )
			);

			if ( !is_wp_error( $response ) && is_array( $response ) && isset($response['body']) ) 
			{
				$header = $response['headers']; // array of http header lines
				$body = $response['body']; // use the content
			
				$json = json_decode( $body, TRUE );

				if ($json !== FALSE && is_array($json['data']))
				{
					if ( $total_pages == 999 && isset($json['pagination']['pageCount']) )
					{
						$total_pages = $json['pagination']['pageCount'];
					}

					if ( !empty($json['data']) )
					{
						foreach ( $json['data'] as $image )
						{
							if ( isset($image['attachments']) && !empty($image['attachments']) )
							{
								foreach ( $image['attachments'] as $attachment )
								{
									if ( !isset($images[$attachment['model_id']]) )
									{
										$images[$attachment['model_id']] = array();
									}

									$images[$attachment['model_id']][] = $image;
								}
							}
						}
					}
				}
				else
		        {
		        	// Failed to parse JSON
		        	$this->log_error( 'Failed to parse property images JSON file: ' . print_r($json, TRUE) );
		        	return false;
		        }
			}
	        else
	        {
	        	// Request failed
	        	$this->log_error( 'Property images request failed. Response: ' . print_r($response, TRUE) );
	        	return false;
	        }

	        ++$page;
	    }

	    // put property images into main properties array
	    foreach ( $this->properties as $i => $property )
	    {
	    	if ( isset($images[$property['id']]) )
	    	{
	    		$this->properties[$i]['images'] = $images[$property['id']];
	    	}
	    }

	    // get unit images so we can get descriptions
	    $total_pages = 999;
    	$page = 1;

    	$images = array();

    	while ( $page <= $total_pages )
    	{
		    $response = wp_remote_get( 
				'https://api.arthuronline.co.uk/v2/assets?model=Unit&is_image=true&limit=100&page=' . $page,
				array(
	                'headers' => array(
	                	'Authorization' => 'Bearer ' . $import_settings['access_token'],
	                	'X-EntityID' => $import_settings['entity_id']
	                ),
	                'body' => array(),
	                'cookies' => array(),
	                'timeout' => 360,
	            )
			);

			if ( !is_wp_error( $response ) && is_array( $response ) && isset($response['body']) ) 
			{
				$header = $response['headers']; // array of http header lines
				$body = $response['body']; // use the content
			
				$json = json_decode( $body, TRUE );

				if ($json !== FALSE && is_array($json['data']))
				{
					if ( $total_pages == 999 && isset($json['pagination']['pageCount']) )
					{
						$total_pages = $json['pagination']['pageCount'];
					}

					if ( !empty($json['data']) )
					{
						foreach ( $json['data'] as $image )
						{
							if ( isset($image['attachments']) && !empty($image['attachments']) )
							{
								foreach ( $image['attachments'] as $attachment )
								{
									if ( !isset($images[$attachment['model_id']]) )
									{
										$images[$attachment['model_id']] = array();
									}

									$images[$attachment['model_id']][] = $image;
								}
							}
						}
					}
				}
				else
		        {
		        	// Failed to parse JSON
		        	$this->log_error( 'Failed to parse unit images JSON file: ' . print_r($json, TRUE) );
		        	return false;
		        }
			}
	        else
	        {
	        	// Request failed
	        	$this->log_error( 'Unit images request failed. Response: ' . print_r($response, TRUE) );
	        	return false;
	        }

        	++$page;
	    }

	    // put property images into main properties array
	    foreach ( $this->properties as $i => $property )
	    {
	    	if ( isset($property['units']) && !empty($property['units']) )
	    	{
	    		foreach ( $property['units'] as $unit_i => $unit )
	    		{
			    	if ( isset($images[$unit['id']]) )
			    	{
			    		$this->properties[$i]['units'][$unit_i]['images'] = $images[$unit['id']];
			    	}
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

		$import_structure = isset($import_settings['import_structure']) ? $import_settings['import_structure'] : '';

		if ( $import_structure == 'units_only' )
		{
			$this->import_units();
			return;
		}

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_arthur_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_arthur_json_properties_due_import", $this->properties, $this->import_id );

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
			do_action( "propertyhive_property_importing_arthur_json", $property, $this->import_id, $this->instance_id );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['id'], 0, $property['id'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			if ( isset($property['portal_address']) && $property['portal_address'] )
			{
				$display_address = $property['portal_address'];
			}
			else
			{
				$display_address = $property['address_line_2'];
		        if ( isset($property['city']) && $property['city'] != '' )
		        {
		        	if ( $display_address != '' )
		        	{
		        		$display_address .= ', ';
		        	}
		        	$display_address .= $property['city'];
		        }
		    }

		    list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, '', '', date("Y-m-d H:i:s", strtotime($property['created'])) );

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

				update_post_meta( $post_id, '_reference_number', ( ( isset($property['id']) ) ? $property['id'] : '' ) );
				$this->populate_address_fields( $post_id, $property, $property['id'] );

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
				
				$office_id = $this->primary_office_id;
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-lettings';
				if ( 
					class_exists('PH_Rooms') &&
					get_option( 'propertyhive_active_departments_rooms' ) == 'yes' &&
					$import_structure == ''
				)
				{
					$department = 'rooms';
				}
				update_post_meta( $post_id, '_department', $department );
				
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property['bedrooms']) && is_numeric($property['bedrooms']) ) ? $property['bedrooms'] : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bathrooms']) && is_numeric($property['bathrooms']) ) ? $property['bathrooms'] : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['receptions']) && is_numeric($property['receptions']) ) ? $property['receptions'] : '' ) );

				if ( $department == 'residential-lettings' || $department == 'rooms' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', $property['portal_market_rent']));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pw';
					$price_actual = $price_actual = ($price * 52) / 12;
					switch ($property['portal_market_rent_frequency'])
					{
						case "Daily": { $rent_frequency = 'pd'; $price_actual = ($price * 365) / 12; break; }
						case "Monthly": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						case "Quarterly": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
						case "Yearly": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					update_post_meta( $post_id, '_currency', 'GBP' );
					
					//update_post_meta( $post_id, '_poa', ( ( isset($property['qualifier_name']) && strtolower($property['qualifier_name']) == 'poa' ) ? 'yes' : '') );

					update_post_meta( $post_id, '_deposit', $property['deposit_amount'] );
            		
            		$available_date = ( isset($property['date_available']) && !empty($property['date_available']) ) ? $property['date_available'] : '';
					if ( empty($available_date) )
					{
						$available_date = ( isset($property['available_from']) && !empty($property['available_from']) ) ? $property['available_from'] : '';
					}
					if ( empty($available_date) )
					{
						if ( isset($property['units']) && is_array($property['units']) && !empty($property['units']) )
						{
							foreach ( $property['units'] as $unit )
							{
								$unit_available_date = ( ( isset($unit['date_available']) && !empty($unit['date_available']) ) ? $unit['date_available'] : '' );
				                if ( empty($unit_available_date) )
				                {
				                	$unit_available_date = ( ( isset($unit['available_from']) && !empty($unit['available_from']) ) ? $unit['available_from'] : '' );
				                }

				                if ( empty($available_date) || ( !empty($available_date) && !empty($unit_available_date) && $unit_available_date < $available_date ) )
				                {
				                	$available_date = $unit_available_date;
				                }
							}
						}
					}
            		update_post_meta( $post_id, '_available_date', $available_date );
				}

				// Marketing
				$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
				if ( $on_market_by_default === true )
				{
					update_post_meta( $post_id, '_on_market', 'yes' );
				}
				add_post_meta( $post_id, '_featured', '', true );

	            // Features
	            if ( isset($property['features']) && is_array($property['features']) && !empty($property['features']) )
	            {
	        		$i = 0;
			        foreach ( $property['features'] as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }
			        update_post_meta( $post_id, '_features', $i );
			    }

			    // Media - Images
			    $media = array();
			    if ( isset($property['image_urls']) && is_array($property['image_urls']) && !empty($property['image_urls']) )
				{
					foreach ( $property['image_urls'] as $url )
					{
						$description = '';
						if ( isset($property['images']) && !empty($property['images']) )
						{
							foreach ( $property['images'] as $image )
							{
								if ( isset($image['download_url']) && $image['download_url'] == $url )
								{
									if ( isset($image['image_description']) )
									{
										$description = $image['image_description'];
									}
								}
							}
						}

						$media[] = array(
							'url' => $url,
							'filename' => basename( $url ) . '.jpg',
							'description' => $description,
						);
					}
				}

				$this->import_media( $post_id, $property['id'], 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if ( isset($property['floor_plan_urls']) && is_array($property['floor_plan_urls']) && !empty($property['floor_plan_urls']) )
				{
					foreach ( $property['floor_plan_urls'] as $url )
					{
						$media[] = array(
							'url' => $url,
							'filename' => basename( $url ) . '.jpg',
						);
					}
				}

				$this->import_media( $post_id, $property['id'], 'floorplan', $media, false );

				// Media - EPCs
			    $media = array();
			    if ( isset($property['epc_urls']) && is_array($property['epc_urls']) && !empty($property['epc_urls']) )
				{
					foreach ( $property['epc_urls'] as $url )
					{
						$media[] = array(
							'url' => $url,
							'filename' => basename( $url ) . '.jpg',
						);
					}
				}
				if ( isset($property['epcs']) && is_array($property['epcs']) && !empty($property['epcs']) )
				{
					foreach ( $property['epcs'] as $epc )
					{
						$media[] = array(
							'url' => $epc['url'],
							'filename' => basename( $epc['url'] ) . '.' . $epc['extension'],
						);
					}
				}

				$this->import_media( $post_id, $property['id'], 'epc', $media, false );

				// Media - Virtual Tours
				$virtual_tours = array();
				if (isset($property['virtual_tour_url']) && $property['virtual_tour_url'] != '')
                {
                    $virtual_tours[] = $property['virtual_tour_url'];
                }
                if ( isset($property['units']) && is_array($property['units']) && !empty($property['units']) )
				{
					foreach ( $property['units'] as $unit_i => $unit )
					{
						if ( isset($unit['virtual_tour_url']) && $unit['virtual_tour_url'] != '' )
		                {
		                    $virtual_tours[] = $unit['virtual_tour_url'];
		                }
					}
				}

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );

				if (
					isset($property['units']) && is_array($property['units']) && !empty($property['units']) 
				)
				{
					$num_units = count($property['units']);
					foreach ( $property['units'] as $unit_i => $unit )
					{
						$this->log( 'Importing unit ' . ($unit_i + 1) . ' of ' . $num_units . ' with reference ' . $unit['id'], 0, $property['id'] . '-' . $unit['id'] );

						if ( isset($unit['portal_address']) && $unit['portal_address'] )
						{
							$unit_display_address = $unit['portal_address'];
						}
						else
						{
							$unit_display_address = $unit['address_line_2'];
					        if ( isset($unit['city']) && $unit['city'] != '' )
					        {
					        	if ( $unit_display_address != '' )
					        	{
					        		$unit_display_address .= ', ';
					        	}
					        	$unit_display_address .= $unit['city'];
					        }
					        if ( trim($unit_display_address) != ''  )
					        {
					        	$unit_display_address = $unit['unit_ref'] . ' - ' . $unit_display_address;
					        }
					    }

					    if ( trim($unit_display_address) == '' )
					    {
					    	$unit_display_address = $display_address;
					    }

					    list( $inserted_updated_unit, $unit_post_id ) = $this->insert_update_property_post( $property['id'] . '-' . $unit['id'], $unit, $unit_display_address, ( ( isset($unit['short_description']) && $unit['short_description'] != '' ) ? $unit['short_description'] : '' ), '', date("Y-m-d H:i:s", strtotime($unit['created'])) );

						if ( $inserted_updated_unit !== false )
						{
							$this->log( 'Successfully ' . $inserted_updated_unit . ' unit', $unit_post_id, $property['id'] . '-' . $unit['id'] );

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

							update_post_meta( $unit_post_id, '_property_import_data', json_encode($unit, JSON_PRETTY_PRINT) );

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
										$value = trim( $unit['unit_ref'] . ( $value != '' ? ' - ' . $value : '' ) );
									}
									update_post_meta( $unit_post_id, $key, $value );
								}
							}

							update_post_meta( $unit_post_id, $imported_ref_key, $unit['property_id'] . '-' . $unit['id'] );

							update_post_meta( $unit_post_id, '_bedrooms', ( ( isset($property['bedrooms']) && is_numeric($unit['bedrooms']) ) ? $unit['bedrooms'] : '' ) );
							update_post_meta( $unit_post_id, '_bathrooms', ( ( isset($property['bathrooms']) && is_numeric($unit['bathrooms']) ) ? $unit['bathrooms'] : '' ) );
							update_post_meta( $unit_post_id, '_reception_rooms', ( ( isset($property['receptions']) && is_numeric($unit['receptions']) ) ? $unit['receptions'] : '' ) );

							if ( isset($unit['portal_unit_type']) && $unit['portal_unit_type'] != '' )
			                {
								$mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();

			                	if ( !empty($mapping) && isset($mapping[$unit['portal_unit_type']]) )
								{
									wp_set_object_terms( $unit_post_id, (int)$mapping[$unit['portal_unit_type']], 'property_type' );
								}
								else
								{
									wp_delete_object_term_relationships( $unit_post_id, 'property_type' );

									$this->log( 'Unit with ID ' . $unit['id'] . ' received with a property type (' . $unit['portal_unit_type'] . ') that is not mapped', $unit_post_id, $property['id'] . '-' . $unit['id'] );

									$import_settings = $this->add_missing_mapping( $mapping, 'property_type', $unit['portal_unit_type'], $unit_post_id );
								}
			                }
			                else
			                {
			                    wp_delete_object_term_relationships( $unit_post_id, 'property_type' );
			                }

							$department = 'residential-lettings';
							if ( class_exists('PH_Rooms') && $import_structure == '' )
					        {
								$department = 'rooms';
							}
							update_post_meta( $unit_post_id, '_department', $department );

							$on_market = '';
							if ( strtolower($unit['unit_status']) != 'unavailable to let' )
							{
								$on_market = 'yes';
							}
							$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
							if ( $on_market_by_default === true )
							{
								update_post_meta( $unit_post_id, '_on_market', $on_market );
							}

			                update_post_meta( $unit_post_id, '_room_name', $unit['unit_ref'] );
			                $available_date = ( ( isset($unit['date_available']) && $unit['date_available'] != '' ) ? date("Y-m-d", strtotime($unit['date_available'])) : '' );
			                if ( $available_date == '' )
			                {
			                	$available_date = ( ( isset($unit['available_from']) && $unit['available_from'] != '' ) ? date("Y-m-d", strtotime($unit['available_from'])) : '' );
			                }
			                update_post_meta( $unit_post_id, '_available_date', $available_date );

			                $rent = preg_replace("/[^0-9.]/", '', $unit['portal_market_rent']);
			                update_post_meta( $unit_post_id, '_rent', $rent );

			                $price_actual = ($rent * 52) / 12;
			                $rent_frequency = 'pw';
			                switch ($unit['portal_market_rent_frequency'])
							{
								case "Daily": { $rent_frequency = 'pd'; $price_actual = ($rent * 365) / 12; break; }
								case "Monthly": { $rent_frequency = 'pcm'; $price_actual = $rent; break; }
								case "Quarterly": { $rent_frequency = 'pq'; $price_actual = ($rent * 4) / 12; break; }
								case "Yearly": { $rent_frequency = 'pa'; $price_actual = $rent / 12; break; }
							}

			                update_post_meta( $unit_post_id, '_rent_frequency', $rent_frequency );

			                update_post_meta( $unit_post_id, '_currency', 'GBP' );

			                /*if ( $rent != '' && ( $parent_price == 0 || ( $parent_price != 0 && $parent_price > $rent ) ) )
			                {
			                    $parent_price = $rent;
			                }*/

			                update_post_meta( $unit_post_id, '_price_actual', $price_actual );

			                //update_post_meta( $unit_post_id, '_poa', '' );

			                update_post_meta( $unit_post_id, '_deposit', preg_replace("/[^0-9.]/", '', $unit['deposit_amount']) );

			                if ( isset($unit['furnished']) && $unit['furnished'] != '' )
			                {
								$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

			                	if ( !empty($mapping) && isset($mapping[$unit['furnished']]) )
								{
									wp_set_object_terms( $unit_post_id, (int)$mapping[$unit['furnished']], 'furnished' );
								}
								else
								{
									wp_delete_object_term_relationships( $unit_post_id, 'furnished' );

									$this->log( 'Unit with ID ' . $unit['id'] . ' received with a furnished value (' . $unit['furnished'] . ') that is not mapped', $unit_post_id, $property['id'] . '-' . $unit['id'] );

									$import_settings = $this->add_missing_mapping( $mapping, 'furnished', $unit['furnished'], $unit_post_id );
								}
			                }
			                else
			                {
			                    wp_delete_object_term_relationships( $unit_post_id, 'furnished' );
			                }

			                if ( isset($unit['unit_status']) && $unit['unit_status'] != '' && strtolower($unit['unit_status']) != 'unavailable to let' )
			                {
			                	// Availability
								$mapping = isset($import_settings['mappings']['lettings_availability']) ? $import_settings['mappings']['lettings_availability'] : array();
								
			                	if ( !empty($mapping) && isset($mapping[$unit['unit_status']]) )
								{
									wp_set_object_terms( $unit_post_id, (int)$mapping[$unit['unit_status']], 'availability' );
								}
								else
								{
									wp_delete_object_term_relationships( $unit_post_id, 'availability' );
								}
			                }
			                else
			                {
			                    wp_delete_object_term_relationships( $unit_post_id, 'availability' );
			                }

			                // Features
				            if ( isset($unit['features']) && is_array($unit['features']) && !empty($unit['features']) )
				            {
				        		$i = 0;
						        foreach ( $unit['features'] as $feature )
						        {
						            update_post_meta( $unit_post_id, '_feature_' . $i, $feature );
						            ++$i;
						        }
						        update_post_meta( $unit_post_id, '_features', $i );
						    }

						    // Rooms / Descriptions
					        // For now put the whole description in one room / description
							update_post_meta( $unit_post_id, '_rooms', '1' );
							update_post_meta( $unit_post_id, '_room_name_0', '' );
				            update_post_meta( $unit_post_id, '_room_dimensions_0', '' );
				            update_post_meta( $unit_post_id, '_room_description_0', $unit['description'] );

				            // Media - Images
						    $media = array();
						    if ( isset($unit['image_urls']) && is_array($unit['image_urls']) && !empty($unit['image_urls']) )
							{
								foreach ( $unit['image_urls'] as $url )
								{
									$description = '';
									if ( isset($unit['images']) && !empty($unit['images']) )
									{
										foreach ( $unit['images'] as $image )
										{
											if ( isset($image['download_url']) &&  $image['download_url'] == $url )
											{
												if ( isset($image['image_description']) )
												{
													$description = $image['image_description'];
												}
											}
										}
									}

									$media[] = array(
										'url' => $url,
										'filename' => basename( $url ) . '.jpg',
										'description' => $description,
									);
								}
							}

							$this->import_media( $unit_post_id, $property['id'] . '-' . $unit['id'], 'photo', $media, false );

							// Media - Floorplans
						    $media = array();
						    if ( isset($unit['floor_plan_urls']) && is_array($unit['floor_plan_urls']) && !empty($unit['floor_plan_urls']) )
							{
								foreach ( $unit['floor_plan_urls'] as $url )
								{
									$media[] = array(
										'url' => $url,
										'filename' => basename( $url ) . '.jpg',
									);
								}
							}

							$this->import_media( $unit_post_id, $property['id'] . '-' . $unit['id'], 'floorplan', $media, false );

							// Media - EPCs
						    $media = array();
						    if ( isset($unit['epc_urls']) && is_array($unit['epc_urls']) && !empty($unit['epc_urls']) )
							{
								foreach ( $unit['epc_urls'] as $url )
								{
									$media[] = array(
										'url' => $url,
										'filename' => basename( $url ) . '.jpg',
									);
								}
							}
							if ( isset($unit['epc_urls']) && is_array($unit['epc_urls']) && !empty($unit['epc_urls']) )
							{
								foreach ( $unit['epc_urls'] as $url )
								{
									$media[] = array(
										'url' => $url,
										'filename' => basename( $url ) . '.' . $epc['modified'],
									);
								}
							}

							$this->import_media( $unit_post_id, $property['id'] . '-' . $unit['id'], 'epc', $media, false );

							// Media - Virtual Tours
							$virtual_tours = array();
							if (isset($unit['virtual_tour_url']) && $unit['virtual_tour_url'] != '')
			                {
			                    $virtual_tours[] = $unit['virtual_tour_url'];
			                }

			                update_post_meta( $unit_post_id, '_virtual_tours', count($virtual_tours) );
			                foreach ($virtual_tours as $i => $virtual_tour)
			                {
			                	update_post_meta( $unit_post_id, '_virtual_tour_' . $i, $virtual_tour );
			                }

			                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $unit_post_id, $property['id'] . '-' . $unit['id'] );

			                do_action( "propertyhive_property_imported", $unit_post_id, $unit, $this->import_id );
							do_action( "propertyhive_property_unit_imported_arthur_json", $unit_post_id, $unit, $this->import_id );

							$post = get_post( $unit_post_id );
							do_action( "save_post_property", $unit_post_id, $post, false );
							do_action( "save_post", $unit_post_id, $post, false );

							if ( $inserted_updated_unit == 'updated' )
							{
								$this->compare_meta_and_taxonomy_data( $unit_post_id, $property['id'] . '-' . $unit['id'], $unit_metadata_before, $unit_taxonomy_terms_before );
							}
						}
					}
				}

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_arthur_json", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_arthur_json" );

		$this->import_end();
	}

	public function import_units()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

		$import_structure = isset($import_settings['import_structure']) ? $import_settings['import_structure'] : '';

        do_action( "propertyhive_pre_import_properties_arthur_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_arthur_json_properties_due_import", $this->properties, $this->import_id );

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
			do_action( "propertyhive_property_importing_arthur_json", $property, $this->import_id, $this->instance_id );

			$display_address = '';
			if ( isset($property['portal_address']) && $property['portal_address'] )
			{
				$display_address = $property['portal_address'];
			}
			else
			{
				$display_address = $property['address_line_2'];
		        if ( isset($property['city']) && $property['city'] != '' )
		        {
		        	if ( $display_address != '' )
		        	{
		        		$display_address .= ', ';
		        	}
		        	$display_address .= $property['city'];
		        }
		    }
			
			$this->log( 'Importing units for property ' . $property_row . ' with reference ' . $property['id'], 0, $property['id'] );

			if (
				isset($property['units']) && is_array($property['units']) && !empty($property['units']) 
			)
			{
				$num_units = count($property['units']);
				foreach ( $property['units'] as $unit_i => $unit )
				{
					$this->log( 'Importing unit ' . ($unit_i + 1) . ' of ' . $num_units . ' with reference ' . $unit['id'], 0, $property['id'] . '-' . $unit['id'] );

					if ( isset($unit['portal_address']) && $unit['portal_address'] != '' )
					{
						$unit_display_address = $unit['portal_address'];
					}
					else
					{
						$unit_display_address = $unit['address_line_2'];
						if ( isset($unit['city']) && $unit['city'] != '' )
						{
							if ( $unit_display_address != '' )
							{
								$unit_display_address .= ', ';
							}
							$unit_display_address .= $unit['city'];
						}
						if ( trim($unit_display_address) != ''  )
						{
							$unit_display_address = $unit['unit_ref'] . ' - ' . $unit_display_address;
						}
					}

					if ( empty($unit_display_address) )
					{
						$unit_display_address = $display_address;
					}

					list( $inserted_updated_unit, $unit_post_id ) = $this->insert_update_property_post( $property['id'] . '-' . $unit['id'], $unit, $unit_display_address, ( ( isset($unit['short_description']) && $unit['short_description'] != '' ) ? $unit['short_description'] : '' ), '', date("Y-m-d H:i:s", strtotime($unit['created'])) );

					if ( $inserted_updated_unit !== false )
					{
						$this->log( 'Successfully ' . $inserted_updated_unit . ' unit', $unit_post_id, $property['id'] . '-' . $unit['id'] );
						
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

						update_post_meta( $unit_post_id, '_property_import_data', json_encode($unit, JSON_PRETTY_PRINT) );

						$this->populate_address_fields($unit_post_id, $unit, $property['id'] . '-' . $unit['id']);

						add_post_meta( $unit_post_id, '_owner_contact_id', '', true );
						add_post_meta( $unit_post_id, '_negotiator_id', get_current_user_id(), true );
						update_post_meta( $unit_post_id, '_office_id', $this->primary_office_id );

						update_post_meta( $unit_post_id, $imported_ref_key, $unit['property_id'] . '-' . $unit['id'] );

						$department = 'residential-lettings';
						if ( isset($unit['unit_type']) && strtolower($unit['unit_type']) == 'commercial' )
						{
							$department = 'commercial';
						}
						if ( class_exists('PH_Rooms') && $import_structure == '' )
						{
							$department = 'rooms';
						}

						update_post_meta( $unit_post_id, '_department', $department );

						update_post_meta( $unit_post_id, '_bedrooms', ( ( isset($property['bedrooms']) && is_numeric($unit['bedrooms']) ) ? $unit['bedrooms'] : '' ) );
						update_post_meta( $unit_post_id, '_bathrooms', ( ( isset($property['bathrooms']) && is_numeric($unit['bathrooms']) ) ? $unit['bathrooms'] : '' ) );
						update_post_meta( $unit_post_id, '_reception_rooms', ( ( isset($property['receptions']) && is_numeric($unit['receptions']) ) ? $unit['receptions'] : '' ) );

						$prefix = '';
						if ( $department == 'commercial' )
						{
							$prefix = 'commercial_';
						}

						if ( isset($unit['portal_unit_type']) && $unit['portal_unit_type'] != '' )
						{
							$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
							
							if ( !empty($mapping) && isset($mapping[$unit['portal_unit_type']]) )
							{
								wp_set_object_terms( $unit_post_id, (int)$mapping[$unit['portal_unit_type']], $prefix . 'property_type' );
							}
							else
							{
								wp_delete_object_term_relationships( $unit_post_id, $prefix . 'property_type' );

								$this->log( 'Unit with ID ' . $unit['id'] . ' received with a property type (' . $unit['portal_unit_type'] . ') that is not mapped', $unit_post_id, $property['id'] . '-' . $unit['id'] );

								$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $unit['portal_unit_type'], $unit_post_id );
							}
						}
						else
						{
							wp_delete_object_term_relationships( $unit_post_id, $prefix . 'property_type' );
						}

						$on_market = '';
						if ( strtolower($unit['unit_status']) != 'unavailable to let' )
						{
							$on_market = 'yes';
						}
						$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
						if ( $on_market_by_default === true )
						{
							update_post_meta( $unit_post_id, '_on_market', $on_market );
						}

						update_post_meta( $unit_post_id, '_room_name', $unit['unit_ref'] );

						if ( $department == 'commercial' ) 
						{
							update_post_meta( $unit_post_id, '_for_sale', '' );
		            		update_post_meta( $unit_post_id, '_to_rent', '' );

		            		// Always to rent
		                    update_post_meta( $unit_post_id, '_to_rent', 'yes' );

		                    update_post_meta( $unit_post_id, '_commercial_rent_currency', 'GBP' );

		                    $rent = preg_replace("/[^0-9.]/", '', $unit['portal_market_rent']);
		                    update_post_meta( $unit_post_id, '_rent_from', $rent );
		                    update_post_meta( $unit_post_id, '_rent_to', $rent );

		                    $rent_frequency = 'pw';
							switch ($unit['portal_market_rent_frequency'])
							{
								case "Daily": { $rent_frequency = 'pd'; break; }
								case "Monthly": { $rent_frequency = 'pcm'; break; }
								case "Quarterly": { $rent_frequency = 'pq'; break; }
								case "Yearly": { $rent_frequency = 'pa'; break; }
							}
		                    update_post_meta( $unit_post_id, '_rent_units', $rent_frequency);

		                    update_post_meta( $unit_post_id, '_rent_poa', '' );

				            $ph_countries = new PH_Countries();
				            $ph_countries->update_property_price_actual( $unit_post_id );

				            $size = preg_replace("/[^0-9.]/", '', $unit['size']);
				            $size_unit = 'sqft';
				            switch ( $unit['size_unit'] )
				            {
				            	case "Sq Meter": { $size_unit = 'sqm'; break; }
				            }
				            update_post_meta( $unit_post_id, '_floor_area_from', $size );

				            update_post_meta( $unit_post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $size_unit ) );

				            update_post_meta( $unit_post_id, '_floor_area_to', $size );

				            update_post_meta( $unit_post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $size_unit ) );

				            update_post_meta( $unit_post_id, '_floor_area_units', $size_unit );

				            $size = '';
				            update_post_meta( $unit_post_id, '_site_area_from', $size );
				            update_post_meta( $unit_post_id, '_site_area_from_sqft', $size );
				            update_post_meta( $unit_post_id, '_site_area_to', $size );
				            update_post_meta( $unit_post_id, '_site_area_to_sqft', $size );
				            update_post_meta( $unit_post_id, '_site_area_units', $size_unit );
						}
						else
						{
							$available_date = ( ( isset($unit['date_available']) && $unit['date_available'] != '' ) ? date("Y-m-d", strtotime($unit['date_available'])) : '' );
							if ( $available_date == '' )
							{
								$available_date = ( ( isset($unit['available_from']) && $unit['available_from'] != '' ) ? date("Y-m-d", strtotime($unit['available_from'])) : '' );
							}
							update_post_meta( $unit_post_id, '_available_date', $available_date );

							$rent = preg_replace("/[^0-9.]/", '', $unit['portal_market_rent']);
							update_post_meta( $unit_post_id, '_rent', $rent );

							$price_actual = ($rent * 52) / 12;
							$rent_frequency = 'pw';
							switch ($unit['portal_market_rent_frequency'])
							{
								case "Daily": { $rent_frequency = 'pd'; $price_actual = ($rent * 365) / 12; break; }
								case "Weekly": { $rent_frequency = 'pw'; $price_actual = ($rent * 52) / 12; break; }
								case "Monthly": { $rent_frequency = 'pcm'; $price_actual = $rent; break; }
								case "Quarterly": { $rent_frequency = 'pq'; $price_actual = ($rent * 4) / 12; break; }
								case "Yearly": { $rent_frequency = 'pa'; $price_actual = $rent / 12; break; }
							}

							update_post_meta( $unit_post_id, '_rent_frequency', $rent_frequency );

							update_post_meta( $unit_post_id, '_currency', 'GBP' );

							$ph_countries = new PH_Countries();
				            $ph_countries->update_property_price_actual( $unit_post_id );

							update_post_meta( $unit_post_id, '_deposit', preg_replace("/[^0-9.]/", '', $unit['deposit_amount']) );

							if ( isset($unit['furnished']) && $unit['furnished'] != '' )
							{
								$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

								if ( !empty($mapping) && isset($mapping[$unit['furnished']]) )
								{
									wp_set_object_terms( $unit_post_id, (int)$mapping[$unit['furnished']], 'furnished' );
								}
								else
								{
									wp_delete_object_term_relationships( $unit_post_id, 'furnished' );

									$this->log( 'Unit with ID ' . $unit['id'] . ' received with a furnished value (' . $unit['furnished'] . ') that is not mapped', $unit_post_id, $property['id'] . '-' . $unit['id'] );

									$import_settings = $this->add_missing_mapping( $mapping, 'furnished', $unit['furnished'], $unit_post_id );
								}
							}
							else
							{
								wp_delete_object_term_relationships( $unit_post_id, 'furnished' );
							}
						}

						if ( isset($unit['unit_status']) && $unit['unit_status'] != '' && strtolower($unit['unit_status']) != 'unavailable to let' )
						{
							// Availability
							$mapping = isset($import_settings['mappings']['lettings_availability']) ? $import_settings['mappings']['lettings_availability'] : array();

							if ( !empty($mapping) && isset($mapping[$unit['unit_status']]) )
							{
								wp_set_object_terms( $unit_post_id, (int)$mapping[$unit['unit_status']], 'availability' );
							}
							else
							{
								wp_delete_object_term_relationships( $unit_post_id, 'availability' );
							}
						}
						else
						{
							wp_delete_object_term_relationships( $unit_post_id, 'availability' );
						}

						// Features
						if ( isset($unit['features']) && is_array($unit['features']) && !empty($unit['features']) )
						{
							$i = 0;
							foreach ( $unit['features'] as $feature )
							{
								update_post_meta( $unit_post_id, '_feature_' . $i, $feature );
								++$i;
							}
							update_post_meta( $unit_post_id, '_features', $i );
						}

						// Rooms / Descriptions
						// For now put the whole description in one room / description
						if ( $department == 'commercial' )
						{
							update_post_meta( $unit_post_id, '_descriptions', '1' );
							update_post_meta( $unit_post_id, '_description_name_0', '' );
				            update_post_meta( $unit_post_id, '_description_0', $unit['description'] );
						}
						else
						{
							update_post_meta( $unit_post_id, '_rooms', '1' );
							update_post_meta( $unit_post_id, '_room_name_0', '' );
							update_post_meta( $unit_post_id, '_room_dimensions_0', '' );
							update_post_meta( $unit_post_id, '_room_description_0', $unit['description'] );
						}

						// Media - Images
					    $media = array();
					    if ( isset($unit['image_urls']) && is_array($unit['image_urls']) && !empty($unit['image_urls']) )
						{
							foreach ( $unit['image_urls'] as $url )
							{
								$media[] = array(
									'url' => $url,
									'filename' => basename( $url ) . '.jpg',
								);
							}
						}

						$this->import_media( $unit_post_id, $property['id'] . '-' . $unit['id'], 'photo', $media, false );

						// Media - Floorplans
					    $media = array();
					    if ( isset($unit['floor_plan_urls']) && is_array($unit['floor_plan_urls']) && !empty($unit['floor_plan_urls']) )
						{
							foreach ( $unit['floor_plan_urls'] as $url )
							{
								$media[] = array(
									'url' => $url,
									'filename' => basename( $url ) . '.jpg',
								);
							}
						}

						$this->import_media( $unit_post_id, $property['id'] . '-' . $unit['id'], 'floorplan', $media, false );

						// Media - EPC
					    $media = array();
					    if ( isset($unit['epc_urls']) && is_array($unit['epc_urls']) && !empty($unit['epc_urls']) )
						{
							foreach ( $unit['epc_urls'] as $url )
							{
								$media[] = array(
									'url' => $url,
									'filename' => basename( $url ) . '.jpg',
								);
							}
						}

						$this->import_media( $unit_post_id, $property['id'] . '-' . $unit['id'], 'epc', $media, false );

						// Media - Virtual Tours
						$virtual_tours = array();
						if (isset($unit['virtual_tour_url']) && $unit['virtual_tour_url'] != '')
		                {
		                    $virtual_tours[] = $unit['virtual_tour_url'];
		                }

		                update_post_meta( $unit_post_id, '_virtual_tours', count($virtual_tours) );
		                foreach ($virtual_tours as $i => $virtual_tour)
		                {
		                	update_post_meta( $unit_post_id, '_virtual_tour_' . $i, $virtual_tour );
		                }

		                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $unit_post_id, $property['id'] . '-' . $unit['id'] );

		                do_action( "propertyhive_property_imported", $unit_post_id, $unit, $this->import_id );
						do_action( "propertyhive_property_unit_imported_arthur_json", $unit_post_id, $unit, $this->import_id);

						$post = get_post( $unit_post_id );
						do_action( "save_post_property", $unit_post_id, $post, false );
						do_action( "save_post", $unit_post_id, $post, false );

						if ( $inserted_updated_unit == 'updated' )
						{
							$this->compare_meta_and_taxonomy_data( $unit_post_id, $property['id'] . '-' . $unit['id'], $unit_metadata_before, $unit_taxonomy_terms_before );
						}
					}
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_arthur_json" );

		$this->import_end();
	}

	public function remove_old_properties( )
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			if (!isset($import_settings['import_structure']) || $import_settings['import_structure'] != 'units_only') {
				$import_refs[] = $property['id'];
			}

			if (
				isset($property['units']) && is_array($property['units']) && !empty($property['units']) 
			)
			{
				foreach ( $property['units'] as $unit )
				{
					$import_refs[] = $property['id'] . '-' . $unit['id'];
				}
			}
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}
	public function populate_address_fields($post_id, $data_array, $unique_id)
	{
		update_post_meta( $post_id, '_address_name_number', isset($data_array['unit_ref']) ? $data_array['unit_ref'] : '' );
		update_post_meta( $post_id, '_address_street', ( ( isset($data_array['address_line_1']) ) ? $data_array['address_line_1'] : '' ) );
		update_post_meta( $post_id, '_address_two', ( ( isset($data_array['address_line_2']) ) ? $data_array['address_line_2'] : '' ) );
		update_post_meta( $post_id, '_address_three', ( ( isset($data_array['city']) ) ? $data_array['city'] : '' ) );
		update_post_meta( $post_id, '_address_four', ( ( isset($data_array['county']) ) ? $data_array['county'] : '' ) );
		update_post_meta( $post_id, '_address_postcode', ( ( isset($data_array['postcode']) ) ? $data_array['postcode'] : '' ) );

		$country = get_option( 'propertyhive_default_country', 'GB' );
		update_post_meta( $post_id, '_address_country', $country );

		// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
		$address_fields_to_check = apply_filters( 'propertyhive_arthur_json_address_fields_to_check', array('address_line_2', 'city', 'county') );
		$location_term_ids = array();

		foreach ( $address_fields_to_check as $address_field )
		{
			if ( isset($data_array[$address_field]) && trim($data_array[$address_field]) != '' )
			{
				$term = term_exists( trim($data_array[$address_field]), 'location');
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
		if ( isset($data_array['latitude']) && isset($data_array['longitude']) && $data_array['latitude'] != '' && $data_array['longitude'] != '' && $data_array['latitude'] != '0' && $data_array['longitude'] != '0' )
		{
			update_post_meta( $post_id, '_latitude', $data_array['latitude'] );
			update_post_meta( $post_id, '_longitude', $data_array['longitude'] );
		}
		elseif ( isset($data_array['lat']) && isset($data_array['lng']) && $data_array['lat'] != '' && $data_array['lng'] != '' && $data_array['lat'] != '0' && $data_array['lng'] != '0' )
		{
			update_post_meta( $post_id, '_latitude', $data_array['lat'] );
			update_post_meta( $post_id, '_longitude', $data_array['lng'] );
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
				if ( isset($data_array['address_line_2']) && trim($data_array['address_line_2']) != '' ) { $address_to_geocode[] = $data_array['address_line_2']; }
				if ( isset($data_array['city']) && trim($data_array['city']) != '' ) { $address_to_geocode[] = $data_array['city']; }
				if ( isset($data_array['county']) && trim($data_array['county']) != '' ) { $address_to_geocode[] = $data_array['county']; }
				if ( isset($data_array['postcode']) && trim($data_array['postcode']) != '' ) { $address_to_geocode[] = $data_array['postcode']; $address_to_geocode_osm[] = $data_array['postcode']; }

				$return = $this->do_geocoding_lookup( $post_id, $unique_id, $address_to_geocode, $address_to_geocode_osm, $country );
			}
		}
	}

	public function get_default_mapping_values()
	{
		return array(
            'lettings_availability' => array(
                'Available To Let' => 'Available To Let',
                'Under Offer' => 'Under Offer',
                'Let' => 'Let',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Bungalow' => 'Bungalow',
                'Chalet' => 'Chalet',
                'Cluster House' => 'Cluster House',
                'Cottage' => 'Cottage',
                'Detached' => 'Detached',
                'Detached Bungalow' => 'Detached Bungalow',
                'End of Terrace' => 'End of Terrace',
                'Flat' => 'Flat',
                'Ground Flat' => 'Ground Flat',
                'Ground Maisonette' => 'Ground Maisonette',
                'House' => 'House',
                'House Share' => 'House Share',
                'Land' => 'Land',
                'Link Detached House' => 'Link Detached House',
                'Maisonette' => 'Maisonette',
                'Mews' => 'Mews',
                'Mobile Home' => 'Mobile Home',
                'Penthouse' => 'Penthouse',
                'Semi-Detached' => 'Semi-Detached',
                'Semi-Detached Bungalow' => 'Semi-Detached Bungalow',
                'Studio' => 'Studio',
                'Terraced' => 'Terraced',
                'Terraced Bungalow' => 'Terraced Bungalow',
                'Town House' => 'Town House',
                'Villa' => 'Villa',
            ),
            'commercial_property_type' => array(
                'Office' => 'Office',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Part-Furnished' => 'Part-Furnished',
                'Unfurnished' => 'Unfurnished',
            )
        );
	}
}

}