<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Import Functions
 */
class PH_Property_Import_Import {

	public function __construct() {

        add_action( 'admin_init', array( $this, 'save_import_settings') );

        add_action( "propertyhive_property_imported", array( $this, 'perform_field_mapping' ), 1, 3 );
        add_action( 'propertyhive_property_imported', array( $this, 'set_generic_propertyhive_property_data'), 1, 3 );

        add_filter( 'propertyhive_property_import_xml_mapped_field_value', array( $this, 'get_xml_mapped_field_value' ), 1, 4 );
        add_filter( 'propertyhive_property_import_csv_mapped_field_value', array( $this, 'get_csv_mapped_field_value' ), 1, 4 );

        //add_action( "propertyhive_property_imported", array( $this, 'update_queue_status' ), 10, 4 );
        add_action( "propertyhive_property_imported", array( $this, 'set_property_data_date' ), 10, 4 );
	}

    public function save_import_settings()
    {
        if ( !isset($_POST['save_import_settings']) )
        {
            return;
        }

        if ( !isset($_POST['_wpnonce']) || ( isset($_POST['_wpnonce']) && !wp_verify_nonce( sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'save-import-settings' ) ) ) 
        {
            die( __( "Failed security check", 'propertyhive' ) );
        }

        // ready to save
        $import_id = !empty($_POST['import_id']) ? (int)$_POST['import_id'] : time();

        $options = get_option( 'propertyhive_property_import' , array() );
        if ( !is_array($options) ) { $options = array(); }
        if ( !isset($options[$import_id]) ) { $options[$import_id] = array(); }
        if ( !is_array($options[$import_id]) ) { $options[$import_id] = array(); }

        $format = sanitize_text_field($_POST['format']);

        if ( isset($_POST['previous_format']) && $format != sanitize_text_field($_POST['previous_format']) )
        {
            // remove any options we stored about current status
            update_option( 'propertyhive_property_import_property_' . $import_id, '', false );
            update_option( 'propertyhive_property_import_property_image_media_ids_' . $import_id, '', false );
        }

        $running = ( isset($_POST['running']) && sanitize_text_field($_POST['running']) == 'yes' ) ? true : false;

        //$agent_display_option = ( isset($_POST['agent_display_option']) ) ? sanitize_text_field($_POST['agent_display_option']) : 'author_info';

        $sanitized_exact_hours = array();

        if ( isset($_POST['exact_hours']) && !empty(sanitize_text_field($_POST['exact_hours'])) )
        {
            $exact_hours = explode(",", sanitize_text_field($_POST['exact_hours']));
            $exact_hours = array_map('trim', $exact_hours); // remove white spaces from around hours
            $exact_hours = array_filter($exact_hours); // remove empty array elements
            sort($exact_hours, SORT_NUMERIC);

            if ( !empty($exact_hours) )
            {
                foreach ( $exact_hours as $hour_to_execute )
                {
                    $hour_to_execute = explode(":", $hour_to_execute);
                    $hour_to_execute = $hour_to_execute[0];

                    if ( is_numeric($hour_to_execute) && (int)$hour_to_execute >= 0 && (int)$hour_to_execute < 24 )
                    {
                        $sanitized_exact_hours[] = $hour_to_execute;
                    }
                }
            }
        }

        $import_options = array(
            'running' => $running,
            'format' => $format,
            'import_frequency' => isset($_POST['frequency']) && !empty($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'daily',
            'exact_hours' => $sanitized_exact_hours,
            'custom_name' => ( isset($_POST['custom_name']) && sanitize_text_field(wp_unslash($_POST['custom_name'])) != '' ? sanitize_text_field(wp_unslash($_POST['custom_name'])) : '' ),
            'limit' => ( isset($_POST['limit']) && !empty((int)$_POST['limit']) ? (int)$_POST['limit'] : '' ),
            'limit_images' => ( isset($_POST['limit_images']) && !empty((int)$_POST['limit_images']) ? (int)$_POST['limit_images'] : '' ),
        );

        $background_mode = '';
        if ( isset($_POST['background_mode']) && sanitize_text_field(wp_unslash($_POST['background_mode'])) == 'yes' )
        {
            $background_mode = 'yes';
        }
        else
        {
            // Clear queue as setting has been disabled
            //global $wpdb;
            
            //$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}propertyhive_property_import_property_queue WHERE import_id = %d", $import_id));
        }
        //$import_options['background_mode'] = $background_mode;

        $rules = array();
        if ( 
            isset($_POST['field_mapping_rules']) && 
            is_array($_POST['field_mapping_rules']) && 
            count($_POST['field_mapping_rules']) > 1 // more than 1 to ignore template
        )
        {
            $rule_i = 0;

            $sanitized_field_mapping_rules = wp_unslash($_POST['field_mapping_rules']);

            foreach ( $sanitized_field_mapping_rules as $j => $field )
            {
                if ($j !== '{rule_count}') // ignore template
                {
                    $result = wp_kses($field['result'], array('br' => array(), 'span' => array(), 'strong' => array(), 'em' => array()));
                    if ( $field['result_type'] == 'dropdown' )
                    {
                        $result = sanitize_text_field($field['result_option']);
                    }
                    $rules[$rule_i] = array(
                        'propertyhive_field' => sanitize_text_field($field['propertyhive_field']),
                        'result' => $result,
                        'delimited' => ( isset($field['delimited']) && $field['delimited'] == '1' ) ? true : false,
                        'delimited_character' => ( isset($field['delimited_character']) ) ? $field['delimited_character'] : '',
                        'rules' => array(),
                    );

                    unset($field['propertyhive_field']);
                    unset($field['result']);
                    unset($field['result_option']);
                    unset($field['delimited']);
                    unset($field['delimited_character']);
                    unset($field['result_type']);

                    foreach ( $field as $i => $rule_fields )
                    {
                        foreach ( $rule_fields as $k => $rule_field )
                        {
                            $rules[$rule_i]['rules'][$k][$i] = sanitize_text_field($rule_field);
                        }
                    }

                    ++$rule_i;
                }
            }
        }
        $import_options['field_mapping_rules'] = $rules;

        // Save core format fields (API Key, XML URL etc)
        $formats = propertyhive_property_import_get_import_formats();
        if ( isset($formats[$format]) )
        {
            if ( isset($formats[$format]['fields']) && !empty($formats[$format]['fields']) )
            {
                foreach ( $formats[$format]['fields'] as $field )
                {   
                    if ( isset($field['id']) && substr($field['id'], 0, 9) == 'previous_' ) // don't save any fields storing previous data
                    {
                        continue;
                    }

                    if ( isset($field['type']) && $field['type'] != 'html' )
                    {
                        $field_value = '';
                        if ( isset($_POST[$format . '_' . $field['id']]) && !empty($_POST[$format . '_' . $field['id']]) )
                        {
                            if ( $field['type'] == 'multiselect' && is_array($_POST[$format . '_' . $field['id']]) )
                            {
                                $field_value = array();
                                foreach ( $_POST[$format . '_' . $field['id']] as $post_key => $post_value )
                                {
                                    $field_value[$post_key] = phpi_sanitize_text_field_keep_encoded(wp_unslash($post_value));
                                }
                            }
                            else
                            {
                                if ( strpos($field['id'], 'url') !== FALSE )
                                {
                                    $field_value = sanitize_url(wp_unslash($_POST[$format . '_' . $field['id']]));
                                }
                                else
                                {
                                    $field_value = phpi_sanitize_text_field_keep_encoded(wp_unslash($_POST[$format . '_' . $field['id']]));
                                }
                            }
                        }
                        if ( $field['id'] == 'property_node_options' || $field['id'] == 'property_field_options' )
                        {
                            $field_value = wp_unslash($field_value);
                        }
                        $import_options[$field['id']] = $field_value;
                    }
                }
            }
        }

        $import_mappings = array();

        if ( isset($_POST['taxonomy_mapping']) && is_array($_POST['taxonomy_mapping']) && !empty($_POST['taxonomy_mapping']) )
        {
            $sanitized_taxonomy_mapping = map_deep( wp_unslash($_POST['taxonomy_mapping']), 'sanitize_text_field' );

            foreach ( $sanitized_taxonomy_mapping as $taxonomy => $mappings )
            {
                $taxonomy = sanitize_text_field($taxonomy);

                $import_mappings[$taxonomy] = array();

                if ( is_array($mappings) && !empty($mappings) )
                {
                    foreach ( $mappings as $crm_value => $term_id )
                    {
                        if ( !empty((int)$term_id) )
                        {
                            $import_mappings[$taxonomy][$crm_value] = (int)$term_id;
                        }
                    }
                }

                if ( isset($_POST['custom_mapping'][$taxonomy]) )
                {
                    $sanitized_custom_mapping = map_deep( wp_unslash($_POST['custom_mapping'][$taxonomy]), 'sanitize_text_field' );

                    foreach ( $sanitized_custom_mapping as $key => $custom_mapping )
                    {
                        $custom_mapping = wp_unslash($custom_mapping);
                        
                        if ( trim($custom_mapping) != '' )
                        {
                            if ( isset($_POST['custom_mapping_value'][$taxonomy][$key]) && trim(sanitize_text_field(wp_unslash($_POST['custom_mapping_value'][$taxonomy][$key]))) != '' )
                            {
                                $import_mappings[$taxonomy][$custom_mapping] = sanitize_text_field(wp_unslash($_POST['custom_mapping_value'][$taxonomy][$key]));
                            }
                        }
                    }
                }
            }
        }

        $import_options['mappings'] = $import_mappings;

        $offices = array();
        if ( isset($_POST['office_mapping']) && is_array($_POST['office_mapping']) && !empty($_POST['office_mapping']) )
        {
            $offices = map_deep( wp_unslash($_POST['office_mapping']), 'sanitize_text_field' );
        }
        $import_options['offices'] = $offices;

        if ( isset($_POST['image_field_arrangement']) && in_array($_POST['image_field_arrangement'], array('', 'comma_delimited')) )
        {
            $import_options['image_field_arrangement'] = sanitize_text_field(wp_unslash($_POST['image_field_arrangement']));
        }
        if ( isset($_POST['image_field']) )
        {
            $import_options['image_field'] = sanitize_text_field(wp_unslash($_POST['image_field']));
        }
        if ( isset($_POST['image_field_delimiter']) )
        {
            $import_options['image_field_delimiter'] = sanitize_text_field(wp_unslash($_POST['image_field_delimiter']));
        }
        if ( isset($_POST['image_fields']) )
        {
            $import_options['image_fields'] = sanitize_textarea_field(wp_unslash($_POST['image_fields']));
        }

        if ( isset($_POST['floorplan_field_arrangement']) && in_array($_POST['floorplan_field_arrangement'], array('', 'comma_delimited')) )
        {
            $import_options['floorplan_field_arrangement'] = sanitize_text_field(wp_unslash($_POST['floorplan_field_arrangement']));
        }
        if ( isset($_POST['floorplan_field']) )
        {
            $import_options['floorplan_field'] = sanitize_text_field(wp_unslash($_POST['floorplan_field']));
        }
        if ( isset($_POST['floorplan_field_delimiter']) )
        {
            $import_options['floorplan_field_delimiter'] = sanitize_text_field(wp_unslash($_POST['floorplan_field_delimiter']));
        }
        if ( isset($_POST['floorplan_fields']) )
        {
            $import_options['floorplan_fields'] = sanitize_textarea_field(wp_unslash($_POST['floorplan_fields']));
        }

        if ( isset($_POST['brochure_field_arrangement']) && in_array($_POST['brochure_field_arrangement'], array('', 'comma_delimited')) )
        {
            $import_options['brochure_field_arrangement'] = sanitize_text_field(wp_unslash($_POST['brochure_field_arrangement']));
        }
        if ( isset($_POST['brochure_field']) )
        {
            $import_options['brochure_field'] = sanitize_text_field(wp_unslash($_POST['brochure_field']));
        }
        if ( isset($_POST['brochure_field_delimiter']) )
        {
            $import_options['brochure_field_delimiter'] = sanitize_text_field(wp_unslash($_POST['brochure_field_delimiter']));
        }
        if ( isset($_POST['brochure_fields']) )
        {
            $import_options['brochure_fields'] = sanitize_textarea_field(wp_unslash($_POST['brochure_fields']));
        }

        if ( isset($_POST['epc_field_arrangement']) && in_array($_POST['epc_field_arrangement'], array('', 'comma_delimited')) )
        {
            $import_options['epc_field_arrangement'] = sanitize_text_field(wp_unslash($_POST['epc_field_arrangement']));
        }
        if ( isset($_POST['epc_field']) )
        {
            $import_options['epc_field'] = sanitize_text_field(wp_unslash($_POST['epc_field']));
        }
        if ( isset($_POST['epc_field_delimiter']) )
        {
            $import_options['epc_field_delimiter'] = sanitize_text_field(wp_unslash($_POST['epc_field_delimiter']));
        }
        if ( isset($_POST['epc_fields']) )
        {
            $import_options['epc_fields'] = sanitize_textarea_field(wp_unslash($_POST['epc_fields']));
        }

        if ( isset($_POST['virtual_tour_field_arrangement']) && in_array($_POST['virtual_tour_field_arrangement'], array('', 'comma_delimited')) )
        {
            $import_options['virtual_tour_field_arrangement'] = sanitize_text_field(wp_unslash($_POST['virtual_tour_field_arrangement']));
        }
        if ( isset($_POST['virtual_tour_field']) )
        {
            $import_options['virtual_tour_field'] = sanitize_text_field(wp_unslash($_POST['virtual_tour_field']));
        }
        if ( isset($_POST['virtual_tour_field_delimiter']) )
        {
            $import_options['virtual_tour_field_delimiter'] = sanitize_text_field(wp_unslash($_POST['virtual_tour_field_delimiter']));
        }
        if ( isset($_POST['virtual_tour_fields']) )
        {
            $import_options['virtual_tour_fields'] = sanitize_textarea_field(wp_unslash($_POST['virtual_tour_fields']));
        }

        $import_options['media_download_clause'] = ( isset($_POST['media_download_clause']) ? sanitize_text_field(wp_unslash($_POST['media_download_clause'])) : 'url_change' );

        if ( empty($_POST['import_id']) && $format == 'rtdf' )
        {
            // this is a new RTDF feed. Default endpoints
            $import_options['send_endpoint'] = '/sendpropertydetails/' . $import_id;
            $import_options['remove_endpoint'] = '/removeproperty/' . $import_id;
            $import_options['get_endpoint'] = '/getbranchpropertylist/' . $import_id;
            $import_options['emails_endpoint'] = '/getbranchemails/' . $import_id;

            $flush = true;
        }

        $options[$import_id] = $import_options;

        update_option( 'propertyhive_property_import', $options );

        if ( $format == 'rtdf' || $format == 'xml_webedge' )
        {
            flush_rewrite_rules();
        }

        wp_redirect( admin_url( 'admin.php?page=propertyhive_import_properties&phpisuccessmessage=' . base64_encode(__( 'Import details saved', 'propertyhive' ) ) ) );
        die();
    }

    public function perform_field_mapping( $post_id, $property, $import_id )
    {
        $import_settings = propertyhive_property_import_get_import_settings_from_id( $import_id );

        if ( $import_settings === false )
        {
            return false;
        }

        if ( !isset($import_settings['field_mapping_rules']) )
        {
            return false;
        }

        if ( empty($import_settings['field_mapping_rules']) )
        {
            return false;
        }

        $original_property = $property;

        if ( is_object($property) )
        {
            $property = propertyhive_property_import_simplexml_to_array_with_cdata_support($property);
        }

        $post_fields_to_update = array(
            'ID' => $post_id,
            'post_status' => 'publish',
        );

        $original_post_fields_to_update = $post_fields_to_update;

        $property_node = '';
        if ( isset($import_settings['property_node']) )
        {
            $property_node = $import_settings['property_node'];
            $explode_property_node = explode("/", $property_node);
            $property_node = $explode_property_node[count($explode_property_node)-1];
        }

        $taxonomies_with_multiple_values = array();

        $multiselect_meta = array();

        $propertyhive_fields = propertyhive_property_import_get_fields_for_field_mapping();

        foreach ( $import_settings['field_mapping_rules'] as $and_rules )
        {
            // field
            // equal
            // propertyhive_field
            // result
            $rules_met = 0;
            foreach ( $and_rules['rules'] as $i => $rule )
            {
                if ( is_object($original_property) && $original_property instanceof SimpleXMLElement )
                {
                    // Using XPATH syntax
                    $xpath = ( ( !empty($property_node) ) ? '/' : '' ) . $property_node . $rule['field'];
                    $values_to_check = $original_property->xpath( $xpath );

                    if ( $values_to_check === FALSE )
                    {
                        // Will return false if invalid xpath syntax
                        continue;
                    }

                    $found = false;

                    if ( empty($values_to_check) )
                    {
                        // xpath syntax ok but field doesn't exist
                        if ( isset($rule['operator']) && $rule['operator'] == 'not_exists' )
                        {
                            $found = true;
                        }
                    }
                    else
                    {
                        // xpath syntax ok but field doesn't exist
                        if ( isset($rule['operator']) && $rule['operator'] == 'exists' )
                        {
                            $found = true;
                        }
                    }

                    foreach ( $values_to_check as $value_to_check )
                    {
                        if ( $rule['equal'] == '*' )
                        {
                            $found = true;
                        }
                        elseif (
                            ( !isset($rule['operator']) || ( isset($rule['operator']) && $rule['operator'] == '=' ) ) && trim($value_to_check) == $rule['equal']
                        )
                        {
                            $found = true;
                        }
                        elseif (
                            ( isset($rule['operator']) && $rule['operator'] == '!=' ) && trim($value_to_check) != $rule['equal']
                        )
                        {
                            $found = true;
                        }
                        elseif (
                            ( isset($rule['operator']) && $rule['operator'] == 'like' ) && strpos(trim($value_to_check), $rule['equal']) !== false
                        )
                        {
                            $found = true;
                        }
                        elseif (
                            ( isset($rule['operator']) && $rule['operator'] == 'begins' ) && strncmp(trim($value_to_check), $rule['equal'], strlen($rule['equal'])) === 0
                        )
                        {
                            $found = true;
                        }
                        elseif (
                            ( isset($rule['operator']) && $rule['operator'] == 'ends' ) && substr(trim($value_to_check), -strlen($rule['equal'])) === $rule['equal']
                        )
                        {
                            $found = true;
                        }
                    }
                    if ( $found )
                    {
                        ++$rules_met;
                    }
                }
                else
                {
                    // loop through all fields in data and see if $rule['field'] is found
                    if ( is_array($property) )
                    {
                        $found = false;

                        if ( substr($rule['field'], 0, 2) == '$.' && version_compare(PHP_VERSION, '8.0', '>=') )
                        {
                            // JSONPath syntax

                            $back_to_json = json_encode($property);
                            $json = json_decode($back_to_json, false);

                            $path = new Flow\JSONPath\JSONPath($json);

                            $results = $path->find($rule['field']);

                            if ( count($results) === 0 ) 
                            {
                                if ( isset($rule['operator']) && $rule['operator'] == 'not_exists' )
                                {
                                    $found = true;
                                }
                                else
                                {
                                    continue;
                                }
                            }

                            if ( isset($rule['operator']) && $rule['operator'] == 'exists' )
                            {
                                $found = true;
                            }

                            foreach ($results as $value_to_check) 
                            {
                                $value_to_check = trim($value_to_check);

                                if ( $rule['equal'] == '*' )
                                {
                                    $found = true;
                                    break;
                                }
                                elseif (
                                    ( !isset($rule['operator']) || ( isset($rule['operator']) && $rule['operator'] == '=' ) ) && trim($value_to_check) == $rule['equal']
                                )
                                {
                                    $found = true;
                                    break;
                                }
                                elseif (
                                    ( isset($rule['operator']) && $rule['operator'] == '!=' ) && trim($value_to_check) != $rule['equal']
                                )
                                {
                                    $found = true;
                                    break;
                                }
                                elseif (
                                    ( isset($rule['operator']) && $rule['operator'] == 'like' ) && strpos(trim($value_to_check), $rule['equal']) !== false
                                )
                                {
                                    $found = true;
                                    break;
                                }
                                elseif (
                                    ( isset($rule['operator']) && $rule['operator'] == 'begins' ) && strncmp(trim($value_to_check), $rule['equal'], strlen($rule['equal'])) === 0
                                )
                                {
                                    $found = true;
                                    break;
                                }
                                elseif (
                                    ( isset($rule['operator']) && $rule['operator'] == 'ends' ) && substr(trim($value_to_check), -strlen($rule['equal'])) === $rule['equal']
                                )
                                {
                                    $found = true;
                                    break;
                                }
                            }
                        }
                        else
                        {
                            // Not JSONPath syntax
                            
                            $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $rule['field'] );

                            if ( $value_to_check === false )
                            {
                                // field not found
                                if ( isset($rule['operator']) && $rule['operator'] == 'not_exists' )
                                {
                                    $found = true;
                                }
                                else
                                {
                                    continue;
                                }
                            }

                            if ( isset($rule['operator']) && $rule['operator'] == 'exists' )
                            {
                                $found = true;
                            }

                            $matches = function($value) use (&$matches, $rule)
                            {
                                $needle = (string)$rule['equal'];
                                $op     = isset($rule['operator']) ? $rule['operator'] : '=';

                                // Special semantics for '!=': ALL scalars must be != needle
                                if ($op === '!=') {
                                    if (is_array($value) || is_object($value)) {
                                        foreach (is_object($value) ? get_object_vars($value) : $value as $v) {
                                            if (!$matches($v)) {
                                                return false; // any equal down the tree ⇒ fail
                                            }
                                        }
                                        return true; // none equal anywhere
                                    }

                                    // scalar
                                    $val = trim((string)$value);
                                    return $val !== $needle;
                                }

                                // For =, like, begins, ends: ANY scalar match is enough
                                if (is_array($value) || is_object($value)) {
                                    foreach (is_object($value) ? get_object_vars($value) : $value as $v) {
                                        if ($matches($v)) {
                                            return true;
                                        }
                                    }
                                    return false;
                                }

                                // scalar
                                $val = trim((string)$value);

                                switch ($op) {
                                    case '=':
                                        return $val === $needle;

                                    case 'like':
                                        return strpos($val, $needle) !== false;

                                    case 'begins':
                                        return strncmp($val, $needle, strlen($needle)) === 0;

                                    case 'ends':
                                        return substr($val, -strlen($needle)) === $needle;

                                    default:
                                        // unknown operator here ⇒ treat as no match
                                        return false;
                                }
                            };

                            if ( $rule['equal'] == '*' )
                            {
                                $found = true;
                            }
                            else
                            {
                                if ( $matches($value_to_check) ) 
                                {
                                    $found = true;
                                }
                            }
                        }

                        if ( $found )
                        {
                            ++$rules_met;
                        }
                    }
                }
            }

            if ( $rules_met == count($and_rules['rules']) )
            {
                $result = $and_rules['result'];

                preg_match_all('/{[^}]*}/', $and_rules['result'], $matches);
                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
                {
                    foreach ( $matches[0] as $match )
                    {
                        $field_name = str_replace(array("{", "}"), "", $match);
                        $value_to_check = '';

                        if ( is_object($original_property) && $original_property instanceof SimpleXMLElement )
                        {
                            // Using XPATH syntax
                            $values_to_check = $original_property->xpath(  ( ( !empty($property_node) ) ? '/' : '' ) . $property_node . $field_name );
                            if ( $values_to_check !== false && is_array($values_to_check) && !empty($values_to_check) )
                            {
                                $value_to_check = (string)$values_to_check[0];
                            }
                        }
                        else
                        {
                            if ( substr($field_name, 0, 2) == '$.' && version_compare(PHP_VERSION, '8.0', '>=') )
                            {
                                // JSONPath syntax

                                $back_to_json = json_encode($property);
                                $json = json_decode($back_to_json, false);

                                $path = new Flow\JSONPath\JSONPath($json);

                                $values_to_check = $path->find($field_name);

                                if ( $values_to_check !== false && is_array($values_to_check) && !empty($values_to_check) )
                                {
                                    $value_to_check = (string)$values_to_check[0];
                                }
                            }
                            else
                            {
                                // Not JSONPath syntax

                                $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

                                if ( $value_to_check === false )
                                {
                                    $value_to_check = '';
                                }
                            }
                        }

                        $value_to_check = trim($value_to_check);
                        $result = str_replace($match, $value_to_check, $result);
                    }
                }

                $result = trim($result);

                // we found a matching field with the required value
                if ( isset($propertyhive_fields[$and_rules['propertyhive_field']]) && $propertyhive_fields[$and_rules['propertyhive_field']]['type'] == 'post_field' )
                {
                    $post_fields_to_update[$and_rules['propertyhive_field']] = $result;
                }
                elseif ( isset($propertyhive_fields[$and_rules['propertyhive_field']]) && $propertyhive_fields[$and_rules['propertyhive_field']]['type'] == 'taxonomy' )
                {
                    // only do for taxonomies that have a single value, else we'll do multiple ones later
                    preg_match_all('/\[[^\]]*\]/', $and_rules['propertyhive_field'], $matches);
                    if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
                    {
                        foreach ( $matches[0] as $match )
                        {
                            $taxonomy = str_replace($match, '', $and_rules['propertyhive_field']);

                            if ( !isset($taxonomies_with_multiple_values[$taxonomy]) ) { $taxonomies_with_multiple_values[$taxonomy] = array(); }

                            // check term exists and get termID as wp_set_object_terms() requires the ID
                            $term_id = '';
                            if ( $taxonomy == 'property_feature' )
                            {
                                // create if not exists
                                $term = term_exists( $result, $taxonomy );
                                if ( $term !== 0 && $term !== null && isset($term['term_id']) )
                                {
                                    $term_id = (int)$term['term_id'];
                                }
                                else
                                {
                                    $term = wp_insert_term( $result, $taxonomy );
                                    if ( is_array($term) && isset($term['term_id']) )
                                    {
                                        $term_id = (int)$term['term_id'];
                                    }
                                }
                            }
                            else
                            {
                                $term = term_exists( $result, $taxonomy );
                                if ( $term !== 0 && $term !== null && isset($term['term_id']) )
                                {
                                    $term_id = (int)$term['term_id'];
                                }
                            }

                            if ( !empty($term_id) )
                            {
                                $taxonomies_with_multiple_values[$taxonomy][] = $term_id;
                            }
                        }
                    }
                    else
                    {
                        if ( isset($and_rules['delimited']) && $and_rules['delimited'] === true && isset($and_rules['delimited_character']) && !empty($and_rules['delimited_character']) )
                        {
                            $taxonomy = $and_rules['propertyhive_field'];

                            if ( !isset($taxonomies_with_multiple_values[$taxonomy]) ) { $taxonomies_with_multiple_values[$taxonomy] = array(); }

                            $results = explode($and_rules['delimited_character'], $result);
                            $results = array_map('trim', $results);
                            $results = array_filter($results);

                            foreach ( $results as $result )
                            {
                                // create if not exists
                                $term = term_exists( $result, $taxonomy );
                                if ( $term !== 0 && $term !== null && isset($term['term_id']) )
                                {
                                    $term_id = (int)$term['term_id'];
                                }
                                else
                                {
                                    $term = wp_insert_term( $result, $taxonomy );
                                    if ( is_array($term) && isset($term['term_id']) )
                                    {
                                        $term_id = (int)$term['term_id'];
                                    }
                                }

                                if ( !empty($term_id) )
                                {
                                    $taxonomies_with_multiple_values[$taxonomy][] = $term_id;
                                }
                            }
                        }
                        else
                        {
                            wp_set_object_terms( $post_id, $result, $and_rules['propertyhive_field'] );
                        }
                    }
                }
                else
                {
                    if ( $and_rules['propertyhive_field'] == 'full_description' && $result != '' )
                    {
                        $department = get_post_meta( $post_id, '_department', true );
                        if ( $department == 'commercial' )
                        {
                            update_post_meta( $post_id, '_descriptions', '1' );
                            update_post_meta( $post_id, '_description_name_0', '' );
                            update_post_meta( $post_id, '_description_0', $result );
                        }
                        else
                        {
                            update_post_meta( $post_id, '_rooms', '1' );
                            update_post_meta( $post_id, '_room_name_0', '' );
                            update_post_meta( $post_id, '_room_dimensions_0', '' );
                            update_post_meta( $post_id, '_room_description_0', $result );
                        }
                    }
                    else
                    {
                        if ( isset($propertyhive_fields[$and_rules['propertyhive_field']]) && isset($propertyhive_fields[$and_rules['propertyhive_field']]['field_type']) && $propertyhive_fields[$and_rules['propertyhive_field']]['field_type'] == 'multiselect' )
                        {
                            if ( !isset($multiselect_meta[$and_rules['propertyhive_field']]) ) { $multiselect_meta[$and_rules['propertyhive_field']] = array(); }
                            $multiselect_meta[$and_rules['propertyhive_field']][] = $result;
                        }
                        else
                        {
                            if ( isset($propertyhive_fields[$and_rules['propertyhive_field']]) && isset($propertyhive_fields[$and_rules['propertyhive_field']]['acf']) && $propertyhive_fields[$and_rules['propertyhive_field']]['acf'] === true )
                            {
                                if ( function_exists('update_field') )
                                {
                                    update_field( $and_rules['propertyhive_field'], $result, $post_id );
                                }
                            }
                            else
                            {
                                update_post_meta( $post_id, $and_rules['propertyhive_field'], $result );
                            }
                        }
                    }
                }
            }
        }

        if ( !empty($multiselect_meta) )
        {
            foreach ( $multiselect_meta as $field_name => $values )
            {
                delete_post_meta( $post_id, $field_name );
                foreach ( $values as $value )
                {
                    add_post_meta( $post_id, $field_name, $value );
                } 
            }
        }

        if ( !empty($taxonomies_with_multiple_values) )
        {
            foreach ( $taxonomies_with_multiple_values as $taxonomy => $taxonomy_values )
            {
                if ( !empty($taxonomy_values) )
                {
                    wp_set_object_terms( $post_id, $taxonomy_values, $taxonomy );
                }
            }
        }

        // remove fields that are handled in the main XML/CSV import class
        $update_post = false;
        if ( $import_settings['format'] == 'csv' || $import_settings['format'] == 'xml' )
        {
            if ( isset($post_fields_to_update['post_title']) ) { unset($post_fields_to_update['post_title']); }
            if ( isset($post_fields_to_update['post_excerpt']) ) { unset($post_fields_to_update['post_excerpt']); }
            if ( isset($post_fields_to_update['post_content']) ) { unset($post_fields_to_update['post_content']); }
            if ( isset($post_fields_to_update['post_status']) ) { unset($post_fields_to_update['post_status']); }

            if ( count($post_fields_to_update) > 1 )
            {
                $update_post = true;
            }
        }
        else
        {
            if ( $post_fields_to_update != $original_post_fields_to_update ) // Something about the post has changed
            {
                $update_post = true;
            }
        }

        if ( isset($post_fields_to_update['post_name']) )
        {
            $post_fields_to_update['post_name'] = sanitize_title($post_fields_to_update['post_name']);
        }

        if ( $update_post === true ) // Something about the post has changed
        {
            wp_update_post($post_fields_to_update, TRUE);
        }
    }

