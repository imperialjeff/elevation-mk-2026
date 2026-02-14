<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Property Import AJAX Functions
 */
class PH_Property_Import_Ajax {

    public function __construct() 
    {
        add_action( "wp_ajax_propertyhive_property_import_fetch_xml_nodes", array( $this, "fetch_xml_nodes" ) );

        add_action( "wp_ajax_propertyhive_property_import_fetch_csv_fields", array( $this, "fetch_csv_fields" ) );

        add_action( "wp_ajax_propertyhive_property_import_draw_automatic_imports_table", array( $this, "draw_automatic_imports_table" ) );

        add_action( "wp_ajax_propertyhive_property_import_get_running_status", array( $this, "get_running_status" ) );

        add_action( 'wp_ajax_propertyhive_dismiss_notice_jupix_export_enquiries', array( $this, 'ajax_propertyhive_dismiss_notice_jupix_export_enquiries' ) );
        add_action( 'wp_ajax_propertyhive_dismiss_notice_loop_export_enquiries', array( $this, 'ajax_propertyhive_dismiss_notice_loop_export_enquiries' ) );
        add_action( 'wp_ajax_propertyhive_dismiss_notice_arthur_online_export_enquiries', array( $this, 'ajax_propertyhive_dismiss_notice_arthur_online_export_enquiries' ) );

        add_action( 'wp_ajax_propertyhive_test_property_import_details', array( $this, 'ajax_propertyhive_test_property_import_details' ) );

        add_action( 'wp_ajax_propertyhive_property_import_search_properties', array( $this, 'ajax_propertyhive_search_properties' ) );

        add_action( 'wp_ajax_propertyhive_check_for_property_in_import', array( $this, 'ajax_propertyhive_check_for_property_in_import' ) );

        add_action( 'wp_ajax_propertyhive_check_for_property_not_removed', array( $this, 'ajax_propertyhive_check_for_property_not_removed' ) );

        add_action( 'wp_ajax_propertyhive_property_import_get_format_default_mapping_values', array( $this, "get_format_default_mapping_values" ) );

        add_action( "wp_ajax_propertyhive_property_import_kill_import", array( $this, "kill_import" ) );
    }

    public function fetch_xml_nodes()
    {
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( !wp_verify_nonce( sanitize_text_field(wp_unslash($_GET['ajax_nonce'])), "phpi_ajax_nonce" ) ) 
        {
            $return = array(
                'success' => false,
                'error' => __( 'Invalid nonce provided', 'propertyhive' )
            );
            echo wp_json_encode($return);
            die();
        } 

        // nonce ok. Let's get the XML

        $contents = '';

        $args = array( 'timeout' => 120, 'sslverify' => false );
        $args = apply_filters( 'propertyhive_property_import_xml_request_args', $args, sanitize_url($_GET['url']) );
        $response = wp_remote_get( sanitize_url($_GET['url']), $args );
        if ( !is_wp_error($response) && is_array( $response ) ) 
        {
            $contents = $response['body'];
        }
        else
        {
            $error = __( 'Failed to obtain XML. Dump of response as follows', 'propertyhive' ) . ': ' . print_r($response, TRUE);
            if ( is_wp_error($response) )
            {
                $error = $response->get_error_message();
            }
            $return = array(
                'success' => false,
                'error' => $error
            );
            echo wp_json_encode($return);
            die();
        }

        $xml = simplexml_load_string($contents);

        if ($xml !== FALSE)
        {
            $node_names = propertyhive_property_import_get_all_node_names($xml, array_merge(array(''), $xml->getNamespaces(true)));
            $node_names = array_unique($node_names);

            $return = array(
                'success' => true,
                'nodes' => $node_names
            );
            echo wp_json_encode($return);
            die();
        }
        else
        {
            // Failed to parse XML
            $return = array(
                'success' => false,
                'error' => __( 'Failed to parse XML file', 'propertyhive' ) . ': ' . print_r($contents, TRUE)
            );
            echo wp_json_encode($return);
            die();
        }

        wp_die();
    }

