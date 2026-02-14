<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Arthur Functions
 */
class PH_Property_Import_Arthur {

	public function __construct() {

        add_action( 'admin_init', array( $this, 'check_arthur_authorization_code' ), 1 );

        add_filter( 'propertyhive_property_import_table_actions', array( $this, 'add_authorize_action' ), 10, 2 );
        
	}

    public function check_arthur_authorization_code()
    {
        if ( isset($_GET['arthur_callback']) && (int)$_GET['arthur_callback'] == 1 )
        {
            if ( !isset($_GET['code']) )
            {
                die('No authorization code present');
            }

            if ( !isset($_GET['import_id']) )
            {
                die('No import_id present. Please check your redirect URL');
            }

            // Load Importer API
            require_once ABSPATH . 'wp-admin/includes/import.php';

            if ( ! class_exists( 'WP_Importer' ) ) 
            {
                $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
                if ( file_exists( $class_wp_importer ) ) require_once $class_wp_importer;
            }

            require_once dirname( __FILE__ ) . '/import-formats/class-ph-arthur-json-import.php';

            $PH_Arthur_JSON_Import = new PH_Arthur_JSON_Import('', (int)$_GET['import_id']);

            $PH_Arthur_JSON_Import->get_access_token_from_authorization_code($_GET['code']);
        }
    }

    public function add_authorize_action( $actions, $import_id )
    {
        $import_settings = propertyhive_property_import_get_import_settings_from_id( $import_id );

        if ( empty($import_settings) )
        {
            return $actions;
        }

        if ( $import_settings['format'] != 'json_arthur' )
        {
            return $actions;
        }

        if ( isset($import_settings['running']) && $import_settings['running'] == 1 )
        {
            if ( !isset($import_settings['access_token']) || empty($import_settings['access_token']) )
            {
                $new_action = array('<a href="https://auth.arthuronline.co.uk/oauth/authorize?client_id=' . $import_settings['client_id'] . '&redirect_uri=' . urlencode(admin_url('admin.php?page=propertyhive_import_properties&arthur_callback=1&import_id=' . $import_id)) . '&state=' . uniqid() . '">Authorize</a>');
            }
            else
            {
                $new_action = array('<a href="https://auth.arthuronline.co.uk/oauth/authorize?client_id=' . $import_settings['client_id'] . '&redirect_uri=' . urlencode(admin_url('admin.php?page=propertyhive_import_properties&arthur_callback=1&import_id=' . $import_id)) . '&state=' . uniqid() . '">Re-Authorize</a>');
            }

            $actions = array_merge(
                array_slice($actions, 0, 1),
                $new_action,
                array_slice($actions, 1)
            );
        }

        return $actions;
    }

}

new PH_Property_Import_Arthur();