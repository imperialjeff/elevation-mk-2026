<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function propertyhive_property_import_get_import_settings_from_id( $import_id )
{
    $options = get_option( 'propertyhive_property_import' , array() );
    $imports = ( isset($options) && is_array($options) && !empty($options) ) ? $options : array();

    if ( isset($imports[$import_id]) )
    {
        return $imports[$import_id];
    }

    return false;
}

function propertyhive_property_import_get_fields_for_field_mapping()
{
    // Departments
    $departments = ph_get_departments();
    $department_options = array();
    foreach ( $departments as $key => $value )
    {
        if ( get_option( 'propertyhive_active_departments_' . str_replace("residential-", "", $key) ) == 'yes' )
        {
            $department_options[$key] = $value;
        }
    }

    $propertyhive_fields = array(
        // Post Fields
        'post_title' => array( 'type' => 'post_field', 'label' => __( 'Post Title / Display Address', 'propertyhive' ) ),
        'post_excerpt' => array( 'type' => 'post_field', 'label' => __( 'Summary Description', 'propertyhive' ) ),
        'post_status' => array( 'type' => 'post_field', 'label' => __( 'Post Status', 'propertyhive' ), 'options' => array( 'publish' => __( 'Publish', 'propertyhive' ), 'private' => __( 'Private', 'propertyhive' ), 'draft' => __( 'Draft', 'propertyhive' ) ) ),
        'post_name' => array( 'type' => 'post_field', 'label' => __( 'Post URL / Permalink', 'propertyhive' ) ),
        
        // Property Hive Address Fields
        '_reference_number' => array( 'type' => 'meta', 'label' => __( 'Reference Number', 'propertyhive' ) ),
        '_address_name_number' => array( 'type' => 'meta', 'label' => __( 'Building Name / Number', 'propertyhive' ) ),
        '_address_street' => array( 'type' => 'meta', 'label' => __( 'Street', 'propertyhive' ) ),
        '_address_two' => array( 'type' => 'meta', 'label' => __( 'Address Line 2', 'propertyhive' ) ),
        '_address_three' => array( 'type' => 'meta', 'label' => __( 'Town / City', 'propertyhive' ) ),
        '_address_four' => array( 'type' => 'meta', 'label' => __( 'County / State', 'propertyhive' ) ),
        '_address_postcode' => array( 'type' => 'meta', 'label' => __( 'Postcode / Zip Code', 'propertyhive' ) ),
        '_country' => array( 'type' => 'meta', 'label' => __( 'Country', 'propertyhive' ) ), // show only if more than one country
        'location' => array( 'type' => 'taxonomy', 'label' => __( 'Location', 'propertyhive' ) ), // show options?

        // Property Hive Record Details Fields
        '_negotiator_id' => array( 'type' => 'meta', 'label' => __( 'Negotiator', 'propertyhive' ) ), // show options?
        '_office_id' => array( 'type' => 'meta', 'label' => __( 'Office', 'propertyhive' ) ), // show options?

        // Property Hive Location Fields
        '_latitude' => array( 'type' => 'meta', 'label' => __( 'Latitude', 'propertyhive' ) ),
        '_longitude' => array( 'type' => 'meta', 'label' => __( 'Longitude', 'propertyhive' ) ),

        // Property Hive Department Fields
        '_department' => array( 'type' => 'meta', 'label' => __( 'Department', 'propertyhive' ), 'options' => $department_options ), // show options?

        // Property Hive Residential Fields - Maybe only show if sales or lettings department active
        '_bedrooms' => array( 'type' => 'meta', 'label' => __( 'Bedrooms', 'propertyhive' ) ),
        '_bathrooms' => array( 'type' => 'meta', 'label' => __( 'Bathrooms', 'propertyhive' ) ),
        '_reception_rooms' => array( 'type' => 'meta', 'label' => __( 'Reception Rooms', 'propertyhive' ) ),
        'property_type' => array( 'type' => 'taxonomy', 'label' => __( 'Property Type', 'propertyhive' ) ), // show options?
        'parking' => array( 'type' => 'taxonomy', 'label' => __( 'Parking', 'propertyhive' ) ), // show options?
        'outside_space' => array( 'type' => 'taxonomy', 'label' => __( 'Outside Space', 'propertyhive' ) ), // show options?
        '_council_tax_band' => array( 'type' => 'meta', 'label' => __( 'Council Tax Band', 'propertyhive' ) ), // show options?

        // Maybe only show the below if the above criteria is met AND GB selected as a country
        '_electricity_type' => array( 'type' => 'meta', 'label' => __( 'Electricity Type', 'propertyhive' ) ), // show options?
        '_water_type' => array( 'type' => 'meta', 'label' => __( 'Water Type', 'propertyhive' ) ), // show options?
        '_heating_type' => array( 'type' => 'meta', 'label' => __( 'Heating Type', 'propertyhive' ) ), // show options?
        '_broadband_type' => array( 'type' => 'meta', 'label' => __( 'Broadband Type', 'propertyhive' ) ), // show options?
        '_sewerage_type' => array( 'type' => 'meta', 'label' => __( 'Sewerage Type', 'propertyhive' ) ), // show options?
        '_accessibility' => array( 'type' => 'meta', 'label' => __( 'Accessibility', 'propertyhive' ) ), // show options?
        '_restriction' => array( 'type' => 'meta', 'label' => __( 'Restrictions', 'propertyhive' ) ), // show options?
        '_right' => array( 'type' => 'meta', 'label' => __( 'Rights & Easements', 'propertyhive' ) ), // show options?
        '_flooded_in_last_five_years' => array( 'type' => 'meta', 'label' => __( 'Flooded in last 5 years?', 'propertyhive' ), 'options' => array( '' => '', 'no' => 'No', 'yes' => 'Yes' ) ), // show options?
        '_flood_source_type' => array( 'type' => 'meta', 'label' => __( 'Flooding Source', 'propertyhive' ) ), // show options?
        '_flood_defences' => array( 'type' => 'meta', 'label' => __( 'Are there flood defences?', 'propertyhive' ), 'options' => array( '' => '', 'no' => 'No', 'yes' => 'Yes' ) ), // show options?

        // Property Hive Residential Sales Fields - Maybe only show if sales (or based on sales) department active
        '_price' => array( 'type' => 'meta', 'label' => __( 'Price / Rent', 'propertyhive' ) ),
        '_currency' => array( 'type' => 'meta', 'label' => __( 'Currency', 'propertyhive' ) ), // show options? Only show if more than one currency in use
        '_poa' => array( 'type' => 'meta', 'label' => __( 'POA', 'propertyhive' ), 'options' => array( '' => 'No', 'yes' => 'Yes' ) ),
        'price_qualifier' => array( 'type' => 'taxonomy', 'label' => __( 'Price Qualifier', 'propertyhive' ) ), // show options?
        'sale_by' => array( 'type' => 'taxonomy', 'label' => __( 'Sale By', 'propertyhive' ) ), // show options?
        'tenure' => array( 'type' => 'taxonomy', 'label' => __( 'Tenure', 'propertyhive' ) ), // show options?

        // Property Hive Residential Lettings Fields - Maybe only show if lettings (or based on lettings) department active
        '_rent_frequency' => array( 'type' => 'meta', 'label' => __( 'Rent Frequency', 'propertyhive' ), 'options' => array( 'pd' => 'Per Day', 'pppw' => 'Per Person Per Week', 'pw' => 'Per Week', 'pcm' => 'Per Calendar Month', 'pq' => 'Per Quarter', 'pa' => 'Per Annum' ) ),
        '_deposit' => array( 'type' => 'meta', 'label' => __( 'Deposit', 'propertyhive' ) ),
        'furnished' => array( 'type' => 'taxonomy', 'label' => __( 'Furnished', 'propertyhive' ) ), // show options?
        '_available_date' => array( 'type' => 'meta', 'label' => __( 'Available Date', 'propertyhive' ) ),

        // Property Hive Commercial Fields - Maybe only show if commercial (or based on commercial) department active

        // Property Hive Marketing Fields
        '_on_market' => array( 'type' => 'meta', 'label' => __( 'On Market', 'propertyhive' ), 'options' => array( '' => 'No', 'yes' => 'Yes' ) ),
        'availability' => array( 'type' => 'taxonomy', 'label' => __( 'Availability', 'propertyhive' ) ), // show options?
        '_featured' => array( 'type' => 'meta', 'label' => __( 'Featured', 'propertyhive' ), 'options' => array( '' => 'No', 'yes' => 'Yes' ) ),
        'marketing_flag' => array( 'type' => 'taxonomy', 'label' => __( 'Marketing Flag', 'propertyhive' ) ), // show options?

        // Property Hive Description Fields
        'full_description' => array( 'type' => 'meta', 'label' => __( 'Full Description', 'propertyhive' ) ),
    );

    for ( $i = 0; $i < apply_filters( 'propertyhive_property_import_field_mapping_feature_count', 10 ); ++$i )
    {
        $propertyhive_fields['_property_feature[' . $i . ']'] = array( 'type' => 'meta', 'label' => __( 'Feature', 'propertyhive' ) . ' ' . ( $i + 1 ) );
    }
    
    // ACF
    if ( function_exists('acf_get_field_groups') ) 
    {
        $field_groups = acf_get_field_groups(['post_type' => 'property']);

        foreach ( $field_groups as $group ) 
        {
            // Get all fields for this field group
            $group_fields = acf_get_fields($group['key']);

            if ( $group_fields ) 
            {
                foreach ( $group_fields as $field )
                {
                    if ( in_array($field['type'], ['text', 'number', 'email', 'textarea', 'url']) ) 
                    {
                        $propertyhive_fields[$field['name']] = array( 
                            'type' => 'meta', 
                            'label' => __( $field['label'], 'propertyhive' ) . ' (ACF Field)', 
                            'acf' => true
                        );
                    }
                    elseif ( in_array($field['type'], ['select', 'radio']) )
                    {
                        $propertyhive_fields[$field['name']] = array(
                            'type'    => 'meta',
                            'label'   => __( $field['label'], 'propertyhive' ) . ' (ACF Field)',
                            'options' => $field['choices'],
                            'acf' => true
                        );
                    }
                }
            }
        }
    }

    $propertyhive_fields = apply_filters( 'propertyhive_property_import_field_mapping_propertyhive_fields', $propertyhive_fields );

    $propertyhive_fields = propertyhive_property_import_array_msort( $propertyhive_fields, array( 'label' => SORT_ASC ) );

    return $propertyhive_fields;
}