    public function set_generic_propertyhive_property_data( $post_id, $property, $import_id )
    {
        // Ensure a department is set
        $department = get_post_meta( $post_id, '_department', true );
        if ( empty($department) )
        {
            $department = get_option( 'propertyhive_primary_department', 'residential-sales' );
            update_post_meta( $post_id, '_department', $department );
        }
    }

    public function get_xml_mapped_field_value( $value, $property, $field_name, $import_id )
    {
        $import_settings = propertyhive_property_import_get_import_settings_from_id( $import_id );

        if ( $import_settings === false )
        {
            return $value;
        }

        if ( !isset($import_settings['field_mapping_rules']) )
        {
            return $value;
        }

        if ( empty($import_settings['field_mapping_rules']) )
        {
            return $value;
        }

        $property_node = '';
        if ( isset($import_settings['property_node']) )
        {
            $property_node = $import_settings['property_node'];
            $explode_property_node = explode("/", $property_node);
            $property_node = $explode_property_node[count($explode_property_node)-1];
        }

        foreach ( $import_settings['field_mapping_rules'] as $and_rules )
        {
            if ( $and_rules['propertyhive_field'] == $field_name )
            {
                // This is the field we're after. Check rules are met
                $rules_met = 0;
                foreach ( $and_rules['rules'] as $i => $rule )
                {
                    if ( is_object($property) && $property instanceof SimpleXMLElement )
                    {
                        // Using XPATH syntax
                        $values_to_check = $property->xpath('/' . $property_node . $rule['field']);

                        if ( $values_to_check === FALSE )
                        {
                            continue;
                        }

                        $found = false;

                        if ( empty($values_to_check) )
                        {
                            // xpath syntax ok but field doesn't exist
                            if ( isset($rule['operator']) && $rule['operator'] == 'not_exists' )
                            {
                                $found = true;
                            }
                        }
                        else
                        {
                            // xpath syntax ok but field doesn't exist
                            if ( isset($rule['operator']) && $rule['operator'] == 'exists' )
                            {
                                $found = true;
                            }
                        }

                        foreach ( $values_to_check as $value_to_check )
                        {
                            if ( $rule['equal'] == '*' )
                            {
                                $found = true;
                            }
                            elseif (
                                ( !isset($rule['operator']) || ( isset($rule['operator']) && $rule['operator'] == '=' ) ) && $value_to_check == $rule['equal']
                            )
                            {
                                $found = true;
                            }
                            elseif (
                                ( isset($rule['operator']) && $rule['operator'] == '!=' ) && $value_to_check != $rule['equal']
                            )
                            {
                                $found = true;
                            }
                            elseif (
                                ( isset($rule['operator']) && $rule['operator'] == 'like' ) && strpos($value_to_check, $rule['equal']) !== false
                            )
                            {
                                $found = true;
                            }
                            elseif (
                            ( isset($rule['operator']) && $rule['operator'] == 'begins' ) && strncmp(trim($value_to_check), $rule['equal'], strlen($rule['equal'])) === 0
                            )
                            {
                                $found = true;
                            }
                            elseif (
                                ( isset($rule['operator']) && $rule['operator'] == 'ends' ) && substr(trim($value_to_check), -strlen($rule['equal'])) === $rule['equal']
                            )
                            {
                                $found = true;
                            }
                        }

                        if ( $found )
                        {
                            ++$rules_met;
                        }
                    }
                }

                if ( $rules_met == count($and_rules['rules']) )
                {
                    $result = $and_rules['result'];

                    preg_match_all('/{[^}]*}/', $and_rules['result'], $matches);

                    if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
                    {
                        foreach ( $matches[0] as $match )
                        {
                            $field_name2 = str_replace(array("{", "}"), "", $match);
                            $value_to_check = '';

                            if ( substr($field_name2, 0, 1) == '/' )
                            {
                                // Using XPATH syntax
                                $values_to_check = $property->xpath('/' . $property_node . $field_name2);
                                if ( $values_to_check !== false && is_array($values_to_check) && !empty($values_to_check) )
                                {
                                    $value_to_check = (string)$values_to_check[0];
                                }
                            }

                            $value_to_check = trim($value_to_check);
                            $result = str_replace($match, $value_to_check, $result);
                        }
                    }

                    return trim($result);
                }
            }
        }

        return $value;
    }

