<?php
/**
 * Installation related functions and actions.
 *
 * @author 		PropertyHive
 * @category 	Admin
 * @package 	PropertyHive/Classes
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_Radial_Search_Install' ) ) :

/**
 * PH_Radial_Search_Install Class
 */
class PH_Radial_Search_Install {

	/**
	 * Hook in tabs.
	 */
	public function __construct() {
		register_activation_hook( PH_RADIAL_SEARCH_PLUGIN_FILE, array( $this, 'install' ) );
		register_deactivation_hook( PH_RADIAL_SEARCH_PLUGIN_FILE, array( $this, 'deactivate' ) );
		register_uninstall_hook( PH_RADIAL_SEARCH_PLUGIN_FILE, array( 'PH_Radial_Search_Install', 'uninstall' ) );

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
	    	( get_option( 'propertyhive_radial_search_version' ) != PHRS()->version || get_option( 'propertyhive_radial_search_db_version' ) != PHRS()->version ) 
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
	 * Install Property Hive Radial Search Add-On
	 */
	public function install() {
        
		$this->create_options();
		$this->create_cron();
		$this->create_tables();

		$current_version = get_option( 'propertyhive_radial_search_version', null );
		$current_db_version = get_option( 'propertyhive_radial_search_db_version', null );
        
        update_option( 'propertyhive_radial_search_version', PHRS()->version );

        update_option( 'propertyhive_radial_search_db_version', PHRS()->version );

        $this->populate_lat_long_table();
	}

	/**
	 * Deactivate Property Hive Radial Search Add-On
	 */
	public function deactivate() {

		$timestamp = wp_next_scheduled( 'phradialsearchcronhook' );
        wp_unschedule_event($timestamp, 'phradialsearchcronhook' );
        wp_clear_scheduled_hook('phradialsearchcronhook');

	}

	/**
	 * Uninstall Property Hive Radial Search Add-On
	 */
	public function uninstall() {

		$timestamp = wp_next_scheduled( 'phradialsearchcronhook' );
        wp_unschedule_event($timestamp, 'phradialsearchcronhook' );
        wp_clear_scheduled_hook('phradialsearchcronhook');

        delete_option( 'propertyhive_radial_search' );

        $this->delete_tables();

	}

	public function delete_tables() {

		global $wpdb;

		$wpdb->hide_errors();

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_radial_search_lat_lng_cache" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}ph_radial_search_lat_lng_post" );
	}



	/**
	 * Default options
	 *
	 * Sets up the default options used on the settings page
	 *
	 * @access public
	 */
	public function create_options() {
	    
        //add_option( 'option_name', 'yes', '', 'yes' );

    }

    /**
	 * Creates the scheduled event to run hourly
	 *
	 * @access public
	 */
    public function create_cron() {
        $timestamp = wp_next_scheduled( 'phradialsearchcronhook' );
        wp_unschedule_event($timestamp, 'phradialsearchcronhook' );
        wp_clear_scheduled_hook('phradialsearchcronhook');
        
        $next_schedule = time() - 60;
        wp_schedule_event( $next_schedule, 'daily', 'phradialsearchcronhook' );
    }

    /**
	 * Set up the database tables which the plugin needs to function.
	 *
	 * Tables:
	 *		ph_radial_search_lat_lng_cache - Table description
	 * 		ph_radial_search_lat_lng_post - Table description
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
	   	$table_name = $wpdb->prefix . "ph_radial_search_lat_lng_cache";
	      
	   	$sql = "CREATE TABLE $table_name (
					location varchar(50) NOT NULL,
  					lat float NOT NULL,
  					lng float NOT NULL
	    		) $collate;";
		
		$table_name = $wpdb->prefix . "ph_radial_search_lat_lng_post";
		
		$sql .= "CREATE TABLE $table_name (
					post_id bigint(20) unsigned NOT NULL,
					lat float NOT NULL,
					lng float NOT NULL,
					KEY post_id (post_id)
	    		) $collate;";
		
		dbDelta( $sql );

	}

	/**
	 * Add lat longs to lookup table for all on-market properties with non-blank lat longs
	 *
	 * @access public
	 * @return void
	 */
	private function populate_lat_long_table() {

		global $wpdb;

		$wpdb->hide_errors();

		$wpdb->query('
			INSERT INTO `' . $wpdb->prefix . 'ph_radial_search_lat_lng_post`
			SELECT
				`' . $wpdb->posts . '`.`ID`,
				TRIM(latitudeMeta.`meta_value`),
				TRIM(longitudeMeta.`meta_value`)
			FROM
				`' . $wpdb->posts . '`
			INNER JOIN
				' . $wpdb->postmeta . ' onMarketMeta ON `' . $wpdb->posts . '`.`ID` = onMarketMeta.`post_id` AND onMarketMeta.`meta_key` = "_on_market" AND onMarketMeta.`meta_value` = "yes"
			INNER JOIN
				' . $wpdb->postmeta . ' latitudeMeta ON `' . $wpdb->posts . '`.`ID` = latitudeMeta.`post_id` AND latitudeMeta.`meta_key` = "_latitude" AND latitudeMeta.`meta_value` != ""
			INNER JOIN
				' . $wpdb->postmeta . ' longitudeMeta ON `' . $wpdb->posts . '`.`ID` = longitudeMeta.`post_id` AND longitudeMeta.`meta_key` = "_longitude" AND longitudeMeta.`meta_value` != ""
			WHERE
				`' . $wpdb->posts . '`.`post_type` = "property"
			AND
				`' . $wpdb->posts . '`.`post_status` = "publish"
			AND
				NOT EXISTS ( SELECT 1 FROM `' . $wpdb->prefix . 'ph_radial_search_lat_lng_post` WHERE `' . $wpdb->prefix . 'ph_radial_search_lat_lng_post`.`post_id` = `' . $wpdb->posts . '`.`ID` )
		');
	}
}

endif;

return new PH_Radial_Search_Install();