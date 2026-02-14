<?php
/**
 * Class for managing the import process of a Domus XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Domus_XML_Import extends PH_Property_Import_Process {

	/**
	 * @var array
	 */
	private $featured_properties = array();

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

		// Featured Properties
        if ( $test === false )
        {
	        $this->log("Obtaining featured properties");

			$contents = '';

			$response = wp_remote_get( $import_settings['xml_url'] . '/featured', array( 'timeout' => 120, 'sslverify' => FALSE ) );
			if ( !is_wp_error($response) && is_array( $response ) ) 
			{
				$contents = $response['body'];
			}
			else
			{
				$this->log_error( 'Failed to obtain featured XML file. Dump of response as follows: ' . print_r($response, TRUE) );
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
				$this->featured_properties = array();

				$this->log("Parsing featured properties");
				
				foreach ($xml->property as $property)
				{
			        $this->featured_properties[] = (string)$property->id;
	            } // end foreach property
	        }
	        else
	        {
	        	// Failed to parse XML
	        	$this->log_error( 'Failed to parse featured XML file. Possibly invalid XML: ' . print_r($contents, true) );

	        	return false;
	        }
	    }

		// Sales Properties
		$this->log("Obtaining sales properties");

		$contents = '';

		$response = wp_remote_get( $import_settings['xml_url'] . '/search?items=9999&includeUnavailable=true', array( 'timeout' => 120, 'sslverify' => FALSE ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( 'Failed to obtain sales XML file. Dump of response as follows: ' . print_r($response, TRUE) );
			return false;
		}

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			$this->log("Parsing sales properties");
			
			foreach ($xml->property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

				if ( isset($property->status) && ( (string)$property->status == 'Sold' || (string)$property->status == 'Let' ) )
				{

				}
				else
				{
					if ( $test === false )
					{
		                $property = $this->get_property( (string)$property->id );

						if ( $property !== FALSE )
						{
				            $this->properties[] = $property;
			            }
			        }
			        else
			        {
			        	$this->properties[] = (string)$property->id;
			        }
		        }
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse sales XML file. Possibly invalid XML: ' . print_r($contents, true) );

        	return false;
        }

        // Lettings Properties
        $this->log("Obtaining lettings properties");

		$contents = '';

		$response = wp_remote_get( $import_settings['xml_url'] . '/search?sales=false&items=9999&includeUnavailable=true', array( 'timeout' => 120, 'sslverify' => FALSE ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( 'Failed to obtain lettings XML file. Dump of response as follows: ' . print_r($response, TRUE) );
			return false;
		}

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			$this->log("Parsing lettings properties");
			
			foreach ($xml->property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

				if ( isset($property->status) && ( (string)$property->status == 'Sold' || (string)$property->status == 'Let' ) )
				{

				}
				else
				{
					if ( $test === false )
					{
		                $property = $this->get_property( (string)$property->id );

						if ( $property !== FALSE )
						{
				            $this->properties[] = $property;
			            }
			        }
			        else
			        {
			        	$this->properties[] = (string)$property->id;
			        }
		        }
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse lettings XML file. Possibly invalid XML: ' . print_r($contents, true) );

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

	private function get_property( $id )
	{
		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$contents = '';

		$response = wp_remote_get( $import_settings['xml_url'] . '/property?propertyID=' . $id, array( 'timeout' => 120, 'sslverify' => FALSE ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( 'Failed to obtain property XML file ' . $import_settings['xml_url'] . '/property?propertyID=' . $id . '. Dump of response as follows: ' . print_r($response, TRUE) );
			return false;
		}

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			return $xml;
		}
		else
		{
			// Failed to parse XML
        	$this->log_error( 'Failed to parse property XML file from ' . $import_settings['xml_url'] . '/property?propertyID=' . $id . '. Possibly invalid XML' );

        	return false;
		}
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_domus_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_domus_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_domus_xml", $property, $this->import_id, $this->instance_id );
            
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

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, (string)$property->address->advertising, ( ( isset($property->description) ) ? (string)$property->description : '' ) );

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

				update_post_meta( $post_id, '_property_import_data', utf8_encode($property->asXML()) );

				// Address
				update_post_meta( $post_id, '_reference_number', ( ( isset($property->reference) ) ? (string)$property->reference : '' ) );
				update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property->address->name) ) ? (string)$property->address->name : '' ) . ' ' . ( ( isset($property->address->number) ) ? (string)$property->address->number : '' ) ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property->address->street) ) ? (string)$property->address->street : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property->address->locality) ) ? (string)$property->address->locality : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->address->town) ) ? (string)$property->address->town : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->address->county) ) ? (string)$property->address->county : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->address->postcode) ) ? (string)$property->address->postcode : '' ) );

				$country = get_option( 'propertyhive_default_country', 'GB' );
				if ( isset($property->address->country) && (string)$property->address->country != '' && class_exists('PH_Countries') )
				{
					$ph_countries = new PH_Countries();
					foreach ( $ph_countries->countries as $country_code => $country_details )
					{
						if ( strtolower((string)$property->address->country) == strtolower($country_details['name']) )
						{
							$country = $country_code;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = apply_filters( 'propertyhive_domus_xml_address_fields_to_check', array('locality', 'town', 'county') );
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

				// Coordinates
				if ( isset($property->address->latitude) && isset($property->address->longitude) && (string)$property->address->latitude != '' && (string)$property->address->longitude != '' && (string)$property->address->latitude != '0' && (string)$property->address->longitude != '0' )
				{
					update_post_meta( $post_id, '_latitude', ( ( isset($property->address->latitude) ) ? (string)$property->address->latitude : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property->address->longitude) ) ? (string)$property->address->longitude : '' ) );
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
						if ( trim($property->address->name) != '' ) { $address_to_geocode[] = (string)$property->address->name; }
						if ( trim($property->address->number) != '' ) { $address_to_geocode[] = (string)$property->address->number; }
						if ( trim($property->address->street) != '' ) { $address_to_geocode[] = (string)$property->address->street; }
						if ( trim($property->address->locality) != '' ) { $address_to_geocode[] = (string)$property->address->locality; }
						if ( trim($property->address->town) != '' ) { $address_to_geocode[] = (string)$property->address->town; }
						if ( trim($property->address->county) != '' ) { $address_to_geocode[] = (string)$property->address->county; }
						if ( trim($property->address->postcode) != '' ) { $address_to_geocode[] = (string)$property->address->postcode; $address_to_geocode_osm[] = (string)$property->address->postcode; }

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode_osm, $country );
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
						if ( $branch_code == (string)$property->branchID )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-sales';
				if ( isset($property->sale) &&  (string)$property->sale == 'false' )
				{
					$department = 'residential-lettings';
				}
				update_post_meta( $post_id, '_department', $department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedrooms) ) ? (string)$property->bedrooms : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathrooms) ) ? (string)$property->bathrooms : '' ) );
				update_post_meta( $post_id, '_reception_rooms', '' );

				$prefix = '';

				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property->type) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->type]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->type], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->type . ') that is not mapped', $post_id, (string)$property->id );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->type, $post_id );
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
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', '' );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($property->pricequalifier) && isset($mapping[(string)$property->pricequalifier]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->pricequalifier], 'price_qualifier' );
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
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					switch ((string)$property->rentFrequency)
					{
						case "per month": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						case "per week": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_deposit', '' );
            		update_post_meta( $post_id, '_available_date', '' );
				}

				// Marketing
				$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', ( ( isset($property->status) && ( (string)$property->status == 'Sold' || (string)$property->status == 'Let' ) ) ? '' : 'yes' ) );
                }
                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
				if ( $featured_by_default === true )
				{
					update_post_meta( $post_id, '_featured', ( in_array((string)$property->id, $this->featured_properties) ? 'yes' : '' ) );
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
				if ( isset($property->features) && !empty($property->features) )
				{
					foreach ( $property->features as $property_features )
					{
						foreach ( $property_features as $feature )
						{
							$features[] = trim((string)$feature);
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

		        // Rooms
		        // For now put the whole description in one room
		        $i = 0;
		        if ( isset($property->description) && (string)$property->description != '' )
		        {
		        	update_post_meta( $post_id, '_room_name_' . $i, '' );
		            update_post_meta( $post_id, '_room_dimensions_' . $i, '' );
		            update_post_meta( $post_id, '_room_description_' . $i, (string)$property->description );

		            ++$i;
		        }
		        if ( isset($property->location) && (string)$property->location != '' )
		        {
		        	update_post_meta( $post_id, '_room_name_' . $i, 'Location' );
		            update_post_meta( $post_id, '_room_dimensions_' . $i, '' );
		            update_post_meta( $post_id, '_room_description_' . $i, (string)$property->location );

		            ++$i;
		        }
		        if (isset($property->floors) && !empty($property->floors))
                {
                    foreach ($property->floors as $floors)
                    {
                        if (!empty($floors->floor))
                        {
                            foreach ($floors->floor as $floor)
                            {
                            	if (isset($floor->rooms) && !empty($floor->rooms))
				                {
				                    foreach ($floor->rooms as $rooms)
				                    {
				                        if (!empty($rooms->room))
				                        {
				                            foreach ($rooms->room as $room)
				                            {
				                            	update_post_meta( $post_id, '_room_name_' . $i, ( ( isset($room->name) ) ? (string)$room->name : '' ) );
									            update_post_meta( $post_id, '_room_dimensions_' . $i, ( ( isset($room->size) ) ? (string)$room->size : '' ) );
									            update_post_meta( $post_id, '_room_description_' . $i, ( ( isset($room->description) ) ? (string)$room->description : '' ) );

									            ++$i;
				                            }
				                        }
				                    }
				                }
                            }
                        }
                    }
                }
                if ( isset($property->additional) && (string)$property->additional != '' )
		        {
		        	update_post_meta( $post_id, '_room_name_' . $i, '' );
		            update_post_meta( $post_id, '_room_dimensions_' . $i, '' );
		            update_post_meta( $post_id, '_room_description_' . $i, (string)$property->additional );

		            ++$i;
		        }

	            update_post_meta( $post_id, '_rooms', $i );

	            // Media - Images
			    $media = array();
			    if (isset($property->photos) && !empty($property->photos))
                {
                    foreach ($property->photos as $photos)
                    {
                        if (!empty($photos->photo))
                        {
                            foreach ($photos->photo as $photo)
                            {
                            	$modified = ( (isset($photo->modified)) ? (string)$photo->modified : '' );
								if ( !empty($modified) )
								{
									$dateTime = new DateTime($modified);
									$modified = $dateTime->format('Y-m-d H:i:s');
								}

								$url = (string)$photo->url;
								if ( isset($photo->largeurl) && (string)$photo->largeurl != '' )
								{
									$url = (string)$photo->largeurl;
								}

								$media[] = array(
									'url' => $url,
									'description' => ( (isset($photo->caption)) ? (string)$photo->caption : '' ),
									'modified' => $modified,
								);
							}
						}
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'photo', $media, true );

				// Media - Floorplans
			    $media = array();
			    if (isset($property->floorplans) && !empty($property->floorplans))
                {
                    foreach ($property->floorplans as $floorplans)
                    {
                        if (!empty($floorplans->floorplan))
                        {
                            foreach ($floorplans->floorplan as $floorplan)
                            {
                            	$modified = ( (isset($floorplan->modified)) ? (string)$floorplan->modified : '' );
								if ( !empty($modified) )
								{
									$dateTime = new DateTime($modified);
									$modified = $dateTime->format('Y-m-d H:i:s');
								}

								$media[] = array(
									'url' => (string)$floorplan->url,
									'description' => ( (isset($floorplan->caption)) ? (string)$floorplan->caption : '' ),
									'modified' => $modified,
								);
							}
						}
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, true );

				// Media - EPCs
			    $media = array();
			    if (isset($property->epcgraph) && (string)$property->epcgraph != '')
                {
					$media[] = array(
						'url' => (string)$property->epcgraph,
					);
				}

				$this->import_media( $post_id, (string)$property->id, 'epc', $media, false );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_domus_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_domus_xml" );

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
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Sold Subject to Contract' => 'Sold Subject to Contract',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Let Subject to Contract' => 'Let Subject to Contract',
                'Let' => 'Let',
            ),
            'property_type' => array(
                'Detached' => 'Detached',
                'Semi-Detached' => 'Semi-Detached',
                'End Terraced' => 'End Terraced',
                'Flat' => 'Flat',
                'Studio' => 'Studio',
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'Offers in Excess of' => 'Offers in Excess of',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
        );
	}
}

}