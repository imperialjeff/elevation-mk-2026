<?php
/**
 * Class for managing the import process of a Reapit Foundations JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Reapit_Foundations_JSON_Import extends PH_Property_Import_Process {

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

    private function log_api_request( $endpoint )
    {
        $option_name = 'propertyhive_property_import_reapit_foundations_api_request_' . date("Ym");

        $existing_option = get_option( $option_name, array() );

        if ( !isset($existing_option[date("j")]) )
        {
            $existing_option[date("j")] = array();
        }

        if ( !isset($existing_option[date("j")][$endpoint]) )
        {
            $existing_option[date("j")][$endpoint] = 0;
        }

        $existing_option[date("j")][$endpoint] = $existing_option[date("j")][$endpoint] + 1;

        update_option( $option_name, $existing_option, false );
    }

    private function is_commercial( $property )
    {
        if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
        {
            // if only active department is commercial default to this
            $departments = ph_get_departments();
            $custom_departments = ph_get_custom_departments(false);

            $commercial_only_department = true;
            foreach ( $departments as $key => $value )
            {
                if ( $key != 'commercial' && get_option( 'propertyhive_active_departments_' . str_replace("residential-", "", $key) ) == 'yes' )
                {
                    $commercial_only_department = false;
                }
            }

            if ( !empty($custom_departments) )
            {
                foreach ( $custom_departments as $key => $custom_department )
                {
                    if ( $key != 'commercial' && get_option( 'propertyhive_active_departments_' . $key ) == 'yes' )
                    {
                        $commercial_only_department = false;
                    }
                }
            }

            if ( $commercial_only_department )
            {
                return true;
            }

            $commercial_types = array( 'commercial', 'development land', 'hotel', 'financial', 'industrial', 'leisure', 'office', 'publicHouse', 'restaurant', 'retail', 'shop', 'storage', 'warehouse', 'mixed use' );
            $commercial_types = apply_filters( 'propertyhive_reapit_foundations_json_commercial_property_types', $commercial_types );

            if ( isset($property['type']) && is_array($property['type']) && !empty($property['type']) )
            {
                $propertyTypeStyle = $property['type'][0];
            
                foreach ( $commercial_types as $commercial_type )
                {
                    if ( strpos( strtolower($propertyTypeStyle), $commercial_type) !== FALSE )
                    {
                        return true;
                    }
                }
            }
            if ( isset($property['unmappedAttributes']) && is_array($property['unmappedAttributes']) && !empty($property['unmappedAttributes']) )
            {
                foreach ( $property['unmappedAttributes'] as $unmapped_attribute )
                {
                    if ( isset($unmapped_attribute['type']) && strpos(strtolower($unmapped_attribute['type']), 'type') !== FALSE )
                    {
                        foreach ( $commercial_types as $commercial_type )
                        {
                            if ( strpos( strtolower($unmapped_attribute['value']), $commercial_type) !== FALSE )
                            {
                                return true;
                            }
                        }
                    }
                }
            }
        }

        return false;
    }

    private function get_token()
    {
        $this->log("Obtaining token");
        
        $client_id = apply_filters( 'propertyhive_reapit_foundations_json_client_id', '45m5oderlfmrbum378s85gbql7' );
        $client_secret = apply_filters( 'propertyhive_reapit_foundations_json_client_secret', '11t5p8oein6hath9at2lf0dqdurqpd7u0sb0u9rrlae70o9t9jqq' );

        $base64_secret = base64_encode( $client_id . ':' . $client_secret );

        $response = wp_remote_post(
            'https://connect.reapit.cloud/token',
            array(
                'method' => 'POST',
                'timeout' => 45,
                'headers' => array(
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Authorization' => 'Basic ' . $base64_secret,
                ),
                'body' => array(
                    'client_id' => $client_id,
                    'grant_type' => 'client_credentials',
                ),
            )
        );

        if ( is_wp_error( $response ) )
        {
            $this->log_error( 'Failed to request token: ' . $response->get_error_message() );
            return false;
        }
        else
        {
            if ( wp_remote_retrieve_response_code($response) !== 200 )
            {
                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting token. Error message: ' . wp_remote_retrieve_response_message($response) );
                return false;
            }
            else
            {
                $body = json_decode($response['body'], TRUE);

                if ( $body === null )
                {
                    $this->log_error( 'Failed to decode token request body: ' . $response['body'] );
                    return false;
                }
                else
                {
                    if ( isset($body['access_token']) )
                    {
                        $this->log("Got token");
                        return $body;
                    }
                    else
                    {
                        $this->log_error( 'Failed to get access_token part of response body: ' . $response['body'] );
                        return false;
                    }
                }
            }
        }
    }

    private function build_reapit_query_string( $url_parameters )
    {
        $url_string = http_build_query( $url_parameters );

        // Reapit can't handle the embed array as http_build_query builds it (&embed[0]=images&embed[1]=area etc)
        // So run a preg_replace to convert it to &embed=images&embed=area etc
        $url_string = preg_replace('/%5B(?:[0-9]|[1-9][0-9]+)%5D=/', '=', $url_string);

        return $url_string;
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

        if ( $this->import_id > PH_PROPERTYIMPORT_FOUNDATIONS_AGREEMENT_UPDATE )
        {
            if ( isset($import_settings['agree']) && $import_settings['agree'] == 'yes' )
            {

            }
            else
            {
                $this->log_error( 'You need to agree to the terms in the import settings about getting charged for the API usage before an import will run.' );
                return false;
            }
        }

        $token_response = $this->get_token();

        if ( $token_response === false )
        {
            return false;
        }

        $token = $token_response['access_token'];
        $token_expires = time() + $token_response['expires_in'];

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

        $property_ids = array();

        $embed = array();

        // only get negotiator embed once a day as pointless doing every time an import runs
        $last_negotiator_embed = get_option( 'propertyhive_reapit_foundations_negotiator_get_' . $this->import_id, '' );
        if ( apply_filters( 'propertyhive_reapit_foundations_json_negotiators_on_every_request', false ) === true || $last_negotiator_embed == '' || $last_negotiator_embed < strtotime('-24 hours') )
        {
            $embed[] = 'negotiator';
        }

        // only call utiliies endpoint once a day to reduce number of API calls
        $last_utilities_endpoint = get_option( 'propertyhive_reapit_foundations_utilities_get_' . $this->import_id, '' );
        $get_utilities = false;
        if ( apply_filters( 'propertyhive_reapit_foundations_json_utilities_on_every_request', false ) === true )
        {
            // overridden to always get
            $get_utilities = true;
        }
        if ( $last_utilities_endpoint == '' || $last_utilities_endpoint < strtotime('-24 hours') )
        {
            // Not got before
            $get_utilities = true;
        }
        if ( ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' ) || !isset($import_settings['only_updated']) )
        {
            // if only updating updated properties, need to get every time
            $get_utilities = true;
        }
        if ( apply_filters( 'propertyhive_reapit_foundations_json_get_utilities', true ) === false )
        {
            // overridden to never get
            $get_utilities = false;
        }

        // only embed area if location taxonomy being used
        if ( taxonomy_exists('location') )
        {
            $args = array(
                'hide_empty' => false,
                'parent' => 0
            );
            $terms = get_terms( 'location', $args );

            if ( !empty( $terms ) && !is_wp_error( $terms ) )
            {
                $embed[] = 'area';
            }
        }

        $total_pages = 999;
        $page_size = 100;
        $page = 1;

        if ( !isset($import_settings['sales_status']) )
        {
            $importSalesStatuses = apply_filters( "propertyhive_reapit_foundations_json_import_sales_statuses", array( 'forSale', 'underOffer', 'exchanged' ) );
        }
        else
        {
            $importSalesStatuses = $import_settings['sales_status'];
        }

        if ( !isset($import_settings['lettings_status']) )
        {
            $importLettingsStatuses = apply_filters( "propertyhive_reapit_foundations_json_import_lettings_statuses", array( 'toLet', 'underOffer', 'arrangingTenancy', 'tenancyCurrent' ) );
        }
        else
        {
            $importLettingsStatuses = $import_settings['lettings_status'];
        }

        $property_status_counts = array();

        if ( $test === true )
        {
            $embed = array();
        }

        $requests = array();
        if ( !empty($importSalesStatuses) )
        {
        	$requests['sales'] = array(
                'pageSize' => $page_size,
                'pageNumber' => $page,
                'sellingStatus' => $importSalesStatuses,
                'internetAdvertising' => 'true',
                'fromArchive' => 'false',
                'isExternal' => 'false',
                'embed' => $embed
            );
        }
        if ( !empty($importLettingsStatuses) )
        {
        	$requests['lettings'] = array(
                'pageSize' => $page_size,
                'pageNumber' => $page,
                'lettingStatus' => $importLettingsStatuses,
                'internetAdvertising' => 'true',
                'fromArchive' => 'false',
                'isExternal' => 'false',
                'embed' => $embed
            );
        }

        $requests = apply_filters( 'propertyhive_reapit_foundations_json_properties_requests', $requests, $this->import_id );
        
        foreach ( $requests as $request_name => $url_parameters )
        {
            $total_pages = 999;
            $page = 1;

            $url_parameters = apply_filters( 'propertyhive_reapit_foundations_json_properties_url_parameters', $url_parameters, $this->import_id );

            $params_for_log = $url_parameters;
            unset($params_for_log['pageSize']);
            unset($params_for_log['pageNumber']);
            $this->log("Requesting " . $request_name . " properties with following params:");
            $this->log($this->build_reapit_query_string( $params_for_log ), 0, '', '', false);

            $url_string = $this->build_reapit_query_string( $url_parameters );

            while ( $page <= $total_pages )
            {
                $properties_this_page = array();

                if ( time() >= $token_expires )
                {
                    $token_response = $this->get_token();

                    if ( $token_response === false )
                    {
                        return false;
                    }

                    $token = $token_response['access_token'];
                    $token_expires = time() + $token_response['expires_in'];
                }

                $response = wp_remote_get(
                    'https://platform.reapit.cloud/properties?' . $url_string,
                    array(
                        'headers' => array(
                            'Authorization' => 'Bearer ' . $token,
                            'reapit-customer' => $import_settings['customer_id'],
                            'api-version' => '2020-01-31',
                        ),
                        'timeout' => 120
                    )
                );

                $this->log_api_request( 'properties' );

                if ( !empty($url_parameters['embed']) && in_array( 'negotiator', $url_parameters['embed'] ) )
                {
                    $this->log_api_request( 'negotiator' );
                }

                if ( !empty($url_parameters['embed']) && in_array( 'area', $url_parameters['embed'] ) )
                {
                    $this->log_api_request( 'area' );
                }

                if ( !is_wp_error( $response ) && is_array( $response ) && wp_remote_retrieve_response_code( $response ) === 200 && isset( $response['body'] ) )
                {
                    $body = $response['body']; // use the content

                    $json = json_decode( $body, TRUE );

                    if ( $json !== null && isset( $json['_embedded'] ) && is_array( $json['_embedded'] ) )
                    {
                        if ( $total_pages === 999 && isset( $json['totalPageCount'] ) )
                        {
                            $total_pages = $json['totalPageCount'];
                        }

                        $this->log("Parsing " . count($json['_embedded']) . " properties on page " . $page . ' / ' . $total_pages);

                        foreach ($json['_embedded'] as $property)
                        {
                            $property_id = $property['id'];

                            $ok_to_import = true;
                            $property_has_valid_status = false;

                            if ( $ok_to_import )
                            {
                                if ( isset( $property['marketingMode'] ) )
                                {
                                    switch ( $property['marketingMode'] )
                                    {
                                        case 'selling':
                                            if ( isset($property['selling']['status']) && in_array( $property['selling']['status'], $importSalesStatuses ) )
                                            {
                                                $property_has_valid_status = true;
                                                if ( !isset($property_status_counts[$property['selling']['status']]) ) { $property_status_counts[$property['selling']['status']] = 0; }
                                                ++$property_status_counts[$property['selling']['status']];
                                            }
                                            break;
                                        case 'letting':
                                            if ( isset($property['letting']['status']) && in_array( $property['letting']['status'], $importLettingsStatuses ) )
                                            {
                                                $property_has_valid_status = true;
                                                if ( !isset($property_status_counts[$property['letting']['status']]) ) { $property_status_counts[$property['letting']['status']] = 0; }
                                                ++$property_status_counts[$property['letting']['status']];
                                            }
                                            break;
                                        case 'sellingAndLetting':
                                            if ( isset($property['selling']['status']) && in_array( $property['selling']['status'], $importSalesStatuses ) )
                                            {
                                                $property_has_valid_status = true;
                                                if ( !isset($property_status_counts[$property['selling']['status']]) ) { $property_status_counts[$property['selling']['status']] = 0; }
                                                ++$property_status_counts[$property['selling']['status']];
                                            }

                                            if ( isset($property['letting']['status']) && in_array( $property['letting']['status'], $importLettingsStatuses ) )
                                            {
                                                $property_has_valid_status = true;
                                                if ( !isset($property_status_counts[$property['letting']['status']]) ) { $property_status_counts[$property['letting']['status']] = 0; }
                                                ++$property_status_counts[$property['letting']['status']];
                                            }
                                            break;
                                    }
                                }

                                if ( $property_has_valid_status )
                                {
                                    if ( $test === false && ( ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' ) || !isset($import_settings['only_updated']) ) )
                                    {
                                        $args = array(
                                            'post_type' => 'property',
                                            'posts_per_page' => 1,
                                            'post_status' => 'any',
                                            'fields' => 'ids',
                                            'meta_query' => array(
                                                array(
                                                    'key' => $imported_ref_key,
                                                    'value' => array( $property_id, $property_id . '-S', $property_id . '-L' ),
                                                    'compare' => 'IN'
                                                )
                                            )
                                        );
                                        $property_query = new WP_Query($args);

                                        if ($property_query->have_posts())
                                        {
                                            while ($property_query->have_posts())
                                            {
                                                $property_query->the_post();

                                                $reapit_eTag = $property['_eTag'];
                                                $previous_eTag = get_post_meta( get_the_ID(), '_reapit_foundations_json_eTag_' . $this->import_id, TRUE );

                                                if ($reapit_eTag == $previous_eTag)
                                                {
                                                    $ok_to_import = false;
                                                }
                                            }
                                        }
                                    }

                                    if ( $ok_to_import )
                                    {
                                        if ( $test === false && $get_utilities )
                                        {
                                            if ( time() >= $token_expires )
                                            {
                                                $token_response = $this->get_token($import_settings);

                                                if ( $token_response === false )
                                                {
                                                    return false;
                                                }

                                                $token = $token_response['access_token'];
                                                $token_expires = time() + $token_response['expires_in'];
                                            }

                                            $utilities_response = wp_remote_get(
                                                'https://platform.reapit.cloud/properties/' . $property_id . '/utilities',
                                                array(
                                                    'headers' => array(
                                                        'Authorization' => 'Bearer ' . $token,
                                                        'reapit-customer' => $import_settings['customer_id'],
                                                        'api-version' => '2020-01-31',
                                                    ),
                                                    'timeout' => 120
                                                )
                                            );

                                            $this->log_api_request( 'utilities' );

                                            if ( !is_wp_error( $utilities_response ) && is_array( $utilities_response ) && wp_remote_retrieve_response_code( $utilities_response ) === 200 && isset( $utilities_response['body'] ) )
                                            {
                                                $utilities_body = $utilities_response['body']; // use the content

                                                $utilities_json = json_decode( $utilities_body, TRUE );

                                                if ( $utilities_json !== null && $utilities_json !== false )
                                                {
                                                    $property['utilities'] = $utilities_json;
                                                }
                                                else
                                                {
                                                    // Failed to parse JSON
                                                    $this->log_error( 'Failed to parse utilities JSON file: ' . print_r($utilities_body, TRUE) );
                                                }
                                            }
                                            else
                                            {
                                                // Request failed
                                                $this->log_error( 'Request for utilities failed. Response: ' . print_r($utilities_response, TRUE) );
                                            }
                                        }

                                        if ( $property['marketingMode'] == 'sellingAndLetting' && !$this->is_commercial($property) )
                                        {
                                            if ( isset($property['selling']['status']) && in_array( $property['selling']['status'], $importSalesStatuses ) )
                                            {
                                                $property['marketingMode'] = 'selling';
                                                $property['id'] = $property_id . '-S';

                                                $properties_this_page[] = $property;
                                            }

                                            if ( isset($property['letting']['status']) && in_array( $property['letting']['status'], $importLettingsStatuses ) )
                                            {
                                                $property['marketingMode'] = 'letting';
                                                $property['id'] = $property_id . '-L';
                                            }

                                            $properties_this_page[] = $property;
                                        }
                                        else
                                        {
                                            $properties_this_page[] = $property;
                                        }

                                        $property_ids[] = $property_id;
                                    }
                                    else
                                    {
                                        // Property not been updated.
                                        // Lets create our own array so at least the property gets put into the $this->properties array and not removed
                                        if ( $property['marketingMode'] == 'sellingAndLetting' && !$this->is_commercial($property) )
                                        {
                                            if ( isset($property['selling']['status']) && in_array( $property['selling']['status'], $importSalesStatuses ) )
                                            {
                                                $property['fake'] = 'yes';
                                                $property['id'] = $property_id . '-S';
                                                $properties_this_page[] = $property;
                                            }

                                            if ( isset($property['letting']['status']) && in_array( $property['letting']['status'], $importLettingsStatuses ) )
                                            {
                                                $property['fake'] = 'yes';
                                                $property['id'] = $property_id . '-L';
                                                $properties_this_page[] = $property;
                                            }
                                        }
                                        else
                                        {
                                            $property['fake'] = 'yes';
                                            $property['id'] = $property_id;
                                            $properties_this_page[] = $property;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    else
                    {
                        // Failed to parse JSON
                        $this->log_error( 'Failed to parse JSON file: ' . print_r($body, TRUE) );
                        return false;
                    }
                }
                else
                {
                    // Request failed
                    $this->log_error( 'Request failed. Response: ' . print_r($response, TRUE) );

                    $body = $response['body']; // use the content

                    $json = json_decode( $body, TRUE );

                    if ( $json !== null && isset( $json['description'] ) && !empty($json['description']) )
                    {
                        $message = $json['description'];

                        if ( strpos($json['description'], 'application installed') !== false )
                        {
                            $message .= ' The application can be installed here: https://marketplace.reapit.cloud/apps/7974a1ed-7c36-4aa2-baef-d7f6da931a26';
                        }

                        $this->log_error( $message );
                    }

                    return false;
                }

                $this->properties = array_merge($this->properties, $properties_this_page);

                // Increment page number for while look and in URL string
                ++$page;
                $url_parameters['pageNumber'] = $page;
                $url_string = $this->build_reapit_query_string( $url_parameters );

                sleep(1);
            }
        }

        if ( !empty( $property_status_counts ) )
        {
            foreach ( $property_status_counts as $status => $count )
            {
                $this->log('Properties with status ' . $status . ': ' . $count);
            }
        }

        if ( $test === false && !empty($property_ids) )
        {
            $this->log("Parsing images");

            $property_ids = array_unique($property_ids);

            // get images
            $per_chunk = 50;

            $property_id_chunks = array_chunk($property_ids, $per_chunk);

            foreach ( $property_id_chunks as $chunk_i => $property_id_chunk )
            {
                $total_pages = 999;
                $page_size = 100;
                $page = 1;

                $url_parameters = array(
                    'pageSize' => $page_size,
                    'pageNumber' => $page,
                    'propertyId' => $property_id_chunk
                );

                $url_parameters = apply_filters( 'propertyhive_reapit_foundations_json_property_images_url_parameters', $url_parameters, $this->import_id );

                $url_string = $this->build_reapit_query_string( $url_parameters );

                while ( $page <= $total_pages )
                {
                    if ( time() >= $token_expires )
                    {
                        $token_response = $this->get_token($import_settings);

                        if ( $token_response === false )
                        {
                            return false;
                        }

                        $token = $token_response['access_token'];
                        $token_expires = time() + $token_response['expires_in'];
                    }

                    $response = wp_remote_get(
                        'https://platform.reapit.cloud/propertyImages?' . $url_string,
                        array(
                            'headers' => array(
                                'Authorization' => 'Bearer ' . $token,
                                'reapit-customer' => $import_settings['customer_id'],
                                'api-version' => '2020-01-31',
                            ),
                            'timeout' => 120
                        )
                    );

                    $this->log_api_request( 'propertyImages' );

                    if ( !is_wp_error( $response ) && is_array( $response ) && wp_remote_retrieve_response_code( $response ) === 200 && isset( $response['body'] ) )
                    {
                        $body = $response['body']; // use the content

                        $json = json_decode( $body, TRUE );

                        if ( $json !== null && isset( $json['_embedded'] ) && is_array( $json['_embedded'] ) )
                        {
                            if ( $total_pages === 999 && isset( $json['totalPageCount'] ) )
                            {
                                $total_pages = $json['totalPageCount'];
                            }

                            $this->log("Parsing images on page " . $page . ' of ' . $total_pages . ' in chunk ' . ($chunk_i + 1) . ' / ' . count($property_id_chunks));

                            foreach ($json['_embedded'] as $image)
                            {
                                // add image to relevant property
                                foreach ( $this->properties as $i => $property )
                                {
                                    if ( 
                                        $property['id'] == $image['propertyId'] || 
                                        $property['id'] == $image['propertyId'] . '-S' || 
                                        $property['id'] == $image['propertyId'] . '-L' 
                                    )
                                    {
                                        if ( !isset($this->properties[$i]['_embedded']['images']) )
                                        {
                                            $this->properties[$i]['_embedded']['images'] = array();
                                        }
                                        $this->properties[$i]['_embedded']['images'][] = $image;
                                    }
                                }
                            }
                        }
                        else
                        {
                            // Failed to parse JSON
                            $this->log_error( 'Failed to parse propertyImages JSON file: ' . print_r($body, TRUE) );
                            return false;
                        }
                    }
                    else
                    {
                        // Request failed
                        $this->log_error( 'propertyImages Request failed. Response: ' . print_r($response, TRUE) );
                        return false;
                    }
                    // Increment page number for while look and in URL string
                    ++$page;
                    $url_parameters['pageNumber'] = $page;
                    $url_string = $this->build_reapit_query_string( $url_parameters );
                }
            }
        }

        if ( $test === false )
        {
            update_option( 'propertyhive_reapit_foundations_negotiator_get_' . $this->import_id, time() );
            update_option( 'propertyhive_reapit_foundations_utilities_get_' . $this->import_id, time() );
        }
        
        return true;
    }

    public function import()
    {
        global $wpdb;

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $this->import_start();

        do_action( "propertyhive_pre_import_properties_reapit_foundations_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_reapit_foundations_json_properties_due_import", $this->properties, $this->import_id );

        $importing = 0;
        foreach ( $this->properties as $property )
        {
            if ( !isset($property['fake']) )
            {
                ++$importing;
            }
        }

        $this->log( 'Beginning to loop through ' . count($this->properties) . ' properties (importing ' . $importing . ')' );

        $property_row = 1;

        foreach ( $this->properties as $property )
        {
            if ( !isset($property['fake']) )
            {
                do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
                do_action( "propertyhive_property_importing_reapit_foundations_json", $property, $this->import_id, $this->instance_id );

                $this->log( 'Importing property ' . $property_row .' with reference ' . $property['id'], 0, $property['id'], '', false );

                $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => $importing));

                if ( !empty( $property['strapline'] ) )
                {
                    $post_title = $property['strapline'];
                }
                else
                {
                    $address_fields_array = array_filter(array(
                        $property['address']['line1'],
                        $property['address']['line2'],
                        $property['address']['line3'],
                        $property['address']['line4'],
                        $property['address']['postcode'],
                    ));

                    $post_title = implode(', ', $address_fields_array);
                }

                $post_date = '';
                if ( 
                    isset($property['created']) &&
                    !empty($property['created'])
                )
                {                   
                    $date = new DateTime($property['created']);
                    $date->setTimezone(new DateTimeZone('UTC'));

                    $post_date = $date->format('Y-m-d H:i:s');
                }

                list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $post_title, ( ( isset($property['description']) ) ? $property['description'] : '' ), '', $post_date );

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

                    // Address
                    update_post_meta( $post_id, '_reference_number', ( ( isset( $property['alternateId'] ) && !empty( $property['alternateId'] ) ) ? $property['alternateId'] : $property['id'] ) );

                    $name_number_parts = array_filter( array(
                        isset($property['address']['buildingName']) ? trim( $property['address']['buildingName'] ) : '',
                        isset($property['address']['buildingNumber']) ? trim( $property['address']['buildingNumber'] ) : '',
                    ));
                    update_post_meta( $post_id, '_address_name_number', implode( ', ', $name_number_parts ) );

                    update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['line1']) ) ? $property['address']['line1'] : '' ) );
                    update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['line2']) ) ? $property['address']['line2'] : '' ) );
                    update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['line3']) ) ? $property['address']['line3'] : '' ) );
                    update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['line4']) ) ? $property['address']['line4'] : '' ) );
                    update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address']['postcode']) ) ? $property['address']['postcode'] : '' ) );

                    $country = isset($property['address']['countryId']) && strlen($property['address']['countryId']) == 2 ? strtoupper($property['address']['countryId']) : get_option( 'propertyhive_default_country', 'GB' );
                    update_post_meta( $post_id, '_address_country', $country );

                    // Coordinates
                    update_post_meta( $post_id, '_latitude', ( ( isset($property['address']['geolocation']['latitude']) ) ? $property['address']['geolocation']['latitude'] : '' ) );
                    update_post_meta( $post_id, '_longitude', ( ( isset($property['address']['geolocation']['longitude']) ) ? $property['address']['geolocation']['longitude'] : '' ) );

                    // Location
                    $location_set = false;
                    if ( isset( $property['_embedded']['area'] ) && is_array( $property['_embedded']['area'] ) && count( $property['_embedded']['area'] ) > 0 )
                    {
                        $reapit_location = $property['_embedded']['area']['name'];
                        if ( trim($reapit_location) != '' )
                        {
                            $term = term_exists( trim($reapit_location), 'location');
                            if ( $term !== 0 && $term !== null && isset($term['term_id']) )
                            {
                            	$location_set = true;
                                wp_set_object_terms( $post_id, array( (int)$term['term_id'] ), 'location' );
                            }
                        }
                    }
                    if ( !$location_set )
                    {
	                	wp_delete_object_term_relationships( $post_id, 'location' );
	                }

                    // Owner
                    add_post_meta( $post_id, '_owner_contact_id', '', true );

                    // Record Details
                    $new_negotiator_id = '';

                    // Check if negotiator exists with this name
                    if ( isset( $property['negotiatorId'] ) )
                    {
                        if ( isset( $property['_embedded']['negotiator']['id'] ) && $property['_embedded']['negotiator']['id'] == $property['negotiatorId'] )
                        {
                            $negotiator_name = $property['_embedded']['negotiator']['name'];

                            foreach ( $this->negotiators as $negotiator_key => $negotiator )
                            {
                                if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim( $negotiator_name )) )
                                {
                                    $new_negotiator_id = $negotiator_key;
                                }
                            }
                        }
                    }

                    if ( $new_negotiator_id == '' )
                    {
                        $new_negotiator_id = get_post_meta( $post_id, '_negotiator_id', TRUE );
                        if ( $new_negotiator_id == '' )
                        {
                            // no neg found and no existing neg
                            $new_negotiator_id = get_current_user_id();
                        }
                    }

                    update_post_meta( $post_id, '_negotiator_id', $new_negotiator_id );

                    $office_id = $this->primary_office_id;

                    if ( isset( $property['officeIds'] ) && is_array( $property['officeIds'] ) && count( $property['officeIds'] ) > 0 )
                    {
                        $passed_office_id = $property['officeIds'][0];
                        if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
                        {
                            foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                            {
                                $branch_code_array = explode(',', $branch_code);
                                $branch_code_array = array_map('trim', $branch_code_array);

                                if ( in_array( $passed_office_id, $branch_code_array ) )
                                {
                                    $office_id = $ph_office_id;
                                    break;
                                }
                            }
                        }
                    }
                    update_post_meta( $post_id, '_office_id', $office_id );

                    $department = $property['marketingMode'] != 'selling' ? 'residential-lettings' : 'residential-sales';
                    if ( $this->is_commercial($property) ) { $department = 'commercial'; }
                    update_post_meta( $post_id, '_department', $department );

                    update_post_meta( $post_id, '_bedrooms', isset($property['bedrooms']) ? $property['bedrooms'] : '' );
                    update_post_meta( $post_id, '_bathrooms', isset($property['bathrooms']) ? $property['bathrooms'] : '' );
                    update_post_meta( $post_id, '_reception_rooms', isset($property['receptions']) ? $property['receptions'] : '' );
                    update_post_meta( $post_id, '_council_tax_band', ( isset($property['councilTax']) && !empty($property['councilTax']) ) ? $property['councilTax'] : '' );

                    $prefix = '';
                    if ( $department == 'commercial' )
                    {
                        $prefix = 'commercial_';
                    }
                    $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                    $propertyTypeStyles = array();

                    if ( isset($property['type']) && is_array($property['type']) && !empty($property['type']) )
                    {
                        if ( !isset($property['style']) || empty($property['style']) )
                        {
                            // no style. Lets just look at all the types
                            foreach ( $property['type'] as $type )
                            {
                                $propertyTypeStyles[] = $type;
                            }
                        }
                        else
                        {
                            $propertyTypeStyle = $property['type'][0];

                            if ( isset($property['style']) && is_array($property['style']) && !empty($property['style']) )
                            {
                                $propertyTypeStyle .= ' - ' . $property['style'][0];
                            }

                            $propertyTypeStyles[] = $propertyTypeStyle;
                        }
                    }

                    if ( isset($property['unmappedAttributes']) && is_array($property['unmappedAttributes']) && !empty($property['unmappedAttributes']) )
                    {
                        foreach ( $property['unmappedAttributes'] as $unmapped_attribute )
                        {
                            if ( isset($unmapped_attribute['type']) && strpos(strtolower($unmapped_attribute['type']), 'type') !== FALSE )
                            {
                                $propertyTypeStyles[] = $unmapped_attribute['value'];
                            }
                        }
                    }

                    $type_term_ids = array();

                    if ( !empty($propertyTypeStyles) )
                    {
                        $propertyTypeStyles = array_unique($propertyTypeStyles);
                        
                        foreach ( $propertyTypeStyles as $propertyTypeStyle )
                        {
                            if ( !empty($mapping) && isset($mapping[$propertyTypeStyle]) )
                            {
                                $type_term_ids[] = (int)$mapping[$propertyTypeStyle];
                            }
                            else
                            {
                                $this->log( 'Property received with a type (' . $propertyTypeStyle . ') that is not mapped', $post_id, $property['id'] );

                                $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $propertyTypeStyle, $post_id );
                            }
                        }                   
                    }

                    if ( !empty($type_term_ids) )
                    {
                        wp_set_object_terms( $post_id, $type_term_ids, $prefix . 'property_type' );
                    } 
                    else
	                {
	                	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
	                }

                    // Residential Sales Details
                    if ( $department == 'residential-sales' )
                    {
                        $price = isset( $property['selling']['price'] ) ? $property['selling']['price'] : 0;

                        update_post_meta( $post_id, '_price', $price );
                        update_post_meta( $post_id, '_price_actual', $price );

                        update_post_meta( $post_id, '_currency', isset( $property['currency'] ) ? $property['currency'] : 'GBP' );

                        $poa = ( isset( $property['selling']['qualifier'] ) && $property['selling']['qualifier'] == 'priceOnApplication' ) ? 'yes' : '';
                        update_post_meta( $post_id, '_poa', $poa );

                        // Price Qualifier
                        $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
                        
                        if ( !empty($mapping) && isset($property['selling']['qualifier']) && isset($mapping[$property['selling']['qualifier']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['selling']['qualifier']], 'price_qualifier' );
                        }
                        else
		                {
		                	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		                }

                        // Tenure
                        $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

                        if ( !empty($mapping) && isset($property['selling']['tenure']['type']) && isset($mapping[$property['selling']['tenure']['type']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['selling']['tenure']['type']], 'tenure' );
                        }
                        else
                        {
                        	wp_delete_object_term_relationships( $post_id, 'tenure' );
                        }

                        if ( isset($property['selling']['tenure']['type']) && in_array($property['selling']['tenure']['type'], array('leasehold', 'shareOfFreehold')) )
                        {
                            // Leasehold
                            update_post_meta( $post_id, '_shared_ownership', ( ( isset($property['sharedOwnership']['sharedPercentage']) && !empty($property['sharedOwnership']['sharedPercentage']) ) ? 'yes' : '' ) );
                            update_post_meta( $post_id, '_shared_ownership_percentage', ( ( isset($property['sharedOwnership']['sharedPercentage']) && !empty($property['sharedOwnership']['sharedPercentage']) ) ? $property['sharedOwnership']['sharedPercentage'] : '' ) );
                            update_post_meta( $post_id, '_ground_rent', (isset($property['groundRent']) && !empty($property['groundRent'])) ? $property['groundRent'] : '' );
                            update_post_meta( $post_id, '_ground_rent_review_years', '' );
                            update_post_meta( $post_id, '_service_charge', (isset($property['serviceCharge']) && !empty($property['serviceCharge'])) ? $property['serviceCharge'] : '' );
                            
                            $leasehold_years_remaining = '';
                            if ( isset($property['selling']['tenure']['expiry']) && !empty($property['selling']['tenure']['expiry']) )
                            {
                                $date1 = new DateTime();
                                $date2 = new DateTime($property['selling']['tenure']['expiry']);
                                $interval = $date1->diff($date2);
                                $leasehold_years_remaining = $interval->y;
                            }
                            update_post_meta( $post_id, '_leasehold_years_remaining', $leasehold_years_remaining );
                        }

                        // Sale By
                        $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

                        if ( !empty($mapping) && isset($property['selling']['disposal']) && isset($mapping[$property['selling']['disposal']]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$property['selling']['disposal']], 'sale_by' );
                        }
                        else
                        {
                        	wp_delete_object_term_relationships( $post_id, 'sale_by' );
                        }
                    }
                    elseif ( $department == 'residential-lettings' )
                    {
                        $price = isset( $property['letting']['rent'] ) ? $property['letting']['rent'] : 0;

                        update_post_meta( $post_id, '_rent', $price );

                        $rent_frequency = 'pcm';
                        $price_actual = $price;
                        if ( isset( $property['letting']['rentFrequency'] ) )
                        {
                            switch ( $property['letting']['rentFrequency'] )
                            {
                                case 'monthly': { $rent_frequency = 'pcm'; $price_actual = $price; break; }
                                case 'weekly': { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
                                case 'yearly':
                                case 'annually': { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
                            }
                        }
                        update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
                        update_post_meta( $post_id, '_price_actual', $price_actual );

                        $poa = ( isset( $property['letting']['qualifier'] ) && $property['letting']['qualifier'] == 'rentOnApplication' ) ? 'yes' : '';
                        update_post_meta( $post_id, '_poa', $poa );

                        update_post_meta( $post_id, '_currency', isset( $property['currency'] ) ? $property['currency'] : 'GBP' );

                        $deposit = '';
                        if ( 
                            isset($property['letting']['deposit']) && 
                            isset($property['letting']['deposit']['amount']) && !empty($property['letting']['deposit']['amount']) && 
                            isset($property['letting']['deposit']['type']) && !empty($property['letting']['deposit']['type']) 
                        )
                        {
                            switch ( $property['letting']['deposit']['type'] )
                            {
                                case "fixed":
                                {
                                    $deposit = $property['letting']['deposit']['amount'];
                                    break;
                                }
                                case "weeks":
                                {
                                    $deposit = floor((($price_actual * 12) / 52) * $property['letting']['deposit']['amount'] * 100) / 100;
                                    break;
                                }
                            }
                        }
                        update_post_meta( $post_id, '_deposit', $deposit );
                        update_post_meta( $post_id, '_available_date', isset( $property['letting']['availableFrom'] ) && !empty( $property['letting']['availableFrom'] ) ? $property['letting']['availableFrom'] : '' );

                        // Furnished
                        $mapped_furnishing = '';
                        if ( isset( $property['letting']['furnishing'] ) && count( $property['letting']['furnishing'] ) > 0 )
                        {
                            $mapped_furnishing = $property['letting']['furnishing'][0];
                        }

                        $mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

                        if ( !empty($mapping) && isset($mapping[$mapped_furnishing]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[$mapped_furnishing], 'furnished' );
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

                        if ( $property['marketingMode'] == 'selling' || $property['marketingMode'] == 'sellingAndLetting' )
                        {
                            update_post_meta( $post_id, '_for_sale', 'yes' );

                            update_post_meta( $post_id, '_commercial_price_currency', isset( $property['currency'] ) ? $property['currency'] : 'GBP' );

                            $price = isset( $property['selling']['price'] ) ? $property['selling']['price'] : 0;
                            $price = preg_replace("/[^0-9.]/", '', $price);

                            update_post_meta( $post_id, '_price_from', $price );
                            update_post_meta( $post_id, '_price_to', $price );

                            update_post_meta( $post_id, '_price_units', '' );

                            $poa = ( isset( $property['selling']['qualifier'] ) && $property['selling']['qualifier'] == 'priceOnApplication' ) ? 'yes' : '';
                            update_post_meta( $post_id, '_price_poa', $poa );

                            // Price Qualifier
                            $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

                            if ( !empty($mapping) && isset($property['selling']['qualifier']) && isset($mapping[$property['selling']['qualifier']]) )
                            {
                                wp_set_object_terms( $post_id, (int)$mapping[$property['selling']['qualifier']], 'price_qualifier' );
                            }
                            else
                            {
                            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                            }

                            // Tenure
                            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

                            if ( !empty($mapping) && isset($property['selling']['tenure']['type']) && isset($mapping[$property['selling']['tenure']['type']]) )
                            {
                                wp_set_object_terms( $post_id, (int)$mapping[$property['selling']['tenure']['type']], 'commercial_tenure' );
                            }
                            else
                            {
                            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
                            }
                        }

                        if ( $property['marketingMode'] == 'letting' || $property['marketingMode'] == 'sellingAndLetting' )
                        {
                            update_post_meta( $post_id, '_to_rent', 'yes' );

                            update_post_meta( $post_id, '_commercial_rent_currency', isset( $property['currency'] ) ? $property['currency'] : 'GBP' );

                            $rent = isset( $property['letting']['rent'] ) ? $property['letting']['rent'] : 0;
                            $rent = preg_replace("/[^0-9.]/", '', $rent);

                            update_post_meta( $post_id, '_rent_from', $rent );
                            update_post_meta( $post_id, '_rent_to', $rent );

                            $rent_frequency = 'pw';
                            if ( isset( $property['letting']['rentFrequency'] ) )
                            {
                                switch ( $property['letting']['rentFrequency'] )
                                {
                                    case 'monthly': { $rent_frequency = 'pcm'; break; }
                                    case 'weekly': { $rent_frequency = 'pw'; break; }
                                    case 'yearly':
                                    case 'annually': { $rent_frequency = 'pa'; break; }
                                }
                            }
                            update_post_meta( $post_id, '_rent_units', $rent_frequency);

                            $poa = ( isset( $property['letting']['qualifier'] ) && $property['letting']['qualifier'] == 'rentOnApplication' ) ? 'yes' : '';
                            update_post_meta( $post_id, '_rent_poa', $poa );
                        }

                        $size = isset( $property['internalArea']['min'] ) ? $property['internalArea']['min'] : '';
                        $size = preg_replace("/[^0-9.]/", '', $property['internalArea']['min']);
                        $size_unit = 'sqft';
                        if ( isset( $property['internalArea']['type'] ) )
                        {
                            switch ( $property['internalArea']['type'] )
                            {
                                case "squareMeter": { $size_unit = 'sqm'; break; }
                                case "acres": { $size_unit = 'acre'; break; }
                            }
                        }
                        update_post_meta( $post_id, '_floor_area_from', $size );

                        update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $size_unit ) );

                        $size = isset( $property['internalArea']['max'] ) ? $property['internalArea']['max'] : '';
                        $size = preg_replace("/[^0-9.]/", '', $property['internalArea']['max']);
                        if ( $size == 0 && isset( $property['internalArea']['min'] ) && !empty($property['internalArea']['min']) ) { $size = ''; }
                        update_post_meta( $post_id, '_floor_area_to', $size );

                        update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $size_unit ) );

                        update_post_meta( $post_id, '_floor_area_units', $size_unit );

                        $size_unit = 'sqft';
                        if ( isset( $property['externalArea']['type'] ) )
                        {
                            switch ( $property['externalArea']['type'] )
                            {
                                case "squareMeter": { $size_unit = 'sqm'; break; }
                                case "acres": { $size_unit = 'acre'; break; }
                            }
                        }

                        $size = isset( $property['externalArea']['min'] ) ? $property['externalArea']['min'] : '';
                        $size = preg_replace("/[^0-9.]/", '', $property['externalArea']['min']);
                        update_post_meta( $post_id, '_site_area_from', $size );
                        update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, $size_unit ) );

                        $size = isset( $property['externalArea']['max'] ) ? $property['externalArea']['max'] : '';
                        $size = preg_replace("/[^0-9.]/", '', $property['externalArea']['max']);
                        if ( $size == 0 && isset( $property['externalArea']['min'] ) && !empty($property['externalArea']['min']) ) { $size = ''; }
                        update_post_meta( $post_id, '_site_area_to', $size );
                        update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, $size_unit ) );

                        update_post_meta( $post_id, '_site_area_units', $size_unit );
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
                        if ( isset( $property['utilities']['electricity']['type'] ) && !empty($property['utilities']['electricity']['type']) ) 
                        {
                            $supply_value = $property['utilities']['electricity']['type'];
                            switch ( $supply_value ) 
                            {
                                case 'mainsSupply': $utility_type[] = 'mains_supply'; break;
                                case 'privateSupply': $utility_type[] = 'private_supply'; break;
                                case 'solar': $utility_type[] = 'solar_pv_panels'; break;
                                case 'windTurbine': $utility_type[] = 'wind_turbine'; break;
                                default: 
                                    $utility_type[] = 'other'; 
                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                    break;
                            }
                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_electricity_type', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_electricity_type_other', $utility_type_other );
                            }
                        }
                        
                        // Water
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['utilities']['water']['type'] ) && !empty( $property['utilities']['water']['type'] ) ) 
                        {
                            $supply_value = $property['utilities']['water']['type'];
                            switch ( $supply_value ) 
                            {
                                case 'mainsSupply': $utility_type[] = 'mains_supply'; break;
                                case 'privateSupply': $utility_type[] = 'private_supply'; break;
                                default: 
                                    $utility_type[] = 'other'; 
                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                    break;
                            }
                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_water_type', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_water_type_other', $utility_type_other );
                            }
                        }
                        
                        // Heating
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['utilities']['heating'] ) && is_array($property['utilities']['heating']) && !empty( $property['utilities']['heating'] ) ) 
                        {
                            foreach ( $property['utilities']['heating'] as $source_value )
                            {
                                switch ( $source_value ) 
                                {
                                    case 'airConditioning': $utility_type[] = 'air_conditioning'; break;
                                    case 'centralHeating': $utility_type[] = 'central'; break;
                                    case 'doubleGlazing': $utility_type[] = 'double_glazing'; break;
                                    case 'ecoFriendly': $utility_type[] = 'eco_friendly'; break;
                                    case 'electric': $utility_type[] = 'electric'; break;
                                    case 'gas': $utility_type[] = 'gas'; break;
                                    case 'gasCentral': $utility_type[] = 'gas_central'; break;
                                    case 'nightStorage': $utility_type[] = 'night_storage'; break;
                                    case 'oil': $utility_type[] = 'oil'; break;
                                    case 'solar': $utility_type[] = 'solar'; break;
                                    case 'solarWater': $utility_type[] = 'solar_water'; break;
                                    case 'underFloor': $utility_type[] = 'under_floor'; break;
                                    case 'woodBurner': $utility_type[] = 'wood_burner'; break;
                                    case 'openFire': $utility_type[] = 'open_fire'; break;
                                    case 'biomassBoiler': $utility_type[] = 'biomass_boiler'; break;
                                    case 'groundSourceHeatPump': $utility_type[] = 'ground_source_heat_pump'; break;
                                    case 'airSourceHeatPump': $utility_type[] = 'air_source_heat_pump'; break;
                                    case 'solarPvThermal': $utility_type[] = 'solar_pv_thermal'; break;
                                    case 'underfloorHeating': $utility_type[] = 'under_floor'; break;
                                    case 'solarThermal': $utility_type[] = 'solar_thermal'; break;
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
                                        break;
                                }
                            }

                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_heating_type', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_heating_type_other', $utility_type_other );
                            }
                        }
                        
                        // Broadband
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( 
                            isset( $property['utilities']['internet']['type'] ) 
                            &&
                            is_array( $property['utilities']['internet']['type'] ) 
                            &&
                            !empty( $property['utilities']['internet']['type'] )
                        ) 
                        {
                            foreach ( $property['utilities']['internet']['type'] as $supply_value )
                            {
                                switch ( $supply_value ) 
                                {
                                    case 'adsl': $utility_type[] = 'adsl'; break;
                                    case 'cable': $utility_type[] = 'cable'; break;
                                    case 'fttc': $utility_type[] = 'fttc'; break;
                                    case 'fttp': $utility_type[] = 'fttp'; break;
                                    case 'none': $utility_type[] = 'none'; break;
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_broadband_type', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_broadband_type_other', $utility_type_other );
                            }
                        }
                        
                        // Sewerage
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( isset( $property['utilities']['water']['sewerage'] ) && !empty( $property['utilities']['water']['sewerage'] ) ) 
                        {
                            $supply_value = $property['utilities']['water']['sewerage'];
                            switch ( $supply_value ) 
                            {
                                case 'mainsSupply': $utility_type[] = 'mains_supply'; break;
                                case 'privateSupply': $utility_type[] = 'private_supply'; break;
                                default: 
                                    $utility_type[] = 'other'; 
                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                    break;
                            }
                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_sewerage_type', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_sewerage_type_other', $utility_type_other );
                            }
                        }
                        
                        // Accessbility
                        $utility_type = [];
                        $utility_type_other = '';
                        if ( 
                            isset( $property['accessibility'] ) 
                            &&
                            is_array( $property['accessibility'] ) 
                            &&
                            !empty( $property['accessibility'] )
                        ) 
                        {
                            foreach ( $property['accessibility'] as $supply_value )
                            {
                                switch ( $supply_value ) 
                                {
                                    case 'unsuitableForWheelchairs': $utility_type[] = 'unsuitableForWheelchairs'; break;
                                    case 'levelAccess': $utility_type[] = 'level_access'; break;
                                    case 'liftAccess': $utility_type[] = 'lift_access'; break;
                                    case 'rampedAccess': $utility_type[] = 'ramped_access'; break;
                                    case 'wetRoom': $utility_type[] = 'wet_room'; break;
                                    case 'wideDoorways': $utility_type[] = 'wide_doorways'; break;
                                    case 'stepFreeAccess': $utility_type[] = 'step_free_access'; break;
                                    case 'levelAccessShower': $utility_type[] = 'level_access_shower'; break;
                                    case 'lateralLiving': $utility_type[] = 'lateral_living'; break;
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_accessibility', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_accessibility_other', $utility_type_other );
                            }
                        }
                        
                        if ( 
                            isset( $property['floodErosion']['floodedInLastFiveYears'] ) &&
                            $property['floodErosion']['floodedInLastFiveYears'] !== null
                        )
                        {
                            $flooded_in_last_five_years = 'no';
                            if ( $property['floodErosion']['floodedInLastFiveYears'] === true ) { $flooded_in_last_five_years = 'yes'; }
                            update_post_meta($post_id, '_flooded_in_last_five_years', $flooded_in_last_five_years );
                        }
                        
                        
                        if ( 
                            isset( $property['floodErosion']['floodDefences'] ) && 
                            $property['floodErosion']['floodDefences'] !== null
                        )
                        {
                            $flood_defenses = 'no';
                            if ( $property['floodErosion']['floodDefences'] === true ) { $flood_defenses = 'yes'; }
                            update_post_meta($post_id, '_flood_defences', $flood_defenses );
                        }
                        
                        if ( 
                            isset( $property['floodErosion']['floodSources'] ) 
                            &&
                            is_array( $property['floodErosion']['floodSources'] ) 
                            &&
                            !empty( $property['floodErosion']['floodSources'] )
                        ) 
                        {
                            foreach ( $property['floodErosion']['floodSources'] as $supply_value )
                            {
                                switch ( $supply_value ) 
                                {
                                    case 'river': $utility_type[] = 'river'; break;
                                    case 'lake': $utility_type[] = 'lake'; break;
                                    case 'sea': $utility_type[] = 'sea'; break;
                                    case 'reservoir': $utility_type[] = 'reservoir'; break;
                                    case 'groundwater': $utility_type[] = 'groundwater'; break;
                                    default: 
                                        $utility_type[] = 'other'; 
                                        $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
                                        break;
                                }
                            }
                            $utility_type = array_unique($utility_type);

                            update_post_meta( $post_id, '_flood_source_type', $utility_type );
                            if ( in_array( 'other', $utility_type ) ) 
                            {
                                update_post_meta( $post_id, '_flood_source_type_other', $utility_type_other );
                            }
                        }
                    }

                    // Marketing
                    $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                    if ( $on_market_by_default === true )
                    {
                        update_post_meta( $post_id, '_on_market', 'yes' );
                    }
                    //update_post_meta( $post_id, '_featured', '' ); // Dont set as there is no definitive Featured field like there is in the SOAP integration

                    // Availability
                    $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
                        $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] :
                        array();

                    $availability_set = false;
                    if ( !empty($mapping) )
                    {
                        if ( 
                            $department == 'residential-sales' &&
                            isset($property['selling']['status']) &&
                            isset($mapping[$property['selling']['status']])
                        )
                        {
                        	$availability_set = true;
                            wp_set_object_terms( $post_id, (int)$mapping[$property['selling']['status']], 'availability' );
                        } 
                        elseif ( 
                            $department == 'residential-lettings' &&
                            isset($property['letting']['status']) &&
                            isset($mapping[$property['letting']['status']])
                        )
                        {
                        	$availability_set = true;
                            wp_set_object_terms( $post_id, (int)$mapping[$property['letting']['status']], 'availability' );
                        }
                        elseif (
                            $department == 'commercial'
                        )
                        {
                            $status = isset( $property['selling']['status'] ) ? $property['selling']['status'] : '';
                            if ( empty($status) && isset( $property['letting']['status'] ) )
                            {
                                $status = $property['letting']['status'];
                            }
                            if ( isset($mapping[$status]) )
                            {
	                            $availability_set = true;
	                            wp_set_object_terms( $post_id, (int)$mapping[$status], 'availability' );
	                        }
                        }
                    }
                    if ( !$availability_set )
                    {
                    	wp_delete_object_term_relationships( $post_id, 'availability' );
                    }

                    // Parking
                    $mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();

                    $parking_term_ids = array();
                    if ( isset($property['parking']) && !empty($property['parking']) )
                    {
                        foreach( $property['parking'] as $parking )
                        {
                            if ( !empty($mapping) && isset($mapping[$parking]) )
                            {
                                $parking_term_ids[] = (int)$mapping[$parking];
                            }
                            else
                            {
                                $this->log( 'Property received with a parking (' . $parking . ') that is not mapped', $post_id, $property['id'] );

                                $import_settings = $this->add_missing_mapping( $mapping, 'parking', $parking, $post_id );
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
                    if ( isset($property['situation']) && !empty($property['situation']) )
                    {
                        foreach( $property['situation'] as $outside_space )
                        {
                            if ( !empty($mapping) && isset($mapping[$outside_space]) )
                            {
                                $outside_space_term_ids[] = (int)$mapping[$outside_space];
                            }
                            else
                            {
                                $this->log( 'Property received with an outside_space (' . $outside_space . ') that is not mapped', $post_id, $property['id'] );

                                $import_settings = $this->add_missing_mapping( $mapping, 'outside_space', $outside_space, $post_id );
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

                    // Features
                    $features = array();
                    if ( isset($property['specialFeatures']) && !empty($property['specialFeatures']) )
                    {
                        foreach ( $property['specialFeatures'] as $feature )
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

                    // Rooms
                    if ( $department == 'commercial' )
                    {
                        $rooms_count = 0;
                        if ( isset( $property['longDescription'] ) && !empty( $property['longDescription'] ) )
                        {
                            update_post_meta( $post_id, '_description_name_0', '' );
                            update_post_meta( $post_id, '_description_0', $property['longDescription'] );

                            ++$rooms_count;
                        }

                        if ( isset( $property['rooms'] ) && is_array( $property['rooms'] ) )
                        {
                            foreach( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_description_name_' . $rooms_count, $room['name'] );
                                update_post_meta( $post_id, '_description_' . $rooms_count, $room['description'] );

                                ++$rooms_count;
                            }
                        }

                        if ( $rooms_count > 0 )
                        {
                            update_post_meta( $post_id, '_descriptions', $rooms_count );
                        }
                    }
                    else
                    {
                        $rooms_count = 0;
                        if ( isset( $property['longDescription'] ) && !empty( $property['longDescription'] ) )
                        {
                            update_post_meta( $post_id, '_room_name_0', '' );
                            update_post_meta( $post_id, '_room_dimensions_0', '' );
                            update_post_meta( $post_id, '_room_description_0', $property['longDescription'] );

                            ++$rooms_count;
                        }

                        if ( isset( $property['rooms'] ) && is_array( $property['rooms'] ) )
                        {
                            foreach( $property['rooms'] as $room )
                            {
                                update_post_meta( $post_id, '_room_name_' . $rooms_count, $room['name'] );
                                update_post_meta( $post_id, '_room_dimensions_' . $rooms_count, $room['dimensions'] );
                                update_post_meta( $post_id, '_room_description_' . $rooms_count, $room['description'] );

                                ++$rooms_count;
                            }
                        }

                        if ( $rooms_count > 0 )
                        {
                            update_post_meta( $post_id, '_rooms', $rooms_count );
                        }
                    }

                    // If there is media, order the array by the order field
                    if (isset($property['_embedded']['images']) && !empty($property['_embedded']['images']))
                    {
                        $media_order = array_column($property['_embedded']['images'], 'order');

                        array_multisort($media_order, SORT_ASC, $property['_embedded']['images']);
                    }

                    // Media - Images
				    $media = array();
				    if (isset($property['_embedded']['images']) && !empty($property['_embedded']['images']))
                    {
                        foreach ($property['_embedded']['images'] as $photo)
                        {
                            if ( isset( $photo['type'] ) && in_array( $photo['type'], apply_filters( 'propertyhive_property_import_reapit_foundations_photo_types', array('photograph', 'map') ) ) )
                            {
                                $modified = ( (isset($photo['modified'])) ? $photo['modified'] : '' );
                                if ( !empty($modified) )
                                {
                                    $dateTime = new DateTime($modified);
                                    $modified = $dateTime->format('Y-m-d H:i:s');
                                }

								$media[] = array(
									'url' => $photo['url'],
									'description' => ( (isset($photo['caption'])) ? $photo['caption'] : '' ),
									'modified' => $modified,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if (isset($property['_embedded']['images']) && !empty($property['_embedded']['images']))
                    {
                        foreach ($property['_embedded']['images'] as $photo)
                        {
                            if ( isset( $photo['type'] ) && in_array( $photo['type'], array('floorPlan') ) )
                            {
                                $modified = ( (isset($photo['modified'])) ? $photo['modified'] : '' );
                                if ( !empty($modified) )
                                {
                                    $dateTime = new DateTime($modified);
                                    $modified = $dateTime->format('Y-m-d H:i:s');
                                }

								$media[] = array(
									'url' => $photo['url'],
									'description' => ( (isset($photo['caption'])) ? $photo['caption'] : '' ),
									'modified' => $modified,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'floorplan', $media, true );

					// Media - Brochures
				    $media = array();
				    if ( isset( $property['selling']['publicBrochureUrl'] ) && !empty($property['selling']['publicBrochureUrl']) )
                    {
                        $modified = $property['modified'];
                        if ( !empty($modified) )
                        {
                            $dateTime = new DateTime($modified);
                            $modified = $dateTime->format('Y-m-d H:i:s');
                        }

						$media[] = array(
							'url' => $property['selling']['publicBrochureUrl'],
							'filename' => 'brochure-' . $post_id . 's.pdf',
							'description' => 'Brochure',
							'modified' => $modified,
						);
					}
					if ( isset( $property['letting']['publicBrochureUrl'] ) && !empty($property['letting']['publicBrochureUrl']) )
                    {
                        $modified = $property['modified'];
                        if ( !empty($modified) )
                        {
                            $dateTime = new DateTime($modified);
                            $modified = $dateTime->format('Y-m-d H:i:s');
                        }

						$media[] = array(
							'url' => $property['letting']['publicBrochureUrl'],
							'filename' => 'brochure-' . $post_id . 'l.pdf',
							'description' => 'Brochure',
							'modified' => $modified,
						);
					}

					$this->import_media( $post_id, $property['id'], 'brochure', $media, true );

					// Media - EPCs
				    $media = array();
				    if (isset($property['_embedded']['images']) && !empty($property['_embedded']['images']))
                    {
                        foreach ($property['_embedded']['images'] as $photo)
                        {
                            if ( isset( $photo['type'] ) && in_array( $photo['type'], array('epc', 'commercialEpc') ) )
                            {
                                $modified = ( (isset($photo['modified'])) ? $photo['modified'] : '' );
                                if ( !empty($modified) )
                                {
                                    $dateTime = new DateTime($modified);
                                    $modified = $dateTime->format('Y-m-d H:i:s');
                                }

								$media[] = array(
									'url' => $photo['url'],
									'description' => ( (isset($photo['caption'])) ? $photo['caption'] : '' ),
									'modified' => $modified,
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'epc', $media, true );

                    // Media - Virtual Tours/Movies
                    $virtual_tours = array();
                    $virtualTourNames = array( '', '2' );

                    foreach( $virtualTourNames as $virtualTourName )
                    {
                        if ( isset( $property['video' . $virtualTourName . 'Url'] ) && !empty( $property['video' . $virtualTourName . 'Url'] ) )
                        {
                            if ( isset( $property['video' . $virtualTourName . 'Caption'] ) && !empty( $property['video' . $virtualTourName . 'Caption'] ) )
                            {
                                $virtual_tour_label = $property['video' . $virtualTourName . 'Caption'];
                            }
                            else
                            {
                                $virtual_tour_label = '';
                            }

                            $virtual_tours[] = array(
                                'url' => $property['video' . $virtualTourName . 'Url'],
                                'label' => $virtual_tour_label,
                            );
                        }
                    }

                    update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                    foreach ($virtual_tours as $i => $virtual_tour)
                    {
                        update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
                        update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
                    }

                    $this->log( 'Successfully imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );

                    // eTag only changes when property data changes, so store it and compare when parsing data to only import updated properties
                    update_post_meta( $post_id, '_reapit_foundations_json_eTag_' . $this->import_id, $property['_eTag'] );

                    do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                    do_action( "propertyhive_property_imported_reapit_foundations_json", $post_id, $property, $this->import_id );

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
        }

        do_action( "propertyhive_post_import_properties_reapit_foundations_json" );

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
                'forSale' => 'forSale',
                'forSaleUnavailable' => 'forSaleUnavailable',
                'underOffer' => 'underOffer',
                'underOfferUnavailable' => 'underOfferUnavailable',
                'reserved' => 'reserved',
                'exchanged' => 'exchanged',
                'completed' => 'completed',
                'soldExternally' => 'soldExternally',
            ),
            'lettings_availability' => array(
                'toLet' => 'toLet',
                'toLetUnavailable' => 'toLetUnavailable',
                'underOffer' => 'underOffer',
                'underOfferUnavailable' => 'underOfferUnavailable',
                'arrangingTenancyUnavailable' => 'arrangingTenancyUnavailable',
                'arrangingTenancy' => 'arrangingTenancy',
                'tenancyCurrentUnavailable' => 'tenancyCurrentUnavailable',
                'tenancyCurrent' => 'tenancyCurrent',
                'tenancyFinished' => 'tenancyFinished',
                'tenancyCancelled' => 'tenancyCancelled',
                'sold' => 'sold',
                'letByOtherAgent' => 'letByOtherAgent',
                'letPrivately' => 'letPrivately',
                'provisional' => 'provisional',
            ),
            'commercial_availability' => array(
                'preAppraisal' => 'preAppraisal',
                'valuation' => 'valuation',
                'forSale' => 'forSale',
                'forSaleUnavailable' => 'forSaleUnavailable',
                'toLet' => 'toLet',
                'toLetUnavailable' => 'toLetUnavailable',
                'underOffer' => 'underOffer',
                'underOfferUnavailable' => 'underOfferUnavailable',
                'arrangingTenancyUnavailable' => 'arrangingTenancyUnavailable',
                'arrangingTenancy' => 'arrangingTenancy',
                'tenancyCurrentUnavailable' => 'tenancyCurrentUnavailable',
                'tenancyCurrent' => 'tenancyCurrent',
                'tenancyFinished' => 'tenancyFinished',
                'tenancyCancelled' => 'tenancyCancelled',
                'sold' => 'sold',
                'letByOtherAgent' => 'letByOtherAgent',
                'letPrivately' => 'letPrivately',
                'provisional' => 'provisional',
                'exchanged' => 'exchanged',
                'completed' => 'completed',
                'soldExternally' => 'soldExternally',
                'withdrawn' => 'withdrawn',
            ),
            'property_type' => array(
                'house' => 'house',
                'house - terraced' => 'house - terraced',
                'house - endTerrace' => 'house - endTerrace',
                'house - detached' => 'house - detached',
                'house - semiDetached' => 'house - semiDetached',
                'house - linkDetached' => 'house - linkDetached',
                'house - mews' => 'house - mews',
                'bungalow' => 'bungalow',
                'bungalow - terraced' => 'bungalow - terraced',
                'bungalow - endTerrace' => 'bungalow - endTerrace',
                'bungalow - detached' => 'bungalow - detached',
                'bungalow - semiDetached' => 'bungalow - semiDetached',
                'bungalow - linkDetached' => 'bungalow - linkDetached',
                'bungalow - mews' => 'bungalow - mews',
                'flatApartment' => 'flatApartment',
                'flatApartment - basement' => 'flatApartment - basement',
                'flatApartment - lowerGroundFloor' => 'flatApartment - lowerGroundFloor',
                'flatApartment - groundFloor' => 'flatApartment - groundFloor',
                'flatApartment - firstFloor' => 'flatApartment - firstFloor',
                'flatApartment - upperFloor' => 'flatApartment - upperFloor',
                'flatApartment - upperFloorWithLift' => 'flatApartment - upperFloorWithLift',
                'flatApartment - penthouse' => 'flatApartment - penthouse',
                'maisonette' => 'maisonette',
                'maisonette - basement' => 'maisonette - basement',
                'maisonette - lowerGroundFloor' => 'maisonette - lowerGroundFloor',
                'maisonette - groundFloor' => 'maisonette - groundFloor',
                'maisonette - firstFloor' => 'maisonette - firstFloor',
                'maisonette - upperFloor' => 'maisonette - upperFloor',
                'maisonette - upperFloorWithLift' => 'maisonette - upperFloorWithLift',
                'maisonette - penthouse' => 'maisonette - penthouse',
                'land' => 'land',
                'farm' => 'farm',
                'cottage' => 'cottage',
                'cottage - terraced' => 'cottage - terraced',
                'cottage - endTerrace' => 'cottage - endTerrace',
                'cottage - detached' => 'cottage - detached',
                'cottage - semiDetached' => 'cottage - semiDetached',
                'cottage - linkDetached' => 'cottage - linkDetached',
                'cottage - mews' => 'cottage - mews',
                'studio' => 'studio',
                'studio - basement' => 'studio - basement',
                'studio - lowerGroundFloor' => 'studio - lowerGroundFloor',
                'studio - groundFloor' => 'studio - groundFloor',
                'studio - firstFloor' => 'studio - firstFloor',
                'studio - upperFloor' => 'studio - upperFloor',
                'studio - upperFloorWithLift' => 'studio - upperFloorWithLift',
                'studio - penthouse' => 'studio - penthouse',
                'townhouse' => 'townhouse',
                'townhouse - terraced' => 'townhouse - terraced',
                'townhouse - endTerrace' => 'townhouse - endTerrace',
                'townhouse - detached' => 'townhouse - detached',
                'townhouse - semiDetached' => 'townhouse - semiDetached',
                'townhouse - linkDetached' => 'townhouse - linkDetached',
                'townhouse - mews' => 'townhouse - mews',
                'developmentPlot' => 'developmentPlot',
                'commercial' => 'commercial',
                'hotel' => 'hotel',
                'industrial' => 'industrial',
                'leisure' => 'leisure',
                'office' => 'office',
                'publicHouse' => 'publicHouse',
                'retail' => 'retail',
                'shop' => 'shop',
                'warehouse' => 'warehouse',
            ),
            'commercial_property_type' => array(
                'commercial' => 'commercial',
                'hotel' => 'hotel',
                'industrial' => 'industrial',
                'leisure' => 'leisure',
                'office' => 'office',
                'publicHouse' => 'publicHouse',
                'retail' => 'retail',
                'shop' => 'shop',
                'warehouse' => 'warehouse',
            ),
            'outside_space' => array(
                'garden' => 'garden',
                'land' => 'land',
                'patio' => 'patio',
                'roofTerrace' => 'roofTerrace',
                'conservatory' => 'conservatory',
                'balcony' => 'balcony',
                'communalGardens' => 'communalGardens',
            ),
            'parking' => array(
                'residents' => 'residents',
                'offStreet' => 'offStreet',
                'secure' => 'secure',
                'underground' => 'underground',
                'garage' => 'garage',
                'doubleGarage' => 'doubleGarage',
                'tripleGarage' => 'tripleGarage',
            ),
            'price_qualifier' => array(
                'askingPrice' => 'askingPrice',
                'priceOnApplication' => 'priceOnApplication',
                'guidePrice' => 'guidePrice',
                'offersInRegion' => 'offersInRegion',
                'offersOver' => 'offersOver',
                'offersInExcess' => 'offersInExcess',
                'fixedPrice' => 'fixedPrice',
                'priceReducedTo' => 'priceReducedTo',
            ),
            'tenure' => array(
                'freehold' => 'freehold',
                'leasehold' => 'leasehold',
                'shareOfFreehold' => 'shareOfFreehold',
                'commonhold' => 'commonhold',
                'tba' => 'tba',
            ),
            'commercial_tenure' => array(
                'freehold' => 'freehold',
                'leasehold' => 'leasehold',
                'shareOfFreehold' => 'shareOfFreehold',
                'commonhold' => 'commonhold',
                'tba' => 'tba',
            ),
            'sale_by' => array(
                'auction' => 'auction',
                'confidential' => 'confidential',
                'tender' => 'tender',
                'offersInvited' => 'offersInvited',
                'privateTreaty' => 'privateTreaty',
            ),
            'furnished' => array(
                'furnished' => 'furnished',
                'unfurnished' => 'unfurnished',
                'partFurnished' => 'partFurnished',
            ),
        );
    }
}

}