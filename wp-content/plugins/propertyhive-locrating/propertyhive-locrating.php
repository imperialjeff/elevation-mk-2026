<?php
/**
 * Plugin Name: Property Hive Locrating Add On
 * Plugin Uri: http://wp-property-hive.com/addons/locrating/
 * Description: Add On for Property Hive allowing users to view information about schools near properties they are browsing 
 * Version: 1.0.10
 * Author: PropertyHive
 * Author URI: http://wp-property-hive.com
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( ! class_exists( 'PH_Locrating' ) ) :
final class PH_Locrating {


    /**
     * @var string
     */
    public $version = '1.0.10';

    /**
     * @var Property Hive The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main Property Hive Locrating Instance
     *
     * Ensures only one instance of Property Hive Locrating is loaded or can be loaded.
     *
     * @static
     * @return Property Hive Locrating - Main instance
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

        $this->id    = 'locrating';
        $this->label = __( 'Locrating', 'propertyhive' );

        // Define constants
        $this->define_constants();

        // Include required files
        $this->includes();

        add_action( 'admin_notices', array( $this, 'locrating_error_notices') );

        add_filter( 'propertyhive_settings_tabs_array', array( $this, 'add_settings_tab' ), 19 );
        add_action( 'propertyhive_settings_' . $this->id, array( $this, 'output' ) );
        add_action( 'propertyhive_settings_save_' . $this->id, array( $this, 'save' ) );

        add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), array( $this, 'plugin_add_settings_link' ) );

        $current_settings = get_option( 'propertyhive_locrating', array() );
        if ( isset($current_settings['enabled']) && $current_settings['enabled'] == '1' )
        {
            add_action( 'propertyhive_property_actions_list_end', array( $this, 'locrating_action' ) );

            add_shortcode( 'locrating_schools_map', array( $this, 'locrating_schools_map_shortcode' ) );
            add_shortcode( 'locrating_amenities_map', array( $this, 'locrating_amenities_map_shortcode' ) );
            add_shortcode( 'locrating_transport_map', array( $this, 'locrating_transport_map_shortcode' ) );
            add_shortcode( 'locrating_broadband_checker_map', array( $this, 'locrating_broadband_checker_map_shortcode' ) );
            add_shortcode( 'locrating_all_in_one_map', array( $this, 'locrating_all_in_one_map_shortcode' ) );

            add_filter( 'propertyhive_elementor_tabbed_details_display_options', array( $this, 'locrating_options_in_elementor_tabbed_details_display_options' ) );
            add_action( 'propertyhive_elementor_tabbed_details_tab_contents', array( $this, 'output_locrating_in_elementor_tabbed_details' ), 10, 2 );
            add_filter( 'propertyhive_elementor_tabbed_details_tab_onclick',  array( $this, 'elementor_tabbed_details_onclick' ), 10, 3 );
        }
    }

    public function elementor_tabbed_details_onclick( $onclick, $property, $item )
    {
        if ( in_array('locrating_local_schools', $item['tab_display']) )
        {
            $onclick .= "setTimeout(function() { setLocratingIFrameProperties({'id': locrating_schools_frame_id, 'lat': jQuery('#'+locrating_schools_frame_id).data('lat'), 'lng' : jQuery('#'+locrating_schools_frame_id).data('lng')}); }, 10);";
        }
        if ( in_array('locrating_local_amenities', $item['tab_display']) )
        {
            $onclick .= "setTimeout(function() { setLocratingIFrameProperties({'id': locrating_amenities_frame_id, 'lat': jQuery('#'+locrating_amenities_frame_id).data('lat'), 'lng' : jQuery('#'+locrating_amenities_frame_id).data('lng'), 'type':'localinfo'}); }, 10);";
        }
        if ( in_array('locrating_local_transport', $item['tab_display']) )
        {
            $onclick .= "setTimeout(function() { setLocratingIFrameProperties({'id': locrating_transport_frame_id, 'lat': jQuery('#'+locrating_transport_frame_id).data('lat'), 'lng' : jQuery('#'+locrating_transport_frame_id).data('lng'), 'type':'transport'}); }, 10);";
        }
        if ( in_array('locrating_broadband_checker', $item['tab_display']) )
        {
            $onclick .= "setTimeout(function() { setLocratingIFrameProperties({'id': locrating_broadband_checker_frame_id, 'lat': jQuery('#'+locrating_broadband_checker_frame_id).data('lat'), 'lng' : jQuery('#'+locrating_broadband_checker_frame_id).data('lng'), 'type':'broadband', showmap : 'true'}); }, 10);";
        }
        if ( in_array('locrating_all_in_one', $item['tab_display']) )
        {
            $onclick .= "setTimeout(function() { setLocratingIFrameProperties({'id': locrating_all_in_one_frame_id, 'lat': jQuery('#'+locrating_all_in_one_frame_id).data('lat'), 'lng' : jQuery('#'+locrating_all_in_one_frame_id).data('lng'), 'type':'all'}); }, 10);";
        }
        return $onclick;
    }

    public function locrating_options_in_elementor_tabbed_details_display_options( $options )
    {
        $options['locrating_local_schools'] = 'Locrating Local Schools';
        $options['locrating_local_amenities'] = 'Locrating Local Amenities';
        $options['locrating_local_transport'] = 'Locrating Local Transport';
        $options['locrating_broadband_checker'] = 'Locrating Broadband Checker';
        $options['locrating_all_in_one'] = 'Locrating All-In-One';
        return $options;
    }


    public function output_locrating_in_elementor_tabbed_details( $property, $display )
    {
        if ( $display == 'locrating_local_schools' )
        {
            $this->propertyhive_locrating_schools_iframe();
        }
        if ( $display == 'locrating_local_amenities' )
        {
            $this->propertyhive_locrating_amenities_iframe();
        }
        if ( $display == 'locrating_local_transport' )
        {
            $this->propertyhive_locrating_transport_iframe();
        }
        if ( $display == 'locrating_broadband_checker' )
        {
            $this->propertyhive_locrating_broadband_checker_iframe();
        }
        if ( $display == 'locrating_all_in_one' )
        {
            $this->propertyhive_locrating_all_in_one_iframe();
        }
    }

    public function plugin_add_settings_link( $links )
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=ph-settings&tab=locrating') . '">' . __( 'Settings' ) . '</a>';
        array_push( $links, $settings_link );
        return $links;
    }

    /**
     * Define PH Locrating Constants
     */
    private function define_constants() 
    {
        define( 'PH_LOCRATING_PLUGIN_FILE', __FILE__ );
        define( 'PH_LOCRATING_VERSION', $this->version );
    }

    private function includes()
    {
        //include_once( dirname( __FILE__ ) . "/includes/class-ph-locrating-install.php" );
    }

    /**
     * Output error message if core Property Hive plugin isn't active
     */
    public function locrating_error_notices() 
    {
        if (!is_plugin_active('propertyhive/propertyhive.php'))
        {
            $message = __( "The Property Hive plugin must be installed and activated before you can use the Property Hive Locrating add-on", 'propertyhive' );
            echo"<div class=\"error\"> <p>$message</p></div>";
        }
    }

    public function locrating_schools_map_shortcode( $atts )
    {
        global $post, $property;

        ob_start();
        
        echo '<div class="locrating-schools-shortcode">';
        $this->propertyhive_locrating_schools_iframe();
        echo '</div>';
        return ob_get_clean();
    }

    public function locrating_amenities_map_shortcode( $atts )
    {
        global $post, $property;

        ob_start();
        echo '<div class="locrating-amenities-shortcode">';
        $this->propertyhive_locrating_amenities_iframe();
        echo '</div>';
        return ob_get_clean();
    }

    public function locrating_transport_map_shortcode( $atts )
    {
        global $post, $property;

        ob_start();
        echo '<div class="locrating-transport-shortcode">';
        $this->propertyhive_locrating_transport_iframe();
        echo '</div>';
        return ob_get_clean();
    }

    public function locrating_broadband_checker_map_shortcode( $atts )
    {
        global $post, $property;

        ob_start();
        echo '<div class="locrating-broadband-checker-shortcode">';
        $this->propertyhive_locrating_broadband_checker_iframe();
        echo '</div>';
        return ob_get_clean();
    }

    public function locrating_all_in_one_map_shortcode( $atts )
    {
        global $post, $property;

        ob_start();
        echo '<div class="locrating-all-in-one-shortcode">';
        $this->propertyhive_locrating_all_in_one_iframe();
        echo '</div>';
        return ob_get_clean();
    }

    public function locrating_action( $actions = array() )
    {
        global $post, $property;

        $property_coords = $this->ph_get_locrating_property_coords();

        if( ! empty($property_coords) ) {

            $current_settings = get_option( 'propertyhive_locrating', array() );

            $template = locate_template( array('propertyhive/locrating.php') );
            if ( !$template )
            {
                include( dirname( PH_LOCRATING_PLUGIN_FILE ) . '/templates/locrating.php' );
            }
            else
            {
                include( $template );
            }
            
        }

        return $actions;
    }

    private function propertyhive_locrating_schools_iframe()
    {
        global $post;

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', __FILE__ ) ) ) . '/assets/';

        wp_register_script( 
            'locrating', 
            'https://www.locrating.com/scripts/locratingIntegrationScripts.js', 
            NULL, 
            NULL,
            true
        );

        wp_enqueue_script('ph-locrating');
 
        wp_register_script( 
            'ph-locrating', 
            $assets_path . 'js/ph-locrating.js', 
            array('jquery','locrating'), 
            PH_LOCRATING_VERSION,
            true
        );

        wp_enqueue_script('ph-locrating');

        $property_coords = $this->ph_get_locrating_property_coords();

        $template = locate_template( array('propertyhive/locrating-schools-iframe.php') );
        if ( !$template )
        {
            include( dirname( PH_LOCRATING_PLUGIN_FILE ) . '/templates/locrating-schools-iframe.php' );
        }
        else
        {
            include( $template );
        }
    }

    private function propertyhive_locrating_amenities_iframe()
    {
        global $post;

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', __FILE__ ) ) ) . '/assets/';

        wp_register_script( 
            'locrating', 
            'https://www.locrating.com/scripts/locratingIntegrationScripts.js', 
            NULL, 
            NULL,
            true
        );

        wp_enqueue_script('ph-locrating');
 
        wp_register_script( 
            'ph-locrating', 
            $assets_path . 'js/ph-locrating.js', 
            array('jquery','locrating'), 
            PH_LOCRATING_VERSION,
            true
        );

        wp_enqueue_script('ph-locrating');

        $property_coords = $this->ph_get_locrating_property_coords();

        $template = locate_template( array('propertyhive/locrating-amenities-iframe.php') );
        if ( !$template )
        {
            include( dirname( PH_LOCRATING_PLUGIN_FILE ) . '/templates/locrating-amenities-iframe.php' );
        }
        else
        {
            include( $template );
        }
    }

    private function propertyhive_locrating_transport_iframe()
    {
        global $post;

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', __FILE__ ) ) ) . '/assets/';

        wp_register_script( 
            'locrating', 
            'https://www.locrating.com/scripts/locratingIntegrationScripts.js', 
            NULL, 
            NULL,
            true
        );

        wp_enqueue_script('ph-locrating');
 
        wp_register_script( 
            'ph-locrating', 
            $assets_path . 'js/ph-locrating.js', 
            array('jquery','locrating'), 
            PH_LOCRATING_VERSION,
            true
        );

        wp_enqueue_script('ph-locrating');

        $property_coords = $this->ph_get_locrating_property_coords();

        $template = locate_template( array('propertyhive/locrating-transport-iframe.php') );
        if ( !$template )
        {
            include( dirname( PH_LOCRATING_PLUGIN_FILE ) . '/templates/locrating-transport-iframe.php' );
        }
        else
        {
            include( $template );
        }
    }

    private function propertyhive_locrating_broadband_checker_iframe()
    {
        global $post;

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', __FILE__ ) ) ) . '/assets/';

        wp_register_script( 
            'locrating', 
            'https://www.locrating.com/scripts/locratingIntegrationScripts.js', 
            NULL, 
            NULL,
            true
        );

        wp_enqueue_script('ph-locrating');
 
        wp_register_script( 
            'ph-locrating', 
            $assets_path . 'js/ph-locrating.js', 
            array('jquery','locrating'), 
            PH_LOCRATING_VERSION,
            true
        );

        wp_enqueue_script('ph-locrating');

        $property_coords = $this->ph_get_locrating_property_coords();

        $template = locate_template( array('propertyhive/locrating-broadband-checker-iframe.php') );
        if ( !$template )
        {
            include( dirname( PH_LOCRATING_PLUGIN_FILE ) . '/templates/locrating-broadband-checker-iframe.php' );
        }
        else
        {
            include( $template );
        }
    }

    private function propertyhive_locrating_all_in_one_iframe()
    {
        global $post;

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', __FILE__ ) ) ) . '/assets/';

        wp_register_script( 
            'locrating', 
            'https://www.locrating.com/scripts/locratingIntegrationScripts.js', 
            NULL, 
            NULL,
            true
        );

        wp_enqueue_script('ph-locrating');
 
        wp_register_script( 
            'ph-locrating', 
            $assets_path . 'js/ph-locrating.js', 
            array('jquery','locrating'), 
            PH_LOCRATING_VERSION,
            true
        );

        wp_enqueue_script('ph-locrating');

        $property_coords = $this->ph_get_locrating_property_coords();

        $template = locate_template( array('propertyhive/locrating-all-in-one-iframe.php') );
        if ( !$template )
        {
            include( dirname( PH_LOCRATING_PLUGIN_FILE ) . '/templates/locrating-all-in-one-iframe.php' );
        }
        else
        {
            include( $template );
        }
    }

    private function ph_get_locrating_property_coords()
    {
        global $post;
        
        $property_meta = get_post_custom($post->ID);
        
        $property_coords = array();
        if ( ! empty($property_meta['_latitude'][0]) && ! empty($property_meta['_longitude'][0]) ) {
            $property_coords['lat'] = $property_meta['_latitude'][0];
            $property_coords['lng'] = $property_meta['_longitude'][0];
        }

        return $property_coords;
    }

    /**
     * Add a new settings tab to the Property Hive settings tabs array.
     *
     * @param array $settings_tabs Array of Property Hive setting tabs & their labels
     * @return array $settings_tabs Array of Property Hive setting tabs & their labels
     */
    public function add_settings_tab( $settings_tabs ) {
        $settings_tabs[$this->id] = $this->label;
        return $settings_tabs;
    }

    /**
     * Uses the Property Hive admin fields API to output settings.
     *
     * @uses propertyhive_admin_fields()
     * @uses self::get_settings()
     */
    public function output() {

        global $current_section;
        
        propertyhive_admin_fields( self::get_locrating_settings() );
    }

    /**
     * Get locrating settings
     *
     * @return array Array of settings
     */
    public function get_locrating_settings() {

        global $post;

        $current_settings = get_option( 'propertyhive_locrating', array() );

        $settings = array(

            array( 'title' => __( 'Locrating Settings', 'propertyhive' ), 'type' => 'title', 'desc' => '', 'id' => 'locrating_settings' )

        );

        $settings[] = array(
            'title'     => __( 'I have a Locrating subscription', 'propertyhive' ),
            'id'        => 'enabled',
            'type'      => 'checkbox',
            'default'   => ( isset($current_settings['enabled']) && $current_settings['enabled'] == 1 ? 'yes' : ''),
            'desc_tip'  => true,
            'desc'      => 'Please only enable the Locrating add on if you have a Locrating subscription.<br>A Locrating subscription can be obtained by contacting <a href="mailto:support@locrating.com">support@locrating.com</a>.'
        );

        $settings[] = array(
            'title'     => __( 'Add Local Schools Button', 'propertyhive' ),
            'id'        => 'local_schools_button',
            'type'      => 'checkbox',
            'default'   => ( !isset($current_settings['local_schools_button']) || ( isset($current_settings['local_schools_button']) && $current_settings['local_schools_button'] == 1 ) ? 'yes' : ''),
        );

        $settings[] = array(
            'title'     => __( 'Add Local Amenities Button', 'propertyhive' ),
            'id'        => 'local_amenities_button',
            'type'      => 'checkbox',
            'default'   => ( !isset($current_settings['local_amenities_button']) || ( isset($current_settings['local_amenities_button']) && $current_settings['local_amenities_button'] == 1 ) ? 'yes' : ''),
        );

        $settings[] = array(
            'title'     => __( 'Add Local Transport Button', 'propertyhive' ),
            'id'        => 'local_transport_button',
            'type'      => 'checkbox',
            'default'   => ( !isset($current_settings['local_transport_button']) || ( isset($current_settings['local_transport_button']) && $current_settings['local_transport_button'] == 1 ) ? 'yes' : ''),
        );

        $settings[] = array(
            'title'     => __( 'Add Broadband Checker Button', 'propertyhive' ),
            'id'        => 'broadband_checker_button',
            'type'      => 'checkbox',
            'default'   => ( !isset($current_settings['broadband_checker_button']) || ( isset($current_settings['broadband_checker_button']) && $current_settings['broadband_checker_button'] == 1 ) ? 'yes' : ''),
        );

        $settings[] = array(
            'title'     => __( 'Add All-In-One Button', 'propertyhive' ),
            'id'        => 'all_in_one_button',
            'type'      => 'checkbox',
            'default'   => ( ( isset($current_settings['all_in_one_button']) && $current_settings['all_in_one_button'] == 1 ) ? 'yes' : ''),
            'desc'      => 'The all-in-one map will show schools, amentities, sold prices, transport, broadband speeds and more all within the same popup. The label of the button can changed using the \'Text Substition\' feature of our free Template Assistant add on',
            'desc_tip'  => TRUE
        );

        $settings[] = array( 'type' => 'sectionend', 'id' => 'locrating_settings');

        $settings[] = array( 'title' => __( 'Shortcodes', 'propertyhive' ), 'type' => 'title', 'desc' => '', 'id' => 'locrating_shortcodes' );

        $settings[] = array(
            'type'      => 'html',
            'html'      => 'If you\'d prefer to embed the Locrating maps direct into a template instead of showing the buttons we have the following shortcodes available:<br><br>
            <pre>[locrating_schools_map]</pre>
            <pre>[locrating_amenities_map]</pre>
            <pre>[locrating_transport_map]</pre>
            <pre>[locrating_all_in_one_map]</pre>
            <pre>[locrating_broadband_checker_map]</pre>',
        );

        $settings[] = array( 'type' => 'sectionend', 'id' => 'locrating_shortcodes');

        return $settings;
    }

    /**
     * Uses the Property Hive options API to save settings.
     *
     * @uses propertyhive_update_options()
     * @uses self::get_settings()
     */
    public function save() {

        $existing_propertyhive_locrating = get_option( 'propertyhive_locrating', array() );

        $propertyhive_locrating = array(
            'enabled' => ( (isset($_POST['enabled'])) ? $_POST['enabled'] : '' ),
            'local_schools_button' => ( (isset($_POST['local_schools_button'])) ? $_POST['local_schools_button'] : '' ),
            'local_amenities_button' => ( (isset($_POST['local_amenities_button'])) ? $_POST['local_amenities_button'] : '' ),
            'local_transport_button' => ( (isset($_POST['local_transport_button'])) ? $_POST['local_transport_button'] : '' ),
            'broadband_checker_button' => ( (isset($_POST['broadband_checker_button'])) ? $_POST['broadband_checker_button'] : '' ),
            'all_in_one_button' => ( (isset($_POST['all_in_one_button'])) ? $_POST['all_in_one_button'] : '' ),
        );

        $propertyhive_locrating = array_merge( $existing_propertyhive_locrating, $propertyhive_locrating );

        update_option( 'propertyhive_locrating', $propertyhive_locrating );
    }
}

endif;

/**
 * Returns the main instance of PH_Locrating to prevent the need to use globals.
 *
 * @since  1.0.0
 * @return PH_Locrating
 */
function PHLOC() {
    return PH_Locrating::instance();
}

$PHLOC = PHLOC();

if( is_admin() && file_exists(  dirname( __FILE__ ) . '/propertyhive-locrating-update.php' ) )
{
    include_once( dirname( __FILE__ ) . '/propertyhive-locrating-update.php' );
}