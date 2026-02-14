<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function propertyhive_property_import_get_import_formats( $import_id = '' )
{
    $curl_warning = !function_exists('curl_version') ? __( 'cURL must be enabled in order to use this format', 'propertyhive' ) : '';
    $simplexml_warning = !class_exists('SimpleXMLElement') ? __( 'SimpleXML must be enabled in order to use this format', 'propertyhive' ) : '';

    $uploads_dir = wp_upload_dir();
    if( $uploads_dir['error'] === FALSE )
    {
        $uploads_dir = $uploads_dir['basedir'] . '/ph_import/';
    }

    $formats = array();

    $formats['xml_10ninety'] = array(
        'name' => __( '10ninety XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-10ninety-xml-import.php',
        'id_field' => 'AGENT_REF',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/334-10ninety',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_acquaint'] = array(
        'name' => __( 'Acquaint XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-acquaint-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://www.acquaintcrm.co.uk/datafeeds/standardxml/',
                'tooltip' => __( 'A comma separated list of URL\'s to the acquaint XML data', 'propertyhive' ),
            )
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/335-acquaint',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['api_agency_pilot'] = array(
        'name' => __( 'Agency Pilot API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-agency-pilot-api-import.php',
        'id_field' => 'ID',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://{site}.agencypilot.com',
            ),
            array(
                'id' => 'client_id',
                'label' => __( 'Client ID', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'client_secret',
                'label' => __( 'Client Secret', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'dynamic_taxonomy_values' => true,
        'help_url' => 'https://docs.wp-property-hive.com/article/336-agency-pilot',
        'test_button' => true,
    );

    $formats['json_agency_pilot'] = array(
        'name' => __( 'Agency Pilot JSON', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-agency-pilot-json-import.php',
        'id_field' => 'Key',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => '{sitename}.agencypilot.com',
            ),
            array(
                'id' => 'password',
                'label' => __( 'Password', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'dynamic_taxonomy_values' => true,
        'help_url' => 'https://docs.wp-property-hive.com/article/336-agency-pilot',
        'test_button' => true,
    );

    $formats['json_agentbox'] = array(
        'name' => __( 'Agentbox API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-agentbox-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => '{sitename}.agencypilot.com',
            ),
            array(
                'id' => 'client_id',
                'label' => __( 'Client ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'infos' => array_filter( 
            array( 
                __( 'Agentbox are strict on the number of requests made per second. As it takes so many individual requests to obtain the data we require, we\'ve had to add pauses to prevent you hitting this throttling limit. As a result, initial imports may take a while and you may need to increase the timeout limit on your server.', 'propertyhive' ), 
            ) 
        ),
        //'help_url' => 'https://docs.wp-property-hive.com/article/336-agency-pilot',
        'test_button' => true,
    );

    $formats['json_letmc'] = array(
        'name' => __( 'agentOS API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-agentos-json-import.php',
        'id_field' => 'OID',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'short_name',
                'label' => __( 'Short Name', 'propertyhive' ),
                'type' => 'text',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/337-agentos',
        'infos' => array_filter( array( __( 'AgentOS are very strict on the number of requests made per minute. As it takes so many individual requests to obtain the data we require, we\'ve had to add pauses to prevent you hitting this throttling limit. As a result, imports from AgentOS may take a while and therefore you\'ll likely need to increase the timeout limit on your server.', 'propertyhive' ) ) ),
        'test_button' => true,
    );

    $formats['xml_agestanet'] = array(
        'name' => __( 'AgestaNET', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-agestanet-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/agestanet/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_vebra_api'] = array(
        'name' => __( 'Alto by Vebra', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-vebra-api-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'datafeed_id',
                'label' => __( 'Datafeed ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'username',
                'label' => __( 'Username', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'password',
                'label' => __( 'Password', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/362-alto-by-vebra',
        'warnings' => array_filter( array( $curl_warning, $simplexml_warning ) ),
        'limit_properties' => false,
        'test_button' => false,
    );

    $formats['xml_apex27'] = array(
        'name' => __( 'Apex27', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-apex27-xml-import.php',
        'id_field' => 'ID',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/338-apex27',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_apimo'] = array(
        'name' => __( 'Apimo', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-apimo-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'provider_id',
                'label' => __( 'Provider ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'token',
                'label' => __( 'Token', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'agency_id',
                'label' => __( 'Agency ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/apimo/',
        'test_button' => true,
    );

    $formats['json_arthur'] = array(
        'name' => __( 'Arthur Online API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-arthur-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'client_id',
                'label' => __( 'Client ID', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'client_secret',
                'label' => __( 'Client Secret', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'entity_id',
                'label' => __( 'Entity ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'import_structure',
                'label' => __( 'Import Structure', 'propertyhive' ),
                'type' => 'select',
                'options' => array(
                    '' => __( 'Top-level property with units/rooms as children', 'propertyhive' ),
                    'no_children' => __( 'Top-level property and units as properties', 'propertyhive' ),
                    'top_level_only' => __( 'Top-level property only. Don\'t import units', 'propertyhive' ),
                    'units_only' => __( 'Units/Rooms only. Don\'t import top-level property as a separate property', 'propertyhive' ),
                ),
            ),
            array(
                'type' => 'html',
                'label' => __( 'Callback URL', 'propertyhive' ),
                'html' => !isset($_GET['import_id']) || empty((int)$_GET['import_id']) ? 'Callback will appear here after being saved.' : admin_url('admin.php?page=propertyhive_import_properties&arthur_callback=1&import_id=' . (int)$_GET['import_id'])
            ),
            array(
                'id' => 'access_token',
                'type' => 'hidden',
            ),
            array(
                'id' => 'access_token_expires',
                'type' => 'hidden',
            ),
            array(
                'id' => 'refresh_token',
                'type' => 'hidden',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/339-arthur-online',
        'limit_properties' => false,
        'test_button' => false,
    );

    $formats['json_bdp'] = array(
        'name' => __( 'BDP API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-bdp-json-import.php',
        'id_field' => 'property_id',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'secret',
                'label' => __( 'Secret', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'account_id',
                'label' => __( 'Account ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'base_url',
                'label' => __( 'API Base URL', 'propertyhive' ),
                'type' => 'text',
                'default' => 'https://api.bdphq.com',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/bdp/',
        'warnings' => array_filter( array( $curl_warning ) ),
        'test_button' => true,
    );

    $formats['blm_local'] = array(
        'name' => __( 'BLM - Local Directory', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-blm-import.php',
        'id_field' => 'AGENT_REF',
        'fields' => array(
            array(
                'id' => 'local_directory',
                'label' => __( 'Local Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => $uploads_dir,
                'tooltip' => __( 'The full server path to where the BLM files will be received into', 'propertyhive' ),
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/489-blm',
    );

    $formats['blm_remote'] = $formats['blm_local'];
    $formats['blm_remote']['name'] = __( 'BLM - Remote URL', 'propertyhive' );
    $formats['blm_remote']['fields'] = array(
        array(
            'id' => 'url',
            'label' => __( 'URL', 'propertyhive' ),
            'type' => 'text',
            'placeholder' => 'https://',
        ),
    );

    $formats['json_casafari'] = array(
        'name' => __( 'CASAFARI API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-casafari-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'environment',
                'label' => __( 'Environment', 'propertyhive' ),
                'type' => 'select',
                'options' => array(
                    'sandbox' => 'Sandbox',
                    'production' => 'Production'
                ),
                'default' => 'production'
            ),
            array(
                'id' => 'api_token',
                'label' => __( 'API Token', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/casafari/'
        'test_button' => true,
    );

    $formats['csv'] = array(
        'name' => __( 'CSV', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-csv-import.php',
        'id_field' => '',
        'fields' => array(
            array(
                'id' => 'csv_url',
                'label' => __( 'CSV URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'csv_delimiter',
                'label' => __( 'CSV Delimiter Character', 'propertyhive' ),
                'type' => 'text',
                'default' => ',',
                'css' => 'max-width:50px;'
            ),
            array(
                'type' => 'html',
                'label' => '',
                'html' => '<a href="" class="button phpi-fetch-csv-fields">' . __( 'Fetch CSV', 'propertyhive' ) . '</a>'
            ),
            array(
                'id' => 'property_id_field',
                'label' => __( 'Unique Property ID Field', 'propertyhive' ),
                'type' => 'select',
                'tooltip' => __( 'Please select which field in the CSV determines the property\'s unique ID. We\'ll use this to determine if a property has been inserted previously or not. If no options show, click the \'Fetch CSV\' button above', 'propertyhive' ),
            ),
            array(
                'id' => 'property_field_options',
                'type' => 'hidden',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/csv/'
    );

    $formats['json_dezrez'] = array(
        'name' => __( 'Dezrez Rezi JSON', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-dezrez-json-import.php',
        'id_field' => 'RoleId',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'branch_ids',
                'label' => __( 'Branch ID(s)', 'propertyhive' ),
                'type' => 'text',
                'tooltip' => __( 'A comma-delimited list of Dezrez branch IDs. Leave blank to import properties for all branches', 'propertyhive' ),
            ),
            array(
                'id' => 'tags',
                'label' => __( 'Tag(s)', 'propertyhive' ),
                'type' => 'text',
                'tooltip' => __( 'A comma-delimited list of agent defined tags within Dezrez. Leave blank if not wanting to filter properties by tag', 'propertyhive' ),
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/342-dezrez',
        'infos' => array_filter( 
            array( 
                __( 'Dezrez are strict on the number of requests made per second. As it takes so many individual requests to obtain the data we require, we\'ve had to add pauses to prevent you hitting this throttling limit. As a result, initial imports from Dezrez may take a while and you may need to increase the timeout limit on your server.', 'propertyhive' ), 
            ) 
        ),
        'test_button' => true,
    );

    $formats['xml_dezrez'] = array(
        'name' => __( 'DezrezOne XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-dezrez-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'eaid',
                'label' => __( 'Estate Agency ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'branch_ids',
                'label' => __( 'Branch ID(s)', 'propertyhive' ),
                'type' => 'text',
                'tooltip' => __( 'A comma-delimited list of Dezrez branch IDs. Leave blank to import properties for all branches', 'propertyhive' ),
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/342-dezrez',
        'test_button' => true,
    );

    $formats['xml_domus'] = array(
        'name' => __( 'Domus API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-domus-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://{your-site}.domus.net/site/go/api/',
            )
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/343-domus',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_ego'] = array(
        'name' => __( 'eGO Real Estate XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-ego-xml-import.php',
        'id_field' => 'Referencia',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/ego/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_estatesit'] = array(
        'name' => __( 'EstatesIT XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-estatesit-xml-import.php',
        'id_field' => 'propcode',
        'fields' => array(
            array(
                'id' => 'local_directory',
                'label' => __( 'Local Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => $uploads_dir,
                'tooltip' => __( 'The full server path to where the XML files will be received into', 'propertyhive' ),
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/344-estates-it-pchomes',
        'warnings' => array_filter( array( $simplexml_warning ) ),
    );

    $formats['xml_expertagent'] = array(
        'name' => __( 'Expert Agent XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-expertagent-xml-import.php',
        'id_field' => 'reference',
        'fields' => array(
            array(
                'id' => 'data_source',
                'label' => __( 'Data Source', 'propertyhive' ),
                'type' => 'select',
                'options' => array(
                    '' => 'FTP',
                    'local' => 'Local Directory'
                ),
                'default' => '',
            ),
            array(
                'id' => 'ftp_host',
                'label' => __( 'FTP Host', 'propertyhive' ),
                'type' => 'text',
                'default' => 'ftp.expertagent.co.uk',
            ),
            array(
                'id' => 'ftp_user',
                'label' => __( 'FTP Username', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => '',
            ),
            array(
                'id' => 'ftp_pass',
                'label' => __( 'FTP Password', 'propertyhive' ),
                'type' => 'password',
                'placeholder' => '',
            ),
            array(
                'id' => 'ftp_passive',
                'label' => __( 'Use FTP Passive Mode', 'propertyhive' ),
                'type' => 'checkbox',
            ),
            array(
                'id' => 'xml_filename',
                'label' => __( 'XML File Name', 'propertyhive' ),
                'type' => 'text',
                'default' => 'properties.xml',
            ),
            array(
                'id' => 'local_directory',
                'label' => __( 'Local File Path', 'propertyhive' ),
                'type' => 'text',
                'default' => $uploads_dir . 'properties.xml',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/345-expert-agent',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_getrix'] = array(
        'name' => __( 'Getrix XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-getrix-xml-import.php',
        'id_field' => 'IDImmobile',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/getrix/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_gnomen'] = array(
        'name' => __( 'Gnomen XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-gnomen-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/346-gnomen',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_infocasa'] = array(
        'name' => __( 'InfoCasa XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-infocasa-xml-import.php',
        'id_field' => 'pi',
        'fields' => array(
            array(
                'id' => 'idre',
                'label' => __( 'IDRE (Agency Identifier)', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'license_key',
                'label' => __( 'License Key', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'language',
                'label' => __( 'Language', 'propertyhive' ),
                'type' => 'select',
                'options' => array(
                    'en' => 'English',
                    'es' => 'Spanish',
                    'de' => 'German',
                    'nl' => 'Dutch',
                    'da' => 'Danish',
                    'fr' => 'French',
                    'sv' => 'Swedish',
                    'no' => 'Norwegian',
                    'fi' => 'Finnish',
                    'ca' => 'Catalan',
                    'it' => 'Italian',
                    'ru' => 'Russian',
                    'el' => 'Greek',
                    'cs' => 'Czech',
                    'sk' => 'Slovak',
                    'pl' => 'Polish',
                )
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/infocasa/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'infos' => array_filter( 
            array( 
                __( 'Your server IP address (<strong>' . $_SERVER['SERVER_ADDR'] . '</strong>) will need to be whitelisted by InfoCasa before you\'re able to import properties.', 'propertyhive' ),
                __( '<strong>This format is in BETA</strong>. Please contact us at info@wp-property-hive.com if you experience issues.', 'propertyhive' ),

            ) 
        ),
        'test_button' => true,
    );

    $formats['json_inmobalia'] = array(
        'name' => __( 'Inmobalia API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-inmobalia-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/inmobalia/',
        'test_button' => true,
    );

    $formats['xml_inmobalia'] = array(
        'name' => __( 'Inmobalia XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-inmobalia-xml-import.php',
        'id_field' => 'reference',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://docs.wp-property-hive.com/article/338-apex27',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_inmovilla'] = array(
        'name' => __( 'Inmovilla XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-inmovilla-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/inmovilla/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_inmoweb'] = array(
        'name' => __( 'Inmoweb XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-inmoweb-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/inmoweb/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_jupix'] = array(
        'name' => __( 'Jupix XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-jupix-xml-import.php',
        'id_field' => 'propertyID',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/347-jupix',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_juvo'] = array(
        'name' => __( 'Juvo XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-juvo-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/348-juvo',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_kato'] = array(
        'name' => __( 'Kato API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-kato-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'environment',
                'label' => __( 'API Environment', 'propertyhive' ),
                'type' => 'select',
                'options' => array(
                    'sandbox' => 'Sandbox',
                    'production' => 'Production',
                )
            ),
            array(
                'id' => 'client_id',
                'label' => __( 'Client ID', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'client_secret',
                'label' => __( 'Client Secret', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/514-kato',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_agentsinsight'] = array(
        'name' => __( 'Kato XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-kato-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/514-kato',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
        'limit_properties' => false,
    );

    $formats['xml_kyero'] = array(
        'name' => __( 'Kyero XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-kyero-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/349-kyero',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_loop_v2'] = array(
        'name' => __( 'Loop API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-loop-json-import.php',
        'id_field' => 'listingId',
        'fields' => array(
            array(
                'id' => 'client_id',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            )
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/350-loop',
        'test_button' => true,
    );

    $formats['xml_mri'] = array(
        'name' => __( 'MRI XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-mri-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://v4.salesandlettings.online/pls/{client}/aspasia_search.xml',
            ),
            array(
                'id' => 'password',
                'label' => __( 'Password', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/351-mri-software-aspasia-qube',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_clarks_computers'] = array(
        'name' => __( 'Muven XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-muven-xml-import.php',
        'id_field' => 'agent_ref ',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/353-muven',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_pixxi'] = array(
        'name' => __( 'Pixxi CRM API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-pixxi-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://dataapi.pixxicrm.ae/v1/properties/<CompanyEndPoint>/',
            ),
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
                'placeholder' => '',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/pixxi/',
        'warnings' => array(),
        'test_button' => true,
    );

    $formats['xml_property_finder_uae'] = array(
        'name' => __( 'Property Finder / PF Expert / myCRM XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-property-finder-uae-xml-import.php',
        'id_field' => 'reference_number',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/property-finder/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_propertyadd'] = array(
        'name' => __( 'PropertyADD XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-propertyadd-xml-import.php',
        'id_field' => 'Property_ID',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://pa.{your-site}.net',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/property-finder/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_reapit_foundations'] = array(
        'name' => __( 'Reapit Foundations API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-reapit-foundations-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'customer_id',
                'label' => __( 'Customer ID', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'e.g. ABC',
            ),
            array(
                'id' => 'sales_status',
                'label' => __( 'Sale Status(es) To Import', 'propertyhive' ),
                'type' => 'multiselect',
                'options' => array(
                    'forSale' => 'forSale',
                    'forSaleUnavailable' => 'forSaleUnavailable',
                    'underOffer' => 'underOffer',
                    'underOfferUnavailable' => 'underOfferUnavailable',
                    'reserved' => 'reserved',
                    'exchanged' => 'exchanged',
                    'completed' => 'completed',
                    'soldExternally' => 'soldExternally',
                ),
                'default' => array( 'forSale', 'underOffer', 'exchanged' ),
                'tooltip' => 'Ctrl/Cmd + Click to select multiple',
            ),
            array(
                'id' => 'lettings_status',
                'label' => __( 'Letting Status(es) To Import', 'propertyhive' ),
                'type' => 'multiselect',
                'options' => array(
                    'toLet' => 'toLet',
                    'toLetUnavailable' => 'toLetUnavailable',
                    'underOffer' => 'underOffer',
                    'underOfferUnavailable' => 'underOfferUnavailable',
                    'arrangingTenancyUnavailable' => 'arrangingTenancyUnavailable',
                    'arrangingTenancy' => 'arrangingTenancy',
                    'tenancyCurrentUnavailable' => 'tenancyCurrentUnavailable',
                    'tenancyCurrent' => 'tenancyCurrent',
                    'tenancyFinished' => 'tenancyFinished',
                    'tenancyCancelled' => 'tenancyCancelled',
                    'sold' => 'sold',
                    'letByOtherAgent' => 'letByOtherAgent',
                    'letPrivately' => 'letPrivately',
                    'provisional' => 'provisional',
                ),
                'default' => array( 'toLet', 'underOffer', 'arrangingTenancy', 'tenancyCurrent' ),
                'tooltip' => 'Ctrl/Cmd + Click to select multiple',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
                'tooltip' => 'Recommended to reduce monthly API usage cost',
            ),
            array(
                'id' => 'agree',
                'label' => __( 'I Agree To Terms', 'propertyhive' ),
                'type' => 'checkbox',
                'tooltip' => 'I understand that I may receive monthly invoices from Reapit for my API usage.',
            ),
            array(
                'label' => 'agreed',
                'type' => 'html',
                'html' => '',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/355-reapit-foundations',
        'infos' => array_filter( 
            array( 
                __( 'Please note that, depending on your Reapit subscription, they may charge per API call and will send a monthly invoice for the usage. You will need to contact Reapit to clarify if you will be charged and to notify them where you wish invoices to be sent if this differs from the email address they have on file.', 'propertyhive' ), 
                __( 'You\'ll need to install the <a href="https://marketplace.reapit.cloud/apps/7974a1ed-7c36-4aa2-baef-d7f6da931a26" target="_blank">Property Hive App on the Reapit Marketplace</a> before being able to import properties.', 'propertyhive' ) 
            ) 
        ),
        'test_button' => true,
        'limit_properties' => false,
    );

    $formats['reaxml_local'] = array(
        'name' => __( 'REAXML - Local Directory', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-reaxml-import.php',
        'id_field' => 'uniqueID',
        'fields' => array(
            array(
                'id' => 'local_directory',
                'label' => __( 'Local Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => $uploads_dir,
                'tooltip' => __( 'The full server path to where the REAXML files will be received into', 'propertyhive' ),
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/356-reaxml',
    );

    $formats['api_rentman'] = array(
        'name' => __( 'Rentman API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-rentman-api-import.php',
        'id_field' => 'propref',
        'fields' => array(
            array(
                'id' => 'token',
                'label' => __( 'API Token', 'propertyhive' ),
                'type' => 'password',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/357-rentman',
        'test_button' => true,
    );

    $formats['xml_rentman'] = array(
        'name' => __( 'Rentman XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-rentman-xml-import.php',
        'id_field' => 'Refnumber',
        'fields' => array(
            array(
                'id' => 'local_directory',
                'label' => __( 'Local Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => $uploads_dir,
                'tooltip' => __( 'The full server path to where the XML files will be received into', 'propertyhive' ),
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/357-rentman',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => false,
    );
    
    $formats['api_resales_online'] = array(
        'name' => __( 'ReSales Online API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-resales-online-api-import.php',
        'id_field' => 'Reference',
        'fields' => array(
            array(
                'id' => 'identifier',
                'label' => __( 'Identifier', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'xxxxxxx',
            ),
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
                'placeholder' => 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
            ),
            array(
                'id' => 'filter_ids',
                'label' => __( 'Filter ID(s)', 'propertyhive' ),
                'type' => 'text',
                'tooltip' => __( 'A comma-delimited list of API filter IDs', 'propertyhive' ),
                'placeholder' => 'e.g. 12345, 23456',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/resales-online/',
        'test_button' => true,
    );

    $formats['xml_resales_online'] = array(
        'name' => __( 'ReSales Online XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-resales-online-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            )
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/resales-online/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_rex'] = array(
        'name' => __( 'Rex API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-rex-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'API Base URL', 'propertyhive' ),
                'type' => 'text',
                'default' => 'https://api.uk.rexsoftware.com',
            ),
            array(
                'id' => 'username',
                'label' => __( 'Username', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'password',
                'label' => __( 'Password', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'account_id',
                'label' => __( 'Account ID', 'propertyhive' ),
                'type' => 'text',
                'tooltip' => 'If you have multiple accounts, enter your account ID, otherwise leave it blank'
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/rex/',
        'test_button' => true,
    );

    $rtdf_fields = array();

    if ( 
        (
            isset($_GET['action']) && $_GET['action'] == 'editimport' && 
            isset($_GET['import_id']) && is_numeric($_GET['import_id'])
        ) 
        ||
        !empty($import_id)
    )
    {
        if ( empty($import_id) ) { $import_id = (int)$_GET['import_id']; }

        $rtdf_fields[] = array(
            'id' => 'send_endpoint',
            'type' => 'text',
            'label' => __( 'Send Property Endpoint URL', 'propertyhive' ),
            'default' => '/sendpropertydetails/' . $import_id,
            'before' => trim(home_url(), "/"),
            'css' => 'max-width:300px'
        );

        $rtdf_fields[] = array(
            'id' => 'remove_endpoint',
            'type' => 'text',
            'label' => __( 'Remove Property Endpoint URL', 'propertyhive' ),
            'default' => '/removeproperty/' . $import_id,
            'before' => trim(home_url(), "/"),
            'css' => 'max-width:300px'
        );

        $rtdf_fields[] = array(
            'id' => 'get_endpoint',
            'type' => 'text',
            'label' => __( 'Branch Properties Endpoint URL', 'propertyhive' ),
            'default' => '/getbranchpropertylist/' . $import_id,
            'before' => trim(home_url(), "/"),
            'css' => 'max-width:300px'
        );

        $rtdf_fields[] = array(
            'id' => 'emails_endpoint',
            'type' => 'text',
            'label' => __( 'Branch Emails Endpoint URL', 'propertyhive' ),
            'default' => '/getbranchemails/' . $import_id,
            'before' => trim(home_url(), "/"),
            'css' => 'max-width:300px'
        );
    }
    else
    {
        $rtdf_fields[] = array(
            'type' => 'html',
            'label' => __( 'Endpoint URLs', 'propertyhive' ),
            'html' => '<em>Endpoint URLs will appear here after being saved.</em>',
        );
    }

    $rtdf_fields[] = array(
        'id' => 'response_format',
        'label' => __( 'Response Format', 'propertyhive' ),
        'type' => 'select',
        'options' => array(
            '' => 'Same as request (recommended)',
            'json' => 'JSON',
            'xml' => 'XML',
        ),
        'tooltip' => 'Ideally all real-time requests and responses should in JSON, however some CRMs will send requests as XML. Use this setting to determine the format of the responses sent back to them',
    );

    $formats['rtdf'] = array(
        'name' => __( 'Rightmove Real-Time Data Feed (RTDF)', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-rtdf-import.php',
        'id_field' => 'agent_ref',
        'fields' => $rtdf_fields,
        'help_url' => 'https://docs.wp-property-hive.com/article/365-rtdf-real-time-data-feed',
        'test_button' => false,
    );

    $formats['json_sme_professional'] = array(
        'name' => __( 'SME Professional JSON', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-sme-professional-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'company_id',
                'label' => __( 'Company ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/358-sme-professional',
        'test_button' => true,
    );

    $formats['xml_sme_professional'] = array(
        'name' => __( 'SME Professional XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-sme-professional-xml-import.php',
        'id_field' => 'agent_ref',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/358-sme-professional',
        'test_button' => true,
    );

    $formats['json_street'] = array(
        'name' => __( 'Street', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-street-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'api_base_url',
                'label' => __( 'API Base URL', 'propertyhive' ),
                'type' => 'text',
                'default' => 'https://street.co.uk',
            ),
            array(
                'id' => 'sales_status',
                'label' => __( 'Sales Status(es) To Import', 'propertyhive' ),
                'type' => 'multiselect',
                'options' => array( 
                    'for_sale' => 'for_sale', 
                    'for_sale_and_to_let' => 'for_sale_and_to_let', 
                    'under_offer' => 'under_offer', 
                    'sold_stc' => 'sold_stc', 
                    'exchanged' => 'exchanged', 
                    'completed'  => 'completed' 
                ),
                'default' => apply_filters( 'propertyhive_street_sales_statuses', array( 'for_sale', 'for_sale_and_to_let', 'under_offer', 'sold_stc' ) ),
                'tooltip' => 'Ctrl/Cmd + Click to select multiple',
            ),
            array(
                'id' => 'lettings_status',
                'label' => __( 'Lettings Status(es) To Import', 'propertyhive' ),
                'type' => 'multiselect',
                'options' => array( 
                    'to_let' => 'to_let', 
                    'for_sale_and_to_let' => 'for_sale_and_to_let', 
                    'let_agreed' => 'let_agreed', 
                    'let' => 'let' 
                ),
                'default' => apply_filters( 'propertyhive_street_lettings_statuses', array( 'to_let', 'for_sale_and_to_let', 'let_agreed' ) ),
                'tooltip' => 'Ctrl/Cmd + Click to select multiple',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
            array(
                'id' => 'use_viewing_url',
                'label' => __( 'Replace enquiry button with Street link', 'propertyhive' ),
                'type' => 'checkbox',
                'tooltip' => 'Requires \'viewing_booking_url\' field to be present in data.',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/359-street',
        'test_button' => true,
    );

    $formats['thesaurus'] = array(
        'name' => __( 'MRI (Thesaurus Format)', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-thesaurus-import.php',
        'id_field' => 0,
        'fields' => array(
            array(
                'id' => 'ftp_host',
                'label' => __( 'FTP Host', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'ftp_user',
                'label' => __( 'FTP Username', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'ftp_pass',
                'label' => __( 'FTP Password', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'ftp_dir',
                'label' => __( 'Data File FTP Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => '/data',
            ),
            array(
                'id' => 'image_ftp_dir',
                'label' => __( 'Image FTP Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => '/images_l,/images_b',
            ),
            array(
                'id' => 'brochure_ftp_dir',
                'label' => __( 'Brochure FTP Directory', 'propertyhive' ),
                'type' => 'text',
                'default' => '/pdf',
            ),
            array(
                'id' => 'ftp_passive',
                'label' => __( 'Use FTP Passive Mode', 'propertyhive' ),
                'type' => 'checkbox',
            ),
            array(
                'id' => 'filename',
                'label' => __( 'Data File Name', 'propertyhive' ),
                'type' => 'text',
                'default' => 'data.file',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/352-mri-software-thesaurus',
        'test_button' => true,
    );

    $formats['xml_thinkspain'] = array(
        'name' => __( 'thinkSPAIN', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-thinkspain-xml-import.php',
        'id_field' => 'unique_id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/thinkspain/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['json_utili'] = array(
        'name' => __( 'Utili API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-utili-json-import.php',
        'id_field' => 'utili_ref',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'account_name',
                'label' => __( 'Account Name', 'propertyhive' ),
                'type' => 'text',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/360-utili',
        'test_button' => true,
    );

    $formats['json_vaultea'] = array(
        'name' => __( 'VaultEA / VaultRE API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-vaultea-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'server',
                'label' => __( 'CRM', 'propertyhive' ),
                'type' => 'select',
                'options' => array(
                    'ea' => 'VaultEA',
                    'ap' => 'VaultRE'
                )
            ),
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'token',
                'label' => __( 'Token', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'portal',
                'label' => __( 'Portal ID', 'propertyhive' ),
                'type' => 'text',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/361-vaultea',
        'infos' => array_filter( 
            array( 
                __( 'VaultEA / VaultRE have strict rate limiting on API calls. If you experience issues with imports timing out it\'s possible you\'ll need to increase the timeout limit on your server', 'propertyhive' ), 
            ) 
        ),
        'test_button' => true,
    );

    $formats['json_veco'] = array(
        'name' => __( 'Veco API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-veco-json-import.php',
        'id_field' => 'WebID',
        'fields' => array(
            array(
                'id' => 'access_token',
                'label' => __( 'Access Token', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/363-veco',
        'test_button' => true,
    );

    $formats['json_veco_plus'] = array(
        'name' => __( 'Veco Plus API', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-veco-plus-json-import.php',
        'id_field' => 'agent_ref',
        'fields' => array(
            array(
                'id' => 'api_key',
                'label' => __( 'API Key', 'propertyhive' ),
                'type' => 'password',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        'help_url' => 'https://docs.wp-property-hive.com/article/363-veco',
        'test_button' => true,
    );

    $formats['xml_webedge'] = array(
        'name' => __( 'WebEDGE/Propertynews.com XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-webedge-xml-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'shared_secret',
                'label' => __( 'Shared Secret', 'propertyhive' ),
                'type' => 'text',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/thinkspain/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => false,
    );

    $formats['json_wordpress_property_hive'] = array(
        'name' => __( 'WordPress Site Running Property Hive', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-wordpress-property-hive-json-import.php',
        'id_field' => 'id',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
                'tooltip' => __( 'A link to the other WordPress website running Property Hive', 'propertyhive' ),
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://docs.wp-property-hive.com/article/335-acquaint',
        //'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml_xml2u'] = array(
        'name' => __( 'XML2U', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-xml2u-xml-import.php',
        'id_field' => 'propertyid ',
        'fields' => array(
            array(
                'id' => 'url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'id' => 'only_updated',
                'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                'type' => 'checkbox',
            ),
        ),
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/xml2u/',
        'warnings' => array_filter( array( $simplexml_warning ) ),
        'test_button' => true,
    );

    $formats['xml'] = array(
        'name' => __( 'XML', 'propertyhive' ),
        'file' => dirname( PH_PROPERTYIMPORT_PLUGIN_FILE ) . '/includes/import-formats/class-ph-xml-import.php',
        'id_field' => '',
        'fields' => array(
            array(
                'id' => 'xml_url',
                'label' => __( 'XML URL', 'propertyhive' ),
                'type' => 'text',
                'placeholder' => 'https://',
            ),
            array(
                'type' => 'html',
                'label' => '',
                'html' => '<a href="" class="button phpi-fetch-xml-nodes">' . __( 'Fetch XML', 'propertyhive' ) . '</a>'
            ),
            array(
                'id' => 'property_node',
                'label' => __( 'Repeating Property Node', 'propertyhive' ),
                'type' => 'select',
                'tooltip' => __( 'Please select which node in the XML determines a property record. If no options show, click the \'Fetch XML\' button above', 'propertyhive' ),
            ),
            array(
                'id' => 'property_id_node',
                'label' => __( 'Unique Property ID Node', 'propertyhive' ),
                'type' => 'select',
                'tooltip' => __( 'Please select which node in the XML determines the property\'s unique ID. We\'ll use this to determine if a property has been inserted previously or not. If no options show, click the \'Fetch XML\' button above', 'propertyhive' ),
            ),
            array(
                'id' => 'property_node_options',
                'type' => 'hidden',
            ),
        ),
        'test_button' => false,
        //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/xml/'
    );

        /*
        'bridge' => array(
            'name' => __( 'Bridge (MLS API Provider)', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'access_token',
                    'label' => __( 'Access Token', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'dataset_id',
                    'label' => __( 'Datafeed ID', 'propertyhive' ),
                    'type' => 'select',
                    'options' => array(
                        'test' => 'Test Data',
                    )
                ),
                array(
                    'id' => 'statuses',
                    'label' => __( 'Status(es) To Import', 'propertyhive' ),
                    'type' => 'multiselect',
                    'options' => array(
                        'Active' => 'Active',
                        'Active Under Contract' => 'Active Under Contract',
                        'Canceled' => 'Canceled',
                        'Closed' => 'Closed',
                        'Coming Soon' => 'Coming Soon',
                        'Delete' => 'Delete',
                        'Expired' => 'Expired',
                        'Hold' => 'Hold',
                        'Incomplete' => 'Incomplete',
                        'Pending' => 'Pending',
                        'Withdrawn' => 'Withdrawn'
                    ),
                    'default' => array( 'Active', 'Coming Soon' ),
                    'tooltip' => 'One or more must be selected. Ctrl/Cmd + Click to select multiple',
                ),
                array(
                    'type' => 'html',
                    'label' => '',
                    'html' => '<div class="notice notice-info inline"><p>Please note: <strong>This format is in BETA</strong>. Please contact us at info@wp-property-hive.com if you experience issues.</p></div>'
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'lettings_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'property_type' => array(
                    'Apartment' => 'Apartment',
                    'Single Family Residence' => 'Single Family Residence',
                )
            ),
            //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/mls-grid/'
        ),
        'mls_grid' => array(
            'name' => __( 'MLS Grid', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'access_token',
                    'label' => __( 'Access Token', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'originating_system_name',
                    'label' => __( 'Originating System Name', 'propertyhive' ),
                    'type' => 'select',
                    'options' => array(
                        'actris' => 'ACTRIS MLS',
                        'carolina' => 'Canopy MLS',
                        'flinthills' => 'Flint Hills MLS',
                        'scranton' => 'Greater Scranton Board of REALTORS®',
                        'nira' => 'Northwest Indiana REALTORS® Association (formerly GNIAR)',
                        'hmls' => 'Heartland Multiple Listing Service, Inc.',
                        'highland' => 'Highland Lakes Association of REALTORS®',
                        'lbor' => 'Lawrence Board of REALTORS®',
                        'lascruces' => 'Southern New Mexico MLS',
                        'maris' => 'MARIS MLS',
                        'mfrmls' => 'My Florida Regional MLS DBA Stellar MLS',
                        'mibor' => 'MIBOR REALTOR® Association',
                        'mlsok' => 'MLSOK',
                        'mred' => 'MRED Midwest Real Estate Data',
                        'neirbr' => 'Northeast Iowa Regional Board of REALTORS®',
                        'nocoast' => 'NoCoast MLS',
                        'northstar' => 'NorthstarMLS®',
                        'nwmls' => 'Northwest MLS',
                        'onekey2' => 'OneKey® MLS (NEW)',
                        'paar' => 'Prescott Area Association of REALTORS®',
                        'pikewayne' => 'Pike/Wayne Association of REALTORS®',
                        'prairie' => 'Mid-Kansas MLS (Prairie Land REALTORS®)',
                        'ranw' => 'REALTOR® Association Northeast Wisconsin',
                        'realtrac' => 'RT RealTracs',
                        'recolorado' => 'REcolorado',
                        'rmlsa' => 'RMLS Alliance',
                        'rrar' => 'Reelfoot Regional Association of REALTORS®',
                        'sarmls' => 'Spokane Association of REALTORS®',
                        'sckansas' => 'South Central Kansas MLS',
                        'somo' => 'Southern Missouri Regional MLS (SOMO)',
                        'spartanburg' => 'Spartanburg Board of REALTORS®',
                        'sunflower' => 'Sunflower MLS',
                    )
                ),
                array(
                    'id' => 'statuses',
                    'label' => __( 'Status(es) To Import', 'propertyhive' ),
                    'type' => 'multiselect',
                    'options' => array(
                        'Active' => 'Active',
                        'Active Under Contract' => 'Active Under Contract',
                        'Canceled' => 'Canceled',
                        'Closed' => 'Closed',
                        'Coming Soon' => 'Coming Soon',
                        'Delete' => 'Delete',
                        'Expired' => 'Expired',
                        'Hold' => 'Hold',
                        'Incomplete' => 'Incomplete',
                        'Pending' => 'Pending',
                        'Withdrawn' => 'Withdrawn'
                    ),
                    'default' => array( 'Active', 'Coming Soon' ),
                    'tooltip' => 'One or more must be selected. Ctrl/Cmd + Click to select multiple',
                ),
                array(
                    'id' => 'only_updated',
                    'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                    'type' => 'checkbox',
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'lettings_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'property_type' => array(
                    'Apartment' => 'Apartment',
                    'Single Family Residence' => 'Single Family Residence',
                )
            ),
            //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/mls-grid/'
        ),
        'openimmo_local' => array(
            'name' => __( 'OpenImmo - Local Directory', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'local_directory',
                    'label' => __( 'Local Directory', 'propertyhive' ),
                    'type' => 'text',
                    'default' => $uploads_dir,
                    'tooltip' => __( 'The full server path to where the OpenImmo files will be received into', 'propertyhive' ),
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'OFFEN' => 'Available',
                    'RESERVIERT ' => 'Reserved',
                    'VERKAUFT' => 'Sold',
                ),
                'lettings_availability' => array(
                    'OFFEN' => 'Available',
                    'RESERVIERT ' => 'Reserved',
                ),
                'property_type' => array(
                    "REIHENHAUS" => "Terraced house",
                    "REIHENEND" => "End-terrace house",
                    "REIHENMITTEL" => "Mid-terrace house",
                    "REIHENECK" => "Corner-terrace house",
                    "DOPPELHAUSHAELFTE" => "Semi-detached house",
                    "EINFAMILIENHAUS" => "Single-family house",
                    "STADTHAUS" => "Townhouse",
                    "BUNGALOW" => "Bungalow",
                    "VILLA" => "Villa",
                    "RESTHOF" => "Manor house",
                    "BAUERNHAUS" => "Farmhouse",
                    "LANDHAUS" => "Country house",
                    "SCHLOSS" => "Castle",
                    "ZWEIFAMILIENHAUS" => "Two-family house",
                    "MEHRFAMILIENHAUS" => "Multi-family house",
                    "FERIENHAUS" => "Holiday house",
                    "BERGHUETTE" => "Mountain hut",
                    "CHALET" => "Chalet",
                    "STRANDHAUS" => "Beach house",
                    "LAUBE-DATSCHE-GARTENHAUS" => "Garden house",
                    "APARTMENTHAUS" => "Apartment building",
                    "BURG" => "Fortress",
                    "HERRENHAUS" => "Mansion",
                    "FINCA" => "Estate",
                    "RUSTICO" => "Rustic house",
                    "FERTIGHAUS" => "Prefabricated house",
                    "KEINE_ANGABE" => "No information"
                )
            ),
            //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/openimmo/',
        ),
        'propstack' => array(
            'name' => __( 'Propstack', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'api_key',
                    'label' => __( 'API Key', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'statuses',
                    'label' => __( 'Status(es) To Import', 'propertyhive' ),
                    'type' => 'multiselect',
                    'options' => array( 
                        'Vorbereitung' => 'Vorbereitung',
                        'Vermarktung' => 'Vermarktung',
                        'Akquise' => 'Akquise',
                        'Abgeschlossen' => 'Abgeschlossen',
                    ),
                    'default' => array( 'Vermarktung' ),
                    'tooltip' => 'One or more must be selected. Ctrl/Cmd + Click to select multiple',
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Vorbereitung' => 'Vorbereitung',
                    'Vermarktung' => 'Vermarktung',
                    'Akquise' => 'Akquise',
                    'Abgeschlossen' => 'Abgeschlossen',
                ),
                'lettings_availability' => array(
                    'Abgeschlossen' => 'Abgeschlossen',
                ),
                'property_type' => array(
                    'APARTMENT' => 'APARTMENT',
                    'HOUSE' => 'HOUSE',
                )
            ),
            //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/propstack/',
        ),
        'reaxml_local' => array(
            'name' => __( 'REAXML - Local Directory', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'local_directory',
                    'label' => __( 'Local Directory', 'propertyhive' ),
                    'type' => 'text',
                    'default' => $uploads_dir,
                    'tooltip' => __( 'The full server path to where the XML files will be received into', 'propertyhive' ),
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Current' => 'Current',
                    'Under Offer' => 'Under Offer',
                ),
                'lettings_availability' => array(
                    'Current' => 'Current',
                    'Deposit Taken' => 'Deposit Taken',
                ),
                'property_type' => array(
                    'House' => 'House',
                    'Unit' => 'Unit',
                    'Townhouse' => 'Townhouse',
                    'Villa' => 'Villa',
                    'Apartment' => 'Apartment',
                    'Flat' => 'Flat',
                    'Studio' => 'Studio',
                    'Warehouse' => 'Warehouse',
                    'DuplexSemi-detached' => 'DuplexSemi-detached',
                    'Alpine' => 'Alpine',
                    'AcreageSemi-rural' => 'AcreageSemi-rural',
                    'BlockOfUnits' => 'BlockOfUnits',
                    'Terrace' => 'Terrace',
                    'Retirement' => 'Retirement',
                    'ServicedApartment' => 'ServicedApartment',
                    'Other' => 'Other',
                )
            ),
            'help_url' => 'https://docs.wp-property-hive.com/article/356-reaxml',
            'warnings' => array_filter( array( $simplexml_warning ) ),
        ),
        'reaxml_remote' => array(
            'name' => __( 'REAXML - URL', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'xml_url',
                    'label' => __( 'XML URL', 'propertyhive' ),
                    'type' => 'text',
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Current' => 'Current',
                    'Under Offer' => 'Under Offer',
                ),
                'lettings_availability' => array(
                    'Current' => 'Current',
                    'Deposit Taken' => 'Deposit Taken',
                ),
                'property_type' => array(
                    'House' => 'House',
                    'Unit' => 'Unit',
                    'Townhouse' => 'Townhouse',
                    'Villa' => 'Villa',
                    'Apartment' => 'Apartment',
                    'Flat' => 'Flat',
                    'Studio' => 'Studio',
                    'Warehouse' => 'Warehouse',
                    'DuplexSemi-detached' => 'DuplexSemi-detached',
                    'Alpine' => 'Alpine',
                    'AcreageSemi-rural' => 'AcreageSemi-rural',
                    'BlockOfUnits' => 'BlockOfUnits',
                    'Terrace' => 'Terrace',
                    'Retirement' => 'Retirement',
                    'ServicedApartment' => 'ServicedApartment',
                    'Other' => 'Other',
                )
            ),
            'help_url' => 'https://docs.wp-property-hive.com/article/356-reaxml',
            'warnings' => array_filter( array( $simplexml_warning ) ),
        ),
        'remax' => array(
            'name' => __( 'RE/MAX', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'api_key',
                    'label' => __( 'API Key', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'access_key',
                    'label' => __( 'Access Key', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'secret_key',
                    'label' => __( 'Secret Key', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'office_id',
                    'label' => __( 'Office ID(s)', 'propertyhive' ),
                    'type' => 'text',
                    'tooltip' => __( 'Enter a comma-delimited list of office IDs if only wanting to import specific office listings. Enter only Agent ID(s) or Office ID(s). Not both.', 'propertyhive' ),
                ),
                array(
                    'id' => 'agent_id',
                    'label' => __( 'Agent ID(s)', 'propertyhive' ),
                    'type' => 'text',
                    'tooltip' => __( 'Enter a comma-delimited list of agent IDs if only wanting to import specific agents listings. Enter only Agent ID(s) or Office ID(s). Not both.', 'propertyhive' ),
                ),
                array(
                    'id' => 'only_updated',
                    'label' => __( 'Only Import Updated Properties', 'propertyhive' ),
                    'type' => 'checkbox',
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'For Sale' => 'For Sale',
                    'New' => 'New',
                    'Price Reduced' => 'Price Reduced',
                    'Offer Made' => 'Offer Made',
                    'Sold' => 'Sold',
                ),
                'lettings_availability' => array(
                    'To Rent' => 'To Rent',
                ),
                'property_type' => array(
                    'Apartment / Flat' => 'Apartment / Flat',
                    'House' => 'House',
                    'Townhouse' => 'Townhouse',
                    'Vacant Land / Plot' => 'Vacant Land / Plot',
                    'Farm' => 'Farm',
                    'Commercial Property: Office' => 'Commercial Property: Office',
                    'Commercial Property: Retail' => 'Commercial Property: Retail',
                    'Commercial Property: Accommodation' => 'Commercial Property: Accommodation',
                    'Commercial Property: Flatlet' => 'Commercial Property: Flatlet',
                    'Industrial Property: Factory' => 'Industrial Property: Factory',
                    'Industrial Property: Warehouse' => 'Industrial Property: Warehouse',
                    'Industrial Property: Storage' => 'Industrial Property: Storage',
                )
            ),
            'help_url' => 'https://docs.wp-property-hive.com/article/490-re-max'
        ),
        'spark' => array(
            'name' => __( 'Spark (MLS API Provider)', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'access_token',
                    'label' => __( 'Access Token', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'statuses',
                    'label' => __( 'Status(es) To Import', 'propertyhive' ),
                    'type' => 'multiselect',
                    'options' => array(
                        'Active' => 'Active',
                        'Active Under Contract' => 'Active Under Contract',
                        'Canceled' => 'Canceled',
                        'Closed' => 'Closed',
                        'Coming Soon' => 'Coming Soon',
                        'Delete' => 'Delete',
                        'Expired' => 'Expired',
                        'Hold' => 'Hold',
                        'Incomplete' => 'Incomplete',
                        'Pending' => 'Pending',
                        'Withdrawn' => 'Withdrawn'
                    ),
                    'default' => array( 'Active', 'Coming Soon' ),
                    'tooltip' => 'One or more must be selected. Ctrl/Cmd + Click to select multiple',
                ),
                array(
                    'type' => 'html',
                    'label' => '',
                    'html' => '<div class="notice notice-info inline"><p>Please note: <strong>This format is in BETA</strong>. Please contact us at info@wp-property-hive.com if you experience issues.</p></div>'
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'lettings_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'property_type' => array(
                    'Apartment' => 'Apartment',
                    'Single Family Residence' => 'Single Family Residence',
                )
            ),
            //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/spark/'
        ),
        'trestle' => array(
            'name' => __( 'Trestle (MLS API Provider)', 'propertyhive' ),
            'fields' => array(
                array(
                    'id' => 'client_id',
                    'label' => __( 'Client ID', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'client_secret',
                    'label' => __( 'Client Secret', 'propertyhive' ),
                    'type' => 'password',
                ),
                array(
                    'id' => 'statuses',
                    'label' => __( 'Status(es) To Import', 'propertyhive' ),
                    'type' => 'multiselect',
                    'options' => array(
                        'Active' => 'Active',
                        'Active Under Contract' => 'Active Under Contract',
                        'Canceled' => 'Canceled',
                        'Closed' => 'Closed',
                        'Coming Soon' => 'Coming Soon',
                        'Delete' => 'Delete',
                        'Expired' => 'Expired',
                        'Hold' => 'Hold',
                        'Incomplete' => 'Incomplete',
                        'Pending' => 'Pending',
                        'Withdrawn' => 'Withdrawn'
                    ),
                    'default' => array( 'Active', 'Coming Soon' ),
                    'tooltip' => 'One or more must be selected. Ctrl/Cmd + Click to select multiple',
                ),
                array(
                    'type' => 'html',
                    'label' => '',
                    'html' => '<div class="notice notice-info inline"><p>Please note: <strong>This format is in BETA</strong>. Please contact us at info@wp-property-hive.com if you experience issues.</p></div>'
                ),
            ),
            'taxonomy_values' => array(
                'sales_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'lettings_availability' => array(
                    'Active' => 'Active',
                    'Coming Soon' => 'Coming Soon',
                ),
                'property_type' => array(
                    'Apartment' => 'Apartment',
                    'Single Family Residence' => 'Single Family Residence',
                )
            ),
            //'help_url' => 'https://wp-property-hive.com/documentation/managing-imports/formats/trestle/'
        ),
        */

    $formats = apply_filters( 'propertyhive_property_import_formats', $formats );

    uasort($formats, 'propertyhive_property_import_compare_by_name');

    return $formats;
}

function propertyhive_property_import_compare_by_name($a, $b) 
{
    return strcasecmp($a['name'], $b['name']);
}

function propertyhive_property_import_get_import_format( $key, $import_id = '' )
{
    $formats = propertyhive_property_import_get_import_formats( $import_id );
    
    return isset($formats[$key]) ? $formats[$key] : false;
}

function propertyhive_property_import_get_format_from_import_id( $import_id )
{
    $formats = propertyhive_property_import_get_import_formats();

    $options = get_option( 'propertyhive_property_import' , array() );
    $imports = ( is_array($options) && !empty($options) ) ? $options : array();

    if ( isset($imports[$import_id]) )
    {
        $format = $imports[$import_id]['format'];

        return propertyhive_property_import_get_import_format( $format );
    }
    
    return false;
}