    public function fetch_csv_fields()
    {
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( !wp_verify_nonce( sanitize_text_field(wp_unslash($_GET['ajax_nonce'])), "phpi_ajax_nonce" ) ) 
        {
            $return = array(
                'success' => false,
                'error' => __( 'Invalid nonce provided', 'propertyhive' )
            );
            echo wp_json_encode($return);
            die();
        } 

        // nonce ok. Let's get the XML

        $contents = '';

        $args = array( 'timeout' => 120, 'sslverify' => false );
        $args = apply_filters( 'propertyhive_property_import_csv_request_args', $args, sanitize_url($_GET['url']) );
        $response = wp_remote_get( sanitize_url($_GET['url']), $args );
        if ( !is_wp_error($response) && is_array( $response ) ) 
        {
            $contents = $response['body'];
        }
        else
        {
            $error = __( 'Failed to obtain CSV. Dump of response as follows', 'propertyhive' ) . ': ' . print_r($response, TRUE);
            if ( is_wp_error($response) )
            {
                $error = $response->get_error_message();
            }
            $return = array(
                'success' => false,
                'error' => $error
            );
            echo wp_json_encode($return);
            die();
        }

        $encoding = mb_detect_encoding($contents, 'UTF-8, ISO-8859-1', true);
        if ( $encoding !== 'UTF-8' )
        {
            $contents = mb_convert_encoding($contents, 'UTF-8', $encoding);
        }

        $lines = explode( "\n", $contents );
        $headers = str_getcsv( array_shift( $lines ), ( isset($_GET['delimiter']) ? sanitize_text_field($_GET['delimiter']) : ',' ) );

        $return = array(
            'success' => true,
            'fields' => $headers
        );
        echo wp_json_encode($return);

        wp_die();
    }

    public function draw_automatic_imports_table()
    {
        if ( !wp_verify_nonce( sanitize_text_field(wp_unslash($_GET['ajax_nonce'])), "phpi_ajax_nonce" ) ) 
        {
            echo 'Failed to verify nonce. Please reload the page';
            die();
        }

        include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/class-ph-property-import-automatic-imports-table.php' );

        $automatic_imports_table = new PH_Property_Import_Automatic_Imports_Table();
        $automatic_imports_table->prepare_items();

        $automatic_imports_table->display();

        wp_die();
    }

