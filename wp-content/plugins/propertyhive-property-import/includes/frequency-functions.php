<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function propertyhive_property_import_get_import_frequencies()
{
    $frequencies = array(
        'daily' => array(
            'name' => __( 'Daily', 'propertyhive' ),
        ),
         'twicedaily' => array(
            'name' => __( 'Twice Daily', 'propertyhive' ),
        ),
        'hourly' => array(
            'name' => __( 'Hourly', 'propertyhive' ),
        ),
        'every_fifteen_minutes' => array(
            'name' => __( 'Every Fifteen Minutes', 'propertyhive' ),
        ),
        'exact_hours' => array(
            'name' => __( 'Exact Hours', 'propertyhive' ),
        )
    );

    $frequencies = apply_filters( 'propertyhive_property_import_import_frequencies', $frequencies );

    return $frequencies;
}

function propertyhive_property_import_get_import_frequency( $key )
{
    $frequencies = propertyhive_property_import_get_import_frequencies();
    
    return $frequencies[$key];
}