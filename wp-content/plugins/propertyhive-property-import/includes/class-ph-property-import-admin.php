<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Admin Functions
 */
class PH_Property_Import_Admin {

	public function __construct() {

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );

        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

        add_filter( "plugin_action_links_" . plugin_basename( PH_PROPERTYIMPORT_PLUGIN_FILE ), array( $this, 'plugin_add_settings_link' ) );
        
        add_action( 'admin_init', array( $this, 'check_for_delete' ) );
        add_action( 'admin_init', array( $this, 'check_clone'), 11 );
        add_action( 'admin_init', array( $this, 'toggle_import_running_status') );

        add_filter( 'posts_join', array( $this, 'posts_join' ), 11, 2 );
        add_filter( 'posts_where', array( $this, 'posts_where' ), 11, 2 );
        add_filter( 'propertyhive_admin_property_column_address_details', array( $this, 'crm_id_in_property_admin_list' ), 10, 2 );

        add_filter( 'propertyhive_property_filters', array( $this, 'property_import_property_filters' ) );
        add_filter( 'propertyhive_property_filter_query', array( $this, 'property_import_property_filter_query' ), 10, 2 );
	
        add_filter( 'propertyhive_use_google_maps_geocoding_api_key', array( $this, 'enable_separate_geocoding_api_key' ) );

    }

	/**
     * Enqueue styles
     */
    public function admin_styles() 
    {
        $screen = get_current_screen();
        
        if ( strpos($screen->id, 'page_propertyhive_import_properties') !== FALSE )
        {
            $suffix       = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
            $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', PH_PROPERTYIMPORT_PLUGIN_FILE ) ) ) . '/assets/';
            
            wp_enqueue_style( 'propertyhive_property_import_admin_styles', $assets_path . '/css/admin.css', array(), PH_PROPERTYIMPORT_VERSION );
            wp_enqueue_style( 'select2_styles', $assets_path . 'css/select2.min.css', array(), '4.0.13' );
            wp_enqueue_style( 'propertyhive_fancybox_css', $assets_path . 'css/jquery.fancybox' . $suffix . '.css', array(), '3.5.7' );
        }
    }

    /**
     * Enqueue scripts
     */
    public function admin_scripts() {

        $suffix       = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

        $assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', PH_PROPERTYIMPORT_PLUGIN_FILE ) ) ) . '/assets/';

        // Register scripts
        wp_register_script( 'propertyhive_property_import_edit_property', $assets_path . 'js/edit-property' . /*$suffix .*/ '.js', array( 'jquery' ), PH_PROPERTYIMPORT_VERSION );
        wp_register_script( 'propertyhive_property_import_edit_import', $assets_path . 'js/edit-import' . /*$suffix .*/ '.js', array( 'jquery' ), PH_PROPERTYIMPORT_VERSION );
        wp_register_script( 'propertyhive_property_import_admin', $assets_path . 'js/admin' . /*$suffix .*/ '.js', array( 'jquery' ), PH_PROPERTYIMPORT_VERSION );
        wp_register_script( 'propertyhive_property_import_imports_table', $assets_path . 'js/imports-table' . /*$suffix .*/ '.js', array( 'jquery' ), PH_PROPERTYIMPORT_VERSION );
        wp_register_script( 'propertyhive_property_import_settings', $assets_path . 'js/settings' . /*$suffix .*/ '.js', array( 'jquery' ), PH_PROPERTYIMPORT_VERSION );
        
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
                        wp_enqueue_script( 'propertyhive_property_import_admin' );
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

                    if ( ( $option['format'] == 'json_loop' || $option['format'] == 'json_loop_v2' ) && isset($option['running']) && $option['running'] == 1 )
                    {
                        wp_enqueue_script( 'propertyhive_property_import_admin' );
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
                        wp_enqueue_script( 'propertyhive_property_import_admin' );
                        break;
                    }
                }
            }
        }

        wp_enqueue_script( 'propertyhive_property_import_admin' );

        $screen = get_current_screen();

        if ( $screen->id == 'property' )
        {
            wp_enqueue_script( 'propertyhive_property_import_edit_property' );
        }

        if ( strpos($screen->id, 'page_propertyhive_import_properties') !== FALSE )
        {
            if ( isset($_GET['action']) && in_array($_GET['action'], array('addimport', 'editimport')) )
            {
                // enqueue draggable/droppable for CSV/XML field mapping
                wp_enqueue_script( 'jquery-ui-draggable' );
                wp_enqueue_script( 'jquery-ui-droppable' );
                wp_enqueue_script( 'jquery-ui-sortable' );

                $taxonomies = propertyhive_property_import_taxonomies_for_mapping();

                $availability_departments = get_option( 'propertyhive_availability_departments', array() );
                if ( !is_array($availability_departments) ) { $availability_departments = array(); }

                $departments = ph_get_departments();

                foreach ( $taxonomies as $taxonomy )
                {
                    ${$taxonomy['import_taxonomy']} = array();

                    // department check
                    if ( isset($taxonomy['departments']) && is_array($taxonomy['departments']) && !empty($taxonomy['departments']) )
                    {
                        $at_least_one_active_department = false;

                        foreach ( $taxonomy['departments'] as $department )
                        {
                            if ( get_option( 'propertyhive_active_departments_' . str_replace("residential-", "", $department) ) == 'yes' )
                            {
                                $at_least_one_active_department = true;
                            }
                        }

                        if ( !$at_least_one_active_department )
                        {
                            // continue if department(s) not active
                            continue;
                        }
                    }

                    $terms = get_terms( array(
                        'taxonomy'   => $taxonomy['propertyhive_taxonomy'],
                        'hide_empty' => false,
                    ) );

                    if ( is_array($terms) && !empty($terms) )
                    {
                        foreach ( $terms as $term )
                        {
                            if ( $taxonomy['propertyhive_taxonomy'] == 'availability' )
                            {
                                $availability_belongs_to_department = false;

                                // only get availabilities for this department
                                if ( isset($availability_departments[$term->term_id]) && !empty($availability_departments[$term->term_id]) )
                                {
                                    foreach ( $availability_departments[$term->term_id] as $availability_department )
                                    {
                                        if ( isset($taxonomy['departments']) && is_array($taxonomy['departments']) && !empty($taxonomy['departments']) )
                                        {
                                            if ( in_array($availability_department, $taxonomy['departments']) )
                                            {
                                                $availability_belongs_to_department = true;
                                            }
                                        }
                                    }
                                }
                                else
                                {
                                    $availability_belongs_to_department = true;
                                }

                                if ( $availability_belongs_to_department === true )
                                {
                                    ${$taxonomy['import_taxonomy']}[(int)$term->term_id] = $term->name;
                                }
                            }
                            else
                            {
                                ${$taxonomy['import_taxonomy']}[(int)$term->term_id] = $term->name;
                            }
                        }
                    }
                }

                $formats = propertyhive_property_import_get_import_formats();

                $import_id = ( isset($_GET['import_id']) && !empty(sanitize_text_field($_GET['import_id'])) ) ? (int)$_GET['import_id'] : false;

                $import_settings = array();

                if ( $import_id !== false )
                {
                    $options = get_option( 'propertyhive_property_import' , array() );

                    $imports = ( isset($options) && is_array($options) && !empty($options) ) ? $options : array();
                    if ( isset($imports[$import_id]) )
                    {
                        $import_settings = $imports[$import_id];
                    }
                }

                // get fields referenced in import format file to show warning if field also gets mapped in 'Field Mapping' section
                $format_fields_imported_by_default = array();
                foreach ( $formats as $key => $format )
                {
                    if ( !isset($formats[$key]['propertyhive_fields_imported_by_default']) )
                    {
                        $formats[$key]['propertyhive_fields_imported_by_default'] = array();
                    }

                    if ( isset($format['file']) && file_exists($format['file']) )
                    {
                        $import_file_contents = file_get_contents($format['file']);

                        if ( !empty($import_file_contents) )
                        {
                            // get all 'update_post_meta' calls already imported in format
                            preg_match_all('/update_post_meta\s*\(\s*[^,]+,\s*([\'"])(.*?)\1/', $import_file_contents, $matches);
                            if ( isset($matches[2]) && is_array($matches[2]) && !empty($matches[2]) )
                            {
                                foreach ( $matches[2] as $match )
                                {
                                    $formats[$key]['propertyhive_fields_imported_by_default'][] = $match;
                                }
                            }

                            // get all taxonomies already mapped
                            preg_match_all('/wp_set_object_terms\((.*?)[\)]+[;]+/', $import_file_contents, $matches);
                            if ( isset($matches[1]) && is_array($matches[1]) && !empty($matches[1]) )
                            {
                                foreach ( $matches[1] as $match )
                                {
                                    $explode_set_object_terms = explode(",", $match);
                                    if ( count($explode_set_object_terms) >= 3 )
                                    {
                                        $taxonomy = str_replace(array('"', "'"), "", trim($explode_set_object_terms[2]));
                                        $formats[$key]['propertyhive_fields_imported_by_default'][] = trim($taxonomy);
                                    }
                                }
                            }
                        }
                        else
                        {
                            //echo 'File ' . $format_file . ' contents empty';
                        }
                    }
                    else
                    {
                        //echo 'File ' . $format_file . ' not found';
                    }

                    if ( $key != 'xml' && $key != 'csv' )
                    {
                        $formats[$key]['propertyhive_fields_imported_by_default'][] = 'post_title';
                        $formats[$key]['propertyhive_fields_imported_by_default'][] = 'post_excerpt';
                        $formats[$key]['propertyhive_fields_imported_by_default'][] = 'post_content';
                    }

                    $formats[$key]['propertyhive_fields_imported_by_default'] = array_unique($formats[$key]['propertyhive_fields_imported_by_default']);
                    $formats[$key]['propertyhive_fields_imported_by_default'] = array_filter($formats[$key]['propertyhive_fields_imported_by_default']);
                }

                wp_localize_script( 'propertyhive_property_import_edit_import', 'phpi_admin_object', array( 
                    'ajax_nonce' => wp_create_nonce("phpi_ajax_nonce"),
                    'action' => ( ( isset($_GET['action']) && in_array(sanitize_text_field($_GET['action']), array('addimport', 'editimport')) ) ? sanitize_text_field($_GET['action']) : '' ),
                    'import_id' => isset($_GET['import_id']) && is_numeric($_GET['import_id']) ? (int)$_GET['import_id'] : '',
                    'formats' => $formats,
                    'import_settings' => $import_settings,
                    'ph_taxonomy_terms' => array(
                        'sales_availability' => $sales_availability,
                        'lettings_availability' => $lettings_availability,
                        'commercial_availability' => $commercial_availability,
                        'property_type' => $property_type,
                        'commercial_property_type' => $commercial_property_type,
                        'price_qualifier' => $price_qualifier,
                        'sale_by' => $sale_by,
                        'tenure' => $tenure,
                        'commercial_tenure' => $commercial_tenure,
                        'furnished' => $furnished,
                        'parking' => $parking,
                        'outside_space' => $outside_space,
                        'location' => $location,
                    ),
                    'propertyhive_fields_for_field_mapping' => propertyhive_property_import_get_fields_for_field_mapping(),
                ) );

                wp_enqueue_script( 'propertyhive_property_import_edit_import' );

                wp_register_script( 'select2', $assets_path . 'js/select2.min.js', array( 'jquery' ), '4.0.13' );
                wp_enqueue_script( 'select2' );

                wp_register_script( 'propertyhive_fancybox', $assets_path . 'js/fancybox/jquery.fancybox' . $suffix . '.js', array( 'jquery' ), '3.5.7', true );
                wp_enqueue_script( 'propertyhive_fancybox' );
            }
            elseif ( isset($_GET['tab']) && $_GET['tab'] == 'settings' )
            {
                wp_enqueue_script( 'propertyhive_property_import_settings' );
            }
            elseif ( isset($_GET['tab']) && $_GET['tab'] == 'troubleshooting' )
            {
                wp_register_script( 'select2', $assets_path . 'js/select2.min.js', array( 'jquery' ), '4.0.13' );
                wp_enqueue_script( 'select2' );
            }
            else
            {
                wp_localize_script( 'propertyhive_property_import_imports_table', 'phpi_admin_object', array( 
                    'ajax_nonce' => wp_create_nonce("phpi_ajax_nonce"),
                    'table_order' => ( isset($_GET['order']) ? sanitize_text_field($_GET['order']) : '' ),
                    'table_orderby' => ( isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : '' ),
                    'table_phpi_filter' => ( isset($_GET['phpi_filter']) ? sanitize_text_field($_GET['phpi_filter']) : '' ),
                    'table_phpi_filter_format' => ( isset($_GET['phpi_filter_format']) ? sanitize_text_field($_GET['phpi_filter_format']) : '' ),
                    'table_refresh_automatic_imports' => apply_filters( 'propertyhive_property_import_table_refresh_automatic_imports', 30000 ),
                    'table_refresh_status_interval' => apply_filters( 'propertyhive_property_import_table_refresh_status_interval', 5000 )
                ) );

                wp_enqueue_script( 'propertyhive_property_import_imports_table' );
            }
        }
    }

    /**
     * Admin Menu
     */
    public function admin_menu() 
    {
        add_submenu_page( 'propertyhive', __( 'Import Properties', 'propertyhive' ),  __( 'Import Properties', 'propertyhive' ) , 'manage_propertyhive', 'propertyhive_import_properties', array( $this, 'admin_page' ) );
    }

    public function admin_page()
    {
        global $wpdb, $post;

        $tabs = array(
            '' => __( 'Automatic Imports', 'propertyhive' ),
            // maybe manual import tab
            'logs' => __( 'Logs', 'propertyhive' ),
            'settings' => __( 'Settings', 'propertyhive' ),
            'troubleshooting' => __( 'Troubleshooting Wizard', 'propertyhive' ),
        );

        $active_tab = ( isset($_GET['tab']) && !empty(sanitize_text_field($_GET['tab'])) ) ? sanitize_text_field($_GET['tab']) : '';

        $options = get_option( 'propertyhive_property_import' , array() );

        include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-header.php' );

        switch ( $active_tab )
        {
            case "logs":
            {
                include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-primary-nav.php' );

                if ( 
                    isset($_GET['action']) && 
                    (
                        sanitize_text_field($_GET['action']) == 'view' ||
                        (
                            sanitize_text_field($_GET['action']) == 'search' && isset($_POST['log_search']) && sanitize_text_field($_POST['log_search']) != ''
                        )
                    )
                )
                {
                    switch ( sanitize_text_field($_GET['action']) )
                    {
                        case "view":
                        {
                            include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/class-ph-property-import-logs-view-table.php' );

                            $logs_view_table = new PH_Property_Import_Logs_View_Table();
                            $logs_view_table->prepare_items();

                            $previous_instance = false;
                            $next_instance = false;

                            $logs = $wpdb->get_results( 
                                $wpdb->prepare("
                                SELECT * 
                                FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                                INNER JOIN 
                                    " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 ON  " . $wpdb->prefix . "ph_propertyimport_instance_v3.id = " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.instance_id
                                WHERE 
                                    " . ( ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) ) ? " import_id = '" . (int)$_GET['import_id'] . "' AND " : "" ) . "
                                    instance_id < %d
                                GROUP BY " . $wpdb->prefix . "ph_propertyimport_instance_v3.id
                                ORDER BY start_date DESC
                                LIMIT 1
                                ", (int)$_GET['log_id'])
                            );

                            if ( $logs )
                            {
                                foreach ( $logs as $log ) 
                                {
                                    $previous_instance = $log->instance_id;
                                }
                            }

                            $logs = $wpdb->get_results( 
                                $wpdb->prepare("
                                SELECT * 
                                FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                                INNER JOIN 
                                    " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 ON  " . $wpdb->prefix . "ph_propertyimport_instance_v3.id = " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.instance_id
                                WHERE 
                                     " . ( ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) ) ? " import_id = '" . (int)$_GET['import_id'] . "' AND " : "" ) . "
                                    instance_id > %d
                                GROUP BY " . $wpdb->prefix . "ph_propertyimport_instance_v3.id
                                ORDER BY start_date ASC
                                LIMIT 1
                                ", (int)$_GET['log_id'])
                            );

                            if ( $logs )
                            {
                                foreach ( $logs as $log ) 
                                {
                                    $next_instance = $log->instance_id;
                                }
                            }

                            include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-logs-view.php' );
                            break;
                        }
                        case "search":
                        {
                            include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/class-ph-property-import-logs-search-import-table.php' );

                            $log_tables = array();

                            $extra_sql = '';
                            if ( is_numeric($_POST['log_search']) )
                            {
                                $extra_sql = " post_id = '" . esc_sql($_POST['log_search']) . "'
                                        OR ";
                            }

                            $like_log_search = '%' . esc_sql($_POST['log_search']) . '%';

                            $query = $wpdb->prepare("
                                SELECT " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.id
                                FROM " . $wpdb->prefix . "ph_propertyimport_instance_v3
                                INNER JOIN 
                                    " . $wpdb->prefix . "ph_propertyimport_instance_log_v3 ON  " . $wpdb->prefix . "ph_propertyimport_instance_v3.id = " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.instance_id
                                LEFT JOIN 
                                    " . $wpdb->posts . " ON " . $wpdb->posts . ".ID = " . $wpdb->prefix . "ph_propertyimport_instance_log_v3.post_id
                                WHERE 
                                     " . ( ( isset($_GET['import_id']) && !empty((int)$_GET['import_id']) ) ? " import_id = '" . (int)$_GET['import_id'] . "' AND " : "" ) . "
                                    (
                                        " . $extra_sql . "
                                        crm_id = %s
                                        OR 
                                        " . $wpdb->posts . ".post_title LIKE %s
                                    )
                                GROUP BY " . $wpdb->prefix . "ph_propertyimport_instance_v3.id
                                ORDER BY start_date ASC
                            ", $_POST['log_search'], $like_log_search);

                            $log_results = $wpdb->get_results( 
                                $query
                            );

                            if ( $log_results )
                            {
                                foreach ( $log_results as $log_result ) 
                                {
                                    $logs_search_table = new PH_Property_Import_Logs_Search_Table(array(), $log_result->id);
                                    $logs_search_table->prepare_items();

                                    $log_tables[] = $logs_search_table;
                                }
                            }

                            include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-logs-search.php' );
                            break;
                        }
                    }
                    
                }
                else
                {
                    include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/class-ph-property-import-logs-table.php' );

                    $logs_table = new PH_Property_Import_Logs_Table();
                    $logs_table->prepare_items();

                    include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-logs.php' );
                }

                break;
            }
            case "settings":
            {
                include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-primary-nav.php' );
                include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-settings.php' );
                break;
            }
            case "troubleshooting":
            {
                include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-primary-nav.php' );
                include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-troubleshooting-wizard.php' );
                break;
            }
            default:
            {
                $active_tab = ( isset($_GET['action']) && !empty(sanitize_text_field($_GET['action'])) ) ? sanitize_text_field($_GET['action']) : '';

                if ( $active_tab == 'addimport' || $active_tab == 'editimport' )
                {
                    $import_id = ( isset($_GET['import_id']) && !empty(sanitize_text_field($_GET['import_id'])) ) ? (int)$_GET['import_id'] : false;

                    $frequencies = propertyhive_property_import_get_import_frequencies();

                    $import_settings = array();
                    if ( $active_tab == 'editimport' )
                    {
                        $imports = ( is_array($options) && !empty($options) ) ? $options : array();
                        if ( isset($imports[$import_id]) )
                        {
                            $import_settings = $imports[$import_id];
                        }
                    }

                    $formats = propertyhive_property_import_get_import_formats();

                    include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-import-settings.php' );
                }
                else
                {
                    include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-primary-nav.php' );

                    $run_now_button = false;
                    $imports = ( is_array($options) && !empty($options) ) ? $options : array();
                    foreach ( $imports as $import_id => $import_settings )
                    {
                        if ( !isset($import_settings['running']) || ( isset($import_settings['running']) && $import_settings['running'] != 1 ) )
                        {
                            continue;
                        }

                        if ( isset($import_settings['format']) && in_array($import_settings['format'], array('rtdf', 'xml_webedge')) )
                        {
                            continue;
                        }

                        if ( isset($import_settings['deleted']) && $import_settings['deleted'] == 1 )
                        {
                            continue;
                        }

                        $run_now_button = true;
                        continue;
                    }

                    include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-automatic-imports.php' );
                }
            }
        }

        include( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/includes/views/admin-footer.php' );
    }

    public function plugin_add_settings_link( $links )
    {
        $settings_link = '<a href="' . admin_url('admin.php?page=propertyhive_import_properties') . '">' . __( 'Manage Imports' ) . '</a>';
        array_push( $links, $settings_link );

        $docs_link = '<a href="https://docs.wp-property-hive.com/add-ons/property-import/" target="_blank">' . __( 'Documentation' ) . '</a>';
        array_push( $links, $docs_link );
        
        return $links;
    }

    public function posts_join( $join, $q ) {
        global $typenow, $wp_query, $wpdb;

        if ( !is_admin() )
            return $join;

        if ( !$q->is_main_query() )
            return $join;

        if ( !isset($_GET['s']) || ( isset($_GET['s']) && ph_clean($_GET['s']) == '' ) )
            return $join;

        if ( 'property' === $typenow ) 
        {
            $join .= " 
LEFT JOIN " . $wpdb->postmeta . " AS ph_property_filter_meta_imported_ref ON " . $wpdb->posts . ".ID = ph_property_filter_meta_imported_ref.post_id AND ph_property_filter_meta_imported_ref.meta_key LIKE '\\_imported\\_ref\\_%'
";
        }

        return $join;
    }

    public function check_for_delete()
    {
        if ( isset($_GET['action']) && $_GET['action'] == 'deleteimport' && isset($_GET['import_id']) )
        {
            $import_id = !empty($_GET['import_id']) ? (int)$_GET['import_id'] : '';

            if ( !isset($_GET['_wpnonce']) || !check_admin_referer('delete-import') )
            {
                wp_redirect( admin_url( 'admin.php?page=propertyhive_import_properties&phpierrormessage=' . base64_encode(urlencode( __( 'Security check failed', 'propertyhive' ) ) ) ) );
                die();
            }

            if ( empty($import_id) )
            {
                wp_redirect( admin_url( 'admin.php?page=propertyhive_import_properties&phpierrormessage=' . base64_encode(urlencode( __( 'No import passed', 'propertyhive' ) ) ) ) );
                die();
            }

            $options = get_option( 'propertyhive_property_import', array() );
            
            if ( !isset($options[$import_id]) )
            {
                wp_redirect( admin_url( 'admin.php?page=propertyhive_import_properties&phpierrormessage=' . base64_encode(urlencode( __( 'Import not found', 'propertyhive' ) ) ) ) );
                die();
            }

            $options[$import_id]['running'] = '';
            $options[$import_id]['deleted'] = 1;
            $options[$import_id] = $this->remove_api_details($import_id, $options[$import_id]);

            update_option( 'propertyhive_property_import', $options );

            wp_redirect( admin_url( 'admin.php?page=propertyhive_import_properties&phpisuccessmessage=' . base64_encode(urlencode( __( 'Import deleted successfully', 'propertyhive' ) ) ) ) );
            die();
        }
    }

    public function check_clone()
    {
        if ( isset($_GET['action']) && $_GET['action'] == 'cloneimport' )
        {
            $import_id = ( isset($_GET['import_id']) && !empty(sanitize_text_field($_GET['import_id'])) ) ? (int)$_GET['import_id'] : '';

            $redirect_url = 'admin.php?page=propertyhive_import_properties';
            if ( isset($_REQUEST['orderby']) && !empty($_REQUEST['orderby']) && isset($_REQUEST['order']) && in_array(strtolower($_REQUEST['order']), array('asc', 'desc')) )
            {
                $redirect_url .= '&orderby=' . sanitize_text_field($_REQUEST['orderby']) . '&order=' . sanitize_text_field($_REQUEST['order']);
            }

            if ( !isset($_GET['_wpnonce']) || !check_admin_referer('clone-import') )
            {
                wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(urlencode( __( 'Security check failed', 'propertyhive' ) ) ) ) );
                die();
            }

            if ( empty($import_id) )
            {
                wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(urlencode(__( 'No import ID to clone found', 'propertyhive' ) ) ) ) );
                die();
            }

            $options = get_option( 'propertyhive_property_import' , array() );

            $imports = ( isset($options) && is_array($options) && !empty($options) ) ? $options : array();
            if ( !isset($imports[$import_id]) )
            {
                wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(urlencode(__( 'Import wanting to clone not found', 'propertyhive' ) ) ) ) );
                die();
            }

            $import_settings = $imports[$import_id];

            $new_import_id = time();

            $import_settings['running'] = '';
            $options[$new_import_id] = $import_settings;

            update_option( 'propertyhive_property_import', $options );

            wp_redirect( admin_url( $redirect_url . '&phpisuccessmessage=' . base64_encode(urlencode(__( 'Import cloned successfully', 'propertyhive' ) ) ) ) );
            die();
        }
    }

    public function toggle_import_running_status()
    {
        if ( isset($_GET['action']) && in_array(sanitize_text_field($_GET['action']), array("startimport", "pauseimport")) && isset($_GET['import_id']) )
        {
            $import_id = !empty($_GET['import_id']) ? (int)$_GET['import_id'] : '';

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

            if ( !isset($_GET['_wpnonce']) || !check_admin_referer('toggle-import-status') )
            {
                wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(urlencode( __( 'Security check failed', 'propertyhive' ) ) ) ) );
                die();
            }

            if ( empty($import_id) )
            {
                wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(__( 'No import passed', 'propertyhive' ) ) ) );
                die();
            }

            $options = get_option( 'propertyhive_property_import', array() );
            
            if ( !isset($options[$import_id]) )
            {
                wp_redirect( admin_url( $redirect_url . '&phpierrormessage=' . base64_encode(__( 'Import not found', 'propertyhive' ) ) ) );
                die();
            }

            switch ( sanitize_text_field($_GET['action']) )
            {
                case "startimport":
                {
                    $options[$import_id]['running'] = 1;

                    update_option( 'propertyhive_property_import', $options );

                    update_option( 'propertyhive_property_import_property_' . $import_id, '', false );

                    do_action( 'propertyhive_property_import_changed_running_status', ph_clean($_GET['import_id']), true );

                    wp_redirect( admin_url( $redirect_url . '&phpisuccessmessage=' . base64_encode(__( 'Import started', 'propertyhive' ) ) ) );
                    die();

                    break;

                }
                case "pauseimport":
                {
                    global $wpdb;

                    $options[$import_id]['running'] = '';

                    update_option( 'propertyhive_property_import', $options );

                    update_option( 'propertyhive_property_import_property_' . $import_id, '', false );

                    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}ph_propertyimport_media_queue WHERE import_id = %d", $import_id));

                    do_action( 'propertyhive_property_import_changed_running_status', ph_clean($_GET['import_id']), false );

                    wp_redirect( admin_url( $redirect_url . '&phpisuccessmessage=' . base64_encode(__( 'Import paused', 'propertyhive' ) ) ) );
                    die();

                    break;

                }
            }
        }
    }

    private function remove_api_details($import_id, $option)
    {
        $fields_to_clear = array();
        switch ( $option['format'] )
        {
            case "xml_expertagent":
            case "thesaurus":
            case "xml_caldes_remote":
            {
                $fields_to_clear = ['ftp_user', 'ftp_pass'];
                break;
            }
            case "blm_remote":
            case "xml_propertyadd":
            case "xml_gnomen":
            case "xml_inmovilla":
            case "xml_agestanet":
            case "xml_getrix":
            case "xml_kyero":
            case "xml_inmoweb":
            case "xml_xml2u":
            case "xml_ego":
            case "xml_inmobalia":
            case "xml_thinkspain":
            case "xml_resales_online":
            case "xml_juvo":
            case "csv_roby":
            {
                $fields_to_clear = ['url'];
                break;
            }
            case "xml_jupix":
            case "xml_property_finder_uae":
            case "xml_acquaint":
            case "xml_citylets":
            case "xml_sme_professional":
            case "xml_10ninety":
            case "xml_domus":
            case "xml_agentsinsight":
            case "xml_apex27":
            {
                $fields_to_clear = ['xml_url'];
                break;
            }
            case "json_dezrez":
            case "json_letmc":
            case "json_realla":
            case "json_utili":
            case "xml_virtualneg":
            case "json_bookster":
            case "json_street":
            case "xml_clarks_computers":
            case "api_resales_online":
            {
                $fields_to_clear = ['api_key'];
                break;
            }
            case "json_agentbox":
            {
                $fields_to_clear = ['api_key', 'client_id'];
                break;
            }
            case "xml_dezrez":
            {
                $fields_to_clear = ['api_key', 'eaid'];
                break;
            }
            case "xml_vebra_api":
            {
                $fields_to_clear = ['username', 'password', 'datafeed_id'];
                break;
            }
            case "json_sme_professional":
            {
                $fields_to_clear = ['company_id'];
                break;
            }
            case "xml_mri":
            {
                $fields_to_clear = ['url', 'password'];
                break;
            }
            case "json_agency_pilot":
            {
                $fields_to_clear = ['password'];
                break;
            }
            case "api_agency_pilot":
            case "json_kato":
            {
                $fields_to_clear = ['client_id', 'client_secret'];
                break;
            }
            case "xml_webedge":
            {
                $fields_to_clear = ['shared_secret'];
                break;
            }
            case "json_loop":
            case "json_loop_v2":
            {
                $fields_to_clear = ['client_id'];
                break;
            }
            case "json_veco":
            {
                $fields_to_clear = ['access_token'];
                break;
            }
            case "json_arthur":
            {
                $fields_to_clear = ['client_id', 'client_secret', 'entity_id'];
                break;
            }
            case "xml_supercontrol":
            {
                $fields_to_clear = ['client_id', 'api_key'];
                break;
            }
            case "json_rex":
            {
                $fields_to_clear = ['url', 'username', 'password'];
                break;
            }
            case "json_reapit_foundations":
            {
                $fields_to_clear = ['customer_id'];
                break;
            }
            case "json_vaultea":
            {
                $fields_to_clear = ['api_key', 'token'];
                break;
            }
            case "json_remax":
            {
                $fields_to_clear = ['api_key', 'access_key', 'secret_key'];
                break;
            }
            case "json_pixxi":
            {
                $fields_to_clear = ['api_key', 'url'];
                break;
            }
            case "json_casafari":
            {
                $fields_to_clear = ['api_token'];
                break;
            }
        }

        $fields_to_clear = apply_filters( 'propertyhive_property_import_delete_fields_to_clear', $fields_to_clear, $import_id, $option );

        foreach ($fields_to_clear as $field_to_clear)
        {
            if ( isset($option[$field_to_clear]) && $option[$field_to_clear] !== '' )
            {
                $option[$field_to_clear] = '';
            }
        }

        return $option;
    }

    public function posts_where( $where, $q ) {
        global $typenow, $wp_query, $wpdb;

        if ( !is_admin() )
            return $where;

        if ( !$q->is_main_query() )
            return $where;

        if ( !isset($_GET['s']) || ( isset($_GET['s']) && ph_clean($_GET['s']) == '' ) )
            return $where;

        if ( 'property' === $typenow ) 
        {
            $where = preg_replace(
                "/(\(\s*" . $wpdb->posts . ".post_title\s+LIKE\s*(\'[^\']+\')\s*\))/",
                "$1 OR (ph_property_filter_meta_imported_ref.meta_value = '" . esc_sql($_GET['s']) . "')",
                $where
            );
        }

        return $where;
    }

    public function crm_id_in_property_admin_list( $details, $post_id )
    {
        $all_meta = get_post_meta($post_id);

        $import_id = '';
        $crm_id = '';

        foreach ($all_meta as $meta_key => $value)
        {
            if (strpos($meta_key, '_imported_ref_') !== FALSE) 
            { 
                $import_id = str_replace("_imported_ref_", "", $meta_key);
                $crm_id = $value[0];
                break; // Stop the loop once we find the first match
            }
        }
        
        if ( !empty($import_id) && !empty($crm_id) )
        {
            $options = get_option( 'propertyhive_property_import' );

            if ( 
                is_array($options) && isset($options[$import_id]) && isset($options[$import_id]['format'])
            )
            {
                $details[] = '<span style="opacity:0.6">' . esc_attr(ucwords(str_replace(array('v2', 'json', 'xml', 'api', 'rest'), "", str_replace("_", " ", $options[$import_id]['format'])))) . ' ID: ' . esc_attr($crm_id) . '</span>'; 
            }
        }

        return $details;
    }

    public function property_import_property_filters( $output )
    {
        $output .= '<select name="_import_id" id="dropdown_property_import_id">';

        $output .= '<option value="">' . __( 'Added Method', 'propertyhive' ) . '</option>';

        $formats = propertyhive_property_import_get_import_formats();

        $imports = get_option( 'propertyhive_property_import' );
        if ( is_array($imports) && !empty($imports) )
        {
            $formats_in_use = array();
            foreach ( $imports as $import_id => $import )
            {
                if ( isset($import['deleted']) && $import['deleted'] == 1 && apply_filters('propertyhive_property_import_include_deleted_in_filter', true) === false )
                {
                    continue;
                }

                // Display an import name based on the mapping array above
                $formats_in_use[$import_id] = isset($formats[$import['format']]) ? $formats[$import['format']]['name'] : $import['format'];

                if ( isset($import['custom_name']) && trim($import['custom_name']) != '' )
                {
                    // Import has been given a custom name, so add that to the end of the import format
                    $formats_in_use[$import_id] .= ' (' . trim($import['custom_name']) . ')';
                }

                if ( isset($import['deleted']) && $import['deleted'] == 1 )
                {
                    $formats_in_use[$import_id] .= ' - Deleted';
                }
                else
                {
                    if ( isset($import['running']) && $import['running'] == 1 )
                    {
                        $formats_in_use[$import_id] .= ' - Active';
                    }
                    else
                    {
                        $formats_in_use[$import_id] .= ' - Inactive';
                    }
                }
            }
            natcasesort($formats_in_use);

            foreach ($formats_in_use as $import_id => $import_format)
            {
                // If there are more than one of the same import, add the import_id on the end
                $id_suffix = count(array_keys($formats_in_use, $import_format)) > 1 ? ' (' . $import_id . ')' : '';
                $output .= '<option value="' . $import_id . '"';
                if ( isset( $_GET['_import_id'] ) && ! empty( $_GET['_import_id'] ) )
                {
                    $output .= selected( $import_id, sanitize_text_field($_GET['_import_id']), false );
                }
                $output .= '>' . 'Imported From ' . $import_format . $id_suffix . '</option>';
            }
        }

        $output .= '<option value="added_manually"';
        if ( isset( $_GET['_import_id'] ) && ! empty( $_GET['_import_id'] ) )
        {
            $output .= selected( 'added_manually', sanitize_text_field($_GET['_import_id']), false );
        }
        $output .= '>' . __( 'Added Manually', 'propertyhive' ) . '</option>';

        $output .= '</select>';

        return $output;
    }

    public function property_import_property_filter_query( $vars, $typenow )
    {
        if ( 'property' === $typenow && isset($_GET['_import_id']) && !empty( $_GET['_import_id'] ) )
        {
            if ( sanitize_text_field($_GET['_import_id']) == 'added_manually' )
            {
                $vars['meta_query'][] = array(
                    'key' => '_imported_ref',
                    'compare' => 'NOT EXISTS'
                );

                $imports = get_option( 'propertyhive_property_import' );
                if ( is_array($imports) && !empty($imports) )
                {
                    foreach ( $imports as $import_id => $import )
                    {
                        $vars['meta_query'][] = array(
                            'key' => '_imported_ref_' . $import_id,
                            'compare' => 'NOT EXISTS'
                        );
                    }
                }
            }
            else
            {
                $import_id = sanitize_text_field( $_GET['_import_id'] );

                $vars['meta_query'][] = array(
                    'key' => '_imported_ref_' . $import_id,
                    'compare' => 'EXISTS'
                );
            }
        }
        return $vars;
    }

    public function enable_separate_geocoding_api_key( $return )
    {
        return true;
    }
}

new PH_Property_Import_Admin();