    public function get_running_status()
    {
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( !wp_verify_nonce( sanitize_text_field(wp_unslash($_GET['ajax_nonce'])), "phpi_ajax_nonce" ) ) 
        {
            $return = array(
                'success' => false,
                'error' => __( 'Invalid nonce provided', 'propertyhive' )
            );
            echo wp_json_encode($return);
            die();
        }

        if ( !isset($_GET['import_ids']) || empty($_GET['import_ids']) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'No import ID(s) passed', 'propertyhive' )
            );
            echo wp_json_encode($return);
            die();
        }

        global $wpdb;

        $statuses = array();

        $failed = false;

        $media_processing = get_option( 'propertyhive_property_import_media_processing', '' );

        $queued_media = array();
        if ( $media_processing == 'background' )
        {
            $media_queue_counts = $wpdb->get_results(
                "
                SELECT 
                    `import_id`, 
                    COUNT(DISTINCT `post_id`, `media_type`, `media_order`) AS `queued_media_count` 
                FROM
                    " . $wpdb->prefix . "ph_propertyimport_media_queue 
                GROUP BY 
                `import_id`
                "
            );
            if ( count($media_queue_counts) > 0 )
            {
                foreach ( $media_queue_counts as $media_queue_count )
                {
                    $queued_media[(int)$media_queue_count->import_id] = (int)$media_queue_count->queued_media_count;
                }
            }
        }

        $kills = get_option( 'propertyhive_property_import_kills', '' );

        foreach ( $_GET['import_ids'] as $import_id )
        {
            $import_id = (int)$import_id;

            $import = propertyhive_property_import_get_import_settings_from_id( $import_id );
            if ( $import === false )
            {
                continue;
            }
            $format = propertyhive_property_import_get_import_format( $import['format'] );
            if ( $format === false )
            {
                continue;
            }

            $status = '';

            $row = $wpdb->get_row( $wpdb->prepare("
                SELECT 
                    end_date, status, status_date, media
                FROM 
                    {$wpdb->prefix}ph_propertyimport_instance_v3
                WHERE 
                    import_id = %d
                ORDER BY start_date DESC 
                LIMIT 1
            ", $import_id), ARRAY_A );
            if ( null !== $row )
            {
                if ( isset($row['end_date']) && $row['end_date'] != '0000-00-00 00:00:00' )
                {
                    $statuses[$import_id] = array( 
                        'status' => 'finished', 
                        'queued_media' => ( isset($queued_media[$import_id]) ? $queued_media[$import_id] : '' ) 
                    );
                    continue;
                }

                if ( isset($row['media']) && $row['media'] == '1' )
                {
                    $status = '<br>Importing media';
                    $statuses[$import_id] = array( 
                        'status' => $status, 
                        'queued_media' => ( isset($queued_media[$import_id]) ? $queued_media[$import_id] : '' ) 
                    );
                    continue;
                }

                $decoded_status = json_decode($row['status'], true);

                $kill_link = '';
                if ( isset($decoded_status['status']) && $decoded_status['status'] == 'importing' )
                {
                    $kill_link = '<a href="" class="kill-import" data-import-id="' . (int)$import_id . '"' . ( isset($kills[(int)$import_id]) ? ' disabled="disabled" style="pointer-events:none"' : '' ) . '>' . ( isset($kills[(int)$import_id]) ? esc_html(__( 'Stopping...', 'propertyhive' ) ) : esc_html(__( 'Stop Import', 'propertyhive' ) ) ) . '</a>';
                }

                if ( isset($row['status_date']) && $row['status_date'] != '0000-00-00 00:00:00' && isset($row['status']) && !empty($row['status']) )
                {
                    if ( isset($decoded_status['status']) && $decoded_status['status'] == 'importing' )
                    {
                        if ( ( ( time() - strtotime($row['status_date']) ) / 60 ) < 5 )
                        {
                            $property = isset($decoded_status['property']) ? (int)$decoded_status['property'] : 0;
                            $total = isset($decoded_status['total']) ? (int)$decoded_status['total'] : 1; // Default to 1 to avoid division by zero
                            $progress = ($property / $total) * 100;
                            
                            $status = '
                            <br>Importing property ' . $property . '/' . $total . '
                            <div class="progress-bar-container" style="width: 100%; background-color: #f3f3f3; border-radius: 5px; overflow: hidden; margin-top: 5px;">
                                <div class="progress-bar" style="width: ' . $progress . '%; height: 8px; background-color: #4caf50; text-align: center; line-height: 20px;"></div>
                            </div>' . $kill_link;
                        }
                        else
                        {
                            $status = '<br>Failed to complete';

                            $failed = $import_id;
                        }
                    }
                    elseif ( isset($decoded_status['status']) && $decoded_status['status'] == 'parsing' )
                    {
                        if ( ( ( time() - strtotime($row['status_date']) ) / 60 ) < 5 )
                        {
                            $status = '<br>Parsing properties<br>' . $kill_link;
                        }
                        else
                        {
                            $status = '<br>Failed to complete';

                            $failed = $import_id;
                        }
                    }
                    elseif ( isset($decoded_status['status']) && $decoded_status['status'] == 'removing' )
                    {
                        $status = '<br>Removing properties<br>' . $kill_link;
                    }
                    elseif ( isset($decoded_status['status']) && $decoded_status['status'] == 'finished' )
                    {
                        $status = 'finished';
                    }
                }
            }

            $statuses[$import_id] = array( 
                'status' => $status, 
                'queued_media' => ( isset($queued_media[$import_id]) ? $queued_media[$import_id] : '' ) ,
            );
        }

        if ( $failed !== false )
        {
            $_GET['custom_property_import_cron'] = 'phpropertyimportcronhook';
            $_GET['import_id'] = $failed;

            ob_start();
            do_action('phpropertyimportcronhook');
            ob_end_clean();
        }

        echo wp_json_encode($statuses);

        wp_die();
    }

    public function ajax_propertyhive_check_for_property_not_removed()
    {
        global $wpdb;

        header( 'Content-Type: application/json; charset=utf-8' );

        if ( isset($_POST['import_id']) && !empty($_POST['import_id']) && isset($_POST['post_id']) && !empty($_POST['post_id']) ) 
        {
            $options = get_option( 'propertyhive_property_import' );
            if ( is_array($options) && !empty($options) )
            {
                if ( isset($options[(int)$_POST['import_id']]) )
                {
                    $option = $options[(int)$_POST['import_id']];

                    if ( isset($option['dont_remove']) && $option['dont_remove'] == '1' )
                    {
                        $return = array(
                            'success' => true,
                            'message' => __( 'You have the \'Don\'t remove properties automatically\' setting enabled in the import settings. This means properties removed from the feed won\'t be getting removed.<br><br>Please untick this setting if properties should be getting removed. If issues persist after unticking this option please re-run the troubleshooting wizard.', 'propertyhive' )
                        );
                        echo json_encode($return);
                        die();
                    }
                }
            }

            $post_id = (int)$_POST['post_id'];

            // check not already deleted or off market
            $post_status = get_post_status($post_id);

            if ( $post_status === false || ( $post_status !== false && $post_status != 'publish' ) )
            {
                $return = array(
                    'success' => true,
                    'message' => __( 'This property looks to have already been removed' . ( $post_status !== false ? ' and has a post status of ' . $post_status : '' ) . '. Please provide an example that is published yet not being removed.', 'propertyhive' )
                );
                echo json_encode($return);
                die();
            }

            $on_market = get_post_meta( $post_id, '_on_market', TRUE );
            if ( $on_market == '' )
            {
                $on_market_change_date = get_post_meta( $post_id, '_on_market_change_date', TRUE );

                $return = array(
                    'success' => true,
                    'message' => __( 'This property looks to have already been taken off of the market' . ( !empty($on_market_change_date) ? ' on ' . get_date_from_gmt( $on_market_change_date, "H:i:s jS F Y" ) : '' ) . '. Please provide an example that is on the market yet not being removed.', 'propertyhive' )
                );
                echo json_encode($return);
                die();
            }

            // Property is published and on market
            
            // Check import completing
            $row = $wpdb->get_row( "
                SELECT id, start_date, end_date
                FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                WHERE 
                    import_id = '" . (int)$_POST['import_id'] . "'
                AND
                    media = 0
                ORDER BY start_date DESC 
                LIMIT 1
            ", ARRAY_A);
            if ( null !== $row )
            {
                if ( $row['end_date'] == '0000-00-00 00:00:00' )
                {
                    $return = array(
                        'success' => true,
                        'message' => '<p>This import doesn\'t appear to be completing. As properties are removed at the end of an import running this could well explain why you\'re experiencing issues.</p>
                        <p>For more information on troubleshooting issues with imports not completing please view our docs below:</p>
                        <a href="https://docs.wp-property-hive.com/article/306-troubleshooting#heading-1" target="_blank" class="button button-primary button-hero">View Troubleshooting Documentation <span class="dashicons dashicons-external" style="vertical-align:middle; margin-top:-2px;"></span></a>'
                    );
                    echo json_encode($return);
                    die();
                }
            }
            else
            {
                $keep_logs_days = (string)apply_filters( 'propertyhive_property_import_keep_logs_days', '7' );

                // Revert back to 7 days if anything other than numbers has been passed
                // This prevent SQL injection and errors
                if ( !preg_match("/^\d+$/", $keep_logs_days) )
                {
                    $keep_logs_days = '7';
                }

                $return = array(
                    'success' => true,
                    'message' => '<p>This import hasn\'t ran in at least ' . $keep_logs_days . ' days which could explain why you\'re experiencing issues. Please ensure the import is not paused.</p>
                    <p>If the import is not paused and you continue to experience issues with it not running automatically please view our docs below:</p>
                    <a href="https://docs.wp-property-hive.com/article/295-requirements#heading-0" target="_blank" class="button button-primary button-hero">View Troubleshooting Documentation <span class="dashicons dashicons-external" style="vertical-align:middle; margin-top:-2px;"></span></a>'
                );
                echo json_encode($return);
                die();
            }

            $return = array(
                'success' => true,
                'message' => '<p>We were unabke</p>'
            );
            echo json_encode($return);
            die();
        }
        else
        {
            $return = array(
                'success' => false,
                'error' => __( 'No import ID or property provided', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        wp_die();
    }

    public function ajax_propertyhive_check_for_property_in_import()
    {
        header( 'Content-Type: application/json; charset=utf-8' );

        if ( !isset($_POST['import_id']) || ( isset($_POST['import_id']) && empty($_POST['import_id']) ) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'No import provided', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        if ( !isset($_POST['crm_id']) || ( isset($_POST['crm_id']) && empty($_POST['crm_id']) ) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'No unique property CRM ID provided', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        $import_id = (int)$_POST['import_id'];

        $import_settings = propertyhive_property_import_get_import_settings_from_id( $import_id );

        if ( $import_settings === false )
        {
            $return = array(
                'success' => false,
                'message' => 'Import not found'
            );
            echo json_encode($return);
            die();
        }

        $format_key = isset($import_settings['format']) ? $import_settings['format'] : '';

        if ( empty($format_key) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'No format found', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        $format = propertyhive_property_import_get_import_format( $format_key );

        if ( empty($format) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'No format found 2', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        // XML/CSV - unique field ID
        if ( $format_key == 'xml' )
        {
            $format['id_field'] = $import_settings['property_id_node'];
        }
        if ( $format_key == 'csv' )
        {
            $format['id_field'] = $import_settings['property_id_field'];
        }

        if ( !isset($format['id_field']) || empty($format['id_field']) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'Unique property ID field unknown', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        if ( !isset($format['file']) || !file_exists($format['file']) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'Format PHP file not found', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        list($import_object, $parsed_in_class) = phpi_get_import_object_from_format( $format_key, '', $import_id );

        if ( $import_settings === false )
        {
            $return = array(
                'success' => false,
                'message' => 'Can\'t generate import object'
            );
            echo json_encode($return);
            die();
        }

        $parsed = $import_object->parse( false, true );

        if ( !$parsed )
        {
            $errors = ( isset($import_object->errors) ? $import_object->errors : array() );
            $error = 'Please check they are correct.';
            if ( !empty($errors) )
            {
                $error = $errors[array_key_last($errors)];
                $explode_error = explode(" - ", $error, 2);
                if ( count($explode_error) == 2 )
                {
                    $error = $explode_error[1];
                }
            }

            $return = array(
                'success' => false,
                'error' => 'An error occurred whilst obtaining the properties using the details provided:<br><br>' . nl2br(esc_html($error))
            );
            echo json_encode($return);
            die();
        }
        else
        {
            if ( !empty($import_object->properties) )
            {
                $found_property = false;

                foreach ( $import_object->properties as $property )
                {
                    $property_id = null;
                    if ( is_object($property) )
                    {
                        if ( isset($property->{$format['id_field']}) ) 
                        {
                            $property_id = (string)$property->{$format['id_field']};
                        }
                        else
                        {
                            $property_attributes = $property->attributes();
                            if ( isset($property_attributes[$format['id_field']]) )
                            {
                                $property_id = (string)$property_attributes[$format['id_field']];
                            }
                        }
                    }
                    if ( is_array($property) )  
                    {
                        if ( isset($property[$format['id_field']]) )
                        {
                            $property_id = (string)$property[$format['id_field']];
                        }
                    }

                    if ( $property_id === sanitize_text_field($_POST['crm_id']) ) 
                    {
                        $found_property = true;

                        $additional_message = '';

                        $args = array(
                            'post_type' => 'property',
                            'posts_per_page' => 1,
                            'meta_query' => array(
                                array(
                                    'key' => '_imported_ref_' . (int)$_POST['import_id'],
                                    'value' => sanitize_text_field($_POST['crm_id'])
                                )
                            )
                        );
                        $property_query = new WP_Query($args);

                        if ( $property_query->have_posts() )
                        {
                            while ( $property_query->have_posts() )
                            {
                                $property_query->the_post();

                                $additional_message .= ' and has been already imported with the following data:<br><br>' . ( get_post_meta(get_the_ID(), '_property_import_data', TRUE) != '' ? '<pre style="max-height:200px; overflow-y:auto; background:#EEE; border:1px solid #CCC; padding:15px 20px;">' . htmlentities(get_post_meta(get_the_ID(), '_property_import_data', TRUE)) . '</pre><br>' : '' ) . '<a href="' . esc_url(get_edit_post_link(get_the_ID())) . '" class="button button-primary">View Imported Property</a>';
                            }
                        }
                        else
                        {
                            $additional_message .= ' but doesn\'t appear to have been imported.<br><br>
                            <a href="https://docs.wp-property-hive.com/article/306-troubleshooting#heading-1" target="_blank" class="button button-primary button-hero">View Troubleshooting Documentation <span class="dashicons dashicons-external" style="vertical-align:middle; margin-top:-2px;"></span></a>';
                        }
                        wp_reset_postdata();

                        $return = array(
                            'success' => true,
                            'message' => 'The property with ID \'' . esc_html(sanitize_text_field($_POST['crm_id'])) . '\' exists in the data sent by the CRM' . $additional_message
                        );
                        echo json_encode($return);
                        die();
                    }
                }

                if ( !$found_property )
                {
                    $return = array(
                        'success' => false,
                        'error' => 'The import appears to execute successfully and ' . count($import_object->properties) . ' properties are found, but the property in question isn\'t included.<br><br>We recommend speaking to the CRM to find out why the property is not being sent in the feed.'
                    );
                    echo json_encode($return);
                    die();
                }
            }
            else
            {
                $return = array(
                    'success' => false,
                    'error' => 'The import appears to execute successfully but no properties are returned.'
                );
                echo json_encode($return);
                die();
            }
        }

        wp_die();
    }

    public function ajax_propertyhive_search_properties()
    {
        header( 'Content-Type: application/json; charset=utf-8' );

        if (isset($_GET['search'])) 
        {
            $results = array();

            // Get all contacts that match the name
            $args = array(
                'post_type' => 'property',
                'nopaging' => true,
                'post_status' => 'any',
                'fields' => 'ids'
            );

            $meta_query = array(
                array(
                    array(
                    'relation' => 'OR',
                        array(
                            'key' => '_address_concatenated',
                            'value' => ph_clean($_GET['search']),
                            'compare' => 'LIKE'
                        ),
                        array(
                            'key' => '_reference_number',
                            'value' => ph_clean($_GET['search']),
                            'compare' => '='
                        ),
                    )
                ),
            );

            if ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) )
            {
                $meta_query[] = array(
                    'key' => '_imported_ref_' . (int)$_GET['import_id'],
                    'compare' => 'EXISTS'
                );
            }

            $args['meta_query'] = $meta_query;

            $property_query = new WP_Query( $args );
            
            if ( $property_query->have_posts() )
            {
                while ( $property_query->have_posts() )
                {
                    $property_query->the_post();

                    $property = new PH_Property(get_the_ID());

                    $results[] = array(
                        'id' => get_the_ID(), 
                        'text' => $property->get_formatted_full_address()
                    );
                }
            }

            wp_send_json($results);
        }
        else
        {
            wp_send_json_error('No search term provided.');
        }
    }

    public function ajax_propertyhive_dismiss_notice_jupix_export_enquiries()
    {
        update_option( 'jupix_export_enquiries_notice_dismissed', 'yes' );
        
        // Quit out
        die();
    }

    public function ajax_propertyhive_dismiss_notice_loop_export_enquiries()
    {
        update_option( 'loop_export_enquiries_notice_dismissed', 'yes' );
        
        // Quit out
        die();
    }

    public function ajax_propertyhive_dismiss_notice_arthur_online_export_enquiries()
    {
        update_option( 'arthur_online_export_enquiries_notice_dismissed', 'yes' );
        
        // Quit out
        die();
    }

    public function ajax_propertyhive_test_property_import_details()
    {
        header( 'Content-Type: application/json; charset=utf-8' );

        $format = isset($_POST['format']) ? sanitize_text_field(wp_unslash($_POST['format'])) : '';

        if ( empty($format) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'No format passed', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        list($import_object, $parsed_in_class) = phpi_get_import_object_from_format( $format, '', '' );

        if ( $import_object === false || empty($import_object) )
        {
            $return = array(
                'success' => false,
                'error' => __( 'Couldn\'t create import object', 'propertyhive' )
            );
            echo json_encode($return);
            die();
        }

        $parsed = $import_object->parse(true);

        if ( !$parsed )
        {
            $errors = ( isset($import_object->errors) ? $import_object->errors : array() );
            $error = 'Please check they are correct.';
            if ( !empty($errors) )
            {
                $error = $errors[array_key_last($errors)];
                $explode_error = explode(" - ", $error, 2);
                if ( count($explode_error) == 2 )
                {
                    $error = $explode_error[1];
                }
            }

            $return = array(
                'success' => false,
                'error' => 'An error occurred whilst obtaining the properties using the details provided:<br><br>' . nl2br(esc_html($error))
            );
            echo json_encode($return);
            die();
        }
        else
        {
            $return = array(
                'success' => true,
                'properties' => count($import_object->properties)
            );
            echo json_encode($return);
            die();
        }

        wp_die();
    }

    public function get_format_default_mapping_values()
    {
        if ( !wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['ajax_nonce'])), "phpi_ajax_nonce" ) ) 
        {
            status_header( 400 );
            echo __( 'Invalid nonce provided', 'propertyhive' );
            wp_die();
        } 

        // nonce ok. Let's get the XML

        if ( !isset($_POST['format']) || empty($_POST['format']) )
        {
            status_header( 400 );
            echo __( 'No format provided', 'propertyhive' );
            wp_die();
        }

        $format = propertyhive_property_import_get_import_format(sanitize_text_field($_POST['format']));
        
        if ( $format === false )
        {
            status_header( 400 );
            echo __( 'Format provided not found', 'propertyhive' );
            wp_die();
        }

        if ( 
            (
                isset($_POST['screen_action']) && $_POST['screen_action'] === 'addimport' && 
                isset($format['dynamic_taxonomy_values']) && $format['dynamic_taxonomy_values'] === true
            )
            ||
            (
                isset($_POST['screen_action']) && $_POST['screen_action'] === 'editimport' && 
                isset($format['dynamic_taxonomy_values']) && $format['dynamic_taxonomy_values'] === true && 
                isset($_POST['previous_format']) && $_POST['format'] != $_POST['previous_format'] 
            )
        )
        {
            echo __('Because the custom field values are fetched dynamically you\'ll need to save the import before being access these settings<br><br>Please save the import and then come back to this tab to set the custom field mapping.', 'propertyhive');
            wp_die();
        }

        if ( !isset($format['file']) || !file_exists($format['file']) )
        {
            status_header( 400 );
            echo __( 'Missing class file for format', 'propertyhive' ) . ' ' . sanitize_text_field($_POST['format']);
            wp_die();
        }

        $before_classes = get_declared_classes();

        include_once($format['file']);

        $after_classes = get_declared_classes();
        $new_classes = array_diff( $after_classes, $before_classes );
        
        foreach ( $new_classes as $class ) 
        {
            $import_object = new $class('', ( ( isset($_POST['import_id']) && is_numeric($_POST['import_id']) ) ? (int)$_POST['import_id'] : '' ) );

            if ( method_exists( $class, 'get_default_mapping_values' ) )
            {
                $all_ph_taxonomy_terms = isset($_POST['ph_taxonomy_terms']) && !empty($_POST['ph_taxonomy_terms']) && is_array($_POST['ph_taxonomy_terms']) ? $_POST['ph_taxonomy_terms'] : array();

                $taxonomies_for_mapping = propertyhive_property_import_taxonomies_for_mapping();

                $default_mapping_values = $import_object->get_default_mapping_values();
                $new_default_mapping_values = array();

                // Remove any taxonomies belonging to departments not active
                foreach ( $default_mapping_values as $taxonomy => $taxonomy_values )
                {
                    $show_taxonomy = false;
                    foreach ( $taxonomies_for_mapping as $taxonomy_for_mapping )
                    {
                        if ( $taxonomy_for_mapping['import_taxonomy'] == $taxonomy )
                        {
                            if ( !isset($taxonomy_for_mapping['departments']) || empty($taxonomy_for_mapping['departments']) )
                            {
                                $show_taxonomy = true;
                            }
                            else
                            {
                                $departments = $taxonomy_for_mapping['departments'];
                                foreach ( $departments as $department )
                                {
                                    if ( get_option( 'propertyhive_active_departments_' . str_replace("residential-", "", $department) ) == 'yes' )
                                    {
                                        $show_taxonomy = true;
                                    }
                                }
                            }
                        }
                    }

                    if ( $show_taxonomy === true )
                    {
                        $new_default_mapping_values[$taxonomy] = $taxonomy_values;
                    }
                }
                $default_mapping_values = $new_default_mapping_values;

                $all_existing_mappings = array();
                if ( 
                    isset($_POST['screen_action']) && $_POST['screen_action'] === 'editimport' && 
                    isset($_POST['import_id']) && !empty($_POST['import_id']) && is_numeric($_POST['import_id']) 
                )
                {
                    $import_settings = propertyhive_property_import_get_import_settings_from_id( (int)$_POST['import_id'] );
                    if ( $import_settings !== false && isset($import_settings['mappings']) )
                    {
                        $all_existing_mappings = $import_settings['mappings'];
                    }
                }
?>
<ul class="subsubsub" style="float:none">
<?php
    $num_taxonomies = count($default_mapping_values);
    $i = 0;
    foreach ( $default_mapping_values as $taxonomy => $taxonomy_values )
    {
        $taxonomy_label = ucwords(str_replace("_", " ", $taxonomy));

        echo '<li><a href="#taxonomy_mapping_' . esc_attr($taxonomy) . '">' . $taxonomy_label . '</a>' . ( ( $i + 1 ) < $num_taxonomies ? ' |&nbsp; ' : '' ) . '</li>';
     
        ++$i;
    }
?>
</ul>

<?php
    foreach ( $default_mapping_values as $taxonomy => $taxonomy_values )
    {
        $taxonomy_name = $taxonomy;
        if ( in_array($taxonomy, array('sales_availability', 'lettings_availability', 'commercial_availability')) )
        {
            $taxonomy_name = 'availability';
        }

        $taxonomy_label = ucwords(str_replace("_", " ", $taxonomy));

        $ph_taxonomy_terms = isset($all_ph_taxonomy_terms[$taxonomy]) && is_array($all_ph_taxonomy_terms[$taxonomy]) ? $all_ph_taxonomy_terms[$taxonomy] : array();

        $existing_mappings = isset($all_existing_mappings[$taxonomy]) && is_array($all_existing_mappings[$taxonomy]) ? $all_existing_mappings[$taxonomy] : array();
?>
<div id="taxonomy_mapping_<?php echo esc_attr($taxonomy); ?>">

    <h3><?php echo esc_html( $taxonomy_label . ' ' . __( 'Taxomomy', 'propertyhive' )); ?></h3>

    <table class="form-table" id="taxonomy_mapping_table_<?php echo $taxonomy; ?>">
        <tbody>
            <tr>
                <th>Value In <span class="phpi-import-format-name"></span> Feed</th>
                <td style="padding-left:0; font-weight:600"><?php echo esc_html( __( 'Value In Property Hive', 'propertyhive' ) ); ?> <a href="<?php echo admin_url( 'admin.php?page=ph-settings&tab=customfields&section=' . $taxonomy_name ); ?>" target="_blank" style="color:inherit; text-decoration:none; margin-right:4px;" title="Configure <?php echo esc_attr($taxonomy_label); ?> Terms"><span class="dashicons dashicons-admin-tools"></span></a></td>
            </tr>
            <?php 
                foreach ( $taxonomy_values as $key => $value )
                {
            ?>
            <tr>
                <td style="padding-left:0"><?php echo $key . ( $key != $value ? ' - <span style="color:#999">' . $value . '</span>' : '' ); ?></td>
                <td style="padding-left:0">
                    <select name="taxonomy_mapping[<?php echo $taxonomy; ?>][<?php echo $key; ?>]">
                        <option value=""></option>
                        <?php 
                            foreach ( $ph_taxonomy_terms as $key1 => $value1 )
                            {
                                $selected = false;

                                if ( isset($existing_mappings[$key]) && $existing_mappings[$key] == $key1 )
                                {
                                    $selected = true;
                                }

                                if ( $selected === false && isset($_POST['screen_action']) && $_POST['screen_action'] === 'addimport' )
                                {
                                    if ( 
                                        strtolower(str_replace(" ", "", $key)) == strtolower(str_replace(" ", "", $value1))
                                        ||
                                        strtolower(str_replace(" ", "", $value)) == strtolower(str_replace(" ", "", $value1)) 
                                    )
                                    {
                                        $selected = true;
                                    }
                                }

                                echo '<option value="' . $key1 . '"' . ( $selected === true ? ' selected' : '' ) . '>' . $value1 . '</option>';
                            }
                        ?>
                    </select>
                </td>
            </tr>
            <?php
                }

                foreach ( $existing_mappings as $existing_key => $existing_value )
                {   
                    $found_in_standard_list = false;
                    foreach ( $taxonomy_values as $key => $value )
                    {
                        if ( $key == $existing_key )
                        {
                            $found_in_standard_list = true;
                        }
                    }

                    if ( $found_in_standard_list === false )
                    {
            ?>
            <tr>
                <td style="padding-left:0">
                    <input type="text" name="custom_mapping[<?php echo $taxonomy; ?>][<?php echo $existing_key; ?>]" value="<?php echo $existing_key; ?>">
                </td>
                <td style="padding-left:0">
                    <select name="taxonomy_mapping[<?php echo $taxonomy; ?>][<?php echo $existing_key; ?>]">
                        <option value=""></option>
                        <?php 
                            foreach ( $ph_taxonomy_terms as $key1 => $value1 )
                            {
                                $selected = false;

                                if ( $existing_value == $key1 )
                                {
                                    $selected = true;
                                }

                                echo '<option value="' . $key1 . '"' . ( $selected === true ? ' selected' : '' ) . '>' . $value1 . '</option>';
                            }
                        ?>
                    </select>
                </td>
            </tr>
            <?php
                    }
                }
            ?>
        </tbody>
    </table>
    <br>
    <a href="#<?php echo $taxonomy; ?>" class="button add-additional-mapping"><span class="dashicons dashicons-plus-alt2"></span> Add Additional Mapping</a>

    <hr>

</div>
<?php
    }

                die();

                break; // or handle multiple if desired
            }
        }

        status_header( 400 );
        echo __( 'Format file found but couldn\'t initiate class or get_default_mapping_values method not found', 'propertyhive' );
        wp_die();
    }

    public function kill_import()
    {
        if ( !wp_verify_nonce( sanitize_text_field(wp_unslash($_GET['ajax_nonce'])), "phpi_ajax_nonce" ) ) 
        {
            status_header( 400 );
            echo __( 'Invalid nonce provided', 'propertyhive' );
            wp_die();
        } 

        // nonce ok

        if ( !isset($_GET['import_id']) || empty($_GET['import_id']) )
        {
            status_header( 400 );
            echo __( 'No import ID provided', 'propertyhive' );
            wp_die();
        }

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

        if ( isset( $kills[(int)$_GET['import_id']] ) )
        {
            unset($kills[(int)$_GET['import_id']]);
        }

        $kills[(int)$_GET['import_id']] = time();

        update_option($option_name, $kills, false);

        wp_die();
    }
}


new PH_Property_Import_Ajax();