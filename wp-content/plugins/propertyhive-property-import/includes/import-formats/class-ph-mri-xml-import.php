<?php
/**
 * Class for managing the import process of an MRI XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_MRI_XML_Import extends PH_Property_Import_Process {

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

		$limit = $this->get_property_limit();

		$departments = array( 'RS', 'RL' );

		foreach ( $departments as $department )
		{
			$data = array(
		        'upw' => $import_settings['password'],
		        'de' => $department,
		        'pp' => 1000,
		    );

	  		$postvars = http_build_query($data);

			$response = wp_remote_post(
				$import_settings['url'],
				array(
					'method' => 'POST',
					'headers' => array(),
					'body' => $postvars,
					'timeout' => 300
			    )
			);

			if ( is_wp_error( $response ) ) 
			{
				$this->log_error( 'Failed to request properties: ' . $response->get_error_message() );
				return false;
			}

			$contents = simplexml_load_string($response['body']);

			if ( $contents === false )
			{
				$this->log_error( 'Failed to decode properties request body: ' . $response['body'] );
				return false;
			}

			if ( isset($contents->houses->property) && !empty($contents->houses->property) )
			{
				foreach ( $contents->houses->property as $property ) 
				{
					if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
	                {
	                    return true;
	                }

					// Get full details XML so we can obtain features and full description
					$response = wp_remote_get(
						str_replace("aspasia_search.xml", "xml_export.xml?prn=N&preg=N&pid=" . (string)$property->id, $import_settings['url']),
						array(
							'timeout' => 300
						)
					);

					if ( is_wp_error( $response ) ) 
					{
						$this->log_error( 'Failed to request property ' . (string)$property->id . ': ' . $response->get_error_message() );
						return false;
					}

					$single_property_contents = simplexml_load_string($response['body']);

					if ( $single_property_contents === false )
					{
						$this->log_error( 'Failed to decode property ' . (string)$property->id . ' request body: ' . $response['body'] );
						return false;
					}

					// Some feeds contain an outer containing node. Catch this to get the data correctly
					if ( property_exists($single_property_contents, 'PROPERTY') )
					{
						$single_property_contents = $single_property_contents->PROPERTY;
					}

					$property->latitude = $single_property_contents->ADDRESS->LATITUDE;
					$property->longitude = $single_property_contents->ADDRESS->LONGITUDE;

					$features_xml = $property->addChild('features');
					if ( isset($single_property_contents->SELLPOINTS) )
					{
						$feature_i = 0;
						foreach ( $single_property_contents->SELLPOINTS as $sellpoints )
						{
							foreach ( $sellpoints->PARA as $para )
							{
								//$features_xml->addChild('feature');
								$property->features[$feature_i] = (string)$para;
								++$feature_i;
							}
						}
					}

					$rooms_xml = $property->addChild('rooms');
					if ( isset($single_property_contents->ACCOMMODATION) )
					{
						$room_i = 0;
						foreach ( $single_property_contents->ACCOMMODATION as $accommodation )
						{
							foreach ( $accommodation->FLOOR as $floor )
							{
								foreach ( $floor->ROOM as $room )
								{
									$room_xml = $rooms_xml->addChild('room');
									$room_xml->addChild('name');
									$room_xml->name = (string)$room->TITLE;
									$room_xml->dimensions = ( property_exists($room, 'DIMENSIONS') ) ? (string)$room->DIMENSIONS : '';
									$description = array();
									$room_xml->addChild('description');
									foreach ( $room->PARA as $para )
									{
										$description[] = html_entity_decode((string)$para, ENT_QUOTES | ENT_HTML5);
									}
									$room_xml->description = implode("\n\n", $description);
									++$room_i;
								}
							}
						}
					}

					$extras_xml = $property->addChild('extras');
					if ( isset($single_property_contents->EXTRAS) )
					{
						$extra_i = 0;
						foreach ( $single_property_contents->EXTRAS as $extras )
						{
							foreach ( $extras->ITEM as $item )
							{
								$extra_xml = $extras_xml->addChild('extra');
								$extra_xml->addChild('name');
								$extra_xml->name = (string)$item->TITLE;

								$description = array();
								$extra_xml->addChild('description');
								foreach ( $item->PARA as $para )
								{
									$description[] = html_entity_decode((string)$para, ENT_QUOTES | ENT_HTML5);
								}
								$extra_xml->description = implode("\n\n", $description);

								++$extra_i;
							}
						}
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

        $price_qualifiers = array();
        $terms = get_terms( array(
		    'taxonomy' => 'price_qualifier',
		    'hide_empty' => false,
		    'fields' => 'names',
		) );
        if ( !empty($terms) )
        {
        	foreach ( $terms as $term )
        	{
        		$price_qualifiers[] = $term;
        	}
        }

        $geocoding_denied = apply_filters( 'propertyhive_mri_xml_import_prevent_geocoding', false, $this->import_id );

        do_action( "propertyhive_pre_import_properties_mri_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_mri_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_mri_xml", $property, $this->import_id, $this->instance_id );
            
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

			$property_attributes = $property->attributes();

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = (string)$property->address->display_address;
			$summary_description = '';
			if ( isset($property->property_summary->short_description->para) && !empty($property->property_summary->short_description->para) )
			{
				foreach ( $property->property_summary->short_description->para as $para )
				{
					if ( $summary_description != '' )
					{
						$summary_description .= "\n\n";
					}
					$summary_description .= (string)$para;
				}
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, $display_address, $summary_description );

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

				$previous_update_date = get_post_meta( $post_id, '_mri_xml_update_date_' . $this->import_id, TRUE);

				$skip_property = false;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if ( $previous_update_date == (string)$property_attributes['last_updated'] )
					{
						$skip_property = true;
					}
				}

				$country = 'GB';

				// Coordinates
				if ( isset($property->latitude) && isset($property->longitude) && (string)$property->latitude != '' && (string)$property->longitude != '' && (string)$property->latitude != '0' && (string)$property->longitude != '0' )
				{
					update_post_meta( $post_id, '_latitude', (string)$property->latitude );
					update_post_meta( $post_id, '_longitude', (string)$property->longitude );
				}
				else
				{
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( !$geocoding_denied && ($lat == '' || $lng == '' || $lat == '0' || $lng == '0') )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array();
						$address_to_geocode_osm = array();
						if ( trim($property->address->address1) != '' ) { $address_to_geocode[] = (string)$property->address->address1; }
						if ( trim($property->address->address2) != '' ) { $address_to_geocode[] = (string)$property->address->address2; }
						if ( trim($property->address->address3) != '' ) { $address_to_geocode[] = (string)$property->address->address3; }
						if ( trim($property->address->town) != '' ) { $address_to_geocode[] = (string)$property->address->town; }
						if ( trim($property->address->county) != '' ) { $address_to_geocode[] = (string)$property->address->county; }
						if ( trim($property->address->postcode) != '' ) { $address_to_geocode[] = (string)$property->address->postcode; $address_to_geocode_osm[] = (string)$property->address->postcode; }

						$geocoding_return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode_osm, $country );
						if ( $geocoding_return === 'denied' )
						{
							$geocoding_denied = true;
						}
					}
				}

				if ( !$skip_property )
				{
					update_post_meta( $post_id, $imported_ref_key, (string)$property->id );

					// Address
					update_post_meta( $post_id, '_reference_number', (string)$property->id );
					update_post_meta( $post_id, '_address_name_number', (string)$property->address->address1 );
					update_post_meta( $post_id, '_address_street', (string)$property->address->address2 );
					update_post_meta( $post_id, '_address_two', (string)$property->address->address3 );
					update_post_meta( $post_id, '_address_three', (string)$property->address->town );
					update_post_meta( $post_id, '_address_four', (string)$property->address->county );
					update_post_meta( $post_id, '_address_postcode', (string)$property->address->postcode );

					update_post_meta( $post_id, '_address_country', $country );

	            	// Let's just look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_mri_xml_address_fields_to_check', array('address3', 'town', 'county', 'property_location', 'location_town') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property->address->{$address_field}) && trim((string)$property->address->{$address_field}) != '' ) 
						{
							$term = term_exists( trim((string)$property->address->{$address_field}), 'location');
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

					$department = 'residential-sales';
					if ( (string)$property->department == 'RL' )
					{
						$department = 'residential-lettings';
					}

					$commercial_property_types = array("COMM");
					$commercial_property_types = apply_filters( 'propertyhive_mri_xml_commercial_property_types', $commercial_property_types );

					if ( 
						isset($property->extra_info->prty_code) && 
						in_array((string)$property->extra_info->prty_code, $commercial_property_types) &&
						get_option( 'propertyhive_active_departments_commercial' ) == 'yes'
					)
					{
						$department = 'commercial';
			        }

					update_post_meta( $post_id, '_department', $department );

					// Residential Details
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->property_summary->beds) ) ? (string)$property->property_summary->beds : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->property_summary->baths) ) ? (string)$property->property_summary->baths : '' ) );
					update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->property_summary->receptions) ) ? (string)$property->property_summary->receptions : '' ) );

					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property->extra_info->prty_code) && isset($property->extra_info->prst_code) )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->extra_info->prty_code . '-' . (string)$property->extra_info->prst_code]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->extra_info->prty_code . '-' . (string)$property->extra_info->prst_code], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property->extra_info->prty_code . '-' . (string)$property->extra_info->prst_code . ') that is not mapped', $post_id, (string)$property->id );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->extra_info->prty_code . '-' . (string)$property->extra_info->prst_code, $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					if ( $department == 'residential-sales' || $department == 'residential-lettings' )
					{
						$mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();

						if ( isset($property->extra_info->prpa_code) && !empty((string)$property->extra_info->prpa_code) )
						{
							if ( !empty($mapping) && isset($mapping[(string)$property->extra_info->prpa_code]) )
							{
								wp_set_object_terms( $post_id, (int)$mapping[(string)$property->extra_info->prpa_code], 'parking' );
							}
							else
							{
								wp_delete_object_term_relationships( $post_id, 'parking' );

								$this->log( 'Property received with a parking (' . (string)$property->extra_info->prpa_code . ') that is not mapped', $post_id, (string)$property->id );

								$import_settings = $this->add_missing_mapping( $mapping, 'parking', (string)$property->extra_info->prpa_code, $post_id );
							}
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, 'parking' );
						}
					}

					// Clean price
					$price = '';
					if ( isset($property->property_summary->price) && (string)$property->property_summary->price != '' )
					{
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->property_summary->price));
					}

					$price_text = (string)$property->property_summary->price_text;

					$poa = '';
					if ( strpos(strtolower($price_text), 'poa') !== FALSE || strpos(strtolower($price_text), 'price on application') !== FALSE || strpos(strtolower($price_text), 'rent on application') !== FALSE )
					{
						$poa = 'yes';
					}

					update_post_meta( $post_id, '_council_tax_band', ( isset($property->property_summary->council_tax_band) ? (string)$property->property_summary->council_tax_band : '' ) );

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );

						update_post_meta( $post_id, '_poa', $poa );

						// Tenure
						$mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

						if ( !empty($mapping) && isset($property->property_summary->tenure) && isset($mapping[(string)$property->property_summary->tenure]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->property_summary->tenure], 'tenure' );
			            }
			            else
						{
							wp_delete_object_term_relationships( $post_id, 'tenure' );
						}

						update_post_meta( $post_id, '_leasehold_years_remaining', ( isset($property->property_summary->remaining_lease) ? (string)$property->property_summary->remaining_lease : '' ) );
						update_post_meta( $post_id, '_service_charge', ( ( isset($property->property_summary->service_charges) && !empty((string)$property->property_summary->service_charges) ) ? (string)$property->property_summary->service_charges : '' ) );
						update_post_meta( $post_id, '_ground_rent', ( ( isset($property->property_summary->ground_rent) && !empty((string)$property->property_summary->ground_rent) ) ? (string)$property->property_summary->ground_rent : '' ) );
						
						// Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						$price_qualifier_set = false;
						foreach ( $mapping as $mapping_key => $mapping_value)
						{
							if ( strpos($price_text, $mapping_key) !== FALSE && !empty($mapping_value) )
							{
								wp_set_object_terms( $post_id, (int)$mapping_value, 'price_qualifier' );
								$price_qualifier_set = true;
								break;
							}
						}

						if ( !$price_qualifier_set )
						{
							wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
						}
					}
					elseif ( $department == 'residential-lettings' )
					{
						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						if ( strpos( strtolower((string)$property->property_summary->price_text), 'week') !== FALSE )
						{
							$rent_frequency = 'pw';
						}

						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', (string)$property->property_summary->price_monthly );
						
						update_post_meta( $post_id, '_poa', $poa );

						update_post_meta( $post_id, '_deposit', (string)$property->property_summary->deposit );

						$available_date = '';
						if ( (string)$property->property_summary->available_from != '' )
						{
							$explode_available_date = explode("/", (string)$property->property_summary->available_from);
							$available_date = $explode_available_date[2] . '-' . $explode_available_date[1] . '-' . $explode_available_date[0];
						}
	            		update_post_meta( $post_id, '_available_date', $available_date );

	            		// Furnished
	            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

	            		if ( !empty($mapping) && isset($property->property_summary->furnished) && isset($mapping[(string)$property->property_summary->furnished]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->property_summary->furnished], 'furnished' );
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

						if ( (string)$property->department == 'RS' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', $poa );
						}
						if ( (string)$property->department == 'RL' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

		                    update_post_meta( $post_id, '_rent_from', $price );
		                    update_post_meta( $post_id, '_rent_to', $price );

		                    $rent_frequency = 'pcm';
							if ( strpos( strtolower((string)$property->property_summary->price_text), 'week') !== FALSE )
							{
								$rent_frequency = 'pw';
							}
							elseif ( strpos( strtolower((string)$property->property_summary->price_text), 'ann') !== FALSE || strpos( strtolower((string)$property->property_summary->price_text), 'year') !== FALSE )
							{
								$rent_frequency = 'pa';
							}
							elseif ( strpos( strtolower((string)$property->property_summary->price_text), 'quart') !== FALSE )
							{
								$rent_frequency = 'pq';
							}
		                    update_post_meta( $post_id, '_rent_units', $rent_frequency );

		                    update_post_meta( $post_id, '_rent_poa', $poa );
						}

						// Store price in common currency (GBP) used for ordering
			            $ph_countries = new PH_Countries();
			            $ph_countries->update_property_price_actual( $post_id );

			            // Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						$price_qualifier_set = false;
						foreach ( $price_qualifiers as $price_qualifier )
						{
							if ( strpos($price_text, $price_qualifier) !== FALSE )
							{
								$price_qualifier_set = true;
								wp_set_object_terms( $post_id, (int)$mapping[$price_qualifier], 'price_qualifier' );
								break;
							}
						}

						if ( !$price_qualifier_set )
						{
							wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
						}

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
					//add_post_meta( $post_id, '_featured', '' );

					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					if ( !empty($mapping) && isset($property->property_summary->status) && isset($mapping[(string)$property->property_summary->status]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->property_summary->status], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
	        		$i = 0;
			        foreach ( $property->features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, (string)$feature );
			            ++$i;
			        }

			        update_post_meta( $post_id, '_features', $i );

			        // Rooms / Descriptions
			        $rooms = 0;
					if ( isset($property->property_summary->long_description->para) && !empty($property->property_summary->long_description->para) )
					{
						foreach ( $property->property_summary->long_description->para as $para )
						{
							update_post_meta( $post_id, '_room_name_' . $rooms, '' );
							update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
							update_post_meta( $post_id, '_room_description_' . $rooms, (string)$para );

							++$rooms;
						}
					}
				    if ( isset($property->rooms->room) && !empty($property->rooms->room) )
					{
						foreach ( $property->rooms->room as $room )
						{
							update_post_meta( $post_id, '_room_name_' . $rooms, (string)$room->name );
				            update_post_meta( $post_id, '_room_dimensions_' . $rooms, (string)$room->dimensions );
				            update_post_meta( $post_id, '_room_description_' . $rooms, (string)$room->description );

				            ++$rooms;
						}
				    }
				    if ( isset($property->extras) && !empty($property->extras) )
					{
						foreach ( $property->extras as $extras )
						{
							foreach ( $extras->extra as $extra )
							{
								update_post_meta( $post_id, '_room_name_' . $rooms, (string)$extra->name );
					            update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
					            update_post_meta( $post_id, '_room_description_' . $rooms, (string)$extra->description );

					            ++$rooms;
					        }
						}
				    }
			        update_post_meta( $post_id, '_rooms', $rooms );

			        // Media - Images
				    $media = array();
				    if (isset($property->images->pictures) && !empty($property->images->pictures))
	                {
	                    foreach ($property->images->pictures as $images)
	                    {
	                        if (!empty($images->picture))
	                        {
	                            foreach ($images->picture as $image)
	                            {
	                            	$media_attributes = $image->attributes();

									if ( 
										isset($media_attributes['type']) &&
										$media_attributes['type'] == 'image'
									)
									{
										$url = str_replace("http://", "https://", (string)$image);

										$media[] = array(
											'url' => $url,
											'description' => (string)$media_attributes['description'],
											'modified' => (string)$media_attributes['updated_date']
										);
									}
								}
							}
						}
					}
					for ( $i = 1; $i <= 99; ++$i )
					{
						if (isset($property->images->{'picture' . $i}) && !empty($property->images->{'picture' . $i}))
	                	{
	                		$media_attributes = $property->images->{'picture' . $i}->attributes();

							if ( isset($media_attributes['type']) && $media_attributes['type'] == 'image' )
							{
								// This is a URL
								$url = str_replace("http://", "https://", (string)$property->images->{'picture' . $i});

								$media[] = array(
									'url' => $url,
									'description' => (string)$media_attributes['description'],
									'modified' => (string)$media_attributes['updated_date']
								);
							}
	                	}
					}

					$this->import_media( $post_id, (string)$property->id, 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if (isset($property->images->floorplans) && !empty($property->images->floorplans))
	                {
	                    foreach ($property->images->floorplans as $images)
	                    {
	                        if (!empty($images->floorplan))
	                        {
	                            foreach ($images->floorplan as $image)
	                            {
	                            	$media_attributes = $image->attributes();

									$url = str_replace("http://", "https://", (string)$image);

									$media[] = array(
										'url' => $url,
										'description' => (string)$media_attributes['description'],
										'modified' => (string)$media_attributes['updated_date']
									);
								}
							}
						}
					}
					for ( $i = 1; $i <= 30; ++$i )
					{
						if (isset($property->images->{'floorplan' . $i}) && !empty($property->images->{'floorplan' . $i}))
	                	{
                        	$media_attributes = $property->images->{'floorplan' . $i}->attributes();

							// This is a URL
							$url = str_replace("http://", "https://", (string)$property->images->{'floorplan' . $i});

							$media[] = array(
								'url' => $url,
								'description' => (string)$media_attributes['description'],
								'modified' => (string)$media_attributes['updated_date']
							);
	                	}
					}

					$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, true );

					// Media - Brochures
				    $media = array();
				    if ( isset($property->links->brochure) && (string)$property->links->brochure != '' )
	                {
                    	$media_attributes = $property->links->brochure->attributes();

						// This is a URL
						$url = str_replace("http://", "https://", (string)$property->links->brochure);

						$media[] = array(
							'url' => $url,
							'modified' => (string)$media_attributes['updated_date']
						);
					}

					$this->import_media( $post_id, (string)$property->id, 'brochure', $media, true );

					// Media - EPCs
				    $media = array();
				    if (isset($property->images->epcs) && !empty($property->images->epcs))
	                {
	                    foreach ($property->images->epcs as $images)
	                    {
	                        if (!empty($images->epc))
	                        {
	                            foreach ($images->epc as $image)
	                            {
	                            	$media_attributes = $image->attributes();

									// This is a URL
									$url = str_replace("http://", "https://", (string)$image);

									$explode_url = explode("?", $url);
									$filename = basename( $explode_url[0] );

									if ( strpos($filename, '.') === FALSE )
									{
										$filename .= '.jpg';
									}

									$media[] = array(
										'url' => $url,
										'filename' => $filename,
										'description' => (string)$media_attributes['description'],
										'modified' => (string)$media_attributes['updated_date']
									);
								}
							}
						}
					}
					for ( $i = 1; $i <= 30; ++$i )
					{
						if (isset($property->images->{'epc' . $i}) && !empty($property->images->{'epc' . $i}))
	                	{
                        	$media_attributes = $property->images->{'epc' . $i}->attributes();

							if ( 
								substr( strtolower((string)$property->images->{'epc' . $i}), 0, 2 ) == '//' || 
								substr( strtolower((string)$property->images->{'epc' . $i}), 0, 4 ) == 'http'
							)
							{
								// This is a URL
								$url = (string)$property->images->{'epc' . $i};

								$description = isset($media_attributes['description']) ? (string)$media_attributes['description'] : '';

								$modified = (string)$media_attributes['updated_date'];

								$explode_url = explode("?", $url);
								$filename = basename( $explode_url[0] );

								if ( strpos($filename, '.') === FALSE )
								{
									$filename .= '.jpg';
								}

								$media[] = array(
									'url' => $url,
									'filename' => $filename,
									'description' => (string)$media_attributes['description'],
									'modified' => (string)$media_attributes['updated_date']
								);
							}
						}
					}

					$this->import_media( $post_id, (string)$property->id, 'epc', $media, true );

					// Media - Virtual Tours
					$virtual_tours = array();
					if (isset($property->links->virtual_tour) && (string)$property->links->virtual_tour)
	                {
	                    $virtual_tours[] = (string)$property->links->virtual_tour;
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->id );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->id );
				}
				
				update_post_meta( $post_id, '_mri_xml_update_date_' . $this->import_id, (string)$property_attributes['last_updated'] );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_mri_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_mri_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->id;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'AVAI' => 'For Sale',
                'REACTIVATE' => 'Reactivated',
                'UO' => 'Under Offer',
                'SSTC' => 'Sold STC',
            ),
            'lettings_availability' => array(
                'ARGAV' => 'Available arranging tenancy',
                'AV_LET' => 'Available to let',
                'LETSTC' => 'Let - Subject to references',
                'LET' => 'Let',
                'QUBEUNAVIL' => 'Unavailable',
            ),
            'commercial_availability' => array(
                'AVAI' => 'For Sale',
                'REACTIVATE' => 'Reactivated',
                'UO' => 'Under Offer',
                'ARGAV' => 'Available arranging tenancy',
                'AV_LET' => 'Available to let',
                'LETSTC' => 'Let - Subject to references',
                'LET' => 'Let',
                'QUBEUNAVIL' => 'Unavailable',
            ),
            'property_type' => array(
                'HOUSE-DETATCH' => 'House - Detached',
                'HOUSE-SEMID' => 'House - Semi Detached',
                'HOUSE-TERRACED' => 'House - Terraced',
                'FLATT-GRNDFLR' => 'Flat - Ground Floor',
                'FLATT-1STFLR' => 'Flat - First Floor',
            ),
            'commercial_property_type' => array(
                'COMM-OFFICE' => 'Commercial - Office',
            ),
            'price_qualifier' => array(
                'Price' => 'Price',
                'Guide Price' => 'Guide Price',
                'Asking Price' => 'Asking Price',
                'Offers Over' => 'Offers Over',
                'Offers In Excess Of' => 'Offers In Excess Of',
            ),
            'tenure' => array(
                'F' => 'Freehold',
                'L' => 'Leasehold',
            ),
            'parking' => array(
                'GARA' => 'Single Garage',
                'SINGLE2' => 'Single Garage x 2',
                'ALLO' => 'Allocated Parking',
                'CARP' => 'Carport',
                'DGAR' => 'Double Garage',
                'DLG' => 'Double Length Garage',
                'DRIV' => 'Drive',
                'NOP' => 'No Parking',
                'OFF' => 'Off Road Parking',
                'PARK' => 'Parking',
                'PERM' => 'Parking Permit',
                'STRT' => 'Street Parking',
                'TRIP' => 'Triple Garage',
            ),
            'furnished' => array(
                'F' => 'Furnished',
                'O' => 'Optional',
                'P' => 'Part Furnished',
                'U' => 'Unfurnished',
            ),
        );
	}
}

}