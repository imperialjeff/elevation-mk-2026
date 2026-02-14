<?php
/**
 * Plugin Name: Property Hive Property Import Add On
 * Plugin Uri: https://wp-property-hive.com/addons/property-import/
 * Description: Add On for Property Hive allowing you to import properties manually or on an automatic basis
 * Version: 3.0.28
 * Author: PropertyHive
 * Author URI: https://wp-property-hive.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_Property_Import' ) ) :

final class PH_Property_Import {

    /**
     * @var string
     */
    public $version = '3.0.28';

    /**
     * @var Property Hive The single instance of the class
     */
    protected static $_instance = null;

    /**
     * @var string
     */
    public $id = '';

    /**
     * @var string
     */
    public $label = '';
    
    /**
     * Main Property Hive Property Import Instance
     *
     * Ensures only one instance of Property Hive Property Import is loaded or can be loaded.
     *
     * @static
     * @return Property Hive Property Import - Main instance
     */
    public static function instance() 
    {
        if ( is_null( self::$_instance ) ) 
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * Constructor.
     */
    public function __construct() {

        $this->id    = 'propertyimport';
        $this->label = __( 'Import Properties', 'propertyhive' );

        // Define constants
        $this->define_constants();

        // Include required files
        $this->includes();

        add_action( 'plugins_loaded', array( $this, 'check_can_be_used'), 1 );

    }

    public function check_can_be_used()
    {
        // check they're running at least version 2.0.3 of Property Hive when this filter was introduced
        if ( class_exists( 'PropertyHive' ) && version_compare(PH()->version, '2.0.3', '>=') )
        {
            if ( apply_filters( 'propertyhive_add_on_can_be_used', true, 'propertyhive-property-import' ) === FALSE )
            { 
                add_action( 'admin_notices', array( $this, 'invalid_license_notice') );
                return;
            }
        }

        // Include required pro files for key functionality to work
        $this->includes_pro();

        if ( is_admin() && file_exists(  dirname( __FILE__ ) . '/propertyhive-property-import-update.php' ) )
        {
            include_once( dirname( __FILE__ ) . '/propertyhive-property-import-update.php' );
        }
    }

    public function invalid_license_notice()
    {
        if ( !current_user_can('manage_options') )
        {
            return;
        }

        if ( isset($_GET['page']) && $_GET['page'] == 'ph-settings' && isset($_GET['tab']) && $_GET['tab'] == 'licensekey' )
        {
            return;
        }

        $message = __( 'The Property Hive ' . $this->label . ' add-on will not function as <a href="' . admin_url('admin.php?page=ph-settings&tab=licensekey') . '">no valid license</a> was found. Please <a href="' . admin_url('admin.php?page=ph-settings&tab=features') . '">disable this feature</a> or enter a valid license.', 'propertyhive' );
        echo"<div class=\"error\"> <p>$message</p></div>";
    }

    /**
     * Define PH Property Import Constants
     */
    private function define_constants() 
    {
        define( 'PH_PROPERTYIMPORT_PLUGIN_FILE', __FILE__ );
        define( 'PH_PROPERTYIMPORT_VERSION', $this->version );
        define( 'PH_PROPERTYIMPORT_DEPARTMENT_AVAILABILITY_UPDATE', 1589328000 );
        define( 'PH_PROPERTYIMPORT_JET_DEPARTMENT_AVAILABILITY_UPDATE', 1616972400 );
        define( 'PH_PROPERTYIMPORT_FOUNDATIONS_AGREEMENT_UPDATE', 1663027200 );
    }

    private function includes_pro()
    {
        include_once( 'includes/class-ph-property-import-cron.php' );
        include_once( 'includes/class-ph-property-import-email-reports.php' );
        include_once( 'includes/class-ph-property-import-redirects.php' );
        include_once( 'includes/class-ph-property-import-shortcodes.php' );
        include_once( 'includes/class-ph-property-import-elementor.php' );

        // CRM specific files
        include_once( 'includes/class-ph-property-import-arthur.php' );
        include_once( 'includes/class-ph-property-import-rtdf.php' );
        include_once( 'includes/class-ph-property-import-street.php' );
        include_once( 'includes/class-ph-property-import-webedge.php' );

        if ( class_exists( 'WP_CLI' ) ) 
        {
            include_once( 'includes/class-ph-property-import-cli.php' );
        }
    }

    private function includes()
    {
        include_once( 'includes/class-ph-property-import-admin.php' );
        include_once( 'includes/class-ph-property-import-admin-notices.php' );
        include_once( 'includes/class-ph-property-import-ajax.php' );
        include_once( 'includes/class-ph-property-import-import.php' );
        include_once( 'includes/class-ph-property-import-install.php' );
        include_once( 'includes/class-ph-property-import-process.php' );
        include_once( 'includes/class-ph-property-import-property-portal.php' );
        include_once( 'includes/class-ph-property-import-settings.php' );
        
        // Helper functions
        include_once( 'includes/array-functions.php' );
        include_once( 'includes/format-functions.php' );
        include_once( 'includes/frequency-functions.php' );
        include_once( 'includes/import-functions.php' );
        include_once( 'includes/xml-functions.php' );

        if ( version_compare(PHP_VERSION, '8.0', '>=') ) 
        {
            // JSONPath
            include_once( 'includes/jsonpath/JSONPath.php' );
            include_once( 'includes/jsonpath/JSONPathException.php' );
            include_once( 'includes/jsonpath/JSONPathLexer.php' );
            include_once( 'includes/jsonpath/JSONPathToken.php' );
            include_once( 'includes/jsonpath/AccessHelper.php' );
            include_once( 'includes/jsonpath/Filters/AbstractFilter.php' );
            include_once( 'includes/jsonpath/Filters/IndexesFilter.php' );
            include_once( 'includes/jsonpath/Filters/IndexFilter.php' );
            include_once( 'includes/jsonpath/Filters/QueryMatchFilter.php' );
            include_once( 'includes/jsonpath/Filters/QueryResultFilter.php' );
            include_once( 'includes/jsonpath/Filters/RecursiveFilter.php' );
            include_once( 'includes/jsonpath/Filters/SliceFilter.php' );
        }
    }
}

endif;

/**
 * Returns the main instance of PH_Property_Import to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return PH_Property_Import
 */
function PHPI() {
    return PH_Property_Import::instance();
}

PHPI();