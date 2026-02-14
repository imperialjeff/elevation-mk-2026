<?php
/**
 * Class for managing the import process of a CASAFARI JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Casafari_JSON_Import extends PH_Property_Import_Process {

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

        $this->log("Parsing properties");

        $current_page = 1;
        $more_properties = true;

        $url = 'https://crmapi.casafaricrm.com/api/Property/ListProperties';
		if ( isset($import_settings['environment']) && $import_settings['environment'] == 'sandbox' )
		{
			$url = 'https://crmapi.proppydev.com/api/Property/ListProperties';
		}

		$limit = $this->get_property_limit();

        while ( $more_properties )
        {
            $headers = array(
                'Authorization' => 'Basic ' . $import_settings['api_token'],
                'Content-Type' => 'application/json'
            );

            $body = array(
                'PropertyIncludes' => array(
                    'IncludeFeatures' => true,
                    'IncludeBrokers' => true,
                    'IncludeAgency' => true,
                    'UseHtmlDescription' => true
                ),
                'Active' => true,
                'VisibleOnWebsite' => true,
                'Sold' => false,
                'MaxResponses' => 100,
                'SequenceNmbr' => $current_page,
                'Lang' => 'en',
            );

            $body = apply_filters( 'propertyhive_property_import_casafari_args', $body );

            $body = json_encode($body);

            $response = wp_remote_request(
                $url,
                array(
                    'method' => 'POST',
                    'timeout' => 360,
                    'headers' => $headers,
                    'body' => $body,
                )
            );

            if ( is_wp_error( $response ) )
            {
                $this->log_error( 'Response: ' . $response->get_error_message() );

                return false;
            }

            if ( wp_remote_retrieve_response_code($response) !== 200 )
            {
                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
                return false;
            }

            $json = json_decode( $response['body'], TRUE );

            if ($json !== FALSE)
            {
                $this->log("Parsing properties on page " . $current_page);

                if ( isset($json['Errors']) && !empty($json['Errors']) )
                {
                    foreach ( $json['Errors'] as $error )
                    {
                        $this->log_error( 'Error returned by CASAFARI: ' . print_r($error, TRUE) );
                    }
                    return false;
                }

                if ( isset($json['Count']) )
                {
                    $total_pages = ceil($json['Count'] / 100);
                    if ( $current_page >= $total_pages )
                    {
                        $more_properties = false;
                    }
                }
                else
                {
                    $this->log_error( 'No pagination element found in response. This should always exist so likely something went wrong. As a result we\'ll play it safe and not continue further.' );
                    
                    return false;
                }

                if ( isset($json['PropertyList']) )
                {
                    foreach ($json['PropertyList'] as $property)
                    {
                    	if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
		                {
		                    return true;
		                }

                        $this->properties[] = $property;
                    }
                }

                ++$current_page;
            }
            else
            {
                // Failed to parse JSON
                $this->log_error( 'Failed to parse JSON: ' . print_r($response['body'], true) );

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

        do_action( "propertyhive_pre_import_properties_casafari_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_casafari_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_casafari_json", $property, $this->import_id, $this->instance_id );
            
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

            $display_address = isset($property['locale'][0]['title']) ? $property['locale'][0]['title'] : '';
            if ( empty($display_address) )
            {
                $display_address = $property['location']['address'];
            }

            $post_excerpt = isset($property['locale'][0]['short']) ? $property['locale'][0]['short'] : '';
            $post_content = isset($property['locale'][0]['description']) ? $property['locale'][0]['description'] : '';
            if ( empty($post_content) && !empty($post_excerpt) )
            {
                $post_content = $post_excerpt;
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $post_excerpt );

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

                $previous_update_date = get_post_meta( $post_id, '_casafari_update_date_' . $this->import_id, TRUE);

                $skip_property = true;
                if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
                {
                    if (
                        $inserted_updated == 'inserted' ||
                        !isset($property['lastChangeDate']) ||
                        (
                            isset($property['lastChangeDate']) &&
                            empty($property['lastChangeDate'])
                        ) ||
                        $previous_update_date == '' ||
                        (
                            isset($property['lastChangeDate']) &&
                            $property['lastChangeDate'] != '' &&
                            $previous_update_date != '' &&
                            strtotime($property['lastChangeDate']) > strtotime($previous_update_date)
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
                    update_post_meta( $post_id, '_reference_number', $property['reference'] );

                    update_post_meta( $post_id, '_address_name_number', '' );
                    update_post_meta( $post_id, '_address_street', '' );
                    update_post_meta( $post_id, '_address_two', ( ( isset($property['location']['Locality']) ) ? $property['location']['Locality'] : '' ) );
                    update_post_meta( $post_id, '_address_three', ( ( isset($property['location']['City']) ) ? $property['location']['City'] : '' ) );
                    update_post_meta( $post_id, '_address_four', ( ( isset($property['location']['Region']) ) ? $property['location']['Region'] : '' ) );
                    update_post_meta( $post_id, '_address_postcode', ( ( isset($property['location']['zipcode']) ) ? $property['location']['zipcode'] : '' ) );

                    $country = get_option( 'propertyhive_default_country', 'ES' );
                    update_post_meta( $post_id, '_address_country', $country );

                    // Coordinates
                    $lat = '';
                    $lng = '';
                    if ( isset($property['location']['coordinates']['visible']) && $property['location']['coordinates']['visible'] === true )
                    {
                        $lat = $property['location']['coordinates']['latitude'];
                        $lng = $property['location']['coordinates']['longitude'];
                    }
                    update_post_meta( $post_id, '_latitude', $lat );
                    update_post_meta( $post_id, '_longitude', $lng  );

                    // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                    $address_fields_to_check = apply_filters( 'propertyhive_casafari_json_address_fields_to_check', array('Locality', 'City', 'Region') );
                    $location_term_ids = array();

                    foreach ( $address_fields_to_check as $address_field )
                    {
                        if ( isset($property['location'][$address_field]) && trim($property['location'][$address_field]) != '' ) 
                        {
                            $term = term_exists( trim($property['location'][$address_field]), 'location');
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
                    $negotiator_id = get_current_user_id();
                    update_post_meta( $post_id, '_negotiator_id', $negotiator_id );

                    $office_id = $this->primary_office_id;
                    update_post_meta( $post_id, '_office_id', $office_id );

                    $department = 'residential-sales';
                    if ( isset($property['businessType']) && in_array($property['businessType'], array('RentWeekly', 'Rent', 'Leasing')) )
                    {
                        $department = 'residential-lettings';
                    }

                    update_post_meta( $post_id, '_department', $department );

                    update_post_meta( $post_id, '_bedrooms', isset($property['bedrooms']) ? $property['bedrooms'] : '' );
                    update_post_meta( $post_id, '_bathrooms', isset($property['bathrooms']) ? $property['bathrooms'] : '' );
                    update_post_meta( $post_id, '_reception_rooms', '' );

                    $mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();

                    if ( isset($property['type']) && !empty($property['type']) )
                    {
                        if ( isset($mapping[$property['type']]) && !empty($mapping[$property['type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['type']], "property_type" );
                        }
                        else
                        {
                        	wp_delete_object_term_relationships( $post_id, 'property_type' );

                            $this->log( 'Property received with a type (' . $property['type'] . ') that is not mapped', $post_id, $property['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, 'property_type', $property['type'], $post_id );
                        }
                    }
                    else
                    {
                    	wp_delete_object_term_relationships( $post_id, 'property_type' );
                    }

                    // Residential Sales Details
                    if ( $department == 'residential-sales' )
                    {
                        $price = '';
                        if ( isset($property['price']) && !empty($property['price']) )
                        {
                            $price = round(preg_replace("/[^0-9.]/", '', $property['price']));
                        }

                        update_post_meta( $post_id, '_price', $price );

                        update_post_meta( $post_id, '_currency', 'EUR' );

                        $poa = ( isset($property['price_visible']) && $property['price_visible'] === false) ? 'yes' : '';
                        update_post_meta( $post_id, '_poa', $poa );
                    }
                    elseif ( $department == 'residential-lettings' )
                    {
                        $price = '';
                        if ( isset($property['price']) && !empty($property['price']) )
                        {
                            $price = round(preg_replace("/[^0-9.]/", '', $property['price']));
                        }

                        update_post_meta( $post_id, '_rent', $price );

                        $poa = ( isset($property['price_visible']) && $property['price_visible'] === false) ? 'yes' : '';
                        update_post_meta( $post_id, '_poa', $poa );

                        $rent_frequency = 'pcm';
                        if ( isset($property['businessType']) && in_array($property['businessType'], array('RentWeekly')) )
                        {
                            $rent_frequency = 'pw';
                        }
                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

                        update_post_meta( $post_id, '_currency', 'EUR' );

                        update_post_meta( $post_id, '_deposit', '' );
                        update_post_meta( $post_id, '_available_date', '' );
                    }

                    $ph_countries = new PH_Countries();
                    $ph_countries->update_property_price_actual( $post_id );

                    // Marketing
                    $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                    if ( $on_market_by_default === true )
                    {
                        update_post_meta( $post_id, '_on_market', 'yes' );
                    }
                    add_post_meta( $post_id, '_featured', 0, true );
                    
                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] :
                        array();

                    if ( 
                        !empty($mapping) &&
                        isset($property['businessTypeLocale']) &&
                        isset($mapping[$property['businessTypeLocale']])
                    )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['businessTypeLocale']], 'availability' );
                    }
                    else
                    {
                    	wp_delete_object_term_relationships( $post_id, 'availability' );
                    }

                    // Rooms
                    $rooms_count = 0;
                    if ( !empty($post_content) )
                    {
                        update_post_meta( $post_id, '_room_name_0', '' );
                        update_post_meta( $post_id, '_room_dimensions_0', '' );
                        update_post_meta( $post_id, '_room_description_0', $post_content );

                        ++$rooms_count;
                    }
                    update_post_meta( $post_id, '_rooms', $rooms_count );
                    
                    // Media - Images
    			    $media = array();
    			    if (isset($property['photos']) && !empty($property['photos']))
                    {
                        foreach ($property['photos'] as $image)
                        {
    						$media[] = array(
    							'url' => $image['Url'],
    						);
    					}
    				}

    				$this->import_media( $post_id, $property['id'], 'photo', $media, false );
                }
                else
                {
                    $this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
                }

                if ( isset($property['lastChangeDate']) ) { update_post_meta( $post_id, '_casafari_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['lastChangeDate'])) ); }

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_casafari_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;
        }

        do_action( "propertyhive_post_import_properties_casafari_json" );

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
                'For sale' => 'For sale',
            ),
            'lettings_availability' => array(
                'For rent' => 'For rent',
            ),
            'property_type' => array(
                "Apartment" => "Apartment",
                "Building" => "Building",
                "Castle" => "Castle",
                "Chalet" => "Chalet",
                "CommercialProperty" => "CommercialProperty",
                "Complex" => "Complex",
                "DuplexApartment" => "DuplexApartment",
                "Farmhouse" => "Farmhouse",
                "Garage" => "Garage",
                "Hotel" => "Hotel",
                "Land" => "Land",
                "Office" => "Office",
                "ParkingPlace" => "ParkingPlace",
                "Plot" => "Plot",
                "Ruin" => "Ruin",
                "StorageRoom" => "StorageRoom",
                "Townhouse" => "Townhouse",
                "Villa" => "Villa",
                "Warehouse" => "Warehouse",
                "Farm" => "Farm",
                "Studio" => "Studio",
                "UrbanLand" => "UrbanLand",
                "RestaurantsBarsShops" => "RestaurantsBarsShops",
                "ComercialShop" => "ComercialShop",
                "SemiDetached" => "SemiDetached",
                "RuralLand" => "RuralLand",
                "ManorHouse" => "ManorHouse",
                "LandWithProject" => "LandWithProject",
                "RestaurantSnack" => "RestaurantSnack",
                "CountryHouse" => "CountryHouse",
                "VillaFloor" => "VillaFloor",
                "VilaToBeRenovated" => "VilaToBeRenovated",
                "Room" => "Room",
                "Loft" => "Loft",
                "Business" => "Business"
            )
        );
    }
}

}