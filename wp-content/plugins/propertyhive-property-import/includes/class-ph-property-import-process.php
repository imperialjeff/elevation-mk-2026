<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Process Functions
 */
class PH_Property_Import_Process {

    /**
     * @var int
     */
    public $instance_id;

    /**
     * @var int
     */
    public $import_id;

    /**
     * @var array
     */
    public $properties = array();

    /**
     * @var array
     */
    public $branch_ids_processed = array();

    /**
     * @var int
     */
    public $primary_office_id;

    /**
     * @var array
     */
    public $branch_mappings = array();

    /**
     * @var array
     */
    public $negotiators = array();

    /**
     * @var array
     * Used in testing
     */
    public $errors = array();

	public function __construct()
    {
        add_action( "propertyhive_property_importing", array( $this, 'check_for_kill' ), 10, 3 );

        add_action( 'propertyhive_property_import_cron_begin', array( $this, 'remove_import_id_from_kills' ), 10, 2 );
        add_action( 'propertyhive_property_import_cron_end', array( $this, 'remove_import_id_from_kills' ), 10, 2 );
	}

    public function check_for_kill( $property, $import_id, $instance_id )
    {
        global $wpdb;

        $option_name = 'propertyhive_property_import_kills';
        $option_value = $wpdb->get_var( $wpdb->prepare(
            "SELECT 
                option_value 
            FROM 
                $wpdb->options 
            WHERE 
                option_name = %s 
            LIMIT 1",
            $option_name
        ) );

        $kills = maybe_unserialize( $option_value );

        if ( empty($kills) )
        {
            return;
        }

        $killed = false;

        foreach ( $kills as $kill_import_id => $kill )
        {
            if ( (int)$kill_import_id === (int)$import_id )
            {
                $current_user = wp_get_current_user();

                $this->instance_id = $instance_id;
                $this->import_id = $import_id;

                $this->log("Process killed manually by " . ( ( isset($current_user->display_name) ) ? $current_user->display_name : '' ) );

                $this->import_end();

                $import_settings = propertyhive_property_import_get_import_settings_from_id( (int)$import_id );

                do_action( 'propertyhive_property_import_cron', $import_settings, (int)$instance_id, (int)$import_id );

                // log instance end
                $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                $current_date = $current_date->format("Y-m-d H:i:s");

                $wpdb->update( 
                    $wpdb->prefix . "ph_propertyimport_instance_v3", 
                    array( 
                        'end_date' => $current_date,
                        'status' => json_encode(array('status' => 'finished')),
                        'status_date' => $current_date
                    ),
                    array( 'id' => $instance_id )
                );

                $killed = true;
                unset($kills[$kill_import_id]);
            }
        }

        if ( $killed === true )
        {
            update_option($option_name, $kills, false);

            die();
        }
    }

    public function remove_import_id_from_kills( $instance_id, $import_id )
    {
        global $wpdb;

        $option_name = 'propertyhive_property_import_kills';
        $option_value = $wpdb->get_var( $wpdb->prepare(
            "SELECT 
                option_value 
            FROM 
                $wpdb->options 
            WHERE 
                option_name = %s 
            LIMIT 1",
            $option_name
        ) );

        $kills = maybe_unserialize( $option_value );

        if ( empty($kills) )
        {
            return;
        }

        $new_kills = array();

        foreach ( $kills as $kill_import_id => $kill )
        {
            if ( (int)$kill_import_id !== (int)$import_id )
            {
                $new_kills[$kill_import_id] = $kill;
            }
        }

        update_option($option_name, $new_kills, false);
    }

    public function set_import_data_time( $post_id, $property )
    {
        if ( empty($post_id) ) 
        {
            return;
        }

        update_post_meta( $post_id, '_property_import_data_time', time() );
    }

    public function log( $message, $post_id = 0, $crm_id = '', $received_data = '', $ping = true )
    {
        if ( $this->instance_id != '' )
        {
            global $wpdb;

            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
            $current_date = $current_date->format("Y-m-d H:i:s");

            if ( !is_integer($post_id) ) { $post_id = 0; }

            $data = array(
                'instance_id' => $this->instance_id,
                'post_id' => $post_id,
                'crm_id' => $crm_id,
                'severity' => 0,
                'entry' => $message,
                'log_date' => $current_date
            );

            if ( $received_data != '' )
            {
                // Add received_data to the first log entry
                $data['received_data'] = $received_data;
            }

            $wpdb->insert( 
                $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                $data
            );

            if ( defined( 'WP_CLI' ) && WP_CLI )
            {
                // Log the entire message using WP-CLI
                WP_CLI::log( $current_date . ' - ' . $message . ( !empty($post_id) ? ' (Property ID: ' . $post_id . ')' : '' ) . ( !empty($crm_id) ? ' (CRM ID: ' . $crm_id . ')' : '' ) );
            }

            if ( $ping === true ) { $this->ping(); }
        }
    }

    public function log_error( $message, $post_id = 0, $crm_id = '', $received_data = '' )
    {
        if ( $this->instance_id != '' )
        {
            global $wpdb;

            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
            $current_date = $current_date->format("Y-m-d H:i:s");

            if ( !is_integer($post_id) ) { $post_id = 0; }

            $data = array(
                'instance_id' => $this->instance_id,
                'post_id' => $post_id,
                'crm_id' => $crm_id,
                'severity' => 1,
                'entry' => $message,
                'log_date' => $current_date
            );

            if ( $received_data != '' )
            {
                // Add received_data to the first log entry
                $data['received_data'] = $received_data;
            }

            $wpdb->insert( 
                $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                $data
            );

            if ( defined( 'WP_CLI' ) && WP_CLI )
            {
                // Log the entire message using WP-CLI
                WP_CLI::log( $current_date . ' - ' . $message . ( !empty($post_id) ? ' (Property ID: ' . $post_id . ')' : '' ) . ( !empty($crm_id) ? ' (CRM ID: ' . $crm_id . ')' : '' ) );
            }

            $this->ping();
        }

        $this->errors[] = $message;
    }

    public function ping( $status = array() )
    {
        global $wpdb;

        if ( $this->instance_id != '' )
        {
            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
            $current_date = $current_date->format("Y-m-d H:i:s");

            $data = array( 
                'status_date' => $current_date
            );
            if ( !empty($status) )
            {
                $data['status'] = json_encode($status);
            }

            $wpdb->update( 
                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                $data,
                array( 'id' => $this->instance_id )
            );
        }
    }

    public function get_property_limit()
    {
        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        if ( 
            isset($import_settings['limit']) && 
            !empty((int)$import_settings['limit']) && 
            is_numeric($import_settings['limit'])
        )
        {
            return (int)$import_settings['limit'];
        }

        return false;
    }

    public function insert_update_property_post( $crm_id, $property, $post_title, $post_excerpt, $post_name = '', $post_date = '', $post_parent = '', $update_date_provided = '', $update_date_meta_key = '' )
    {
        $inserted_updated = false;
        $post_id = 0;

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );
        $imported_ref_key = apply_filters( 'propertyhive_property_import_imported_ref_key', $imported_ref_key, $this->import_id );

        if ( empty($post_title) ) { $post_title = ''; }
        if ( empty($post_excerpt) ) { $post_excerpt = ''; }

        if ( 
            function_exists('mb_check_encoding') && 
            function_exists('mb_detect_encoding')  && 
            function_exists('iconv') 
        )
        {
            // Ensure title is in UTF8
            if ( !mb_check_encoding($post_title, 'UTF-8') ) 
            {
                // Try to detect likely legacy encodings
                $enc = mb_detect_encoding($post_title, ['Windows-1252','ISO-8859-1','UTF-8'], true);
                if ($enc === false) 
                {
                    // Fallback: assume Windows-1252 (most common)
                    $enc = 'Windows-1252';
                }

                // Convert to UTF-8 without throwing; drop invalid bytes if any
                $post_title = iconv($enc, 'UTF-8//IGNORE', $post_title);
            }

            // Ensure summary is in UTF8
            if ( !mb_check_encoding($post_excerpt, 'UTF-8') ) 
            {
                // Try to detect likely legacy encodings
                $enc = mb_detect_encoding($post_excerpt, ['Windows-1252','ISO-8859-1','UTF-8'], true);
                if ($enc === false) 
                {
                    // Fallback: assume Windows-1252 (most common)
                    $enc = 'Windows-1252';
                }

                // Convert to UTF-8 without throwing; drop invalid bytes if any
                $post_excerpt = iconv($enc, 'UTF-8//IGNORE', $post_excerpt);
            }
        }

