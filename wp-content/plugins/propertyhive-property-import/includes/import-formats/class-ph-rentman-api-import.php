<?php
/**
 * Class for managing the import process of a Rentman API JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Rentman_API_Import extends PH_Property_Import_Process {

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

        $current_page = 1;
        $more_properties = true;

        while ( $more_properties )
        {
            $url = 'https://www.rentman.online/propertyadvertising.php';

            $headers = array(
                'token' => $import_settings['token'],
                'limit' => 50,
                'page' => $current_page,
                //'noimage' => 1
            );
            $headers = apply_filters( 'propertyhive_rentman_api_property_headers', $headers );

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

            if ( wp_remote_retrieve_response_code($response) !== 200 )
            {
                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
                return false;
            }

            $json = json_decode( $response['body'], TRUE );

            if ($json !== FALSE)
            {
                $this->log("Parsing properties on page " . $current_page);

                if ( !empty($json) )
                {
                    foreach ($json as $property)
                    {
                        if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                        {
                            return true;
                        }

                        $property['images'] = array();

                        if ( $test === false )
                        {
                            $url = 'https://www.rentman.online/propertymedia.php';

                            $headers = array(
                                'token' => $import_settings['token'],
                                'propref' => $property['propref']
                            );
                            $headers = apply_filters( 'propertyhive_rentman_api_property_media_headers', $headers );

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

                            if ( wp_remote_retrieve_response_code($response) !== 200 )
                            {
                                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting media for property ' . $property['propref'] . '. Error message: ' . wp_remote_retrieve_response_message($response) );
                                return false;
                            }

                            $json = json_decode( $response['body'], TRUE );

                            if ($json !== FALSE)
                            {
                                $property['images'] = $json;

                                if ( !empty($json) ) { $this->log( 'Images ' . $property['propref'] ); }
                            }
                            else
                            {
                                // Failed to parse JSON
                                $this->log_error( 'Failed to parse media JSON for property ' . $property['propref'] . ': ' . print_r($response['body'], true) );

                                return false;
                            }

                            usleep(1000000); // Sleep for a hundreth of a second. Not needed but doing to avoid hammering Rentman servers
                        }

                        $this->properties[] = $property;
                    }
                }
                else
                {
                    $more_properties = false;
                }

                ++$current_page;
            }
            else
            {
                // Failed to parse JSON
                $this->log_error( 'Failed to parse properties JSON: ' . print_r($response['body'], true) );

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

        do_action( "propertyhive_pre_import_properties_rentman_api", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_rentman_api_properties_due_import", $this->properties, $this->import_id );

        $this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

        $start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

        $property_row = 1;
        foreach ( $this->properties as $property )
        {
            do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_rentman_api", $property, $this->import_id, $this->instance_id );
            
            if ( !empty($start_at_property) )
            {
                // we need to start on a certain property
                if ( $property['propref'] == $start_at_property )
                {
                    // we found the property. We'll continue for this property onwards
                    $this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['propref'] );
                    $start_at_property = false;
                }
                else
                {
                    ++$property_row;
                    continue;
                }
            }

            update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['propref'], false );

            $this->log( 'Importing property ' . $property_row .' with reference ' . $property['propref'], 0, $property['propref'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = $property['displayaddress'];

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['propref'], $property, $display_address, ( ( isset($property['comments']) ) ? $property['comments'] : '' ) );

            if ( $inserted_updated !== false )
            {
                // Need to check title and excerpt and see if they've gone in as blank but weren't blank in the feed
                // If they are, then do the encoding
                $inserted_post = get_post( $post_id );
                if (
                    $inserted_post &&
                    $inserted_post->post_title == '' && $inserted_post->post_excerpt == '' &&
                    ($display_address != '' || ( isset($property['comments']) && $property['comments'] != '' ))
                )
                {
                    $my_post = array(
                        'ID'             => $post_id,
                        'post_title'     => htmlentities(mb_convert_encoding(wp_strip_all_tags( $display_address ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
                        'post_excerpt'   => htmlentities(mb_convert_encoding(( ( isset($property['comments']) ) ? $property['comments'] : '' ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['propref'] );

                update_post_meta( $post_id, $imported_ref_key, $property['propref'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                // Address
                update_post_meta( $post_id, '_reference_number', ( ( isset($property['propref']) ) ? $property['propref'] : '' ) );

                update_post_meta( $post_id, '_address_name_number', ( ( isset($property['number']) ) ? $property['number'] : '' ) );
                update_post_meta( $post_id, '_address_street', ( ( isset($property['street']) ) ? $property['street'] : '' ) );
                update_post_meta( $post_id, '_address_two', ( ( isset($property['area']) ) ? $property['area'] : '' ) );
                update_post_meta( $post_id, '_address_three', ( ( isset($property['address3']) ) ? $property['address3'] : '' ) );
                update_post_meta( $post_id, '_address_four', ( ( isset($property['address4']) ) ? $property['address4'] : '' ) );
                update_post_meta( $post_id, '_address_postcode', ( ( isset($property['postcode']) ) ? $property['postcode'] : '' ) );

                $country = get_option( 'propertyhive_default_country', 'GB' );
                update_post_meta( $post_id, '_address_country', $country );

                // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                $address_fields_to_check = apply_filters( 'propertyhive_rentman_api_address_fields_to_check', array('area', 'address3', 'address4') );
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
                $lat_lng = explode(",", $property['geolocation']);
                $lat_lng = array_filter($lat_lng);
                if ( count($lat_lng) == 2 )
                {
                    update_post_meta( $post_id, '_latitude', $lat_lng[0] );
                    update_post_meta( $post_id, '_longitude', $lat_lng[1] );
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
                        if ( isset($property['number']) && trim($property['number']) != '' ) { $address_to_geocode[] = $property['number']; }
                        if ( isset($property['street']) && trim($property['street']) != '' ) { $address_to_geocode[] = $property['street']; }
                        if ( isset($property['address3']) && trim($property['address3']) != '' ) { $address_to_geocode[] = $property['address3']; }
                        if ( isset($property['address4']) && trim($property['address4']) != '' ) { $address_to_geocode[] = $property['address4']; }
                        if ( isset($property['postcode']) && trim($property['postcode']) != '' ) { $address_to_geocode[] = $property['postcode']; $address_to_geocode_osm[] = $property['postcode']; }

                        $return = $this->do_geocoding_lookup( $post_id, $property['propref'], $address_to_geocode, $address_to_geocode_osm, $country );
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
                            if ( $branch_code == $property['branch'] )
                            {
                                $office_id = $ph_office_id;
                                break;
                            }
                        }
                    }
                }
                update_post_meta( $post_id, '_office_id', $office_id );

                $department = $property['rentorbuy'] == 1 ? 'residential-lettings' : 'residential-sales';
                if ( isset($property['commercial']) && $property['commercial'] == 1 )
                {
                    $department = 'commercial';
                }

                // Is the property portal add on activated
                if (class_exists('PH_Property_Portal'))
                {
                    // Use the branch code to map this property to the correct agent and branch
                    $explode_agent_branch = array();
                    if (
                        isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['branch'] . '|' . $this->import_id]) &&
                        $this->branch_mappings[str_replace("residential-", "", $department)][$property['branch'] . '|' . $this->import_id] != ''
                    )
                    {
                        // A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
                        $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['branch'] . '|' . $this->import_id]);
                    }
                    elseif (
                        isset($this->branch_mappings[str_replace("residential-", "", $department)][$property['branch']]) &&
                        $this->branch_mappings[str_replace("residential-", "", $department)][$property['branch']] != ''
                    )
                    {
                        // No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
                        $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][$property['branch']]);
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
                update_post_meta( $post_id, '_bedrooms', ( ( isset($property['beds']) ) ? (string)$property['beds'] : '' ) );
                update_post_meta( $post_id, '_bathrooms', ( ( isset($property['baths']) ) ? (string)$property['baths'] : '' ) );
                update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['receps']) ) ? (string)$property['receps'] : '' ) );
                update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['taxband']) ) ? (string)$property['taxband'] : '' ) );

                // Property Type
                $prefix = '';
                if ( $department == 'commercial' )
                {
                    $prefix = 'commercial_';
                }
                $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                if ( isset($property['TYPE']) )
                {
                    if ( !empty($mapping) && isset($mapping[$property['TYPE']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['TYPE']], $prefix . 'property_type' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                        $this->log( 'Property received with a type (' . $property['TYPE'] . ') that is not mapped', $post_id, $property['listingId'] );

                        $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['TYPE'], $post_id );
                    }
                }
                else
                {
                    wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
                }

                // Residential Sales Details
                if ( $department == 'residential-sales' )
                {
                    $price = round(preg_replace("/[^0-9.]/", '', $property['saleprice']));
                    update_post_meta( $post_id, '_price', $price );

                    update_post_meta( $post_id, '_poa', ( strpos(strtolower($property['displayprice']), 'poa') !== false || strpos(strtolower($property['displayprice']), 'application') !== false ) ? 'yes' : '' );

                    // Price qualifier
                    $price_qualifier_id = false;
                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
                    foreach ( $mapping as $price_qualifier => $price_qualifier_term_id )
                    {
                        if ( strpos(strtolower($property['displayprice']), strtolower($price_qualifier)) !== false )
                        {
                            $price_qualifier_id = (int)$price_qualifier_term_id;
                        }
                    }
                    if ( $price_qualifier_id !== false )
                    {
                        wp_set_object_terms( $post_id, $price_qualifier_id, 'price_qualifier' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id,'price_qualifier' );
                    }

                    // Tenure
                    $mapping = isset($import_settings['mappings']['tenure']) ? 
                        $import_settings['mappings']['tenure'] : 
                        array();
                  
                    if ( !empty($mapping) && isset($property['tenure']) && isset($mapping[$property['tenure']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['tenure']], 'tenure' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'tenure' );
                    }
                }
                elseif ( $department == 'residential-lettings' )
                {
                    $rent = round(preg_replace("/[^0-9.]/", '', $property['rentmonth']));
                    $rent_frequency = 'pcm';
                    update_post_meta( $post_id, '_rent', $rent );
                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

                    update_post_meta( $post_id, '_available_date', ( ( isset($property['available']) && !empty($property['available']) ) ? date("Y-m-d", strtotime($property['available'])) : '' ) );
                    
                    update_post_meta( $post_id, '_poa', ( strpos(strtolower($property['displayprice']), 'poa') !== false || strpos(strtolower($property['displayprice']), 'application') !== false ) ? 'yes' : '' );

                    update_post_meta( $post_id, '_deposit', '' );
                }
                elseif ( $department == 'commercial' )
                {
                    update_post_meta( $post_id, '_for_sale', '' );
                    update_post_meta( $post_id, '_to_rent', '' );

                    if ( $property['rentorbuy'] == 2 || $property['rentorbuy'] == 3 )
                    {
                        update_post_meta( $post_id, '_for_sale', 'yes' );

                        update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                        $price = round(preg_replace("/[^0-9.]/", '', $property['saleprice']));
                        update_post_meta( $post_id, '_price_from', $price );
                        update_post_meta( $post_id, '_price_to', $price );

                        update_post_meta( $post_id, '_price_units', '' );

                        update_post_meta( $post_id, '_price_poa', ( strpos(strtolower($property['displayprice']), 'poa') !== false || strpos(strtolower($property['displayprice']), 'application') !== false ) ? 'yes' : '' );
                    }

                    if ( $property['rentorbuy'] == 1 || $property['rentorbuy'] == 3 )
                    {
                        update_post_meta( $post_id, '_to_rent', 'yes' );

                        update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                        $rent = round(preg_replace("/[^0-9.]/", '', $property['rentmonth']));
                        update_post_meta( $post_id, '_rent_from', $rent );
                        update_post_meta( $post_id, '_rent_to', $rent );

                        update_post_meta( $post_id, '_rent_units', 'pcm');

                        update_post_meta( $post_id, '_rent_poa', ( strpos(strtolower($property['displayprice']), 'poa') !== false || strpos(strtolower($property['displayprice']), 'application') !== false ) ? 'yes' : '' );
                    }

                    $size = '';
                    update_post_meta( $post_id, '_floor_area_from', $size );
                    update_post_meta( $post_id, '_floor_area_from_sqft', $size );
                    update_post_meta( $post_id, '_floor_area_to', $size );
                    update_post_meta( $post_id, '_floor_area_to_sqft', $size );
                    update_post_meta( $post_id, '_floor_area_units', 'sqft' );

                    $size = '';
                    update_post_meta( $post_id, '_site_area_from', $size );
                    update_post_meta( $post_id, '_site_area_from_sqft', $size );
                    update_post_meta( $post_id, '_site_area_to', $size );
                    update_post_meta( $post_id, '_site_area_to_sqft', $size );
                    update_post_meta( $post_id, '_site_area_units', 'sqft' );
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
                    $featured = '';
                    if ( isset($property['featured']) && $property['featured'] == 1 )
                    {
                        $featured = 'yes';
                    }
                    update_post_meta( $post_id, '_featured', $featured );
                }

                // Availability
                $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                    $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                    array();
              
                if ( !empty($mapping) && isset($property['STATUS']) && isset($mapping[$property['STATUS']]) )
                {
                    wp_set_object_terms( $post_id, (int)$mapping[$property['STATUS']], 'availability' );
                }
                else
                {
                    wp_delete_object_term_relationships( $post_id, 'availability' );
                }

                // Features
                $i = 0;
                $features = explode("\r\n", $property['bullets']);
                $features = array_filter($features);
                if ( !empty($features) )
                {
                    foreach ( $features as $feature )
                    {
                        update_post_meta( $post_id, '_feature_' . $i, trim($feature) );
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
                    update_post_meta( $post_id, '_description_' . $descriptions, str_replace(array("\r\n", "\n"), "", $property['DESCRIPTION']) );

                    ++$descriptions;

                    update_post_meta( $post_id, '_descriptions', $descriptions );
                }
                else
                {
                    $rooms = 0;

                    update_post_meta( $post_id, '_room_name_' . $rooms, '' );
                    update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
                    update_post_meta( $post_id, '_room_description_' . $rooms, str_replace(array("\r\n", "\n"), "", $property['DESCRIPTION']) );

                    ++$rooms;

                    update_post_meta( $post_id, '_rooms', $rooms );
                }

                // Media - Images
                /*$media = array();
                if (isset($property['images']) && !empty($property['images']))
                {
                    foreach ($property['images'] as $photo)
                    {
                        $size = apply_filters( 'propertyhive_street_image_size', false ); // thumbnail, small, medium, large, hero, full
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

                $this->import_media( $post_id, $property['propref'], 'photo', $media, false );

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

                $this->import_media( $post_id, $property['propref'], 'floorplan', $media, false );

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

                $this->import_media( $post_id, $property['propref'], 'brochure', $media, false );

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

                $this->import_media( $post_id, $property['propref'], 'epc', $media, false );*/

                // Media - Virtual Tours
                $virtual_tours = array();
                if ( isset($property['evt']) && !empty($property['evt']) )
                {
                    $virtual_tours[] = $property['evt'];
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['propref'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_rentman_api", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['propref'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;

        } // end foreach property
        
        do_action( "propertyhive_post_import_properties_rentman_api" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $import_refs[] = $property['propref'];
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
                'For Sale' => 'For Sale',
                'ForSale&ToLet' => 'ForSale&ToLet',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'ForSale&ToLet' => 'ForSale&ToLet',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Unavailable' => 'Unavailable',
                'Withdrawn' => 'Withdrawn',
                'Valuation' => 'Valuation',
                'For Sale' => 'For Sale',
                'ForSale&ToLet' => 'ForSale&ToLet',
                'Sold' => 'Sold',
            ),
            'property_type' => array(
                'Detached' => 'Detached',
                'Semi' => 'Semi',
                'Terraced' => 'Terraced',
                'Garage' => 'Garage',
                'Parking Space' => 'Parking Space',
            ),
            'commercial_property_type' => array(
                'Office' => 'Office',
                'Shop' => 'Shop',
            ),
            'price_qualifier' => array(
                'Asking' => 'Asking',
                'Guide' => 'Guide',
                'Excess' => 'Excess',
                'Fixed' => 'Fixed',
                'Region' => 'Region',
            ),
            'tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '4' => 'Shared Ownership',
                '3' => 'Share of Freehold',
            ),
        );
    }
}

}