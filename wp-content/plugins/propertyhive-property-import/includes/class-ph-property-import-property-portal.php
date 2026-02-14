<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Property Portal Functions
 */
class PH_Property_Import_Property_Portal {

	public function __construct() {

        // Redirects
        add_action( 'admin_init', array( $this, 'propertyhive_property_portal_add_on_integration') );
	}

    public function propertyhive_property_portal_add_on_integration()
    {
        // Is the property portal add on activated
        if (class_exists('PH_Property_Portal'))
        {
            //add_action( 'propertyhive_agent_branch_template_fields', array( $this, 'add_propertyhive_agent_branch_template_fields'), 1 );
            add_action( 'propertyhive_agent_branch_existing_fields', array( $this, 'add_propertyhive_agent_branch_existing_fields'), 1, 1 );
            add_action( 'propertyhive_save_agent_branches', array( $this, 'do_propertyhive_save_agent_branches'), 1 );
        }
    }

    public function add_propertyhive_agent_branch_existing_fields( $branch_post_id )
    {
        $imports = array('' => 'Please Select');
        $options = get_option( 'propertyhive_property_import' );
        foreach ( $options as $import_id => $option )
        {
            if ( isset($option['deleted']) && $option['deleted'] == 1 )
            {
                continue;
            }

            if ( isset($option['custom_name']) && trim($option['custom_name']) != '' )
            {
                $suffix = trim($option['custom_name']);
            }
            else
            {
                $suffix = $import_id;
            }

            $format = propertyhive_property_import_get_import_format( $option['format'], $import_id );
            $name = $option['format'];
            if ( $format !== false )
            {
                $name = $format['name'];
            }
            $imports[$import_id] = $name . ' (' . $suffix . ')';
        }

        $args = array(
            'id' => '_import_id[existing_' . $branch_post_id . ']',
            'label' => __( 'Associated Import', 'propertyhive' ),
            'desc_tip' => false,
            'options' => $imports,
            'value' => get_post_meta( $branch_post_id, '_import_id', true ),
        );
        propertyhive_wp_select( $args );

        if ( get_option( 'propertyhive_active_departments_sales' ) == 'yes' )
        {
            propertyhive_wp_text_input( array( 
                'id' => '_branch_code_sales[existing_' . $branch_post_id . ']', 
                'label' => __( 'Branch Code (Sales)', 'propertyhive' ), 
                'desc_tip' => false, 
                'value' => get_post_meta( $branch_post_id, '_branch_code_sales', true ),
                'type' => 'text'
            ) );
        }
        if ( get_option( 'propertyhive_active_departments_lettings' ) == 'yes' )
        {
            propertyhive_wp_text_input( array( 
                'id' => '_branch_code_lettings[existing_' . $branch_post_id . ']', 
                'label' => __( 'Branch Code (Lettings)', 'propertyhive' ), 
                'desc_tip' => false, 
                'value' => get_post_meta( $branch_post_id, '_branch_code_lettings', true ),
                'type' => 'text'
            ) );
        }
        if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
        {
            propertyhive_wp_text_input( array( 
                'id' => '_branch_code_commercial[existing_' . $branch_post_id . ']', 
                'label' => __( 'Branch Code (Commercial)', 'propertyhive' ), 
                'desc_tip' => false, 
                'value' => get_post_meta( $branch_post_id, '_branch_code_commercial', true ),
                'type' => 'text'
            ) );
        }
    }

    public function do_propertyhive_save_agent_branches()
    {
        foreach ($_POST['_branch_name'] as $key => $value)
        {
            $existing = FALSE;
            if ( strpos($key, 'existing_') !== FALSE )
            {
                $existing = str_replace('existing_', '', $key);
            }

            if ($existing !== FALSE)
            {
                $branch_id = $existing;

                // This is an existing branch
                update_post_meta( $branch_id, '_import_id', $_POST['_import_id'][$key] );
                update_post_meta( $branch_id, '_branch_code_sales', ( isset($_POST['_branch_code_sales'][$key]) ? $_POST['_branch_code_sales'][$key] : '' ) );
                update_post_meta( $branch_id, '_branch_code_lettings', ( isset($_POST['_branch_code_lettings'][$key]) ? $_POST['_branch_code_lettings'][$key] : '' ) );
                update_post_meta( $branch_id, '_branch_code_commercial', ( isset($_POST['_branch_code_commercial'][$key]) ? $_POST['_branch_code_commercial'][$key] : '' ) );
            }
        }
    }

}

new PH_Property_Import_Property_Portal();