function propertyhive_property_import_taxonomies_for_mapping()
{
    return array(
        array(
            'import_taxonomy' => 'sales_availability',
            'propertyhive_taxonomy' => 'availability',
            'departments' => array('residential-sales'),
        ),
        array(
            'import_taxonomy' => 'lettings_availability',
            'propertyhive_taxonomy' => 'availability',
            'departments' => array('residential-lettings'),
        ),
        array(
            'import_taxonomy' => 'commercial_availability',
            'propertyhive_taxonomy' => 'availability',
            'departments' => array('commercial'),
        ),
        array(
            'import_taxonomy' => 'property_type',
            'propertyhive_taxonomy' => 'property_type',
            'departments' => array('residential-sales', 'residential-lettings'),
        ),
        array(
            'import_taxonomy' => 'commercial_property_type',
            'propertyhive_taxonomy' => 'commercial_property_type',
            'departments' => array('commercial'),
        ),
        array(
            'import_taxonomy' => 'price_qualifier',
            'propertyhive_taxonomy' => 'price_qualifier',
        ),
        array(
            'import_taxonomy' => 'sale_by',
            'propertyhive_taxonomy' => 'sale_by',
        ),
        array(
            'import_taxonomy' => 'tenure',
            'propertyhive_taxonomy' => 'tenure',
            'departments' => array('residential-sales'),
        ),
        array(
            'import_taxonomy' => 'commercial_tenure',
            'propertyhive_taxonomy' => 'commercial_tenure',
            'departments' => array('commercial'),
        ),
        array(
            'import_taxonomy' => 'furnished',
            'propertyhive_taxonomy' => 'furnished',
            'departments' => array('residential-lettings'),
        ),
        array(
            'import_taxonomy' => 'parking',
            'propertyhive_taxonomy' => 'parking',
            'departments' => array('residential-sales', 'residential-lettings'),
        ),
        array(
            'import_taxonomy' => 'outside_space',
            'propertyhive_taxonomy' => 'outside_space',
            'departments' => array('residential-sales', 'residential-lettings'),
        ),
        array(
            'import_taxonomy' => 'location',
            'propertyhive_taxonomy' => 'location',
        ),
    );
}

