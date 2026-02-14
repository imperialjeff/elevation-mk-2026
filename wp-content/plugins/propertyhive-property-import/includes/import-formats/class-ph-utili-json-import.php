<?php
/**
 * Class for managing the import process of a Utili JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Utili_JSON_Import extends PH_Property_Import_Process {

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

        $contents = '';

        $response = wp_remote_post( 
            'https://pro.utili.co.uk/api/getProperties', 
            array(
                'method' => 'POST',
                'timeout' => 120,
                'headers' => array(),
                'body' => array( 
                    'appkey' => $import_settings['api_key'], 
                    'account' => $import_settings['account_name'] 
                ),
            )
        );

        if ( !is_wp_error( $response ) && is_array( $response ) ) 
        {
           $contents = $response['body'];
        } 
        else 
        {
           $this->log_error( "Failed to obtain JSON. Dump of response as follows: " . print_r($response, TRUE) );

            return false;
        }

        $this->log("Parsing properties");

        $json = json_decode( $contents, TRUE );

        if ($json !== FALSE && is_array($json) && !empty($json))
        {
            if ( isset($json['status']) && $json['status'] == 'ok' )
            {
                $limit = $this->get_property_limit();

                $this->log("Found " . count($json['properties']) . " properties in JSON ready for parsing");

                foreach ($json['properties'] as $property)
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
                // Status not 'ok'
                $this->log_error( 'Status not OK: ' . print_r($json, true) );
                return false;
            }
        }
        else
        {
            // Failed to parse JSON
            $this->log_error( 'Failed to parse JSON file. Possibly invalid JSON: ' . print_r($contents, true) );
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

        $commercial_active = false;
        if ( get_option( 'propertyhive_active_departments_commercial', '' ) == 'yes' )
        {
            $commercial_active = true;
        }

        $this->import_start();

        do_action( "propertyhive_pre_import_properties_utili_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_utili_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_utili_json", $property, $this->import_id, $this->instance_id );
            
            if ( !empty($start_at_property) )
            {
                // we need to start on a certain property
                if ( $property['utili_ref'] == $start_at_property )
                {
                    // we found the property. We'll continue for this property onwards
                    $this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['utili_ref'] );
                    $start_at_property = false;
                }
                else
                {
                    ++$property_row;
                    continue;
                }
            }

            update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['utili_ref'], false );

            $this->log( 'Importing property ' . $property_row . ' with reference ' . $property['utili_ref'], 0, $property['utili_ref'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $post_date = isset($property['date_created_formatted']) ? date( 'Y-m-d H:i:s', strtotime(str_replace('/', '-', $property['date_created_formatted'])) ) : '';

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['utili_ref'], $property, $property['advertise_address'], $property['summary'], '', $post_date );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['utili_ref'] );

                update_post_meta( $post_id, $imported_ref_key, $property['utili_ref'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                // Address
                update_post_meta( $post_id, '_reference_number', ( ( isset($property['agent_ref']) ) ? $property['agent_ref'] : '' ) );
                update_post_meta( $post_id, '_address_name_number', ( ( isset($property['address_1']) ) ? $property['address_1'] : '' ) );
                update_post_meta( $post_id, '_address_street', ( ( isset($property['address_2']) ) ? $property['address_2'] : '' ) );
                update_post_meta( $post_id, '_address_two', ( ( isset($property['address_3']) ) ? $property['address_3'] : '' ) );
                update_post_meta( $post_id, '_address_three', ( ( isset($property['town']) ) ? $property['town'] : '' ) );
                update_post_meta( $post_id, '_address_four', ( ( isset($property['county']) ) ? $property['county'] : '' ) );
                update_post_meta( $post_id, '_address_postcode', ( ( isset($property['postcode']) ) ? $property['postcode'] : '' ) );

                $country = get_option( 'propertyhive_default_country', 'GB' );
                update_post_meta( $post_id, '_address_country', $country );

                // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                $address_fields_to_check = apply_filters( 'propertyhive_utili_json_address_fields_to_check', array('address_2', 'address_3', 'town', 'county') );
                $location_term_ids = array();

                foreach ( $address_fields_to_check as $address_field )
                {
                    if ( isset($property[$address_field]) && trim($property[$address_field]) != '' ) 
                    {
                        $term = term_exists( trim($property[$address_field]), 'location');
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
                if ( isset($property['lat']) && isset($property['lng']) && $property['lat'] != '' && $property['lng'] != '' && $property['lat'] != '0' && $property['lng'] != '0' )
                {
                    update_post_meta( $post_id, '_latitude', $property['lat'] );
                    update_post_meta( $post_id, '_longitude', $property['lng'] );
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
                        if ( isset($property['address_1']) && trim($property['address_1']) != '' ) { $address_to_geocode[] = $property['address_1']; }
                        if ( isset($property['address_2']) && trim($property['address_2']) != '' ) { $address_to_geocode[] = $property['address_2']; }
                        if ( isset($property['address_3']) && trim($property['address_3']) != '' ) { $address_to_geocode[] = $property['address_3']; }
                        if ( isset($property['town']) && trim($property['town']) != '' ) { $address_to_geocode[] = $property['town']; }
                        if ( isset($property['county']) && trim($property['county']) != '' ) { $address_to_geocode[] = $property['county']; }
                        if ( isset($property['postcode']) && trim($property['postcode']) != '' ) { $address_to_geocode[] = $property['postcode']; $address_to_geocode_osm[] = $property['postcode']; }
                        
                        $return = $this->do_geocoding_lookup( $post_id, $property['utili_ref'], $address_to_geocode, $address_to_geocode_osm, $country );
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
                if ( isset($property['category_id']) && $property['category_id'] == 2 )
                {
                    $department = 'residential-lettings';
                }
                if ( $commercial_active )
                {
                    // Check if the type is any of the commercial types
                    $commercial_property_types = $this->get_mapping_values('commercial_property_type');
                    if ( isset($commercial_property_types[$property['type_name']]) )
                    {
                        $department = 'commercial';
                    }
                }
                update_post_meta( $post_id, '_department', $department );
                
                update_post_meta( $post_id, '_bedrooms', ( ( isset($property['bedrooms']) ) ? $property['bedrooms'] : '' ) );
                update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bathrooms']) ) ? $property['bathrooms'] : '' ) );
                update_post_meta( $post_id, '_reception_rooms', '' );

                $prefix = '';
                if ( $department == 'commercial' )
                {
                    $prefix = 'commercial_';
                }
                $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
                
                if ( isset($property['type_name']) )
                {
                    if ( !empty($mapping) && isset($mapping[$property['type_name']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['type_name']], $prefix . 'property_type' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                        $this->log( 'Property received with a type (' . $property['type_name'] . ') that is not mapped', $post_id, $property['utili_ref'] );

                        $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['type_name'], $post_id );
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
                    $price = round(preg_replace("/[^0-9.]/", '', $property['price']));

                    update_post_meta( $post_id, '_price', $price );
                    update_post_meta( $post_id, '_price_actual', $price );
                    update_post_meta( $post_id, '_poa', ( ( isset($property['qualifier_name']) && strtolower($property['qualifier_name']) == 'poa' ) ? 'yes' : '') );
                    update_post_meta( $post_id, '_currency', 'GBP' );

                    // Price Qualifier
                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                    if ( !empty($mapping) && isset($property['qualifier_name']) && isset($mapping[$property['qualifier_name']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['qualifier_name']], 'price_qualifier' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                    }
                }
                elseif ( $department == 'residential-lettings' )
                {
                    // Clean price
                    $price = round(preg_replace("/[^0-9.]/", '', $property['price']));

                    update_post_meta( $post_id, '_rent', $price );

                    $rent_frequency = 'pcm';
                    $price_actual = $price;
                    switch ($property['freq_name'])
                    {
                        case "pm": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
                        case "pw": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
                        case "pppw": 
                        { 
                            $rent_frequency = 'ppww'; 
                            $price_actual = ($price * 52) / 12; 
                            if ( $property['bedrooms'] != '' && $property['bedrooms'] != '0' && apply_filters( 'propertyhive_pppw_to_consider_bedrooms', true ) == true )
                            {
                                $price_actual = $price_actual / $property['bedrooms'];
                            }
                            break; 
                        }
                        case "pa": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
                    }
                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
                    update_post_meta( $post_id, '_price_actual', $price_actual );
                    update_post_meta( $post_id, '_currency', 'GBP' );
                    
                    update_post_meta( $post_id, '_poa', ( ( isset($property['qualifier_name']) && strtolower($property['qualifier_name']) == 'poa' ) ? 'yes' : '') );

                    update_post_meta( $post_id, '_deposit', '' );
                    update_post_meta( $post_id, '_available_date', ( ( isset($property['let_date_available']) ) ? $property['let_date_available'] : '' ) );

                    // Furnished
                    $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

                    if ( !empty($mapping) && isset($property['let_furn_id']) && isset($mapping[$property['let_furn_id']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['let_furn_id']], 'furnished' );
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

                    if ( isset($property['category_id']) && $property['category_id'] != 2 )
                    {
                        update_post_meta( $post_id, '_for_sale', 'yes' );

                        update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                        $price = round(preg_replace("/[^0-9.]/", '', $property['price']));
                        update_post_meta( $post_id, '_price_from', $price );
                        update_post_meta( $post_id, '_price_to', $price );

                        update_post_meta( $post_id, '_price_units', '' );

                        update_post_meta( $post_id, '_price_poa', ( ( isset($property['qualifier_name']) && strtolower($property['qualifier_name']) == 'poa' ) ? 'yes' : '') );
                    }

                    if ( isset($property['category_id']) && $property['category_id'] == 2 )
                    {
                        update_post_meta( $post_id, '_to_rent', 'yes' );

                        update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                        $rent = round(preg_replace("/[^0-9.]/", '', $property['price']));
                        update_post_meta( $post_id, '_rent_from', $rent );
                        update_post_meta( $post_id, '_rent_to', $rent );

                        $rent_frequency = 'pcm';
                        switch ($property['freq_name'])
                        {
                            case "pm": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
                            case "pw": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
                            case "pppw": 
                            { 
                                $rent_frequency = 'ppww'; 
                                $price_actual = ($price * 52) / 12; 
                                if ( $property['bedrooms'] != '' && $property['bedrooms'] != '0' && apply_filters( 'propertyhive_pppw_to_consider_bedrooms', true ) == true )
                                {
                                    $price_actual = $price_actual / $property['bedrooms'];
                                }
                                break; 
                            }
                            case "pa": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
                        }
                        update_post_meta( $post_id, '_rent_units', $rent_frequency );

                        update_post_meta( $post_id, '_rent_poa', ( ( isset($property['qualifier_name']) && strtolower($property['qualifier_name']) == 'poa' ) ? 'yes' : '') );

                        // Store price in common currency (GBP) used for ordering
                        $ph_countries = new PH_Countries();
                        $ph_countries->update_property_price_actual( $post_id );

                        update_post_meta( $post_id, '_floor_area_from', '' );
                        update_post_meta( $post_id, '_floor_area_from_sqft', '' );
                        update_post_meta( $post_id, '_floor_area_to', '' );
                        update_post_meta( $post_id, '_floor_area_to_sqft', '' );
                        update_post_meta( $post_id, '_floor_area_units', 'sqft' );

                        update_post_meta( $post_id, '_site_area_from', '' );
                        update_post_meta( $post_id, '_site_area_from_sqft', '' );
                        update_post_meta( $post_id, '_site_area_to', '' );
                        update_post_meta( $post_id, '_site_area_to_sqft', '' );
                        update_post_meta( $post_id, '_site_area_units', 'sqft' );
                    }
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
                    update_post_meta( $post_id, '_featured', ( (isset($property['featured']) && $property['featured'] == '1') ? 'yes' : '' ) );
                }
            
                // Availability
                $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                    $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                    array();

                if ( !empty($mapping) && isset($property['status']) && isset($mapping[$property['status']]) )
                {
                    wp_set_object_terms( $post_id, (int)$mapping[$property['status']], 'availability' );
                }
                else
                {
                    wp_delete_object_term_relationships( $post_id, 'availability' );
                }

                // Features
                $features = array();
                for ( $i = 1; $i <= 10; ++$i )
                {
                    if ( isset($property['feature_' . $i]) && trim($property['feature_' . $i]) != '' )
                    {
                        $features[] = $property['feature_' . $i];
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
                    update_post_meta( $post_id, '_descriptions', '1' );
                    update_post_meta( $post_id, '_description_name_0', '' );
                    update_post_meta( $post_id, '_description_0', str_replace(array("\r\n", "\n"), "", $property['description']) );
                }
                else
                {
                    update_post_meta( $post_id, '_rooms', '1' );
                    update_post_meta( $post_id, '_room_name_0', '' );
                    update_post_meta( $post_id, '_room_dimensions_0', '' );
                    update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", $property['description']) );
                }

                // Media - Images
                $media = array();
                if ( isset($property['images']) && is_array($property['images']) && !empty($property['images']) )
                {
                    foreach ( $property['images'] as $image )
                    {
                        $url = 'https://s3-eu-west-1.amazonaws.com/assets-pro-utili/' . $import_settings['account_name'] . '/images/' . $image . '_1024x1024.jpg';

                        $media[] = array(
                            'url' => $url,
                        );
                    }
                }

                $this->import_media( $post_id, $property['utili_ref'], 'photo', $media, false );

                // Media - Floorplans
                $media = array();
                if ( isset($property['floorplans']) && is_array($property['floorplans']) && !empty($property['floorplans']) )
                {
                    foreach ( $property['floorplans'] as $floorplan )
                    {
                        $url = 'https://s3-eu-west-1.amazonaws.com/assets-pro-utili/' . $import_settings['account_name'] . '/floorplans/' . $floorplan . '_1024x1024.jpg';

                        $media[] = array(
                            'url' => $url,
                        );
                    }
                }

                $this->import_media( $post_id, $property['utili_ref'], 'floorplan', $media, false );

                // Media - Brochures
                $media = array();
                if ( isset($property['pdfs']) && is_array($property['pdfs']) && !empty($property['pdfs']) )
                {
                    foreach ( $property['pdfs'] as $pdf )
                    {
                        $url = 'https://s3-eu-west-1.amazonaws.com/assets-pro-utili/' . $import_settings['account_name'] . '/pdfs/' . $pdf . '.pdf';

                        $media[] = array(
                            'url' => $url,
                        );
                    }
                }

                $this->import_media( $post_id, $property['utili_ref'], 'brochure', $media, false );

                // Media - EPCs
                $media = array();
                if ( isset($property['epcs']) && is_array($property['epcs']) && !empty($property['epcs']) )
                {
                    foreach ( $property['epcs'] as $epc )
                    {
                        $url = 'https://s3-eu-west-1.amazonaws.com/assets-pro-utili/' . $import_settings['account_name'] . '/epcs/' . $epc . '_1024x1024.jpg';

                        $media[] = array(
                            'url' => $url,
                        );
                    }
                }

                $this->import_media( $post_id, $property['utili_ref'], 'epc', $media, false );

                // Media - Virtual Tours
                $virtual_tours = array();
                if ( isset($property['virtual_tour_url']) && $property['virtual_tour_url'] != '' )
                {
                    $virtual_tours[] = $property['virtual_tour_url'];
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ( $virtual_tours as $i => $virtual_tour )
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['utili_ref'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_utili_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['utili_ref'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;

        } // end foreach property

        do_action( "propertyhive_post_import_properties_utili_json" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $import_refs[] = $property['utili_ref'];
        }

        $this->do_remove_old_properties( $import_refs );

        unset($import_refs);
    }

    public function get_default_mapping_values()
    {
        return array(
             'sales_availability' => array(
                'Available' => 'Available',
                'Sale Agreed' => 'Sale Agreed',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Sale Agreed' => 'Sale Agreed',
            ),
            'property_type' => array(
                'House' => 'House',
                'Detached' => 'Detached',
                'Semi-Detached' => 'Semi-Detached',
                'End of Terrace' => 'End of Terrace',
                'Terraced' => 'Terraced',
                'Apartment' => 'Apartment',
                'Flat' => 'Flat',
                'Ground Flat' => 'Ground Flat',
                'Maisonette' => 'Maisonette',
            ),
            'commercial_property_type' => array(
                'Bar' => 'Bar',
                'Cafe' => 'Cafe',
                'Commercial' => 'Commercial',
                'Office' => 'Office',
                'Warehouse' => 'Warehouse',
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'Fixed Price' => 'Fixed Price',
            ),
            'furnished' => array(
                '0' => 'Furnished',
                '1' => 'Part Furnished',
                '2' => 'Unfurnished',
                '4' => 'Furnished / Unfurnished',
                '3' => 'Not Specified',
            ),
        );
    }
}

}