<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Shortcode Functions
 */
class PH_Property_Import_Shortcodes {

	public function __construct() {

        $shortcodes = array(
            'properties',
            'recent_properties',
            'featured_properties'
        );

        foreach ( $shortcodes as $shortcode )
        {
            add_filter( 'shortcode_atts_' . $shortcode, array( $this, 'add_imported_ids_as_attribute_to_shortcode' ), 10, 4 );
            add_filter( 'shortcode_atts_' . $shortcode, array( $this, 'add_import_id_as_attribute_to_shortcode' ), 10, 4 );

            add_filter( 'propertyhive_shortcode_' . $shortcode . '_query', array( $this, 'handle_shortcode_imported_ids_attribute' ), 99, 2 );
            add_filter( 'propertyhive_shortcode_' . $shortcode . '_query', array( $this, 'handle_shortcode_import_id_attribute' ), 99, 2 );
        }
        
	}

    public function add_imported_ids_as_attribute_to_shortcode( $out, $pairs, $atts, $shortcode )
    {
        $out['imported_ids'] = ( isset($atts['imported_ids']) ? $atts['imported_ids'] : '' );

        return $out;
    }

    public function add_import_id_as_attribute_to_shortcode( $out, $pairs, $atts, $shortcode )
    {
        $out['import_id'] = ( isset($atts['import_id']) ? $atts['import_id'] : '' );

        return $out;
    }

    public function handle_shortcode_imported_ids_attribute( $args, $atts )
    {
        if ( 
            isset( $atts['imported_ids'] ) && $atts['imported_ids'] != ''
        )
        {
            $args['meta_query'][] = array(
                'compare_key' => 'LIKE',
                'key'     => '_imported_ref_',
                'value'   => explode(",", sanitize_text_field($atts['imported_ids'])),
                'compare' => 'IN'
            );
        }

        return $args;
    }

    public function handle_shortcode_import_id_attribute( $args, $atts )
    {
        if ( 
            isset( $atts['import_id'] ) && $atts['import_id'] != ''
        )
        {
            $args['meta_query'][] = array(
                'key'     => '_imported_ref_' . $atts['import_id'],
                'compare' => 'EXISTS'
            );
        }

        return $args;
    }

}

new PH_Property_Import_Shortcodes();