function phpi_determine_number_separators( $number ) 
{
    $decimalSeparator = '';
    $thousandSeparator = '';

    // Count occurrences
    $commaCount = substr_count($number, ',');
    $periodCount = substr_count($number, '.');

    // Logic to determine separators
    if ($commaCount > 0 && $periodCount > 0) {
        // Both symbols are present, determine based on position
        if (strrpos($number, ',') > strrpos($number, '.')) {
            $decimalSeparator = ',';
            $thousandSeparator = '.';
        } else {
            $decimalSeparator = '.';
            $thousandSeparator = ',';
        }
    } elseif ($commaCount > 0) {
        // Only commas are present
        if (($commaCount == 1 && strlen($number) - strrpos($number, ',') > 3) || ($commaCount > 1 && phpi_is_thousands_grouping($number, ','))) {
            // Single comma or valid thousands grouping
            $thousandSeparator = ',';
            $decimalSeparator = '.';
        } else {
            // Comma likely used as decimal separator
            $decimalSeparator = ',';
            $thousandSeparator = '.';
        }
    } elseif ($periodCount > 0) {
        // Only periods are present
        if (($periodCount == 1 && strlen($number) - strrpos($number, '.') > 3) || ($periodCount > 1 && phpi_is_thousands_grouping($number, '.'))) {
            // Single period or valid thousands grouping
            $thousandSeparator = '.';
            $decimalSeparator = ',';
        } else {
            // Period likely used as decimal separator
            $decimalSeparator = '.';
            $thousandSeparator = ',';
        }
    } else {
        // No separators found, default to common usage
        $decimalSeparator = '.';
        $thousandSeparator = ',';
    }

    return ['decimal' => $decimalSeparator, 'thousand' => $thousandSeparator];
}

