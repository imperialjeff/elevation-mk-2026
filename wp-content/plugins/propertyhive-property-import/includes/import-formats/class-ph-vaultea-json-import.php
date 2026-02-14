<?php
/**
 * Class for managing the import process of a VaultEA JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_VaultEA_JSON_Import extends PH_Property_Import_Process {

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

    private function get_server( $test = false )
    {
        if ( $test === false )
        {
            $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
        }
        else
        {
            $import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
        }

        if ( isset($import_settings['server']) && $import_settings['server'] == 'ap' )
        {
            return 'https://ap-southeast-2.api.vaultre.com.au/api/v1.3/';
        }

        return 'https://eu-west-1.api.vaultea.co.uk/api/v1.3/';
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
        $requests_per_chunk = apply_filters( 'propertyhive_property_import_vaultea_requests_per_chunk', 10 );
        $pause_between_requests = apply_filters( 'propertyhive_property_import_vaultea_pause_between_requests', 1 );

        $limit = $this->get_property_limit();

        $server = $this->get_server( $test );

        // List endpoints for getting both sales and lettings properties
        $endpoints = array(
            array(
                'uri' => 'properties/residential/sale',
                'department' => 'residential-sales',
                'portalStatus' => array( 'listing', 'conditional' )
            ),
            array(
                'uri' => 'properties/residential/lease',
                'department' => 'residential-lettings'
            ),
            array(
                'uri' => 'properties/commercial/sale',
                'department' => 'commercial',
                'portalStatus' => array( 'listing', 'conditional' )
            ),
            array(
                'uri' => 'properties/commercial/lease',
                'department' => 'commercial'
            ),
            array(
                'uri' => 'properties/land/sale',
                'department' => 'residential-sales',
                'portalStatus' => array( 'listing', 'conditional' )
            ),
        );

        $endpoints = apply_filters( 'propertyhive_property_import_vaultea_endpoints', $endpoints );

        foreach ( $endpoints as $endpoint )
        {
            $current_page = 1;
            $more_properties = true;

            while ( $more_properties )
            {
                ++$requests;
                if ( $requests % $requests_per_chunk ) { sleep($pause_between_requests); }

                $response = wp_remote_get( $server . $endpoint['uri'] . '?' . ( isset($endpoint['portalStatus']) ? 'portalStatus=' . implode(",", $endpoint['portalStatus']) . '&' : '' ) . 'publishedOnPortals=' . $import_settings['portal'] . '&pagesize=50&page=' . $current_page, array( 'timeout' => 120, 'headers' => array(
                    'accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Api-Key' => $import_settings['api_key'],
                    'Authorization' => 'Bearer ' . $import_settings['token'],
                ) ) );

                if ( !is_wp_error($response) && is_array( $response ) )
                {
                    $contents = $response['body'];

                    $json = json_decode( $contents, TRUE );

                    if ( $json !== FALSE && is_array($json) )
                    {
                        if ( isset($json['totalPages']) )
                        {
                            if ( $current_page >= $json['totalPages'] )
                            {
                                $more_properties = false;
                            }
                        }
                        else
                        {
                            $more_properties = false;
                        }

                        $this->log("Parsing properties from " . $endpoint['uri'] . " on page " . $current_page);

                        if ( !isset($json['items']) && isset($json['message']) && !empty($json['message']) )
                        {
                            $this->log_error( 'Response: ' . $json['message'] );
                            return false;
                        }

                        $this->log("Found " . count($json['items']) . " properties in JSON from " . $endpoint['uri'] . " ready for parsing");

                        foreach ($json['items'] as $property)
                        {
                            if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                            {
                                return true;
                            }

                            $property['department'] = $endpoint['department'];

                            if ( $test !== false )
                            {
                                $property['features'] = array();
                                $property['rooms'] = array();
                                $property['custom'] = array();

                                $explode_endpoint = explode("/", $endpoint['uri']);
                                $salelease = $explode_endpoint[count($explode_endpoint)-1];
                                $life_id = isset($property[$salelease . 'LifeId']) && !empty($property[$salelease . 'LifeId']) ? $property[$salelease . 'LifeId'] : '';

                                // custom
                                if ( apply_filters( 'propertyhive_property_import_vaultea_custom', false ) === true )
                                {
                                    ++$requests;
                                    if ( $requests % $requests_per_chunk ) { sleep($pause_between_requests); }

                                    $custom_response = wp_remote_get( $server . 'properties/residential/' . $salelease . '/' . $property['id'] . '/custom?pagesize=50', array( 'timeout' => 120, 'headers' => array(
                                        'accept' => 'application/json',
                                        'Content-Type' => 'application/json',
                                        'X-Api-Key' => $import_settings['api_key'],
                                        'Authorization' => 'Bearer ' . $import_settings['token'],
                                    ) ) );

                                    if ( !is_wp_error($custom_response) && is_array( $custom_response ) )
                                    {
                                        $custom_contents = $custom_response['body'];

                                        $custom_json = json_decode( $custom_contents, TRUE );

                                        if ( $custom_json !== FALSE && is_array($custom_json) && is_array($custom_json['items']) )
                                        {
                                            $property['custom'] = $custom_json['items'];
                                        }
                                    }
                                }
                                // end custom

                                if ( !empty($life_id) )
                                {
                                    // rooms
                                    ++$requests;
                                    if ( $requests % $requests_per_chunk ) { sleep($pause_between_requests); }

                                    $rooms_response = wp_remote_get( $server . 'properties/' . $property['id'] . '/' . $salelease . '/' . $life_id . '/rooms', array( 'timeout' => 120, 'headers' => array(
                                        'accept' => 'application/json',
                                        'Content-Type' => 'application/json',
                                        'X-Api-Key' => $import_settings['api_key'],
                                        'Authorization' => 'Bearer ' . $import_settings['token'],
                                    ) ) );

                                    if ( !is_wp_error($rooms_response) && is_array( $rooms_response ) )
                                    {
                                        $rooms_contents = $rooms_response['body'];

                                        $rooms_json = json_decode( $rooms_contents, TRUE );

                                        if ( $rooms_json !== FALSE && is_array($rooms_json) && is_array($rooms_json['items']) )
                                        {
                                            $property['rooms'] = $rooms_json['items'];
                                        }
                                    }
                                    // end rooms
                                }

                                ++$requests;
                                if ( $requests % $requests_per_chunk ) { sleep($pause_between_requests); }

                                $features_response = wp_remote_get( 'https://eu-west-1.api.vaultea.co.uk/api/v1.3/' . $endpoint['uri'] . '/' . $property['id'] . '', array( 'timeout' => 120, 'headers' => array(
                                    'accept' => 'application/json',
                                    'Content-Type' => 'application/json',
                                    'X-Api-Key' => $import_settings['api_key'],
                                    'Authorization' => 'Bearer ' . $import_settings['token'],
                                ) ) );

                                if ( !is_wp_error($features_response) && is_array( $features_response ) )
                                {
                                    $features_contents = $features_response['body'];

                                    $features_json = json_decode( $features_contents, TRUE );

                                    if ( $features_json !== FALSE && is_array($features_json) && is_array($features_json['highlights']) )
                                    {
                                        $property['features'] = $features_json['highlights'];
                                    }
                                }
                                // end features

                                // If lettings, ensure it doesn't exist in sales alredy
                                if ( $endpoint['department'] == 'residential-lettings' )
                                {
                                    foreach ( $this->properties as $existing_property )
                                    {
                                        if ( 
                                            $existing_property['department'] == 'residential-sales' && 
                                            $property['id'] == $existing_property['id'] 
                                        )
                                        {
                                            $property['id'] = $property['id'] . '-L';
                                        }
                                    }
                                }
                            }

                            $this->properties[] = $property;
                        }

                        ++$current_page;
                    }
                    else
                    {
                        // Failed to parse JSON
                        $this->log_error( 'Failed to parse JSON file for ' . $endpoint['uri'] . ': ' . print_r($contents, true) );
                        return false;
                    }
                }
                else
                {
                    $this->log_error( 'Failed to obtain JSON from ' . $endpoint . '. Dump of response as follows: ' . print_r($response, TRUE) );
                    return false;
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

        do_action( "propertyhive_pre_import_properties_vaultea_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_vaultea_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_vaultea_json", $property, $this->import_id, $this->instance_id );
            
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

            $this->log( 'Importing property ' . $property_row . ' with reference ' . $property['id'], 0, $property['id'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = ( isset($property['address']['displayAddress']) && !empty($property['address']['displayAddress']) ) ? trim($property['address']['displayAddress']) : trim($property['displayAddress']);

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $property['heading'], '', ( isset($property['inserted']) ) ? date( 'Y-m-d H:i:s', strtotime( $property['inserted'] )) : '' );

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

                $previous_update_date = get_post_meta( $post_id, '_vaultea_update_date_' . $this->import_id, TRUE);

                $skip_property = true;
                if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
                {
                    if (
                        $inserted_updated == 'inserted' ||
                        !isset($property['modified']) ||
                        (
                            isset($property['modified']) &&
                            empty($property['modified'])
                        ) ||
                        $previous_update_date == '' ||
                        (
                            isset($property['modified']) &&
                            $property['modified'] != '' &&
                            $previous_update_date != '' &&
                            strtotime($property['modified']) > strtotime($previous_update_date)
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
                    update_post_meta( $post_id, '_reference_number', ( ( isset($property['referenceID']) ) ? $property['referenceID'] : '' ) );

                    // Name number
                    $address_name_number = array();
                    if ( isset($property['address']['royalMail']['buildingName']) ) { $address_name_number[] = trim($property['address']['royalMail']['buildingName']); }
                    if ( isset($property['address']['royalMail']['buildingNumber']) ) { $address_name_number[] = trim($property['address']['royalMail']['buildingNumber']); }
                    if ( isset($property['address']['royalMail']['subbuildingNumber']) ) { $address_name_number[] = trim($property['address']['royalMail']['subbuildingNumber']); }
                    if ( isset($property['address']['royalMail']['subbuildingName']) ) { $address_name_number[] = trim($property['address']['royalMail']['subbuildingName']); }
                    
                    if ( isset($property['address']['unitNumber']) ) { $address_name_number[] = trim($property['address']['unitNumber']); }
                    if ( isset($property['address']['streetNumber']) ) { $address_name_number[] = trim($property['address']['streetNumber']); }

                    $address_name_number = array_filter($address_name_number);
                    $address_name_number = implode(" ", $address_name_number);
                    update_post_meta( $post_id, '_address_name_number', $address_name_number );

                    // Street
                    $address_street = array();
                    if ( isset($property['address']['royalMail']['thoroughfare']) ) { $address_street[] = trim($property['address']['royalMail']['thoroughfare']); }
                    if ( isset($property['address']['royalMail']['thoroughfare2']) ) { $address_street[] = trim($property['address']['royalMail']['thoroughfare2']); }
                    
                    if ( isset($property['address']['street']) ) { $address_street[] = trim($property['address']['street']); }
                    
                    $address_street = array_filter($address_street);
                    $address_street = implode(" ", $address_street);
                    update_post_meta( $post_id, '_address_street', $address_street );
                    
                    // Address 2
                    $address_two = array();
                    if ( isset($property['address']['royalMail']['locality']) ) { $address_two[] = trim($property['address']['royalMail']['locality']); }
                    if ( isset($property['address']['royalMail']['locality2']) ) { $address_two[] = trim($property['address']['royalMail']['locality2']); }

                    if ( isset($property['address']['suburb']['name']) ) { $address_two[] = trim($property['address']['suburb']['name']); }
                    
                    $address_two = array_filter($address_two);
                    $address_two = implode(" ", $address_two);
                    update_post_meta( $post_id, '_address_two', $address_two );

                    // Address 3
                    $address_three = array();
                    if ( isset($property['address']['royalMail']['postTown']) ) { $address_three[] = trim($property['address']['royalMail']['postTown']); }
                    
                    if ( isset($property['address']['suburb']['giDistrict']['name']) ) { $address_three[] = trim($property['address']['suburb']['giDistrict']['name']); }
                    
                    $address_three = array_filter($address_three);
                    $address_three = implode(" ", $address_three);
                    update_post_meta( $post_id, '_address_three', $address_three );

                    // Address 4
                    $address_four = array();

                    if ( isset($property['address']['state']['name']) ) { $address_four[] = trim($property['address']['state']['name']); }

                    $address_four = array_filter($address_four);
                    $address_four = implode(" ", $address_four);
                    update_post_meta( $post_id, '_address_four', $address_four );

                    // Postcode
                    $postcode = ( ( isset($property['address']['royalMail']['postcode']) ) ? $property['address']['royalMail']['postcode'] : '' );
                    if ( isset($property['address']['suburb']['postcode']) )
                    {
                        $postcode = $property['address']['suburb']['postcode'];
                    }
                    update_post_meta( $post_id, '_address_postcode', $postcode );

                    $country = get_option( 'propertyhive_default_country', 'GB' );
                    if ( isset($property['address']['country']['isocode']) && !empty($property['address']['country']['isocode']) )
                    {
                        $country = strtoupper($property['address']['country']['isocode']);
                    }
                    update_post_meta( $post_id, '_address_country', $country );

                    // Coordinates
                    if ( isset($property['geolocation']['latitude']) && isset($property['geolocation']['longitude']) && $property['geolocation']['latitude'] != '' && $property['geolocation']['longitude'] != '' && $property['geolocation']['latitude'] != '0' && $property['geolocation']['longitude'] != '0' )
                    {
                        update_post_meta( $post_id, '_latitude', $property['geolocation']['latitude'] );
                        update_post_meta( $post_id, '_longitude', $property['geolocation']['longitude'] );
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
                            if ( isset($property['address']['royalMail']['thoroughfare']) && trim($property['address']['royalMail']['thoroughfare']) != '' ) { $address_to_geocode[] = $property['address']['royalMail']['thoroughfare']; }
                            if ( isset($property['address']['royalMail']['thoroughfare2']) && trim($property['address']['royalMail']['thoroughfare2']) != '' ) { $address_to_geocode[] = $property['address']['royalMail']['thoroughfare2']; }
                            if ( isset($property['address']['royalMail']['postcode']) && trim($property['address']['royalMail']['postcode']) != '' ) { $address_to_geocode[] = $property['address']['royalMail']['postcode']; $address_to_geocode_osm[] = $property['address']['royalMail']['postcode']; }

                            $return = $this->do_geocoding_lookup( $post_id, $property['id'], $address_to_geocode, $address_to_geocode_osm, $country );
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
                            if ( strtolower(trim($branch_code)) == strtolower(trim($property['branch']['name'])) )
                            {
                                $office_id = $ph_office_id;
                                break;
                            }
                        }
                    }
                    update_post_meta( $post_id, '_office_id', $office_id );

                    // Residential Details
                    $department = $property['department'];
                    update_post_meta( $post_id, '_department', $department );

                    update_post_meta( $post_id, '_bedrooms', ( ( isset($property['bed']) ) ? $property['bed'] : '' ) );
                    update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bath']) ) ? $property['bath'] : '' ) );
                    update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['receptionRooms']) ) ? $property['receptionRooms'] : '' ) );

                    update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['councilTaxBand']) ) ? $property['councilTaxBand'] : '' ) );

                    $prefix = $department == 'commercial' ? 'commercial_' : '';
                    $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                    if ( isset($property['type']['name']) )
                    {
                        if ( !empty($mapping) && isset($mapping[$property['type']['name']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['type']['name']], $prefix . 'property_type' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                            $this->log( 'Property received with a type (' . $property['type']['name'] . ') that is not mapped', $post_id, $property['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['type']['name'], $post_id );
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
                        $price = round(preg_replace("/[^0-9.]/", '', $property['searchPrice']));

                        update_post_meta( $post_id, '_price', $price );
                        update_post_meta( $post_id, '_poa', ( ( isset($property['priceOnApplication']) && $property['priceOnApplication'] == true ) ? 'yes' : '') );
                        update_post_meta( $post_id, '_currency', 'GBP' );
                        
                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                        if ( !empty($mapping) && isset($property['priceQualifier']['name']) && isset($mapping[$property['priceQualifier']['name']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['priceQualifier']['name']], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                        }

                        // Tenure
                        $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

                        if ( !empty($mapping) && isset($property['tenureOrTitleType']['name']) && isset($mapping[$property['tenureOrTitleType']['name']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['tenureOrTitleType']['name']], 'tenure' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'tenure' );
                        }
                    }
                    elseif ( $department == 'residential-lettings' )
                    {
                        // Clean price
                        $price = round(preg_replace("/[^0-9.]/", '', $property['searchPrice']));

                        update_post_meta( $post_id, '_rent', $price );

                        $rent_frequency = 'pcm';
                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

                        update_post_meta( $post_id, '_poa', ( ( isset($property['priceOnApplication']) && $property['priceOnApplication'] == true ) ? 'yes' : '') );

                        update_post_meta( $post_id, '_deposit', ( ( isset($property['bondPrice']) ) ? $property['bondPrice'] : '' ) );
                        update_post_meta( $post_id, '_available_date', ( ( isset($property['availableDate']) ) ? $property['availableDate'] : '' ) );
                    }
                    elseif ( $department == 'commercial' )
                    {
                        update_post_meta( $post_id, '_for_sale', '' );
                        update_post_meta( $post_id, '_to_rent', '' );

                        if ( $property['commercialListingType'] == 'sale' || $property['commercialListingType'] == 'both' )
                        {
                            update_post_meta( $post_id, '_for_sale', 'yes' );

                            update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                            // Clean price
                            $price = round(preg_replace("/[^0-9.]/", '', $property['searchPrice']));

                            update_post_meta( $post_id, '_price_from', $price );
                            update_post_meta( $post_id, '_price_to', $price );

                            update_post_meta( $post_id, '_price_units', '' );

                            update_post_meta( $post_id, '_price_poa', ( ( isset($property['priceOnApplication']) && $property['priceOnApplication'] == true ) ? 'yes' : '') );
                        }

                        if ( $property['commercialListingType'] == 'lease' || $property['commercialListingType'] == 'both' )
                        {
                            update_post_meta( $post_id, '_to_rent', 'yes' );

                            update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                            // Clean price
                            if ( $property['commercialListingType'] == 'both' && isset($property['commercialLeasePrice']) && !empty($property['commercialLeasePrice']) )
                            {
                                $price = round(preg_replace("/[^0-9.]/", '', $property['commercialLeasePrice']));
                            }
                            else
                            {
                                $price = round(preg_replace("/[^0-9.]/", '', $property['searchPrice']));
                            }

                            update_post_meta( $post_id, '_rent_from', $price );
                            update_post_meta( $post_id, '_rent_to', $price );

                            update_post_meta( $post_id, '_rent_units', 'pa' );

                            update_post_meta( $post_id, '_rent_poa', ( ( isset($property['priceOnApplication']) && $property['priceOnApplication'] == true ) ? 'yes' : '') );
                        }

                        $size = ( isset($property['floorArea']['value']) && !empty($property['floorArea']['value']) ) ? $property['floorArea']['value'] : '';
                        update_post_meta( $post_id, '_floor_area_from', $size );
                        update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $property['floorArea']['units'] ) );
                        update_post_meta( $post_id, '_floor_area_to', $size );
                        update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $property['floorArea']['units'] ) );
                        update_post_meta( $post_id, '_floor_area_units', $property['floorArea']['units'] );
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
                    $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
                    if ( $featured_by_default === true )
                    {
                        update_post_meta( $post_id, '_featured', '' );
                    }
                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                        array();

                    if ( !empty($mapping) && isset($property['portalStatus']) )
                    {
                        if ( $property['portalStatus'] == 'management' && isset($property['currentTenancy']['letAgreed']) && $property['currentTenancy']['letAgreed'] == true )
                        {
                            $property['portalStatus'] = 'letAgreed';
                        }
                        if ( isset($mapping[$property['portalStatus']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['portalStatus']], 'availability' );
                        }
                    }

                    // Features
                    $features = array();
                    if ( isset($property['features']) && is_array($property['features']) && !empty($property['features']) )
                    {
                        foreach ( $property['features'] as $feature )
                        {
                            $features[] = trim($feature);
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
                    if ( $department == 'commercial' )
                    {
                        $descriptions = 0;
                        update_post_meta( $post_id, '_description_name_0', '' );
                        update_post_meta( $post_id, '_description_0', str_replace(array("\r\n", "\n"), "", $property['description']) );

                        ++$descriptions;

                        if ( isset($property['rooms']) && is_array($property['rooms']) && !empty($property['rooms']) )
                        {
                            foreach ( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_description_name_' . $descriptions, $room['name'] );
                                update_post_meta( $post_id, '_description_' . $descriptions, str_replace(array("\r\n", "\n"), "", $room['description']) );

                                ++$descriptions;
                            }
                        }

                        update_post_meta( $post_id, '_descriptions', $descriptions );
                    }
                    else
                    {
                        $rooms = 0;
                        update_post_meta( $post_id, '_room_name_0', '' );
                        update_post_meta( $post_id, '_room_dimensions_0', '' );
                        update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", $property['description']) );
                        ++$rooms;

                        if ( isset($property['rooms']) && is_array($property['rooms']) && !empty($property['rooms']) )
                        {
                            foreach ( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_room_name_' . $rooms, $room['name'] );
                                update_post_meta( $post_id, '_room_dimensions_' . $rooms, ( ( isset($room['length']) && isset($room['width']) && !empty($room['length']) && !empty($room['width']) ) ? $room['length'] . ' x ' . $room['width'] . ' ' . $room['units'] : '' ) );
                                update_post_meta( $post_id, '_room_description_' . $rooms, str_replace(array("\r\n", "\n"), "", $room['description']) );

                                ++$rooms;
                            }
                        }

                        update_post_meta( $post_id, '_rooms', $rooms );
                    }

                    // Media - Images
                    $media = array();
                    if ( isset($property['photos']) && is_array($property['photos']) && !empty($property['photos']) )
                    {
                        foreach ( $property['photos'] as $image )
                        {
                            if (
                                isset($image['type']) && strtolower($image['type']) == 'photograph'
                                && 
                                isset($image['published']) && $image['published'] == true
                            )
                            {
                                $media[] = array(
                                    'url' => $image['url'],
                                    'modified' => $image['modified']
                                );
                            }
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'photo', $media, true );

                    // Media - Floorplans
                    $media = array();
                    if ( isset($property['photos']) && is_array($property['photos']) && !empty($property['photos']) )
                    {
                        foreach ( $property['photos'] as $image )
                        {
                            if (
                                isset($image['type']) && strtolower($image['type']) == 'floorplan'
                                && 
                                isset($image['published']) && $image['published'] == true
                            )
                            {
                                $media[] = array(
                                    'url' => $image['url'],
                                    'modified' => $image['modified']
                                );
                            }
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'floorplan', $media, true );

                    // Media - Brochures
                    $media = array();
                    if ( isset($property['soiUrl']) && !empty($property['soiUrl']) )
                    {
                        $media[] = array(
                            'url' => $property['soiUrl'],
                        );
                    }

                    $this->import_media( $post_id, $property['id'], 'brochure', $media, false );

                    // Media - EPCs
                    $media = array();
                    if ( isset($property['epcGraphUrl']) && !empty($property['epcGraphUrl']) )
                    {
                        $media[] = array(
                            'url' => $property['epcGraphUrl'],
                        );
                    }

                    $this->import_media( $post_id, $property['id'], 'epc', $media, false );

                    // Media - Virtual Tours
                    $virtual_tours = array();
                    if ( isset($property['externalLinks']) && is_array($property['externalLinks']) && !empty($property['externalLinks']) )
                    {
                        foreach ( $property['externalLinks'] as $external_link )
                        {
                            if ( 
                                isset($external_link['url']) && $external_link['url'] != ''
                                &&
                                (
                                    substr( strtolower($external_link['url']), 0, 2 ) == '//' || 
                                    substr( strtolower($external_link['url']), 0, 4 ) == 'http'
                                )
                                &&
                                isset($external_link['type']['name']) && strtolower($external_link['type']['name']) == 'virtual tour'
                            )
                            {
                                // This is a URL
                                $url = $external_link['url'];

                                $virtual_tours[] = $url;
                            }
                        }
                    }

                    update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                    foreach ( $virtual_tours as $i => $virtual_tour )
                    {
                        update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                    }

                    $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );
                }
                else
                {
                    $this->log( 'Skipping property as not been updated', $post_id, (string)$property->AGENT_REF );
                }

                if ( isset($property['modified']) ) { update_post_meta( $post_id, '_vaultea_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['modified'])) ); }

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_vaultea_json", $post_id, $property, $this->import_id );

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

        do_action( "propertyhive_post_import_properties_vaultea_json" );

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
                'listing' => 'listing',
                'conditional' => 'conditional',
                'listingOrConditional' => 'listingOrConditional',
                'unconditional' => 'unconditional'
            ),
            'lettings_availability' => array(
                'listing' => 'listing',
                'management' => 'management',
                'letAgreed' => 'letAgreed',
            ),
            'commercial_availability' => array(
                'listing' => 'listing',
                'conditional' => 'conditional',
                'listingOrConditional' => 'listingOrConditional',
                'unconditional' => 'unconditional',
                'management' => 'management',
                'letAgreed' => 'letAgreed',
            ),
            'property_type' => array(
                'Bungalow' => 'Bungalow',
                'Cottage' => 'Cottage',
                'Detached Bungalow' => 'Detached Bungalow',
                'Semi-Detached Bungalow' => 'Semi-Detached Bungalow',
                'Terraced Bungalow' => 'Terraced Bungalow',
                'Detached House' => 'Detached House',
                'Semi-Detached House' => 'Semi-Detached House',
                'End Terrace House' => 'End Terrace House',
                'Terraced House' => 'Terraced House',
                'Flat' => 'Flat',
                'Apartment' => 'Apartment',
                'Maisonette' => 'Maisonette',
                'Studio' => 'Studio',
            ),
            'commercial_property_type' => array(),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'Fixed Price' => 'Fixed Price',
                'Offers in Excess' => 'Offers in Excess',
                'OIRO' => 'OIRO',
                'From' => 'From',
                'Offers Over' => 'Offers Over',
                'Shared Ownership' => 'Shared Ownership',
                'Part Buy Part Rent' => 'Part Buy Part Rent',
                'Shared Equity' => 'Shared Equity',
                'Offers Invited' => 'Offers Invited',
                'Coming Soon' => 'Coming Soon',
            ),
        );
    }
}

}