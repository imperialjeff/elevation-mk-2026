<?php
/**
 * Installation related functions and actions.
 *
 * @author      PropertyHive
 * @category    Admin
 * @package     PropertyHive/Classes
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_Property_Import_Install' ) ) :

/**
 * PH_Property_Import_Install Class
 */
class PH_Property_Import_Install {

    /**
     * DB updates and callbacks that need to be run per version.
     *
     * @var array
     */
    private static $db_updates = array(
        '3.0.0' => array(
            'propertyhive_property_import_update_300_global_options_and_split_availabilities',
        ),
    );

    /**
     * Hook in tabs.
     */
    public function __construct() {
        register_activation_hook( PH_PROPERTYIMPORT_PLUGIN_FILE, array( $this, 'install' ) );
        register_deactivation_hook( PH_PROPERTYIMPORT_PLUGIN_FILE, array( $this, 'deactivate' ) );
        register_uninstall_hook( PH_PROPERTYIMPORT_PLUGIN_FILE, array( 'PH_Property_Import_Install', 'uninstall' ) );

        add_action( 'admin_init', array( $this, 'install_actions' ) );
        add_action( 'admin_init', array( $this, 'check_version' ), 5 );
    }

    /**
     * check_version function.
     *
     * @access public
     * @return void
     */
    public function check_version() {
        if ( 
            ! defined( 'IFRAME_REQUEST' ) && 
            ( get_option( 'propertyhive_property_import_version' ) != PHPI()->version || get_option( 'propertyhive_property_import_db_version' ) != PHPI()->version ) 
        ) {
            $this->install();
        }
    }

    /**
     * Install actions
     */
    public function install_actions() {



    }

    /**
     * Install Property Hive Property Import Add-On
     */
    public function install() {
        
        $this->create_options();
        $this->create_cron();
        $this->create_tables();

        $this->update();

        $current_version = get_option( 'propertyhive_property_import_version', null );
        $current_db_version = get_option( 'propertyhive_property_import_db_version', null );

        // No existing version set. This must be a new fresh install
        if ( is_null( $current_version ) && is_null( $current_db_version ) ) 
        {
            //set_transient( '_ph_property_import_activation_redirect', 1, 30 );
        }
        
        update_option( 'propertyhive_property_import_db_version', PHPI()->version );

        // Update version
        update_option( 'propertyhive_property_import_version', PHPI()->version );
    }

    /**
     * Deactivate Property Hive Property Import Add-On
     */
    public function deactivate() {

        $timestamp = wp_next_scheduled( 'phpropertyimportcronhook' );
        wp_unschedule_event($timestamp, 'phpropertyimportcronhook' );
        wp_clear_scheduled_hook('phpropertyimportcronhook');
        
        $timestamp = wp_next_scheduled( 'phpropertydownloadimportmediacronhook' );
        wp_unschedule_event($timestamp, 'phpropertydownloadimportmediacronhook' );
        wp_clear_scheduled_hook('phpropertydownloadimportmediacronhook');

    }

    /**
     * Uninstall Property Hive Property Import Add-On
     */
    public function uninstall() {

        $timestamp = wp_next_scheduled( 'phpropertyimportcronhook' );
        wp_unschedule_event($timestamp, 'phpropertyimportcronhook' );
        wp_clear_scheduled_hook('phpropertyimportcronhook');
        
        $timestamp = wp_next_scheduled( 'phpropertydownloadimportmediacronhook' );
        wp_unschedule_event($timestamp, 'phpropertydownloadimportmediacronhook' );
        wp_clear_scheduled_hook('phpropertydownloadimportmediacronhook');

        delete_option( 'propertyhive_property_import' );

        $this->delete_tables();
    }

    public function delete_tables() {

        global $wpdb;

        $wpdb->hide_errors();

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_instance_v3" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_instance_log_v3" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_media_queue" );
    }

    /**
     * Get list of DB update callbacks.
     *
     * @return array
     */
    public static function get_db_update_callbacks() {
        return self::$db_updates;
    }

    /**
     * Handle updates
     */
    public function update() {
        // Do updates
        $current_db_version = get_option( 'propertyhive_property_import_db_version' );

        include( 'update-functions.php' );
        foreach ( self::get_db_update_callbacks() as $version => $update_callbacks ) 
        {
            if ( version_compare( $current_db_version, $version, '<' ) ) 
            {
                foreach ( $update_callbacks as $update_callback ) 
                {
                    add_action('property_run_import_update_actions', $update_callback);
                }
            }
        }
        do_action('property_run_import_update_actions');
    }

