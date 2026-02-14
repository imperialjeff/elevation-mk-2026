<?php
/**
 * Class for managing the import process of a WebEDGE XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_WebEDGE_XML_Import extends PH_Property_Import_Process {

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

    public function validate()
    {
        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $shared_secret = $import_settings['shared_secret'];

        if ( !isset($_POST['q']) )
        {
            $this->log_error( 'Missing \'q\' parameter in $_POST body' );
            
            // Missing body
            echo '<?xml version="1.0" encoding="UTF-8"?>
            <response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://feeds.propertynews.com/schemas/response.xsd" id="" action="" agent="">
              <result>110</result>
              <message>Missing \'q\' parameter in $_POST body</message>
              <secret></secret>
            </response>';

            return false;
        }
        // Expecting $_POST['q'] which will contain full XML
        $property = simplexml_load_string(stripslashes($_POST['q']));

        if ( $property === FALSE )
        {
            $this->log_error( 'Invalid XML provided' );

            // Invalid XML
            echo '<?xml version="1.0" encoding="UTF-8"?>
            <response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://feeds.propertynews.com/schemas/response.xsd" id="" action="" agent="">
              <result>110</result>
              <message>Invalid XML</message>
              <secret></secret>
            </response>';

            return false;
        }

        // Check secret
        $property_secret = ( isset($property->secret) ) ? (string)$property->secret : '';

        $property_attributes = $property->attributes();

        $property_id = ( isset($property_attributes['id']) ? (string)$property_attributes['id'] : '' );
        $agent = ( isset($property_attributes['agent']) ? (string)$property_attributes['agent'] : '' );

        $md5 = md5( md5( $property_id . $agent ) . $shared_secret );

        if ( $property_secret != $md5 )
        {
            $this->log_error( 'Invalid Secret. Expecting ' . $md5 . ', Got ' . $property_secret, 0, (string)$property_attributes['id'] );

            // Secret invalid
            echo '<?xml version="1.0" encoding="UTF-8"?>
            <response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://feeds.propertynews.com/schemas/response.xsd" id="' . (string)$property_attributes['id'] . '" action="' . (string)$property_attributes['action'] . '" agent="' . (string)$property_attributes['agent'] . '">
              <result>130</result>
              <message>Invalid Secret</message>
              <secret></secret>
            </response>';

            return false;
        }

        return $property;
    }

    public function import()
    {
        global $wpdb;

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $this->import_start();

        $upload_dir = wp_upload_dir();
        $upload_path = str_replace( '/', DIRECTORY_SEPARATOR, $upload_dir['path'] ) . DIRECTORY_SEPARATOR;

        do_action( "propertyhive_pre_import_property_webedge_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_webedge_xml_property_due_import", $this->properties, $this->import_id );

        $property_row = 1;
        foreach ( $this->properties as $property )
        {
            $property_attributes = $property->attributes();
            
            do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_webedge_xml", $property, $this->import_id, $this->instance_id );
            
            $this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property_attributes['id'], 0, (string)$property_attributes['id'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = array();
            if ( isset($property->address1) && trim((string)$property->address1) != '' )
            {
                $display_address[] = trim((string)$property->address1);
            }
            if ( isset($property->address2) && trim((string)$property->address2) != '' )
            {
                $display_address[] = trim((string)$property->address2);
            }
            elseif ( isset($property->address3) && trim((string)$property->address3) != '' )
            {
                $display_address[] = trim((string)$property->address3);
            }
            elseif ( isset($property->address4) && trim((string)$property->address4) != '' )
            {
                $display_address[] = trim((string)$property->address4);
            }
            $display_address = implode(", ", $display_address);

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property_attributes['id'], $property, $display_address, (string)$property->description );

            if ( $inserted_updated !== false )
            {
                // Need to check title and excerpt and see if they've gone in as blank but weren't blank in the feed
                // If they are, then do the encoding
                $inserted_post = get_post( $post_id );
                if ( 
                    $inserted_post && 
                    $inserted_post->post_title == '' && $inserted_post->post_excerpt == '' && 
                    ($display_address != '' || (string)$property->description != '')
                )
                {
                    $my_post = array(
                        'ID'             => $post_id,
                        'post_title'     => htmlentities(mb_convert_encoding(wp_strip_all_tags( $display_address ), 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
                        'post_excerpt'   => htmlentities(mb_convert_encoding((string)$property->description, 'UTF-8', 'ASCII'), ENT_SUBSTITUTE, "UTF-8"),
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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property_attributes['id'] );

                update_post_meta( $post_id, $imported_ref_key, (string)$property_attributes['id'] );

                update_post_meta( $post_id, '_property_import_data', $property->asXML() );

                // Address
                update_post_meta( $post_id, '_reference_number', (string)$property_attributes['id'] );
                update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property->name) ) ? (string)$property->name : '' ) . ' ' . ( ( isset($property->house_number) ) ? (string)$property->house_number : '' ) ) );
                update_post_meta( $post_id, '_address_street', ( ( isset($property->address1) ) ? (string)$property->address1 : '' ) );
                update_post_meta( $post_id, '_address_two', ( ( isset($property->address2) ) ? (string)$property->address2 : '' ) );
                update_post_meta( $post_id, '_address_three', ( ( isset($property->address3) ) ? (string)$property->address3 : '' ) );
                update_post_meta( $post_id, '_address_four', ( ( isset($property->address4) ) ? (string)$property->address4 : '' ) );
                update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

                $country = 'GB';
                update_post_meta( $post_id, '_address_country', $country );
                
                // We didn't find a location by doing mapping. Let's just look at address fields to see if we find a match
                $address_fields_to_check = apply_filters( 'propertyhive_webedge_xml_address_fields_to_check', array('area', 'address2', 'address3', 'address4', 'area') );
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
                if ( isset($property->map_coordinates->latitude) && isset($property->map_coordinates->longitude) && (string)$property->map_coordinates->latitude != '' && (string)$property->map_coordinates->longitude != '' && (string)$property->map_coordinates->latitude != '0' && (string)$property->map_coordinates->longitude != '0' )
                {
                    update_post_meta( $post_id, '_latitude', ( ( isset($property->map_coordinates->latitude) ) ? (string)$property->map_coordinates->latitude : '' ) );
                    update_post_meta( $post_id, '_longitude', ( ( isset($property->map_coordinates->longitude) ) ? (string)$property->map_coordinates->longitude : '' ) );
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
                        if ( trim($property->name) != '' ) { $address_to_geocode[] = (string)$property->name; }
                        if ( trim($property->house_number) != '' ) { $address_to_geocode[] = (string)$property->house_number; }
                        if ( trim($property->address1) != '' ) { $address_to_geocode[] = (string)$property->address1; }
                        if ( trim($property->address2) != '' ) { $address_to_geocode[] = (string)$property->address2; }
                        if ( trim($property->address3) != '' ) { $address_to_geocode[] = (string)$property->address3; }
                        if ( trim($property->address4) != '' ) { $address_to_geocode[] = (string)$property->address4; }
                        if ( trim($property->postcode) != '' ) { $address_to_geocode[] = (string)$property->postcode; $address_to_geocode_osm[] = (string)$property->postcode; }

                        $return = $this->do_geocoding_lookup( $post_id, (string)$property_attributes['id'], $address_to_geocode, $address_to_geocode_osm, $country );
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
                if ( strtolower((string)$property_attributes['property_type']) == 'rent' )
                {
                    $department = 'residential-lettings';
                }
                if ( substr(strtolower((string)$property->sector), 0, 10) == 'commercial' )
                {
                    $department = 'commercial';
                }

                update_post_meta( $post_id, '_department', $department );

                $bedrooms = $property->bedrooms;
                $bedrooms_attributes = $bedrooms->attributes();
                update_post_meta( $post_id, '_bedrooms', ( ( isset($bedrooms_attributes['value']) && (string)$bedrooms_attributes['value'] != '' ) ? (string)$bedrooms_attributes['value'] : (string)$property->propertyBedrooms ) );
                update_post_meta( $post_id, '_bathrooms', '' );
                $reception_rooms = $property->reception_rooms;
                $reception_rooms_attributes = $reception_rooms->attributes();
                update_post_meta( $post_id, '_reception_rooms', ( ( isset($reception_rooms_attributes['value']) && (string)$reception_rooms_attributes['value'] != '' ) ? (string)$reception_rooms_attributes['value'] : (string)$property->reception_rooms ) );

                $prefix = '';
                if ( $department == 'commercial' )
                {
                    $prefix = 'commercial_';
                }
                $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                if ( isset($property->house_type) )
                {
                    if ( !empty($mapping) && isset($mapping[(string)$property->house_type]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[(string)$property->house_type], $prefix . 'property_type' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                        $this->log( 'Property received with a type (' . (string)$property->house_type . ') that is not mapped', $post_id, (string)$property_attributes['id'] );

                        $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->house_type, $post_id );
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
                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->asking_price));
                    update_post_meta( $post_id, '_price', $price );
                    update_post_meta( $post_id, '_price_actual', $price );
                    update_post_meta( $post_id, '_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '') );
                    
                    $price_attributes = $property->asking_price->attributes();
                    update_post_meta( $post_id, '_currency', ( ( isset($price_attributes['currency']) ) ? (string)$price_attributes['currency'] : 'GBP' ) );

                    // Price Qualifier
                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                    if ( !empty($mapping) && isset($property->price_description) && isset($mapping[(string)$property->price_description]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[(string)$property->price_description], 'price_qualifier' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                    }
                }
                elseif ( $department == 'residential-lettings' )
                {
                    // Clean price
                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->asking_price));

                    update_post_meta( $post_id, '_rent', $price );

                    $rent_frequency = 'pcm';
                    $price_actual = $price;
                    switch ((string)$property->rent_frequency)
                    {
                        case "Monthly": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
                        case "Weekly": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
                        case "Quarterly": { $rent_frequency = 'pq'; $price_actual = $price / 4; break; }
                        case "Yearly": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
                    }
                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
                    update_post_meta( $post_id, '_price_actual', $price_actual );

                    $price_attributes = $property->asking_price->attributes();
                    update_post_meta( $post_id, '_currency', ( ( isset($price_attributes['currency']) ) ? (string)$price_attributes['currency'] : 'GBP' ) );
                    
                    update_post_meta( $post_id, '_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '') );

                    update_post_meta( $post_id, '_deposit', '' );
                    $available_date = ( isset($property->available_from) ? (string)$property->available_from : '' );
                    if ( $available_date != '' && strpos($available_date, ' ') !== FALSE && strpos($available_date, '/') !== FALSE )
                    {
                        $explode_available_date = explode(" ", $available_date);
                        $available_date = $explode_available_date[0];
                        $explode_available_date = explode("/", $available_date);
                        $available_date = $explode_available_date[2] . '-' . $explode_available_date[1] . '-' . $explode_available_date[0];
                    }
                    update_post_meta( $post_id, '_available_date', $available_date );

                    // Furnished
                    $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

                    if ( !empty($mapping) && isset($property->furnished) && isset($mapping[(string)$property->furnished]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[(string)$property->furnished], 'furnished' );
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

                    $price_attributes = $property->asking_price->attributes();

                    if ( strtolower((string)$property_attributes['property_type']) == 'sale' )
                    {
                        update_post_meta( $post_id, '_for_sale', 'yes' );

                        update_post_meta( $post_id, '_commercial_price_currency', ( ( isset($price_attributes['currency']) ) ? (string)$price_attributes['currency'] : 'GBP' ) );

                        $price = preg_replace("/[^0-9.]/", '', (string)$property->asking_price);
                        update_post_meta( $post_id, '_price_from', $price );
                        update_post_meta( $post_id, '_price_to', $price );

                        update_post_meta( $post_id, '_price_units', '' );

                        update_post_meta( $post_id, '_price_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '') );

                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                        if ( !empty($mapping) && isset($property->price_description) && isset($mapping[(string)$property->price_description]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->price_description], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                        }
                    }

                    if ( strtolower((string)$property_attributes['property_type']) == 'rent' )
                    {
                        update_post_meta( $post_id, '_to_rent', 'yes' );

                        update_post_meta( $post_id, '_commercial_rent_currency', ( ( isset($price_attributes['currency']) ) ? (string)$price_attributes['currency'] : 'GBP' ) );

                        $rent = preg_replace("/[^0-9.]/", '', (string)$property->asking_price);
                        update_post_meta( $post_id, '_rent_from', $rent );
                        update_post_meta( $post_id, '_rent_to', $rent );

                        $rent_frequency = 'pa';
                        switch ((string)$property->rent_frequency)
                        {
                            case "Monthly": { $rent_frequency = 'pcm'; break; }
                            case "Weekly": { $rent_frequency = 'pw'; break; }
                            case "Quarterly": { $rent_frequency = 'pq'; break; }
                            case "Yearly": { $rent_frequency = 'pa'; break; }
                        }
                        update_post_meta( $post_id, '_rent_units', $rent_frequency );

                        update_post_meta( $post_id, '_rent_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '') );
                    }

                    // Store price in common currency (GBP) used for ordering
                    $ph_countries = new PH_Countries();
                    $ph_countries->update_property_price_actual( $post_id );

                    $size = ( isset($property->floor_area) ? preg_replace("/[^0-9.]/", '', (string)$property->floor_area) : '' );
                    update_post_meta( $post_id, '_floor_area_from', $size );

                    update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

                    update_post_meta( $post_id, '_floor_area_to', '' );

                    update_post_meta( $post_id, '_floor_area_to_sqft', '' );

                    update_post_meta( $post_id, '_floor_area_units', 'sqft' );

                    update_post_meta( $post_id, '_site_area_from', '' );

                    update_post_meta( $post_id, '_site_area_from_sqft', '' );

                    update_post_meta( $post_id, '_site_area_to', '' );

                    update_post_meta( $post_id, '_site_area_to_sqft', '' );

                    update_post_meta( $post_id, '_site_area_units', 'sqft' );
                }

                // Marketing
                $on_market = 'yes';
                if ( in_array( (string)$property->status, apply_filters( 'propertyhive_webedge_off_market_statuses', array('On Hold', 'Draft', 'Let', 'Sold', 'Withdrawn') ) ) )
                {
                    $on_market = '';
                }
                $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', $on_market );
                }
                //update_post_meta( $post_id, '_featured', ( isset($property->featuredProperty) && (string)$property->featuredProperty == '1' ) ? 'yes' : '' );

                // Availability
                $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                    $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                    array();

                if ( isset($property->status) )
                {
                    if ( !empty($mapping) && isset($mapping[(string)$property->status]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[(string)$property->status], 'availability' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'availability' );
                    }
                }
                else
                {
                    wp_delete_object_term_relationships( $post_id, 'availability' );
                }

                // Features
                $features = array();
                if ( isset($property->features->feature) && !empty($property->features->feature) )
                {
                    foreach ( $property->features->feature as $feature )
                    {
                        if ( trim((string)$feature) != '' )
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

                // Rooms / Descriptions
                $rooms = 0;
                if ( isset($property->comprises->room) && !empty($property->comprises->room) )
                {
                    foreach ( $property->comprises->room as $room )
                    {
                        update_post_meta($post_id, '_room_name_' . $rooms, (string)$room->name);
                        $dimensions = '';
                        if ( (string)$room->width_imperial != '' && (string)$room->length_imperial != '' && (int)preg_replace("/[^0-9]/", "", (string)$room->width_imperial) != 0 && (int)preg_replace("/[^0-9]/", "", (string)$room->length_imperial) != 0 )
                        {
                            $dimensions = (string)$room->width_imperial . ' x ' . (string)$room->length_imperial;
                        }
                        update_post_meta($post_id, '_room_dimensions_' . $rooms, $dimensions);
                        update_post_meta($post_id, '_room_description_' . $rooms, (string)$room->description);

                        ++$rooms;
                    }
                }
                update_post_meta( $post_id, '_rooms', $rooms );

                // Media - Images
                if ( get_option('propertyhive_images_stored_as', '') == 'urls' )
                {
                    // Not going to be possible to import these as URLs
                }
                else
                {
                    $media_ids = array();
                    $new = 0;
                    $existing = 0;
                    $deleted = 0;
                    $previous_media_ids = get_post_meta( $post_id, '_photos', TRUE );
                    if (isset($property->media) && !empty($property->media))
                    {
                        foreach ($property->media->file_group as $file_group)
                        {
                            $file_group_attributes = $file_group->attributes();

                            if ( (string)$file_group_attributes['filetype'] == 'JPEG' && isset($file_group->file) && !empty($file_group->file) )
                            {
                                foreach ($file_group->file as $file)
                                {
                                    $file_attributes = $file->attributes();

                                    $contents = (string)$file;

                                    $description = ((string)$file_attributes['title']) ? (string)$file_attributes['title'] : '';

                                    // Check, based on the URL, whether we have previously imported this media
                                    $imported_previously = false;
                                    $imported_previously_id = '';
                                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                    {
                                        foreach ( $previous_media_ids as $previous_media_id )
                                        {
                                            if ( 
                                                get_post_meta( $previous_media_id, '_imported_length', TRUE ) == strlen($contents)
                                            )
                                            {
                                                $imported_previously = true;
                                                $imported_previously_id = $previous_media_id;
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if ($imported_previously)
                                    {
                                        $media_ids[] = $imported_previously_id;

                                        ++$existing;
                                    }
                                    else
                                    {
                                        $decoded = base64_decode($contents);

                                        $filename = $post_id . '-' . (string)$file_attributes['order'] . '.jpg';

                                        $hashed_filename = md5( $filename . microtime() ) . '_' . $filename;

                                        $image_upload = file_put_contents( $upload_path . $hashed_filename, $decoded );

                                        if ( $image_upload !== FALSE )
                                        {
                                            $file             = array();
                                            $file['error']    = '';
                                            $file['tmp_name'] = $upload_path . $hashed_filename;
                                            $file['name']     = $hashed_filename;
                                            $file['type']     = 'image/jpg';
                                            $file['size']     = filesize( $upload_path . $hashed_filename );

                                            $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

                                            $filename = $file_return['file'];
                                            $attachment = array(
                                                'post_mime_type' => $file_return['type'],
                                                'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                                                'post_content' => '',
                                                'post_status' => 'inherit',
                                                'guid' => $upload_dir['url'] . '/' . basename($filename)
                                            );
                                            $id = wp_insert_attachment( $attachment, $filename, $post_id );
                                            $attach_data = wp_generate_attachment_metadata( $id, $filename );
                                            wp_update_attachment_metadata( $id, $attach_data );

                                            // Check for handle sideload errors.
                                            if ( is_wp_error( $id ) ) 
                                            {
                                                //@unlink( $file_array['tmp_name'] );
                                                
                                                $this->log_error( 'ERROR: An error occurred whilst importing ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                            }
                                            else
                                            {
                                                $media_ids[] = $id;

                                                update_post_meta( $id, '_imported_length', strlen($contents));

                                                ++$new;
                                            }
                                        }
                                        else
                                        {
                                            $this->log_error( 'ERROR: An error occurred whilst putting ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                        }
                                    }
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_photos', $media_ids );

                    // Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                    {
                        foreach ( $previous_media_ids as $previous_media_id )
                        {
                            if ( !in_array($previous_media_id, $media_ids) )
                            {
                                if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
                                {
                                    ++$deleted;
                                }
                            }
                        }
                    }

                    $this->log( 'Imported ' . count($media_ids) . ' photos (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, (string)$property_attributes['id'] );
                }

                // Media - Floorplans
                if ( get_option('propertyhive_floorplans_stored_as', '') == 'urls' )
                {
                    // Not going to be possible to import these as URLs
                }
                else
                {
                    $media_ids = array();
                    $new = 0;
                    $existing = 0;
                    $deleted = 0;
                    $previous_media_ids = get_post_meta( $post_id, '_floorplans', TRUE );
                    if (isset($property->media) && !empty($property->media))
                    {
                        foreach ($property->media->file_group as $file_group)
                        {
                            $file_group_attributes = $file_group->attributes();

                            if ( (string)$file_group_attributes['filetype'] == 'floorplan' && isset($file_group->file) && !empty($file_group->file) )
                            {
                                foreach ($file_group->file as $file)
                                {
                                    $file_attributes = $file->attributes();

                                    $contents = (string)$file;

                                    $description = ((string)$file_attributes['title']) ? (string)$file_attributes['title'] : '';

                                    // Check, based on the URL, whether we have previously imported this media
                                    $imported_previously = false;
                                    $imported_previously_id = '';
                                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                    {
                                        foreach ( $previous_media_ids as $previous_media_id )
                                        {
                                            if ( 
                                                get_post_meta( $previous_media_id, '_imported_length', TRUE ) == strlen($contents)
                                            )
                                            {
                                                $imported_previously = true;
                                                $imported_previously_id = $previous_media_id;
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if ($imported_previously)
                                    {
                                        $media_ids[] = $imported_previously_id;

                                        ++$existing;
                                    }
                                    else
                                    {
                                        $decoded = base64_decode($contents);

                                        $filename = $post_id . '-' . (string)$file_attributes['order'] . '.pdf';

                                        $hashed_filename = md5( $filename . microtime() ) . '_' . $filename;

                                        $image_upload = file_put_contents( $upload_path . $hashed_filename, $decoded );

                                        if ( $image_upload !== FALSE )
                                        {
                                            $file             = array();
                                            $file['error']    = '';
                                            $file['tmp_name'] = $upload_path . $hashed_filename;
                                            $file['name']     = $hashed_filename;
                                            $file['type']     = 'application/pdf';
                                            $file['size']     = filesize( $upload_path . $hashed_filename );

                                            $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

                                            $filename = $file_return['file'];
                                            $attachment = array(
                                                'post_mime_type' => $file_return['type'],
                                                'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                                                'post_content' => '',
                                                'post_status' => 'inherit',
                                                'guid' => $upload_dir['url'] . '/' . basename($filename)
                                            );
                                            $id = wp_insert_attachment( $attachment, $filename, $post_id );
                                            $attach_data = wp_generate_attachment_metadata( $id, $filename );
                                            wp_update_attachment_metadata( $id, $attach_data );

                                            // Check for handle sideload errors.
                                            if ( is_wp_error( $id ) ) 
                                            {
                                                //@unlink( $file_array['tmp_name'] );
                                                
                                                $this->log_error( 'ERROR: An error occurred whilst importing ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                            }
                                            else
                                            {
                                                $media_ids[] = $id;

                                                update_post_meta( $id, '_imported_length', strlen($contents));

                                                ++$new;
                                            }
                                        }
                                        else
                                        {
                                            $this->log_error( 'ERROR: An error occurred whilst putting ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                        }
                                    }
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_floorplans', $media_ids );

                    // Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                    {
                        foreach ( $previous_media_ids as $previous_media_id )
                        {
                            if ( !in_array($previous_media_id, $media_ids) )
                            {
                                if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
                                {
                                    ++$deleted;
                                }
                            }
                        }
                    }

                    $this->log( 'Imported ' . count($media_ids) . ' floorplans (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, (string)$property_attributes['id'] );
                }

                // Media - Brochures
                if ( get_option('propertyhive_brochures_stored_as', '') == 'urls' )
                {
                    // Not going to be possible to import these as URLs
                }
                else
                {
                    $media_ids = array();
                    $new = 0;
                    $existing = 0;
                    $deleted = 0;
                    $previous_media_ids = get_post_meta( $post_id, '_brochures', TRUE );
                    if (isset($property->media) && !empty($property->media))
                    {
                        foreach ($property->media->file_group as $file_group)
                        {
                            $file_group_attributes = $file_group->attributes();

                            if ( (string)$file_group_attributes['filetype'] == 'PDF' && isset($file_group->file) && !empty($file_group->file) )
                            {
                                foreach ($file_group->file as $file)
                                {
                                    $file_attributes = $file->attributes();

                                    $contents = (string)$file;

                                    $description = ((string)$file_attributes['title']) ? (string)$file_attributes['title'] : '';

                                    // Check, based on the URL, whether we have previously imported this media
                                    $imported_previously = false;
                                    $imported_previously_id = '';
                                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                    {
                                        foreach ( $previous_media_ids as $previous_media_id )
                                        {
                                            if ( 
                                                get_post_meta( $previous_media_id, '_imported_length', TRUE ) == strlen($contents)
                                            )
                                            {
                                                $imported_previously = true;
                                                $imported_previously_id = $previous_media_id;
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if ($imported_previously)
                                    {
                                        $media_ids[] = $imported_previously_id;

                                        ++$existing;
                                    }
                                    else
                                    {
                                        $decoded = base64_decode($contents);

                                        $filename = $post_id . '-' . (string)$file_attributes['order'] . '.pdf';

                                        $hashed_filename = md5( $filename . microtime() ) . '_' . $filename;

                                        $image_upload = file_put_contents( $upload_path . $hashed_filename, $decoded );

                                        if ( $image_upload !== FALSE )
                                        {
                                            $file             = array();
                                            $file['error']    = '';
                                            $file['tmp_name'] = $upload_path . $hashed_filename;
                                            $file['name']     = $hashed_filename;
                                            $file['type']     = 'application/pdf';
                                            $file['size']     = filesize( $upload_path . $hashed_filename );

                                            $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

                                            $filename = $file_return['file'];
                                            $attachment = array(
                                                'post_mime_type' => $file_return['type'],
                                                'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                                                'post_content' => '',
                                                'post_status' => 'inherit',
                                                'guid' => $upload_dir['url'] . '/' . basename($filename)
                                            );
                                            $id = wp_insert_attachment( $attachment, $filename, $post_id );
                                            $attach_data = wp_generate_attachment_metadata( $id, $filename );
                                            wp_update_attachment_metadata( $id, $attach_data );

                                            // Check for handle sideload errors.
                                            if ( is_wp_error( $id ) ) 
                                            {
                                                //@unlink( $file_array['tmp_name'] );
                                                
                                                $this->log_error( 'ERROR: An error occurred whilst importing ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                            }
                                            else
                                            {
                                                $media_ids[] = $id;

                                                update_post_meta( $id, '_imported_length', strlen($contents));

                                                ++$new;
                                            }
                                        }
                                        else
                                        {
                                            $this->log_error( 'ERROR: An error occurred whilst putting ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                        }
                                    }
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_brochures', $media_ids );

                    // Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                    {
                        foreach ( $previous_media_ids as $previous_media_id )
                        {
                            if ( !in_array($previous_media_id, $media_ids) )
                            {
                                if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
                                {
                                    ++$deleted;
                                }
                            }
                        }
                    }

                    $this->log( 'Imported ' . count($media_ids) . ' brochures (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, (string)$property_attributes['id'] );
                }

                // Media - EPCs
                if ( get_option('propertyhive_epcs_stored_as', '') == 'urls' )
                {
                    // Not going to be possible to import these as URLs
                }
                else
                {
                    $media_ids = array();
                    $new = 0;
                    $existing = 0;
                    $deleted = 0;
                    $previous_media_ids = get_post_meta( $post_id, '_epcs', TRUE );
                    if (isset($property->media) && !empty($property->media))
                    {
                        foreach ($property->media->file_group as $file_group)
                        {
                            $file_group_attributes = $file_group->attributes();

                            if ( (string)$file_group_attributes['filetype'] == 'epccertificate' && isset($file_group->file) && !empty($file_group->file) )
                            {
                                foreach ($file_group->file as $file)
                                {
                                    $file_attributes = $file->attributes();

                                    $contents = (string)$file;

                                    $description = ((string)$file_attributes['title']) ? (string)$file_attributes['title'] : '';

                                    // Check, based on the URL, whether we have previously imported this media
                                    $imported_previously = false;
                                    $imported_previously_id = '';
                                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                                    {
                                        foreach ( $previous_media_ids as $previous_media_id )
                                        {
                                            if ( 
                                                get_post_meta( $previous_media_id, '_imported_length', TRUE ) == strlen($contents)
                                            )
                                            {
                                                $imported_previously = true;
                                                $imported_previously_id = $previous_media_id;
                                                break;
                                            }
                                        }
                                    }
                                    
                                    if ($imported_previously)
                                    {
                                        $media_ids[] = $imported_previously_id;

                                        ++$existing;
                                    }
                                    else
                                    {
                                        $decoded = base64_decode($contents);

                                        $filename = $post_id . '-' . (string)$file_attributes['order'] . '.pdf';

                                        $hashed_filename = md5( $filename . microtime() ) . '_' . $filename;

                                        $image_upload = file_put_contents( $upload_path . $hashed_filename, $decoded );

                                        if ( $image_upload !== FALSE )
                                        {
                                            $file             = array();
                                            $file['error']    = '';
                                            $file['tmp_name'] = $upload_path . $hashed_filename;
                                            $file['name']     = $hashed_filename;
                                            $file['type']     = 'application/pdf';
                                            $file['size']     = filesize( $upload_path . $hashed_filename );

                                            $file_return = wp_handle_sideload( $file, array( 'test_form' => false ) );

                                            $filename = $file_return['file'];
                                            $attachment = array(
                                                'post_mime_type' => $file_return['type'],
                                                'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
                                                'post_content' => '',
                                                'post_status' => 'inherit',
                                                'guid' => $upload_dir['url'] . '/' . basename($filename)
                                            );
                                            $id = wp_insert_attachment( $attachment, $filename, $post_id );
                                            $attach_data = wp_generate_attachment_metadata( $id, $filename );
                                            wp_update_attachment_metadata( $id, $attach_data );

                                            // Check for handle sideload errors.
                                            if ( is_wp_error( $id ) ) 
                                            {
                                                //@unlink( $file_array['tmp_name'] );
                                                
                                                $this->log_error( 'ERROR: An error occurred whilst importing ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                            }
                                            else
                                            {
                                                $media_ids[] = $id;

                                                update_post_meta( $id, '_imported_length', strlen($contents));

                                                ++$new;
                                            }
                                        }
                                        else
                                        {
                                            $this->log_error( 'ERROR: An error occurred whilst putting ' . (string)$file_attributes['order'] . '. The error was as follows: ' . $id->get_error_message(), $post_id, (string)$property_attributes['id'] );
                                        }
                                    }
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_epcs', $media_ids );

                    // Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                    {
                        foreach ( $previous_media_ids as $previous_media_id )
                        {
                            if ( !in_array($previous_media_id, $media_ids) )
                            {
                                if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
                                {
                                    ++$deleted;
                                }
                            }
                        }
                    }

                    $this->log( 'Imported ' . count($media_ids) . ' EPCs (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, (string)$property_attributes['id'] );
                }

                // Media - Virtual Tours
                $virtual_tours = array();
                if (isset($property->virtual_tour) && (string)$property->virtual_tour != '')
                {
                    $virtual_tours[] = (string)$property->virtual_tour;
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property_attributes['id'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_webedge_xml", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, (string)$property_attributes['id'], $metadata_before, $taxonomy_terms_before );
                }
            }
        }

        do_action( "propertyhive_post_import_properties_webedge_xml" );

        $this->import_end();
    }

    public function remove()
    {
        global $wpdb, $post;

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $property_attributes = $property->attributes();

            $import_refs[] = (string)$property_attributes['id'];
        }
        
        $args = array(
            'post_type' => 'property',
            'nopaging' => true,
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'     => $imported_ref_key,
                    'value'   => $import_refs,
                    'compare' => 'IN'
                ),
            ),
        );
        $property_query = new WP_Query( $args );
        if ( $property_query->have_posts() )
        {
            while ( $property_query->have_posts() )
            {
                $property_query->the_post();

                $this->remove_property( '', $post->ID );

                do_action( "propertyhive_property_removed_webedge_xml", $post->ID );
            }
        }
        wp_reset_postdata();
    }

    public function get_default_mapping_values()
    {
        return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'Agreed' => 'Agreed',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
                'Let' => 'Let',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Agreed' => 'Agreed',
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
                'Let' => 'Let',
                'Sold' => 'Sold',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Cottage' => 'Cottage',
                'Detached Bungalow' => 'Detached Bungalow',
                'Detached' => 'Detached',
                'Flat' => 'Flat',
                'Land' => 'Land',
                'Semi-Detached Bungalow' => 'Semi-Detached Bungalow',
                'Semi-Detached' => 'Semi-Detached',
                'Terrace' => 'Terrace',
                'Townhouse' => 'Townhouse',
                'End Terrace' => 'End Terrace',
            ),
            'commercial_property_type' => array(
                'Storage Unit' => 'Storage Unit'
            ),
            'price_qualifier' => array(
                'Offers Over' => 'Offers Over',
                'Offers Around' => 'Offers Around',
                'Offers in or Around' => 'Offers in or Around',
                'From' => 'From',
                'On Hold' => 'On Hold',
                'Price' => 'Price',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Unfurnished' => 'Unfurnished',
                'Part Furnished' => 'Part Furnished',
                'Furnished/Unfurnished' => 'Furnished/Unfurnished',
            ),
        );
    }
}

}