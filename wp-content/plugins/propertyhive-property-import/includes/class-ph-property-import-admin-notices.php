<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Admin Notice Functions
 */
class PH_Property_Import_Admin_Notices {

	public function __construct() {

        add_action( 'admin_notices', array( $this, 'propertyimport_error_notices') );

        add_action( 'current_screen', array( $this, 'suppress_notices_on_splash_screen' ) );

    }

    /**
     * Output error message if core Property Hive plugin isn't active
     */
    public function propertyimport_error_notices() 
    {
        if (!is_plugin_active('propertyhive/propertyhive.php'))
        {
            $message = "The Property Hive plugin must be installed and activated before you can use the Property Hive Property Import add-on";
            echo"<div class=\"error\"> <p>$message</p></div>";
        }
        else
        {
            global $wpdb;

            // Check timeout limit
            $screen = get_current_screen();
            if ( $screen->id == 'property-hive_page_propertyhive_import_properties' )
            {
                $show_last_run_warning = false;
                $show_fallen_over_warning = false;

                $options = get_option( 'propertyhive_property_import' );
                if ( is_array($options) && !empty($options) )
                {
                    foreach( $options as $import_id => $option )
                    {
                        if ( $show_last_run_warning == true && $show_fallen_over_warning == true )
                        {
                            break;
                        }

                        if ( isset($option['running']) && $option['running'] == 1 )
                        {
                            $row = $wpdb->get_row( "
                                SELECT
                                    id, status_date, start_date, end_date
                                FROM 
                                    " . $wpdb->prefix . "ph_propertyimport_instance_v3
                                WHERE
                                    import_id = '" . $import_id . "'
                                ORDER BY status_date DESC 
                                LIMIT 1", ARRAY_A);
                            if ( null !== $row )
                            {
                                // Check if import might have got stuck. Where:
                                // - End date is empty
                                // - Last entry in log for this import is more than 5 minutes ago
                                if ($show_fallen_over_warning == false && $row['end_date'] == '0000-00-00 00:00:00')
                                {
                                    $last_log_entry = $row['status_date'];
                                    if (time() - strtotime($last_log_entry) > (5 * 60) )
                                    {
                                        $show_fallen_over_warning = true;
                                    }
                                }

                                if ( $show_last_run_warning == false )
                                {
                                    $next_due = wp_next_scheduled( 'phpropertyimportcronhook' );
                                    if ( $next_due !== false && isset($row['start_date']) )
                                    {
                                        $last_start_date = strtotime($row['start_date']);

                                        $got_next_due = false;

                                        while ( $got_next_due === false )
                                        {
                                            switch ($option['import_frequency'])
                                            {
                                                case "every_15_minutes":
                                                case "every_fifteen_minutes":
                                                {
                                                    if ( ( ($next_due - $last_start_date) / 60 / 60 ) >= 0.25 )
                                                    {
                                                        $got_next_due = $next_due;
                                                    }
                                                    break;
                                                }
                                                case "hourly":
                                                {
                                                    if ( ( ($next_due - $last_start_date) / 60 / 60 ) >= 1 )
                                                    {
                                                        $got_next_due = $next_due;
                                                    }
                                                    break;
                                                }
                                                case "twicedaily":
                                                {
                                                    if ( ( ($next_due - $last_start_date) / 60 / 60 ) >= 12 )
                                                    {
                                                        $got_next_due = $next_due;
                                                    }
                                                    break;
                                                }
                                                default: // daily
                                                {
                                                    if ( ( ($next_due - $last_start_date) / 60 / 60 ) >= 24 )
                                                    {
                                                        $got_next_due = $next_due;
                                                    }
                                                }
                                            }
                                            $next_due = $next_due + 900;
                                        }

                                        if ( $next_due <= strtotime('-48 hours') )
                                        {
                                            $show_last_run_warning = true;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }

                if ( $show_fallen_over_warning == true )
                {
                    echo '<div class="notice notice-info is-dismissible"><p><strong>' .
                    __( 'It looks like one of your imports might have fallen over? It\'s likely the \'max_execution_time\' PHP setting needs increasing on your server. Your web hosting company should be able to increase this providing you\'re not on shared hosting.<br>For more help, please visit the <a href="https://docs.wp-property-hive.com/add-ons/property-import/troubleshooting/" target="_blank">Property Import Troubleshooting</a> page.', 'propertyhive' ) .
                    '</strong></p></div>';
                }

                if ( $show_last_run_warning == true )
                {
                    echo '<div class="notice notice-info is-dismissible"><p><strong>' .
                    __( 'It looks like one of your imports may not be starting automatically. For help on how to fix this, you can visit the <a href="https://docs.wp-property-hive.com/add-ons/property-import/troubleshooting/" target="_blank">Property Import Troubleshooting</a> page.', 'propertyhive' ) .
                    '</strong></p></div>';
                }
            }
            if ( $screen->id == 'property' && isset($_GET['post']) && get_post_type($_GET['post']) == 'property' )
            {
                // Check if this property was imported from somewhere and warn if it was
                $post_meta = get_post_meta($_GET['post']);

                $imported = false;

                foreach ($post_meta as $key => $val )
                {
                    if ( strpos($key, '_imported_ref_') !== FALSE )
                    {
                        echo '<div class="notice notice-info"><p>';

                        $property_import_id = str_replace("_imported_ref_", "", $key);
                        $options = get_option( 'propertyhive_property_import' );
                        $format = '';
                        if ( is_array($options) && !empty($options) )
                        {
                            foreach ( $options as $import_id => $option )
                            {
                                if ( $import_id == $property_import_id )
                                {
                                    $format = $option['format'];
                                    break;
                                }
                            }
                        }

                        $import_data_time = get_post_meta($_GET['post'], '_property_import_data_time', true);

                        echo __( '<strong>It looks like this property was imported automatically. Please note that any changes made manually might get overwritten the next time an import runs.</strong><br><br><em>Import Details: ' . $key . ': ' . $val[0] . ( $format != '' ? ' (' . $format . ')' : '' ) . ( !empty($import_data_time) ? ' on ' . get_date_from_gmt( date( 'Y-m-d H:i:s', $import_data_time ), "jS F Y" ) . ' at ' . get_date_from_gmt( date( 'Y-m-d H:i:s', $import_data_time ), "H:i:s" ) : '' ) . '</em>', 'propertyhive' );

                        $import_data = get_post_meta($_GET['post'], '_property_import_data', true);
                        if( !empty($import_data) )
                        {
                            if ( !wp_script_is('propertyhive_property_import_admin', 'enqueued') )
                            {
                                wp_enqueue_script( 'propertyhive_property_import_admin' );
                            }

                            echo '<br><strong><a href="" id="toggle_import_data_div">Show Import Data</a>
                              <br><div id="import_data_div" style="display:none;"><textarea readonly rows="20" cols="120">' . $import_data . '</textarea></div></strong>';
                        }

                        if ( is_array($options) && !empty($options) )
                        {
                            foreach ( $options as $import_id => $option )
                            {
                                if ( $import_id == $property_import_id && isset($option['deleted']) && $option['deleted'] == 1 )
                                {
                                    echo '<br><br><strong style="color:#900">' . __( 'This property was imported by an import which no longer exists.', 'propertyhive' ) . '</strong>';
                                }
                            }
                        }

                        echo '</p></div>';
                        break;
                    }
                }
            }

            if ( get_option('jupix_export_enquiries_notice_dismissed', '') != 'yes' )
            {
                $options = get_option( 'propertyhive_property_import' );
                if ( is_array($options) && !empty($options) )
                {
                    foreach ( $options as $import_id => $option )
                    {
                        if ( isset($option['deleted']) && $option['deleted'] == 1 )
                        {
                            continue;
                        }

                        if ( isset($option['format']) && $option['format'] == 'xml_jupix' && isset($option['running']) && $option['running'] == 1 )
                        {
                            echo '<div class="notice notice-info" id="ph_notice_jupix_export_enquiries"><p>' .
                                __( '<strong>It looks like you\'re importing properties from Jupix. Did you know you can now automatically send enquiries made through the website back into your Jupix account?</strong>', 'propertyhive' ) .
                                '</p>
                                <p>
                                    <a href="https://wp-property-hive.com/addons/export-jupix-enquiries/" target="_blank" class="button-primary">View Jupix Enquiries Add On</a>
                                    <a href="" class="button" id="ph_dismiss_notice_jupix_export_enquiries">Dismiss</a>
                                </p></div>';
                            break;
                        }
                    }
                }
            }

            if ( get_option('loop_export_enquiries_notice_dismissed', '') != 'yes' )
            {
                $options = get_option( 'propertyhive_property_import' );
                if ( is_array($options) && !empty($options) )
                {
                    foreach ( $options as $import_id => $option )
                    {
                        if ( isset($option['deleted']) && $option['deleted'] == 1 )
                        {
                            continue;
                        }

                        if ( ( isset($option['format']) && ( $option['format'] == 'json_loop' || $option['format'] == 'json_loop_v2' ) ) && isset($option['running']) && $option['running'] == 1 )
                        {
                            echo '<div class="notice notice-info" id="ph_notice_loop_export_enquiries"><p>' .
                                __( '<strong>It looks like you\'re importing properties from Loop Software. Did you know you can now automatically send enquiries made through the website back into your Loop account?</strong>', 'propertyhive' ) .
                                '</p>
                                <p>
                                    <a href="https://wp-property-hive.com/addons/export-loop-enquiries/" target="_blank" class="button-primary">View Loop Enquiries Add On</a>
                                    <a href="" class="button" id="ph_dismiss_notice_loop_export_enquiries">Dismiss</a>
                                </p></div>';
                            break;
                        }
                    }
                }
            }

            if ( get_option('arthur_online_export_enquiries_notice_dismissed', '') != 'yes' )
            {
                $options = get_option( 'propertyhive_property_import' );
                if ( is_array($options) && !empty($options) )
                {
                    foreach ( $options as $import_id => $option )
                    {
                        if ( isset($option['deleted']) && $option['deleted'] == 1 )
                        {
                            continue;
                        }

                        if ( isset($option['format']) && $option['format'] == 'json_arthur' && isset($option['running']) && $option['running'] == 1 )
                        {
                            echo '<div class="notice notice-info" id="ph_notice_arthur_online_export_enquiries"><p>' .
                                __( '<strong>It looks like you\'re importing properties from Arthur Online. Did you know you can now automatically send enquiries made through the website back into your Arthur Online account?</strong>', 'propertyhive' ) .
                                '</p>
                                <p>
                                    <a href="https://wp-property-hive.com/addons/export-arthur-online-enquiries/" target="_blank" class="button-primary">View Arthur Online Enquiries Add On</a>
                                    <a href="" class="button" id="ph_dismiss_notice_arthur_online_export_enquiries">Dismiss</a>
                                </p></div>';
                            break;
                        }
                    }
                }
            }

            $error = '';    
            $uploads_dir = wp_upload_dir();
            if( $uploads_dir['error'] === FALSE )
            {
                $uploads_dir = $uploads_dir['basedir'] . '/ph_import/';
                
                if ( ! @file_exists($uploads_dir) )
                {
                    if ( ! @mkdir($uploads_dir) )
                    {
                        $error = 'Unable to create subdirectory in uploads folder for use by Property Hive Property Import plugin. Please ensure the <a href="http://codex.wordpress.org/Changing_File_Permissions" target="_blank" title="WordPress Codex - Changing File Permissions">correct permissions</a> are set.';
                    }
                }
                else
                {
                    if ( ! @is_writeable($uploads_dir) )
                    {
                        $error = 'The uploads folder is not currently writeable and will need to be before properties can be imported. Please ensure the <a href="http://codex.wordpress.org/Changing_File_Permissions" target="_blank" title="WordPress Codex - Changing File Permissions">correct permissions</a> are set.';
                    }
                }
            }
            else
            {
                $error = 'An error occurred whilst trying to create the uploads folder. Please ensure the <a href="http://codex.wordpress.org/Changing_File_Permissions" target="_blank" title="WordPress Codex - Changing File Permissions">correct permissions</a> are set. '.$uploads_dir['error'];
            }
            
            if( $error != '' )
            {
                echo '<div class="error"><p><strong>'.$error.'</strong></p></div>';
            }
        }
    }

    public function suppress_notices_on_splash_screen( $current_screen )
    {
        if ( $current_screen->id === 'property-hive_page_propertyhive_import_properties' ) 
        {
            $options = get_option( 'propertyhive_property_import' );

            if ( is_array($options) && !empty($options) )
            {
                foreach ( $options as $import_id => $option )
                {
                    if ( isset($option['deleted']) && $option['deleted'] == 1 )
                    {
                        unset($options[$import_id]);
                    }
                }
            }

            if ( empty($options) && (!isset($_POST) || empty($_POST)) ) 
            {
                remove_all_actions('admin_notices');
                remove_all_actions('all_admin_notices');
            }
        }
    }
}

new PH_Property_Import_Admin_Notices();