// Helper function to check for consistent thousands grouping
function phpi_is_thousands_grouping( $number, $separator ) 
{
    $parts = explode($separator, $number);

    // Allow the first part to have fewer than 3 digits
    foreach (array_slice($parts, 1, -1) as $part) 
    {
        if (strlen($part) !== 3) return false;
    }

    return true;
}

function phpi_get_import_object_from_format( $format, $instance_id, $import_id )
{
    $import_object = false;

    $format_details = propertyhive_property_import_get_import_format($format);
    if ( $format_details === false )
    {
        return false;
    }

    if ( isset($format_details['file']) && file_exists($format_details['file']) )
    {
        require_once $format_details['file'];
    }
    else
    {
        return false;
    }

    $parsed_in_class = false;
    
    switch ($format)
    {
        case "xml_10ninety":
        {
            $import_object = new PH_10ninety_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_acquaint":
        {
            $import_object = new PH_Acquaint_XML_Import( $instance_id, $import_id );
            break;
        }
        case "api_agency_pilot":
        {
            $import_object = new PH_Agency_Pilot_API_Import( $instance_id, $import_id );
            break;
        }
        case "json_agency_pilot":
        {
            $import_object = new PH_Agency_Pilot_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_agentbox":
        {
            $import_object = new PH_Agentbox_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_letmc":
        {
            $import_object = new PH_AgentOS_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_agestanet":
        {
            $import_object = new PH_Agestanet_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_vebra_api":
        {
            $import_object = new PH_Vebra_API_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_apex27":
        {
            $import_object = new PH_Apex27_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_apimo":
        {
            $import_object = new PH_Apimo_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_arthur":
        {
            $import_object = new PH_Arthur_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_bdp":
        {
            $import_object = new PH_BDP_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "blm_local":
        {
            $import_object = new PH_BLM_Import( $instance_id, $import_id );

            $import_object->parse_and_import();

            $parsed_in_class = true;

            break;
        }
        case "blm_remote":
        {
            $import_object = new PH_BLM_Import( $instance_id, $import_id );
            break;
        }
        case "json_casafari":
        {
            $import_object = new PH_Casafari_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "csv":
        {
            $import_object = new PH_CSV_Import( $instance_id, $import_id );
            break;
        }
        case "json_dezrez":
        {
            $import_object = new PH_Dezrez_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_dezrez":
        {
            $import_object = new PH_Dezrez_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_domus":
        {
            $import_object = new PH_Domus_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_ego":
        {
            $import_object = new PH_Ego_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_estatesit":
        {
            $import_object = new PH_EstatesIT_XML_Import( $instance_id, $import_id );

            $import_object->parse_and_import();

            $parsed_in_class = true;

            break;
        }
        case "xml_expertagent":
        {
            $import_object = new PH_Expertagent_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_getrix":
        {
            $import_object = new PH_Getrix_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_gnomen":
        {
            $import_object = new PH_Gnomen_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_infocasa":
        {
            $import_object = new PH_Infocasa_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_inmobalia":
        {
            $import_object = new PH_Inmobalia_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_inmobalia":
        {
            $import_object = new PH_Inmobalia_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_inmovilla":
        {
            $import_object = new PH_Inmovilla_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_inmoweb":
        {
            $import_object = new PH_Inmoweb_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_jupix":
        {
            $import_object = new PH_Jupix_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_juvo":
        {
            $import_object = new PH_Juvo_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_kato":
        {
            $import_object = new PH_Kato_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_agentsinsight":
        {
            $import_object = new PH_Kato_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_kyero":
        {
            $import_object = new PH_Kyero_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_loop_v2":
        {
            $import_object = new PH_Loop_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_mri":
        {
            $import_object = new PH_MRI_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_clarks_computers":
        {
            $import_object = new PH_Muven_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_pixxi":
        {
            $import_object = new PH_Pixxi_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_property_finder_uae":
        {
            $import_object = new PH_Property_Finder_UAE_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_propertyadd":
        {
            $import_object = new PH_Propertyadd_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_reapit_foundations":
        {
            $import_object = new PH_Reapit_Foundations_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "reaxml_local":
        {
            $import_object = new PH_REAXML_Import( $instance_id, $import_id );

            $import_object->parse_and_import();

            $parsed_in_class = true;
            
            break;
        }
        case "api_rentman":
        {
            $import_object = new PH_Rentman_API_Import( $instance_id, $import_id );
            break;
        }
        case "xml_rentman":
        {
            $import_object = new PH_Rentman_XML_Import( $instance_id, $import_id );

            $import_object->parse_and_import();

            $parsed_in_class = true;

            break;
        }
        case "api_resales_online":
        {
            $import_object = new PH_ReSales_Online_API_Import( $instance_id, $import_id );
            break;
        }
        case "xml_resales_online":
        {
            $import_object = new PH_ReSales_Online_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_rex":
        {
            $import_object = new PH_Rex_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_sme_professional":
        {
            $import_object = new PH_SME_Professional_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml_sme_professional":
        {
            $import_object = new PH_SME_Professional_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_street":
        {
            $import_object = new PH_Street_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "thesaurus":
        {
            $import_object = new PH_Thesaurus_Import( $instance_id, $import_id );
            break;
        }
        case "xml_thinkspain":
        {
            $import_object = new PH_Thinkspain_XML_Import( $instance_id, $import_id );
            break;
        }
        case "json_utili":
        {
            $import_object = new PH_Utili_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_vaultea":
        {
            $import_object = new PH_VaultEA_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_veco":
        {
            $import_object = new PH_Veco_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_veco_plus":
        {
            $import_object = new PH_Veco_Plus_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "json_wordpress_property_hive":
        {
            $import_object = new PH_WordPress_Property_Hive_JSON_Import( $instance_id, $import_id );
            break;
        }
        case "xml":
        {
            $import_object = new PH_XML_Import( $instance_id, $import_id );
            break;
        }
        case "xml_xml2u":
        {
            $import_object = new PH_Xml2u_XML_Import( $instance_id, $import_id );
            break;
        }
        default:
        {
            $import_object = apply_filters( 'propertyhive_property_import_object', null, $instance_id, $import_id );
        }
    }

    return array($import_object, $parsed_in_class);
}

function phpi_sanitize_text_field_keep_encoded( $str ) 
{
    if ( is_object( $str ) || is_array( $str ) ) {
        return '';
    }

    $str = (string) $str;

    $filtered = wp_check_invalid_utf8( $str );

    if ( str_contains( $filtered, '<' ) ) {
        $filtered = wp_pre_kses_less_than( $filtered );
        // This will strip extra whitespace for us.
        $filtered = wp_strip_all_tags( $filtered, false );

        /*
         * Use HTML entities in a special case to make sure that
         * later newline stripping stages cannot lead to a functional tag.
         */
        $filtered = str_replace( "<\n", "&lt;\n", $filtered );
    }

    $filtered = preg_replace( '/[\r\n\t ]+/', ' ', $filtered );
    
    $filtered = trim( $filtered );

    return $filtered;
}

function phpi_media_cron_add_log( $instance_id, $severity, $message, $post_id = 0 )
{
    if ( $instance_id != '' )
    {
        global $wpdb;

        $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
        $current_date = $current_date->format("Y-m-d H:i:s");

        $crm_id = '';
        if ( !empty($post_id) )
        {
            $property = new PH_Property((int)$post_id);
            $crm_id = $property->imported_id;
        }

        $wpdb->insert(
            $wpdb->prefix . "ph_propertyimport_instance_log_v3",
            array(
                'instance_id' => $instance_id,
                'post_id' => $post_id,
                'crm_id' => $crm_id,
                'severity' => $severity,
                'entry' => $message,
                'log_date' => $current_date
            )
        );

        if ( defined( 'WP_CLI' ) && WP_CLI )
        {
            WP_CLI::log( $current_date . ' - ' . $message . ( !empty($post_id) ? ' (Property ID: ' . $post_id . ')' : '' ) );
        }

        $data = array( 
            'status_date' => $current_date
        );

        $wpdb->update( 
            $wpdb->prefix . "ph_propertyimport_instance_v3", 
            $data,
            array( 'id' => $instance_id )
        );
    }
}