    public function get_csv_mapped_field_value( $value, $property, $field_name, $import_id )
    {
        $import_settings = propertyhive_property_import_get_import_settings_from_id( $import_id );

        if ( $import_settings === false )
        {
            return $value;
        }

        if ( !isset($import_settings['field_mapping_rules']) )
        {
            return $value;
        }

        if ( empty($import_settings['field_mapping_rules']) )
        {
            return $value;
        }

        foreach ( $import_settings['field_mapping_rules'] as $and_rules )
        {
            if ( $and_rules['propertyhive_field'] == $field_name )
            {
                // This is the field we're after. Check rules are met
                $rules_met = 0;
                foreach ( $and_rules['rules'] as $i => $rule )
                {
                    $found = false;

                    $value_to_check = '';
                    if ( isset($property[$rule['field']]) )
                    {
                        if ( isset($rule['operator']) && $rule['operator'] == 'exists' )
                        {
                            $found = true;
                        }

                        $value_to_check = $property[$rule['field']];
                    }
                    else
                    {
                        if ( isset($rule['operator']) && $rule['operator'] == 'not_exists' )
                        {
                            $found = true;
                        }
                    }
                    
                    if ( $rule['equal'] == '*' )
                    {
                        $found = true;
                    }
                    elseif (
                        ( !isset($rule['operator']) || ( isset($rule['operator']) && $rule['operator'] == '=' ) ) && $value_to_check == $rule['equal']
                    )
                    {
                        $found = true;
                    }
                    elseif (
                        ( isset($rule['operator']) && $rule['operator'] == '!=' ) && $value_to_check != $rule['equal']
                    )
                    {
                        $found = true;
                    }
                    elseif (
                        ( isset($rule['operator']) && $rule['operator'] == 'like' ) && strpos($value_to_check, $rule['equal']) !== false
                    )
                    {
                        $found = true;
                    }
                    elseif (
                        ( isset($rule['operator']) && $rule['operator'] == 'begins' ) && strncmp(trim($value_to_check), $rule['equal'], strlen($rule['equal'])) === 0
                    )
                    {
                        $found = true;
                    }
                    elseif (
                        ( isset($rule['operator']) && $rule['operator'] == 'ends' ) && substr(trim($value_to_check), -strlen($rule['equal'])) === $rule['equal']
                    )
                    {
                        $found = true;
                    }

                    if ( $found )
                    {
                        ++$rules_met;
                    }
                }

                if ( $rules_met == count($and_rules['rules']) )
                {
                    $result = $and_rules['result'];

                    preg_match_all('/{[^}]*}/', $and_rules['result'], $matches);
                    if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
                    {
                        foreach ( $matches[0] as $match )
                        {
                            $field_name2 = str_replace(array("{", "}"), "", $match);
                            $value_to_check = '';

                            if ( isset($property[$field_name2]) )
                            {
                                $value_to_check = $property[$field_name2];
                            }

                            $value_to_check = trim($value_to_check);
                            $result = str_replace($match, $value_to_check, $result);
                        }
                    }

                    return trim($result);
                }
            }
        }

        return $value;
    }

    public function update_queue_status($post_id, $property, $import_id, $instance_id = null)
    {
        global $wpdb;

        if ( empty($instance_id) )
        {
            return;
        }

        // get CRM_ID from post meta
        $imported_ref_key = ( ( $import_id != '' ) ? '_imported_ref_' . $import_id : '_imported_ref' );
        $imported_ref_key = apply_filters( 'propertyhive_property_import_property_imported_ref_key', $imported_ref_key, $import_id );

        $crm_id = get_post_meta( $post_id, $imported_ref_key, true );

        $current_date = new DateTimeImmutable( 'now', new DateTimeZone('UTC') );
        $current_date = $current_date->format("Y-m-d H:i:s");

        $wpdb->update( 
            $wpdb->prefix . "propertyhive_property_import_property_queue", 
            array( 
                'status' => 'processed',
                'date_processed' => $current_date
            ),
            array( 
                'crm_id' => $crm_id,
                'instance_id' => $instance_id,
                'status' => 'pending'
            )
        );
    }

    public function set_property_data_date($post_id, $property, $import_id, $instance_id = null)
    {
        update_post_meta( $post_id, '_property_import_data_time', time() );
    }
}

new PH_Property_Import_Import();