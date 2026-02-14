<?php
/**
 * Class for managing the import process of a Veco JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Veco_JSON_Import extends PH_Property_Import_Process {

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

        $response = wp_remote_get( 
            'https://passport.eurolink.co/api/properties/v1/?size=9999', 
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $import_settings['access_token'],
                    'Content-Type' => 'application/json'
                ),
                'body' => '',
                'timeout' => 120,
            )
        );

        if ( !is_wp_error( $response ) && is_array( $response ) ) 
        {
            $contents = $response['body'];

            $json = json_decode( $contents, TRUE );

            if ($json !== FALSE && isset($json['Data']) && is_array($json['Data']) && !empty($json['Data']))
            {
                $limit = $this->get_property_limit();

                $this->log("Parsing properties");
                
                foreach ($json['Data'] as $property)
                {
                    if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                    {
                        return true;
                    }

                    if (isset($property['_source']))
                    {
                        $this->properties[] = $property['_source'];
                    }
                }

                $this->log("Found " . count($this->properties) . " properties in JSON ready for importing");
            }
            else
            {
                // Failed to parse JSON
                $this->log_error( 'Failed to parse JSON file. Possibly invalid JSON: ' . print_r($contents, true) );
                return false;
            }
        }
        else
        {
            $this->log_error( 'Failed to obtain JSON. Dump of response as follows: ' . print_r($response, TRUE) );
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

        do_action( "propertyhive_pre_import_properties_veco_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_veco_json_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_veco_json", $property, $this->import_id, $this->instance_id );
            
            if ( !empty($start_at_property) )
            {
                // we need to start on a certain property
                if ( $property['WebID'] == $start_at_property )
                {
                    // we found the property. We'll continue for this property onwards
                    $this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['WebID'] );
                    $start_at_property = false;
                }
                else
                {
                    ++$property_row;
                    continue;
                }
            }

            update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['WebID'], false );

            $this->log( 'Importing property with reference ' . $property['WebID'], 0, $property['WebID'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = isset($property['Property']['ShortAddress']) ? $property['Property']['ShortAddress'] : '';
            if ( trim($display_address) == '' )
            {
                $display_address = array();
                if ( isset($property['Address']['Street']) && trim($property['Address']['Street']) != '' )
                {
                    $display_address[] = trim($property['Address']['Street']);
                }
                if ( isset($property['Address']['Line2']) && trim($property['Address']['Line2']) != '' )
                {
                    $display_address[] = trim($property['Address']['Line2']);
                }
                elseif ( isset($property['Address']['PostTown']) && trim($property['Address']['PostTown']) != '' )
                {
                    $display_address[] = trim($property['Address']['PostTown']);
                }
                elseif ( isset($property['Address']['County']) && trim($property['Address']['County']) != '' )
                {
                    $display_address[] = trim($property['Address']['County']);
                }
                $display_address = implode(", ", $display_address);
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['WebID'], $property, $display_address, !is_null($property['Property']['SummaryDescription']) ? $property['Property']['SummaryDescription'] : '', '', ( isset($property['Property']['InsertDate']) ) ? date( 'Y-m-d H:i:s', strtotime( $property['Property']['InsertDate'] )) : '' );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['WebID'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                $previous_update_date = get_post_meta( $post_id, '_veco_json_update_date_' . $this->import_id, TRUE);

                $skip_property = false;
                if (isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
                {
                    if ( isset($property['Property']['UpdatedDate']) && $previous_update_date == $property['Property']['UpdatedDate'] )
                    {
                        $skip_property = true;
                    }
                }

                $country = get_option( 'propertyhive_default_country', 'GB' );

                // Coordinates
                if ( isset($property['Location']['Latitude']) && isset($property['Location']['Longitude']) && $property['Location']['Latitude'] != '' && $property['Location']['Longitude'] != '' && $property['Location']['Latitude'] != '0' && $property['Location']['Longitude'] != '0' )
                {
                    update_post_meta( $post_id, '_latitude', ( ( isset($property['Location']['Latitude']) ) ? $property['Location']['Latitude'] : '' ) );
                    update_post_meta( $post_id, '_longitude', ( ( isset($property['Location']['Longitude']) ) ? $property['Location']['Longitude'] : '' ) );
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
                        if ( isset($property['Address']['Street']) && trim($property['Address']['Street']) != '' ) { $address_to_geocode[] = $property['Address']['Street']; }
                        if ( isset($property['Address']['PostTown']) && trim($property['Address']['PostTown']) != '' ) { $address_to_geocode[] = $property['Address']['PostTown']; }
                        if ( isset($property['Address']['County']) && trim($property['Address']['County']) != '' ) { $address_to_geocode[] = $property['Address']['County']; }
                        if ( isset($property['Postcode']['PostcodeFull']) && trim($property['Postcode']['PostcodeFull']) != '' ) { $address_to_geocode[] = $property['Postcode']['PostcodeFull']; $address_to_geocode_osm[] = $property['Postcode']['PostcodeFull']; }

                        $return = $this->do_geocoding_lookup( $post_id, $property['WebID'], $address_to_geocode, $address_to_geocode_osm, $country );
                    }
                }

                if ( !$skip_property )
                {
                    update_post_meta( $post_id, $imported_ref_key, $property['WebID'] );

                    // Address
                    update_post_meta( $post_id, '_reference_number', $property['WebID'] );
                    update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['Address']['BuildingName']) ) ? $property['Address']['BuildingName'] : '' ) . ' ' . ( ( isset($property['Address']['BuildingNumber']) ) ? $property['Address']['BuildingNumber'] : '' ) ) );
                    update_post_meta( $post_id, '_address_street', ( ( isset($property['Address']['Street']) ) ? $property['Address']['Street'] : '' ) );
                    update_post_meta( $post_id, '_address_two', ( ( isset($property['Address']['Line2']) ) ? $property['Address']['Line2'] : '' ) );
                    update_post_meta( $post_id, '_address_three', ( ( isset($property['Address']['PostTown']) ) ? $property['Address']['PostTown'] : '' ) );
                    update_post_meta( $post_id, '_address_four', ( ( isset($property['Address']['County']) ) ? $property['Address']['County'] : '' ) );
                    update_post_meta( $post_id, '_address_postcode', ( ( isset($property['Postcode']['PostcodeFull']) ) ? $property['Postcode']['PostcodeFull'] : '' ) );

                    update_post_meta( $post_id, '_address_country', $country );

                    // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                    $address_fields_to_check = apply_filters( 'propertyhive_veco_json_address_fields_to_check', array('Line2', 'Line3', 'Line4', 'PostTown', 'County') );
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
                            if ( $branch_code == $property['Office']['ID'] || $branch_code == $property['Office']['Name'] )
                            {
                                $office_id = $ph_office_id;
                                break;
                            }
                        }
                    }
                    update_post_meta( $post_id, '_office_id', $office_id );

                    // Residential Details
                    $department = 'residential-sales';
                    if ( $property['Property']['Category'] == 'Lettings' )
                    {
                        $department = 'residential-lettings';
                    }
                    update_post_meta( $post_id, '_department', $department );
                    
                    update_post_meta( $post_id, '_bedrooms', ( ( isset($property['Property']['Bedrooms']) ) ? $property['Property']['Bedrooms'] : '' ) );
                    update_post_meta( $post_id, '_bathrooms', ( ( isset($property['Property']['Bathrooms']) ) ? $property['Property']['Bathrooms'] : '' ) );
                    update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['Property']['Receptions']) ) ? $property['Property']['Receptions'] : '' ) );

                    update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['Property']['CouncilTaxBand']) ) ? $property['Property']['CouncilTaxBand'] : '' ) );

                    if (
                        isset($property['Property']['LeaseExpiryYear']) && !empty($property['Property']['LeaseExpiryYear']) 
                    )
                    {
                        $leasehold_years_remaining = $property['Property']['LeaseExpiryYear'] - date("Y");
                        update_post_meta( $post_id, '_leasehold_years_remaining', $leasehold_years_remaining );
                    }

                    update_post_meta( $post_id, '_ground_rent', ( ( isset($property['Charges']['AnnualGroundRent']) && !empty($property['Charges']['AnnualGroundRent']) ) ? $property['Property']['AnnualGroundRent'] : '' ) );
                    update_post_meta( $post_id, '_service_charge', ( ( isset($property['Charges']['AnnualServiceCharge']) && !empty($property['Charges']['AnnualServiceCharge']) ) ? $property['Property']['AnnualServiceCharge'] : '' ) );

                    $prefix = '';
                    $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
                    
                    if ( isset($property['Property']['PropertyType']) )
                    {
                        if ( !empty($mapping) && isset($mapping[$property['Property']['PropertyType']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['Property']['PropertyType']], $prefix . 'property_type' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                            $this->log( 'Property received with a type (' . $property['Property']['PropertyType'] . ') that is not mapped', $post_id, $property['WebID'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['Property']['PropertyType'], $post_id );
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
                        $price = round(preg_replace("/[^0-9.]/", '', $property['Property']['Amount']));

                        update_post_meta( $post_id, '_price', $price );
                        update_post_meta( $post_id, '_price_actual', $price );
                        update_post_meta( $post_id, '_poa', ( $property['Property']['PriceStatus'] == 'Price on Application' ) ? 'yes' : '' );
                        update_post_meta( $post_id, '_currency', 'GBP' );
                        
                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                        if ( !empty($mapping) && isset($property['Property']['PriceStatus']) && isset($mapping[$property['Property']['PriceStatus']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['Property']['PriceStatus']], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                        }

                        // Tenure
                        $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

                        if ( !empty($mapping) && isset($property['Tenure']['Tenure']) && isset($mapping[$property['Tenure']['Tenure']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['Tenure']['Tenure']], 'tenure' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'tenure' );
                        }
                    }

                    if ( $department == 'residential-lettings' )
                    {
                        update_post_meta( $post_id, '_rent', $property['Property']['Amount'] );

                        $rent_frequency = 'pcm';
                        $price_actual = $property['Property']['Amount'];
                        switch ($property['Property']['RentPeriod'])
                        {
                            case "per week": { $rent_frequency = 'pw'; $price_actual = ($property['Property']['Amount'] * 52) / 12; break; }
                            case "per month": { $rent_frequency = 'pcm'; $price_actual = $property['Property']['Amount']; break; }
                        }
                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
                        update_post_meta( $post_id, '_price_actual', $price_actual );
                        update_post_meta( $post_id, '_currency', 'GBP' );
                        update_post_meta( $post_id, '_poa', ( $property['Property']['PriceStatus'] == 'Price on Application' ) ? 'yes' : '' );

                        update_post_meta( $post_id, '_deposit', ( ( isset($property['Property']['DepositAmount']) && !empty($property['Property']['DepositAmount']) ) ? $property['Property']['DepositAmount'] : '' ) );
                        update_post_meta( $post_id, '_available_date', ( (isset($property['Property']['AvailableFromDate']) && $property['Property']['AvailableFromDate'] != '') ? date("Y-m-d", strtotime($property['Property']['AvailableFromDate'])) : '' ) );

                        // Furnished
                        $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

                        if ( !empty($mapping) && isset($property['Property']['Furnished']) && isset($mapping[$property['Property']['Furnished']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['Property']['Furnished']], 'furnished' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'furnished' );
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
                        update_post_meta( $post_id, '_featured', ( isset($property['Property']['Featured']) && strtolower($property['Property']['Featured']) == 'true' ) ? 'yes' : '' );
                    }

                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                        array();

                    if ( !empty($mapping) && isset($property['Property']['Status']) && isset($mapping[$property['Property']['Status']]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[$property['Property']['Status']], 'availability' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'availability' );
                    }

                    // Features
                    $features = array();
                    for ( $i = 1; $i <= 10; ++$i )
                    {
                        if ( isset($property['Features']['Feature' . $i]) && trim($property['Features']['Feature' . $i]) != '' )
                        {
                            $features[] = trim($property['Features']['Feature' . $i]);
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
                    update_post_meta( $post_id, '_room_description_0', str_replace(array("\r\n", "\n"), "", $property['Property']['MarketingDescriptionHTML']) );
                    
                    // Media - Images
                    $media = array();
                    for ( $i = 1; $i <= 25; ++$i )
                    {
                        if ( isset($property['Photos']['Photo' . $i]) && !empty($property['Photos']['Photo' . $i]) )
                        {
                            // This is a URL
                            $url = 'https://passport.eurolink.co/api/properties/v1/media/' . $property['Photos']['Photo' . $i];
                            
                            $media[] = array(
                                'url' => $url,
                                'description' => isset($property['Photos']['Description' . $i]) ? $property['Photos']['Description' . $i] : '',
                                'modified' => $property['Property']['UpdatedDate'],
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['WebID'], 'photo', $media, true );

                    // Media - Floorplans
                    $media = array();
                    for ( $i = 1; $i <= 5; ++$i )
                    {
                        if ( isset($property['FloorPlans']['Plan' . $i]) && !empty($property['FloorPlans']['Plan' . $i]) )
                        {
                            // This is a URL
                            $url = 'https://passport.eurolink.co/api/properties/v1/media/' . $property['FloorPlans']['Plan' . $i];
                            
                            $media[] = array(
                                'url' => $url,
                                'modified' => $property['Property']['UpdatedDate'],
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['WebID'], 'floorplan', $media, true );

                    // Media - Brochures
                    $media = array();
                    for ( $i = 1; $i <= 2; ++$i )
                    {
                        if ( isset($property['Brochures']['Document' . $i]) && $property['Brochures']['Document' . $i] != '' )
                        {
                            // This is a URL
                            $url = 'https://passport.eurolink.co/api/properties/v1/media/' . $property['Brochures']['Document' . $i];
                            
                            $media[] = array(
                                'url' => $url,
                                'modified' => $property['Property']['UpdatedDate'],
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['WebID'], 'brochure', $media, true );

                    // Media - EPCs
                    $media = array();
                    for ( $i = 1; $i <= 2; ++$i )
                    {
                        if ( isset($property['EPCs']['Image' . $i]) && $property['EPCs']['Image' . $i] != '' )
                        {
                            // This is a URL
                            $url = 'https://passport.eurolink.co/api/properties/v1/media/' . $property['EPCs']['Image' . $i];
                            
                            $media[] = array(
                                'url' => $url,
                                'modified' => $property['Property']['UpdatedDate'],
                            );
                        }
                    }
                    for ( $i = 1; $i <= 2; ++$i )
                    {
                        if ( isset($property['EPCs']['Document' . $i]) && $property['EPCs']['Document' . $i] != '' )
                        {
                            // This is a URL
                            $url = 'https://passport.eurolink.co/api/properties/v1/media/' . $property['EPCs']['Document' . $i];
                            
                            $media[] = array(
                                'url' => $url,
                                'modified' => $property['Property']['UpdatedDate'],
                            );
                        }
                    }

                    $this->import_media( $post_id, $property['WebID'], 'epc', $media, true );

                    // Media - Virtual Tours
                    $virtual_tours = array();
                    for ( $i = 1; $i <= 4; ++$i )
                    {
                        if ( isset($property['Videos']['Video' . $i]) && $property['Videos']['Video' . $i] != '' )
                        {
                            $url = $property['Videos']['Video' . $i];

                            $virtual_tours[] = $url;
                        }
                    }

                    for ( $i = 1; $i <= 2; ++$i )
                    {
                        if ( isset($property['URLs']['URL' . $i]) && $property['URLs']['URL' . $i] != '' )
                        {
                            $url = $property['URLs']['URL' . $i];

                            if (
                                strpos($url, 'yout') !== FALSE ||
                                strpos($url, 'vimeo') !== FALSE ||
                                strpos($url, 'matterport') !== FALSE ||
                                strpos($url, 'tour') !== FALSE 
                            ) 
                            { 
                                $virtual_tours[] = trim($url);
                            }
                        }
                    }

                    update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                    foreach ( $virtual_tours as $i => $virtual_tour )
                    {
                        update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                    }

                    $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['WebID'] );
                }
                else
                {
                    $this->log( 'Skipping property as not been updated', $post_id, $property['WebID'] );
                }
                
                update_post_meta( $post_id, '_veco_json_update_date_' . $this->import_id, $property['Property']['UpdatedDate'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_veco_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['WebID'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;

        } // end foreach property

        do_action( "propertyhive_post_import_properties_veco_json" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $import_refs[] = $property['WebID'];
        }

        $this->do_remove_old_properties( $import_refs );

        unset($import_refs);
    }
    
    public function get_default_mapping_values()
    {
        return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'SSTC' => 'SSTC',
                'Under Offer' => 'Under Offer',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Let Agreed' => 'Let Agreed',
            ),
            'property_type' => array(
                'House' => 'House',
                'Flat' => 'Flat',
                'Apartment' => 'Apartment',
            ),
            'price_qualifier' => array(
                'Price on Application' => 'Price on Application',
                'Guide Price' => 'Guide Price',
                'Fixed Price' => 'Fixed Price',
                'Offers in Excess of' => 'Offers in Excess of',
                'Offers In Region Of' => 'Offers In Region Of',
                'Sale by Tender' => 'Sale by Tender',
                'From' => 'From',
                'Offers Over' => 'Offers Over',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
                'Feudal' => 'Feudal',
                'Commonhold' => 'Commonhold',
                'Share of Freehold' => 'Share of Freehold',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Part Furnished' => 'Part Furnished',
                'Unfurnished' => 'Unfurnished',
            )
        );
    }
}

}