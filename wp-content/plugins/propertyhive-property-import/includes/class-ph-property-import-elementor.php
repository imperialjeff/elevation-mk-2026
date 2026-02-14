<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Elementor Functions
 */
class PH_Property_Import_Elementor {

	public function __construct() {

        // check if Street format in list
        $import_options = get_option( 'propertyhive_property_import' );
        if ( is_array($import_options) && !empty($import_options) )
        {
            foreach ( $import_options as $import_id => $options )
            {
                if ( isset($options['format']) && $options['format'] == 'json_street' )
                {
                    add_filter( 'propertyhive_elementor_widgets', array( $this, 'add_street_book_viewing_elementor_widget' ), 10, 1 );
                    add_filter( 'propertyhive_elementor_widget_directory', array( $this, 'select_street_book_viewing_elementor_widget_directory' ), 10, 2 );
                    break;
                }
            }
        }
        
	}

    public function add_street_book_viewing_elementor_widget( $widgets )
    {
        // Don't insert roooms widget if it's already in the list
        if ( array_search( 'Property Street Book Viewing Link', $widgets ) === false )
        {
            // If the full description widget is in the list, insert it after that. If not, add it on the end
            $desc_position = array_search( 'Property Enquiry Form Link', $widgets );
            if ( $desc_position !== false )
            {
                array_splice($widgets, $desc_position+1, 0, 'Property Street Book Viewing Link');
            }
            else
            {
                $widgets[] = 'Property Street Book Viewing Link';
            }
        }
        return $widgets;
    }

    public function select_street_book_viewing_elementor_widget_directory( $widget_dir, $widget )
    {
        if ( $widget == 'Property Street Book Viewing Link' )
        {
            $widget_dir = dirname(__FILE__) . '/includes/elementor-widgets';
        }
        return $widget_dir;
    }

}

new PH_Property_Import_Elementor();