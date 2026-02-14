<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Settings Functions
 */
class PH_Property_Import_Settings {

	public function __construct() {

        add_action( 'admin_init', array( $this, 'save_settings') );

	}

	public function save_settings()
    {
        if ( !isset($_POST['save_phpi_settings']) )
        {
            return;
        }

        if ( !isset($_POST['_wpnonce']) || ( isset($_POST['_wpnonce']) && !wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'save-phpi-settings' ) ) ) 
        {
            die( __( "Failed security check", 'propertyhive' ) );
        }

        $value = ( isset($_POST['email_reports']) && $_POST['email_reports'] == 'yes' ) ? 'yes' : '';
        update_option( 'propertyhive_property_import_email_reports', $value, false );

        $value = ( isset($_POST['email_reports_to']) && !empty(sanitize_email($_POST['email_reports_to'])) ) ? sanitize_email($_POST['email_reports_to']) : '';
        update_option( 'propertyhive_property_import_email_reports_to', $value, false );

        $value = ( isset($_POST['remove_action']) ) ? sanitize_text_field($_POST['remove_action']) : '';
        update_option( 'propertyhive_property_import_remove_action', $value, false );

        $value = ( isset($_POST['media_processing'])) ? sanitize_text_field($_POST['media_processing']) : '';
        update_option( 'propertyhive_property_import_media_processing', $value, false );

        wp_redirect( admin_url( 'admin.php?page=propertyhive_import_properties&tab=settings&phpisuccessmessage=' . base64_encode(__( 'Settings saved', 'propertyhive' ) ) ) );
        die();
    }

}

new PH_Property_Import_Settings();