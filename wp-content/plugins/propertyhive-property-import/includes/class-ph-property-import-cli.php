<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * CLI Functions
 */
class PH_Property_Import_CLI {

	public function __construct() {

        WP_CLI::add_command( 'import-properties', array( $this, 'property_import_execute_feed' ) );

	}

	public function property_import_execute_feed( $args = array(), $assoc_args = array() ) 
    {
        require( dirname(PH_PROPERTYIMPORT_PLUGIN_FILE) . '/cron.php' );

        WP_CLI::success( "Import completed successfully" );
    }

}

new PH_Property_Import_CLI();