<?php
/**
 * Class for managing the import process of a Loop JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_RTDF_Import extends PH_Property_Import_Process {

    public function __construct( $instance_id = '', $import_id = '' )
    {
        parent::__construct();
        
        $this->instance_id = $instance_id;
        $this->import_id = $import_id;
    }

    private function validate_mandatory_fields( $mandatory_fields, $json )
    {
        foreach ( $mandatory_fields as $mandatory_field => $sub_mandatory_fields )
        {
            if ( is_array( $sub_mandatory_fields ) )
            {
                foreach ( $sub_mandatory_fields as $sub_mandatory_field => $sub_sub_mandatory_fields )
                {
                    if ( is_array( $sub_sub_mandatory_fields ) )
                    {
                        foreach ( $sub_sub_mandatory_fields as $sub_sub_mandatory_field => $sub_sub_sub_mandatory_fields )
                        {
                            if ( is_array( $sub_sub_sub_mandatory_fields ) )
                            {

                            }
                            else
                            {
                                if ( !isset($json[$mandatory_field][$sub_mandatory_field][$sub_sub_sub_mandatory_fields]) )
                                {
                                    return $mandatory_field . ':' . $sub_mandatory_field . ':' . $sub_sub_sub_mandatory_fields . ' element missing';
                                }
                                if ( trim($json[$mandatory_field][$sub_mandatory_field][$sub_sub_sub_mandatory_fields]) == '' )
                                {
                                    return $mandatory_field . ':' . $sub_mandatory_field . ':' . $sub_sub_sub_mandatory_fields . ' element empty';
                                }
                            }
                        }
                    }
                    else
                    {
                        if ( !isset($json[$mandatory_field][$sub_sub_mandatory_fields]) )
                        {
                            return $mandatory_field . ':' . $sub_sub_mandatory_fields . ' element missing';
                        }
                        if ( trim($json[$mandatory_field][$sub_sub_mandatory_fields]) == '' )
                        {
                            return $mandatory_field . ':' . $sub_sub_mandatory_fields . ' element empty';
                        }
                    }
                }
            }
            else
            {
                if ( !isset($json[$mandatory_field]) )
                {
                    return $mandatory_field . ' element missing';
                }
                if ( trim($json[$mandatory_field]) == '' )
                {
                    return $mandatory_field . ' element empty';
                }
            }
        }

        return true;
    }

    public function validate_send()
    {
        // Takes raw data from the request
        $original_body = file_get_contents('php://input');

        $this->log("Validating send property request");

        $body = $original_body;

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        if ( $json === NULL )
        {
            // Failed to parse JSON
            $this->log_error( 'Failed to parse JSON: ' . print_r($body, TRUE) );
            return 'Failed to parse JSON';
        }

        if ( $json !== FALSE )
        {
            // Valid JSON. Let's validate the mandatory fields
            $mandatory_fields = array(
                'network' => array(
                    'network_id' => 'network_id',
                ),
                'branch' => array(
                    'branch_id' => 'branch_id',
                    'channel' => 'channel',
                ),
                'property' => array(
                    'agent_ref' => 'agent_ref',
                    'published' => 'published',
                    'property_type' => 'property_type',
                    'status' => 'status',
                    'address' => array(
                        'house_name_number' => 'house_name_number',
                        'postcode_1' => 'postcode_1',
                        'postcode_2' => 'postcode_2',
                        'display_address' => 'display_address',
                    ),
                    'price_information' => array(
                        'price' => 'price',
                    ),
                    'details' => array(
                        'summary' => 'summary',
                        'description' => 'description',
                    ),
                ),
            );

            $mandatory_fields = apply_filters( 'propertyhive_property_import_rtdf_mandatory_fields_send', $mandatory_fields );
            
            $validate = $this->validate_mandatory_fields( $mandatory_fields, $json );

            if ( $validate !== TRUE )
            {
                $this->log_error( $validate );
            }

            return $validate;
        }
        else
        {       
            // Failed to parse request
            $this->log_error( 'Failed to parse request: ' . print_r($body, TRUE) );
            return 'Failed to parse request';
        }

        return true;
    }

    public function validate_remove()
    {
        // Takes raw data from the request
        $original_body = file_get_contents('php://input');

        $this->log("Validating remove property request");

        $body = $original_body;

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        if ( $json === NULL )
        {
            // Failed to parse JSON
            $this->log_error( 'Failed to parse JSON: ' . print_r($body, TRUE) );
            return 'Failed to parse JSON';
        }

        if ( $json !== FALSE )
        {
            // Valid JSON. Let's validate the mandatory fields
            $mandatory_fields = array(
                'network' => array(
                    'network_id' => 'network_id',
                ),
                'branch' => array(
                    'branch_id' => 'branch_id',
                    'channel' => 'channel',
                ),
                'property' => array(
                    'agent_ref' => 'agent_ref',
                ),
            );

            $mandatory_fields = apply_filters( 'propertyhive_property_import_rtdf_mandatory_fields_remove', $mandatory_fields );

            $validate = $this->validate_mandatory_fields( $mandatory_fields, $json );

            if ( $validate !== TRUE )
            {
                $this->log_error( $validate );
                return $validate;
            }

            // validate property sent exists
            $args = array(
                'post_type' => 'property',
                'posts_per_page' => 1,
                'post_status' => 'publish',
                'meta_query' => array(
                    array(
                        'key' => '_imported_ref_' . $this->import_id,
                        'value' => $json['property']['agent_ref']
                    )
                )
            );

            $property_query = new WP_Query($args);
            
            if ( !$property_query->have_posts() )
            {
                $this->log_error( 'Property with ID ' . $json['property']['agent_ref'] . ' not found' );
                return 'Property with ID ' . $json['property']['agent_ref'] . ' not found';
            }
            else
            {
                while ( $property_query->have_posts() )
                {
                    $property_query->the_post();

                    if ( get_post_meta( get_the_ID(), '_on_market', TRUE ) == '' )
                    {
                        $this->log_error( 'Property with ID ' . $json['property']['agent_ref'] . ' is already set to not on the market', get_the_ID(), $json['property']['agent_ref'] );
                        return 'Property with ID ' . $json['property']['agent_ref'] . ' is already set to not on the market';
                    }
                }
            }
        }
        else
        {       
            // Failed to parse request
            $this->log_error( 'Failed to parse request: ' . print_r($body, TRUE) );
            return 'Failed to parse request';
        }

        return true;
    }

    public function validate_get_properties()
    {
        // Takes raw data from the request
        $original_body = file_get_contents('php://input');

        $this->log("Validating get branch properties request");

        $body = $original_body;

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        if ( $json !== FALSE )
        {
            // Valid JSON. Let's validate the mandatory fields
            $mandatory_fields = array(
                'network' => array(
                    'network_id' => 'network_id',
                ),
                'branch' => array(
                    'branch_id' => 'branch_id',
                ),
            );

            $mandatory_fields = apply_filters( 'propertyhive_property_import_rtdf_mandatory_fields_get_properties', $mandatory_fields );
            
            $validate = $this->validate_mandatory_fields( $mandatory_fields, $json );

            if ( $validate !== TRUE )
            {
                $this->log_error( $validate );
                return $validate;
            }

            // validate branch ID is assigned to an office
            $office_id = false;

            if ( class_exists('PH_Property_Portal') )
            {
                $args = array(
                    'post_type' => 'agent',
                    'nopaging' => true
                );
                $agent_query = new WP_Query($args);
                
                if ($agent_query->have_posts())
                {
                    while ($agent_query->have_posts())
                    {
                        $agent_query->the_post();

                        $agent_id = get_the_ID();

                        $args = array(
                            'post_type' => 'branch',
                            'nopaging' => true,
                            'meta_query' => array(
                                array(
                                    'key' => '_agent_id',
                                    'value' => $agent_id
                                ),
                                array(
                                    'key' => '_import_id',
                                    'value' => $this->import_id
                                )
                            )
                        );
                        $branch_query = new WP_Query($args);
                        
                        if ($branch_query->have_posts())
                        {
                            while ($branch_query->have_posts())
                            {
                                $branch_query->the_post();

                                $agent_branch_id = get_the_ID();
    
                                if ( get_post_meta( $agent_branch_id, '_branch_code_sales', true ) == $json['branch']['branch_id'] )
                                {
                                    $office_id = $agent_branch_id;
                                    return true;
                                }
                                if ( get_post_meta( $agent_branch_id, '_branch_code_lettings', true ) == $json['branch']['branch_id'] )
                                {
                                    $office_id = $agent_branch_id;
                                    return true;
                                }
                                if ( get_post_meta( $agent_branch_id, '_branch_code_commercial', true ) == $json['branch']['branch_id'] )
                                {
                                    $office_id = $agent_branch_id;
                                    return true;
                                }
                            }
                        }
                        $branch_query->reset_postdata();
                    }
                }
                $agent_query->reset_postdata();
            }

            if ( $office_id === false )
            {
                $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
                
                if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
                {
                    foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                    {
                        if ( $branch_code == $json['branch']['branch_id'] )
                        {
                            $office_id = $ph_office_id;
                        }
                    }
                }
            }

            if ( $office_id === false )
            {
                $this->log_error( 'Branch ID provided (' . $json['branch']['branch_id'] . ') doesn\'t match any offices');
                return 'Branch ID provided (' . $json['branch']['branch_id'] . ') doesn\'t match any offices';
            }
        }
        else
        {       
            // Failed to parse request
            $this->log_error( 'Failed to parse request: ' . print_r($body, TRUE) );
            return 'Failed to parse request';
        }

        return true;
    }

    public function validate_get_emails()
    {
        // Takes raw data from the request
        $original_body = file_get_contents('php://input');

        $this->log("Validating get branch emails request");
        
        $body = $original_body;

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        if ( $json !== FALSE )
        {
            // Valid JSON. Let's validate the mandatory fields
            $mandatory_fields = array(
                'network' => array(
                    'network_id' => 'network_id',
                ),
                'branch' => array(
                    'branch_id' => 'branch_id',
                ),
                'export_period' => array(
                    'start_date_time' => 'start_date_time',
                    'end_date_time' => 'end_date_time',
                ),
            );
            
            $validate = $this->validate_mandatory_fields( $mandatory_fields, $json );

            if ( $validate !== TRUE )
            {
                $this->log_error( $validate );
                return $validate;
            }

            // validate branch ID is assigned to an office
            $office_id = false;

            if ( class_exists('PH_Property_Portal') )
            {
                $args = array(
                    'post_type' => 'agent',
                    'nopaging' => true
                );
                $agent_query = new WP_Query($args);
                
                if ($agent_query->have_posts())
                {
                    while ($agent_query->have_posts())
                    {
                        $agent_query->the_post();

                        $agent_id = get_the_ID();

                        $args = array(
                            'post_type' => 'branch',
                            'nopaging' => true,
                            'meta_query' => array(
                                array(
                                    'key' => '_agent_id',
                                    'value' => $agent_id
                                ),
                                array(
                                    'key' => '_import_id',
                                    'value' => $this->import_id
                                )
                            )
                        );
                        $branch_query = new WP_Query($args);
                        
                        if ($branch_query->have_posts())
                        {
                            while ($branch_query->have_posts())
                            {
                                $branch_query->the_post();

                                $agent_branch_id = get_the_ID();
    
                                if ( get_post_meta( $agent_branch_id, '_branch_code_sales', true ) == $json['branch']['branch_id'] )
                                {
                                    $office_id = $agent_branch_id;
                                    return true;
                                }
                                if ( get_post_meta( $agent_branch_id, '_branch_code_lettings', true ) == $json['branch']['branch_id'] )
                                {
                                    $office_id = $agent_branch_id;
                                    return true;
                                }
                                if ( get_post_meta( $agent_branch_id, '_branch_code_commercial', true ) == $json['branch']['branch_id'] )
                                {
                                    $office_id = $agent_branch_id;
                                    return true;
                                }
                            }
                        }
                        $branch_query->reset_postdata();
                    }
                }
                $agent_query->reset_postdata();
            }

            if ( $office_id === false )
            {
                $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

                if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
                {
                    foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                    {
                        if ( $branch_code == $json['branch']['branch_id'] )
                        {
                            $office_id = $ph_office_id;
                        }
                    }
                }
            }

            if ( $office_id === false )
            {
                $this->log_error( 'Branch ID provided (' . $json['branch']['branch_id'] . ') doesn\'t match any offices');
                return 'Branch ID provided (' . $json['branch']['branch_id'] . ') doesn\'t match any offices';
            }
        }
        else
        {       
            // Failed to parse request
            $this->log_error( 'Failed to parse request: ' . print_r($body, TRUE) );
            return 'Failed to parse request';
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

        // Takes raw data from the request
        $body = file_get_contents('php://input');

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        $property = $json['property'];
        $this->properties[] = $property;

        $this->import_start();

        do_action( "propertyhive_pre_import_property_rtdf", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_rtdf_property_due_import", $this->properties, $this->import_id );

        $property_row = 1;
        foreach ( $this->properties as $property )
        {
            do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_rtdf", $property, $this->import_id, $this->instance_id );

            $this->log( 'Importing property ' . $property_row .' with reference ' . $property['agent_ref'], 0, $property['agent_ref'], '', false );

            $display_address = trim($property['address']['display_address']);

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['agent_ref'], $property, $display_address, $property['details']['summary'], '', ( $property['create_date'] ) ? date( 'Y-m-d H:i:s', strtotime( $property['create_date'] )) : '' );

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

                update_post_meta( $post_id, $imported_ref_key, $property['agent_ref'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($json, JSON_PRETTY_PRINT) );

                // Address
                update_post_meta( $post_id, '_reference_number', $property['agent_ref'] );
                update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['address']['house_name_number']) ) ? $property['address']['house_name_number'] : '' ) . ' ' . ( ( isset($property['address_HouseSecondaryNameOrNumber']) ) ? $property['address_HouseSecondaryNameOrNumber'] : '' ) ) );
                update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['address_2']) ) ? $property['address']['address_2'] : '' ) );
                update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['address_3']) ) ? $property['address']['address_3'] : '' ) );
                update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['town']) ) ? $property['address']['town'] : '' ) );
                update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['address_4']) ) ? $property['address']['address_4'] : '' ) );
                update_post_meta( $post_id, '_address_postcode', trim( ( ( isset($property['address']['postcode_1']) ) ? $property['address']['postcode_1'] : '' ) . ' ' . ( ( isset($property['address']['postcode_2']) ) ? $property['address']['postcode_2'] : '' ) ) );

                $country = get_option( 'propertyhive_default_country', 'GB' );
                update_post_meta( $post_id, '_address_country', $country );

                // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                $address_fields_to_check = apply_filters( 'propertyhive_rtdf_address_fields_to_check', array('address_2', 'address_3', 'address_4', 'town') );
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

                    if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
                    {
                        // No lat lng. Let's get it
                        $address_to_geocode = array();
                        $address_to_geocode_osm = array();
                        if ( isset($property['address']['address_2']) && trim($property['address']['address_2']) != '' ) { $address_to_geocode[] = $property['address']['address_2']; }
                        if ( isset($property['address']['address_3']) && trim($property['address']['address_3']) != '' ) { $address_to_geocode[] = $property['address']['address_3']; }
                        if ( isset($property['address']['address_4']) && trim($property['address']['address_4']) != '' ) { $address_to_geocode[] = $property['address']['address_4']; }
                        if ( isset($property['address']['postcode_1']) && trim($property['address']['postcode_1']) != '' ) { $address_to_geocode[] = $property['address']['postcode_1']; $address_to_geocode_osm[] = $property['address']['postcode_1']; }

                        $return = $this->do_geocoding_lookup( $post_id, $property['agent_ref'], $address_to_geocode, $address_to_geocode_osm, $country );
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
                        if ( $branch_code == $json['branch']['branch_id'] )
                        {
                            $office_id = $ph_office_id;
                            break;
                        }
                    }
                }
                update_post_meta( $post_id, '_office_id', $office_id );

                // Residential Details
                $department = ( isset($json['branch']['channel']) && $json['branch']['channel'] == 2 ) ? 'residential-lettings' : 'residential-sales';
                if ( $commercial_active )
                {
                    // Check if the type is any of the commercial types
                    $mappings = $this->get_default_mapping_values();
                    $commercial_property_types = $mappings['commercial_property_type'];
                    if ( isset($commercial_property_types[$property['property_type']]) )
                    {
                        $department = 'commercial';
                    }
                }
                update_post_meta( $post_id, '_department', $department );
                
                update_post_meta( $post_id, '_bedrooms', ( ( isset($property['details']['bedrooms']) ) ? $property['details']['bedrooms'] : '' ) );
                update_post_meta( $post_id, '_bathrooms', ( ( isset($property['details']['bathrooms']) ) ? $property['details']['bathrooms'] : '' ) );
                update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['details']['reception_rooms']) ) ? $property['details']['reception_rooms'] : '' ) );

                update_post_meta( $post_id, '_council_tax_band', ( ( isset($property['details']['council_tax_band']) ) ? $property['details']['council_tax_band'] : '' ) );

                $prefix = '';
                if ( $department == 'commercial' )
                {
                    $prefix = 'commercial_';
                }
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
                    $price = preg_replace("/[^0-9.]/", '', (string)$property['price_information']['price']);
                    if ( !empty($price) )
                    {
                        $price = round((float)$price);
                    }

                    update_post_meta( $post_id, '_price', $price );
                    update_post_meta( $post_id, '_poa', ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == 1 ) ? 'yes' : '' );
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
                        update_post_meta( $post_id, '_leasehold_years_remaining', ( isset($property['price_information']['tenure_unexpired_years']) ? $property['price_information']['tenure_unexpired_years'] : '' ) );
                        update_post_meta( $post_id, '_shared_ownership', ( ( isset($property['price_information']['shared_ownership']) && ($property['price_information']['shared_ownership'] === true || $property['price_information']['shared_ownership'] === "true") ) ? 'yes' : '' ) );
                        update_post_meta( $post_id, '_shared_ownership_percentage', ( isset($property['price_information']['shared_ownership_percentage']) ? $property['price_information']['shared_ownership_percentage'] : '' ) );
                        update_post_meta( $post_id, '_ground_rent', ( isset($property['price_information']['annual_ground_rent']) ? $property['price_information']['annual_ground_rent'] : '' ) );
                        update_post_meta( $post_id, '_ground_rent_review_years', ( isset($property['price_information']['ground_rent_review_period_years']) ? $property['ground_rent_review_period_years']['tenure_unexpired_years'] : '' ) );
                        update_post_meta( $post_id, '_service_charge', ( isset($property['price_information']['annual_service_charge']) ? $property['price_information']['annual_service_charge'] : '' ) );
                    }
                }
                elseif ( $department == 'residential-lettings' )
                {
                    // Clean price
                    $price = preg_replace("/[^0-9.]/", '', (string)$property['price_information']['price']);
                    if ( !empty($price) )
                    {
                        $price = round((float)$price);
                    }

                    update_post_meta( $post_id, '_rent', $price );

                    $rent_frequency = 'pcm';
                    switch ($property['price_information']['rent_frequency'])
                    {
                        case 1:
                        {
                            $rent_frequency = 'pa'; break;
                        }
                        case 4:
                        {
                            $rent_frequency = 'pq'; break;
                        }
                        case 52:
                        {
                            $rent_frequency = 'pw'; break;
                        }
                    }
                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

                    update_post_meta( $post_id, '_poa', ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == 1 ) ? 'yes' : '' );
                    update_post_meta( $post_id, '_currency', 'GBP' );
                    
                    update_post_meta( $post_id, '_deposit', ( isset($property['price_information']['deposit']) ? $property['price_information']['deposit'] : '' ) );
                    update_post_meta( $post_id, '_available_date', ( isset($property['date_available']) && $property['date_available'] != '' ) ? date("Y-m-d", strtotime($property['date_available'])) : '' );

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
                elseif ( $department == 'commercial' )
                {
                    update_post_meta( $post_id, '_for_sale', '' );
                    update_post_meta( $post_id, '_to_rent', '' );

                    if ( isset($json['branch']['channel']) && $json['branch']['channel'] == 1 )
                    {
                        update_post_meta( $post_id, '_for_sale', 'yes' );

                        update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                        $price = preg_replace("/[^0-9.]/", '', (string)$property['price_information']['price']);
                        update_post_meta( $post_id, '_price_from', $price );
                        update_post_meta( $post_id, '_price_to', $price );

                        update_post_meta( $post_id, '_price_units', '' );

                        update_post_meta( $post_id, '_price_poa', ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == 1 ) ? 'yes' : '' );

                        // Tenure
                        $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

                        if ( !empty($mapping) && isset($property['price_information']['tenure_type']) && isset($mapping[$property['price_information']['tenure_type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['price_information']['tenure_type']], 'commercial_tenure' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
                        }
                    }

                    if ( isset($json['branch']['channel']) && $json['branch']['channel'] == 2 )
                    {
                        update_post_meta( $post_id, '_to_rent', 'yes' );

                        update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                        $rent = preg_replace("/[^0-9.]/", '', (string)$property['price_information']['price']);
                        update_post_meta( $post_id, '_rent_from', $rent );
                        update_post_meta( $post_id, '_rent_to', $rent );

                        $rent_frequency = 'pcm';
                        switch ($property['price_information']['rent_frequency'])
                        {
                            case 1:
                            {
                                $rent_frequency = 'pa'; $price_actual = $price / 12; break;
                            }
                            case 4:
                            {
                                $rent_frequency = 'pq'; $price_actual = $price / 4; break;
                            }
                            case 52:
                            {
                                $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break;
                            }
                        }
                        update_post_meta( $post_id, '_rent_units', $rent_frequency);

                        update_post_meta( $post_id, '_rent_poa', ( isset($property['price_information']['price_qualifier']) && $property['price_information']['price_qualifier'] == 1 ) ? 'yes' : '' );
                    }

                    $units = 'sqft';
                    if ( isset($property['details']['sizing']['area_unit']) )
                    {
                        switch ($property['details']['sizing']['area_unit'])
                        {
                            case 2: { $units = 'sqm'; break; }
                            case 3: { $units = 'acre'; break; }
                            case 4: { $units = 'hectare'; break; }
                        }
                    }

                    $size = isset($property['details']['sizing']['minimum']) ? preg_replace("/[^0-9.]/", '', $property['details']['sizing']['minimum']) : '';
                    update_post_meta( $post_id, '_floor_area_from', $size );
                    update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $units ) );

                    $size = isset($property['details']['sizing']['maximum']) ? preg_replace("/[^0-9.]/", '', $property['details']['sizing']['maximum']) : '';
                    update_post_meta( $post_id, '_floor_area_to', $size );
                    update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $units ) );

                    update_post_meta( $post_id, '_floor_area_units', $units );

                    update_post_meta( $post_id, '_site_area_from', '' );
                    update_post_meta( $post_id, '_site_area_from_sqft', '' );
                    update_post_meta( $post_id, '_site_area_to', '' );
                    update_post_meta( $post_id, '_site_area_to_sqft', '' );
                    update_post_meta( $post_id, '_site_area_units', '' );
                }

                // Store price in common currency (GBP) used for ordering
                $ph_countries = new PH_Countries();
                $ph_countries->update_property_price_actual( $post_id );

                // Marketing
                $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', ( ( isset($property['published']) && ($property['published'] === true || $property['published'] === "true") ) ? 'yes' : '' ) );
                }
                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
                if ( $featured_by_default === true )
                {
                    if ( get_post_meta( $post_id, '_featured', TRUE ) == '' ) { update_post_meta( $post_id, '_featured', '' ); }
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
                if ( isset($property['details']['features']) && is_array($property['details']['features']) && !empty($property['details']['features']) )
                {
                    foreach ( $property['details']['features'] as $feature )
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
                    $rooms = 0;

                    if ( isset($property['details']['description']) && !empty($property['details']['description']) )
                    {
                        update_post_meta( $post_id, '_description_name_' . $rooms, '' );
                        update_post_meta( $post_id, '_description_' . $rooms, $property['details']['description'] );

                        ++$rooms;
                    }

                    if ( isset($property['details']['rooms']) && is_array($property['details']['rooms']) && !empty($property['details']['rooms']) )
                    {
                        foreach ( $property['details']['rooms'] as $room )
                        {
                            $room_name = ( isset($room['room_name']) ? $room['room_name'] : '' );
                            $room_name .= ( isset($room['room_dimension_text']) ? ' (' . $room['room_dimension_text'] . ')' : '' );
                            update_post_meta( $post_id, '_description_name_' . $rooms, trim($room_name) );
                            update_post_meta( $post_id, '_description_' . $rooms, ( isset($room['room_description']) ? $room['room_description'] : '' ) );

                            ++$rooms;
                        }
                    }
                    
                    update_post_meta( $post_id, '_descriptions', $rooms );
                }
                else
                {
                    $rooms = 0;

                    if ( isset($property['details']['description']) && !empty($property['details']['description']) )
                    {
                        update_post_meta( $post_id, '_room_name_' . $rooms, '' );
                        update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
                        update_post_meta( $post_id, '_room_description_' . $rooms, $property['details']['description'] );

                        ++$rooms;
                    }

                    if ( isset($property['details']['rooms']) && is_array($property['details']['rooms']) && !empty($property['details']['rooms']) )
                    {
                        foreach ( $property['details']['rooms'] as $room )
                        {
                            update_post_meta( $post_id, '_room_name_' . $rooms, ( isset($room['room_name']) ? $room['room_name'] : '' ) );
                            update_post_meta( $post_id, '_room_dimensions_' . $rooms, ( isset($room['room_dimension_text']) ? $room['room_dimension_text'] : '' ) );
                            update_post_meta( $post_id, '_room_description_' . $rooms, ( isset($room['room_description']) ? $room['room_description'] : '' ) );

                            ++$rooms;
                        }
                    }
                    
                    update_post_meta( $post_id, '_rooms', $rooms );
                }

                // Media - Images
                $media = array();
                if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
                {
                    foreach ( $property['media'] as $image )
                    {
                        if ( isset($image['media_type']) && $image['media_type'] == 1 )
                        {
                            $media[] = array(
                                'url' => $image['media_url'],
                                'description' => ( isset($image['caption']) && !empty($image['caption']) ) ? $image['caption'] : '',
                                'modified' => ( isset($image['media_update_date']) && !empty($image['media_update_date']) ) ? $image['media_update_date'] : '',
                            );
                        }
                    }
                }

                $this->import_media( $post_id, $property['agent_ref'], 'photo', $media, true );

                // Media - Floorplans
                $media = array();
                if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
                {
                    foreach ( $property['media'] as $image )
                    {
                        if ( isset($image['media_type']) && $image['media_type'] == 2 )
                        {
                            $media[] = array(
                                'url' => $image['media_url'],
                                'description' => ( isset($image['caption']) && !empty($image['caption']) ) ? $image['caption'] : '',
                                'modified' => ( isset($image['media_update_date']) && !empty($image['media_update_date']) ) ? $image['media_update_date'] : '',
                            );
                        }
                    }
                }

                $this->import_media( $post_id, $property['agent_ref'], 'floorplan', $media, true );

                // Media - Brochures
                $media = array();
                if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
                {
                    foreach ( $property['media'] as $image )
                    {
                        if ( isset($image['media_type']) && $image['media_type'] == 3 )
                        {
                            $media[] = array(
                                'url' => $image['media_url'],
                                'description' => ( isset($image['caption']) && !empty($image['caption']) ) ? $image['caption'] : '',
                                'modified' => ( isset($image['media_update_date']) && !empty($image['media_update_date']) ) ? $image['media_update_date'] : '',
                            );
                        }
                    }
                }

                $this->import_media( $post_id, $property['agent_ref'], 'brochure', $media, true );

                // Media - EPCs
                $media = array();
                if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
                {
                    foreach ( $property['media'] as $image )
                    {
                        if ( isset($image['media_type']) && $image['media_type'] == ( $image['media_type'] == 6 || $image['media_type'] == 7 ) )
                        {
                            $media[] = array(
                                'url' => $image['media_url'],
                                'description' => ( isset($image['caption']) && !empty($image['caption']) ) ? $image['caption'] : '',
                                'modified' => ( isset($image['media_update_date']) && !empty($image['media_update_date']) ) ? $image['media_update_date'] : '',
                            );
                        }
                    }
                }

                $this->import_media( $post_id, $property['agent_ref'], 'epc', $media, true );

                // Media - Virtual Tours
                $virtual_tours = array();
                if ( isset($property['media']) && is_array($property['media']) && !empty($property['media']) )
                {
                    foreach ( $property['media'] as $image )
                    {
                        if ( 
                            isset($image['media_url']) && $image['media_url'] != ''
                            &&
                            (
                                substr( strtolower($image['media_url']), 0, 2 ) == '//' || 
                                substr( strtolower($image['media_url']), 0, 4 ) == 'http'
                            )
                            &&
                            isset($image['media_type']) && $image['media_type'] == 4
                        )
                        {
                            // This is a URL
                            $virtual_tours[] = array(
                                'url' => $image['media_url'],
                                'label' => isset($image['caption']) ? $image['caption'] : '',
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

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['agent_ref'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_rtdf", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['agent_ref'], $metadata_before, $taxonomy_terms_before );
                }
            }
        }
        
        do_action( "propertyhive_post_import_properties_rtdf" );

        $this->import_end();

        return array($post_id, $inserted_updated);
    }

    public function remove()
    {
        global $wpdb, $post;

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $remove_properties = true;

        if ( class_exists('PH_Property_Portal') && empty($this->branch_ids_processed) )
        {
            // If Property Portal addon is active, but no agents exist, it's not being used and we can proceed with removing properties
            $args = array(
                'post_type' => 'agent',
                'nopaging' => true,
                'fields' => 'ids',
            );
            $agent_query = new WP_Query( $args );
            if ( $agent_query->have_posts() )
            {
                $remove_properties = false;
            }
        }

        if ( $remove_properties )
        {
            // Takes raw data from the request
            $body = file_get_contents('php://input');

            $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
            
            if ( $xml !== FALSE )
            {
                $xpath = '//*[not(normalize-space())]';
                foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                    unset($remove[0]);
                }

                // we've been sent XML. Convert it to JSON
                $body = json_encode($xml);
            }

            // Converts it into a PHP array
            $json = json_decode($body, TRUE);

            $property = $json['property'];

            $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

            $args = array(
                'post_type' => 'property',
                'nopaging' => true,
            );

            $meta_query = array(
                'relation' => 'AND',
                array(
                    'key'     => $imported_ref_key,
                    'value'   => $property['agent_ref'],
                ),
                array(
                    'key'     => '_on_market',
                    'value'   => 'yes',
                ),
            );

            $args['meta_query'] = $meta_query;

            $return = true;

            $property_query = new WP_Query( $args );
            if ( $property_query->have_posts() )
            {
                while ( $property_query->have_posts() )
                {
                    $property_query->the_post();

                    $return = $post->ID;

                    $this->remove_property( '', $post->ID );

                    do_action( "propertyhive_property_removed_rtdf", $post->ID );
                }
            }
            wp_reset_postdata();

            return $return;
        }
    }

    public function get_properties()
    {
        global $post;

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        // Takes raw data from the request
        $body = file_get_contents('php://input');

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        $return = array();

        $did_agent_lookup = false;
        $agent_branch_ids = array();
        if ( class_exists('PH_Property_Portal') )
        {
            $args = array(
                'post_type' => 'agent',
                'nopaging' => true
            );
            $agent_query = new WP_Query($args);
            
            if ($agent_query->have_posts())
            {
                while ($agent_query->have_posts())
                {
                    $agent_query->the_post();

                    $agent_id = get_the_ID();

                    $args = array(
                        'post_type' => 'branch',
                        'nopaging' => true,
                        'meta_query' => array(
                            array(
                                'key' => '_agent_id',
                                'value' => $agent_id
                            ),
                            array(
                                'key' => '_import_id',
                                'value' => $this->import_id
                            )
                        )
                    );
                    $branch_query = new WP_Query($args);
                    
                    if ($branch_query->have_posts())
                    {
                        while ($branch_query->have_posts())
                        {
                            $branch_query->the_post();

                            $agent_branch_id = get_the_ID();

                            if ( get_post_meta( $agent_branch_id, '_branch_code_sales', true ) == $json['branch']['branch_id'] )
                            {
                                $agent_branch_ids[] = $agent_branch_id;
                                $did_agent_lookup = true;
                            }
                            if ( get_post_meta( $agent_branch_id, '_branch_code_lettings', true ) == $json['branch']['branch_id'] )
                            {
                                $agent_branch_ids[] = $agent_branch_id;
                                $did_agent_lookup = true;
                            }
                            if ( get_post_meta( $agent_branch_id, '_branch_code_commercial', true ) == $json['branch']['branch_id'] )
                            {
                                $agent_branch_ids[] = $agent_branch_id;
                                $did_agent_lookup = true;
                            }
                        }
                    }
                    $branch_query->reset_postdata();
                }
            }
            $agent_query->reset_postdata();

            $agent_branch_ids = array_unique($agent_branch_ids);
        }

        $args = array(
            'post_type' => 'property',
            'nopaging' => true,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => $imported_ref_key,
                    'compare' => 'EXISTS',
                ),
                array(
                    'key' => '_on_market',
                    'value' => 'yes',
                ),
            )
        );

        if ( $did_agent_lookup && !empty($agent_branch_ids) )
        {
            $args['meta_query'][] = array(
                'key' => '_branch_id',
                'value' => $agent_branch_ids,
                'compare' => 'IN'
            );
        }
        else
        {
            $office_ids = array();
            if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
            {
                foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                {
                    if ( $branch_code == $json['branch']['branch_id'] )
                    {
                        $office_ids[] = $ph_office_id;
                    }
                }
            }

            $args['meta_query'][] = array(
                'key' => '_office_id',
                'value' => $office_ids,
                'compare' => 'IN'
            );
        }

        if ( isset($json['branch']['channel']) && $json['branch']['channel'] == 1 )
        {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_department',
                    'value' => 'residential-sales',
                ),
                array(
                    'relation' => 'AND',
                    array(
                        'key' => '_department',
                        'value' => 'commercial',
                    ),
                    array(
                        'key' => '_for_sale',
                        'value' => 'yes',
                    )
                ),
            );
        }
        if ( isset($json['branch']['channel']) && $json['branch']['channel'] == 2 )
        {
            $args['meta_query'][] = array(
                'relation' => 'OR',
                array(
                    'key' => '_department',
                    'value' => 'residential-lettings',
                ),
                array(
                    'relation' => 'AND',
                    array(
                        'key' => '_department',
                        'value' => 'commercial',
                    ),
                    array(
                        'key' => '_to_rent',
                        'value' => 'yes',
                    )
                ),
            );
        }

        $property_query = new WP_Query($args);

        if ($property_query->have_posts())
        {
            while ($property_query->have_posts())
            {
                $property_query->the_post();

                $return[] = get_the_ID();
            }
        }
        wp_reset_postdata();

        return $return;
    }

    public function get_emails()
    {
        global $post;

        $return = array();

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        // Takes raw data from the request
        $body = file_get_contents('php://input');

        $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);
        
        if ( $xml !== FALSE )
        {
            $xpath = '//*[not(normalize-space())]';
            foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                unset($remove[0]);
            }

            // we've been sent XML. Convert it to JSON
            $body = json_encode($xml);
        }

        // Converts it into a PHP array
        $json = json_decode($body, TRUE);

        $do_office_check = true;
        
        if ( class_exists('PH_Property_Portal') )
        {
            $branch_ids = array();

            $args = array(
                'post_type' => 'agent',
                'nopaging' => true
            );
            $agent_query = new WP_Query($args);
            
            if ($agent_query->have_posts())
            {
                while ($agent_query->have_posts())
                {
                    $agent_query->the_post();

                    $agent_id = get_the_ID();

                    $args = array(
                        'post_type' => 'branch',
                        'nopaging' => true,
                        'meta_query' => array(
                            array(
                                'key' => '_agent_id',
                                'value' => $agent_id
                            ),
                            array(
                                'key' => '_import_id',
                                'value' => $this->import_id
                            )
                        )
                    );
                    $branch_query = new WP_Query($args);
                    
                    if ($branch_query->have_posts())
                    {
                        while ($branch_query->have_posts())
                        {
                            $branch_query->the_post();

                            $agent_branch_id = get_the_ID();

                            if ( get_post_meta( $agent_branch_id, '_branch_code_sales', true ) == $json['branch']['branch_id'] )
                            {
                                $branch_ids[] = $agent_branch_id;
                            }
                            if ( get_post_meta( $agent_branch_id, '_branch_code_lettings', true ) == $json['branch']['branch_id'] )
                            {
                                $branch_ids[] = $agent_branch_id;
                            }
                            if ( get_post_meta( $agent_branch_id, '_branch_code_commercial', true ) == $json['branch']['branch_id'] )
                            {
                                $branch_ids[] = $agent_branch_id;
                            }
                        }
                    }
                    $branch_query->reset_postdata();
                }
            }
            $agent_query->reset_postdata();

            $branch_ids = array_unique($branch_ids);
            $branch_ids = array_filter($branch_ids);

            if ( !empty($branch_ids) )
            {
                $do_office_check = false;

                foreach ( $branch_ids as $branch_id )
                {
                    $branch_properties = $this->get_properties_by_branch((int)$branch_id);

                    if ( !empty($branch_properties) )
                    {
                        // get all properties belonging to this branch
                        $args = array(
                            'post_type' => 'enquiry',
                            'nopaging' => true,
                            'post_status' => 'publish',
                            'date_query' => array(),
                            'meta_query' => array(
                                array(
                                    'compare_key' => 'LIKE',
                                    'key' => 'property_id',
                                    'value' => $branch_properties,
                                    'compare' => 'IN',
                                )
                            )
                        );

                        $start_date = date("Y-m-d", strtotime('28 days ago'));
                        if ( isset($json['export_period']['start_date_time']) && !empty($json['export_period']['start_date_time']) )
                        {
                            $start_date = date("Y-m-d H:i:s", strtotime($json['export_period']['start_date_time']));
                        }

                        $end_date = date("Y-m-d H:i:s");
                        if ( isset($json['export_period']['end_date_time']) && !empty($json['export_period']['end_date_time']) )
                        {
                            $end_date = date("Y-m-d H:i:s", strtotime($json['export_period']['end_date_time']));
                        }

                        $args['date_query'][] = array(
                            'after'     => $start_date,
                            'before'    => $end_date,
                            'inclusive' => true,
                        );

                        $enquiries_query = new WP_Query($args);

                        if ($enquiries_query->have_posts())
                        {
                            while ($enquiries_query->have_posts())
                            {
                                $enquiries_query->the_post();

                                // ensure property came from this import
                                $enquiry = new PH_Enquiry( get_the_ID() );

                                $property_id = $enquiry->property_id;

                                if ( !empty($property_id) )
                                {
                                    if ( get_post_meta( $property_id, $imported_ref_key, TRUE ) != '' )
                                    {
                                        $return[] = get_the_ID();
                                    }
                                }
                            }
                        }
                        wp_reset_postdata();
                    }
                }

                return $return;
            }
        }

        if ( $do_office_check )
        {
            $office_ids = array();
            if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
            {
                foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                {
                    if ( $branch_code == $json['branch']['branch_id'] )
                    {
                        $office_ids[] = $ph_office_id;
                    }
                }
            }

            if ( !empty($office_ids) )
            {       
                $args = array(
                    'post_type' => 'enquiry',
                    'nopaging' => true,
                    'post_status' => 'publish',
                    'date_query' => array(),
                    'meta_query' => array(
                        array(
                            'key' => '_office_id',
                            'value' => $office_ids,
                            'compare' => 'IN'
                        ),
                    )
                );

                $start_date = date("Y-m-d", strtotime('28 days ago'));
                if ( isset($json['export_period']['start_date_time']) && !empty($json['export_period']['start_date_time']) )
                {
                    $start_date = date("Y-m-d H:i:s", strtotime($json['export_period']['start_date_time']));
                }

                $end_date = date("Y-m-d H:i:s");
                if ( isset($json['export_period']['end_date_time']) && !empty($json['export_period']['end_date_time']) )
                {
                    $end_date = date("Y-m-d H:i:s", strtotime($json['export_period']['end_date_time']));
                }

                $args['date_query'][] = array(
                    'after'     => $start_date,
                    'before'    => $end_date,
                    'inclusive' => true,
                );

                $enquiries_query = new WP_Query($args);

                if ($enquiries_query->have_posts())
                {
                    while ($enquiries_query->have_posts())
                    {
                        $enquiries_query->the_post();

                        // ensure property came from this import
                        $enquiry = new PH_Enquiry( get_the_ID() );

                        $property_id = $enquiry->property_id;

                        if ( !empty($property_id) )
                        {
                            if ( get_post_meta( $property_id, $imported_ref_key, TRUE ) != '' )
                            {
                                $return[] = get_the_ID();
                            }
                        }
                    }
                }
                wp_reset_postdata();
            }
        }

        return $return;
    }

    private function get_properties_by_branch($branch_id) {
        $properties = get_posts(array(
            'post_type' => 'property',
            'meta_key' => '_branch_id',
            'meta_value' => $branch_id,
            'fields' => 'ids',
            'posts_per_page' => -1,
        ));
        return $properties;
    }
    
    public function get_default_mapping_values()
    {
        $commercial_property_types = array(
            '19' => 'Commercial Property',
            '80' => 'Restaurant',
            '83' => 'Cafe',
            '86' => 'Mill',
            '134' => 'Bar / Nightclub',
            '137' => 'Shop',
            '178' => 'Office',
            '181' => 'Business Park',
            '184' => 'Serviced Office',
            '187' => 'Retail Property (High Street)',
            '190' => 'Retail Property (Out of Town)',
            '193' => 'Convenience Store',
            '196' => 'Garages',
            '199' => 'Hairdresser/Barber Shop',
            '202' => 'Hotel',
            '205' => 'Petrol Station',
            '208' => 'Post Office',
            '211' => 'Pub',
            '214' => 'Workshop & Retail Space',
            '217' => 'Distribution Warehouse',
            '220' => 'Factory',
            '223' => 'Heavy Industrial',
            '226' => 'Industrial Park',
            '229' => 'Light Industrial',
            '232' => 'Storage',
            '235' => 'Showroom',
            '238' => 'Warehouse',
            '241' => 'Land (Commercial)',
            '244' => 'Commercial Development',
            '247' => 'Industrial Development',
            '250' => 'Residential Development',
            '253' => 'Commercial Property',
            '256' => 'Data Centre',
            '259' => 'Farm',
            '262' => 'Healthcare Facility',
            '265' => 'Marine Property',
            '268' => 'Mixed Use',
            '271' => 'Research & Development Facility',
            '274' => 'Science Park',
            '277' => 'Guest House',
            '280' => 'Hospitality',
            '283' => 'Leisure Facility',
        );

        $property_types = array(
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
            '45' => 'Parking',
            '46' => 'Sheltered Housing',
            '47' => 'Retirement property',
            '48' => 'House share',
            '49' => 'Flat share',
            '50' => 'Park home',
            '51' => 'Garages',
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
            '80' => 'Restaurant',
            '83' => 'Cafe',
            '86' => 'Mill',
            '92' => 'Castle',
            '95' => 'Village House',
            '101' => 'Cave House',
            '104' => 'Cortijo',
            '107' => 'Farm Land',
            '113' => 'Country House',
            '117' => 'Caravan',
            '118' => 'Lodge',
            '119' => 'Log Cabin',
            '120' => 'Manor House',
            '121' => 'Stately Home',
            '125' => 'Off-Plan',
            '128' => 'Semi-detached Villa',
            '131' => 'Detached Villa',
            '134' => 'Bar/Nightclub',
            '137' => 'Shop',
            '140' => 'Riad',
            '141' => 'House Boat',
            '142' => 'Hotel Room',
            '143' => 'Block of Apartments',
            '144' => 'Private Halls',
            '259' => 'Farm',
            '298' => 'Takeaway',
            '301' => 'Childcare Facility',
            '304' => 'Smallholding',
            '307' => 'Place of Worship',
            '310' => 'Trade Counter',
            '511' => 'Coach House',
            '512' => 'House of Multiple Occupation',
            '535' => 'Sports facilities',
            '538' => 'Spa',
            '541' => 'Campsite & Holiday Village',
        );

        // If commercial department not active then add commercial types to normal list of types
        if ( get_option( 'propertyhive_active_departments_commercial', '' ) == '' )
        {
            $property_types = array_merge( $property_types, $commercial_property_types );
        }

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
            'commercial_availability' => array(
                '1' => 'Available',
                '2' => 'SSTC',
                '3' => 'SSTCM',
                '4' => 'Under Offer',
                '5' => 'Reserved',
                '6' => 'Let Agreed',
            ),
            'property_type' => $property_types,
            'commercial_property_type' => $commercial_property_types,
            'price_qualifier' => array(
                '0' => 'Default',
                '1' => 'POA',
                '2' => 'Guide Price',
                '3' => 'Fixed Price',
                '4' => 'Offers in Excess of',
                '5' => 'OIRO',
                '6' => 'Sale by Tender',
                '7' => 'From',
                '9' => 'Shared Ownership',
                '10' => 'Offers Over',
                '11' => 'Part Buy, Part Rent',
                '12' => 'Shared Equity',
                '16' => 'Coming Soon',
            ),
            'tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Feudal',
                '5' => 'Share of Freehold',
                '4' => 'Commonhold',
            ),
            'commercial_tenure' => array(
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Feudal',
                '5' => 'Share of Freehold',
                '4' => 'Commonhold',
            ),
            'furnished' => array(
                '0' => 'Furnished',
                '1' => 'Part-furnished',
                '2' => 'Unfurnished',
                '4' => 'Furnished/Un Furnished',
            ),
        );
    }
}

}