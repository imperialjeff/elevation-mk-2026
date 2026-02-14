<?php
/**
 * Class for managing the import process of a Vebra / Alto API XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Vebra_API_XML_Import extends PH_Property_Import_Process {

    /**
     * @var array
     */
    private $database_ids;

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

    private function get_uploads_dir()
    {
        $uploads_dir = false;

        $wp_upload_dir = wp_upload_dir();
        if ( $wp_upload_dir['error'] !== FALSE )
        {
            $this->log_error("Unable to create uploads folder. Please check permissions");
            return false;
        }
        else
        {
            $uploads_dir = $wp_upload_dir['basedir'] . '/ph_import/';

            if ( ! @file_exists($uploads_dir) )
            {
                if ( ! @mkdir($uploads_dir) )
                {
                    $this->log_error("Unable to create directory " . $uploads_dir);
                    return false;
                }
            }
            else
            {
                if ( ! @is_writeable($uploads_dir) )
                {
                    $this->log_error("Directory " . $uploads_dir . " isn't writeable");
                    return false;
                }
            }
        }

        return $uploads_dir;
    }

    // Function to authenticate self to API and return/store the Token
    private function get_token($url, $filename) 
    {
        $uploads_dir = $this->get_uploads_dir();

        if ( $uploads_dir === false )
        {
            return false;
        }

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        // Overwriting the response headers from each attempt in this file (for information only)
        $file = $uploads_dir . "headers-" . $this->import_id . ".txt";

        if ( file_exists($file) )
        {
            unlink($file);
        }

        $fh = fopen($file, "w");

        if ( $fh === false )
        {
            $this->log_error( 'Failed to open file ' . $file . ' to store returned headers. Please check permissions' );
            $this->log_error( print_r(error_get_last(), true) );
        }
        else
        {
            // Start curl session
            $ch = curl_init($url);
            // Define Basic HTTP Authentication method
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            // Provide Username and Password Details
            curl_setopt($ch, CURLOPT_USERPWD, $import_settings['username'] . ":" . $import_settings['password']);
            // Show headers in returned data but not body as we are only using this curl session to aquire and store the token
            curl_setopt($ch, CURLOPT_HEADER, 1); 
            curl_setopt($ch, CURLOPT_NOBODY, 1); 
            // write the output (returned headers) to file
            curl_setopt($ch, CURLOPT_FILE, $fh);
            // execute curl session
            curl_exec($ch);
            // Log curl request info in case of error
            $token_request_info = curl_getinfo($ch);
            // close curl session
            curl_close($ch); 

            // close headers.txt file
            fclose($fh);
        }

        // read each line of the returned headers back into an array
        $headers = file($uploads_dir . 'headers-' . $this->import_id . '.txt', FILE_SKIP_EMPTY_LINES);
        
        $token = '';

        // For each line of the array explode the line by ':' (Separating the header name from its value)
        foreach ( $headers as $headerLine )
        {
            $line = explode(':', $headerLine);
            $header = $line[0];
            
            // If the request is successful and we are returned a token
            if ( strtolower($header) == "token" ) 
            {
                $value = trim($line[1]);

                // Save token start and expire time (roughly)
                $tokenStart = time(); 
                $tokenExpire = $tokenStart + (60 * 60);

                $token = base64_encode($value);
                $this->log("Got new token: " . $token);

                update_option( 'propertyhive_vebra_api_xml_token_' . $this->import_id, $token . "," . date('Y-m-d H:i:s', $tokenStart) . "," . date('Y-m-d H:i:s', $tokenExpire), false );
            }
        }

        // If we have been given a token request XML from the API authenticating using the token
        if ( !empty($token) ) 
        {
            $this->connect($url, $filename);

            unlink($uploads_dir . 'headers-' . $this->import_id . '.txt');
        }
        else
        {
            // If we have not been given a new token its because:
            // a) we already have a live token which has not expired yet (check the tokens.txt file)
            // or
            // b) there was an error.
            // Write this to logs for reference
            //log_error("There is still an active Token, you must wait for this token to expire before a new one can be requested!");
            $this->log("Response when requesting token: " . file_get_contents($uploads_dir . 'headers-' . $this->import_id . '.txt'));

            if ( isset($token_request_info['http_code']) && $token_request_info['http_code'] === 401 )
            {
                $this->log_error("Error encountered when requesting token. The most common causes for this are incorrect credentials or the credentials are already in use on another site. Please confirm credentials or request a new set from Alto.");
            }
        }
    }

    // Function to connect to the API authenticating ourself with the token we have been given
    private function connect( $url, $filename ) {

        $token = '';

        if ( isset($_GET['token']) && !empty($_GET['token']) )
        {
            $token = $_GET['token'];
        }
        else
        {
            $latest_token = get_option( 'propertyhive_vebra_api_xml_token_' . $this->import_id, '' );

            if ( !empty($latest_token) )
            {
                $timeNowSecs = time();

                $tokenRow = explode(",", $latest_token);
                $tokenValue = $tokenRow[0];
                $tokenStart = $tokenRow[1];
                $tokenStartSecs = strtotime($tokenStart);
                $tokenExpiry = $tokenRow[2];
                $tokenExpirySecs = strtotime($tokenExpiry);

                if ( $timeNowSecs >= $tokenStartSecs && $timeNowSecs <= $tokenExpirySecs )
                {
                    // We have a token that is currently valid
                    $token = $tokenValue;
                }
            }
        }

        // If token is not set skip to else condition to request a new token 
        if ( !empty($token) ) 
        {
            // Set a new file name and create a new file handle for our returned XML
            $file = $filename;

            if ( file_exists($file) )
            {
                unlink($file);
            }

            $fh3 = fopen($file, "w");

            if ( $fh3 === false )
            {
                $this->log_error( 'Failed to open file ' . $file . ' to store returned XML. Please check permissions' );
                $this->log_error( print_r(error_get_last(), true) );
            }
            else
            {
                // Initiate a new curl session
                $ch = curl_init($url);
                // Don't require header this time as curl_getinfo will tell us if we get HTTP 200 or 401
                curl_setopt($ch, CURLOPT_HEADER, 0); 
                // Provide Token in header
                curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Basic ' . $token));
                // Write returned XML to file
                curl_setopt($ch, CURLOPT_FILE, $fh3);
                // Execute the curl session
                curl_exec($ch);
                
                // Store the curl session info/returned headers into the $info array
                $info = curl_getinfo($ch);

                // Check if we have been authorised or not
                if ( $info['http_code'] == '401' ) 
                {
                    $this->get_token($url, $filename);
                }
                elseif ( $info['http_code'] == '200' )
                {
                    
                }
                else
                {
                    $this->log("Got HTTP code: " . $info['http_code'] . " when making request to " . $url);

                    if ( $info['http_code'] === 304 )
                    {
                        $this->log("No properties have been modified since the last time an import ran.");
                    }
                }
                
                // Close the curl session
                curl_close($ch);
                // Close the open file handle
                fclose($fh3);
            }           
        }
        else
        {
            // Run the getToken function above if we are not authenticated
            $this->get_token($url, $filename);
        }
        
    }

    public function get_properties_for_initial_population( $test = false )
    {
        $uploads_dir = $this->get_uploads_dir();

        if ( $uploads_dir === false )
        {
            return false;
        }

        if ( $test === false )
        {
            $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
        }
        else
        {
            $import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
        }

        $this->log("Getting all properties for initial population");

        $request = "http://webservices.vebra.com/export/" . $import_settings['datafeed_id'] . "/v13/branch";

        $branches_file = $uploads_dir . "branches-" . $this->import_id . ".xml";

        $this->connect($request, $branches_file);

        if ( file_exists($branches_file) )
        {
            $branches_xml = @simplexml_load_file($branches_file);

            if ( $branches_xml !== FALSE )
            {
                foreach ( $branches_xml->branch as $branch )
                {
                    $branch_xml_url = (string)$branch->url;

                    // We have the branch. Now get all properties for this branch
                    $request = $branch_xml_url . "/property";

                    $properties_file = $uploads_dir . "properties-" . $this->import_id . ".xml";

                    $this->connect($request, $properties_file);

                    if ( file_exists($properties_file) )
                    {
                        $properties_xml = @simplexml_load_file($properties_file);

                        if ( $properties_xml !== FALSE )
                        {
                            foreach ( $properties_xml->property as $property )
                            {
                                $property_xml_url = (string)$property->url;

                                $request = $property_xml_url;

                                $property_file = $uploads_dir . "property-" . $this->import_id . ".xml";

                                $this->connect($request, $property_file);

                                if ( file_exists($property_file) )
                                {
                                    $property_xml = @simplexml_load_file($property_file);

                                    if ( $property_xml !== FALSE )
                                    {
                                        $property_xml->addChild('action', 'updated');

                                        $this->properties[] = $property_xml;
                                    }
                                    else
                                    {
                                        //echo 'Failed to parse property XML';
                                    }

                                    unlink($property_file);
                                }
                                else
                                {
                                    //echo 'File ' . $property_file . ' doesnt exist';
                                }
                            }
                        }
                        else
                        {
                            //echo 'Failed to parse properties XML';
                        }

                        unlink($properties_file);
                    }
                    else
                    {
                        //echo 'File ' . $properties_file . ' doesnt exist';
                    }
                }
            }
            else
            {
                //echo 'Failed to parse branches XML';
            }

            unlink($branches_file);
        }
        else
        {
            //echo 'File ' . $branches_file . ' doesnt exist';
        }
    }

    public function get_changed_properties( $date_ran_before, $test = false )
    {
        $uploads_dir = $this->get_uploads_dir();

        if ( $uploads_dir === false )
        {
            return false;
        }

        if ( $test === false )
        {
            $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
        }
        else
        {
            $import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
        }
        
        $this->log("Getting properties updated since " . $date_ran_before);

        $request = "http://webservices.vebra.com/export/" . $import_settings['datafeed_id'] . "/v13/property/" . 
            date("Y", strtotime($date_ran_before)) . "/" . 
            date("m", strtotime($date_ran_before)) . "/" . 
            date("d", strtotime($date_ran_before)) . "/" . 
            date("H", strtotime($date_ran_before)) . "/" . 
            date("i", strtotime($date_ran_before)) . "/" . 
            date("s", strtotime($date_ran_before));

        $properties_file = $uploads_dir . "properties-" . $this->import_id . ".xml";

        $this->connect($request, $properties_file);

        if ( file_exists($properties_file) && filesize($properties_file) > 0 )
        {
            $properties_xml = @simplexml_load_file($properties_file);

            if ( $properties_xml !== FALSE )
            {
                foreach ( $properties_xml->property as $property )
                {
                    if ( isset($property->action) && (string)$property->action == 'deleted' )
                    {
                        $this->remove_property( (string)$property->propid );
                    }
                    else
                    {
                        $property_xml_url = (string)$property->url;

                        $request = $property_xml_url;

                        $property_file = $uploads_dir . "property-" . $this->import_id . ".xml";

                        $this->connect($request, $property_file);

                        if ( file_exists($property_file) )
                        {
                            $property_xml = @simplexml_load_file($property_file);

                            if ( $property_xml !== FALSE )
                            {
                                $property_xml->addChild('action', (string)$property->action);

                                $this->properties[] = $property_xml;
                            }
                            else
                            {
                                //echo 'Failed to parse property XML';
                            }

                            unlink($property_file);
                        }
                        else
                        {
                            //echo 'File ' . $property_file . ' doesnt exist';
                        }
                    }
                }
            }
            else
            {
                //echo 'Failed to parse properties XML or no properties found';
            }

            unlink($properties_file);
        }
        else
        {
            //echo 'File ' . $properties_file . ' doesnt exist';
        }
    }

    public function parse( $test = false )
    {
        global $wpdb;

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

        $date_ran_before = false;
        if ( ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' ) )
        {
            $query = "
                SELECT 
                    id, start_date
                FROM 
                    " . $wpdb->prefix . "ph_propertyimport_instance_v3
                WHERE 
                    start_date <= end_date
                    AND
                    import_id = '" . $this->import_id . "'
                    AND
                    media = 0
                ORDER BY
                    start_date DESC
                LIMIT 1
            ";
            $results = $wpdb->get_results( $query );
            if ( $results )
            {
                foreach ( $results as $result ) 
                {
                    $date_ran_before = $result->start_date;
                }
            }
        }

        if ( $date_ran_before === FALSE )
        {
            // Import never ran before. Need to do initial process which involves getting branches then properties
            $this->get_properties_for_initial_population( $test );
        }
        else
        {
            if ( date("I", strtotime($date_ran_before)) == 1 )
            {
                $date_ran_before = date("Y-m-d H:i:s", strtotime($date_ran_before) - 86400); // - Just get all changed properties in last 24 hours. This avoids any issues with timestamps, BST etc
            }
            $this->get_changed_properties( $date_ran_before, $test );
        }

        if ( !empty($this->properties) )
        {
            $this->log("Parsing properties");

            $properties = array();

            $database_id_mappings = array(
                '1' => 'residential-sales',
                '2' => 'residential-lettings',
                '5' => 'commercial',
                '15' => 'residential-sales',
            );
            $this->database_ids = apply_filters( 'propertyhive_database_id_mappings_vebra_api_xml', $database_id_mappings, $this->import_id );
            
            foreach ( $this->properties as $property )
            {
                $property_attributes = $property->attributes();

                // Only import UK residential sales (1), UK residential lettings (2), UK commercial (5), UK new homes (15)
                if ( 
                    isset($property_attributes['database']) 
                    &&
                    in_array((string)$property_attributes['database'], array_keys($this->database_ids))
                )
                {
                    $properties[] = $property;
                }

            } // end foreach property

            $this->properties = $properties;
        }

        if ( $test === false && empty($this->properties) && ( !isset($import_settings['only_updated']) || $import_settings['only_updated'] == '' ) )
        {
            $this->log_error('No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.');

            return false;
        }
    }

    public function import()
    {
        global $wpdb;

        $imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

        $this->import_start();

        do_action( "propertyhive_pre_import_properties_vebra_api_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_vebra_api_xml_properties_due_import", $this->properties, $this->import_id );

        $this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

        $start_at_property = '';
        if ( !isset($import_settings['only_updated']) || $import_settings['only_updated'] == '' )
        {
            $start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );
        }

        $property_row = 1;
        foreach ( $this->properties as $property )
        {
            do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_vebra_api_xml", $property, $this->import_id, $this->instance_id );
            
            $property_attributes = $property->attributes();

            if ( !empty($start_at_property) )
            {
                // we need to start on a certain property
                if ( (string)$property_attributes['id'] == $start_at_property )
                {
                    // we found the property. We'll continue for this property onwards
                    $this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property_attributes['id'] );
                    $start_at_property = false;
                }
                else
                {
                    ++$property_row;
                    continue;
                }
            }

            if ( !isset($import_settings['only_updated']) || $import_settings['only_updated'] == '' )
            {
                update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property_attributes['id'], false );
            }

            $this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property_attributes['id'], 0, (string)$property_attributes['id'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $create_date = '';
            if ( isset($property->uploaded) && (string)$property->uploaded != '' )
            {
                $create_date = date( 'Y-m-d H:i:s', strtotime( $property->uploaded ) );
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property_attributes['id'], $property, (string)$property->address->display, (string)$property->description, '', $create_date );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property_attributes['id'] );

                update_post_meta( $post_id, $imported_ref_key, (string)$property_attributes['id'] );
                update_post_meta( $post_id, '_vebra_propertyid', (string)$property_attributes['propertyid'] );

                update_post_meta( $post_id, '_property_import_data', $property->asXML() );

                // Address
                $reference = (string)$property->reference->agents;
                if ( empty($reference) && isset($property_attributes['id']) )
                {
                    $reference = (string)$property_attributes['id'];
                }
                update_post_meta( $post_id, '_reference_number', $reference );
                update_post_meta( $post_id, '_address_name_number', trim( ( isset($property->address->name) ) ? (string)$property->address->name : '' ) );
                update_post_meta( $post_id, '_address_street', ( ( isset($property->address->street) ) ? (string)$property->address->street : '' ) );
                update_post_meta( $post_id, '_address_two', ( ( isset($property->address->locality) ) ? (string)$property->address->locality : '' ) );
                update_post_meta( $post_id, '_address_three', ( ( isset($property->address->town) ) ? (string)$property->address->town : '' ) );
                update_post_meta( $post_id, '_address_four', ( ( isset($property->address->county) ) ? (string)$property->address->county : '' ) );
                update_post_meta( $post_id, '_address_postcode', ( ( isset($property->address->postcode) ) ? (string)$property->address->postcode : '' ) );

                $country = get_option( 'propertyhive_default_country', 'GB' );
                update_post_meta( $post_id, '_address_country', $country );

                // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
                $address_lines = array();
                if ( isset($property->address->locality) && trim((string)$property->address->locality) != '' )
                {
                    $address_lines[] = $property->address->locality;
                }
                if ( isset($property->address->town) && trim((string)$property->address->town) != '' )
                {
                    $address_lines[] = $property->address->town;
                }
                if ( isset($property->address->county) && trim((string)$property->address->county) != '' )
                {
                    $address_lines[] = $property->address->county;
                }
                if ( isset($property->address->custom_location) && trim((string)$property->address->custom_location) != '' )
                {
                    $address_lines[] = $property->address->custom_location;
                }

                $location_term_ids = array();
                foreach ( $address_lines as $address_line )
                {
                    $term = term_exists( trim($address_line), 'location');
                    if ( $term !== 0 && $term !== null && isset($term['term_id']) )
                    {
                        $location_term_ids[] = (int)$term['term_id'];
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
                update_post_meta( $post_id, '_latitude', ( ( isset($property->latitude) ) ? (string)$property->latitude : '' ) );
                update_post_meta( $post_id, '_longitude', ( ( isset($property->longitude) ) ? (string)$property->longitude : '' ) );

                // Owner
                add_post_meta( $post_id, '_owner_contact_id', '', true );

                // Record Details
                $new_negotiator_id = '';

                // Check if negotiator exists with this name
                if ( isset($property->negotiator) && (string)$property->negotiator != '' )
                {
                    foreach ( $this->negotiators as $negotiator_key => $negotiator )
                    {
                        if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim( (string)$property->negotiator )) )
                        {
                            $new_negotiator_id = $negotiator_key;
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
                if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
                {
                    foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
                    {
                        $explode_branch_codes = explode(",", $branch_code);
                        $explode_branch_codes = array_map('trim', $explode_branch_codes);
                        foreach ( $explode_branch_codes as $branch_code )
                        {
                            $explode_branch_code = explode("-", $branch_code);
                            if ( 
                                ( count($explode_branch_code) == 1 && $branch_code == (string)$property_attributes['branchid'] )
                                || 
                                ( count($explode_branch_code) == 2 && $explode_branch_code[0] == (string)$property_attributes['firmid'] && $explode_branch_code[1] == (string)$property_attributes['branchid'] )
                            )
                            {
                                $office_id = $ph_office_id;
                                break;
                            }
                        }
                    }
                }
                update_post_meta( $post_id, '_office_id', $office_id );

                // Default values: '1' => 'residential-sales', '2' => 'residential-lettings', '5' => 'commercial', '15' => 'residential-sales'
                $department = '';
                $prefix = '';
                if ( isset($this->database_ids[(string)$property_attributes['database']]) )
                {
                    $department = $this->database_ids[(string)$property_attributes['database']];

                    if ( $department == 'commercial' )
                    {
                        $prefix = 'commercial_';
                    }
                }

                // Is the property portal add on activated
                if (class_exists('PH_Property_Portal'))
                {
                    // Use the branch code to map this property to the correct agent and branch
                    $explode_agent_branch = array();
                    if (
                        isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['firmid'] . '-' . (string)$property_attributes['branchid'] . '|' . $this->import_id]) &&
                        $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['firmid'] . '-' . (string)$property_attributes['branchid'] . '|' . $this->import_id] != ''
                    )
                    {
                        // A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
                        $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['firmid'] . '-' . (string)$property_attributes['branchid'] . '|' . $this->import_id]);
                    }
                    elseif (
                        isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['branchid'] . '|' . $this->import_id]) &&
                        $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['branchid'] . '|' . $this->import_id] != ''
                    )
                    {
                        // A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
                        $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['branchid'] . '|' . $this->import_id]);
                    }
                    elseif (
                        isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['firmid'] . '-' . (string)$property_attributes['branchid']]) &&
                        $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['firmid'] . '-' . (string)$property_attributes['branchid']] != ''
                    )
                    {
                        // No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
                        $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['firmid'] . '-' . (string)$property_attributes['branchid']]);
                    }
                    elseif (
                        isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['branchid']]) &&
                        $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['branchid']] != ''
                    )
                    {
                        // No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
                        $explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property_attributes['branchid']]);
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
                update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedrooms) ) ? (string)$property->bedrooms : '' ) );
                update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathrooms) ) ? (string)$property->bathrooms : '' ) );
                update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->receptions) ) ? (string)$property->receptions : '' ) );

                update_post_meta( $post_id, '_council_tax_band', ( ( isset($property->council_tax->band) ) ? (string)$property->council_tax->band : '' ) );

                $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

                if ( isset($property->type) )
                {
                    $type = $property->type;
                    if ( is_array($type) )
                    {
                        $type = $type[0];
                    }

                    if ( (string)$type != '' )
                    {
                        if ( !empty($mapping) && isset($mapping[(string)$type]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[(string)$type], $prefix . 'property_type' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

                            $this->log( 'Property received with a type (' . (string)$type . ') that is not mapped', $post_id, (string)$property_attributes['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$type, $post_id );
                        }
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

                // Parking
                $mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();

                $term_ids = [];
                if ( isset( $property->features->parking->parking_type ) ) 
                {
                    foreach ( $property->features->parking->parking_type as $parking ) 
                    {
                        $parking = (string)$parking;
                        
                        if ( !empty($mapping) && isset($mapping[$parking]) )
                        {
                            $term_ids[] = (int)$mapping[$parking];
                        }
                    }
                    $term_ids = array_unique($term_ids);
                }

                if ( !empty($term_ids) )
                {
                    wp_set_object_terms( $post_id, $term_ids, 'parking' );
                }
                else
                {
                    wp_delete_object_term_relationships( $post_id, 'parking' );
                }

                $price_attributes = $property->price->attributes();

                if ( $department == 'residential-sales' )
                {
                    // Clean price
                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

                    update_post_meta( $post_id, '_price', $price );
                    update_post_meta( $post_id, '_price_actual', $price );

                    $poa = '';
                    if ( isset($price_attributes['display']) && (string)$price_attributes['display'] == 'no' )
                    {
                        $poa = 'yes';
                    }
                    if ( isset($price_attributes['qualifier']) && strtolower((string)$price_attributes['qualifier']) == 'poa' )
                    {
                        $poa = 'yes';
                    }
                    update_post_meta( $post_id, '_poa', $poa );
                    update_post_meta( $post_id, '_currency', 'GBP' );

                    // Price Qualifier
                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
                    $mapping = array_change_key_case($mapping, CASE_LOWER);

                    if ( isset($price_attributes['qualifier']) && (string)$price_attributes['qualifier'] != '' )
                    {
                        if ( !empty($mapping) && isset($mapping[strtolower((string)$price_attributes['qualifier'])]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[strtolower((string)$price_attributes['qualifier'])], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );

                            $this->log( 'Property received with a price qualifier (' . (string)$price_attributes['qualifier'] . ') that is not mapped', $post_id, (string)$property_attributes['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'price_qualifier', (string)$price_attributes['qualifier'], $post_id );
                        }
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                    }

                    // Tenure
                    $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

                    if ( !empty($mapping) && isset($property->tenure) && isset($mapping[(string)$property->tenure]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[(string)$property->tenure], 'tenure' );
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'tenure' );
                    }

                    $leasehold_years_remaining = '';
                    $service_charge = '';
                    $service_charge_review_years = '';
                    $ground_rent = '';
                    $ground_rent_review_years = '';
                    $shared_ownership = '';
                    $shared_ownership_percentage = '';
                    if ( isset($property->tenure) && ( (string)$property->tenure == 2 || (string)$property->tenure == 3 ) ) // 2 = Leasehold, 3 = Leasehold With Share Of Freehold
                    {
                        if ( isset($property->leasehold->years_remaining) && !empty((string)$property->leasehold->years_remaining) )
                        {
                            $leasehold_years_remaining = (string)$property->leasehold->years_remaining;
                        }

                        if ( isset($property->service_charge_amount) && !empty((string)$property->service_charge_amount) )
                        {
                            $service_charge = (string)$property->service_charge_amount;

                            // Convert it to a float
                            $service_charge_float = (float)$service_charge;

                            // Check if the number has a fractional part
                            if ($service_charge_float == floor($service_charge_float)) 
                            {
                                // If it doesn't, format it as an integer
                                $service_charge = number_format($service_charge_float, 0, '.', '');
                            } 
                            else 
                            {
                                // If it does, format it with two decimal places
                                $service_charge = number_format($service_charge_float, 2, '.', '');
                            }
                            
                            $service_charge_attributes = $property->service_charge_amount->attributes();
                            if ( isset($service_charge_attributes['frequency']) )
                            {
                                switch ( (string)$service_charge_attributes['frequency'] )
                                {
                                    case "week": { $service_charge = $service_charge * 52; break; }
                                    case "month": { $service_charge = $service_charge * 12; break; }
                                    case "quarter": { $service_charge = $service_charge * 4; break; }
                                }
                            }

                            if ( isset($service_charge_attributes['review_period']) )
                            {
                                $service_charge_review_years = (string)$service_charge_attributes['review_period'];
                            }
                        }
                        

                        if ( isset($property->ground_rent_amount) && !empty((string)$property->ground_rent_amount) )
                        {
                            $ground_rent = (string)$property->ground_rent_amount;

                            // Convert it to a float
                            $ground_rent_float = (float)$ground_rent;

                            // Check if the number has a fractional part
                            if ($ground_rent_float == floor($ground_rent_float)) 
                            {
                                // If it doesn't, format it as an integer
                                $ground_rent = number_format($ground_rent_float, 0, '.', '');
                            } 
                            else 
                            {
                                // If it does, format it with two decimal places
                                $ground_rent = number_format($ground_rent_float, 2, '.', '');
                            }

                            $ground_rent_attributes = $property->ground_rent_amount->attributes();
                            if ( isset($ground_rent_attributes['review_period']) )
                            {
                                $ground_rent_review_years = (string)$ground_rent_attributes['review_period'];
                            }
                        }

                        if ( isset($property->leasehold->shared_ownership) && !empty((string)$property->leasehold->shared_ownership) )
                        {
                            $shared_ownership_attributes = $property->leasehold->shared_ownership->attributes();
                            if ( isset($shared_ownership_attributes['percentage']) && !empty($shared_ownership_attributes['percentage']) )
                            {
                                $shared_ownership = 'yes';
                                $shared_ownership_percentage = (string)$shared_ownership_attributes['percentage'];
                            }
                        }
                    }
                    update_post_meta( $post_id, '_leasehold_years_remaining', $leasehold_years_remaining );
                    update_post_meta( $post_id, '_service_charge', $service_charge );
                    update_post_meta( $post_id, '_service_charge_review_years', $service_charge_review_years );
                    update_post_meta( $post_id, '_ground_rent', $ground_rent );
                    update_post_meta( $post_id, '_ground_rent_review_years', $ground_rent_review_years );
                    update_post_meta( $post_id, '_shared_ownership', $shared_ownership );
                    update_post_meta( $post_id, '_shared_ownership_percentage', $shared_ownership_percentage );
                }
                elseif ( $department == 'residential-lettings' )
                {
                    // Clean price
                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

                    update_post_meta( $post_id, '_rent', $price );

                    $rent_frequency = 'pcm';
                    $price_actual = $price;
                    if ( isset($price_attributes['rent']) )
                    {
                        switch (strtolower((string)$price_attributes['rent']))
                        {
                            case "pcm": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
                            case "pw": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
                            case "pq": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
                            case "pa": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
                            case "pppw":
                            {
                                $rent_frequency = 'pppw';
                                $bedrooms = ( isset($property->bedrooms) ? (string)$property->bedrooms : '0' );
                                if ( $bedrooms != '' && $bedrooms != 0 )
                                {
                                    $price_actual = (($price * 52) / 12) * $bedrooms;
                                }
                                else
                                {
                                    $price_actual = ($price * 52) / 12;
                                }
                                break;
                            }
                        }
                    }
                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
                    update_post_meta( $post_id, '_price_actual', $price_actual );
                    update_post_meta( $post_id, '_currency', 'GBP' );
                        
                    $poa = '';
                    if ( isset($price_attributes['display']) && (string)$price_attributes['display'] == 'no' )
                    {
                        $poa = 'yes';
                    }
                    if ( isset($price_attributes['qualifier']) && strtolower((string)$price_attributes['qualifier']) == 'poa' )
                    {
                        $poa = 'yes';
                    }
                    update_post_meta( $post_id, '_poa', $poa );

                    update_post_meta( $post_id, '_deposit', ( ( isset($property->let_bond) ) ? (string)$property->let_bond : '' ) );

                    $available_date = '';
                    if ( isset($property->available) && $property->available != '' && $property->available != '1900-01-01' )
                    {
                        $available_date = date( 'Y-m-d', strtotime( $property->available ) );
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

                    if ( (string)$property->commercial->transaction == 'sale' )
                    {
                        update_post_meta( $post_id, '_for_sale', 'yes' );

                        update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                        $price = (string)$property->price;
                        update_post_meta( $post_id, '_price_from', $price );
                        update_post_meta( $post_id, '_price_to', $price );

                        update_post_meta( $post_id, '_price_units', '' );

                        update_post_meta( $post_id, '_price_poa', ( ( isset($price_attributes['display']) && (string)$price_attributes['display'] == 'no' ) ? 'yes' : '') );
                    }
                    if ( (string)$property->commercial->transaction == 'rental' )
                    {
                        update_post_meta( $post_id, '_to_rent', 'yes' );

                        update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                        $rent = (string)$property->price;
                        update_post_meta( $post_id, '_rent_from', $rent );
                        update_post_meta( $post_id, '_rent_to', $rent );

                        $rent_frequency = 'pa';
                        if ( isset($price_attributes['rent']) )
                        {
                            switch (strtolower((string)$price_attributes['rent']))
                            {
                                case "pcm": { $rent_frequency = 'pcm'; break; }
                                case "pw": { $rent_frequency = 'pw'; break; }
                                case "pq": { $rent_frequency = 'pq'; break; }
                                case "pa": { $rent_frequency = 'pa'; break; }
                            }
                        }
                        update_post_meta( $post_id, '_rent_units', $rent_frequency );

                        update_post_meta( $post_id, '_rent_poa', ( ( isset($price_attributes['display']) && (string)$price_attributes['display'] == 'no' ) ? 'yes' : '') );
                    }

                    // Store price in common currency (GBP) used for ordering
                    $ph_countries = new PH_Countries();
                    $ph_countries->update_property_price_actual( $post_id );

                    // TO DO: PROPERTY TYPE

                    // Price Qualifier
                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
                    $mapping = array_change_key_case($mapping, CASE_LOWER);

                    if ( isset($price_attributes['qualifier']) && (string)$price_attributes['qualifier'] != '' )
                    {
                        if ( !empty($mapping) && isset($mapping[strtolower((string)$price_attributes['qualifier'])]) )
                        {
                            wp_set_object_terms( $post_id, (int)$mapping[strtolower((string)$price_attributes['qualifier'])], 'price_qualifier' );
                        }
                        else
                        {
                            wp_delete_object_term_relationships( $post_id, 'price_qualifier' );

                            $this->log( 'Property received with a price qualifier (' . (string)$price_attributes['qualifier'] . ') that is not mapped', $post_id, (string)$property_attributes['id'] );

                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'price_qualifier', (string)$price_attributes['qualifier'], $post_id );
                        }
                    }
                    else
                    {
                        wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
                    }

                    update_post_meta( $post_id, '_floor_area_from', '' );
                    update_post_meta( $post_id, '_floor_area_from_sqft', '' );
                    update_post_meta( $post_id, '_floor_area_to', '' );
                    update_post_meta( $post_id, '_floor_area_to_sqft', '' );
                    update_post_meta( $post_id, '_floor_area_units', '' );

                    if ( isset($property->area) && !empty($property->area) )
                    {
                        foreach ( $property->area as $area )
                        {
                            $area_attributes = $area->attributes();

                            if ( (string)$area->min != '' && (string)$area->min != '0' )
                            {
                                $size = preg_replace("/[^0-9.]/", '', (string)$area->min);
                                update_post_meta( $post_id, '_floor_area_from', $size );
                                update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, (string)$area_attributes['unit'] ) );
                            }

                            if ( (string)$area->max != '' && (string)$area->max != '0' )
                            {
                                $size = preg_replace("/[^0-9.]/", '', (string)$area->max);
                                update_post_meta( $post_id, '_floor_area_to', $size );
                                update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, (string)$area_attributes['unit'] ) );
                            }

                            update_post_meta( $post_id, '_floor_area_units', (string)$area_attributes['unit'] );

                            break;
                        }
                    }

                    update_post_meta( $post_id, '_site_area_from', '' );
                    update_post_meta( $post_id, '_site_area_from_sqft', '' );
                    update_post_meta( $post_id, '_site_area_to','' );
                    update_post_meta( $post_id, '_site_area_to_sqft', '' );
                    update_post_meta( $post_id, '_site_area_units', '' );

                    if ( isset($property->landarea) )
                    {
                        $area_attributes = $property->landarea->attributes();

                        update_post_meta( $post_id, '_site_area_from', (string)$property->landarea->area );
                        update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( (string)$property->landarea->area, (string)$area_attributes['unit'] ) );
                        update_post_meta( $post_id, '_site_area_to', (string)$property->landarea->area );
                        update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( (string)$property->landarea->area, (string)$area_attributes['unit'] ) );
                        update_post_meta( $post_id, '_site_area_units', (string)$area_attributes['unit'] );
                    }
                }

                $departments_with_residential_details = apply_filters( 'propertyhive_departments_with_residential_details', array( 'residential-sales', 'residential-lettings' ) );
                if ( in_array($department, $departments_with_residential_details) )
                {
                    // Electricity
                    $utility_type = [];
                    $utility_type_other = '';
                    if ( isset( $property->features->electricity->supply ) ) 
                    {
                        foreach ( $property->features->electricity->supply as $supply ) 
                        {
                            $supply_value = (string) $supply;
                            switch ( $supply_value ) 
                            {
                                case 'MainsSupply': $utility_type[] = 'mains_supply'; break;
                                case 'PrivateSupply': $utility_type[] = 'private_supply'; break;
                                case 'SolarPvPanels': $utility_type[] = 'solar_pv_panels'; break;
                                case 'WindTurbine': $utility_type[] = 'wind_turbine'; break;
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
                    if ( isset( $property->features->water->supply ) ) 
                    {
                        foreach ( $property->features->water->supply as $supply ) 
                        {
                            $supply_value = (string)$supply;
                            switch ( $supply_value ) 
                            {
                                case 'Mains': $utility_type[] = 'mains_supply'; break;
                                case 'PrivateWell': 
                                case 'PrivateSpring': 
                                case 'PrivateBorehole': 
                                    $utility_type[] = 'private_supply'; break;
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
                    if ( isset( $property->features->heating->source ) ) 
                    {
                        foreach ( $property->features->heating->source as $source ) 
                        {
                            $source_value = (string)$source;
                            switch ( $source_value ) 
                            {
                                case 'BiomassBoiler': $utility_type[] = 'biomass_boiler'; break;
                                case 'ElectricMains': 
                                case 'ElectricRoomHeaters': $utility_type[] = 'electric'; break;
                                case 'GasMains': $utility_type[] = 'gas_central'; break;
                                case 'HeatPumpAirSource': $utility_type[] = 'air_source_heat_pump'; break;
                                case 'HeatPumpGroundSource': $utility_type[] = 'ground_source_heat_pump'; break;
                                case 'NightStorageHeaters': $utility_type[] = 'night_storage'; break;
                                case 'Oil': $utility_type[] = 'oil'; break;
                                case 'SolarPanels': $utility_type[] = 'solar'; break;
                                case 'SolarPhotovoltaicThermal': $utility_type[] = 'solar_pv_thermal'; break;
                                case 'SolarThermal': $utility_type[] = 'solar_thermal'; break;
                                case 'UnderFloorHeating': $utility_type[] = 'under_floor'; break;
                                case 'WoodBurner': $utility_type[] = 'wood_burner'; break;
                                case 'OpenFire': $utility_type[] = 'open_fire'; break;
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
                    if ( isset( $property->features->broadband->supply ) ) 
                    {
                        foreach ( $property->features->broadband->supply as $supply ) 
                        {
                            $supply_value = (string)$supply;
                            switch ( $supply_value ) 
                            {
                                case 'Adsl': $utility_type[] = 'adsl'; break;
                                case 'Cable': $utility_type[] = 'cable'; break;
                                case 'Fttc': $utility_type[] = 'fttc'; break;
                                case 'Fttp': $utility_type[] = 'fttp'; break;
                                case 'None': $utility_type[] = 'none'; break;
                                default: 
                                    $utility_type[] = 'other'; 
                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
                                    break;
                            }
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
                    if ( isset( $property->features->sewerage->supply ) ) 
                    {
                        foreach ( $property->features->sewerage->supply as $supply ) 
                        {
                            $supply_value = (string)$supply;
                            switch ( $supply_value ) 
                            {
                                case 'Mains': $utility_type[] = 'mains_supply'; break;
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

                    // Accessibility
                    $utility_type = [];
                    $utility_type_other = '';
                    if ( isset( $property->features->accessibility_requirements->accessibility ) ) 
                    {
                        foreach ( $property->features->accessibility_requirements->accessibility as $accessibility ) 
                        {
                            $accessibility_value = (string) $accessibility;
                            switch ( $accessibility_value ) 
                            {
                                case 'LateralLiving': $utility_type[] = 'lateral_living'; break;
                                case 'StepFreeAccess': $utility_type[] = 'step_free_access'; break;
                                case 'WetRoom': $utility_type[] = 'wet_room'; break;
                                case 'LevelAccess': $utility_type[] = 'level_access'; break;
                                case 'RampedAccess': $utility_type[] = 'ramped_access'; break;
                                case 'LiftAccess': $utility_type[] = 'lift_access'; break;
                                case 'WideDoorways': $utility_type[] = 'wide_doorways'; break;
                                case 'LevelAccessShower': $utility_type[] = 'level_access_shower'; break;
                                default: 
                                    $utility_type[] = 'other'; 
                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $accessibility_value; 
                                    break;
                            }
                        }
                        $utility_type = array_unique($utility_type);
                    }
                    update_post_meta( $post_id, '_accessibility', $utility_type );
                    if ( in_array( 'other', $utility_type ) ) 
                    {
                        update_post_meta( $post_id, '_accessibility_other', $utility_type_other );
                    }

                    // Restrictions
                    $utility_type = [];
                    $utility_type_other = '';
                    if ( function_exists('get_restrictions') )
                    {
                        $restriction_types = get_restrictions();

                        if ( isset( $property->features->restrictions ) ) 
                        {
                            foreach ( $restriction_types as $restriction_key => $restriction_label ) 
                            {
                                if ( isset( $property->features->restrictions->$restriction_key ) && (string) $property->features->restrictions->$restriction_key === 'true' ) 
                                {
                                    $utility_type[] = $restriction_key;
                                }
                            }
                            if ( isset( $property->features->restrictions->other ) && (string) $property->features->restrictions->other === 'true' ) 
                            {
                                $utility_type[] = 'other';
                                $utility_type_other = 'other';
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_restriction', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_restriction_other', $utility_type_other );
                        }
                    }

                    // Rights
                    $utility_type = [];
                    $utility_type_other = '';
                    if ( function_exists('get_rights') )
                    {
                        $restriction_types = get_rights();
                        if ( isset( $property->features->rights_and_easements ) ) 
                        {
                            foreach ( $rights_types as $rights_key => $rights_label ) 
                            {
                                if ( isset( $property->features->rights_and_easements->$rights_key ) && (string) $property->features->rights_and_easements->$rights_key === 'true' ) 
                                {
                                    $utility_type[] = $rights_key;
                                }
                            }
                            if ( isset( $property->features->rights_and_easements->other ) && (string) $property->features->rights_and_easements->other === 'true' ) 
                            {
                                $utility_type[] = 'other';
                                $utility_type_other = 'other';
                            }
                            $utility_type = array_unique($utility_type);
                        }
                        update_post_meta( $post_id, '_right', $utility_type );
                        if ( in_array( 'other', $utility_type ) ) 
                        {
                            update_post_meta( $post_id, '_right_other', $utility_type_other );
                        }
                    }

                    $flooded_in_last_five_years = '';
                    if ( isset( $property->features->flooding_risks->flooded_within_last_5_years ) && (string)$property->features->flooding_risks->flooded_within_last_5_years === 'true' )
                    {
                        $flooded_in_last_five_years = 'yes';
                    }
                    if ( isset( $property->features->flooding_risks->flooded_within_last_5_years ) && (string)$property->features->flooding_risks->flooded_within_last_5_years === 'false' )
                    {
                        $flooded_in_last_five_years = 'no';
                    }
                    update_post_meta($post_id, '_flooded_in_last_five_years', $flooded_in_last_five_years );

                    $flood_defenses = '';
                    if ( isset( $property->features->flooding_risks->flood_defenses_present ) && (string)$property->features->flooding_risks->flood_defenses_present === 'true' )
                    {
                        $flood_defenses = 'yes';
                    }
                    if ( isset( $property->features->flooding_risks->flood_defenses_present ) && (string)$property->features->flooding_risks->flood_defenses_present === 'false' )
                    {
                        $flood_defenses = 'no';
                    }
                    update_post_meta($post_id, '_flood_defences', $flood_defenses );


                    $utility_type = [];
                    $utility_type_other = '';
                    if ( isset( $property->features->flooding_risks->sources_of_flooding->source ) ) 
                    {
                        foreach ( $property->features->flooding_risks->sources_of_flooding->source as $source ) 
                        {
                            $source_value = (string) $source;
                            switch ( $source_value ) 
                            {
                                case 'River': $utility_type[] = 'river'; break;
                                case 'Sea': $utility_type[] = 'sea'; break;
                                case 'Groundwater': $utility_type[] = 'groundwater'; break;
                                case 'Lake': $utility_type[] = 'lake'; break;
                                case 'Reservoir': $utility_type[] = 'reservoir'; break;
                                default: 
                                    $utility_type[] = 'other'; 
                                    $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $accessibility_value; 
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
                $on_market = '';
                if ( (string)$property->action != 'deleted' )
                {
                    $on_market = 'yes';
                }
                if ( isset($import_settings['dont_remove']) && $import_settings['dont_remove'] == '1' )
                {
                    // Keep it on the market if 'dont remove' is checked and it's already on the market
                    $previous_on_market = get_post_meta( $post_id, '_on_market', TRUE );
                    if ( $previous_on_market == 'yes' )
                    {
                        $on_market = 'yes';
                    }
                }
                $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', $on_market );
                }
                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
                if ( $featured_by_default === true )
                {
                    update_post_meta( $post_id, '_featured', ( isset($property_attributes['featured']) && (string)$property_attributes['featured'] == '1' ) ? 'yes' : '' );
                }
            
                // Availability
                $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
                    $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
                    array();

                if ( isset($property->web_status) && (string)$property->web_status != '' )
                {
                    if ( !empty($mapping) && isset($mapping[(string)$property->web_status]) )
                    {
                        wp_set_object_terms( $post_id, (int)$mapping[(string)$property->web_status], 'availability' );
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
                if ( isset($property->bullets) )
                {
                    foreach ( $property->bullets as $bullets )
                    {
                        if ( isset($bullets->bullet) )
                        {
                            foreach ( $bullets->bullet as $bullet )
                            {
                                $features[] = (string)$bullet;
                            }
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
                if ( $department == 'commercial' )
                {
                    $i = 0;
                    if ( isset($property->paragraphs) )
                    {
                        foreach ( $property->paragraphs as $paragraphs )
                        {
                            if ( isset($paragraphs->paragraph) )
                            {
                                foreach ( $paragraphs->paragraph as $paragraph )
                                {
                                    update_post_meta( $post_id, '_description_name_' . $i, (string)$paragraph->name );
                                    $text = (string)$paragraph->text;
                                    if ( isset($paragraph->dimensions->mixed) && (string)$paragraph->dimensions->mixed != '' )
                                    {
                                        $text = (string)$paragraph->dimensions->mixed;
                                        if ( isset($paragraph->text) && (string)$paragraph->text != '' )
                                        {
                                            $text .= '<br>' . (string)$paragraph->text;
                                        }
                                    }
                                    update_post_meta( $post_id, '_description_' . $i, $text );

                                    ++$i;
                                }
                            }
                        }
                    }

                    update_post_meta( $post_id, '_descriptions', $i );
                }
                else
                {
                    $i = 0;
                    if ( isset($property->paragraphs) )
                    {
                        foreach ( $property->paragraphs as $paragraphs )
                        {
                            if ( isset($paragraphs->paragraph) )
                            {
                                foreach ( $paragraphs->paragraph as $paragraph )
                                {
                                    $dimensions = '';
                                    if ( isset($paragraph->dimensions->mixed) && (string)$paragraph->dimensions->mixed != '' )
                                    {
                                        $dimensions = (string)$paragraph->dimensions->mixed;
                                    }
                                    elseif ( isset($paragraph->dimensions->metric) && (string)$paragraph->dimensions->metric != '' )
                                    {
                                        $dimensions = (string)$paragraph->dimensions->metric;
                                    }
                                    elseif ( isset($paragraph->dimensions->imperial) && (string)$paragraph->dimensions->imperial != '' )
                                    {
                                        $dimensions = (string)$paragraph->dimensions->imperial;
                                    }
                                    update_post_meta( $post_id, '_room_name_' . $i, (string)$paragraph->name );
                                    update_post_meta( $post_id, '_room_dimensions_' . $i, $dimensions );
                                    update_post_meta( $post_id, '_room_description_' . $i, (string)$paragraph->text );

                                    ++$i;
                                }
                            }
                        }
                    }

                    update_post_meta( $post_id, '_rooms', $i );
                }

                // Media - Images
                $media = array();
                if (isset($property->files) && !empty($property->files))
                {
                    foreach ($property->files as $files)
                    {
                        if (!empty($files->file))
                        {
                            foreach ($files->file as $file)
                            {
                                $file_attributes = $file->attributes();

                                if ( (string)$file_attributes['type'] == '0' )
                                {
                                    $url = (string)$file->url;
                                    $explode_url = explode("?", $url);
                                    $url = $explode_url[0];

                                    $media[] = array(
                                        'url' => $url,
                                        'description' => (string)$file->name,
                                    );
                                }
                            }
                        }
                    }
                }

                $this->import_media( $post_id, (string)$property_attributes['id'], 'photo', $media, false );

                // Media - Floorplans
                $media = array();
                if (isset($property->files) && !empty($property->files))
                {
                    foreach ($property->files as $files)
                    {
                        if (!empty($files->file))
                        {
                            foreach ($files->file as $file)
                            {
                                $file_attributes = $file->attributes();

                                if ( (string)$file_attributes['type'] == '2' )
                                {
                                    $url = (string)$file->url;
                                    $explode_url = explode("?", $url);
                                    $url = $explode_url[0];

                                    $media[] = array(
                                        'url' => $url,
                                        'description' => (string)$file->name,
                                    );
                                }
                            }
                        }
                    }
                }

                $this->import_media( $post_id, (string)$property_attributes['id'], 'floorplan', $media, false );

                // Media - Brochures
                $media = array();
                if (isset($property->files) && !empty($property->files))
                {
                    foreach ($property->files as $files)
                    {
                        if (!empty($files->file))
                        {
                            foreach ($files->file as $file)
                            {
                                $file_attributes = $file->attributes();

                                if ( (string)$file_attributes['type'] == '7' )
                                {
                                    $url = (string)$file->url;
                                    $explode_url = explode("?", $url);
                                    $url = $explode_url[0];

                                    $media[] = array(
                                        'url' => $url,
                                        'description' => (string)$file->name,
                                    );
                                }
                            }
                        }
                    }
                }

                $this->import_media( $post_id, (string)$property_attributes['id'], 'brochure', $media, false );

                // Media - EPCs
                $media = array();
                if (isset($property->files) && !empty($property->files))
                {
                    foreach ($property->files as $files)
                    {
                        if (!empty($files->file))
                        {
                            foreach ($files->file as $file)
                            {
                                $file_attributes = $file->attributes();

                                if ( (string)$file_attributes['type'] == '9' )
                                {
                                    $url = (string)$file->url;
                                    $explode_url = explode("?", $url);
                                    $url = $explode_url[0];

                                    $media[] = array(
                                        'url' => $url,
                                        'description' => (string)$file->name,
                                    );
                                }
                            }
                        }
                    }
                }

                $this->import_media( $post_id, (string)$property_attributes['id'], 'epc', $media, false );

                // Media - Virtual Tours
                $virtual_tours = array();
                if (isset($property->files) && !empty($property->files))
                {
                    foreach ($property->files as $files)
                    {
                        if (!empty($files->file))
                        {
                            foreach ($files->file as $file)
                            {
                                $file_attributes = $file->attributes();

                                if ( 
                                    (string)$file_attributes['type'] == '11' &&
                                    (
                                        substr( strtolower((string)$file->url), 0, 2 ) == '//' || 
                                        substr( strtolower((string)$file->url), 0, 4 ) == 'http'
                                    )
                                )
                                {
                                    $virtual_tours[] = array(
                                        'url' => (string)$file->url,
                                        'label' => isset($file->name) ? (string)$file->name : '',
                                    );
                                }
                            }
                        }
                    }
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
                    update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property_attributes['id'] );

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_vebra_api_xml", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, (string)$property_attributes['id'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;

        } // end foreach property

        do_action( "propertyhive_post_import_properties_vebra_api_xml" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $property_attributes = $property->attributes();
            
            $import_refs[] = (string)$property_attributes['id'];
        }

        $this->do_remove_old_properties( $import_refs );

        unset($import_refs);
    }

    public function get_default_mapping_values()
    {
        return array(
            'sales_availability' => array(
                '0' => 'For Sale',
                '1' => 'Under Offer',
                '2' => 'Sold',
                '3' => 'SSTC',
                '4' => 'For Sale By Auction',
                '5' => 'Reserved',
                '6' => 'New Instruction',
                '7' => 'Just on Market',
                '8' => 'Price Reduction',
                '9' => 'Keen to Sell',
                '10' => 'No Chain',
                '11' => 'Vendor will pay stamp duty',
                '12' => 'Offers in the region of',
                '13' => 'Guide Price',
                '200' => 'For Sale',
                '201' => 'Under Offer',
                '202' => 'Sold',
                '203' => 'SSTC',
            ),
            'lettings_availability' => array(
                '0' => 'To Let',
                '1' => 'Let',
                '2' => 'Under Offer',
                '3' => 'Reserved',
                '4' => 'Let Agreed',
                '100' => 'To Let',
                '101' => 'Let',
                '102' => 'Under Offer',
                '103' => 'Reserved',
                '104' => 'Let Agreed',
                '200' => 'To Let',
                '214' => 'Let',
            ),
            'commercial_availability' => array(
                '0' => 'Sales: For Sale / Lettings: To Let',
                '1' => 'Sales: Under Offer / Lettings: Let',
                '2' => 'Sales: Sold / Lettings: Under Offer',
                '3' => 'Sales: SSTC / Lettings: Reserved',
                '4' => 'Sales: For Sale By Auction / Lettings: Let Agreed',
                '5' => 'Sales: Reserved',
                '6' => 'Sales: New Instruction',
                '7' => 'Sales: Just on Market',
                '8' => 'Sales: Price Reduction',
                '9' => 'Sales: Keen to Sell',
                '10' => 'Sales: No Chain',
                '11' => 'Sales: Vendor will pay stamp duty',
                '12' => 'Sales: Offers in the region of',
                '13' => 'Sales: Guide Price',
                '100' => 'To Let',
                '101' => 'Let',
                '102' => 'Under Offer',
                '103' => 'Reserved',
                '104' => 'Let Agreed',
                '200' => 'Sales: For Sale / Lettings: To Let',
                '201' => 'Sales: Under Offer',
                '202' => 'Sales: Sold',
                '203' => 'Sales: SSTC',
                '214' => 'Lettings: Let',
                '255' => 'Not Marketed',
            ),
            'property_type' => array(
                'House' => 'House',
                'Flat' => 'Flat',
            ),
            'commercial_property_type' => array(
                'Commercial' => 'Commercial',
            ),
            'price_qualifier' => array(
                'Asking Price' => 'Asking Price',
                'Auction Guide' => 'Auction Guide',
                'Best And Final Offers' => 'Best And Final Offers',
                'Best Offers Around' => 'Best Offers Around',
                'Best Offers Over' => 'Best Offers Over',
                'By Auction' => 'By Auction',
                'By Public Auction' => 'By Public Auction',
                'Circa' => 'Circa',
                'Fixed Asking Price' => 'Fixed Asking Price',
                'Fixed price' => 'Fixed price',
                'Guide Price' => 'Guide Price',
                'No Offers' => 'No Offers',
                'O.I.R.O' => 'O.I.R.O',
                'Offers Around' => 'Offers Around',
                'Offers Based On' => 'Offers Based On',
                'Offers In Excess Of' => 'Offers In Excess Of',
                'Offers In The Region Of' => 'Offers In The Region Of',
                'Offers Invited' => 'Offers Invited',
                'Offers Over' => 'Offers Over',
                'Open To Offers' => 'Open To Offers',
                'Or Nearest Offer' => 'Or Nearest Offer',
                'Part Exchange Considered' => 'Part Exchange Considered',
                'POA' => 'POA',
                'Price Guide' => 'Price Guide',
                'Price On Application' => 'Price On Application',
                'Prices From' => 'Prices From',
            ),
            'tenure' => array(
                '0' => 'Unspecified',
                '1' => 'Freehold',
                '2' => 'Leasehold',
                '3' => 'Leasehold With Share Of Freehold',
                '6' => 'Share of Freehold',
                '4' => 'Flying Freehold',
                '5' => 'Commonhold ',
                '7' => 'Non-Traditional',
            ),
            'parking' => array(
                'DoubleGarage' => 'DoubleGarage',
                'OffStreetParking' => 'OffStreetParking',
                'ResidentsParking' => 'ResidentsParking',
                'SingleGarage' => 'SingleGarage',
                'Underground' => 'Underground',
                'CommunalCarParkAllocatedSpace' => 'CommunalCarParkAllocatedSpace',
                'CommunalCarParkNoAllocatedSpace' => 'CommunalCarParkNoAllocatedSpace',
                'DisabledParkingAvailable' => 'DisabledParkingAvailable',
                'DisabledParkingNotAvailable' => 'DisabledParkingNotAvailable',
                'DrivewayPrivate' => 'DrivewayPrivate',
                'DrivewayShared' => 'DrivewayShared',
                'EvChargingPrivate' => 'EvChargingPrivate',
                'EvChargingShared' => 'EvChargingShared',
                'Garage' => 'Garage',
                'GarageBloc' => 'GarageBloc',
                'GarageCarport' => 'GarageCarport',
                'GarageDetached' => 'GarageDetached',
                'GarageIntegral' => 'GarageIntegral',
                'GatedParking' => 'GatedParking',
                'NoParkingAvailable' => 'NoParkingAvailable',
                'RearOfProperty' => 'RearOfProperty',
                'StreetParkingPermitNotRequired' => 'StreetParkingPermitNotRequired',
                'StreetParkingPermitRequired' => 'StreetParkingPermitRequired',
                'Undercroft' => 'Undercroft',
                'UndergroundParkingAllocatedSpace' => 'UndergroundParkingAllocatedSpace',
                'UndergroundParkingNoAllocatedSpace' => 'UndergroundParkingNoAllocatedSpace',
                'Other' => 'Other'
            ),
            'furnished' => array(
                '0' => 'Furnished',
                '1' => 'Part Furnished',
                '2' => 'Un-Furnished',
                '4' => 'Furnished / Un-Furnished',
            )
        );
    }
}

}