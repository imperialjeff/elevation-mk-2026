<?php
/**
 * Class for managing the import process of a Kyero XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Kyero_XML_Import extends PH_Property_Import_Process {


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
			if ( isset( $_POST['url'] ) ) 
			{
			    $import_settings['url'] = sanitize_url( wp_unslash( $_POST['url'] ) );
			}
		}

		$contents = '';

		$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 600, 'sslverify' => false ) );
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

		$this->properties = array(); // Reset properties in the event we're importing multiple files

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			
            $properties_imported = 0;
            
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

        $geocoding_denied = apply_filters( 'propertyhive_kyero_xml_import_prevent_geocoding', false, $this->import_id );

        do_action( "propertyhive_pre_import_properties_kyero_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_kyero_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_kyero_xml", $property, $this->import_id, $this->instance_id );
            
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

			$display_address = (string)$property->location_detail;
			if ( $display_address == '' )
			{
				$display_address = (string)$property->town;
			}
			if ( $display_address == '' )
			{
				$display_address = (string)$property->province;
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, $display_address, (string)$property->desc->en );

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

				update_post_meta( $post_id, $imported_ref_key, (string)$property->id );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_kyero_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property->date) ||
						(
							isset($property->date) &&
							trim((string)$property->date) == ''
						) ||
						$previous_update_date == '' ||
						(
							isset($property->date) &&
							(string)$property->date != '' &&
							$previous_update_date != '' &&
							strtotime((string)$property->date) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', (string)$property->ref );
					update_post_meta( $post_id, '_address_name_number', '' );
					update_post_meta( $post_id, '_address_street', '' );
					update_post_meta( $post_id, '_address_two', '' );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->town) ) ? (string)$property->town : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->province) ) ? (string)$property->province : '' ) );
					update_post_meta( $post_id, '_address_postcode', '' );

					$country = 'GB';
					$currency = 'GBP';
					if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower((string)$property->country) == strtolower($country_details['name']) )
							{
								$country = $country_code;
								$currency = $country_details['currency_code'];
								break;
							}
						}
					}
					else
					{
						$country = get_option( 'propertyhive_default_country', 'GB' );
					}
					update_post_meta( $post_id, '_address_country', $country );

	            	// Let's just look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_kyero_xml_address_fields_to_check', array('town', 'province', 'location_detail'), $this->import_id );
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

					// Coordinates
					if ( isset($property->location->latitude) && isset($property->location->longitude) && (string)$property->location->latitude != '' && (string)$property->location->longitude != '' && (string)$property->location->latitude != '0' && (string)$property->location->longitude != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property->location->latitude) ) ? (string)$property->location->latitude : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property->location->longitude) ) ? (string)$property->location->longitude : '' ) );
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
							if ( isset($property->town) && trim((string)$property->town) != '' ) { $address_to_geocode[] = (string)$property->town; }
							if ( isset($property->province) && trim((string)$property->province) != '' ) { $address_to_geocode[] = (string)$property->province; }

							$geocoding_return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode, $country );
							if ( $geocoding_return === 'denied' )
							{
								$geocoding_denied = true;
							}
						}
					}

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
						
					$office_id = $this->primary_office_id;
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = 'residential-sales';
					if ( isset($property->price_freq) && ( (string)$property->price_freq == 'week' || (string)$property->price_freq == 'month' ) )
					{
						$department = 'residential-lettings';
					}

					update_post_meta( $post_id, '_department', $department );

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
		    		{
		    			$explode_agent_branch = array();
		    			
		    			if ( isset($this->branch_mappings[$this->import_id]) )
		    			{
		    				$explode_agent_branch = explode("|", $this->branch_mappings[$this->import_id]);
		    			}

		    			if ( !empty($explode_agent_branch) )
						{
							update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
							update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );
						}
						else
						{
							update_post_meta( $post_id, '_agent_id', '' );
							update_post_meta( $post_id, '_branch_id', '' );
						}
		    		}

					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->beds) ) ? (string)$property->beds : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->baths) ) ? (string)$property->baths : '' ) );
					update_post_meta( $post_id, '_reception_rooms', '' );

					$prefix = '';
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property->type) )
					{
						if ( $property->type->en )
						{
							$property_type = (string)$property->type->en;
						}
						else
						{
							$property_type = (string)$property->type;
						}
						if ( !empty($mapping) && isset($mapping[$property_type]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[$property_type], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . $property_type . ') that is not mapped', $post_id, (string)$property->id );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property_type, $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						// Clean price
						$price = '';
						if ( isset($property->price) && is_numeric((string)$property->price) )
						{
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
						}

						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_poa', '' );

						if ( isset($property->currency) && (string)$property->currency != '' )
						{
							$currency = (string)$property->currency;
						}
						update_post_meta( $post_id, '_currency', $currency );
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = '';
						if ( isset($property->price) && is_numeric((string)$property->price) )
						{
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
						}

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						switch ((string)$property->price_freq)
						{
							case "month": { $rent_frequency = 'pcm'; break; }
							case "week": { $rent_frequency = 'pw'; break; }
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						
						update_post_meta( $post_id, '_poa', '' );

						if ( isset($property->currency) && (string)$property->currency != '' )
						{
							$currency = (string)$property->currency;
						}
						update_post_meta( $post_id, '_currency', $currency );

						update_post_meta( $post_id, '_deposit', '' );
	            		update_post_meta( $post_id, '_available_date', '' );
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
					add_post_meta( $post_id, '_featured', '', true );

					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					$status = 'Sales';
					if ( $department == 'residential-lettings' )
					{
						$status = 'Lettings';
					}
					if ( !empty($mapping) && isset($mapping[$status]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$status], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					if ( isset($property->features) && !empty($property->features) )
					{
						foreach ( $property->features->feature as $feature )
						{
							$features[] = trim((string)$feature);
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
					update_post_meta( $post_id, '_rooms', '0' );
					/*update_post_meta( $post_id, '_room_name_0', '' );
		            update_post_meta( $post_id, '_room_dimensions_0', '' );
		            update_post_meta( $post_id, '_room_description_0', '' );*/

		            // Media - Images
				    $media = array();
				    if (isset($property->images) && !empty($property->images))
	                {
	                    foreach ($property->images as $images)
	                    {
	                        if (!empty($images->image))
	                        {
	                            foreach ($images->image as $image)
	                            {
	                            	if ( isset($image->tags->tag) && (string)$image->tags->tag == 'floorplan' )
										continue;

									$url = trim((string)$image->url);

									$media_attributes = $image->attributes();
									$modified = isset($media_attributes['modified']) ? (string)$media_attributes['modified'] : '';

									$explode_url = explode("?", $url);
									$filename = basename( $explode_url[0] );
									if ( strpos($filename, '.php') !== FALSE || strpos($filename, '.asp') !== FALSE )
									{
										$filename = str_replace(".php", ".jpg", $filename);
										$filename = str_replace(".asp", ".jpg", $filename);
									}

									$media[] = array(
										'url' => $url,
										'filename' => $filename,
										'modified' => $modified,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->id, 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if (isset($property->images) && !empty($property->images))
	                {
	                    foreach ($property->images as $images)
	                    {
	                        if (!empty($images->image))
	                        {
	                            foreach ($images->image as $image)
	                            {
	                            	if ( isset($image->tags->tag) && (string)$image->tags->tag == 'floorplan' )
	                            	{
										$url = trim((string)$image->url);

										$media_attributes = $image->attributes();
										$modified = isset($media_attributes['modified']) ? (string)$media_attributes['modified'] : '';

										$explode_url = explode("?", $url);
										$filename = basename( $explode_url[0] );
										if ( strpos($filename, '.php') !== FALSE || strpos($filename, '.asp') !== FALSE )
										{
											$filename = str_replace(".php", ".jpg", $filename);
											$filename = str_replace(".asp", ".jpg", $filename);
										}

										$media[] = array(
											'url' => $url,
											'filename' => $filename,
											'modified' => $modified,
										);
									}
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, true );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->id );
				}

				$update_date = '';
				if ( isset($property->date) && (string)$property->date != '' )
				{
					$update_date = date("Y-m-d H:i:s", strtotime((string)$property->date));
				}
				update_post_meta( $post_id, '_kyero_update_date_' . $this->import_id, $update_date );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_kyero_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_kyero_xml", $this->import_id );

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
                'Sales' => 'Sales',
            ),
            'lettings_availability' => array(
                'Lettings' => 'Lettings',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Finca' => 'Finca',
                'Penthouse' => 'Penthouse',
                'Plot' => 'Plot',
                'Townhouse' => 'Townhouse',
                'Villa' => 'Villa',
            )
        );
	}
}

}