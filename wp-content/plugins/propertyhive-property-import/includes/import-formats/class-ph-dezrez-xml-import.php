<?php
/**
 * Class for managing the import process of an Dezrez XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Dezrez_XML_Import extends PH_Property_Import_Process {

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

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$guid = uniqid(uniqid(), true);

		$api_calls = array(
			'sales' => array(
				'perpage' => 99999,
				'showSTC' => 'true',
				'rentalPeriod' => 0
			),
			'lettings' => array(
				'perpage' => 99999,
				'showSTC' => 'true',
				'rentalPeriod' => 4
			)
		);
		
		$api_calls = apply_filters( 'propertyhive_dezrez_xml_api_calls', $api_calls, $this->import_id );

		foreach ( $api_calls as $department => $params )
		{
			// Properties
	        $search_url = 'https://www.dezrez.com/DRApp/DotNetSites/WebEngine/property/Default.aspx';
			$fields = array_merge($params, array(
				'apiKey' => urlencode($import_settings['api_key']),
				'eaid' => urlencode($import_settings['eaid']),
				'sessionGUID' => urlencode($guid),
				'xslt' => urlencode('-1'),
			));
			if ( isset($import_settings['branch_ids']) && trim($import_settings['branch_ids']) != '' )
			{
				$fields['branchList'] = urlencode(str_replace(' ', '', $import_settings['branch_ids']));
			}

			$fields_string = '';
			foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
			$fields_string = rtrim($fields_string, '&');

			$search_url = $search_url . '?' . $fields_string;

			$response = wp_remote_get( $search_url, array( 'timeout' => 120 ) );
			if ( !is_wp_error($response) && is_array( $response ) ) 
			{
				$contents = $response['body'];
			}
    		else
    		{
    			$this->log_error( "Failed to obtain " . $department . " XML. Dump of response as follows: " . print_r($response, TRUE) );
	        	return false;
    		};

    		if ( wp_remote_retrieve_response_code($response) !== 200 )
            {
                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties: ' . wp_remote_retrieve_response_message($response) );
                return false;
            }

    		$xml = simplexml_load_string($contents);

			if ($xml !== FALSE)
			{
				$this->log("Parsing properties");
				
	            $properties_imported = 0;

	            $properties_xml_array = array();

	            if (isset($xml->propertySearchSales->properties))
				{
					$this->log("Parsing sales properties");

					if (isset($xml->propertySearchSales->properties->property))
					{
						$properties_xml_array = $xml->propertySearchSales->properties->property;
					}
				}
	            
				if (isset($xml->propertySearchLettings->properties))
				{
					$this->log("Parsing lettings properties");

					if (isset($xml->propertySearchLettings->properties->property))
					{
						$properties_xml_array = $xml->propertySearchLettings->properties->property;
					}
				}

				$this->log("Found " . count($properties_xml_array) . " properties in XML array ready for parsing");

				foreach ($properties_xml_array as $property)
				{
					$attributes = $property->attributes();
					$property_id = (string)$attributes['id'];

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
					            ),
					            array(
					            	'key' => '_on_market',
					            	'value' => 'yes'
					            )
				            )
				        );
				        $property_query = new WP_Query($args);
				        
				        if ($property_query->have_posts())
				        {
				        	while ($property_query->have_posts())
				        	{
				        		$property_query->the_post();

			                	$dezrez_last_updated = (string)$attributes['updated'];

			                	$last_imported_date = get_post_meta( get_the_ID(), '_dezrez_xml_update_date_' . $this->import_id, TRUE);
		                		if ($last_imported_date == $dezrez_last_updated )
		                		{
		                			$ok_to_import = false;
		                		}
			                }
		                }
		           	}

	                if ( $test !== true && $ok_to_import === true )
	                {
						$property_url = 'https://www.dezrez.com/DRApp/DotNetSites/WebEngine/property/Property.aspx';
						$fields = array(
							'apiKey' => urlencode($import_settings['api_key']),
							'eaid' => urlencode($import_settings['eaid']),
							'sessionGUID' => urlencode($guid),
							'xslt' => urlencode('-1'),
							'pid' => $property_id
						);

						//url-ify the data for the POST
						$fields_string = '';
						foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
						$fields_string = rtrim($fields_string, '&');

						$contents = '';
						if ( ini_get('allow_url_fopen') )
						{
			    			$contents = file_get_contents($property_url . '?' . $fields_string);
			    		}
			    		elseif ( function_exists('curl_version') )
						{
							$curl = curl_init();
						    curl_setopt($curl, CURLOPT_URL, $property_url . '?' . $fields_string);
						    curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
						    $contents = curl_exec($curl);
						    curl_close($curl);
			    		}
			    		else
			    		{
			    			die("Neither allow_url_fopen nor cURL is active on your server");
			    		}
						
						$property_xml = simplexml_load_string($contents);

						if ($property_xml !== FALSE)
						{
							if (isset($property_xml->propertyFullDetails->property))
							{
								$property_attributes = $property_xml->propertyFullDetails->property->attributes();
								if ((string)$property_attributes['deleted'] != 'true')
								{
									$property_xml->propertyFullDetails->property->addChild('summaryDescription', (string)$property->summaryDescription);

									$this->properties[] = $property_xml->propertyFullDetails->property;
								}
							}
						}
					}
					else
					{
						// Property not been updated.
						// Lets create our own XML so at least the property gets put into the $this->properties array
						$xml = '<?xml version="1.0" standalone="yes"?>
	<response>
	<propertyFullDetails>
	<property id="' . $property_id . '" fake="yes">
	<dummy></dummy>
	</property>
	</propertyFullDetails>
	</response>';
						$property_xml = new SimpleXMLElement($xml);

						$this->properties[] = $property_xml->propertyFullDetails->property;
					}
				}
	        }
	        else
	        {
	        	// Failed to parse XML
	        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML' );

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

        do_action( "propertyhive_pre_import_properties_dezrez_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_dezrez_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_dezrez_xml", $property, $this->import_id, $this->instance_id );
            
			$property_attributes = $property->attributes();

			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property_attributes['id'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property_attributes['id'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property_attributes['id'], false );

			if ( !isset($property_attributes['fake']) )
			{
				$property_address = $property->address;
				$property_media = $property->media;
				$property_text = $property->text;

				$this->log( 'Importing property ' . $property_row . ' with reference ' . (string)$property_attributes['id'], 0, (string)$property_attributes['id'], '', false );

				$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

				list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property_attributes['id'], $property, (string)$property_address->useAddress, (string)$property->summaryDescription);

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

					$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property_attributes['id'] );

					update_post_meta( $post_id, $imported_ref_key, (string)$property_attributes['id'] );

					update_post_meta( $post_id, '_property_import_data', $property->asXML() );

					// Address
					update_post_meta( $post_id, '_reference_number', '' );
					update_post_meta( $post_id, '_address_name_number', (string)$property_address->num );
					update_post_meta( $post_id, '_address_street', (string)$property_address->sa1 );
					update_post_meta( $post_id, '_address_two', (string)$property_address->sa2 );
					update_post_meta( $post_id, '_address_three', trim( (string)$property_address->town . ' ' . (string)$property_address->city ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property_address->county) ) ? (string)$property_address->county : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property_address->postcode) ) ? (string)$property_address->postcode : '' ) );

					$country = 'GB';
					if ( isset($property_address->country) && (string)$property_address->country != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower((string)$property_address->country) == strtolower($country_details['name']) )
							{
								$country = $country_code;
								break;
							}
						}
						if ( $country == '' )
						{
							switch (strtolower((string)$property_address->country))
							{
								case "uk": { $country = 'GB'; break; }
							}
						}
					}
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_dezrez_xml_address_fields_to_check', array('town', 'city', 'county') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property_address->{$address_field}) && trim((string)$property_address->{$address_field}) != '' ) 
						{
							$term = term_exists( trim((string)$property_address->{$address_field}), 'location');
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
					update_post_meta( $post_id, '_latitude', ( ( isset($property_attributes['latitude']) ) ? (string)$property_attributes['latitude'] : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property_attributes['longitude']) ) ? (string)$property_attributes['longitude'] : '' ) );

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );

					$office_id = $this->primary_office_id;
					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( $branch_code == (string)$property_attributes['bid'] )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = ((string)$property_attributes['sale'] != 'true') ? 'residential-lettings' : 'residential-sales';

					$commercial_property_types = array("70");
					$commercial_property_types = apply_filters( 'propertyhive_dezrez_xml_commercial_property_types', $commercial_property_types );

					if ( 
						isset($property_attributes['propertyType']) && 
						in_array((string)$property_attributes['propertyType'], $commercial_property_types) &&
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
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['bid'] . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['bid'] . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['bid'] . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['bid']]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['bid']] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['bid']]);
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
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property_attributes['bedrooms']) ) ? (string)$property_attributes['bedrooms'] : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property_attributes['bathrooms']) ) ? (string)$property_attributes['bathrooms'] : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property_attributes['receptions']) ) ? (string)$property_attributes['receptions'] : '' ) );

					// Property Type
					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
		            $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property_attributes['propertyType']) && (string)$property_attributes['propertyType'] != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property_attributes['propertyType']]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property_attributes['propertyType']], $prefix . 'property_type' );
			            }
			            else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property_attributes['propertyType'] . ') that is not mapped', $post_id, (string)$property_attributes['id'] );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property_attributes['propertyType'], $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

		            $price = (string)$property_attributes['priceVal'];

		            $poa = '';
					if (
						isset($property_attributes['POA']) && 
						(string)$property_attributes['POA'] == 'true'
					)
					{
						$poa = 'yes';
					}
					update_post_meta( $post_id, '_poa', $poa );

					// Residential Sales Details
					if ( $department == 'residential-sales' || $department == 'residential-lettings' )
					{
						if ( (string)$property_attributes['sale'] == 'true' )
						{
							update_post_meta( $post_id, '_price', $price );
							update_post_meta( $post_id, '_price_actual', $price );

							update_post_meta( $post_id, '_currency', 'GBP' );

							// Price Qualifier
							$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

							if ( !empty($mapping) && isset($property_text->pricetext) && isset($mapping[(string)$property_text->pricetext]) )
							{
				                wp_set_object_terms( $post_id, (int)$mapping[(string)$property_text->pricetext], 'price_qualifier' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
				            }

				            // Tenure
				            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

							if ( !empty($mapping) && isset($property_attributes['leaseType']) && isset($mapping[(string)$property_attributes['leaseType']]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[(string)$property_attributes['leaseType']], 'tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'tenure' );
				            }
						}
						elseif ( (string)$property_attributes['sale'] != 'true' )
						{
							update_post_meta( $post_id, '_rent', $price );

							$rent_frequency = 'pcm';
							$price_actual = $price;

							if ( isset($property_attributes['rentalperiod']) )
							{
								switch ( (string)$property_attributes['rentalperiod'] )
								{	
									case "2": { break; } // per day
									case "3": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; } // per week
									case "4": { $rent_frequency = 'pcm'; $price_actual = $price; break; } // per month
									case "5": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; } // per quarter
									case "6": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; } // per year
								}
							}

							update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
							update_post_meta( $post_id, '_price_actual', $price_actual );

							update_post_meta( $post_id, '_currency', 'GBP' );

							update_post_meta( $post_id, '_deposit', '' );
		            		update_post_meta( $post_id, '_available_date', '' );

		            		// We don't receive furnished options in the feed
						}
					}
					elseif ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

						if ( (string)$property_attributes['sale'] == 'true' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', $poa );
						}
						elseif ( (string)$property_attributes['sale'] != 'true' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    update_post_meta( $post_id, '_rent_from', $price );
		                    update_post_meta( $post_id, '_rent_to', $price );

		                    $rent_frequency = 'pcm';
		                    if ( isset($property_attributes['rentalperiod']) )
							{
								switch ( (string)$property_attributes['rentalperiod'] )
								{	
									case "2": { break; } // per day
									case "3": { $rent_frequency = 'pw'; break; } // per week
									case "4": { $rent_frequency = 'pcm'; break; } // per month
									case "5": { $rent_frequency = 'pq'; break; } // per quarter
									case "6": { $rent_frequency = 'pa'; break; } // per year
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

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
	                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', ( isset($property_attributes['featured']) && (string)$property_attributes['featured'] == 'true' ) ? 'yes' : '' );
					}
					
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					$availability_term_id = '';
					if (
						((string)$property_attributes['sold'] == '0' || (string)$property_attributes['sold'] == '1')
						&&
						(string)$property_attributes['sale'] == 'true'
						&&
						isset($mapping['Available (Sales)'])
					)
					{
						$availability_term_id = $mapping['Available (Sales)'];
					}
					if (
						((string)$property_attributes['sold'] == '0' || (string)$property_attributes['sold'] == '1')
						&&
						(string)$property_attributes['sale'] != 'true'
						&&
						isset($mapping['Available (Lettings)'])
					)
					{
						$availability_term_id = $mapping['Available (Lettings)'];
					}
					if (
						(string)$property_attributes['UO_LA'] == 'true'
						&&
						(string)$property_attributes['sale'] == 'true'
						&&
						isset($mapping['Under Offer'])
					)
					{
						$availability_term_id = $mapping['Under Offer'];
					}
					if (
						(string)$property_attributes['UO_LA'] == 'true'
						&&
						(string)$property_attributes['sale'] != 'true'
						&&
						isset($mapping['Let Agreed'])
					)
					{
						$availability_term_id = $mapping['Let Agreed'];
					}
					if (
						(string)$property_attributes['sold'] == '2'
						&&
						(string)$property_attributes['sale'] == 'true'
						&&
						isset($mapping['Sold STC'])
					)
					{
						$availability_term_id = $mapping['Sold STC'];
					}
					if (
						(string)$property_attributes['sold'] == '2'
						&&
						(string)$property_attributes['sale'] != 'true'
						&&
						isset($mapping['Let Agreed'])
					)
					{
						$availability_term_id = $mapping['Let Agreed'];
					}

					if ( $availability_term_id != '' )
					{
		                wp_set_object_terms( $post_id, (int)$availability_term_id, 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Rooms
		            if ( $department != 'commercial' )
					{
			            $previous_room_count = get_post_meta( $post_id, '_rooms', TRUE );
				        $new_room_count = 0;
			            if (
		                	isset($property_text->areas)
		                )
						{
							foreach ($property_text->areas->area as $xml_area)
							{
								if (isset($xml_area->feature))
								{
									foreach ($xml_area->feature as $xml_feature)
									{
										update_post_meta( $post_id, '_room_name_' . $new_room_count, (string)$xml_feature->heading );
							            update_post_meta( $post_id, '_room_dimensions_' . $new_room_count, '' );
							            update_post_meta( $post_id, '_room_description_' . $new_room_count, (string)$xml_feature->description );

								        ++$new_room_count;
									}
								}
							}
						}
						update_post_meta( $post_id, '_rooms', $new_room_count );
					}
					else
					{
						$previous_room_count = get_post_meta( $post_id, '_descriptions', TRUE );
				        $new_room_count = 0;
			            if (
		                	isset($property_text->areas)
		                )
						{
							foreach ($property_text->areas->area as $xml_area)
							{
								if (isset($xml_area->feature))
								{
									foreach ($xml_area->feature as $xml_feature)
									{
										update_post_meta( $post_id, '_description_name_' . $new_room_count, (string)$xml_feature->heading );
							            update_post_meta( $post_id, '_description_' . $new_room_count, (string)$xml_feature->description );

								        ++$new_room_count;
									}
								}
							}
						}
						update_post_meta( $post_id, '_descriptions', $new_room_count );
					}

					// Media - Images
				    $media = array();
				    if ( isset($property_media->picture) && !empty($property_media->picture) )
					{
						foreach ( $property_media->picture as $picture )
						{
							$picture_attributes = $picture->attributes();

							if ( 
								trim((string)$picture) != '' &&
								((string)$picture_attributes['categoryID'] == '1' || (string)$picture_attributes['categoryID'] == '2')
							)
							{
								$url = (string)$picture . '&width=';
								$url .= apply_filters( 'propertyhive_dezrez_xml_image_width', '2048' );

								$media[] = array(
									'url' => $url,
									'filename' => (string)$property_attributes['id'] . '_' . (string)$picture_attributes['id'] . '.jpg',
									'description' => ( ( isset($picture_attributes['caption']) && (string)$picture_attributes['caption'] != '' ) ? (string)$picture_attributes['caption'] : '' ),
									'modified' => $picture_attributes['updated']
								);
							}
						}
					}

					$this->import_media( $post_id, (string)$property_attributes['id'], 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property_media->picture) && !empty($property_media->picture) )
					{
						foreach ( $property_media->picture as $picture )
						{
							$picture_attributes = $picture->attributes();

							if ( 
								trim((string)$picture) != '' &&
								((string)$picture_attributes['categoryID'] == '3')
							)
							{
								$url = (string)$picture . '&width=';
								if ( strpos(strtolower($url), 'width=') === FALSE )
								{
									// If no width passed then set to 2048
									$url .= '&width=';
									$url .= apply_filters( 'propertyhive_dezrez_xml_floorplan_width', '2048' );
								}

								$media[] = array(
									'url' => $url,
									'filename' => (string)$property_attributes['id'] . '_' . (string)$picture_attributes['id'] . '.jpg',
									'description' => ( ( isset($picture_attributes['caption']) && (string)$picture_attributes['caption'] != '' ) ? (string)$picture_attributes['caption'] : '' ),
									'modified' => $picture_attributes['updated']
								);
							}
						}
					}

					$this->import_media( $post_id, (string)$property_attributes['id'], 'floorplan', $media, true );

					// Media - Brochures
				    $media = array();
				    if ( isset($property_media->document) && !empty($property_media->document) )
					{
						foreach ( $property_media->document as $document )
						{
							$document_attributes = $document->attributes();

							if ( 
								trim((string)$document) != '' &&
								((string)$document_attributes['category'] == 'brochure') &&
								((string)$document_attributes['source'] == 'document-location-url')
							)
							{
								$url = (string)$document;

								$media[] = array(
									'url' => $url,
									'filename' => (string)$property_attributes['id'] . '_brochure_' . count($media_ids) . '.pdf',
								);
							}
						}
					}

					$this->import_media( $post_id, (string)$property_attributes['id'], 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if ( isset($property_media->picture) && !empty($property_media->picture) )
					{
						foreach ( $property_media->picture as $picture )
						{
							$picture_attributes = $picture->attributes();

							if ( 
								trim((string)$picture) != '' &&
								((string)$picture_attributes['category'] == 'EER' || (string)$picture_attributes['category'] == 'EIR')
							)
							{
								$url = (string)$picture;
								if ( strpos(strtolower($url), 'width=') === FALSE )
								{
									// If no width passed then set to 500
									$url .= '&width=';
									$url .= apply_filters( 'propertyhive_dezrez_xml_epc_width', '500' );
								}

								$media[] = array(
									'url' => $url,
									'filename' => (string)$property_attributes['id'] . '_' . (string)$picture_attributes['id'] . '.jpg',
									'description' => ( ( isset($picture_attributes['caption']) && (string)$picture_attributes['caption'] != '' ) ? (string)$picture_attributes['caption'] : '' ),
								);
							}
						}
					}

					$this->import_media( $post_id, (string)$property_attributes['id'], 'epc', $media, false );

					// Media - Virtual Tours
					$virtual_tours = array();
					if ( isset($property_media->virtualtour) && !empty($property_media->virtualtour) )
	                {
	                	if ( !is_array($property_media->virtualtour) )
	                	{
	                		// If theres only one it's treated as a string so turn into an array
	                		$property_media->virtualtour = array( (string)$property_media->virtualtour );
	                	}
	                	if ( !empty($property_media->virtualtour) )
	                	{
		                    foreach ( $property_media->virtualtour as $virtualtour )
		                    {
		                        $virtual_tours[] = (string)$virtualtour;
		                    }
		                }
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ( $virtual_tours as $i => $virtual_tour )
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property_attributes['id'] );

					update_post_meta( $post_id, '_dezrez_xml_update_date_' . $this->import_id, (string)$property_attributes['updated'] );

					do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
					do_action( "propertyhive_property_imported_dezrez_xml", $post_id, $property, $this->import_id );

					$post = get_post( $post_id );
					do_action( "save_post_property", $post_id, $post, false );
					do_action( "save_post", $post_id, $post, false );

					if ( $inserted_updated == 'updated' )
					{
						$this->compare_meta_and_taxonomy_data( $post_id, (string)$property_attributes['id'], $metadata_before, $taxonomy_terms_before );
					}
				}

				++$property_row;
			}
			else
			{
				$args = array(
		            'post_type' => 'property',
		            'posts_per_page' => 1,
		            'post_status' => 'any',
		            'meta_query' => array(
		            	array(
			            	'key' => $imported_ref_key,
			            	'value' => (string)$property_attributes['id']
			            )
		            )
		        );
		        $property_query = new WP_Query($args);
		        
		        if ($property_query->have_posts())
		        {
		        	// We've imported this property before
		            while ($property_query->have_posts())
		            {
		                $property_query->the_post();

		                $post_id = get_the_ID();

		                update_post_meta( $post_id, '_on_market', 'yes' );
		            }
		        }
			}

		} // end foreach property

		do_action( "propertyhive_post_import_properties_dezrez_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$property_attributes = $property->attributes();
			$import_refs[] = (string)$property_attributes['id'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'availability' => array(
                'Available (Sales)' => 'Available (Sales)',
                'Available (Lettings)' => 'Available (Lettings)',
                'Under Offer' => 'Under Offer',
                'Sold STC' => 'Sold STC',
                'Let Agreed' => 'Let Agreed',
            ),
            'property_type' => array(
                10 => 'Apartment',
                58 => 'Apartment (Low Density)',
                59 => 'Apartment (Studio)',
                57 => 'Building Plot',
                60 => 'Business',
                56 => 'Cluster',
                70 => 'Commercial',
                61 => 'Corner Townhouse',',',
                40 => 'Detached (Barn Conversion)',
                15 => 'Detached (Bungalow)',
                39 => 'Detached (Chalet)',
                23 => 'Detached (Cottage)',
                30 => 'Detached (Country House)',
                5 => 'Detached (House)',
                29 => 'Detached (Town House)',
                52 => 'Duplex Apartment',
                33 => 'East Wing (Country House)',
                17 => 'End Link (Bungalow)',
                7 => 'End Link (House)',
                12 => 'End Terrace (Bungalow)',
                36 => 'End Terrace (Chalet)',
                20 => 'End Terrace (Cottage)',
                2 => 'End Terrace (House)',
                26 => 'End Terrace (Town House)',
                47 => 'First Floor Converted (Flat)',
                44 => 'First Floor Purpose Built (Flat)',
                50 => 'First &amp; Second Floor (Maisonette)',
                9 => 'Flat',
                49 => 'Ground &amp; First Floor (Maisonette)',
                46 => 'Ground Floor Converted (Flat)',
                43 => 'Ground Floor Purpose Built (Flat)',
                66 => 'Link Detached',
                53 => 'Mansion',
                68 => 'Maisonette',
                42 => 'Mews Style (Barn Conversion)',
                18 => 'Mid Link (Bungalow)',
                8 => 'Mid Link (House)',
                13 => 'Mid Terrace (Bungalow)',
                37 => 'Mid Terrace (Chalet)',
                21 => 'Mid Terrace (Cottage)',
                3 => 'Mid Terrace (House)',
                27 => 'Mid Terrace (Town House)',
                31 => 'North Wing (Country House)',
                51 => 'Penthouse Apartment',
                54 => 'Q-Type',
                41 => 'Remote Detached (Barn Conversion)',
                16 => 'Remote Detached (Bungalow)',
                24 => 'Remote Detached(Cottage)',
                6 => 'Remote Detached (House)',
                48 => 'Second Floor Converted (Flat)',
                45 => 'Second Floor Purpose Built (Flat)',
                14 => 'Semi-Detached (Bungalow)',
                38 => 'Semi-Detached(Chalet)',
                22 => 'Semi-Detached(Cottage)',
                4 => 'Semi-Detached (House)',
                28 => 'Semi-Detached (Town House)',
                69 => 'Shell',
                32 => 'South Wing (Country House)',
                67 => 'Studio',
                11 => 'Terraced (Bungalow)',
                35 => 'Terraced (Chalet)',
                19 => 'Terraced (Cottage)',
                1 => 'Terraced (House)',
                25 => 'Terraced (Town House)',
                55 => 'T-Type',
                65 => 'Village House',
                62 => 'Villa (Detached)',
                63 => 'Villa (Link-Detached)',
                64 => 'Villa (Semi-Detached)',
                34 => 'West Wing (Country House)',
                71 => 'Retirement Flat',
                72 => 'Bedsit',
                73 => 'Park Home/Mobile Home'
            ),
            'commercial_property_type' => array(
                70 => 'Commercial',
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'Fixed Price' => 'Fixed Price',
                'Offers in Excess of' => 'Offers in Excess of',
                'OIRO' => 'OIRO',
                'Sale by Tender' => 'Sale by Tender',
                'From' => 'From',
                'Shared Ownership' => 'Shared Ownership',
                'Offers Over' => 'Offers Over',
                'Part Buy Part Rent' => 'Part Buy Part Rent',
                'Shared Equity' => 'Shared Equity',
            ),
            'tenure' => array(
                '1' => 'Not Applicable',
                '3' => 'Freehold',
                '5' => 'Freehold (to be confirmed)',
                '2' => 'Leasehold',
                '4' => 'Leasehold (to be confirmed)',
                '6' => 'To be Advised',
                '7' => 'Share of Leasehold',
                '8' => 'Share of Freehold',
                '9' => 'Flying Freehold',
                '11' => 'Leasehold (Share of Freehold)',
            ),
            'furnished' => array(

            ),
        );
	}
}

}