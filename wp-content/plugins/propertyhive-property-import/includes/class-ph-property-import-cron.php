<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Cron Functions
 */
class PH_Property_Import_Cron {

	public function __construct() {

        add_filter( 'cron_schedules', array( $this, 'custom_cron_recurrence' ) );

        add_action( 'phpropertyimportcronhook', array( $this, 'property_import_execute_feed' ) );

        add_action( 'phpropertydownloadimportmediacronhook', array( $this, 'property_import_media_execute_feed' ) );

        add_action( 'admin_init', array( $this, 'check_for_manually_run_import') );

        add_action( 'admin_init', array( $this, 'check_propertyimport_is_scheduled'), 99 );

	}

	public function custom_cron_recurrence( $schedules ) 
    {
        $schedules['every_five_minutes'] = array(
            'interval'  => 300,
            'display'   => __( 'Every 5 Minutes', 'propertyhive' )
        );

        $schedules['every_fifteen_minutes'] = array(
            'interval'  => 900,
            'display'   => __( 'Every 15 Minutes', 'propertyhive' )
        );
         
        return $schedules;
    }

    public function property_import_execute_feed( $args = array(), $assoc_args = array() ) 
    {
        require( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/cron.php' );
    }

    public function property_import_media_execute_feed()
    {
        $media_processing = get_option( 'propertyhive_property_import_media_processing', '' );

        if ( $media_processing === 'background' )
        {
            require( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/download-queued-media-cron.php' );

            if ( defined( 'WP_CLI' ) && WP_CLI )
            {
                WP_CLI::success( "Import media downloaded successfully" );
            }
        }
    }

    public function check_for_manually_run_import() 
    {
        if ( 
            isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'propertyhive_property_import') &&
            isset($_GET['custom_property_import_cron']) && in_array(sanitize_text_field($_GET['custom_property_import_cron']), array('phpropertyimportcronhook', 'phpropertydownloadimportmediacronhook')) 
        )
        {
            $redirect_url = 'admin.php?page=propertyhive_import_properties';
            if ( isset($_REQUEST['orderby']) && !empty($_REQUEST['orderby']) && isset($_REQUEST['order']) && in_array(strtolower($_REQUEST['order']), array('asc', 'desc')) )
            {
                $redirect_url .= '&orderby=' . sanitize_text_field($_REQUEST['orderby']) . '&order=' . sanitize_text_field($_REQUEST['order']);
            }
            if ( isset($_REQUEST['phpi_filter']) && !empty($_REQUEST['phpi_filter']) )
            {
                $redirect_url .= '&phpi_filter=' . sanitize_text_field($_REQUEST['phpi_filter']);
                if ( isset($_REQUEST['phpi_filter_format']) && !empty($_REQUEST['phpi_filter_format']) )
                {
                    $redirect_url .= '&phpi_filter_format=' . sanitize_text_field($_REQUEST['phpi_filter_format']);
                }
            }

            if ( !isset($_GET['force']) )
            {
                global $wpdb;

                $options = get_option( 'propertyhive_property_import', array() );
                $imports = ( is_array($options) && !empty($options) ) ? $options : array();

                foreach ( $imports as $import_id => $import_settings )
                {
                    if ( !isset($import_settings['running']) || ( isset($import_settings['running']) && $import_settings['running'] != 1 ) )
                    {
                        continue;
                    }

                    if ( isset($import_settings['deleted']) && $import_settings['deleted'] == 1 )
                    {
                        continue;
                    }

                    // Make sure there's been no activity in the logs for at least 5 minutes for this feed as that indicates there's possible a feed running
                    $row = $wpdb->get_row( "
                        SELECT 
                            status_date
                        FROM 
                            " . $wpdb->prefix . "ph_propertyimport_instance_v3
                        WHERE
                            " . ( ( apply_filters( 'propertyhive_property_import_one_import_at_a_time', false ) === false ) ? " import_id = '" . $import_id . "' AND " : "" ) . "
                            end_date = '0000-00-00 00:00:00'
                        ORDER BY status_date DESC
                        LIMIT 1
                    ", ARRAY_A);

                    if ( null !== $row )
                    {
                        if ( ( ( time() - strtotime($row['status_date']) ) / 60 ) < 5 )
                        {
                            wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(__( "There has been activity within the past 5 minutes on an unfinished import. To prevent multiple imports running at the same time and possible duplicate properties being created we won't currently allow manual execution. Please try again in a few minutes or check the logs to see the status of the current import.", 'propertyhive' ) ) ) );
                            die();
                        }
                    }
                }
            }

            do_action(sanitize_text_field($_GET['custom_property_import_cron']));

            wp_redirect( admin_url( $redirect_url . '&phpisuccessmessage=' . base64_encode(urlencode(__( 'Import executed successfully. You can check <a href="' . esc_url( admin_url('admin.php?page=propertyhive_import_properties&tab=logs') ) . '">the logs</a> to see what happened during the import.', 'propertyhive' ) ) ) ) );
            die();
        }
        /*if ( isset($_GET['custom_property_import_cron']) && in_array(sanitize_text_field($_GET['custom_property_import_cron']), array('phpropertyimportcronhook', 'phpropertydownloadimportmediacronhook')) )
        {
            do_action(sanitize_text_field($_GET['custom_property_import_cron']));
        }*/
    }

    public function check_propertyimport_is_scheduled()
    {
        $schedule = wp_get_schedule( 'phpropertyimportcronhook' );

        if ( $schedule === FALSE )
        {
            // Hmm... cron job not found. Let's set it up
            $timestamp = wp_next_scheduled( 'phpropertyimportcronhook' );
            wp_unschedule_event($timestamp, 'phpropertyimportcronhook' );
            wp_clear_scheduled_hook('phpropertyimportcronhook');
            
            $next_schedule = time() - 60;
            wp_schedule_event( $next_schedule, apply_filters( 'propertyhive_property_import_cron_frequency', 'every_five_minutes' ), 'phpropertyimportcronhook' );
        }

        $schedule = wp_get_schedule( 'phpropertydownloadimportmediacronhook' );

        if ( $schedule === FALSE )
        {
            // Hmm... cron job not found. Let's set it up
            $timestamp = wp_next_scheduled( 'phpropertydownloadimportmediacronhook' );
            wp_unschedule_event($timestamp, 'phpropertydownloadimportmediacronhook' );
            wp_clear_scheduled_hook('phpropertydownloadimportmediacronhook');

            $next_schedule = time() - 60;
            wp_schedule_event( $next_schedule, apply_filters( 'propertyhive_property_import_media_cron_frequency', 'every_fifteen_minutes' ), 'phpropertydownloadimportmediacronhook' );
        }
    }

}

new PH_Property_Import_Cron();