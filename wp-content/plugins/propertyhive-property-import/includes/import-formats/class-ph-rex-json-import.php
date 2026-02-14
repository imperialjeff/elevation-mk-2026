<?php
/**
 * Class for managing the import process of a Rex API JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Rex_JSON_Import extends PH_Property_Import_Process {

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

    private function get_token( $test = false )
    {
        if ( $test === false )
        {
            $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
        }
        else
        {
            $import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
        }

        $endpoint = '/v1/rex/Authentication/login';

        $data = array(
            'email' => $import_settings['username'],
            'password' => $import_settings['password'],
            //'application' => 'rex' // getting error when using this even though it's in the docs
        );

        if ( isset($import_settings['account_id']) && !empty($import_settings['account_id']) )
        {
            $data['account_id'] = $import_settings['account_id'];
        }

        $data = apply_filters( 'propertyhive_rex_authentication_request_body', $data );

        $data = json_encode($data);

        if ( !$data )
        {
            $this->log_error( 'Failed to encode authentication request data' );
            return false;
        }

        $base_url = 'https://api.uk.rexsoftware.com';
        if ( isset($import_settings['url']) && !empty($import_settings['url']) )
        {
            $base_url = rtrim($import_settings['url'], "/");
        }

        $response = wp_remote_post(
            $base_url . $endpoint,
            array(
                'body' => $data,
                'headers' => array(
                    'Content-Type' => 'application/json'
                ),
            )
        );

        if ( is_wp_error($response) )
        {
            $this->log_error( 'WP Error returned in response from authentication' );
            return false;
        }

        $json = json_decode( $response['body'], TRUE );

        if ( !$json )
        {
            $this->log_error( 'Failed to decode authentication response data' );
            return false;
        }

        if ( isset($json['error']) && !empty($json['error']) )
        {
            $this->log_error( 'Error returned in response from authentication: ' . print_r( $json['error'], TRUE ) );
            return false;
        }

        if ( !isset($json['result']) )
        {
            $this->log_error( 'No result in response from authentication: ' . print_r( $json, TRUE ) );
            return false;
        }

        // get token from result
        $token = $json['result'];

        return $token;
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

        $base_url = 'https://api.uk.rexsoftware.com';
        if ( isset($import_settings['url']) && !empty($import_settings['url']) )
        {
            $base_url = rtrim($import_settings['url'], "/");
        }

        $token = $this->get_token( $test );

        if ( !$token )
        {
            return false;
        }

        $limit = $this->get_property_limit();

        $this->log("Parsing properties");

        $pagination_limit = 100;

        $endpoint = '/v1/rex/published-listings/search';

        $data = array(
            'result_format' => 'website_overrides_applied',
            'extra_options' => array(
                'extra_fields' => array( 'documents', 'highlights', 'links', 'rooms', 'images', 'floorplans', 'epc', 'tags', 'features', 'advert_internet', 'advert_brochure', 'advert_stocklist', 'subcategories', 'meta', 'meta' ),
            ),
            'criteria' => array(
                array(
                    "name" => "listing.system_listing_state", 
                    "type" => "notin",
                    "value" => array("withdrawn")
                ),
                array(
                    "name" => "listing.publish_to_external", 
                    "type" => "=",
                    "value" => true
                )
            ),
            'limit' => $pagination_limit,
            'order_by' => array('system_publication_time' => 'desc')
        );

        $data = apply_filters( 'propertyhive_rex_property_request_body', $data );

        $page = 1;
        $found_results = true;

        while ( $found_results && $page < 99 )
        {
            $offset = ( $page - 1 ) * $pagination_limit;
            $data['offset'] = $offset;

            $body = json_encode($data);

            if ( !$body )
            {
                $this->log_error( 'Failed to encode property request data' );
                return false;
            }

            $response = wp_remote_post(
                $base_url . $endpoint,
                array(
                    'body' => $body,
                    'headers' => array(
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Bearer ' . $token
                    ),
                    'timeout' => 120,
                )
            );

            if ( is_wp_error($response) )
            {
                $this->log_error( 'WP Error returned in response from properties:' . $response->get_error_message() );
                return false;
            }

            $json = json_decode( $response['body'], TRUE );

            if ( !$json )
            {
                $this->log_error( 'Failed to decode property response data' );
                return false;
            }

            if ( isset($json['error']) && !empty($json['error']) )
            {
                $this->log_error( 'Error returned in response from properties: ' . print_r( $json['error'], TRUE ) );
                return false;
            }

            if ( !isset($json['result']) )
            {
                $this->log_error( 'No result in response from properties: ' . print_r( $json, TRUE ) );
                return false;
            }

            if ( is_array($json['result']['rows']) )
            {
                if ( !empty($json['result']['rows']) )
                {
                    $this->log("Found " . count($json['result']['rows']) . " properties in JSON on page " . $page . " ready for parsing");

                    foreach ($json['result']['rows'] as $property)
                    {
                        if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                        {
                            return true;
                        }

                        $this->properties[] = $property;
                    }
                }
                else
                {
                    $found_results = false;
                }
            }
            else
            {
                // Failed to parse JSON
                $this->log_error( 'Rows missing or empty from property response' );
                return false;
            }

            ++$page;
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

        $geocoding_denied = apply_filters( 'propertyhive_rex_json_import_prevent_geocoding', false, $this->import_id );

        do_action( "propertyhive_pre_import_properties_rex_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_rex_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_rex_json", $property, $this->import_id, $this->instance_id );
            
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

            $display_address = array();
            if ( isset($property['address']['formats']['display_address']) && trim($property['address']['formats']['display_address']) != '' )
            {
                $display_address = $property['address']['formats']['display_address'];
            }
            else
            {
                if ( isset($property['address']['street_name']) && trim($property['address']['street_name']) != '' )
                {
                    $display_address[] = trim($property['address']['street_name']);
                }
                if ( isset($property['address']['locality']) && trim($property['address']['locality']) != '' )
                {
                    $display_address[] = trim($property['address']['locality']);
                }
                elseif ( isset($property['address']['suburb_or_town']) && trim($property['address']['suburb_or_town']) != '' )
                {
                    $display_address[] = trim($property['address']['suburb_or_town']);
                }
                $display_address = implode(", ", $display_address);
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $property['advert_internet']['heading'], '', isset($property['system_publication_timestamp']) ? date( 'Y-m-d H:i:s', $property['system_publication_timestamp'] ) : '', '', isset($property['system_modtime']) ? date( 'Y-m-d H:i:s', $property['system_modtime'] ) : '', '_rex_update_date_' . $this->import_id );

            if ( $inserted_updated !== false )
            {
                // Inserted property ok. Continue

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['id'] );

                update_post_meta( $post_id, $imported_ref_key, $property['id'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                $previous_update_date = get_post_meta( $post_id, '_rex_update_date_' . $this->import_id, TRUE);

                $skip_property = true;
                if (
                    ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' ) || 
                    !isset($import_settings['only_updated'])
                )
                {
                    if (
                        $inserted_updated == 'inserted' ||
                        !isset($property['system_modtime']) ||
                        (
                            isset($property['system_modtime']) &&
                            trim($property['system_modtime']) == ''
                        ) ||
                        $previous_update_date == '' ||
                        (
                            isset($property['system_modtime']) &&
                            $property['system_modtime'] != '' &&
                            $previous_update_date != '' &&
                            $property['system_modtime'] > $previous_update_date
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
                
                    // Address
                    update_post_meta( $post_id, '_reference_number', $property['id'] );
                    update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['address']['unit_number']) ) ? $property['address']['unit_number'] : '' ) . ' ' . ( ( isset($property['address']['street_number']) ) ? $property['address']['street_number'] : '' ) ) );
                    update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['street_name']) ) ? $property['address']['street_name'] : '' ) );
                    update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['locality']) ) ? $property['address']['locality'] : '' ) );
                    update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['suburb_or_town']) ) ? $property['address']['suburb_or_town'] : '' ) );
                    update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['state_or_region']) ) ? $property['address']['state_or_region'] : '' ) );
                    update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address']['postcode']) ) ? $property['address']['postcode'] : '' ) );
                    
                    $country = ( ( isset($property['address']['country']) ) ? strtoupper($property['address']['country']) : get_option( 'propertyhive_default_country', 'UK' ) );
                    if ( $country == 'UK' )
                    {
                        $country = 'GB';
                    }
                    update_post_meta( $post_id, '_address_country', $country );

                    // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                    $address_fields_to_check = apply_filters( 'propertyhive_rex_json_address_fields_to_check', array('locality', 'suburb_or_town', 'state_or_region') );
                    $location_term_ids = array();

                    foreach ( $address_fields_to_check as $address_field )
                    {
                        if ( isset($property['address'][$address_field]) && trim($property['address'][$address_field]) != '' ) 
                        {
                            $term = term_exists( trim($property['address'][$address_field]), 'location');
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
                    if ( isset($property['address']['latitude']) && isset($property['address']['longitude']) && $property['address']['latitude'] != '' && $property['address']['longitude'] != '' && $property['address']['latitude'] != '0' && $property['address']['longitude'] != '0' )
                    {
                        update_post_meta( $post_id, '_latitude', $property['address']['latitude'] );
                        update_post_meta( $post_id, '_longitude', $property['address']['longitude'] );
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
                            if ( isset($property['address']['street_name']) && trim($property['address']['street_name']) != '' ) { $address_to_geocode[] = $property['address']['street_name']; }
                            if ( isset($property['address']['locality']) && trim($property['address']['locality']) != '' ) { $address_to_geocode[] = $property['address']['locality']; }
                            if ( isset($property['address']['suburb_or_town']) && trim($property['address']['suburb_or_town']) != '' ) { $address_to_geocode[] = $property['address']['suburb_or_town']; }
                            if ( isset($property['address']['state_or_region']) && trim($property['address']['state_or_region']) != '' ) { $address_to_geocode[] = $property['address']['state_or_region']; }
                            if ( isset($property['address']['postcode']) && trim($property['address']['postcode']) != '' ) { $address_to_geocode[] = $property['address']['postcode']; $address_to_geocode_osm[] = $property['address']['postcode']; }

                            $geocoding_return = $this->do_geocoding_lookup( $post_id, $property['id'], $address_to_geocode, $address_to_geocode_osm, $country );
                            if ( $geocoding_return === 'denied' )
                            {
                                $geocoding_denied = true;
                            }
                        }
                    }

                    // Owner
                    add_post_meta( $post_id, '_owner_contact_id', '', true );

                    // Record Details
                    $negotiator_id = false;
                    if ( isset($property['listing_agent_1']['name']) && !empty($property['listing_agent_1']['name']) )
                    {
                        foreach ( $this->negotiators as $negotiator_key => $negotiator )
                        {
                            if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim($property['listing_agent_1']['name'])) )
                            {
                                $negotiator_id = $negotiator_key;
                            }
                        }
                    }
                    if ( $negotiator_id === false )
                    {
                        $negotiator_id = get_current_user_id();
                    }
                    update_post_meta( $post_id, '_negotiator_id', $negotiator_id );
                    
                    $office_id = $this->primary_office_id;
                    if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
                    {
                        foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                        {
                            $explode_branch_codes = explode(",", $branch_code);
                            $explode_branch_codes = array_map('trim', $explode_branch_codes);
                            foreach ( $explode_branch_codes as $branch_code )
                            {
                                if ( $branch_code == $property['location']['id'] )
                                {
                                    $office_id = $ph_office_id;
                                    break;
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_office_id', $office_id );

                    // Residential Details
                    $department = 'residential-sales';
                    if ( isset($property['listing_category_id']) )
                    {
                        switch ( $property['listing_category_id'] )
                        {
                            case "residential_letting":
                            case "residential_rental":
                            case "commercial_rental":
                            {
                                $department = 'residential-lettings';
                                break;
                            }
                        }
                    }
                    if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' && strpos($property['listing_category_id'], 'commercial') !== false )
                    {
                        $department = 'commercial';
                    }
                    update_post_meta( $post_id, '_department', $department );
                    
                    update_post_meta( $post_id, '_bedrooms', ( ( isset($property['attributes']['bedrooms']) ) ? $property['attributes']['bedrooms'] : '' ) );
                    update_post_meta( $post_id, '_bathrooms', ( ( isset($property['attributes']['bathrooms']) ) ? $property['attributes']['bathrooms'] : '' ) );
                    update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['attributes']['living_areas']) ) ? $property['attributes']['living_areas'] : '' ) );

                    update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['meta']['tax_band']) ) ? $property['meta']['tax_band'] : '' ) );

                    $prefix = '';
                    if ( $department == 'commercial' )
                    {
                        $prefix = 'commercial_';
                    }
                    $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                    if ( isset($property['subcategories']) && is_array($property['subcategories']) && !empty($property['subcategories']) )
                    {
                        $term_ids = array();
                        foreach ( $property['subcategories'] as $subcategory )
                        {
                            if ( !empty($mapping) && isset($mapping[$subcategory]) )
                            {
                                $term_ids[] = (int)$mapping[$subcategory];
                            }
                            else
                            {
                                $this->log( 'Property received with a type (' . $subcategory . ') that is not mapped', $post_id, (string)$property->propertyID );

                                $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $subcategory, $post_id );
                            }
                        }

                        if ( !empty($term_ids) )
                        {
                            wp_set_object_terms( $post_id, $term_ids, $prefix . 'property_type' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
                        }
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
                    }

                    // Residential Sales Details
                    if ( $department == 'residential-sales' )
                    {
                        $price = '';

                        $regex = '/(?:[\x{00A3}\$€])?(\d+(?:,\d{3})*)(?:\.\d+)?|(?:[\x{00A3}\$€])?(\d+)(?:\.\d+)?/u';

                        $property['price_advertise_as'] = str_replace('u00a3', '£', $property['price_advertise_as']);
                        
                        if ( preg_match($regex, $property['price_advertise_as'], $matches) )
                        {
                            // Check if we found a match
                            if ( !empty($matches[1]) ) 
                            {
                                $price = str_replace(',', '', $matches[1]);
                            }
                        }

                        if ( empty($price) )
                        {
                            // Clean price
                            $price = round(preg_replace("/[^0-9.]/", '', $property['price_match']));
                        }

                        update_post_meta( $post_id, '_price', $price );
                        update_post_meta( $post_id, '_poa', ( ( ( isset($property['state_hide_price']) && $property['state_hide_price'] == '1' ) || ( isset($property['price_advertise_as']) && $property['price_advertise_as'] == 'Price On Application' ) ) ? 'yes' : '') );

                        $currency = 'GBP';
                        $ph_countries = new PH_Countries();
                        $country = $ph_countries->get_country( $country );
                        if ( $country !== FALSE )
                        {
                            $currency = $country['currency_code'];
                        }
                        update_post_meta( $post_id, '_currency', $currency );

                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                        $price_qualifier = '';
                        $explode_price = explode("£", trim($property['price_advertise_as']));
                        if ( count($explode_price) == 2 && !empty($mapping) && isset($explode_price[0]) && isset($mapping[trim($explode_price[0])]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[trim($explode_price[0])], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                        }
                        
                        // Tenure
                        $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

                        if ( !empty($mapping) && isset($property['attributes']['tenure']) && isset($mapping[$property['attributes']['tenure']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['attributes']['tenure']], 'tenure' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'tenure' );
                        }
                    }
                    elseif ( $department == 'residential-lettings' )
                    {
                        $price = '';

                        $regex = '/(?:[\x{00A3}\$€])?(\d{1,3}(?:,\d{3})*)(?:\.\d+)?/u';

                        if ( preg_match($regex, $property['price_advertise_as'], $matches) )
                        {
                            // Check if we found a match
                            if ( !empty($matches[1]) ) 
                            {
                                $price = str_replace(',', '', $matches[1]);
                            }
                        }

                        if ( empty($price) )
                        {
                            // Clean price
                            $price = round(preg_replace("/[^0-9.]/", '', $property['price_rent']));
                        }

                        update_post_meta( $post_id, '_rent', $price );

                        $rent_frequency = 'pcm';
                        if ( isset($property['price_rent_period']) )
                        {
                            if ( strpos(strtolower($property['price_rent_period']), 'week') !== FALSE || strpos(strtolower($property['price_rent_period']), 'pw') !== FALSE )
                            {
                                $rent_frequency = 'pw'; 
                            }
                            elseif ( strpos(strtolower($property['price_rent_period']), 'ann') !== FALSE || strpos(strtolower($property['price_rent_period']), 'pa') !== FALSE || strpos(strtolower($property['price_rent_period']), 'year') !== FALSE )
                            {
                                $rent_frequency = 'pa';
                            }
                        }

                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

                        $currency = 'GBP';
                        $ph_countries = new PH_Countries();
                        $country = $ph_countries->get_country( $country );
                        if ( $country !== FALSE )
                        {
                            $currency = $country['currency_code'];
                        }
                        update_post_meta( $post_id, '_currency', $currency );
                        
                        update_post_meta( $post_id, '_poa', ( ( isset($property['state_hide_price']) && $property['state_hide_price'] == '1' ) ? 'yes' : '') );

                        $deposit = '';
                        if ( !empty($property['price_bond']) )
                        {
                            $deposit = round(preg_replace("/[^0-9.]/", '', $property['price_bond']));
                        }
                        update_post_meta( $post_id, '_deposit', $deposit );

                        update_post_meta( $post_id, '_available_date', ( isset($property['available_from_date']) ? $property['available_from_date'] : '' ) );
                    }
                    elseif ( $department == 'commercial' )
                    {
                        update_post_meta( $post_id, '_for_sale', '' );
                        update_post_meta( $post_id, '_to_rent', '' );

                        if ( strpos($property['listing_category_id'], 'sale') !== false )
                        {
                            update_post_meta( $post_id, '_for_sale', 'yes' );

                            update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                            $price = '';

                            $regex = '/(?:[\x{00A3}\$€])?(\d+(?:,\d{3})*)(?:\.\d+)?|(?:[\x{00A3}\$€])?(\d+)(?:\.\d+)?/u';

                            $property['price_advertise_as'] = str_replace('u00a3', '£', $property['price_advertise_as']);
                            
                            if ( preg_match($regex, $property['price_advertise_as'], $matches) )
                            {
                                // Check if we found a match
                                if ( !empty($matches[1]) ) 
                                {
                                    $price = str_replace(',', '', $matches[1]);
                                }
                            }

                            if ( empty($price) )
                            {
                                // Clean price
                                $price = round(preg_replace("/[^0-9.]/", '', $property['price_match']));
                            }
                            update_post_meta( $post_id, '_price_from', $price );
                            update_post_meta( $post_id, '_price_to', $price );

                            update_post_meta( $post_id, '_price_units', '' );

                            update_post_meta( $post_id, '_price_poa', ( ( ( isset($property['state_hide_price']) && $property['state_hide_price'] == '1' ) || ( isset($property['price_advertise_as']) && $property['price_advertise_as'] == 'Price On Application' ) ) ? 'yes' : '') );

                            // Tenure
                            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();
                            
                            if ( !empty($mapping) && isset($property['attributes']['tenure']) && isset($mapping[$property['attributes']['tenure']]) )
                            {
                                wp_set_object_terms( $post_id, (int)$mapping[$property['attributes']['tenure']], 'commercial_tenure' );
                            }
                            else
                            {
                                wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
                            }
                        }

                        if ( strpos($property['listing_category_id'], 'letting') !== false || strpos($property['listing_category_id'], 'rent') !== false )
                        {
                            update_post_meta( $post_id, '_to_rent', 'yes' );

                            update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                            $price = '';

                            $regex = '/(?:[\x{00A3}\$€])?(\d{1,3}(?:,\d{3})*)(?:\.\d+)?/u';

                            if ( preg_match($regex, $property['price_advertise_as'], $matches) )
                            {
                                // Check if we found a match
                                if ( !empty($matches[1]) ) 
                                {
                                    $price = str_replace(',', '', $matches[1]);
                                }
                            }

                            if ( empty($price) )
                            {
                                // Clean price
                                $price = round(preg_replace("/[^0-9.]/", '', $property['price_rent']));
                            }

                            update_post_meta( $post_id, '_rent_from', $price );
                            update_post_meta( $post_id, '_rent_to', $price );

                            $rent_frequency = 'pcm';
                            if ( isset($property['price_rent_period']) )
                            {
                                if ( strpos(strtolower($property['price_rent_period']), 'week') !== FALSE || strpos(strtolower($property['price_rent_period']), 'pw') !== FALSE )
                                {
                                    $rent_frequency = 'pw'; 
                                }
                                elseif ( strpos(strtolower($property['price_rent_period']), 'ann') !== FALSE || strpos(strtolower($property['price_rent_period']), 'pa') !== FALSE || strpos(strtolower($property['price_rent_period']), 'year') !== FALSE )
                                {
                                    $rent_frequency = 'pa';
                                }
                            }
                            update_post_meta( $post_id, '_rent_units', $rent_frequency);

                            update_post_meta( $post_id, '_rent_poa', ( ( ( isset($property['state_hide_price']) && $property['state_hide_price'] == '1' ) || ( isset($property['price_advertise_as']) && $property['price_advertise_as'] == 'Price On Application' ) ) ? 'yes' : '') );
                        }

                        $size = preg_replace("/[^0-9.]/", '', $property['attributes']['buildarea_m2']);
                        if ( empty($size) )
                        {
                            $size = preg_replace("/[^0-9.]/", '', $property['attributes']['buildarea_max_m2']);
                        }
                        update_post_meta( $post_id, '_floor_area_from', $size );

                        update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, 'sqm' ) );

                        $size = preg_replace("/[^0-9.]/", '', $property['attributes']['buildarea_max_m2']);
                        if ( empty($size) )
                        {
                            $size = preg_replace("/[^0-9.]/", '', $property['attributes']['buildarea_m2']);
                        }
                        update_post_meta( $post_id, '_floor_area_to', $size );

                        update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, 'sqm' ) );

                        update_post_meta( $post_id, '_floor_area_units', 'sqm' );

                        $size = preg_replace("/[^0-9.]/", '', $property['attributes']['landarea']);
                        update_post_meta( $post_id, '_site_area_from', $size );

                        update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, 'sqm' ) );

                        update_post_meta( $post_id, '_site_area_to', $size );

                        update_post_meta( $post_id, '_site_area_to_sqft',  convert_size_to_sqft( $size, 'sqm' ) );

                        update_post_meta( $post_id, '_site_area_units', 'sqm' );
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
                    //update_post_meta( $post_id, '_featured', '' );

                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                        array();

                    $availability = isset($property['project_listing_status']) ? $property['project_listing_status'] : '';
                    if ( $availability == '' || $availability === null )
                    {
                        $availability = 'Available';
                        if ( $department == 'residential-lettings' && isset($property['let_agreed']) && $property['let_agreed'] == 1 )
                        {
                            $availability = 'Let Agreed';
                        }
                    }
                    if ( isset($property['system_listing_state']) && strtolower($property['system_listing_state']) == 'sold' )
                    {
                        $availability = 'Sold';
                    }
                    if ( isset($property['system_listing_state']) && strtolower($property['system_listing_state']) == 'leased' )
                    {
                        $availability = 'Let';
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
                    $features = array();
                    if ( isset($property['features']) && is_array($property['features']) && !empty($property['features']) )
                    {
                        foreach ( $property['features'] as $feature )
                        {
                            $features[] = trim($feature);
                        }
                    }
                    elseif ( isset($property['highlights']) && is_array($property['highlights']) && !empty($property['highlights']) )
                    {
                        foreach ( $property['highlights'] as $feature )
                        {
                            if ( isset($feature['description']) && !empty($feature['description']) )
                            {
                                $features[] = trim($feature['description']);
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

                    // Rooms / Descriptions
                    $num_rooms = 0;

                    if ( $department == 'commercial' )
                    {
                        if ( isset($property['advert_brochure']) && isset($property['advert_brochure']['body']) && $property['advert_brochure']['body'] != '' )
                        {
                            update_post_meta( $post_id, '_description_name_' . $num_rooms, '' );
                            update_post_meta( $post_id, '_description_' . $num_rooms, $property['advert_brochure']['body'] );

                            ++$num_rooms;
                        }

                        if ( isset($property['rooms']) && is_array($property['rooms']) && !empty($property['rooms']) )
                        {
                            foreach ( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_description_name_' . $num_rooms, $room['room_type'] );
                                update_post_meta( $post_id, '_description_' . $num_rooms, $room['description'] );

                                ++$num_rooms;
                            }
                        }

                        update_post_meta( $post_id, '_descriptions', $num_rooms );
                    }
                    else
                    {
                        if ( isset($property['advert_brochure']) && isset($property['advert_brochure']['body']) && $property['advert_brochure']['body'] != '' )
                        {
                            update_post_meta( $post_id, '_room_name_' . $num_rooms, '' );
                            update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, '' );
                            update_post_meta( $post_id, '_room_description_' . $num_rooms, $property['advert_brochure']['body'] );

                            ++$num_rooms;
                        }

                        if ( isset($property['rooms']) && is_array($property['rooms']) && !empty($property['rooms']) )
                        {
                            foreach ( $property['rooms'] as $room )
                            {
                                $dimensions = '';
                                if ( isset($room['formatted_dimensions']) && !empty($room['formatted_dimensions']) )
                                {
                                    $dimensions = $room['formatted_dimensions'];
                                }
                                elseif ( isset($room['dimensions']) && !empty($room['dimensions']) )
                                {
                                    $dimensions = $room['dimensions'];
                                }
                                update_post_meta( $post_id, '_room_name_' . $num_rooms, $room['room_type'] );
                                update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, $dimensions );
                                update_post_meta( $post_id, '_room_description_' . $num_rooms, $room['description'] );

                                ++$num_rooms;
                            }
                        }

                        update_post_meta( $post_id, '_rooms', $num_rooms );
                    }

                    // Media - Images
                    $media = array();
                    if ( isset($property['images']) && is_array($property['images']) && !empty($property['images']) )
                    {
                        foreach ( $property['images'] as $image )
                        {
                            $url = $image['url'];
                            if ( substr($url, 0, 2) == '//' )
                            {
                                $url = 'https:' . $url;
                            }

                            $media[] = array(
                                'url' => $url,
                                'modified' => $image['modtime'],
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'photo', $media, true );

                    // Media - Floorplans
                    $media = array();
                    if ( isset($property['floorplans']) && is_array($property['floorplans']) && !empty($property['floorplans']) )
                    {
                        foreach ( $property['floorplans'] as $image )
                        {
                            $url = $image['url'];
                            if ( substr($url, 0, 2) == '//' )
                            {
                                $url = 'https:' . $url;
                            }

                            $media[] = array(
                                'url' => $url,
                                'modified' => $image['modtime'],
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'floorplan', $media, true );

                    // Media - Brochures
                    $media = array();
                    if ( isset($property['documents']) && is_array($property['documents']) && !empty($property['documents']) )
                    {
                        foreach ( $property['documents'] as $image )
                        {
                            if ( 
                                isset($image['url']) && $image['url'] != '' &&
                                isset($image['type_id']) && $image['type_id'] != 'epc'
                            )
                            {
                                $url = $image['url'];
                                if ( substr($url, 0, 2) == '//' )
                                {
                                    $url = 'https:' . $url;
                                }

                                $description = ( ( isset($image['description']) && $image['description'] != '' ) ? $image['description'] : '' );
                                $explode_description = explode(".", $description);
                                $description = $explode_description[0];

                                $media[] = array(
                                    'url' => $url,
                                    'description' => $description,
                                );
                            }
                        }
                    }
                    if ( isset($property['links']) && is_array($property['links']) && !empty($property['links']) )
                    {
                        foreach ( $property['links'] as $image )
                        {
                            if ( 
                                isset($image['link_url']) && $image['link_url'] != '' &&
                                (
                                    substr( strtolower($image['link_url']), 0, 2 ) == '//' || 
                                    substr( strtolower($image['link_url']), 0, 4 ) == 'http'
                                )
                                &&
                                isset($image['link_type']) && $image['link_type'] == 'brochure'
                            )
                            {
                                // This is a URL
                                $url = $image['link_url'];
                                if ( substr($url, 0, 2) == '//' )
                                {
                                    $url = 'https:' . $url;
                                }

                                $description = ( ( isset($image['link_label']) && $image['link_label'] != '' ) ? $image['link_label'] : '' );

                                $media[] = array(
                                    'url' => $url,
                                    'description' => $description,
                                );
                            }
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'brochure', $media, false );

                    // Media - EPCs
                    $media = array();
                    if ( isset($property['epc']['combined_chart_url']) && $property['epc']['combined_chart_url'] != '' )
                    {
                        $url = $property['epc']['combined_chart_url'];
                        if ( substr($url, 0, 2) == '//' )
                        {
                            $url = 'https:' . $url;
                        }

                        $media[] = array(
                            'url' => $url,
                        );
                    }
                    if ( isset($property['documents']) && is_array($property['documents']) && !empty($property['documents']) )
                    {
                        foreach ( $property['documents'] as $image )
                        {
                            if ( 
                                isset($image['url']) && $image['url'] != '' &&
                                isset($image['type_id']) && $image['type_id'] == 'epc'
                            )
                            {
                                $url = $image['url'];
                                if ( substr($url, 0, 2) == '//' )
                                {
                                    $url = 'https:' . $url;
                                }

                                $media[] = array(
                                    'url' => $url,
                                );
                            }
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'epc', $media, false );

                    // Media - Virtual Tours
                    $virtual_tours = array();
                    if ( isset($property['links']) && is_array($property['links']) && !empty($property['links']) )
                    {
                        foreach ( $property['links'] as $image )
                        {
                            if ( 
                                isset($image['link_url']) && $image['link_url'] != ''
                                &&
                                (
                                    substr( strtolower($image['link_url']), 0, 2 ) == '//' || 
                                    substr( strtolower($image['link_url']), 0, 4 ) == 'http'
                                )
                                &&
                                isset($image['link_type']) && ( $image['link_type'] == 'virtual_tour' || $image['link_type'] == 'video_link' )
                            )
                            {
                                // This is a URL
                                $virtual_tours[] = array(
                                    'url' => $image['link_url'],
                                    'label' => ucwords(str_replace("_", " ", str_replace("_link", "", $image['link_type'])))
                                );
                            }
                        }
                    }

                    update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                    foreach ( $virtual_tours as $i => $virtual_tour )
                    {
                        update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
                        update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
                    }

                    $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );

                    do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                    do_action( "propertyhive_property_imported_rex_json", $post_id, $property, $this->import_id );

                    $post = get_post( $post_id );
                    do_action( "save_post_property", $post_id, $post, false );
                    do_action( "save_post", $post_id, $post, false );

                    if ( $inserted_updated == 'updated' )
                    {
                        $this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
                    }
                }
                else
                {
                    $this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
                }

                update_post_meta( $post_id, '_rex_update_date_' . $this->import_id, date("Y-m-d H:i:s", $property['system_modtime']) );
            }

            ++$property_row;

        } // end foreach property

        do_action( "propertyhive_post_import_properties_rex_json" );

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
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Exchanged' => 'Exchanged',
                'Completed' => 'Completed',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Let' => 'Let',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Exchanged' => 'Exchanged',
                'Completed' => 'Completed',
                'Sold' => 'Sold',
                'Let' => 'Let',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Barn Conversion' => 'Barn Conversion',
                'Block of Flats' => 'Block of Flats',
                'Bungalow' => 'Bungalow',
                'Chalet' => 'Chalet',
                'Coach House' => 'Coach House',
                'Country House' => 'Country House',
                'Cottage' => 'Cottage',
                'Detached bungalow' => 'Detached bungalow',
                'Detached house' => 'Detached house',
                'End of terrace house' => 'End of terrace house',
                'Finca' => 'Finca',
                'Flat' => 'Flat',
                'House Boat' => 'House Boat',
                'Link detached house' => 'Link detached house',
                'Lodge' => 'Lodge',
                'Longere' => 'Longere',
                'Maisonette' => 'Maisonette',
                'Mews house' => 'Mews house',
                'Park home' => 'Park home',
                'Riad' => 'Riad',
                'Semi-detached bungalow' => 'Semi-detached bungalow',
                'Semi-detached house' => 'Semi-detached house',
                'Studio' => 'Studio',
                'Terraced bungalow' => 'Terraced bungalow',
                'Terraced House' => 'Terraced House',
                'Town House' => 'Town House',
                'Villa' => 'Villa',
            ),
            'commercial_property_type' => array(
                'Garages' => 'Garages',
                'Office' => 'Office',
                'Pub' => 'Pub',
                'Takeaway' => 'Takeaway',
            ),
            'price_qualifier' => array(
                'Fixed Price' => 'Fixed Price',
                'Guide Price' => 'Guide Price',
                'Offers Over' => 'Offers Over',
                'Offers In The Region Of' => 'Offers In The Region Of',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
        );
    }
}

}