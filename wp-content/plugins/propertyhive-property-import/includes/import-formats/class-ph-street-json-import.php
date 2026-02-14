<?php
/**
 * Class for managing the import process of a Street JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Street_JSON_Import extends PH_Property_Import_Process {

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

        if ( !isset($import_settings['sales_status']) )
        {
            $importSalesStatuses = apply_filters( 'propertyhive_street_sales_statuses', array( 'for_sale', 'under_offer', 'sold_stc', 'for_sale_and_to_let' ) );
        }
        else
        {
            $importSalesStatuses = $import_settings['sales_status'];
        }

        if ( !isset($import_settings['lettings_status']) )
        {
            $importLettingsStatuses = apply_filters( 'propertyhive_street_lettings_statuses', array( 'to_let', 'let_agreed', 'for_sale_and_to_let' ) );
        }
        else
        {
            $importLettingsStatuses = $import_settings['lettings_status'];
        }

        $departments = array();
        if ( !empty($importSalesStatuses) ) { $departments['sales'] = $importSalesStatuses; }
        if ( !empty($importLettingsStatuses) ) { $departments['lettings'] = $importLettingsStatuses; }

        $departments = apply_filters( 'propertyhive_street_departments', $departments );

        $limit = $this->get_property_limit();

        foreach ( $departments as $department => $statuses )
        {
            $current_page = 1;
            $more_properties = true;

            while ( $more_properties )
            {
                $url = ( isset($import_settings['api_base_url']) && !empty($import_settings['api_base_url']) ) ? $import_settings['api_base_url'] : 'https://street.co.uk';
                $url = apply_filters( 'propertyhive_street_api_base_url', $url );
                $url .= '/api/property-feed/' . $department . '/search?';
                if ( $test !== true ) { $url .= 'include=featuresForPortals%2Crooms%2Cimages%2Cfloorplans%2Cepc%2Cbrochure%2CadditionalMedia%2Ctags%2CparkingSpaces%2CoutsideSpaces%2Cbranch'; }
                $url .= '&page%5Bnumber%5D=' . $current_page;
                $url .= '&filter%5Binclude_land%5D=true';
                if ( is_array($statuses) && !empty($statuses) )
                {
                    $url .= '&filter%5Bstatus%5D=' . implode(',', $statuses);
                }

                $headers = array(
                    'Authorization' => 'Bearer ' . $import_settings['api_key']
                );

                $headers['User-Agent'] = 'PropertyHive/' . PH_VERSION . ' PropertyHiveImportAddOn/' . PH_PROPERTYIMPORT_VERSION;
                
                $headers = apply_filters( 'propertyhive_street_headers', $headers );

                $response = wp_remote_request(
                    $url,
                    array(
                        'method' => 'GET',
                        'timeout' => 60,
                        'headers' => $headers
                    )
                );

                if ( is_wp_error( $response ) )
                {
                    $this->log_error( 'Response: ' . $response->get_error_message() );

                    return false;
                }

                $json = json_decode( $response['body'], TRUE );

                if ( json_last_error() !== JSON_ERROR_NONE ) 
                {
                    $this->log_error( 'Failed to parse JSON (' . json_last_error_msg() . '): ' . print_r( $response['body'], true ) );
                    return false;
                }

                if ( $json === FALSE ) 
                {
                    $this->log_error( 'Failed to parse JSON: ' . print_r($response['body'], true) );
                    return false;
                }

                if ( isset($json['errors']) && !empty($json['errors']) )
                {
                    foreach ( $json['errors'] as $error )
                    {
                        $this->log_error( 'Error returned by Street: ' . print_r($error, TRUE) );
                    }
                    return false;
                }
                
                $total_pages = '';
                if ( isset($json['meta']['pagination']['total_pages']) )
                {
                    if ( $current_page == $json['meta']['pagination']['total_pages'] )
                    {
                        $more_properties = false;
                    }

                    $total_pages = $json['meta']['pagination']['total_pages'];
                }
                else
                {
                    $this->log_error( 'No pagination element found in response. This should always exist so likely something went wrong. As a result we\'ll play it safe and not continue further.' );
                    return false;
                }

                $this->log("Parsing " . $department . " properties on page " . $current_page . " out of " . $total_pages);

                if ( isset($json['data']) )
                {
                    foreach ($json['data'] as $property)
                    {
                        if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                        {
                            return true;
                        }

                        $property['street_department'] = $department;
                        $property['department'] = 'residential-' . $department;

                        // Check if commercial
                        if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
                        {
                            if ( isset($property['attributes']['is_commercial']) && $property['attributes']['is_commercial'] === true )
                            {
                                $property['department'] = 'commercial';
                            }
                        }

                        if ( $property['department'] != 'commercial' && isset($property['attributes']['dual_listing']) && $property['attributes']['dual_listing'] === true )
                        {
                            $property['id'] = $property['id'] . '-' . $department;
                        }

                        $relationships = array( 
                            array(
                                'relationship_name' => 'address',
                                'included_name' => 'address',
                            ),
                            array(
                                'relationship_name' => 'details',
                                'included_name' => 'details',
                            ),
                            array(
                                'relationship_name' => 'salesListing',
                                'included_name' => 'sales_listing',
                            ),
                            array(
                                'relationship_name' => 'lettingsListing',
                                'included_name' => 'lettings_listing',
                            ),
                            array(
                                'relationship_name' => 'featuresForPortals',
                                'included_name' => 'feature',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'rooms',
                                'included_name' => 'room',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'images',
                                'included_name' => 'media',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'floorplans',
                                'included_name' => 'floorplan',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'epc',
                                'included_name' => 'epc',
                            ),
                            array(
                                'relationship_name' => 'brochure',
                                'included_name' => 'brochure',
                            ),
                            array(
                                'relationship_name' => 'additionalMedia',
                                'included_name' => 'additionalMedia',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'tags',
                                'included_name' => 'tags',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'parkingSpaces',
                                'included_name' => 'parkingSpaces',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'outsideSpaces',
                                'included_name' => 'outsideSpaces',
                                'multiple' => true
                            ),
                            array(
                                'relationship_name' => 'branch',
                                'included_name' => 'branch',
                            ),
                        );
                        $relationships = apply_filters( 'propertyhive_street_relationships', $relationships );
                        foreach ( $relationships as $relationship )
                        {
                            if ( isset($property['relationships'][$relationship['relationship_name']]['data']) )
                            {
                                if ( isset($relationship['multiple']) && $relationship['multiple'] === true )
                                {
                                    if ( !empty($property['relationships'][$relationship['relationship_name']]['data']) )
                                    {
                                        foreach ( $property['relationships'][$relationship['relationship_name']]['data'] as $relationship_data )
                                        {
                                            if ( isset($relationship_data['id']) )
                                            {
                                                foreach ( $json['included'] as $included )
                                                {
                                                    if ( /*$included['type'] == $relationship['included_name'] &&*/ $included['id'] == $relationship_data['id'] )
                                                    {
                                                        if ( !isset($property[$relationship['relationship_name']]) )
                                                        {
                                                            $property[$relationship['relationship_name']] = array();
                                                        }
                                                        $property[$relationship['relationship_name']][] = $included['attributes'];
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                                else
                                {
                                    if ( isset($property['relationships'][$relationship['relationship_name']]['data']['id']) )
                                    {
                                        foreach ( $json['included'] as $included )
                                        {
                                            if ( $included['type'] == $relationship['included_name'] && $included['id'] == $property['relationships'][$relationship['relationship_name']]['data']['id'] )
                                            {
                                                $property[$relationship['relationship_name']] = $included['attributes'];
                                            }
                                        }
                                    }
                                }
                            }
                        }

                        if ( 
                            isset($property[$department . 'Listing']['publish_after']) &&
                            !empty($property[$department . 'Listing']['publish_after']) &&
                            strtotime($property[$department . 'Listing']['publish_after']) > time()
                        )
                        {
                            // Has a 'publish_after' date in the future. Ignore
                            continue;
                        }

                        $this->properties[] = $property;
                    }
                }

                ++$current_page;
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

        do_action( "propertyhive_pre_import_properties_street_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_street_json_properties_due_import", $this->properties, $this->import_id );

        $this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

        $start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

        $property_row = 1;
        foreach ( $this->properties as $property )
        {
            do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_street_json", $property, $this->import_id, $this->instance_id );
            
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

            $display_address = $property['address']['anon_address'];
            if ( isset($property['attributes']['public_address']) && !empty($property['attributes']['public_address']) )
            {
                $display_address = $property['attributes']['public_address'];
            }

            $department = $property['department'];
            
            $post_excerpt = isset($property['details']['short_description']) ? $property['details']['short_description'] : '';
            if ( $department == 'residential-lettings' && isset($property['details']['short_description_lettings']) && !empty($property['details']['short_description_lettings']) )
            {
                $post_excerpt = $property['details']['short_description_lettings'];
            }

            $create_date = '';
            if ( isset($property['attributes']['last_instructed_' . $property['street_department'] . '_at']) && !empty($property['attributes']['last_instructed_' . $property['street_department'] . '_at']) )
            {
                $date = new DateTime($property['attributes']['last_instructed_' . $property['street_department'] . '_at']);
                $date->setTimezone(new DateTimeZone('UTC'));
                $create_date = $date->format('Y-m-d H:i:s');
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $post_excerpt, '', $create_date );

            if ( $inserted_updated !== false )
            {
                // Need to check title and excerpt and see if they've gone in as blank but weren't blank in the feed
                // If they are, then do the encoding
                $inserted_post = get_post( $post_id );
                if (
                    $inserted_post &&
                    $inserted_post->post_title == '' && $inserted_post->post_excerpt == '' &&
                    ($display_address != '' || ( isset($property['details']['short_description']) && $property['details']['short_description'] != '' ))
                )
                {
                    $my_post = array(
                        'ID'             => $post_id,
                        'post_title'     => htmlentities(mb_convert_encoding(wp_strip_all_tags( $display_address ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
                        'post_excerpt'   => htmlentities(mb_convert_encoding(( ( isset($property['details']['short_description']) ) ? $property['details']['short_description'] : '' ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
                        'post_name'      => sanitize_title($display_address),
                        'post_status'    => 'publish',
                    );

                    // Update the post into the database
                    wp_update_post( $my_post );
                }

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

                $previous_update_date = get_post_meta( $post_id, '_street_json_update_date_' . $this->import_id, TRUE);

                $skip_property = false;
                if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
                {
                    if ( !empty($previous_update_date) && $previous_update_date == $property['attributes']['updated_at'] )
                    {
                        $skip_property = true;
                    }
                }

                if ( !$skip_property )
                {
                    update_post_meta( $post_id, '_book_viewing_url', ( ( isset($property['attributes']['viewing_booking_url']) ) ? $property['attributes']['viewing_booking_url'] : '' ) );

                    // Address
                    update_post_meta( $post_id, '_reference_number', ( ( isset($property['id']) ) ? $property['id'] : '' ) );

                    $address_number = '';
                    $address_street = isset($property['address']['line_1']) ? $property['address']['line_1'] : '';
                    $address1_explode = explode(' ', $address_street);
                    if ( !empty($address1_explode) && is_numeric($address1_explode[0]) )
                    {
                        $address_number = array_shift($address1_explode);
                        $address_street = implode(' ', $address1_explode);
                    }

                    update_post_meta( $post_id, '_address_name_number', $address_number );
                    update_post_meta( $post_id, '_address_street', $address_street );
                    update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['line_2']) ) ? $property['address']['line_2'] : '' ) );
                    update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['town']) ) ? $property['address']['town'] : '' ) );
                    update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['line_3']) ) ? $property['address']['line_3'] : '' ) );
                    update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address']['postcode']) ) ? $property['address']['postcode'] : '' ) );

                    $country = get_option( 'propertyhive_default_country', 'GB' );
                    update_post_meta( $post_id, '_address_country', $country );

                    // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                    $address_fields_to_check = apply_filters( 'propertyhive_street_json_address_fields_to_check', array('town', 'line_2', 'line_3') );
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
                        update_post_meta( $post_id, '_latitude', (string)$property['address']['latitude'] );
                        update_post_meta( $post_id, '_longitude', (string)$property['address']['longitude'] );
                    }
                    else
                    {
                        // Get lat long from address if possible
                        $lat = get_post_meta( $post_id, '_latitude', TRUE);
                        $lng = get_post_meta( $post_id, '_longitude', TRUE);

                        if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
                        {
                            // No lat lng. Let's get it
                            $address_to_geocode = array();
                            $address_to_geocode_osm = array();
                            if ( isset($property['address']['line_1']) && trim($property['address']['line_1']) != '' ) { $address_to_geocode[] = $property['address']['line_1']; }
                            if ( isset($property['address']['line_2']) && trim($property['address']['line_2']) != '' ) { $address_to_geocode[] = $property['address']['line_2']; }
                            if ( isset($property['address']['line_3']) && trim($property['address']['line_3']) != '' ) { $address_to_geocode[] = $property['address']['line_3']; }
                            if ( isset($property['address']['town']) && trim($property['address']['town']) != '' ) { $address_to_geocode[] = $property['address']['town']; }
                            if ( isset($property['address']['postcode']) && trim($property['address']['postcode']) != '' ) { $address_to_geocode[] = $property['address']['postcode']; $address_to_geocode_osm[] = $property['address']['postcode']; }

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
                            $explode_branch_codes = explode(",", $branch_code);
                            $explode_branch_codes = array_map('trim', $explode_branch_codes);
                            foreach ( $explode_branch_codes as $branch_code )
                            {
                                if ( $branch_code == $property['attributes']['branch_uuid'] )
                                {
                                    $office_id = $ph_office_id;
                                    break;
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_office_id', $office_id );

                    $department = $property['department'];

                    // Is the property portal add on activated
                    if (class_exists('PH_Property_Portal'))
                    {
                        // Use the branch code to map this property to the correct agent and branch
                        $explode_agent_branch = array();
                        if (
                            isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['attributes']['branch_uuid'] . '|' . $this->import_id]) &&
                            $this->branch_mappings[str_replace("residential-", "", $department)][$property['attributes']['branch_uuid'] . '|' . $this->import_id] != ''
                        )
                        {
                            // A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
                            $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['attributes']['branch_uuid'] . '|' . $this->import_id]);
                        }
                        elseif (
                            isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['attributes']['branch_uuid']]) &&
                            $this->branch_mappings[str_replace("residential-", "", $department)][$property['attributes']['branch_uuid']] != ''
                        )
                        {
                            // No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
                            $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['attributes']['branch_uuid']]);
                        }

                        if ( !empty($explode_agent_branch) )
                        {
                            update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
                            update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );

                            $this->branch_ids_processed[] = $explode_agent_branch[1];
                        }
                        else
                        {
                            update_post_meta( $post_id, '_agent_id', '' );
                            update_post_meta( $post_id, '_branch_id', '' );
                        }
                    }

                    update_post_meta( $post_id, '_department', $department );
                    update_post_meta( $post_id, '_bedrooms', ( ( isset($property['attributes']['bedrooms']) ) ? (string)$property['attributes']['bedrooms'] : '' ) );
                    update_post_meta( $post_id, '_bathrooms', ( ( isset($property['attributes']['bathrooms']) ) ? (string)$property['attributes']['bathrooms'] : '' ) );
                    update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['attributes']['receptions']) ) ? (string)$property['attributes']['receptions'] : '' ) );
                    update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['details']['council_tax_band']) ) ? (string)$property['details']['council_tax_band'] : '' ) );

                    // Property Type
                    $prefix = '';
                    if ( $department == 'commercial' )
                    {
                        $prefix = 'commercial_';
                    }
                    $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                    $property_type_term_id = false;

                    if ( 
                        isset($property['attributes']['property_type']) &&
                        isset($property['attributes']['property_style']) &&
                        $property['attributes']['property_type'] != '' &&
                        $property['attributes']['property_style'] != ''
                    )
                    {
                        // has a type and style set
                        if ( !empty($mapping) && isset($mapping[$property['attributes']['property_type'] . ' - ' . $property['attributes']['property_style']]) )
                        {
                            $property_type_term_id = $mapping[$property['attributes']['property_type'] . ' - ' . $property['attributes']['property_style']];
                        }
                        else
                        {
                            $this->log( 'Property received with a type (' . $property['attributes']['property_type'] . ' - ' . $property['attributes']['property_style'] . ') that is not mapped', $post_id, $property['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['attributes']['property_type'] . ' - ' . $property['attributes']['property_style'], $post_id );
                        }
                    }

                    if ( $property_type_term_id === false )
                    {
                        // didn't find a type/style mapping. Try just type
                        if ( !empty($mapping) && isset($mapping[$property['attributes']['property_type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['attributes']['property_type']], $prefix . 'property_type' );
                        }
                        else
                        {
                            $this->log( 'Property received with a type (' . $property['attributes']['property_type'] . ') that is not mapped', $post_id, $property['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['attributes']['property_type'], $post_id );
                        }
                    }

                    if ( $property_type_term_id !== false )
                    {
                        wp_set_object_terms( $post_id, (int)$property_type_term_id, $prefix . 'property_type' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
                    }

                    // Residential Sales Details
                    if ( $department == 'residential-sales' )
                    {
                        $price = preg_replace("/[^0-9.]/", '', $property['salesListing']['price']);
                        if ( !empty($price) )
                        {
                            $price = round($price);
                        }

                        update_post_meta( $post_id, '_price', $price );

                        update_post_meta( $post_id, '_poa', ( ( isset($property['salesListing']['display_price']) && $property['salesListing']['display_price'] === false ) ? 'yes' : '' ) );

                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                        if ( !empty($mapping) && isset($property['salesListing']['price_qualifier']) && isset($mapping[$property['salesListing']['price_qualifier']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['salesListing']['price_qualifier']], 'price_qualifier' );
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

                        if ( isset($property['attributes']['tenure']) && strtolower($property['attributes']['tenure']) == 'leasehold' )
                        {
                            if ( isset($property['details']['shared_ownership']) ) { update_post_meta( $post_id, '_shared_ownership', ( $property['details']['shared_ownership'] === true ? 'yes' : '' ) ); }
                            if ( isset($property['details']['shared_ownership_percentage_sold']) ) { update_post_meta( $post_id, '_shared_ownership_percentage', ( $property['details']['shared_ownership'] === true ? $property['details']['shared_ownership_percentage_sold'] : '' ) ); }
                            
                            $ground_rent = '';
                            if ( isset($property['details']['ground_rent']) && !empty($property['details']['ground_rent']) ) 
                            { 
                                $ground_rent = $property['details']['ground_rent'];

                                if ( isset($property['details']['ground_rent_period']) && $property['details']['ground_rent_period'] == 'month' )
                                {
                                    $ground_rent = round($property['details']['ground_rent'] * 12, 2);
                                }
                            }
                            update_post_meta( $post_id, '_ground_rent', $ground_rent );
                            if ( isset($property['details']['ground_rent_review_period_years']) && !empty($property['details']['ground_rent_review_period_years']) ) { update_post_meta( $post_id, '_ground_rent_review_years', $property['details']['ground_rent_review_period_years'] ); }
                            
                            $service_charge = '';
                            if ( isset($property['details']['service_charge']) && !empty($property['details']['service_charge']) ) 
                            { 
                                $service_charge = $property['details']['service_charge'];

                                if ( isset($property['details']['service_charge_period']) && $property['details']['service_charge_period'] == 'month' )
                                {
                                    $service_charge = round($property['details']['service_charge'] * 12, 2);
                                }
                            }
                            update_post_meta( $post_id, '_service_charge', $service_charge );
                            
                            $leasehold_years_remaining = '';
                            if ( isset($property['attributes']['lease_expiry_date']) && !empty($property['attributes']['lease_expiry_date']) )
                            {
                                $date1 = new DateTime();
                                $date2 = new DateTime($property['attributes']['lease_expiry_date']);
                                $interval = $date1->diff($date2);
                                $leasehold_years_remaining = $interval->y;
                            }
                            update_post_meta( $post_id, '_leasehold_years_remaining', $leasehold_years_remaining );
                        }
                    }
                    elseif ( $department == 'residential-lettings' )
                    {
                        //Price
                        $rent = '';
                        if ( isset($property['lettingsListing']['price_pcm']) && !empty($property['lettingsListing']['price_pcm']) )
                        {
                            $rent = preg_replace("/[^0-9.]/", '', $property['lettingsListing']['price_pcm']);
                            if ( !empty($rent) )
                            {
                                $rent = round($rent);
                            }
                        }
                        elseif ( isset($property['lettingsListing']['price']) && !empty($property['lettingsListing']['price']) )
                        {
                            $rent = preg_replace("/[^0-9.]/", '', $property['lettingsListing']['price']);
                            if ( !empty($rent) )
                            {
                                $rent = round($rent);
                            }
                        }
                        $rent_frequency = 'pcm';
                        /*switch ( $property['lettingsListing']['price_qualifier'] )
                        {
                            // This is based on the assumption that rent frequencies are held in price_qualifer. The schema doesn't reference this explicitly
                            // If true, this will need to be mapped when we have accurate mappings
                        }*/
                        update_post_meta( $post_id, '_rent', $rent );
                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

                        update_post_meta( $post_id, '_available_date', ( ( isset($property['lettingsListing']['available_from']) && !empty($property['lettingsListing']['available_from']) ) ? date("Y-m-d", strtotime($property['lettingsListing']['available_from'])) : '' ) );
                        
                        update_post_meta( $post_id, '_poa', ( ( isset($property['lettingsListing']['display_price']) && $property['lettingsListing']['display_price'] === false ) ? 'yes' : '') );

                        update_post_meta( $post_id, '_deposit', ( ( isset($property['lettingsListing']['deposit']) && !empty($property['lettingsListing']['deposit']) ) ? $property['lettingsListing']['deposit'] : '' ) );
                    }
                    elseif ( $department == 'commercial' )
                    {
                        if ( isset($property['attributes']['dual_listing']) && $property['attributes']['dual_listing'] === true )
                        {

                        }
                        else
                        {
                            update_post_meta( $post_id, '_for_sale', '' );
                            update_post_meta( $post_id, '_to_rent', '' );
                        }

                        if ( $property['street_department'] == 'sales' )
                        {
                            update_post_meta( $post_id, '_for_sale', 'yes' );

                            update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                            $price = round(preg_replace("/[^0-9.]/", '', $property['salesListing']['price']));
                            update_post_meta( $post_id, '_price_from', $price );
                            update_post_meta( $post_id, '_price_to', $price );

                            update_post_meta( $post_id, '_price_units', '' );

                            update_post_meta( $post_id, '_price_poa', ( ( isset($property['salesListing']['display_price']) && $property['salesListing']['display_price'] === false ) ? 'yes' : '' ) );

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

                        if ( $property['street_department'] == 'lettings' )
                        {
                            update_post_meta( $post_id, '_to_rent', 'yes' );

                            update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                            if ( isset($property['lettingsListing']['price_pcm']) )
                            {
                                $rent = preg_replace("/[^0-9.]/", '', $property['lettingsListing']['price_pcm']);
                            }
                            else
                            {
                                $rent = preg_replace("/[^0-9.]/", '', $property['lettingsListing']['price']);
                            }
                            update_post_meta( $post_id, '_rent_from', $rent );
                            update_post_meta( $post_id, '_rent_to', $rent );

                            update_post_meta( $post_id, '_rent_units', 'pcm');

                            update_post_meta( $post_id, '_rent_poa', ( ( isset($property['lettingsListing']['display_price']) && $property['lettingsListing']['display_price'] === false ) ? 'yes' : '') );
                        }

                        $size = preg_replace("/[^0-9.]/", '', $property['attributes']['floor_area']);
                        if ( empty($size) )
                        {
                            $size = '';
                        }
                        update_post_meta( $post_id, '_floor_area_from', $size );
                        update_post_meta( $post_id, '_floor_area_from_sqft', $size );
                        update_post_meta( $post_id, '_floor_area_to', $size );
                        update_post_meta( $post_id, '_floor_area_to_sqft', $size );
                        update_post_meta( $post_id, '_floor_area_units', 'sqft' );

                        $size = preg_replace("/[^0-9.]/", '', $property['attributes']['plot_area']);
                        if ( empty($size) )
                        {
                            $size = '';
                        }
                        update_post_meta( $post_id, '_site_area_from', $size );
                        update_post_meta( $post_id, '_site_area_from_sqft', $size );
                        update_post_meta( $post_id, '_site_area_to', $size );
                        update_post_meta( $post_id, '_site_area_to_sqft', $size );
                        update_post_meta( $post_id, '_site_area_units', 'sqft' );
                    }

                    // Parking
                    $mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();

                    $parking_term_ids = array();
                    if ( !empty($mapping) && isset($property['parkingSpaces']) )
                    {
                        foreach ( $property['parkingSpaces'] as $parking_space )
                        {
                            if ( isset($parking_space['parking_space_type']) && isset($mapping[$parking_space['parking_space_type']]) )
                            {
                                $parking_term_ids[] = (int)$mapping[$parking_space['parking_space_type']];
                            }
                        }
                    }
                    if ( !empty($parking_term_ids) )
                    {
                        wp_set_object_terms( $post_id, $parking_term_ids, 'parking' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'parking' );
                    }

                    // Outside Space
                    $mapping = isset($import_settings['mappings']['outside_space']) ? $import_settings['mappings']['outside_space'] : array();

                    $outside_space_term_ids = array();
                    if ( !empty($mapping) && isset($property['outsideSpaces']) )
                    {
                        foreach ( $property['outsideSpaces'] as $outside_space )
                        {
                            if ( isset($outside_space['type']) && isset($mapping[$outside_space['type']]) )
                            {
                                $outside_space_term_ids[] = (int)$mapping[$outside_space['type']];
                            }
                        }
                    }
                    if ( !empty($outside_space_term_ids) )
                    {
                        wp_set_object_terms( $post_id, $outside_space_term_ids, 'outside_space' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'outside_space' );
                    }

                    // Store price in common currency (GBP) used for ordering
                    $ph_countries = new PH_Countries();
                    $ph_countries->update_property_price_actual( $post_id );

                    $departments_with_residential_details = apply_filters( 'propertyhive_departments_with_residential_details', array( 'residential-sales', 'residential-lettings' ) );
                    if ( in_array($department, $departments_with_residential_details) )
                    {
                        // Electricity
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['details']['material_information']['electricity_supply'] ) ) 
                        {
                            foreach ( $property['details']['material_information']['electricity_supply'] as $supply ) 
                            {
                                if ( empty($supply) ) { continue; }

                                $supply_value = $supply;
                                switch ( $supply_value ) 
                                {
                                    case 'national_grid': $utility_type[] = 'mains_supply'; break;
                                    case 'solar_panels': $utility_type[] = 'solar_pv_panels'; break;
                                    case 'wind_turbine': $utility_type[] = 'wind_turbine'; break;
                                    case 'other': 
                                    {
                                        $utility_type[] = 'other';
                                        if ( 
                                            isset($property['details']['material_information']['electricity_supply_other'])
                                            &&
                                            !empty($property['details']['material_information']['electricity_supply_other'])
                                        )
                                        {
                                            $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $property['details']['material_information']['electricity_supply_other']; 
                                        }
                                        break;
                                    }
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_electricity_type', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_electricity_type_other', $utility_type_other );
                        }

                        // Water
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['details']['material_information']['water_supply'] ) ) 
                        {
                            foreach ( $property['details']['material_information']['water_supply'] as $supply ) 
                            {
                                if ( empty($supply) ) { continue; }

                                $supply_value = (string)$supply;
                                switch ( $supply_value ) 
                                {
                                    case 'direct_main_waters': $utility_type[] = 'mains_supply'; break;
                                    case 'wells': 
                                    case 'boreholes': 
                                    case 'springs': 
                                        $utility_type[] = 'private_supply'; break;
                                    case 'other': 
                                    {
                                        $utility_type[] = 'other';
                                        if ( 
                                            isset($property['details']['material_information']['water_supply_other'])
                                            &&
                                            !empty($property['details']['material_information']['water_supply_other'])
                                        )
                                        {
                                            $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $property['details']['material_information']['water_supply_other']; 
                                        }
                                        break;
                                    }
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_water_type', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_water_type_other', $utility_type_other );
                        }
                        
                        // Heating
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['details']['material_information']['heating_supply'] ) ) 
                        {
                            foreach ( $property['details']['material_information']['heating_supply'] as $source ) 
                            {
                                if ( empty($source) ) { continue; }

                                $source_value = $source;
                                switch ( $source_value ) 
                                {
                                    case 'electric_central': $utility_type[] = 'electric'; break;
                                    case 'gas_central': $utility_type[] = 'gas_central'; break;
                                    case 'gas_other': $utility_type[] = 'gas'; break;
                                    case 'wood_burner': $utility_type[] = 'wood_burner'; break;
                                    case 'biomass_boiler': $utility_type[] = 'biomass_boiler'; break;
                                    case 'solar_panels': $utility_type[] = 'solar'; break;
                                    case 'other': 
                                    {
                                        $utility_type[] = 'other';
                                        if ( 
                                            isset($property['details']['material_information']['heating_supply_other'])
                                            &&
                                            !empty($property['details']['material_information']['heating_supply_other'])
                                        )
                                        {
                                            $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $property['details']['material_information']['heating_supply_other']; 
                                        }
                                        break;
                                    }
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_heating_type', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_heating_type_other', $utility_type_other );
                        }

                        // Broadband
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( 
                            isset( $property['details']['material_information']['broadband']['value'] ) 
                            &&
                            !empty( $property['details']['material_information']['broadband']['value'] )
                        ) 
                        {
                            $supply_value = $property['details']['material_information']['broadband']['value'];
                            switch ( $supply_value ) 
                            {
                                case 'adsl': $utility_type[] = 'adsl'; break;
                                case 'cable': $utility_type[] = 'cable'; break;
                                case 'fttc': $utility_type[] = 'fttc'; break;
                                case 'fttp': $utility_type[] = 'fttp'; break;
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_broadband_type', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_broadband_type_other', $utility_type_other );
                        }

                        // Sewerage
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['details']['material_information']['sewerage'] ) ) 
                        {
                            foreach ( $property['details']['material_information']['sewerage'] as $supply ) 
                            {
                                if ( empty($supply) ) { continue; }

                                $supply_value = $supply;
                                switch ( $supply_value ) 
                                {
                                    case 'standard': $utility_type[] = 'mains_supply'; break;
                                    case 'septic_tank': 
                                    case 'cesspit': 
                                    case 'cesspool': 
                                        $utility_type[] = 'private_supply';
                                        break;
                                    case 'other': 
                                    {
                                        $utility_type[] = 'other';
                                        if ( 
                                            isset($property['details']['material_information']['sewerage_other'])
                                            &&
                                            !empty($property['details']['material_information']['sewerage_other'])
                                        )
                                        {
                                            $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $property['details']['material_information']['sewerage_other']; 
                                        }
                                        break;
                                    }
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_sewerage_type', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_sewerage_type_other', $utility_type_other );
                        }

                        $flooded_in_last_five_years = '';
                        if ( 
                            isset( $property['details']['material_information']['has_flooded_in_last_five_years'] ) && 
                            $property['details']['material_information']['has_flooded_in_last_five_years'] === true 
                        )
                        {
                            $flooded_in_last_five_years = 'yes';
                        }
                        update_post_meta($post_id, '_flooded_in_last_five_years', $flooded_in_last_five_years );

                        $flood_defenses = '';
                        if ( 
                            isset( $property['details']['material_information']['has_flood_defences'] ) && 
                            $property['details']['material_information']['has_flood_defences'] === true 
                        )
                        {
                            $flood_defenses = 'yes';
                        }
                        update_post_meta($post_id, '_flood_defences', $flood_defenses );

                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['details']['material_information']['sources_of_flooding'] ) ) 
                        {
                            foreach ( $property['details']['material_information']['sources_of_flooding'] as $source ) 
                            {
                                if ( empty($source) ) { continue; }

                                $source_value = $source;
                                switch ( $source_value ) 
                                {
                                    case 'other': 
                                    {
                                        $utility_type[] = 'other';
                                        if ( 
                                            isset($property['details']['material_information']['sources_of_flooding_other'])
                                            &&
                                            !empty($property['details']['material_information']['sources_of_flooding_other'])
                                        )
                                        {
                                            $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $property['details']['material_information']['sources_of_flooding_other']; 
                                        }
                                        break;
                                    }
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_flood_source_type', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_flood_source_type_other', $utility_type_other );
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
                        $featured = '';
                        if ( isset($property['tags']) && is_array($property['tags']) && !empty($property['tags']) )
                        {
                            foreach ( $property['tags'] as $tag )
                            {
                                if ( isset($tag['tag']) && strtolower($tag['tag']) == 'featured' )
                                {
                                    $featured = 'yes';
                                }
                            }
                        }
                        update_post_meta( $post_id, '_featured', $featured );
                    }

                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                        array();
                  
                    $status_field = str_replace('residential-', '', str_replace('sales', 'sale', $department));
                    if ( $department == 'commercial' )
                    {
                        if (isset($property['attributes']['sale_status']) && !empty($property['attributes']['sale_status']))
                        {
                            $status_field = 'sale';
                        }
                        if (isset($property['attributes']['lettings_status']) && !empty($property['attributes']['lettings_status']))
                        {
                            $status_field = 'lettings';
                        }
                    }
                    if ( !empty($mapping) && isset($property['attributes'][$status_field . '_status']) && isset($mapping[$property['attributes'][$status_field . '_status']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['attributes'][$status_field . '_status']], 'availability' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'availability' );
                    }

                    // Features
                    $i = 0;
                    if ( isset($property['featuresForPortals']) && is_array($property['featuresForPortals']) )
                    {
                        foreach ( $property['featuresForPortals'] as $feature )
                        {
                            update_post_meta( $post_id, '_feature_' . $i, $feature['name'] );
                            ++$i;
                        }
                    }
                    update_post_meta( $post_id, '_features', $i );

                    // Rooms / Descriptions
                    // For now put the whole description in one room / description
                    // A Rooms node does exist. We might need to bring these in as well, or check whether they or full_description are populated
                    if ( $department == 'commercial' )
                    {
                        $descriptions = 0;

                        update_post_meta( $post_id, '_description_name_' . $descriptions, '' );
                        update_post_meta( $post_id, '_description_' . $descriptions, str_replace(array("\r\n", "\n"), "", $property['details']['full_description']) );

                        ++$descriptions;

                        if ( isset($property['rooms']) && !empty($property['rooms']) )
                        {
                            foreach ( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_description_name_' . $descriptions, $room['name'] );
                                update_post_meta( $post_id, '_description_' . $descriptions, $room['description'] );
                                ++$descriptions;
                            }
                        }

                        if ( isset($property['branch']['disclaimer']) && !empty($property['branch']['disclaimer']) )
                        {
                            update_post_meta( $post_id, '_description_name_' . $descriptions, 'Disclaimer' );
                            update_post_meta( $post_id, '_description_' . $descriptions, $property['branch']['disclaimer'] );
                            ++$descriptions;
                        }

                        update_post_meta( $post_id, '_descriptions', $descriptions );
                    }
                    else
                    {
                        $rooms = 0;

                        $full_description = isset($property['details']['full_description']) ? $property['details']['full_description'] : '';
                        if ( $department == 'residential-lettings' && isset($property['details']['full_description_lettings']) && !empty($property['details']['full_description_lettings']) )
                        {
                            $full_description = $property['details']['full_description_lettings'];
                        }

                        if ( !empty($full_description) )
                        {
                            update_post_meta( $post_id, '_room_name_' . $rooms, '' );
                            update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
                            update_post_meta( $post_id, '_room_description_' . $rooms, str_replace(array("\r\n", "\n"), "", $full_description) );

                            ++$rooms;
                        }

                        if ( isset($property['rooms']) && !empty($property['rooms']) )
                        {
                            foreach ( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_room_name_' . $rooms, $room['name'] );
                                update_post_meta( $post_id, '_room_dimensions_' . $rooms, $room['formatted_dimensions'] );
                                update_post_meta( $post_id, '_room_description_' . $rooms, $room['description'] );
                                ++$rooms;
                            }
                        }

                        if ( isset($property['branch']['disclaimer']) && !empty($property['branch']['disclaimer']) )
                        {
                            update_post_meta( $post_id, '_room_name_' . $rooms, 'Disclaimer' );
                            update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
                            update_post_meta( $post_id, '_room_description_' . $rooms, $property['branch']['disclaimer'] );
                            ++$rooms;
                        }

                        update_post_meta( $post_id, '_rooms', $rooms );
                    }

                    // Media - Images
                    $default_size = false;
                    if ( get_option('propertyhive_images_stored_as', '') == 'urls' )
                    {
                        $default_size = 'large';
                    }

                    $media = array();
                    if (isset($property['images']) && !empty($property['images']))
                    {
                        foreach ($property['images'] as $photo)
                        {

                            $size = apply_filters( 'propertyhive_street_image_size', $default_size ); // thumbnail, small, medium, large, hero, full
                            $url = ( $size !== false && isset($photo['urls'][$size]) ) ? $photo['urls'][$size] : $photo['url'];
                            
                            $explode_url = explode("?", $url);
                            $filename = basename( $explode_url[0] );

                            $media[] = array(
                                'url' => $url,
                                'filename' => $filename,
                                'description' => ( (isset($photo['title'])) ? $photo['title'] : '' ),
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'photo', $media, false );

                    // Media - Floorplans
                    $media = array();
                    if (isset($property['floorplans']) && !empty($property['floorplans']))
                    {
                        foreach ($property['floorplans'] as $floorplan)
                        {
                            $url = $floorplan['url'];
                            
                            $explode_url = explode("?", $url);
                            $filename = basename( $explode_url[0] );

                            $media[] = array(
                                'url' => $url,
                                'filename' => $filename,
                                'description' => ( (isset($floorplan['title'])) ? $floorplan['title'] : '' ),
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'floorplan', $media, false );

                    // Media - Brochures
                    $media = array();
                    if (isset($property['brochure']) && !empty($property['brochure']))
                    {
                        $url = $property['brochure']['url'];
                        
                        $explode_url = explode("?", $url);
                        $filename = basename( $explode_url[0] );

                        $media[] = array(
                            'url' => $url,
                            'filename' => $filename,
                        );
                    }
                    if (isset($property['additionalMedia']) && !empty($property['additionalMedia']))
                    {
                        foreach ($property['additionalMedia'] as $brochure)
                        {
                            $url = $brochure['url'];
                    
                            $explode_url = explode("?", $url);
                            $filename = basename( $explode_url[0] );

                            $media[] = array(
                                'url' => $url,
                                'filename' => $filename,
                                'description' => ( isset($brochure['title']) ? $brochure['title'] : '' )
                            );
                        }
                    }
                    if ( isset($property['attributes']['property_urls']) && !empty($property['attributes']['property_urls']) && is_array($property['attributes']['property_urls']) )
                    {
                        foreach ( $property['attributes']['property_urls'] as $property_url )
                        {
                            if (
                                isset($property_url['media_type']) && 
                                strtolower($property_url['media_type']) == 'brochure' &&
                                isset($property_url['media_url']) && 
                                !empty($property_url['media_url'])
                            )
                            {
                                $url = $property_url['media_url'];
                            
                                $explode_url = explode("?", $url);
                                $filename = basename( $explode_url[0] );

                                $media[] = array(
                                    'url' => $url,
                                    'filename' => $filename,
                                );
                            }
                        }
                    }

                    $this->import_media( $post_id, $property['id'], 'brochure', $media, false );


                    // Media - EPCs
                    $media = array();
                    if (isset($property['epc']) && !empty($property['epc']))
                    {
                        $url = $property['epc']['report_url'];
                        
                        $explode_url = explode("?", $url);
                        $filename = basename( $explode_url[0] );

                        $media[] = array(
                            'url' => $url,
                            'filename' => $filename,
                        );
                    }

                    $epc_data = array();
                    if ( 
                        apply_filters( 'propertyhive_property_import_street_import_epc_ratings', TRUE ) === TRUE
                        &&
                        (
                            (
                                isset($property['epc']['energy_efficiency_current']) && $property['epc']['energy_efficiency_current'] != '' && 
                                isset($property['epc']['energy_efficiency_potential']) && $property['epc']['energy_efficiency_potential'] != '' 
                            )
                            ||
                            ( 
                                isset($property['epc']['environment_impact_current']) && $property['epc']['environment_impact_current'] != '' && 
                                isset($property['epc']['environment_impact_potential']) && $property['epc']['environment_impact_potential'] != '' 
                            )
                        )
                    )
                    {
                        $epc_data = array(
                            'eec' => $property['epc']['energy_efficiency_current'],
                            'eep' => $property['epc']['energy_efficiency_potential'],
                            'eic' => $property['epc']['environment_impact_current'],
                            'eip' => $property['epc']['environment_impact_potential'],
                        );
                    }

                    $this->import_media( $post_id, $property['id'], 'epc', $media, false, false, $epc_data );

                    // Media - Virtual Tours
                    $virtual_tours = array();
                    if ( isset($property['details']['virtual_tour']) && !empty($property['details']['virtual_tour']) )
                    {
                        $virtual_tours[] = $property['details']['virtual_tour'];
                    }
                    if ( isset($property['attributes']['property_urls']) && !empty($property['attributes']['property_urls']) && is_array($property['attributes']['property_urls']) )
                    {
                        foreach ( $property['attributes']['property_urls'] as $property_url )
                        {
                            if ( 
                                isset($property_url['media_type']) && 
                                (
                                    strpos(strtolower($property_url['media_type']), 'virtual') !== FALSE ||
                                    strpos(strtolower($property_url['media_type']), 'video') !== FALSE ||
                                    strpos(strtolower($property_url['media_type']), 'tour') !== FALSE
                                ) &&
                                isset($property_url['media_url']) && 
                                !empty($property_url['media_url']) &&
                                !in_array($property_url['media_url'], $virtual_tours)
                            )
                            {
                                $virtual_tours[] = $property_url['media_url'];
                            }
                        }
                    }

                    update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                    foreach ($virtual_tours as $i => $virtual_tour)
                    {
                        update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                    }

                    $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );
                }
                else
                {
                    $this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
                }

                update_post_meta( $post_id, '_street_json_update_date_' . $this->import_id, $property['attributes']['updated_at'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_street_json", $post_id, $property, $this->import_id );

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
        
        do_action( "propertyhive_post_import_properties_street_json" );

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
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
                'Sold STC' => 'Sold STC',
                'For Sale and To Let' => 'For Sale and To Let',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
                'For Sale and To Let' => 'For Sale and To Let',
            ),
            'commercial_availability' => array(
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
                'Sold STC' => 'Sold STC',
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
                'Let' => 'Let',
                'For Sale and To Let' => 'For Sale and To Let',
            ),
            'property_type' => array(
                'House' => 'House',
                'House - Detached' => 'House - Detached',
                'House - Semi Detached' => 'House - Semi Detached',
                'House - Terraced' => 'House - Terraced',
                'House - Mid-Terraced' => 'House - Mid-Terraced',
                'House - End of Terrace' => 'House - End of Terrace',
                'House - Link Detached' => 'House - Link Detached',
                'Bungalow' => 'Bungalow',
                'Bungalow - Detached' => 'Bungalow - Detached',
                'Bungalow - Semi Detached' => 'Bungalow - Semi Detached',
                'Bungalow - Terraced' => 'Bungalow - Terraced',
                'Bungalow - Mid-Terraced' => 'Bungalow - Mid-Terraced',
                'Bungalow - End of Terrace' => 'Bungalow - End of Terrace',
                'Bungalow - Link Detached' => 'Bungalow - Link Detached',
                'Flat' => 'Flat',
                'Maisonette' => 'Maisonette',
                'Cottage' => 'Cottage',
                'Apartment' => 'Apartment',
                'Penthouse' => 'Penthouse',
            ),
            'commercial_property_type' => array(
                'Office (Commercial)' => 'Office (Commercial)',
                'Retail (Commercial)' => 'Retail (Commercial)',
                'Industrial (Commercial)' => 'Industrial (Commercial)',
                'Farm (Commercial)' => 'Farm (Commercial)',
                'Other (Commercial)' => 'Other (Commercial)',
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'In Excess of' => 'In Excess of',
                'Offers in Region of' => 'Offers in Region of',
                'Offers Over' => 'Offers Over',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'parking' => array(
                'Garage' => 'Garage',
                'On Road' => 'On Road',
                'Off Road' => 'Off Road',
                'On Drive' => 'On Drive',
                'Allocated Parking' => 'Allocated Parking',
                'Secure Gated' => 'Secure Gated',
                'Car Port' => 'Car Port',
                'Permit' => 'Permit',
            ),
            'outside_space' => array(
                'Garden' => 'Garden',
                'Front Garden' => 'Front Garden',
                'Rear Garden' => 'Rear Garden',
                'Balcony' => 'Balcony',
                'Communal Garden' => 'Communal Garden',
                'Yard' => 'Yard',
                'Roof Terrace' => 'Roof Terrace',
            ),
        );
    }
}

}