        $args = array(
            'post_type' => 'property',
            'posts_per_page' => 1,
            'post_status' => 'any',
            'suppress_filters' => true,
            'meta_query' => array(
                array(
                    'key' => $imported_ref_key,
                    'value' => $crm_id
                )
            )
        );
        $property_query = new WP_Query($args);
        
        if ( $property_query->have_posts() )
        {
            // We've imported this property before
            while ( $property_query->have_posts() )
            {
                $property_query->the_post();

                $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

                $post_id = get_the_ID();

                $previous_update_date = get_post_meta( $post_id, $update_date_meta_key, TRUE);

                $skip_property = false;
                if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
                {
                    if (
                        !empty($update_date_provided) &&
                        !empty($previous_update_date) &&
                        strtotime($update_date_provided) <= strtotime($previous_update_date)
                    )
                    {
                        $skip_property = true;
                    }
                }

                if ( $skip_property === false )
                {
                    $this->log( 'This property has been imported before. Updating it', $post_id, $crm_id );

                    $postdata = array(
                        'ID'             => $post_id,
                        'post_title'     => wp_strip_all_tags( $post_title ),
                        'post_excerpt'   => $post_excerpt,
                        'post_status'    => 'publish',
                    );

                    if ( !empty($post_parent) )
                    {
                        $postdata['post_parent'] = $post_parent;
                    }

                    $postdata = apply_filters( 'propertyhive_property_import_post_data', $postdata, $this->import_id, $property, $crm_id );

                    // Update the post into the database
                    $post_id = wp_update_post( $postdata, true );

                    if ( is_wp_error( $post_id ) ) 
                    {
                        $this->log_error( 'ERROR: Failed to update post. The error was as follows: ' . $post_id->get_error_message(), 0, $crm_id );
                    }
                    else
                    {
                        $inserted_updated = 'updated';
                    }
                }
                else
                {
                    $inserted_updated = 'updated';
                }
            }
        }
        else
        {
            $this->log( 'This property hasn\'t been imported before. Inserting it', 0, $crm_id );

            // We've not imported this property before
            $postdata = array(
                'post_title'     => wp_strip_all_tags( $post_title ),
                'post_excerpt'   => $post_excerpt,
                'post_content'   => '',
                'post_status'    => 'publish',
                'post_type'      => 'property',
                'comment_status' => 'closed',
            );

            if ( !empty($post_name) )
            {
                $postdata['post_name'] = sanitize_title($post_name);
            }

            if ( !empty($post_date) )
            {
                $postdata['post_date'] = $post_date;
            }

            if ( !empty($post_parent) )
            {
                $postdata['post_parent'] = $post_parent;
            }

            $postdata = apply_filters( 'propertyhive_property_import_post_data', $postdata, $this->import_id, $property, $crm_id );

            $post_id = wp_insert_post( $postdata, true );

            if ( is_wp_error( $post_id ) ) 
            {
                $this->log_error( 'Failed to insert post. The error was as follows: ' . $post_id->get_error_message(), 0, $crm_id );
            }
            else
            {
                $inserted_updated = 'inserted';
            }
        }
        $property_query->reset_postdata();

