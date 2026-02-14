<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Street Functions
 */
class PH_Property_Import_Street {

	public function __construct() {

        add_action( 'wp', array( $this, 'replace_street_enquiry_button' ) );

        // check if Street format in list
        $imports = get_option( 'propertyhive_property_import' );
        if ( is_array($imports) && !empty($imports) )
        {
            foreach ( $imports as $import_id => $import_settings )
            {
                if ( $import_settings['format'] == 'json_street' )
                {
                    add_filter( 'propertyhive_elementor_widgets', array( $this, 'add_street_book_viewing_elementor_widget' ), 10, 1 );
                    add_filter( 'propertyhive_elementor_widget_directory', array( $this, 'select_street_book_viewing_elementor_widget_directory' ), 10, 2 );
                    break;
                }
            }
        }
        
	}

    public function replace_street_enquiry_button()
    {
        if ( is_singular('property') )
        {
            global $post, $wpdb;

            // did this property come from a Street import

            $row = $wpdb->get_row(
                "
                SELECT meta_key 
                FROM {$wpdb->prefix}postmeta 
                WHERE 
                    meta_key LIKE '_imported_ref_%'
                AND
                    post_id = '" . $post->ID . "'
                LIMIT 1
                ",
                ARRAY_A
            );

            if ( null !== $row ) 
            {
                $import_id = $row['meta_key'];
                $import_id = str_replace("_imported_ref_", "", $import_id);

                // check if import ID is a street import
                $options = get_option( 'propertyhive_property_import' );

                if ( 
                    is_array($options) && 
                    isset($options[$import_id]) && $options[$import_id]['format'] == 'json_street' && 
                    isset($options[$import_id]['use_viewing_url']) && $options[$import_id]['use_viewing_url'] == 'yes'
                )
                {
                    $property = new PH_Property($post->ID);

                    if ( $property->_book_viewing_url != '' )
                    {
                        // Do template replacement stuff
                        remove_action( 'propertyhive_property_actions_list_start', 'propertyhive_make_enquiry_button', 10 );
                        add_filter( 'propertyhive_single_property_actions', array( $this, 'add_street_enquiry_button' ) );
                    }
                }
            }
        }
    }

    public function add_street_enquiry_button( $actions )
    {
        global $property;
      
        $action = array(
          'href' => $property->_book_viewing_url,
          'label' => __( 'Book Viewing', 'propertyhive' ),
          'class' => 'action-make-enquiry',
          'attributes' => array(
            'target' => '_blank'
          )
        );
        
        array_unshift($actions, $action);
      
        return $actions;
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
            $widget_dir = dirname(__FILE__) . '/elementor-widgets';
        }
        return $widget_dir;
    }
}

new PH_Property_Import_Street();