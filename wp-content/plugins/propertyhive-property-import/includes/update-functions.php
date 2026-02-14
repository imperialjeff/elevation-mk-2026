<?php
/**
 * PropertyHive Property Import Updates
 *
 * Functions for updating data during an update.
 *
 * @author      PropertyHive
 * @category    Core
 * @package     PropertyHive/Functions
 * @version     1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * @return void
 */
function propertyhive_property_import_update_300_global_options_and_split_availabilities() 
{
    $email_reports = '';
    $email_reports_to = '';
    $remove_action = 'remove_all_media_except_first_image';
    $media_processing = '';

    $options = get_option( 'propertyhive_property_import' , array() );
    $imports = ( isset($options) && is_array($options) && !empty($options) ) ? $options : array();

    $new_imports = array();
    if ( !empty($imports) )
    {
        foreach ( $imports as $import_id => $import_settings )
        {
            // Cater for where the sales availability was just called 'availabilities'
            if ( isset($import_settings['mappings']['availability']) && !isset($import_settings['mappings']['sales_availability']) )
            {
                $import_settings['mappings']['sales_availability'] = $import_settings['mappings']['availability'];
            }

            if ( isset($import_settings['mappings']['availability']) && !isset($import_settings['mappings']['lettings_availability']) )
            {
                $import_settings['mappings']['lettings_availability'] = $import_settings['mappings']['availability'];
            }

            if ( isset($import_settings['mappings']['availability']) && !isset($import_settings['mappings']['commercial_availability']) )
            {
                $import_settings['mappings']['commercial_availability'] = $import_settings['mappings']['availability'];
            }
            
            if ( !isset($import_settings['running']) || $import_settings['running'] != 1 )
            {
                $new_imports[$import_id] = $import_settings;
                continue;
            }

            if ( isset($import_settings['deleted']) && $import_settings['deleted'] == 1 )
            {
                $new_imports[$import_id] = $import_settings;
                continue;
            }

            // Update global options based on options set on individual imports
            // - Email reports
            // - Remove action
            // - Media processing

            if ( 
                isset($import_settings['email_reports']) && $import_settings['email_reports'] == 'yes' && 
                isset($import_settings['email_reports_to']) && $import_settings['email_reports_to'] != '' 
            )
            {
                $email_reports = 'yes';
                $email_reports_to = sanitize_email($import_settings['email_reports_to']);
            }

            if ( isset($import_settings['remove_action']) )
            {
                $remove_action = $import_settings['remove_action'];
            }

            if ( isset($import_settings['dont_remove']) && $import_settings['dont_remove'] == 'yes' )
            {
                $remove_action = 'nothing';
            }

            if ( isset($import_settings['queue_media_downloads']) && $import_settings['queue_media_downloads'] == 'yes' )
            {
                $media_processing = 'background';
            }

            $new_imports[$import_id] = $import_settings;
        }

        update_option( 'propertyhive_property_import', $new_imports, false );

        update_option( 'propertyhive_property_import_email_reports', $email_reports, false );
        update_option( 'propertyhive_property_import_email_reports_to', $email_reports_to, false );
        update_option( 'propertyhive_property_import_remove_action', $remove_action, false );
        update_option( 'propertyhive_property_import_media_processing', $media_processing, false );
    }
}