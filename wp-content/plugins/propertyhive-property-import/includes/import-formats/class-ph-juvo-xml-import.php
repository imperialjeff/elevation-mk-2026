<?php
/**
 * TODO: Check EPC and brochure ids as the same is used for brochure and EPC at the moment (no sufficient sample data to check this)
 * 
 * Class for managing the import process of a Juvo XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Juvo_XML_Import extends PH_Property_Import_Process {

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

		$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 120 ) );
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

		$xml = simplexml_load_string($contents);

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

        $geocoding_denied = false;

        do_action( "propertyhive_pre_import_properties_juvo_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_juvo_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_juvo_xml", $property, $this->import_id, $this->instance_id );
            
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

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, (string)$property->advertise_address, (string)$property->summary, '', (string)$property->created_date );

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

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->ref );
				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', '' );
				update_post_meta( $post_id, '_address_two', '' );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->town) ) ? (string)$property->town : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->county) ) ? (string)$property->county : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

				$country = 'GB';
				update_post_meta( $post_id, '_address_country', $country );

            	$address_fields_to_check = apply_filters( 'propertyhive_juvo_xml_address_fields_to_check', array('town', 'county') );
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
				$lat = get_post_meta( $post_id, '_latitude', TRUE);
				$lng = get_post_meta( $post_id, '_longitude', TRUE);

				if ( !$geocoding_denied && ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' ) )
				{
					// No lat lng. Let's get it
					$address_to_geocode = array();
					$address_to_geocode_osm = array();
					if ( trim($property->town) != '' ) { $address_to_geocode[] = (string)$property->town; }
					if ( trim($property->county) != '' ) { $address_to_geocode[] = (string)$property->county; }
					if ( trim($property->postcode) != '' ) { $address_to_geocode[] = (string)$property->postcode; $address_to_geocode_osm[] = (string)$property->postcode; }

					$return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode_osm, $country );
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
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-sales';
				if ( (string)$property->category_id == '2' )
				{
					$department = 'residential-lettings';
				}
				elseif ( (string)$property->category_id == '3' )
				{
					$department = 'commercial';
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

				if ( isset($property->type_name) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->type_name]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->type_name], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->type_name . ') that is not mapped', $post_id, (string)$property->id );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->type_name, $post_id );
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
					update_post_meta( $post_id, '_poa', ( isset($property->price_label) && strtolower((string)$property->price_label) == 'price on application' ) ? 'yes' : '' );
					update_post_meta( $post_id, '_currency', 'GBP' );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
					
					if ( !empty($mapping) && isset($property->price_label) && isset($mapping[(string)$property->price_label]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->price_label], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					switch ((string)$property->price_label)
					{
						case "Monthly": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						case "Weekly": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
						case "Yearly": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
						case "Quarterly": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					update_post_meta( $post_id, '_currency', 'GBP' );
					
					update_post_meta( $post_id, '_poa', ( isset($property->price_label) && strtolower((string)$property->price_label) == 'price on application' ) ? 'yes' : '' );

					update_post_meta( $post_id, '_deposit', '' );
            		update_post_meta( $post_id, '_available_date', (string)$property->available_date );

            		// Furnished - not provided in XML
            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();
					
					if ( !empty($mapping) && isset($property->letting_furnished) && isset($mapping[(string)$property->letting_furnished]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->letting_furnished], 'furnished' );
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

            		$for_sale = true;
            		if ( in_array(strtolower((string)$property->price_label), array('monthly', 'weekly', 'yearly', 'quarterly')) )
            		{
            			$for_sale = false;
            		}

            		if ( $for_sale === true )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));
	                    update_post_meta( $post_id, '_price_from', $price );
	                    update_post_meta( $post_id, '_price_to', $price );
	                    update_post_meta( $post_id, '_price_units', '' );

	                    update_post_meta( $post_id, '_price_poa', ( isset($property->price_label) && strtolower((string)$property->price_label) == 'price on application' ) ? 'yes' : '' );

	                    // Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						if ( !empty($mapping) && isset($property->price_label) && isset($mapping[(string)$property->price_label]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->price_label], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }
	                }

	                if ( $for_sale !== true )
	                {
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    update_post_meta( $post_id, '_rent_from', $rent );
	                    update_post_meta( $post_id, '_rent_to', $rent );

	                    $rent_frequency = 'pcm';
						switch ((string)$property->price_label)
						{
							case "Monthly": { $rent_frequency = 'pcm'; break; }
							case "Weekly": { $rent_frequency = 'pw'; break; }
							case "Yearly": { $rent_frequency = 'pa'; break; }
							case "Quarterly": { $rent_frequency = 'pq'; break; }
						}
	                    update_post_meta( $post_id, '_rent_units', $rent_frequency);

	                    update_post_meta( $post_id, '_rent_poa', '' );
	                }

	                // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

		            $size = '';
		            update_post_meta( $post_id, '_floor_area_from', $size );
		            update_post_meta( $post_id, '_floor_area_from_sqft', $size );
		            update_post_meta( $post_id, '_floor_area_to', $size );
		            update_post_meta( $post_id, '_floor_area_to_sqft', $size );
		            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

		            update_post_meta( $post_id, '_site_area_from', $size );
		            update_post_meta( $post_id, '_site_area_from_sqft', $size );
		            update_post_meta( $post_id, '_site_area_to', $size );
		            update_post_meta( $post_id, '_site_area_to_sqft', $size );
		            update_post_meta( $post_id, '_site_area_units', 'sqft' );
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
					update_post_meta( $post_id, '_featured', ( isset($property->featured) && (string)$property->featured == '1' ) ? 'yes' : '' );
				}
			
				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->status_name) && isset($mapping[(string)$property->status_name]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->status_name], $prefix . 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, $prefix . 'availability' );
	            }

	            // Features
				$features = array();
				for ( $i = 1; $i <= 10; ++$i )
				{
					if ( isset($property->{'feature_' . $i}) && trim((string)$property->{'feature_' . $i}) != '' )
					{
						$features[] = trim((string)$property->{'feature_' . $i});
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
				update_post_meta( $post_id, '_rooms', '1' );
				update_post_meta( $post_id, '_room_name_0', '' );
	            update_post_meta( $post_id, '_room_dimensions_0', '' );
	            update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", (string)$property->description) );

	            // Media - Images
			    $media = array();
			    for ( $i = 0; $i <= 99; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '1'
					)
					{
						$media[] = array(
							'url' => (string)$property->assets->{'item' . $i}->url,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    for ( $i = 0; $i <= 99; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '2'
					)
					{
						$media[] = array(
							'url' => (string)$property->assets->{'item' . $i}->url,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, false );

				// Media - Brochures
			    $media = array();
			    for ( $i = 0; $i <= 99; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '4'
					)
					{
						$media[] = array(
							'url' => (string)$property->assets->{'item' . $i}->url,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'brochure', $media, false );

				// Media - EPCs
			    $media = array();
			    for ( $i = 0; $i <= 99; ++$i )
				{
					if ( 
						isset($property->assets->{'item' . $i}) && 
						isset($property->assets->{'item' . $i}->url) &&
						trim((string)$property->assets->{'item' . $i}->url) != '' &&
						isset($property->assets->{'item' . $i}->type_id) &&
						$property->assets->{'item' . $i}->type_id == '4'
					)
					{
						$media[] = array(
							'url' => (string)$property->assets->{'item' . $i}->url,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'epc', $media, false );

				// Media - Virtual Tours
				$virtual_tours = array();
				if (isset($property->video_url) && (string)$property->video_url != '')
                {
                    $virtual_tours[] = (string)$property->video_url;
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->id );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_juvo_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_juvo_xml" );

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
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Semi-Detached' => 'Semi-Detached',
                'Terraced' => 'Terraced',
                'End of Terrace' => 'End of Terrace',
                'Detached' => 'Detached',
                'Mews' => 'Mews',
                'Ground Flat' => 'Ground Flat',
                'Flat' => 'Flat',
                'Studio' => 'Studio',
                'Ground Maisonette' => 'Ground Maisonette',
                'Maisonette' => 'Maisonette',
                'Bungalow' => 'Bungalow',
                'Terraced Bungalow' => 'Terraced Bungalow',
                'Semi-Detached Bungalow' => 'Semi-Detached Bungalow',
                'Detached Bungalow' => 'Detached Bungalow',
                'Link Detached House' => 'Link Detached House',
            ),
            'commercial_property_type' => array(
                'Commercial Property' => 'Commercial Property',
                'Land' => 'Land',
                'Office' => 'Office',
                'Serviced Office' => 'Serviced Office',
            ),
            'price_qualifier' => array(
                'Fixed Price' => 'Fixed Price',
                'Guide Price' => 'Guide Price',
                'Offers In Excess Of' => 'Offers In Excess Of',
                'Shared Ownership' => 'Shared Ownership',
                'Offers In The Region Of' => 'Offers In The Region Of',
                'Price From' => 'Price From',
                'Part Buy Part Rent' => 'Part Buy Part Rent',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Furnished/Unfurnished' => 'Furnished/Unfurnished',
                'Part Furnished' => 'Part Furnished',
                'Unfurnished' => 'Unfurnished',
            ),
        );
	}
}

}