    /**
     * Default options
     *
     * Sets up the default options used on the settings page
     *
     * @access public
     */
    public function create_options() {
        
        add_option( 'propertyhive_property_import_remove_action', 'remove_all_media_except_first_image', '', false );
        add_option( 'propertyhive_property_import_media_processing', 'background', '', false );

    }

    /**
     * Creates the scheduled event to run hourly
     *
     * @access public
     */
    public function create_cron() {
        $timestamp = wp_next_scheduled( 'phpropertyimportcronhook' );
        wp_unschedule_event($timestamp, 'phpropertyimportcronhook' );
        wp_clear_scheduled_hook('phpropertyimportcronhook');
        
        $next_schedule = time() - 60;
        wp_schedule_event( $next_schedule, apply_filters( 'propertyhive_property_import_cron_frequency', 'every_five_minutes' ), 'phpropertyimportcronhook' );

        $timestamp = wp_next_scheduled( 'phpropertydownloadimportmediacronhook' );
        wp_unschedule_event($timestamp, 'phpropertydownloadimportmediacronhook' );
        wp_clear_scheduled_hook('phpropertydownloadimportmediacronhook');

        $next_schedule = time() - 60;
        wp_schedule_event( $next_schedule, apply_filters( 'propertyhive_property_import_media_cron_frequency', 'every_fifteen_minutes' ), 'phpropertydownloadimportmediacronhook' );
    }

    /**
     * Set up the database tables which the plugin needs to function.
     *
     * Tables:
     *      ph_propertyimport_instance_v3 - Table description
     *      ph_propertyimport_instance_log_v3 - Table description
     *
     * @access public
     * @return void
     */
    private function create_tables() {

        global $wpdb;

        $wpdb->hide_errors();

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $collate = '';

        if ( $wpdb->has_cap( 'collation' ) ) {
            if ( ! empty($wpdb->charset ) ) {
                $collate .= "DEFAULT CHARACTER SET $wpdb->charset";
            }
            if ( ! empty($wpdb->collate ) ) {
                $collate .= " COLLATE $wpdb->collate";
            }
        }

        // Create table to record individual feeds being ran
        $table_name = $wpdb->prefix . "ph_propertyimport_instance_v3";
          
        $sql = "CREATE TABLE $table_name (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    import_id bigint(20) UNSIGNED NOT NULL,
                    start_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                    end_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                    status varchar(255) NOT NULL,
                    status_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                    media tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
                    PRIMARY KEY  (id),
                    INDEX import_id (import_id)
                ) $collate;";
        
        $table_name = $wpdb->prefix . "ph_propertyimport_instance_log_v3";
        
        $sql .= "CREATE TABLE $table_name (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    instance_id bigint(20) UNSIGNED NOT NULL,
                    post_id bigint(20) UNSIGNED NOT NULL,
                    crm_id varchar(255) NOT NULL,
                    severity tinyint(1) UNSIGNED NOT NULL,
                    entry longtext NOT NULL,
                    received_data longtext,
                    log_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                    PRIMARY KEY  (id),
                    INDEX instance_id (instance_id)
                ) $collate;";

        $table_name = $wpdb->prefix . "ph_propertyimport_media_queue";

        $sql .= "CREATE TABLE $table_name (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    import_id bigint(20) UNSIGNED NOT NULL,
                    post_id bigint(20) UNSIGNED NOT NULL,
                    property_id bigint(20) UNSIGNED NOT NULL,
                    media_location text NOT NULL,
                    media_description varchar(255) NOT NULL,
                    media_type varchar(255) NOT NULL,
                    media_order smallint(1) UNSIGNED NOT NULL,
                    media_compare_url text NOT NULL,
                    media_modified datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                    date_queued datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                    processed tinyint(1) UNSIGNED NOT NULL DEFAULT 0,
                    attachment_id bigint(20) UNSIGNED DEFAULT NULL,
                    PRIMARY KEY  (id),
                    INDEX import_id (import_id)
                ) $collate;";

        dbDelta( $sql );

        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_instance" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_instance_log" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_logs_instance" );
        $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_propertyimport_logs_instance_log" );

    }

}

endif;

return new PH_Property_Import_Install();