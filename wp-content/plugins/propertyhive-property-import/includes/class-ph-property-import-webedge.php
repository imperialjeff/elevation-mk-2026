<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Webedge Functions
 */
class PH_Property_Import_Webedge {

	public function __construct() {

        add_action( 'init', array( $this, 'webedge_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'webedge_query_vars' ) );
        add_action( 'parse_request', array( $this, 'run_webedge_import' ), 99 );
        
	}

    public function webedge_rewrite_rules()
    {
        add_rewrite_rule(
            '^webedge-send-property/?',
            'index.php?webedge=1',
            'top'
        );
    }

    public function webedge_query_vars( $query_vars ){
        $query_vars[] = 'webedge';
        return $query_vars;
    }

    public function run_webedge_import($query)
    {
        if (
            isset($query->query_vars['webedge']) && 
            $query->query_vars['webedge'] == '1'
        )
        {
            global $wpdb;

            // Load Importer API
            require_once ABSPATH . 'wp-admin/includes/import.php';

            if ( ! class_exists( 'WP_Importer' ) ) 
            {
                $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                if ( file_exists( $class_wp_importer ) ) require_once $class_wp_importer;
            }

            require_once dirname( __FILE__ ) . '/import-formats/class-ph-webedge-xml-import.php';

            $webedge_import_id = false;
            $webedge_options = false;

            $import_options = get_option( 'propertyhive_property_import' );
            if ( is_array($import_options) && !empty($import_options) )
            {
                foreach ( $import_options as $import_id => $options )
                {
                    if ( $options['format'] != 'xml_webedge' )
                    {
                        continue;
                    }

                    if ( isset($options['deleted']) && $options['deleted'] == 1 )
                    {
                        continue;
                    }

                    if ( $options['running'] != 1 )
                    {
                        continue;
                    }

                    $webedge_import_id = $import_id;
                    $webedge_options = $options;
                    break;
                }
            }

            if ( $webedge_import_id === FALSE )
            {
                // Service unavailable
                echo '<?xml version="1.0" encoding="UTF-8"?>
                <response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://feeds.propertynews.com/schemas/response.xsd" id="" action="" agent="">
                  <result>120</result>
                  <message>Import not active</message>
                  <secret></secret>
                </response>';

                die();
            }
            else
            {
                $import_id = $webedge_import_id;
                $options = $webedge_options;

                // log instance start
                $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
                $current_date = $current_date->format("Y-m-d H:i:s");

                $wpdb->insert( 
                    $wpdb->prefix . "ph_propertyimport_instance_v3", 
                    array(
                        'import_id' => $import_id,
                        'start_date' => $current_date,
                        'media' => 0
                    )
                );
                $instance_id = $wpdb->insert_id;

                $PH_WebEDGE_XML_Import = new PH_WebEDGE_XML_Import( $instance_id, $import_id );

                $PH_WebEDGE_XML_Import->log('Validating request');

                $property = $PH_WebEDGE_XML_Import->validate( $options );

                // Ok to import
                if ( $property !== FALSE )
                {
                    $PH_WebEDGE_XML_Import->properties[] = $property;

                    $PH_WebEDGE_XML_Import->log('Validation successful. Processing property');

                    $property_attributes = $property->attributes();

                    $new_secret = md5($property->secret . $options['shared_secret']);

                    if ( 
                        $property_attributes['action'] == 'DELETE' ||
                        (
                            $property_attributes['action'] != 'DELETE' &&
                            isset($property->status) &&
                            in_array( (string)$property->status, apply_filters( 'propertyhive_webedge_off_market_statuses', array('On Hold', 'Draft', 'Let', 'Sold', 'Withdrawn') ) ) 
                        )
                    )
                    {
                        $PH_WebEDGE_XML_Import->log('Removing property');

                        $PH_WebEDGE_XML_Import->remove();

                        echo '<?xml version="1.0" encoding="UTF-8"?>
                        <response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://feeds.propertynews.com/schemas/response.xsd" id="' . (string)$property_attributes['id'] . '" action="' . (string)$property_attributes['action'] . '" agent="' . (string)$property_attributes['agent'] . '">
                          <result>00</result>
                          <message>Processed OK</message>
                          <secret>' . $new_secret . '</secret>
                        </response>';
                    }
                    else
                    {
                        $PH_WebEDGE_XML_Import->import();

                        echo '<?xml version="1.0" encoding="UTF-8"?>
                        <response xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:noNamespaceSchemaLocation="http://feeds.propertynews.com/schemas/response.xsd" id="' . (string)$property_attributes['id'] . '" action="' . (string)$property_attributes['action'] . '" agent="' . (string)$property_attributes['agent'] . '">
                          <result>00</result>
                          <message>Processed OK</message>
                          <secret>' . $new_secret . '</secret>
                        </response>';
                    }
                }
                else
                {
                    $PH_WebEDGE_XML_Import->log_error('Validation failed');
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
            
            die();
        }
    }

}

new PH_Property_Import_Webedge();