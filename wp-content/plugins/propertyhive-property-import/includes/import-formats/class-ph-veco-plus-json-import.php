<?php
/**
 * Class for managing the import process of a Veco Plus JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Veco_Plus_JSON_Import extends PH_Property_Import_Process {

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

        $current_page = 1;
        $per_page = 25;
        $more_properties = true;

        $query_string = '';
        if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
        {
            $previous_update_date = get_option( 'veco_plus_update_date_' . $this->import_id, '' );
            if ( $previous_update_date != '' )
            {
                $query_string .= '&from=' . gmdate( 'Y-m-d H:i:s', $previous_update_date ) . '.00'; // Always UTC
            }
        }

        $all_items = array();

        while ( $more_properties )
        {
            $response = wp_remote_get( 
                'https://api.all.veco.plus/api/func/api/v1/events/getevents?apiEventType=property&page=' . $current_page . '&pageSize=' . $per_page, 
                array(
                    'headers' => array(
                        'x-api-key' => $import_settings['api_key'],
                        'Content-Type' => 'application/json'
                    ),
                    'timeout' => 120,
                )
            );

            usleep(500000);

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

            $contents = $response['body'];

            $json = json_decode( $contents, TRUE );

            if ( $json === FALSE )
            {
                // Failed to parse JSON
                $this->log_error( 'Failed to parse JSON file. Possibly invalid JSON: ' . print_r($contents, TRUE) );
                return false;
            }

            if ( !isset($json['items']) || !is_array($json['items']) )
            {
                // Failed to parse JSON
                $this->log_error( 'Missing \'items\' node in JSON: ' . print_r($contents, TRUE) );
                return false;
            }

            if ( !empty($json['items']) )
            {
                $this->log("Parsing properties on page " . $current_page);
                
                foreach ($json['items'] as $item)
                {
                    $all_items[] = $item;
                }
            }
            else
            {
                $more_properties = false;
            }

            ++$current_page;
        }

        if ( !empty($all_items) )
        {
            usort($all_items, function ($a, $b) 
            {
                return strtotime($a['createdDate']) <=> strtotime($b['createdDate']);
            });
            
            // Track which transactionIds we've already kept
            $seen = [];

            // Loop backwards
            for ($i = count($all_items) - 1; $i >= 0; $i--) {
                $id = $all_items[$i]['transactionId'];
                
                if (!isset($seen[$id])) {
                    // First time we see this ID (from the end)
                    $seen[$id] = true;
                } else {
                    // Already seen -> remove it
                    unset($all_items[$i]);
                }
            }

            // Reindex array (optional)
            $all_items = array_values($all_items);

            foreach ($all_items as $item)
            {
                if ( isset($item['eventType']) && $item['eventType'] == 1 ) // Property Unpublished
                {
                    $this->remove_property( $item['transactionId'] );
                }
                elseif ( isset($item['eventType']) && $item['eventType'] == 0 )
                {
                    $property = json_decode($item['payload'], true);
                    if ( $property !== false )
                    {
                        // remove any existing properties with agent_ref
                        foreach ( $this->properties as $property_i => $temp_property )
                        {
                            if ( isset($temp_property['agent_ref']) && $temp_property['agent_ref'] == $property['agent_ref'] )
                            {
                                // yes. Already a property with this ref in the queue. Remove it.
                                unset($this->properties[$property_i]);
                            }
                        }
                        $this->properties[] = $property;
                    }
                    else
                    {
                        // Failed to parse property JSON
                        $this->log_error( 'Failed to parse property JSON. Possibly invalid JSON: ' . print_r($item['payload'], TRUE) );
                        return false;
                    }
                }
                else
                {
                    $this->log_error( 'Unknown event type: ' . print_r($item['eventType'], TRUE) );
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

        do_action( "propertyhive_pre_import_properties_veco_plus_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_veco_plus_json_properties_due_import", $this->properties, $this->import_id );

        $this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

        $property_row = 1;
        foreach ( $this->properties as $property )
        {
            do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_veco_plus_json", $property, $this->import_id, $this->instance_id );
            
            $this->log( 'Importing property with reference ' . $property['agent_ref'], 0, $property['agent_ref'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = isset($property['address']['display_address']) ? $property['address']['display_address'] : '';
            if ( trim($display_address) == '' )
            {
                $display_address = array();
                if ( isset($property['address']['address_2']) && trim($property['address']['address_2']) != '' )
                {
                    $display_address[] = trim($property['address']['address_2']);
                }
                if ( isset($property['address']['address_3']) && trim($property['address']['address_3']) != '' )
                {
                    $display_address[] = trim($property['address']['address_3']);
                }
                elseif ( isset($property['address']['address_4']) && trim($property['address']['address_4']) != '' )
                {
                    $display_address[] = trim($property['address']['address_4']);
                }
                elseif ( isset($property['address']['town']) && trim($property['address']['town']) != '' )
                {
                    $display_address[] = trim($property['address']['town']);
                }
                $display_address = implode(", ", $display_address);
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['agent_ref'], $property, $display_address, !is_null($property['details']['summary']) ? $property['details']['summary'] : '', '', ( isset($property['create_date']) ) ? date( 'Y-m-d H:i:s', strtotime( $property['create_date'] )) : '' );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['agent_ref'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                $previous_update_date = get_post_meta( $post_id, '_veco_plus_json_update_date_' . $this->import_id, TRUE);

                $skip_property = false;
                if (
                    ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
                )
                {
                    if (
                        isset($property['update_date']) && 
                        $previous_update_date == $property['update_date']
                    )
                    {
                        $skip_property = true;
                    }
                }

                $country = get_option( 'propertyhive_default_country', 'GB' );

                // Coordinates
                if ( isset($property['address']['latitude']) && isset($property['address']['longitude']) && $property['address']['latitude'] != '' && $property['address']['longitude'] != '' && $property['address']['latitude'] != '0' && $property['address']['longitude'] != '0' )
                {
                    update_post_meta( $post_id, '_latitude', ( ( isset($property['address']['latitude']) ) ? $property['address']['latitude'] : '' ) );
                    update_post_meta( $post_id, '_longitude', ( ( isset($property['address']['longitude']) ) ? $property['address']['longitude'] : '' ) );
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
                        if ( isset($property['address']['address_2']) && trim($property['address']['address_2']) != '' ) { $address_to_geocode[] = $property['address']['address_2']; }
                        if ( isset($property['address']['address_4']) && trim($property['address']['address_4']) != '' ) { $address_to_geocode[] = $property['address']['address_4']; }
                        if ( isset($property['address']['town']) && trim($property['address']['town']) != '' ) { $address_to_geocode[] = $property['address']['town']; }
                        if ( isset($property['address']['postcode_1']) && trim($property['address']['postcode_1']) != '' ) 
                        { 
                            $address_to_geocode[] = trim($property['address']['postcode_1'] . ' ' . $property['address']['postcode_2']); 
                            $address_to_geocode_osm[] = trim($property['address']['postcode_1'] . ' ' . $property['address']['postcode_2']); 
                        }

                        $return = $this->do_geocoding_lookup( $post_id, $property['agent_ref'], $address_to_geocode, $address_to_geocode_osm, $country );
                    }
                }

                if ( !$skip_property )
                {
                    update_post_meta( $post_id, $imported_ref_key, $property['agent_ref'] );

                    // Address
                    update_post_meta( $post_id, '_reference_number', $property['reference'] );
                    update_post_meta( $post_id, '_address_name_number', ( isset($property['address']['house_name_number']) ? $property['address']['house_name_number'] : '' ) );
                    update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['address_2']) ) ? $property['address']['address_2'] : '' ) );
                    update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['address_3']) ) ? $property['address']['address_3'] : '' ) );
                    update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['address_4']) ) ? $property['address']['address_4'] : '' ) );
                    update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['town']) ) ? $property['address']['town'] : '' ) );
                    update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address']['postcode_1']) ) ? $property['address']['postcode_1'] : '' ) . ' ' . ( ( isset($property['address']['postcode_2']) ) ? $property['address']['postcode_2'] : '' ) );

                    update_post_meta( $post_id, '_address_country', $country );

                    // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                    $address_fields_to_check = apply_filters( 'propertyhive_veco_plus_json_address_fields_to_check', array('address_3', 'address_4', 'town') );
                    $location_term_ids = array();

                    foreach ( $address_fields_to_check as $address_field )
                    {
                        if ( isset($property['Address'][$address_field]) && trim($property['Address'][$address_field]) != '' ) 
                        {
                            $term = term_exists( trim($property['Address'][$address_field]), 'location');
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
                            if ( $branch_code == $property['branch_id'] )
                            {
                                $office_id = $ph_office_id;
                                break;
                            }
                        }
                    }
                    update_post_meta( $post_id, '_office_id', $office_id );

                    // Residential Details
                    $department = 'residential-sales';
                    if ( 
                        (
                            isset($property['price_information']['rent_frequency']) && 
                            !empty($property['price_information']['rent_frequency']) 
                        )
                        ||
                        (
                            isset($property['price_information']['deposit']) && 
                            !empty($property['price_information']['deposit']) 
                        )
                    )
                    {
                        $department = 'residential-lettings';
                    }
                    update_post_meta( $post_id, '_department', $department );
                    
                    update_post_meta( $post_id, '_bedrooms', ( ( isset($property['details']['bedrooms']) ) ? $property['details']['bedrooms'] : '' ) );
                    update_post_meta( $post_id, '_bathrooms', ( ( isset($property['details']['bathrooms']) ) ? $property['details']['bathrooms'] : '' ) );
                    update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['details']['reception_rooms']) ) ? $property['details']['reception_rooms'] : '' ) );

                    update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['details']['council_tax_band']) ) ? $property['details']['council_tax_band'] : '' ) );

                    $prefix = '';
                    $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                    if ( isset($property['property_type']) )
                    {
                        if ( !empty($mapping) && isset($mapping[$property['property_type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['property_type']], $prefix . 'property_type' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                            $this->log( 'Property received with a type (' . $property['property_type'] . ') that is not mapped', $post_id, $property['agent_ref'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['property_type'], $post_id );
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
                        $price = round(preg_replace("/[^0-9.]/", '', $property['price_information']['price']));

                        update_post_meta( $post_id, '_price', $price );
                        update_post_meta( $post_id, '_poa', ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == '1' ) ? 'yes' : '' );
                        update_post_meta( $post_id, '_currency', 'GBP' );
                        
                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                        if ( !empty($mapping) && isset($property['price_information']['price_qualifier']) && isset($mapping[$property['price_information']['price_qualifier']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['price_information']['price_qualifier']], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                        }

                        // Tenure
                        $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

                        if ( !empty($mapping) && isset($property['price_information']['tenure_type']) && isset($mapping[$property['price_information']['tenure_type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['price_information']['tenure_type']], 'tenure' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'tenure' );
                        }

                        if ( isset($property['price_information']['tenure_type']) && $property['price_information']['tenure_type'] == 2 )
                        {
                            update_post_meta( $post_id, '_leasehold_years_remaining', ( ( isset($property['price_information']['tenure_unexpired_years']) && !empty($property['price_information']['tenure_unexpired_years']) ) ? $property['price_information']['tenure_unexpired_years'] : '' ) );
                            update_post_meta( $post_id, '_ground_rent', ( ( isset($property['price_information']['annual_ground_rent']) && !empty($property['price_information']['annual_ground_rent']) ) ? $property['price_information']['annual_ground_rent'] : '' ) );
                            update_post_meta( $post_id, '_ground_rent_review_years', ( ( isset($property['price_information']['ground_rent_review_period_years']) && !empty($property['price_information']['ground_rent_review_period_years']) ) ? $property['price_information']['ground_rent_review_period_years'] : '' ) );
                            update_post_meta( $post_id, '_service_charge', ( ( isset($property['price_information']['annual_service_charge']) && !empty($property['price_information']['annual_service_charge']) ) ? $property['price_information']['annual_service_charge'] : '' ) );
                            update_post_meta( $post_id, '_shared_ownership', ( isset($property['price_information']['shared_ownership']) && $property['price_information']['shared_ownership'] == true ? 'yes' : '' ) );
                            update_post_meta( $post_id, '_shared_ownership_percentage', ( isset($property['price_information']['shared_ownership']) && $property['price_information']['shared_ownership'] == true ? $property['price_information']['shared_ownership_percentage'] : '' ) );
                        }
                    }
                    elseif ( $department == 'residential-lettings' )
                    {
                        update_post_meta( $post_id, '_rent', $property['price_information']['price'] );

                        $rent_frequency = 'pcm';
                        if ( isset($property['price_information']['rent_frequency']) )
                        {
                            switch ( $property['price_information']['rent_frequency'] )
                            {
                                case 1: { $rent_frequency = 'pa'; break; }
                                case 4: { $rent_frequency = 'pq'; break; }
                                case 52: { $rent_frequency = 'pw'; break; }
                                case 365: { $rent_frequency = 'pd'; break; }
                            }
                        }
                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
                        update_post_meta( $post_id, '_currency', 'GBP' );
                        update_post_meta( $post_id, '_poa', ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == '1' ) ? 'yes' : '' );

                        update_post_meta( $post_id, '_deposit', ( ( isset($property['price_information']['deposit']) && !empty($property['price_information']['deposit']) ) ? $property['price_information']['deposit'] : '' ) );
                        update_post_meta( $post_id, '_available_date', ( (isset($property['date_available']) && $property['date_available'] != '') ? date("Y-m-d", strtotime($property['date_available'])) : '' ) );

                        // Furnished
                        $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

                        if ( !empty($mapping) && isset($property['details']['furnished_type']) && isset($mapping[$property['details']['furnished_type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['details']['furnished_type']], 'furnished' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'furnished' );
                        }
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
                        update_post_meta( $post_id, '_featured', ( isset($property['Property']['Featured']) && strtolower($property['Property']['Featured']) == 'true' ) ? 'yes' : '' );
                    }

                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                        array();

                    if ( !empty($mapping) && isset($property['status']) && isset($mapping[$property['status']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['status']], 'availability' );
                    }

                    // Features
                    $features = array();
                    if ( isset($property['details']['features']) )
                    {
                        foreach ( $property['details']['features'] as $feature)
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
                    update_post_meta( $post_id, '_rooms', '1' );
                    update_post_meta( $post_id, '_room_name_0', '' );
                    update_post_meta( $post_id, '_room_dimensions_0', '' );
                    update_post_meta( $post_id, '_room_description_0', $property['details']['description'] );
                    
                    // Media - Images
                    $media = array();
                    if ( isset($property['media']) && !empty($property['media']) )
                    {
                        foreach ( $property['media'] as $image )
                        {
                            if ( isset($image['media_type']) && $image['media_type'] == '1' )
                            {
                                $media[] = array(
                                    'url' => $image['media_url'],
                                    'description' => isset($image['caption']) ? $image['caption'] : '',
                                    'modified' => $image['media_update_date'],
                                    'sort_order' => $image['sort_order'],
                                );
                            }
                        }

                        usort($media, function ($a, $b)
                        {
                            $aOrder = isset($a['sort_order']) && is_numeric($a['sort_order']) ? (int)$a['sort_order'] : PHP_INT_MAX;
                            $bOrder = isset($b['sort_order']) && is_numeric($b['sort_order']) ? (int)$b['sort_order'] : PHP_INT_MAX;
                            return $aOrder <=> $bOrder; // ascending order
                        });
                    }

                    $this->import_media( $post_id, $property['agent_ref'], 'photo', $media, true );

                    // Media - Floorplans
                    $media = array();
                    if ( isset($property['media']) && !empty($property['media']) )
                    {
                        foreach ( $property['media'] as $image )
                        {
                            if ( isset($image['media_type']) && $image['media_type'] == '2' )
                            {
                                $media[] = array(
                                    'url' => $image['media_url'],
                                    'description' => isset($image['caption']) ? $image['caption'] : '',
                                    'modified' => $image['media_update_date'],
                                    'sort_order' => $image['sort_order'],
                                );
                            }
                        }

                        usort($media, function ($a, $b)
                        {
                            $aOrder = isset($a['sort_order']) && is_numeric($a['sort_order']) ? (int)$a['sort_order'] : PHP_INT_MAX;
                            $bOrder = isset($b['sort_order']) && is_numeric($b['sort_order']) ? (int)$b['sort_order'] : PHP_INT_MAX;
                            return $aOrder <=> $bOrder; // ascending order
                        });
                    }

                    $this->import_media( $post_id, $property['agent_ref'], 'floorplan', $media, true );

                    // Media - Brochures
                    $media = array();
                    if ( isset($property['media']) && !empty($property['media']) )
                    {
                        foreach ( $property['media'] as $image )
                        {
                            if ( isset($image['media_type']) && $image['media_type'] == '3' )
                            {
                                $media[] = array(
                                    'url' => $image['media_url'],
                                    'description' => isset($image['caption']) ? $image['caption'] : '',
                                    'modified' => $image['media_update_date'],
                                    'sort_order' => $image['sort_order'],
                                );
                            }
                        }

                        usort($media, function ($a, $b)
                        {
                            $aOrder = isset($a['sort_order']) && is_numeric($a['sort_order']) ? (int)$a['sort_order'] : PHP_INT_MAX;
                            $bOrder = isset($b['sort_order']) && is_numeric($b['sort_order']) ? (int)$b['sort_order'] : PHP_INT_MAX;
                            return $aOrder <=> $bOrder; // ascending order
                        });
                    }

                    $this->import_media( $post_id, $property['agent_ref'], 'brochure', $media, true );

                    // Media - EPCs
                    $media = array();
                    if ( isset($property['media']) && !empty($property['media']) )
                    {
                        foreach ( $property['media'] as $image )
                        {
                            if ( isset($image['media_type']) && $image['media_type'] == '6' )
                            {
                                $media[] = array(
                                    'url' => $image['media_url'],
                                    'description' => isset($image['caption']) ? $image['caption'] : '',
                                    'modified' => $image['media_update_date'],
                                    'sort_order' => $image['sort_order'],
                                );
                            }
                        }

                        usort($media, function ($a, $b)
                        {
                            $aOrder = isset($a['sort_order']) && is_numeric($a['sort_order']) ? (int)$a['sort_order'] : PHP_INT_MAX;
                            $bOrder = isset($b['sort_order']) && is_numeric($b['sort_order']) ? (int)$b['sort_order'] : PHP_INT_MAX;
                            return $aOrder <=> $bOrder; // ascending order
                        });
                    }

                    $this->import_media( $post_id, $property['agent_ref'], 'epc', $media, true );

                    // Media - Virtual Tours
                    $virtual_tours = array();
                    if ( isset($property['media']) && !empty($property['media']) )
                    {
                        foreach ( $property['media'] as $image )
                        {
                            if ( isset($image['media_type']) && $image['media_type'] == '4' )
                            {
                                // This is a URL
                                $url = $image['media_url'];

                                $virtual_tours[] = $url;
                            }
                        }
                    }

                    update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                    foreach ( $virtual_tours as $i => $virtual_tour )
                    {
                        update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                    }

                    $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['agent_ref'] );
                }
                else
                {
                    $this->log( 'Skipping property as not been updated', $post_id, $property['agent_ref'] );
                }
                
                update_post_meta( $post_id, '_veco_plus_json_update_date_' . $this->import_id, $property['update_date'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_veco_plus_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['agent_ref'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;

        } // end foreach property

        do_action( "propertyhive_post_import_properties_veco_plus_json" );

        update_option( 'veco_plus_update_date_' . $this->import_id,  time() );

        $this->import_end();
    }

    // Blank on purpose. Shouldn't need this as it's event based
    public function remove_old_properties()
    {
        
    }
    
    public function get_default_mapping_values()
    {
        return array(
            'sales_availability' => array(
                '1' => 'Available',
                '2' => 'SSTC',
                '3' => 'SSTCM',
                '4' => 'Under Offer',
            ),
            'lettings_availability' => array(
                '1' => 'Available',
                '4' => 'Under Offer',
                '5' => 'Reserved',
                '6' => 'Let Agreed',
            ),
            'property_type' => array(
                '0' => 'Not Specified',
                '1' => 'Terraced House',
                '2' => 'End of terrace house',
                '3' => 'Semi-detached house',
                '4' => 'Detached house',
                '5' => 'Mews house',
                '6' => 'Cluster house',
                '7' => 'Ground floor flat',
                '8' => 'Flat',
                '9' => 'Studio flat',
                '10' => 'Ground floor maisonette',
                '11' => 'Maisonette',
                '12' => 'Bungalow',
                '13' => 'Terraced bungalow',
                '14' => 'Semi-detached bungalow',
                '15' => 'Detached bungalow',
                '16' => 'Mobile home',
                '20' => 'Land (Residential)',
                '21' => 'Link detached house',
                '22' => 'Town house',
                '23' => 'Cottage',
                '24' => 'Chalet',
                '25' => 'Character Property',
                '26' => 'House (unspecified)',
                '27' => 'Villa',
                '28' => 'Apartment',
                '29' => 'Penthouse',
                '30' => 'Finca',
                '43' => 'Barn Conversion',
                '44' => 'Serviced apartment',
                '46' => 'Sheltered Housing',
                '47' => 'Retirement property',
                '48' => 'House share',
                '49' => 'Flat share',
                '50' => 'Park home',
                '52' => 'Farm House',
                '53' => 'Equestrian facility',
                '56' => 'Duplex',
                '59' => 'Triplex',
                '62' => 'Longere',
                '65' => 'Gite',
                '68' => 'Barn',
                '71' => 'Trulli',
                '74' => 'Mill',
                '77' => 'Ruins',
                '95' => 'Village House',
                '101' => 'Cave House',
                '104' => 'Cortijo',
                '107' => 'Farm Land',
                '110' => 'Plot',
                '113' => 'Country House',
                '116' => 'Stone House',
                '117' => 'Caravan',
                '118' => 'Lodge',
                '119' => 'Log Cabin',
                '120' => 'Manor House',
                '121' => 'Stately Home',
                '125' => 'Off-Plan',
                '128' => 'Semi-detached Villa',
                '131' => 'Detached Villa',
                '141' => 'House Boat',
                '142' => 'Hotel Room',
                '143' => 'Block of Apartments',
                '144' => 'Private Halls',
                '511' => 'Coach House',
                '512' => 'House of Multiple Occupation',
            ),
            'commercial_property_type' => array(
                '0' => 'Not Specified',
                '45' => 'Parking',
                '80' => 'Restaurant',
                '83' => 'Cafe (Commercial)',
                '86' => 'Mill (Commercial)',
                '92' => 'Castle (Commercial)',
                '137' => 'Shop (Commercial)',
                '140' => 'Riad (Commercial)',
                '178' => 'Office',
                '181' => 'Business Park (Commercial)',
                '184' => 'Serviced Office (Commercial)',
                '187' => 'Retail Property (High Street)',
                '190' => 'Retail Property (Out of Town)',
                '193' => 'Convenience Store (Commercial)',
                '196' => 'Garages (Commercial)',
                '199' => 'Hairdresser/Barber Shop (Commercial)',
                '202' => 'Hotel (Commercial)',
                '205' => 'Petrol Station (Commercial)',
                '208' => 'Post Office (Commercial)',
                '211' => 'Pub (Commercial)',
                '214' => 'Workshop & Retail Space (Commercial)',
                '217' => 'Distribution Warehouse (Commercial)',
                '220' => 'Factory (Commercial)',
                '223' => 'Heavy Industrial (Commercial)',
                '226' => 'Industrial Park (Commercial)',
                '229' => 'Light Industrial (Commercial)',
                '232' => 'Storage (Commercial)',
                '235' => 'Showroom (Commercial)',
                '238' => 'Warehouse (Commercial)',
                '241' => 'Land (Commercial)',
                '244' => 'Commercial Development',
                '247' => 'Industrial Development (Commercial)',
                '250' => 'Residential Development (Commercial)',
                '253' => 'Commercial Property',
                '256' => 'Data Centre (Commercial)',
                '259' => 'Farm (Commercial)',
                '262' => 'Healthcare Facility (Commercial)',
                '265' => 'Marine Property (Commercial)',
                '268' => 'Mixed Use (Commercial)',
                '271' => 'Research & Development Facility (Commercial)',
                '274' => 'Science Park (Commercial)',
                '277' => 'Guest House (Commercial)',
                '280' => 'Hospitality (Commercial)',
                '283' => 'Leisure Facility (Commercial)',
                '298' => 'Takeaway (Commercial)',
                '301' => 'Childcare Facility',
                '304' => 'Smallholding (Commercial)',
                '307' => 'Place of Worship (Commercial)',
                '310' => 'Trade Counter',
                '535' => 'Sports facilities',
                '538' => 'Spa',
                '541' => 'Campsite & Holiday Village',
                '544' => 'Retail Property (Shopping Centre)',
                '547' => 'Retail Property (Park)',
                '550' => 'Retail Property (Pop Up)',
                '134' => 'Bar/Nightclub',
            ),
            'price_qualifier' => array(
                '2' => 'Guide Price',
                '3' => 'Fixed Price',
                '4' => 'Offers In Excess Of',
                '5' => 'OIRO',
                '6' => 'Sale By Tender',
                '7' => 'From',
                '9' => 'Shared Ownership',
                '10' => 'Offers Over',
                '11' => 'Part Buy Part Rent',
                '12' => 'Shared Equity',
                '15' => 'Offers Invited',
                '16' => 'Coming Soon',
            ),
            'tenure' => array(
                '1' => 'Free hold',
                '2' => 'Lease hold',
                '3' => 'Feudal',
                '5' => 'Share Of Free hold',
                '4' => 'Common Hold',
            ),
            'furnished' => array(
                '0' => 'Furnished',
                '1' => 'Part Furnished',
                '2' => 'Unfurnished',
                '4' => 'Furnished Un furnished',
            ),
        );
    }
}

}