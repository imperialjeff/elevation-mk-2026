<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * RTDF Functions
 */
class PH_Property_Import_RTDF {

	public function __construct() {

        add_action( 'init', array( $this, 'rtdf_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'rtdf_query_vars' ) );
        add_action( 'parse_request', array( $this, 'run_rtdf_import' ), 99 );
        
	}

    public function rtdf_rewrite_rules()
    {
        $import_options = get_option( 'propertyhive_property_import' );
        if ( is_array($import_options) && !empty($import_options) )
        {
            foreach ( $import_options as $import_id => $option )
            {
                if ( isset($option['deleted']) && $option['deleted'] == 1 )
                {
                    continue;
                }
                
                if ( isset($option['format']) && $option['format'] == 'rtdf' )
                {
                    $send_endpoint = trim( ( isset($option['send_endpoint']) ? $option['send_endpoint'] : 'sendpropertydetails/' . $import_id ), '/' );
                    if ( $send_endpoint != '' )
                    {
                        add_rewrite_rule(
                            $send_endpoint . '/?$',
                            'index.php?rtdf=1&rtdf_action=sendpropertydetails&import_id=' . $import_id,
                            'top'
                        );
                    }

                    $remove_endpoint = trim( ( isset($option['remove_endpoint']) ? $option['remove_endpoint'] : 'removeproperty/' . $import_id ), '/' );
                    if ( $remove_endpoint != '' )
                    {
                        add_rewrite_rule(
                            $remove_endpoint . '/?$',
                            'index.php?rtdf=1&rtdf_action=removeproperty&import_id=' . $import_id,
                            'top'
                        );
                    }

                    $get_endpoint = trim( ( isset($option['get_endpoint']) ? $option['get_endpoint'] : 'getbranchpropertylist/' . $import_id ), '/' );
                    if ( $get_endpoint != '' )
                    {
                        add_rewrite_rule(
                            $get_endpoint . '/?$',
                            'index.php?rtdf=1&rtdf_action=getbranchpropertylist&import_id=' . $import_id,
                            'top'
                        );
                    }

                    $emails_endpoint = trim( ( isset($option['emails_endpoint']) ? $option['emails_endpoint'] : 'getbranchemails/' . $import_id ), '/' );
                    if ( $emails_endpoint != '' )
                    {
                        add_rewrite_rule(
                            $emails_endpoint . '/?$',
                            'index.php?rtdf=1&rtdf_action=getbranchemails&import_id=' . $import_id,
                            'top'
                        );
                    }
                }
            }
        }

        add_rewrite_rule(
            '^sendpropertydetails/([0-9]+)[/]?',
            'index.php?rtdf=1&rtdf_action=sendpropertydetails&import_id=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^removeproperty/([0-9]+)[/]?',
            'index.php?rtdf=1&rtdf_action=removeproperty&import_id=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^getbranchpropertylist/([0-9]+)[/]?',
            'index.php?rtdf=1&rtdf_action=getbranchpropertylist&import_id=$matches[1]',
            'top'
        );

        add_rewrite_rule(
            '^getbranchemails/([0-9]+)[/]?',
            'index.php?rtdf=1&rtdf_action=getbranchemails&import_id=$matches[1]',
            'top'
        );
    }

    public function rtdf_query_vars( $query_vars ){
        $query_vars[] = 'rtdf';
        $query_vars[] = 'rtdf_action';
        $query_vars[] = 'import_id';
        return $query_vars;
    }

    public $last_xml_node = '';

    // https://stackoverflow.com/a/5965940/762994
    private function ph_array_to_xml( $data, &$xml_data, $multiple_nodes = array() ) 
    {
        foreach ( $data as $key => $value ) 
        {
            if ( is_array($value) ) 
            {
                if ( in_array($key, $multiple_nodes) && !empty($value) )
                {
                    $this->last_xml_node = $key;
                    $this->ph_array_to_xml($value, $xml_data, $multiple_nodes);
                }
                else
                {
                    if ( is_numeric($key) )
                    {
                        $key = $this->last_xml_node;
                    }
                    else
                    {
                        if ( $this->last_xml_node == '' ) { $this->last_xml_node = $key; }
                    }
                    $subnode = $xml_data->addChild($key);
                    $this->ph_array_to_xml($value, $subnode, $multiple_nodes);
                }
            }
            else
            {
                if ( is_bool($value) ) 
                { 
                    $xml_data->addChild("$key", $value === true ? 'true' : 'false' ); 
                }
                else
                {
                    $xml_data->addChild("$key", htmlspecialchars("$value")); 
                }
            }
         }
    }

    public function run_rtdf_import($query)
    {
        $request_timestamp = current_datetime();
        $request_timestamp = $request_timestamp->format("d-m-Y H:i:s");

        if (
            isset($query->query_vars['rtdf']) && 
            $query->query_vars['rtdf'] == '1' && 
            isset($query->query_vars['rtdf_action']) && 
            in_array( $query->query_vars['rtdf_action'], array('sendpropertydetails', 'removeproperty', 'getbranchpropertylist', 'getbranchemails') )
        )
        {
            if (
                isset($query->query_vars['import_id']) && 
                !empty((int)$query->query_vars['import_id'])
            )
            {
                global $wpdb;

                $body = file_get_contents('php://input');
                $original_body = $body;

                $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                $current_date = $current_date->format("Y-m-d H:i:s");

                $wpdb->insert( 
                    $wpdb->prefix . "ph_propertyimport_instance_v3", 
                    array(
                        'import_id' => (int)$query->query_vars['import_id'],
                        'start_date' => $current_date,
                        'media' => 0
                    )
                );
                $instance_id = $wpdb->insert_id;

                $wpdb->insert( 
                    $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                    array(
                        'instance_id' => $instance_id,
                        'post_id' => 0,
                        'crm_id' => '',
                        'severity' => 0,
                        'entry' => 'Received ' . $query->query_vars['rtdf_action'] . ' request',
                        'log_date' => $current_date,
                        'received_data' => $original_body
                    )
                );

                $is_xml_request = false;
                
                $xml = @simplexml_load_string($body, "SimpleXMLElement", LIBXML_NOCDATA);

                if ( $xml !== FALSE )
                {
                    $is_xml_request = true;

                    $xpath = '//*[not(normalize-space())]';
                    foreach (array_reverse($xml->xpath($xpath)) as $remove) {
                        unset($remove[0]);
                    }

                    // we've been sent XML. Convert it to JSON
                    $body = json_encode($xml);

                    if ($body === false) 
                    {
                        $error = 'JSON encoding error: ' . json_last_error() . ' - ' . json_last_error_msg();

                        // log instance end
                        $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                        $current_date = $current_date->format("Y-m-d H:i:s");

                        $wpdb->insert( 
                            $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                            array(
                                'instance_id' => $instance_id,
                                'post_id' => 0,
                                'crm_id' => '',
                                'severity' => 1,
                                'entry' => $error,
                                'log_date' => $current_date
                            )
                        );

                        $wpdb->update( 
                            $wpdb->prefix . "ph_propertyimport_instance_v3", 
                            array( 
                                'end_date' => $current_date
                            ),
                            array( 'id' => $instance_id )
                        );

                        $current_date = current_datetime();
                        $current_date = $current_date->format("d-m-Y H:i:s");
                        
                        $return = array(
                            'request_id' => $instance_id,
                            'message' => $error,
                            'success' => false,
                            'request_timestamp' => $request_timestamp,
                            'response_timestamp' => $current_date,
                            'errors' => array( 
                                 array(
                                    'error_code' => 2,
                                    'error_description' => $error,
                                    'error_value' => '',
                                ),
                            ),
                        );
                        if ( $return_format == 'xml' )
                        {
                            if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                            if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                            $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                            $this->ph_array_to_xml($return, $xml_data);
                            header('Content-Type: text/xml');
                            $xml = $xml_data->asXML();
                            //$xml = str_replace("<propertyActionResponse>", "", $xml);
                            //$xml = str_replace("</propertyActionResponse>", "", $xml);
                            echo $xml;
                        }
                        else
                        {
                            header( 'Content-Type: application/json; charset=utf-8' );
                            echo json_encode($return);
                        }
                        die();
                    }
                }

                // Converts it into a PHP array
                $json = json_decode($body, TRUE);

                $return_format = 'json';
                if ( $is_xml_request && extension_loaded('simplexml') )
                {
                    $return_format = 'xml';
                }

                // verify import ID passed
                $import_options = get_option( 'propertyhive_property_import' );
                if ( !isset($import_options[(int)$query->query_vars['import_id']]) )
                {
                    // return error about import not existing
                    $error = 'Import ' . (int)$query->query_vars['import_id'] . ' not found';

                    // log instance end
                    $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                    $current_date = $current_date->format("Y-m-d H:i:s");

                    $wpdb->insert( 
                        $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                        array(
                            'instance_id' => $instance_id,
                            'post_id' => 0,
                            'crm_id' => '',
                            'severity' => 1,
                            'entry' => $error,
                            'log_date' => $current_date
                        )
                    );

                    $wpdb->update( 
                        $wpdb->prefix . "ph_propertyimport_instance_v3", 
                        array( 
                            'end_date' => $current_date
                        ),
                        array( 'id' => $instance_id )
                    );

                    $current_date = current_datetime();
                    $current_date = $current_date->format("d-m-Y H:i:s");
                    
                    $return = array(
                        'request_id' => $instance_id,
                        'message' => $error,
                        'success' => false,
                        'request_timestamp' => $request_timestamp,
                        'response_timestamp' => $current_date,
                        'errors' => array(
                            array(
                                'error_code' => 1,
                                'error_description' => $error,
                                'error_value' => '',
                            ),
                        ),
                    );
                    if ( $return_format == 'xml' )
                    {
                        if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                        if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                        $xml_data = '<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>';
                        $this->ph_array_to_xml($return, $xml_data);
                        header('Content-Type: text/xml');
                        $xml = $xml_data->asXML();
                        //$xml = str_replace("<propertyActionResponse>", "", $xml);
                        //$xml = str_replace("</propertyActionResponse>", "", $xml);
                        echo $xml;
                    }
                    else
                    {
                        header( 'Content-Type: application/json; charset=utf-8' );
                        echo json_encode($return);
                    }
                    die();
                }

                // return error about import being paused
                if ( 
                    isset($import_options[(int)$query->query_vars['import_id']]['response_format']) &&
                    $import_options[(int)$query->query_vars['import_id']]['response_format'] != '' &&
                    in_array($import_options[(int)$query->query_vars['import_id']]['response_format'], array('json', 'xml'))
                )
                {
                    $return_format = $import_options[(int)$query->query_vars['import_id']]['response_format'];
                }

                if ( $import_options[(int)$query->query_vars['import_id']]['running'] != 1 )
                {
                    // Return error about import not running
                    $error = 'Import ' . (int)$query->query_vars['import_id'] . ' is currently not active. Please try again later.';

                    // log instance end
                    $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                    $current_date = $current_date->format("Y-m-d H:i:s");

                    $wpdb->insert( 
                        $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                        array(
                            'instance_id' => $instance_id,
                            'post_id' => 0,
                            'crm_id' => '',
                            'severity' => 1,
                            'entry' => $error,
                            'log_date' => $current_date
                        )
                    );

                    $wpdb->update( 
                        $wpdb->prefix . "ph_propertyimport_instance_v3", 
                        array( 
                            'end_date' => $current_date
                        ),
                        array( 'id' => $instance_id )
                    );

                    $current_date = current_datetime();
                    $current_date = $current_date->format("d-m-Y H:i:s");
                    
                    $return = array(
                        'request_id' => $instance_id,
                        'message' => $error,
                        'success' => false,
                        'request_timestamp' => $request_timestamp,
                        'response_timestamp' => $current_date,
                        'errors' => array( 
                             array(
                                'error_code' => 2,
                                'error_description' => $error,
                                'error_value' => '',
                            ),
                        ),
                    );
                    if ( $return_format == 'xml' )
                    {
                        if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                        if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                        $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                        $this->ph_array_to_xml($return, $xml_data);
                        header('Content-Type: text/xml');
                        $xml = $xml_data->asXML();
                        //$xml = str_replace("<propertyActionResponse>", "", $xml);
                        //$xml = str_replace("</propertyActionResponse>", "", $xml);
                        echo $xml;
                    }
                    else
                    {
                        header( 'Content-Type: application/json; charset=utf-8' );
                        echo json_encode($return);
                    }
                    die();
                }

                if ( $import_options[(int)$query->query_vars['import_id']]['format'] != 'rtdf' )
                {
                    // return error about import not being the right format
                    $error = 'Import ' . (int)$query->query_vars['import_id'] . ' isn\'t setup to be a real-time feed. Please try again later.';
                    
                    // log instance end
                    $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                    $current_date = $current_date->format("Y-m-d H:i:s");

                    $wpdb->insert( 
                        $wpdb->prefix . "ph_propertyimport_instance_log_v3", 
                        array(
                            'instance_id' => $instance_id,
                            'post_id' => 0,
                            'crm_id' => '',
                            'severity' => 1,
                            'entry' => $error,
                            'log_date' => $current_date
                        )
                    );

                    $wpdb->update( 
                        $wpdb->prefix . "ph_propertyimport_instance_v3", 
                        array( 
                            'end_date' => $current_date
                        ),
                        array( 'id' => $instance_id )
                    );

                    $current_date = current_datetime();
                    $current_date = $current_date->format("d-m-Y H:i:s");
                    
                    $return = array(
                        'request_id' => $instance_id,
                        'message' => $error,
                        'success' => false,
                        'request_timestamp' => $request_timestamp,
                        'response_timestamp' => $current_date,
                        'errors' => array( 
                             array(
                                'error_code' => 3,
                                'error_description' => $error,
                                'error_value' => '',
                            ),
                        ),
                    );
                    if ( $return_format == 'xml' )
                    {
                        $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array();
                        $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array();
                        $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                        $this->ph_array_to_xml($return, $xml_data);
                        header('Content-Type: text/xml');
                        $xml = $xml_data->asXML();
                        //$xml = str_replace("<propertyActionResponse>", "", $xml);
                        //$xml = str_replace("</propertyActionResponse>", "", $xml);
                        echo $xml;
                    }
                    else
                    {
                        header( 'Content-Type: application/json; charset=utf-8' );
                        echo json_encode($return);
                    }
                    die();
                }

                if ( !defined('ALLOW_UNFILTERED_UPLOADS') ) { define( 'ALLOW_UNFILTERED_UPLOADS', true ); }
    
                $keep_logs_days = (string)apply_filters( 'propertyhive_property_import_keep_logs_days', '7' );

                // Revert back to 7 days if anything other than numbers has been passed
                // This prevent SQL injection and errors
                if ( !preg_match("/^\d+$/", $keep_logs_days) )
                {
                    $keep_logs_days = '7';
                }

                // Delete logs older than 7 days
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3 WHERE start_date < DATE_SUB(NOW(), INTERVAL " . $keep_logs_days . " DAY)" );
                $wpdb->query( "DELETE FROM " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 WHERE log_date < DATE_SUB(NOW(), INTERVAL " . $keep_logs_days . " DAY)" );

                // Load Importer API
                require_once ABSPATH . 'wp-admin/includes/import.php';

                if ( ! class_exists( 'WP_Importer' ) ) 
                {
                    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                    if ( file_exists( $class_wp_importer ) ) require_once $class_wp_importer;
                }

                require_once dirname( __FILE__ ) . '/import-formats/class-ph-rtdf-import.php';

                switch ( $query->query_vars['rtdf_action'] )
                {
                    case "sendpropertydetails":
                    {
                        $PH_RTDF_Import = new PH_RTDF_Import( $instance_id, (int)$query->query_vars['import_id'] );

                        $validated = $PH_RTDF_Import->validate_send();

                        if ( $validated === true )
                        {
                            list($post_id, $inserted_updated) = $PH_RTDF_Import->import();

                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            // get any warnings
                            $warnings = array();

                            $logs = $wpdb->get_results( 
                                "
                                SELECT * 
                                FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                                INNER JOIN 
                                    " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 ON  " . $wpdb->prefix . "ph_propertyimport_instance_v3.id = " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.instance_id
                                WHERE 
                                    " . $wpdb->prefix . "ph_propertyimport_instance_v3.id = '" . $instance_id . "'
                                AND
                                    severity = 1
                                "
                            );

                            if ( $logs )
                            {
                                foreach ( $logs as $log ) 
                                {
                                    $warnings[] = array( 
                                        'warning_code' => 1,
                                        'warning_description' => $log->entry,
                                        'warning_value' => '',
                                    );
                                }
                            }

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => 'Success',
                                'success' => true,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'property' => array(
                                    'agent_ref' => get_post_meta( $post_id, '_imported_ref_' . (int)$query->query_vars['import_id'], true ),
                                    'rightmove_id' => $post_id,
                                    'rightmove_url' => get_permalink($post_id),
                                    'change_type' => ( $inserted_updated == 'inserted' ? 'CREATE' : 'UPDATE' ),
                                )
                            );
                            if ( !empty($warnings) )
                            {
                                $return['warnings'] = $warnings;
                            }
                            if ( $return_format == 'xml' )
                            {
                                if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                                if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                                $this->ph_array_to_xml($return, $xml_data, array('warnings'));
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<propertyActionResponse>", "", $xml);
                                //$xml = str_replace("</propertyActionResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        else
                        {
                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $error = $validated;
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => $error,
                                'success' => false,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'errors' => array( 
                                     array(
                                        'error_code' => 4,
                                        'error_description' => $error,
                                        'error_value' => '',
                                    ),
                                ),
                            );
                            if ( $return_format == 'xml' )
                            {
                                if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                                if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                                $this->ph_array_to_xml($return, $xml_data);
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<propertyActionResponse>", "", $xml);
                                //$xml = str_replace("</propertyActionResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }

                        break;
                    }
                    case "removeproperty":
                    {
                        $PH_RTDF_Import = new PH_RTDF_Import( $instance_id, (int)$query->query_vars['import_id'] );

                        $validated = $PH_RTDF_Import->validate_remove();

                        if ( $validated === true )
                        {
                            $post_id = $PH_RTDF_Import->remove();

                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => 'Success',
                                'success' => true,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'property' => array(
                                    'agent_ref' => get_post_meta( $post_id, '_imported_ref_' . (int)$query->query_vars['import_id'], true ),
                                    'rightmove_id' => $post_id,
                                    'rightmove_url' => get_permalink($post_id),
                                    'change_type' => 'DELETE',
                                ),
                            );
                            if ( $return_format == 'xml' )
                            {
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                                $this->ph_array_to_xml($return, $xml_data);
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<propertyActionResponse>", "", $xml);
                                //$xml = str_replace("</propertyActionResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        else
                        {
                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $error = $validated;
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => $error,
                                'success' => false,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'errors' => array( 
                                     array(
                                        'error_code' => 5,
                                        'error_description' => $error,
                                        'error_value' => '',
                                    ),
                                ),
                            );
                            if ( $return_format == 'xml' )
                            {
                                if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                                if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                                $this->ph_array_to_xml($return, $xml_data);
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<propertyActionResponse>", "", $xml);
                                //$xml = str_replace("</propertyActionResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        break;
                    }
                    case "getbranchpropertylist":
                    {
                        $PH_RTDF_Import = new PH_RTDF_Import( $instance_id, (int)$query->query_vars['import_id'] );

                        $validated = $PH_RTDF_Import->validate_get_properties();

                        if ( $validated === true )
                        {
                            $post_ids = $PH_RTDF_Import->get_properties();

                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $properties = array();
                            if ( !empty($post_ids) )
                            {
                                foreach ( $post_ids as $post_id )
                                {
                                    $channel = 1;
                                    if ( get_post_meta( $post_id, '_department', true ) == 'residential-lettings' )
                                    {
                                        $channel = 2;
                                    }
                                    if ( get_post_meta( $post_id, '_department', true ) == 'commercial' && get_post_meta( $post_id, '_to_rent', true ) == 'yes' )
                                    {
                                        $channel = 2;
                                    }
                                    $properties[] = array(
                                        'agent_ref' => get_post_meta( $post_id, '_imported_ref_' . (int)$query->query_vars['import_id'], true ),
                                        'rightmove_id' => $post_id,
                                        'update_date' => get_the_modified_time('d-m-Y H:i:s', $post_id),
                                        'channel' => $channel,
                                    );
                                }
                            }

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => 'Success',
                                'success' => true,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'property' => $properties,
                            );
                            if ( $return_format == 'xml' )
                            {
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><getBranchPropertyListResponse></getBranchPropertyListResponse>');
                                $this->ph_array_to_xml($return, $xml_data, array('property'));
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<getBranchPropertyListResponse>", "", $xml);
                                //$xml = str_replace("</getBranchPropertyListResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        else
                        {
                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $error = $validated;
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => $error,
                                'success' => false,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'errors' => array( 
                                     array(
                                        'error_code' => 6,
                                        'error_description' => $error,
                                        'error_value' => '',
                                    ),
                                ),
                            );
                            if ( $return_format == 'xml' )
                            {
                                if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                                if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><propertyActionResponse></propertyActionResponse>');
                                $this->ph_array_to_xml($return, $xml_data);
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<propertyActionResponse>", "", $xml);
                                //$xml = str_replace("</propertyActionResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        break;
                    }
                    case "getbranchemails":
                    {
                        $PH_RTDF_Import = new PH_RTDF_Import( $instance_id, (int)$query->query_vars['import_id'] );

                        $start_date = date("Y-m-d", strtotime('28 days ago')) . ' 00:00:00';
                        if ( isset($json['export_period']['start_date_time']) && !empty($json['export_period']['start_date_time']) )
                        {
                            $start_date = date("Y-m-d H:i:s", strtotime($json['export_period']['start_date_time']));
                        }

                        $end_date = date("Y-m-d H:i:s");
                        if ( isset($json['export_period']['end_date_time']) && !empty($json['export_period']['end_date_time']) )
                        {
                            $end_date = date("Y-m-d H:i:s", strtotime($json['export_period']['end_date_time']));
                        }

                        $validated = $PH_RTDF_Import->validate_get_emails();

                        if ( $validated === true )
                        {
                            $post_ids = $PH_RTDF_Import->get_emails();

                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $emails = array();

                            if ( !empty($post_ids) )
                            {
                                foreach ( $post_ids as $post_id )
                                {
                                    $enquiry = new PH_Enquiry($post_id);

                                    $property_id = $enquiry->property_id;

                                    $to_email_address = '';

                                    $property_data = array(
                                        'agent_ref' => '',
                                        'rightmove_id' => '',
                                        'rightmove_url' => '',
                                        'price' => '',
                                        'postcode' => '',
                                        'bedrooms' => '',
                                        'property_type' => '',
                                    );

                                    if ( !empty($property_id) )
                                    {
                                        $property = new PH_Property( (int)$property_id );

                                        $property_data = array(
                                            'agent_ref' => get_post_meta( $property_id, '_imported_ref_' . (int)$query->query_vars['import_id'], true ),
                                            'rightmove_id' => $property_id,
                                            'rightmove_url' => get_permalink($property_id),
                                            'price' => $property->_price_actual,
                                            'postcode' => $property->_address_postcode,
                                            'bedrooms' => $property->_bedrooms,
                                            'property_type' => 0 // TO DO: set accordingly based on mappings
                                        );

                                        $property_department = get_post_meta((int)$property_ids[0], '_department', TRUE);
                    
                                        $fields_to_check = array();
                                        switch ( $property_department )
                                        {
                                            case "residential-sales":
                                            {
                                                $fields_to_check[] = '_office_email_address_sales';
                                                $fields_to_check[] = '_office_email_address_lettings';
                                                $fields_to_check[] = '_office_email_address_commercial';
                                                break;
                                            }
                                            case "residential-lettings":
                                            {
                                                $fields_to_check[] = '_office_email_address_lettings';
                                                $fields_to_check[] = '_office_email_address_sales';
                                                $fields_to_check[] = '_office_email_address_commercial';
                                                break;
                                            }
                                            case "commercial":
                                            {
                                                $fields_to_check[] = '_office_email_address_commercial';
                                                $fields_to_check[] = '_office_email_address_lettings';
                                                $fields_to_check[] = '_office_email_address_sales';
                                                break;
                                            }
                                            default:
                                            {
                                                $fields_to_check[] = '_office_email_address_' . str_replace("residential-", "", $property_department);
                                                $fields_to_check[] = '_office_email_address_sales';
                                                $fields_to_check[] = '_office_email_address_lettings';
                                                $fields_to_check[] = '_office_email_address_commercial';
                                                break;
                                            }
                                        }
                                        
                                        foreach ( $fields_to_check as $field_to_check )
                                        {
                                            $to_email_address = get_post_meta($enquiry->office_id, $field_to_check, TRUE);
                                            if ( $to_email_address != '' )
                                            {
                                                break;
                                            }
                                        }

                                        if ( $to_email_address == '' )
                                        {
                                            $to_email_address = get_option( 'admin_email' );
                                        }
                                    }

                                    $first_name = '';
                                    $last_name = '';
                                    $name = $enquiry->name;
                                    if ( !empty($name) )
                                    {
                                        $explode_name = explode(" ", $name, 2);
                                        $first_name = $explode_name[0];
                                        $last_name = isset($explode_name[1]) ? $explode_name[1] : '';
                                    }

                                    $from_email_address = $enquiry->email_address;
                                    if ( empty($from_email_address) )
                                    {
                                        $from_email_address = $enquiry->email;
                                    }

                                    $emails[] = array(
                                        'email_id' => $post_id,
                                        'from_address' => $from_email_address,
                                        'to_address' => $to_email_address,
                                        'email_date' => get_the_time('d-m-Y H:i:s', $post_id),
                                        'email_types' => array(6),
                                        'user' => array(
                                            'user_contact_details' => array(
                                                //'title' => '',
                                                'first_name' => $first_name,
                                                'last_name' => $last_name,
                                                'phone_day' => $enquiry->telephone,
                                                'phone_evening' => $enquiry->telephone,
                                            ),
                                            'user_information' => (object)array()
                                        ),
                                        'property' => $property_data,
                                    );
                                }
                            }

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => 'Success',
                                'success' => true,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'export_period' => array(
                                    'start_date_time' => date("d-m-Y H:i:s", strtotime($start_date)),
                                    'end_date_time' => date("d-m-Y H:i:s", strtotime($end_date))
                                ),
                                'emails' => $emails,
                            );
                            if ( $return_format == 'xml' )
                            {
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><getBranchEmailsResponse></getBranchEmailsResponse>');
                                $this->ph_array_to_xml($return, $xml_data, array('emails'));
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<getBranchEmailsResponse>", "", $xml);
                                //$xml = str_replace("</getBranchEmailsResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        else
                        {
                            // log instance end
                            $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                            $current_date = $current_date->format("Y-m-d H:i:s");

                            $wpdb->update( 
                                $wpdb->prefix . "ph_propertyimport_instance_v3", 
                                array( 
                                    'end_date' => $current_date
                                ),
                                array( 'id' => $instance_id )
                            );

                            $current_date = current_datetime();
                            $current_date = $current_date->format("d-m-Y H:i:s");
                            $error = $validated;
                            $return = array(
                                'request_id' => $instance_id,
                                'message' => $error,
                                'success' => false,
                                'request_timestamp' => $request_timestamp,
                                'response_timestamp' => $current_date,
                                'export_period' => array(
                                    'start_date_time' => date("d-m-Y H:i:s", strtotime($start_date)),
                                    'end_date_time' => date("d-m-Y H:i:s", strtotime($end_date))
                                ),
                                'errors' => array( 
                                     array(
                                        'error_code' => 7,
                                        'error_description' => $error,
                                        'error_value' => '',
                                    ),
                                ),
                            );
                            if ( $return_format == 'xml' )
                            {
                                if ( isset($return['warnings']) ) { $return['warnings'] = !empty( $return['warnings'] ) ? $return['warnings'][0] : array(); }
                                if ( isset($return['errors']) ) { $return['errors'] = !empty( $return['errors'] ) ? $return['errors'][0] : array(); }
                                $xml_data = new SimpleXMLElement('<?xml version="1.0"?><getBranchEmailsResponse></getBranchEmailsResponse>');
                                $this->ph_array_to_xml($return, $xml_data);
                                header('Content-Type: text/xml');
                                $xml = $xml_data->asXML();
                                //$xml = str_replace("<getBranchEmailsResponse>", "", $xml);
                                //$xml = str_replace("</getBranchEmailsResponse>", "", $xml);
                                echo $xml;
                            }
                            else
                            {
                                header( 'Content-Type: application/json; charset=utf-8' );
                                echo json_encode($return);
                            }
                            die();
                        }
                        break;
                    }
                }

                // log instance end
                $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                $current_date = $current_date->format("Y-m-d H:i:s");

                $wpdb->update( 
                    $wpdb->prefix . "ph_propertyimport_instance_v3", 
                    array( 
                        'end_date' => $current_date
                    ),
                    array( 'id' => $instance_id )
                );
            }
            else
            {
                // throw error about no import id being passed and to check the endpoints
            }

            die();
        }
    }

}

new PH_Property_Import_RTDF();