        return array($inserted_updated, $post_id);
    }

    public function compare_meta_and_taxonomy_data( $post_id, $crm_id, $metadata_before = array(), $taxonomy_terms_before = array() )
    {
        $metadata_after = get_metadata('post', $post_id, '', true);

        foreach ( $metadata_after as $key => $value)
        {
            if ( in_array($key, array('_photos', '_photo_urls', '_floorplans', '_floorplan_urls', '_brochures', '_brochure_urls', '_epcs', '_epc_urls', '_virtual_tours', '_property_import_data', '_property_import_data_time', '_view_statistics')) )
            {
                continue;
            }

            if ( !isset($metadata_before[$key]) )
            {
                $this->log( 'New meta data for ' . trim($key, '_') . ': ' . ( ( is_array($value) ) ? implode(", ", $value) : $value ), $post_id, $crm_id );
            }
            elseif ( $metadata_before[$key] != $metadata_after[$key] )
            {
                $this->log( 'Updated ' . trim($key, '_') . '. Before: ' . ( ( is_array($metadata_before[$key]) ) ? implode(", ", $metadata_before[$key]) : $metadata_before[$key] ) . ', After: ' . ( ( is_array($value) ) ? implode(", ", $value) : $value ), $post_id, $crm_id );
            }
        }

        $taxonomy_terms_after = array();
        $taxonomy_names = get_post_taxonomies( $post_id );
        foreach ( $taxonomy_names as $taxonomy_name )
        {
            $taxonomy_terms_after[$taxonomy_name] = wp_get_post_terms( $post_id, $taxonomy_name, array('fields' => 'ids') );
        }

        foreach ( $taxonomy_terms_after as $taxonomy_name => $ids)
        {
            if ( !isset($taxonomy_terms_before[$taxonomy_name]) )
            {
                $this->log( 'New taxonomy data for ' . $taxonomy_name . ': ' . ( ( is_array($ids) ) ? implode(", ", $ids) : $ids ), $post_id, $crm_id );
            }
            elseif ( $taxonomy_terms_before[$taxonomy_name] != $taxonomy_terms_after[$taxonomy_name] )
            {
                $this->log( 'Updated ' . $taxonomy_name . '. Before: ' . ( ( is_array($taxonomy_terms_before[$taxonomy_name]) ) ? implode(", ", $taxonomy_terms_before[$taxonomy_name]) : $taxonomy_terms_before[$taxonomy_name] ) . ', After: ' . ( ( is_array($ids) ) ? implode(", ", $ids) : $ids ), $post_id, $crm_id );
            }
        }
    }

    public function import_start()
    {
        $this->log( 'Starting import' );

        wp_suspend_cache_invalidation( true );

        wp_defer_term_counting( true );
        wp_defer_comment_counting( true );

        if ( !function_exists('media_handle_upload') ) 
        {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }

        $this->get_primary_office_id();

        $this->get_negotiators();

        $this->get_property_portal_branch_mappings();
    }

    public function import_end()
    {
        update_option( 'propertyhive_property_import_property_' . $this->import_id, '', false );

        $this->log( 'Finished import' );

        if ( apply_filters( 'propertyhive_property_import_flush_cache_at_end', true ) === true ) 
        {
            wp_cache_flush(); 
        }

        wp_suspend_cache_invalidation( false );

        wp_defer_term_counting( false );
        wp_defer_comment_counting( false );
    }

    public function download_url( $url, $timeout = 300 ) 
    {
        if ( function_exists('download_url') )
        {
            // This is normal and is a self-hosted WP site. If it's a WP.com site it won't come into this function
            return download_url($url);
        }

        // WARNING: The file is not automatically deleted, the script must delete or move the file.
        if ( ! $url ) {
            return new WP_Error( 'http_no_url', __( 'No URL Provided.' ) );
        }

        // Generate a temporary filename using tempnam()
        $tmpfname = tempnam(sys_get_temp_dir(), 'wp-download-');
        if ( ! $tmpfname ) {
            return new WP_Error( 'http_no_file', __( 'Could not create a temporary file.' ) );
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'  => $timeout,
                'stream'   => true,
                'filename' => $tmpfname,
            )
        );

        if ( is_wp_error( $response ) ) {
            unlink( $tmpfname );
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        if ( 200 !== $response_code ) {
            $data = array(
                'code' => $response_code,
            );

            // Retrieve a sample of the response body for debugging purposes.
            $tmpf = fopen( $tmpfname, 'rb' );

            if ( $tmpf ) {
                /**
                 * Filters the maximum error response body size in `download_url()`.
                 *
                 * @since 5.1.0
                 *
                 * @see download_url()
                 *
                 * @param int $size The maximum error response body size. Default 1 KB.
                 */
                $response_size = apply_filters( 'download_url_error_max_body_size', KB_IN_BYTES );

                $data['body'] = fread( $tmpf, $response_size );
                fclose( $tmpf );
            }

            unlink( $tmpfname );

            return new WP_Error( 'http_404', trim( wp_remote_retrieve_response_message( $response ) ), $data );
        }

        $content_disposition = wp_remote_retrieve_header( $response, 'Content-Disposition' );

        if ( $content_disposition ) {
            $content_disposition = strtolower( $content_disposition );

            if ( str_starts_with( $content_disposition, 'attachment; filename=' ) ) {
                $tmpfname_disposition = sanitize_file_name( substr( $content_disposition, 21 ) );
            } else {
                $tmpfname_disposition = '';
            }

            // Potential file name must be valid string.
            if ( $tmpfname_disposition && is_string( $tmpfname_disposition )
                && ( 0 === validate_file( $tmpfname_disposition ) )
            ) {
                $tmpfname_disposition = dirname( $tmpfname ) . '/' . $tmpfname_disposition;

                if ( rename( $tmpfname, $tmpfname_disposition ) ) {
                    $tmpfname = $tmpfname_disposition;
                }

                if ( ( $tmpfname !== $tmpfname_disposition ) && file_exists( $tmpfname_disposition ) ) {
                    unlink( $tmpfname_disposition );
                }
            }
        }

        $content_md5 = wp_remote_retrieve_header( $response, 'Content-MD5' );

        if ( $content_md5 ) {
            $md5_check = verify_file_md5( $tmpfname, $content_md5 );

            if ( is_wp_error( $md5_check ) ) {
                unlink( $tmpfname );
                return $md5_check;
            }
        }

        return $tmpfname;
    }

    public function do_geocoding_lookup( $post_id, $agent_ref, $address, $address_osm, $country = '' )
    {
        if ( empty($country) )
        {
            $country = get_option( 'propertyhive_default_country', 'GB' );
        }

        if ( get_option('propertyhive_geocoding_provider') == 'osm' )
        {
            if ( empty($address_osm) )
            {
                $address_osm = $address;
            }

            $this->log( 'Performing geocoding request for ' . implode( ", ", $address_osm ), $post_id, $agent_ref );

            $request_url = "https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=" . strtolower($country) . "&addressdetails=1&q=" . urlencode(implode(", ", $address_osm));

            $response = wp_remote_get(
                $request_url,
                array(
                    'headers' => array(
                        'Referer' => home_url(),
                        'User-Agent' => 'Property-Hive/' . PH_VERSION . ' (+https://wp-property-hive.com)',
                    ),
                )
            );

            if ( is_wp_error( $response ))
            {
                $this->log_error( 'Error returned from geocoding service: ' . $response->get_error_message(), $post_id, $agent_ref );
                sleep(2); // Sleep due to throttling limits
                return false;
            }

            if ( wp_remote_retrieve_response_code($response) !== 200 )
            {
                $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when geocoding address ' . implode(", ", $address) . '. Error message: ' . wp_remote_retrieve_response_message($response), $post_id, $agent_ref );
                sleep(2); // Sleep due to throttling limits
                return false;
            }

            if ( is_array( $response ) )
            {
                $body = wp_remote_retrieve_body( $response );
                $json = json_decode($body, true);

                if ( !empty($json) && isset($json[0]['lat']) && isset($json[0]['lon']) )
                {
                    $lat = $json[0]['lat'];
                    $lng = $json[0]['lon'];

                    if ($lat != '' && $lng != '')
                    {
                        update_post_meta( $post_id, '_latitude', $lat );
                        update_post_meta( $post_id, '_longitude', $lng );

                        sleep(2);

                        return true;
                    }
                }
                else
                {
                    $this->log_error( 'No co-ordinates returned for the address provided: ' . implode( ", ", $address_osm ), $post_id, $agent_ref );
                }
            }
            else
            {
                $this->log_error( 'Failed to parse JSON response from OSM Geocoding service.', $post_id, $agent_ref );
            }

            sleep(2); // Sleep due to nominatim throttling limits
        }
        else
        {
            $api_key = get_option('propertyhive_google_maps_geocoding_api_key', '');
            if ( $api_key == '' )
            {
                $api_key = get_option('propertyhive_google_maps_api_key', '');
            }
            if ( $api_key != '' )
            {
                if ( ini_get('allow_url_fopen') )
                {
                    $this->log( 'Performing geocoding request for ' . implode( ", ", $address ), $post_id, $agent_ref );

                    $request_url = "https://maps.googleapis.com/maps/api/geocode/xml?address=" . urlencode( implode( ", ", $address ) ) . "&sensor=false&region=" . strtolower($country); // the request URL you'll send to google to get back your XML feed
                    
                    if ( $api_key != '' ) { $request_url .= "&key=" . $api_key; }

                    $response = wp_remote_get($request_url);

                    if ( is_array( $response ) && !is_wp_error( $response ) ) 
                    {
                        $header = $response['headers']; // array of http header lines
                        $body = $response['body']; // use the content

                        $xml = simplexml_load_string($body);

                        if ( $xml !== FALSE )
                        {
                            $status = $xml->status; // Get the request status as google's api can return several responses

                            if ($status == "OK") 
                            {
                                //request returned completed time to get lat / lng for storage
                                $lat = (string)$xml->result->geometry->location->lat;
                                $lng = (string)$xml->result->geometry->location->lng;
                                
                                if ($lat != '' && $lng != '')
                                {
                                    update_post_meta( $post_id, '_latitude', $lat );
                                    update_post_meta( $post_id, '_longitude', $lng );

                                    return true;
                                }
                            }
                            else
                            {
                                $log = 'Google Geocoding service returned status ' . $status;

                                switch ( $status )
                                {
                                    case "ZERO_RESULTS":
                                    {
                                        $log .= ' - No results found for the given address.';
                                        break;
                                    }
                                    case "OVER_DAILY_LIMIT":
                                    {
                                        $log .= ' - Over daily quota limit. Possible issues: billing not enabled, quota exceeded, or invalid API key.';
                                        break;
                                    }
                                    case "OVER_QUERY_LIMIT":
                                    {
                                        $log .= ' - Too many requests sent in a short time. Rate limit exceeded.';
                                        break;
                                    }
                                    case "REQUEST_DENIED":
                                    {
                                        $log .= ' - Request was denied. Possible causes: invalid API key, key restrictions, or API not enabled.';
                                        break;
                                    }
                                    case "INVALID_REQUEST":
                                    {
                                        $log .= ' - Request is missing required parameters such as address or latlng.';
                                        break;
                                    }
                                }

                                $this->log_error( $log, $post_id, $agent_ref );
                                sleep(3);

                                if ( $status == "REQUEST_DENIED" )
                                {
                                    return 'denied';
                                }
                            }
                        }
                        else
                        {
                            $this->log_error( 'Failed to parse XML response from Google Geocoding service', $post_id, $agent_ref );
                        }
                    }
                    else
                    {
                        $this->log_error( 'Invalid response when trying to obtain co-ordinates', $post_id, $agent_ref );
                    }
                }
                else
                {
                    $this->log_error( 'Failed to obtain co-ordinates as allow_url_fopen setting is disabled', $post_id, $agent_ref );
                }
            }
            else
            {
                $this->log( 'Not performing Google Geocoding request as no API key present in settings', $post_id, $agent_ref );
            }
        }

        return false;
    }

    public function do_remove_old_properties( $import_refs = array() )
    {
        global $wpdb, $post;

        $remove_action = get_option( 'propertyhive_property_import_remove_action', '' );

        if ( $remove_action == 'nothing' )
        {
            return false;
        }

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
                return false;
            }
        }

        if ( empty($import_refs) && apply_filters( 'propertyhive_property_import_stop_if_no_properties', true, $this->import_id ) === true )
        {
            return false;
        }

        $this->ping(array('status' => 'removing'));

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );
        $imported_ref_key = apply_filters( 'propertyhive_property_import_imported_ref_key', $imported_ref_key, $this->import_id );

        // Get all properties that don't have an _imported_ref matching the properties in $this->properties

        $args = array(
            'post_type' => 'property',
            'nopaging' => true,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key'     => '_on_market',
                    'value'   => 'yes',
                ),                
            ),
        );

        $meta_query = array();

        if ( apply_filters('propertyhive_property_import_remove_properties_in_php', false) === false )
        {
            $args['meta_query'][] = array(
                'key'     => $imported_ref_key,
                'value'   => $import_refs,
                'compare' => 'NOT IN',
            );
        }
        else
        {
            $args['meta_query'][] = array(
                'key'     => $imported_ref_key,
                'compare' => 'EXISTS'
            );
        }

        // Is the property portal add on activated and branch_ids have been processed
        if ( class_exists('PH_Property_Portal') && !empty($this->branch_ids_processed) )
        {
            $args['meta_query'][] = array(
                'key'     => '_branch_id',
                'value'   => $this->branch_ids_processed,
                'compare' => 'IN',
            );
        }

        $args = apply_filters( 'propertyhive_property_import_remove_old_properties_query_args', $args, $this->import_id );

        $property_query = new WP_Query( $args );

        if ( $property_query->have_posts() )
        {
            $remove_action = get_option( 'propertyhive_property_import_remove_action', '' );
            $this->log( 'Removing' . ( apply_filters('propertyhive_property_import_remove_properties_in_php', false) === false ? ' ' . $property_query->found_posts : '' ) . ' propert' . ( $property_query->found_posts != 1 ? 'ies' : 'y' ) . ( !empty($remove_action) ? ' with remove option: ' . $remove_action : '' ) );

            while ( $property_query->have_posts() )
            {
                $property_query->the_post();

                $property_post_id = get_the_ID();

                $agent_ref = get_post_meta($property_post_id, $imported_ref_key, TRUE);

                if ( !in_array($agent_ref, $import_refs) )
                {
                    $this->remove_property( '', $property_post_id );
                }
            }
        }
        wp_reset_postdata();
    }

    public function remove_property( $import_ref = '', $property_post_id = '' )
    {
        global $wpdb;

        $remove_action = get_option( 'propertyhive_property_import_remove_action', '' );

        if ( $remove_action == 'nothing' )
        {
            return false;
        }

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );
        $imported_ref_key = apply_filters( 'propertyhive_property_import_imported_ref_key', $imported_ref_key, $this->import_id );

        if ( !empty($import_ref) && empty($property_post_id) )
        {
            // get post ID from import ref
            $args = array(
                'post_type' => 'property',
                'post_status' => 'publish',
                'nopaging' => true,
                'fields' => 'ids',
                'meta_query' => array(
                    array(
                        'key'     => $imported_ref_key,
                        'value'   => $import_ref,
                    ),
                ),
            );
            $property_query = new WP_Query( $args );

            if ( $property_query->have_posts() )
            {
                while ( $property_query->have_posts() )
                {
                    $property_query->the_post();

                    $property_post_id = get_the_ID();
                }
            }
            else
            {
                $this->log_error( 'No post ID found for import ref ' . $imported_ref_key  . ' to remove: ' . $import_ref );
                return;
            }
            wp_reset_postdata();
        }

        if ( empty($property_post_id) ) 
        {
            $this->log_error('Property post ID is empty for ' . $imported_ref_key  . ' ' . $import_ref);
            return;
        }

        update_post_meta( $property_post_id, '_on_market', '' );

        $this->log( 'Property removed', $property_post_id, get_post_meta($property_post_id, $imported_ref_key, TRUE) );

        do_action( "save_post_property", $property_post_id, get_post($property_post_id), false );
        do_action( "save_post", $property_post_id, get_post($property_post_id), false );

        if ( $remove_action != '' )
        {
            if ( $remove_action == 'remove_all_media' || $remove_action == 'remove_all_media_except_first_image' )
            {
                // Remove all EPCs
                $this->delete_media( $property_post_id, '_epcs' );

                // Remove all Brochures
                $this->delete_media( $property_post_id, '_brochures' );

                // Remove all Floorplans
                $this->delete_media( $property_post_id, '_floorplans' );

                // Remove all Images (except maybe the first)
                $this->delete_media( $property_post_id, '_photos', ( ( $remove_action == 'remove_all_media_except_first_image' ) ? TRUE : FALSE ) );

                $this->log( 'Deleted property media', $property_post_id, get_post_meta($property_post_id, $imported_ref_key, TRUE) );
            }
            elseif ( $remove_action == 'draft_property' )
            {
                $my_post = array(
                    'ID'             => $property_post_id,
                    'post_status'    => 'draft',
                );

                // Update the post into the database
                $post_id = wp_update_post( $my_post, true );

                if ( is_wp_error( $post_id ) ) 
                {
                    $this->log_error( 'Failed to set post as draft. The error was as follows: ' . $post_id->get_error_message(), $property_post_id, get_post_meta($property_post_id, $imported_ref_key, TRUE) );
                }
                else
                {
                    $this->log( 'Drafted property', $property_post_id, get_post_meta($property_post_id, $imported_ref_key, TRUE) );
                }
            }
            elseif ( $remove_action == 'remove_property' )
            {
                wp_delete_post( $property_post_id, true );
                $this->log( 'Deleted property', $property_post_id, get_post_meta($property_post_id, $imported_ref_key, TRUE) );
            }
        }

        do_action( "propertyhive_property_import_property_removed", $property_post_id, $this->import_id );

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ph_propertyimport_media_queue WHERE `property_id` = %d",
            $property_post_id
        ));
    }

    public function delete_media( $post_id, $meta_key, $except_first = false )
    {
        $media_ids = get_post_meta( $post_id, $meta_key, TRUE );
        if ( !empty( $media_ids ) )
        {
            $i = 0;
            foreach ( $media_ids as $media_id )
            {
                if ( !$except_first || ( $except_first && $i > 0 ) )
                {
                    if ( wp_delete_attachment( $media_id, TRUE ) !== FALSE )
                    {
                        // Deleted succesfully. Now remove from array
                        if( ($key = array_search($media_id, $media_ids)) !== false)
                        {
                            unset($media_ids[$key]);
                        }
                    }
                    else
                    {
                        $this->log_error( 'Failed to delete ' . $meta_key . ' with attachment ID ' . $media_id, $post_id, get_post_meta($post_id, $imported_ref_key, TRUE) );
                    }
                }
                ++$i;
            }
        }
        update_post_meta( $post_id, $meta_key, $media_ids );
    }

    public function add_missing_mapping( $mappings, $custom_field, $value, $post_id = 0 )
    {
        wp_cache_delete( 'propertyhive_property_import', 'options' );
        $options = get_option( 'propertyhive_property_import' );

        if ( ph_clean($value) != '' && !isset($mappings[$custom_field][$value]) )
        {
            $mappings[$custom_field][$value] = '';

            if ( $this->import_id != '' && isset($options[$this->import_id]) )
            {
                $options[$this->import_id]['mappings'][$custom_field][$value] = '';

                update_option( 'propertyhive_property_import', $options );

                $crm_id = '';
                if ( !empty($post_id) )
                {
                    $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );
                    
                    $crm_id = get_post_meta($post_id, $imported_ref_key, TRUE);
                }

                $this->log( 'Added new option (' . ph_clean($value) . ') to ' . $custom_field . ' mappings that you will need to assign', $post_id, $crm_id );
            }
        }

        if ( $this->import_id != '' && isset($options[$this->import_id]) )
        {
            return $options[$this->import_id];
        }

        return array();
    }

    // $media_type (photo, floorplan, brochure, epc)
    // $media array( array('url' => '', 'compare_url' => '', 'filename' => '', 'description' => '', 'modified' => '', 'post_title' => '', 'local' => '', 'local_directory' => '') )
    // $force_download (bool) - Only used in CSV and XML imports where user can opt to download media every time an import runs
    // $epc_data (array container eec, eep, eic and eip keys) - Used when EPC chart should be generated from values
    public function import_media( $post_id, $crm_id, $media_type = 'photo', $media = array(), $use_modified = false, $force_download = false, $epc_data = array() )
    {
        do_action( "propertyhive_property_media_importing", $post_id, $crm_id, $this->import_id, $this->instance_id, $media_type, $media, $use_modified, $force_download, $epc_data,  );

        $files_to_unlink  = array();

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $media_processing = get_option( 'propertyhive_property_import_media_processing', '' );

        $media_stored_as_option_name = 'propertyhive_' . $media_type . 's_stored_as';
        if ( $media_type == 'photo' ) { $media_stored_as_option_name = 'propertyhive_images_stored_as'; }

        if ( get_option($media_stored_as_option_name, '') == 'urls' )
        {
            $media_urls = array();

            foreach ( $media as $media_item )
            {
                if ( 
                    substr( strtolower($media_item['url']), 0, 2 ) != '//' &&
                    substr( strtolower($media_item['url']), 0, 4 ) != 'http'
                )
                {
                    continue;
                }

                if ( isset($media_item['local']) && $media_item['local'] === true )
                {
                    $this->log_error( 'Attempting to import local file, but media settings specify ' . $media_type . 's should be stored as URLs', $post_id, $crm_id );
                    continue;
                }

                if ( 
                    isset($import_settings['limit_images']) && 
                    !empty((int)$import_settings['limit_images']) && 
                    is_numeric($import_settings['limit_images']) &&
                    count($media_urls) >= $import_settings['limit_images']
                )
                {
                    break;
                }
                
                $media_urls[] = array('url' => $media_item['url']);
            }

            update_post_meta( $post_id, '_' . $media_type . '_urls', $media_urls );

            $this->log( 'Imported ' . count($media_urls) . ' ' . $media_type . ' URLs', $post_id, $crm_id );
        }
        else
        {
            $media_ids = array();
            $new = 0;
            $existing = 0;
            $deleted = 0;
            $queued = 0;
            $media_count = 0;
            $previous_media_ids = get_post_meta( $post_id, '_' . $media_type . 's', TRUE );

            $start_at_media_i = false;
            $previous_import_media_ids = get_option( 'propertyhive_property_' . $media_type . '_media_ids_' . $this->import_id );

            if ( !empty($previous_import_media_ids) )
            {
                // an import stopped previously whilst doing this media type. Check if it was this post
                $explode_previous_media_ids = explode("|", $previous_import_media_ids);
                if ( $explode_previous_media_ids[0] == $post_id )
                {
                    // yes it was this property. now loop through the media already imported to ensure it's not imported again
                    if ( isset($explode_previous_media_ids[1]) && !empty($explode_previous_media_ids[1]) )
                    {
                        $media_ids = explode(",", $explode_previous_media_ids[1]);
                        $start_at_media_i = count($media_ids);

                        $this->log( 'Imported ' . count($media_ids) . ' ' . $media_type . 's before failing in the previous import. Continuing from here', $post_id, $crm_id );
                    }
                }
            }

            foreach ( $media as $i => $media_item )
            {
                if ( $start_at_media_i !== false )
                {
                    // we need to start at a specific media item
                    if ( $media_count < $start_at_media_i )
                    {
                        ++$existing;
                        ++$media_count;
                        continue;
                    }
                }

                if ( !isset($media_item['local']) || $media_item['local'] === false )
                {
                    // remote URL to be downloaded

                    if (
                        substr( strtolower($media_item['url']), 0, 2 ) != '//' &&
                        substr( strtolower($media_item['url']), 0, 4 ) != 'http'
                    )
                    {
                        continue;
                    }

                    // Check EPC doesn't link to a website
                    if ( $media_type == 'epc' )
                    {
                        $import_epc = true;

                        if ( apply_filters( 'propertyhive_property_import_validate_epc_links_to_file', true, $this->import_id ) === true )
                        {
                            // Check the file is not a website
                            $stream_options = [
                                'http' => ['method' => 'HEAD']
                            ];

                            $stream_options = apply_filters( 'propertyhive_property_import_epc_stream_options', $stream_options, $this->import_id );

                            $context = stream_context_create($stream_options);

                            // Try a HEAD request first
                            $headers = @get_headers($media_item['url'], 1, $context);

                            // Fallback to GET request if HEAD fails
                            if ( !$headers )
                            {
                                $headers = @get_headers($media_item['url'], 1);
                            }

                            if ( isset($headers['Content-Type']) )
                            {
                                $content_type = $headers['Content-Type'];

                                // Check if Content-Type is an array or string
                                if ( is_array($content_type) ) 
                                {
                                    // Loop through each Content-Type value
                                    foreach ( $content_type as $type ) 
                                    {
                                        if ( strpos($type, 'text/html') !== false ) 
                                        {
                                            // Yes, website
                                            $import_epc = false;
                                            break; // Exit loop if found
                                        }
                                    }
                                }
                                else 
                                {
                                    // Single Content-Type value
                                    if ( strpos($content_type, 'text/html') !== false ) 
                                    {
                                        // Yes, website
                                        $import_epc = false;
                                    }
                                }
                            }
                        }

                        if ( $import_epc === false )
                        {
                            continue;
                        }
                    }
                    
                    if ( 
                        isset($import_settings['limit_images']) && 
                        !empty((int)$import_settings['limit_images']) && 
                        is_numeric($import_settings['limit_images']) &&
                        (count($media_ids) + $queued) >= $import_settings['limit_images']
                    )
                    {
                        break;
                    }

                    $url = $media_item['url'];

                    $compare_url = ( isset($media_item['compare_url']) && !empty($media_item['compare_url']) ) ? $media_item['compare_url'] : $url;

                    $description = ( isset($media_item['description']) && !empty($media_item['description']) ) ? $media_item['description'] : '';

                    $modified = ( $use_modified === true && isset($media_item['modified']) && !empty($media_item['modified']) ) ? $media_item['modified'] : '';
                        
                    $filename = ( isset($media_item['filename']) && !empty($media_item['filename']) ) ? $media_item['filename'] : basename( $url );

                    $post_title = ( isset($media_item['post_title']) && !empty($media_item['post_title']) ) ? $media_item['post_title'] : $filename;

                    // Check, based on the URL, whether we have previously imported this media
                    $imported_previously = false;
                    $imported_previously_id = '';
                    if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
                    {
                        foreach ( $previous_media_ids as $previous_media_id )
                        {
                            if ( 
                                $force_download === false
                                &&
                                get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $compare_url 
                                &&
                                (
                                    $use_modified === false
                                    ||
                                    (
                                        $use_modified === true
                                        &&
                                        (
                                            get_post_meta( $previous_media_id, '_modified', TRUE ) == '' 
                                            ||
                                            (
                                                get_post_meta( $previous_media_id, '_modified', TRUE ) != '' &&
                                                get_post_meta( $previous_media_id, '_modified', TRUE ) == $modified
                                            )
                                        )
                                    )
                                )
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

                        if ( $description != '' )
                        {
                            $my_post = array(
                                'ID'             => $imported_previously_id,
                                'post_title'     => $description,
                            );

                            // Update the post into the database
                            wp_update_post( $my_post );
                        }

                        ++$existing;

                        update_option( 'propertyhive_property_' . $media_type . '_media_ids_' . $this->import_id, $post_id . '|' . implode(",", $media_ids), false );
                    }
                    else
                    {
                        if ( $media_processing == '' ) 
                        {
                            $tmp = download_url( $url );
                            $file_array = array(
                                'name' => $filename,
                                'tmp_name' => $tmp
                            );

                            // Check for download errors
                            if ( is_wp_error( $tmp ) ) 
                            {
                                $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), $post_id, $crm_id );
                            }
                            else
                            {
                                $id = media_handle_sideload( $file_array, $post_id, $description, array(
                                    'post_title' => $post_title,
                                    'post_excerpt' => $description
                                ) );

                                // Check for handle sideload errors.
                                if ( is_wp_error( $id ) ) 
                                {
                                    @unlink( $file_array['tmp_name'] );
                                    
                                    $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), $post_id, $crm_id );
                                }
                                else
                                {
                                    $media_ids[] = $id;

                                    update_post_meta( $id, '_imported_url', $compare_url);
                                    if ( $modified !== '' ) 
                                    {
                                        update_post_meta( $id, '_modified', $modified );
                                    }

                                    ++$new;

                                    update_option( 'propertyhive_property_' . $media_type . '_media_ids_' . $this->import_id, $post_id . '|' . implode(",", $media_ids), false );
                                }
                            }
                        }
                        else
                        {
                            $this->add_media_to_download_queue($post_id, $url, $media_type . 's', $media_count, $description, $compare_url);
                            ++$queued;
                        }
                    }
                }
                else
                {
                    // local file

                    $media_file_name = $media_item['url'];

                    $description = ( isset($media_item['description']) && !empty($media_item['description']) ) ? $media_item['description'] : '';
                    
                    if ( file_exists( $media_item['local_directory'] . '/' . $media_file_name ) )
                    {
                        $upload = true;
                        $replacing_attachment_id = '';
                        if ( isset($previous_media_ids[$i]) ) 
                        {
                            // get this attachment
                            $current_image_path = get_post_meta( $previous_media_ids[$i], '_imported_path', TRUE );
                            $current_image_size = filesize( $current_image_path );
                            
                            if ($current_image_size > 0 && $current_image_size !== FALSE)
                            {
                                $replacing_attachment_id = $previous_media_ids[$i];
                                
                                $new_image_size = filesize( $media_item['local_directory'] . '/' . $media_file_name );
                                
                                if ($new_image_size > 0 && $new_image_size !== FALSE)
                                {
                                    if ($current_image_size == $new_image_size)
                                    {
                                        $upload = false;
                                    }
                                    else
                                    {
                                        
                                    }
                                }
                                else
                                {
                                    $this->log_error( 'Failed to get filesize of new image file ' . $media_item['local_directory'] . '/' . $media_file_name, $post_id, $crm_id );
                                }
                                
                                unset($new_image_size);
                            }
                            else
                            {
                                $this->log_error( 'Failed to get filesize of existing image file ' . $current_image_path, $post_id, $crm_id );
                            }
                            
                            unset($current_image_size);
                        }

                        if ($upload)
                        {
                            $description = ( $description != '' ) ? $description : preg_replace('/\.[^.]+$/', '', trim($media_file_name, '_'));

                            if ( $media_processing == '' ) 
                            {
                                // We've physically received the file
                                $upload = wp_upload_bits(trim($media_file_name, '_'), null, file_get_contents($media_item['local_directory'] . '/' . $media_file_name));  
                                
                                if ( isset($upload['error']) && $upload['error'] !== FALSE )
                                {
                                    $this->log_error( print_r($upload['error'], TRUE), $post_id, $crm_id );
                                }
                                else
                                {
                                    // We don't already have a thumbnail and we're presented with a media file
                                    $wp_filetype = wp_check_filetype( $upload['file'], null );
                                
                                    $attachment = array(
                                        'post_mime_type' => $wp_filetype['type'],
                                        'post_title' => $description,
                                        'post_content' => '',
                                        'post_status' => 'inherit'
                                    );
                                    $attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
                                    
                                    if ( $attach_id === FALSE || $attach_id == 0 )
                                    {    
                                        $this->log_error( 'Failed inserting ' . $media_type . ' attachment ' . $upload['file'] . ' - ' . print_r($attachment, TRUE), $post_id, $crm_id );
                                    }
                                    else
                                    {  
                                        $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                                        wp_update_attachment_metadata( $attach_id,  $attach_data );

                                        update_post_meta( $attach_id, '_imported_path', $upload['file']);

                                        $media_ids[] = $attach_id;

                                        ++$new;
                                    }
                                }

                                $files_to_unlink[] = $media_item['local_directory'] . '/' . $media_file_name;
                            } else {
                                $file_data = array(
                                    'name' => trim($media_file_name, '_'),
                                    'path' => $media_item['local_directory'] . '/' . $media_file_name
                                );
                                $this->add_media_to_download_queue($post_id, serialize($file_data), $media_type . 's', $media_count, $description);
                                ++$queued;
                            }
                        }
                        else
                        {
                            if ( isset($previous_media_ids[$i]) ) 
                            {
                                $media_ids[] = $previous_media_ids[$i];

                                if ( $description != '' )
                                {
                                    $my_post = array(
                                        'ID'             => $previous_media_ids[$i],
                                        'post_title'     => $description,
                                    );

                                    // Update the post into the database
                                    wp_update_post( $my_post );
                                }

                                ++$existing;
                            }

                            $files_to_unlink[] = $media_item['local_directory'] . '/' . $media_file_name;
                        }
                    }
                    else
                    {
                        if ( isset($previous_media_ids[$i]) ) 
                        {
                            $media_ids[] = $previous_media_ids[$i];

                            if ( $description != '' )
                            {
                                $my_post = array(
                                    'ID'             => $previous_media_ids[$i],
                                    'post_title'     => $description,
                                );

                                // Update the post into the database
                                wp_update_post( $my_post );
                            }

                            ++$existing;
                        }
                    }
                }

                ++$media_count;
            }

            // Generate EPC if no existing EPCs and EPC data passed
            if ( $media_count == 0 && $media_type == 'epc' && !empty($epc_data) )
            {
                $epc_type = '';
                if ( isset($epc_data['eec']) && $epc_data['eec'] != '' && isset($epc_data['eep']) && $epc_data['eep'] != '' )
                {
                    if ( isset($epc_data['eic']) && $epc_data['eic'] != '' && isset($epc_data['eip']) && $epc_data['eip'] != '' )
                    {
                        $background = dirname(__FILE__) . '/../assets/images/background.png';
                        $epc_type = 'eer_eir';
                    }
                    else
                    {
                        $background = dirname(__FILE__) . '/../assets/images/background_eer.png';
                        $epc_type = 'eer_only';
                    }
                }
                elseif ( isset($epc_data['eic']) && $epc_data['eic'] != '' && isset($epc_data['eip']) && $epc_data['eip'] != '' )
                {
                    $background = dirname(__FILE__) . '/../assets/images/background_eir.png';
                    $epc_type = 'eir_only';
                }

                // see if the ratings differ
                if (
                    (isset($epc_data['eec']) && $epc_data['eec'] != get_post_meta( $post_id, '_energy_efficiency_current', TRUE ))
                    ||
                    (isset($epc_data['eep']) && $epc_data['eep'] != get_post_meta( $post_id, '_energy_efficiency_potential', TRUE ))
                    ||
                    (isset($epc_data['eic']) && $epc_data['eic'] != get_post_meta( $post_id, '_environment_impact_current', TRUE ))
                    ||
                    (isset($epc_data['eip']) && $epc_data['eip'] != get_post_meta( $post_id, '_environment_impact_potential', TRUE ))
                )
                {
                    if ( function_exists('imagecreatefrompng') )
                    {
                        $background = imagecreatefrompng($background);

                        // If EER ratings are input, get the correct coloured pointer images and their vertical positions
                        if ( in_array($epc_type, array('eer_eir', 'eer_only')) )
                        {
                            list($eer_current_image, $eer_current_y) = $this->convert_value_to_image_and_y($epc_data['eec']);
                            $eer_current = dirname(__FILE__) . '/../assets/images/eer/' . $eer_current_image;
                            $eer_current = imagecreatefrompng($eer_current);

                            list($eer_potential_image, $eer_potential_y) = $this->convert_value_to_image_and_y($epc_data['eep']);
                            $eer_potential = dirname(__FILE__) . '/../assets/images/eer/' . $eer_potential_image;
                            $eer_potential = imagecreatefrompng($eer_potential);
                        }

                        // If EIR ratings are input, get the correct coloured pointer images and their vertical positions
                        if ( in_array($epc_type, array('eer_eir', 'eir_only')) )
                        {
                            list($eir_current_image, $eir_current_y) = $this->convert_value_to_image_and_y($epc_data['eic']);
                            $eir_current = dirname(__FILE__) . '/../assets/images/eir/' . $eir_current_image;
                            $eir_current = imagecreatefrompng($eir_current);

                            list($eir_potential_image, $eir_potential_y) = $this->convert_value_to_image_and_y($epc_data['eip']);
                            $eir_potential = dirname(__FILE__) . '/../assets/images/eir/' . $eir_potential_image;
                            $eir_potential = imagecreatefrompng($eir_potential);
                        }

                        // Create the background image of the image
                        $background_image_width = $epc_type == 'eer_eir' ? 957 : 471;
                        $output_image = imagecreatetruecolor($background_image_width, 404);
                        imagecopyresized($output_image, $background, 0, 0, 0, 0, $background_image_width, 404, $background_image_width, 404);

                        // Place the EER pointers in the correct place on the EPC graph background
                        if ( in_array($epc_type, array('eer_eir', 'eer_only')) )
                        {
                            imagecopyresized($output_image, $eer_current, 313, $eer_current_y, 0, 0, 71, 31, 71, 31);
                            imagecopyresized($output_image, $eer_potential, 390, $eer_potential_y, 0, 0, 71, 31, 71, 31);
                        }

                        // Place the EIR pointers in the correct place on the EPC graph background
                        if ( in_array($epc_type, array('eer_eir', 'eir_only')) )
                        {
                            $eir_current_x = $epc_type == 'eer_eir' ? 801 : 316;
                            $eir_potential_x = $epc_type == 'eer_eir' ? 877 : 393;
                            imagecopyresized($output_image, $eir_current, $eir_current_x, $eir_current_y, 0, 0, 71, 31, 71, 31);
                            imagecopyresized($output_image, $eir_potential, $eir_potential_x, $eir_potential_y, 0, 0, 71, 31, 71, 31);
                        }

                        // Add the rating text to the pointers in the correct position
                        $white = imagecolorallocate($output_image, 255, 255, 255);
                        $font = dirname(__FILE__) . '/../assets/fonts/arial.ttf';

                        if ( in_array($epc_type, array('eer_eir', 'eer_only')) )
                        {
                            $text = round($epc_data['eec']);
                            $x = 346;
                            if ( $epc_data['eec'] > 9 ) { $x = $x-5; }
                            imagettftext($output_image, 15, 0, $x, $eer_current_y + 23, $white, $font, $text);

                            $text = round($epc_data['eep']);
                            $x = 423;
                            if ( $epc_data['eep'] > 9 ) { $x = $x-5; }
                            imagettftext($output_image, 15, 0, $x, $eer_potential_y + 23, $white, $font, $text);
                        }

                        if ( in_array($epc_type, array('eer_eir', 'eir_only')) )
                        {
                            $text = round($epc_data['eic']);
                            $x = $epc_type == 'eer_eir' ? 834 : 349;
                            if ( $epc_data['eic'] > 9 ) { $x = $x-5; }
                            imagettftext($output_image, 15, 0, $x, $eir_current_y + 23, $white, $font, $text);

                            $text = round($epc_data['eip']);
                            $x = $epc_type == 'eer_eir' ? 910 : 426;
                            if ( $epc_data['eip'] > 9 ) { $x = $x-5; }
                            imagettftext($output_image, 15, 0, $x, $eir_potential_y + 23, $white, $font, $text);
                        }

                        $tmpfname = tempnam(sys_get_temp_dir(), 'ph_epc');

                        imagepng($output_image, $tmpfname);

                        $upload = wp_upload_bits('epc-' . $post_id . '-' . time() . '.png', null, file_get_contents($tmpfname));  
                                                            
                        if( isset($upload['error']) && $upload['error'] !== FALSE )
                        {
                            $this->log_error( 'An error occurred whilst generating EPC. The error was as follows: ' . print_r($upload['error'], TRUE), $post_id, $crm_id );
                        }
                        else
                        {
                            // We don't already have a thumbnail and we're presented with an image
                            $wp_filetype = wp_check_filetype( $upload['file'], null );
                        
                            $attachment = array(
                                 //'guid' => $wp_upload_dir['url'] . '/' . trim($media_file_name, '_'), 
                                 'post_mime_type' => $wp_filetype['type'],
                                 'post_title' => 'EPC',
                                 'post_content' => '',
                                 'post_status' => 'inherit'
                            );
                            $attach_id = wp_insert_attachment( $attachment, $upload['file'], $_POST['post_id'] );
                            
                            if ( $attach_id === FALSE || $attach_id == 0 )
                            {
                                $this->log_error( 'Failed inserting image attachment ' . $upload['file'] . ' - ' . print_r($attachment, TRUE), $post_id, $crm_id );
                            }
                            else
                            {  
                                $attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                                wp_update_attachment_metadata( $attach_id, $attach_data );

                                $media_ids[] = $attach_id;

                                ++$new;
                                
                                $this->log( 'EPC graph auto-generated', $post_id, $crm_id );

                                update_post_meta( $post_id, '_energy_efficiency_current', isset($epc_data['eec']) ? $epc_data['eec'] : '' );
                                update_post_meta( $post_id, '_energy_efficiency_potential', isset($epc_data['eep']) ? $epc_data['eep'] : '' );
                                update_post_meta( $post_id, '_environment_impact_current', isset($epc_data['eic']) ? $epc_data['eic'] : '' );
                                update_post_meta( $post_id, '_environment_impact_potential', isset($epc_data['eip']) ? $epc_data['eip'] : '' );
                            }
                        }

                        unlink($tmpfname);
                    }
                    else
                    {
                        // imagecreatefrompng function doesn't exist
                        $this->log_error( 'Couldn\'t generate EPC graph: imagecreatefrompng function doesn\'t exist', $post_id, (string)$property->ID );

                        $media_ids = is_array($previous_media_ids) ? $previous_media_ids : array();
                    }
                }
                else
                {
                    // ratings are the same as last time
                    $media_ids = is_array($previous_media_ids) ? $previous_media_ids : array();

                    ++$existing;
                }
            }
            // End Generate EPC

            update_post_meta( $post_id, '_' . $media_type . 's', $media_ids );

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

            $this->log( 'Imported ' . count($media_ids) . ' ' . $media_type . 's (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', $post_id, $crm_id );
            if ( $queued > 0 ) 
            {
                $this->log( $queued . ' ' . $media_type . 's added to download queue', $post_id, $crm_id );
            }

            update_option( 'propertyhive_property_' . $media_type . '_media_ids_' . $this->import_id, '', false );
        }

        if ( !empty($files_to_unlink) )
        {
            foreach ( $files_to_unlink as $file_to_unlink )
            {
                unlink($file_to_unlink);
            }
        }

        do_action( "propertyhive_property_media_imported", $post_id, $crm_id, $this->import_id, $this->instance_id, $media_type, $media, $use_modified, $force_download, $epc_data );
    }

    public function add_media_to_download_queue($property_id, $media_location, $media_type, $media_order, $media_description = '', $media_compare_url = '', $media_modified = '0000-00-00 00:00:00')
    {
        global $wpdb;

        $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
        $current_date = $current_date->format("Y-m-d H:i:s");

        if ( empty($media_description) )
        {
            $media_description = '';
        }

        $wpdb->insert(
            $wpdb->prefix . "ph_propertyimport_media_queue",
            array(
                'import_id'      => $this->import_id,
                'property_id'    => $property_id,
                'post_id'        => $property_id,
                'media_location' => $media_location,
                'media_description' => $media_description,
                'media_type'     => $media_type,
                'media_order'    => $media_order,
                'media_compare_url' => $media_compare_url,
                'media_modified' => $media_modified,
                'date_queued'    => $current_date,
            )
        );
    }

    public function open_ftp_connection($host, $username, $password, $directory, $passive)
    {
        // Connect to FTP directory and get file
        $ftp_connected = false;
        $ftp_conn = ftp_connect( $host );
        if ( $ftp_conn !== FALSE )
        {
            $this->log('Connected via FTP to ' . $host . ' successfully');
            $ftp_login = ftp_login( $ftp_conn, $username, $password );
            if ( $ftp_login !== FALSE )
            {
                $this->log('Logged in via FTP successfully');
                if ( isset($passive) && ( $passive == '1' || $passive == 'yes' ) )
                {
                    if ( ftp_pasv( $ftp_conn, true ) )
                    {
                        $this->log('Enabled FTP passive mode');
                    }
                    else
                    {
                        $this->log_error('Failed when enabling FTP passive mode');
                    }
                }

                if ( !empty($directory) )
                {
                    if ( ftp_chdir( $ftp_conn, $directory ) )
                    {
                        $this->log('Changed to FTP directory ' . $directory . ' successfully');
                        $ftp_connected = true;
                    }
                    else
                    {
                        $this->log_error('Failed to change to FTP directory ' . $directory);
                    }
                }
                else
                {
                    $ftp_connected = true;
                }
            }
        }
        return $ftp_connected ? $ftp_conn : null;
    }

    public function convert_value_to_image_and_y($value)
    {
        $value = (int)$value;
        $image = '1-20.png';
        $y = 100;
        if ( $value >= 1 && $value <= 20 )
        {
            $min_y = 275;
            $max_y = 303;

            $y = $max_y - $this->get_y($value, 1, 20, $min_y, $max_y);

            $image = '1-20.png';
        }
        if ( $value >= 21 && $value <= 38 )
        {
            $min_y = 243;
            $max_y = 271;

            $y = $max_y - $this->get_y($value, 21, 38, $min_y, $max_y);

            $image = '21-38.png';
        }
        if ( $value >= 39 && $value <= 54 )
        {
            $min_y = 211;
            $max_y = 239;

            $y = $max_y - $this->get_y($value, 39, 54, $min_y, $max_y);

            $image = '39-54.png';
        }
        if ( $value >= 55 && $value <= 68 )
        {
            $min_y = 179;
            $max_y = 207;

            $y = $max_y - $this->get_y($value, 55, 68, $min_y, $max_y);

            $image = '55-68.png';
        }
        if ( $value >= 69 && $value <= 80 )
        {
            $min_y = 147;
            $max_y = 175;

            $y = $max_y - $this->get_y($value, 69, 80, $min_y, $max_y);

            $image = '69-80.png';
        }
        if ( $value >= 81 && $value <= 91 )
        {
            $min_y = 115;
            $max_y = 143;

            $y = $max_y - $this->get_y($value, 81, 91, $min_y, $max_y);

            $image = '81-91.png';
        }
        if ( $value >= 92 && $value <= 100 )
        {
            $min_y = 83;
            $max_y = 111;

            $y = $max_y - $this->get_y($value, 92, 100, $min_y, $max_y);

            $image = '92-100.png';
        }

        return array($image, $y);
    }

    private function get_y($value, $min, $max, $min_y, $max_y)
    {
        $value_diff = $max - $min;

        $y = (($value - $min) / $value_diff) * 100;

        // convert percentage back into px
        $px_diff = $max_y - $min_y;

        $y = ($y / 100) * $px_diff;

        return floor($y);
    }

    public function get_primary_office_id()
    {
        $primary_office_id = '';
        $args = array(
            'post_type' => 'office',
            'nopaging' => true
        );
        $office_query = new WP_Query($args);
        
        if ( $office_query->have_posts() )
        {
            while ( $office_query->have_posts() )
            {
                $office_query->the_post();

                if ( get_post_meta(get_the_ID(), 'primary', TRUE) == '1' )
                {
                    $primary_office_id = get_the_ID();
                }
            }
        }
        wp_reset_postdata();

        $this->primary_office_id = $primary_office_id;
    }

    public function get_negotiators()
    {
        $args = array(
            'number' => 9999,
            'orderby' => 'display_name',
            'role__not_in' => array('property_hive_contact')
        );
        $user_query = new WP_User_Query( $args );

        $negotiators = array();

        if ( ! empty( $user_query->results ) )
        {
            foreach ( $user_query->results as $user )
            {
                $negotiators[$user->ID] = array(
                    'display_name' => $user->display_name
                );
            }
        }

        $this->negotiators = $negotiators;
    }

    public function get_property_portal_branch_mappings()
    {
        if ( !class_exists('PH_Property_Portal') )
        {
            return;
        }

        $branch_mappings = array();
        $branch_mappings['sales'] = array();
        $branch_mappings['lettings'] = array();
        $branch_mappings['commercial'] = array();

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

                $agent_branches = get_post_meta($agent_id, '_branches', true);
                $agent_branch_ids = array();

                // If _branches post_meta is set, use that. If not, get list of associated branch_ids
                if ( is_array($agent_branches) )
                {
                    $agent_branch_ids = array_keys($agent_branches);
                }
                else
                {
                    $args = array(
                        'post_type' => 'branch',
                        'nopaging' => true,
                        'meta_query' => array(
                            array(
                                'key' => '_agent_id',
                                'value' => $agent_id
                            )
                        )
                    );
                    $branch_query = new WP_Query($args);
                    
                    if ($branch_query->have_posts())
                    {
                        while ($branch_query->have_posts())
                        {
                            $branch_query->the_post();

                            $agent_branch_ids[] = get_the_ID();
                        }
                    }
                    $branch_query->reset_postdata();
                }

                foreach ( $agent_branch_ids as $agent_branch_id )
                {
                    // If this branch has an import_id associated, append it to branch code to ensure the correct agent is assigned
                    // If $import_id is empty, the feed is being run manually so we don't need to check the branch's import_id
                    $branch_import_suffix = '';
                    if ($this->import_id != '' && get_post_meta( $agent_branch_id, '_import_id', true ) != '')
                    {
                        $branch_import_suffix = '|' . get_post_meta( $agent_branch_id, '_import_id', true );
                    }

                    if ( get_post_meta( $agent_branch_id, '_branch_code_sales', true ) != '' )
                    {
                        $branch_code = get_post_meta( $agent_branch_id, '_branch_code_sales', true ) . $branch_import_suffix;
                        $branch_mappings['sales'][$branch_code] = $agent_id . '|' . $agent_branch_id;
                    }
                    if ( get_post_meta( $agent_branch_id, '_branch_code_lettings', true ) != '' )
                    {
                        $branch_code = get_post_meta( $agent_branch_id, '_branch_code_lettings', true ) . $branch_import_suffix;
                        $branch_mappings['lettings'][$branch_code] = $agent_id . '|' . $agent_branch_id;
                    }
                    if ( get_post_meta( $agent_branch_id, '_branch_code_commercial', true ) != '' )
                    {
                        $branch_code = get_post_meta( $agent_branch_id, '_branch_code_commercial', true ) . $branch_import_suffix;
                        $branch_mappings['commercial'][$branch_code] = $agent_id . '|' . $agent_branch_id;
                    }
                }
            }
        }
        $agent_query->reset_postdata();
        
        $this->branch_mappings = $branch_mappings;
    }

    // DEPRECATED
    public function propertyhive_property_import_add_log( $message, $post_id = 0, $crm_id = '', $received_data = '', $ping = true )
    {
        $this->log( $message, $post_id = 0, $crm_id = '', $received_data = '', $ping = true );
    }
    public function propertyhive_property_import_add_error( $message, $post_id = 0, $crm_id = '', $received_data = '', $ping = true )
    {
        $this->log_error( $message, $post_id = 0, $crm_id = '', $received_data = '', $ping = true );
    }
}

new PH_Property_Import_Process();