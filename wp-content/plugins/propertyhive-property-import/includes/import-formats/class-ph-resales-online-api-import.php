<?php
/**
 * Class for managing the import process of a ReSales Online JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_ReSales_Online_API_Import extends PH_Property_Import_Process {

	public function __construct( $instance_id = '', $import_id = '' )
	{
		parent::__construct();
		
		$this->instance_id = $instance_id;
		$this->import_id = $import_id;

		if ( isset($_GET['custom_property_import_cron']) )
		{
			$current_user = wp_get_current_user();

			$this->log("Executed manually by " . ( ( isset($current_user->display_name) ) ? $current_user->display_name : '' ) );
		}
	}

	public function parse( $test = false )
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

		$this->log("Parsing properties");

		$filter_ids = explode(",", $import_settings['filter_ids']);
		$filter_ids = array_filter(array_map('trim', $filter_ids));

		if ( empty($filter_ids) )
		{
			$this->log_error( 'No API filter IDs provided' );
			return false;
		}

		$limit = $this->get_property_limit();

		foreach ( $filter_ids as $filter_id )
		{
			$per_page = 40;
			$total_pages = false;
			$more_properties = true;
			$current_page = 1;

			$url = 'https://webapi.resales-online.com/V6/SearchProperties?p_apiid=' . $filter_id . '&p1=' . $import_settings['identifier'] . '&p2=' . $import_settings['api_key'] . '&P_PageSize=' . $per_page . '';

			while ( $more_properties )
			{
				$this->log("Parsing properties for filter with ID " . $filter_id . " on page " . $current_page);

				$response = wp_remote_request(
					$url . '&P_PageNo=' . $current_page,
					array(
						'method' => 'GET',
						'timeout' => 360,
						'headers' => array()
					)
				);

				if ( is_wp_error( $response ) )
				{
					$this->log_error( 'Response: ' . $response->get_error_message() );

					return false;
				}

				$json = json_decode( $response['body'], TRUE );

				if ( $json !== FALSE )
				{
					if ( isset($json['QueryInfo']['PropertyCount']) )
					{
						$total_pages = ceil( $json['QueryInfo']['PropertyCount'] / $json['QueryInfo']['PropertiesPerPage'] );

						if ( $current_page >= $total_pages )
						{
							$more_properties = false;
						}
					}
					else
					{
						$this->log_error( 'No pagination element found in response. This should always exist so likely something went wrong. As a result we\'ll play it safe and not continue further.' );
						
						return false;
					}

					if ( isset($json['Property']) )
					{
						foreach ($json['Property'] as $property)
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    return true;
			                }

							$property['department'] = 'residential-sales';
							if ( strpos(strtolower($json['QueryInfo']['SearchType']), 'rental') !== false )
							{
								$property['department'] = 'residential-lettings';
							}
							$this->properties[] = $property;
						}
					}
				}
				else
				{
					// Failed to parse JSON
					$this->log_error( 'Failed to parse JSON: ' . $response['body'] );

					return false;
				}

				++$current_page;
			}
		}

		if ( $test === false )
        {
        	if ( empty($this->properties) && apply_filters( 'propertyhive_property_import_stop_if_no_properties', true, $this->import_id ) === true )
        	{
	        	$this->log_error('No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.');

	        	return false;
	        }
        }

		return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

		do_action( "propertyhive_pre_import_properties_resales_online_api", $this->properties, $this->import_id );
		$this->properties = apply_filters( "propertyhive_resales_online_api_properties_due_import", $this->properties, $this->import_id );

		$limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_resales_online_api", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['Reference'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['Reference'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['Reference'], false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . $property['Reference'], 0, $property['Reference'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = array();
			if ( isset($property['SubLocation']) && !empty($property['SubLocation']) )
			{
				$display_address[] = $property['SubLocation'];
			}
			if ( isset($property['Location']) && !empty($property['Location']) )
			{
				$display_address[] = $property['Location'];
			}
			if ( isset($property['Area']) && !empty($property['Area']) )
			{
				$display_address[] = $property['Area'];
			}
			if ( isset($property['Province']) && !empty($property['Province']) )
			{
				$display_address[] = $property['Province'];
			}
			$display_address = implode(", ", $display_address);

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['Reference'], $property, $display_address, ( ( isset($property['Description']) ) ? $property['Description'] : '' ) );

			if ( $inserted_updated !== false )
			{
				// Inserted property ok. Continue

				if ( $inserted_updated == 'updated' )
				{
					// Get all meta data so we can compare before and after to see what's changed
					$metadata_before = get_metadata('post', $post_id, '', true);

					// Get all taxonomy/term data
					$taxonomy_terms_before = array();
					$taxonomy_names = get_post_taxonomies( $post_id );
					foreach ( $taxonomy_names as $taxonomy_name )
					{
						$taxonomy_terms_before[$taxonomy_name] = wp_get_post_terms( $post_id, $taxonomy_name, array('fields' => 'ids') );
					}
				}

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['Reference'] );

				update_post_meta( $post_id, $imported_ref_key, $property['Reference'] );

				update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

				// Address
				update_post_meta( $post_id, '_reference_number', ( ( isset($property['AgencyRef']) ) ? $property['AgencyRef'] : '' ) );

				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', ( ( isset($property['SubLocation']) ) ? $property['SubLocation'] : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property['Location']) ) ? $property['Location'] : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property['Area']) ) ? $property['Area'] : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property['Province']) ) ? $property['Province'] : '' ) );
				update_post_meta( $post_id, '_address_postcode', '' );

				$country = get_option( 'propertyhive_default_country', 'ES' );
				if ( isset($property['Country']) && $property['Country'] != '' && class_exists('PH_Countries') )
				{
					$ph_countries = new PH_Countries();
					foreach ( $ph_countries->countries as $country_code => $country_details )
					{
						if ( strtolower($property['Country']) == strtolower($country_details['name']) )
						{
							$country = $country_code;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = apply_filters( 'propertyhive_resales_online_api_address_fields_to_check', array('SubLocation', 'Location', 'Area', 'Province') );
				$location_term_ids = array();

				foreach ( $address_fields_to_check as $address_field )
				{
					if ( isset($property[$address_field]) && trim($property[$address_field]) != '' )
					{
						$term = term_exists( trim($property[$address_field]), 'location');
						if ( $term !== 0 && $term !== null && isset($term['term_id']) )
						{
							$location_term_ids[] = (int)$term['term_id'];
						}
					}
				}

				if ( !empty($location_term_ids) )
				{
					wp_set_object_terms( $post_id, $location_term_ids, 'location' );
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, 'location' );
				}

				// Coordinates
				// Get lat long from address if possible
				$lat = get_post_meta( $post_id, '_latitude', TRUE);
				$lng = get_post_meta( $post_id, '_longitude', TRUE);

				if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
				{
					// No lat lng. Let's get it
					$address_to_geocode = array();
					$address_to_geocode_osm = array();
					if ( isset($property['SubLocation']) && trim($property['SubLocation']) != '' ) { $address_to_geocode[] = $property['SubLocation']; }
					if ( isset($property['Location']) && trim($property['Location']) != '' ) { $address_to_geocode[] = $property['Location']; }
					if ( isset($property['Area']) && trim($property['Area']) != '' ) { $address_to_geocode[] = $property['Area']; }
					if ( isset($property['Province']) && trim($property['Province']) != '' ) { $address_to_geocode[] = $property['Province']; }

					$return = $this->do_geocoding_lookup( $post_id, $property['Reference'], $address_to_geocode, $address_to_geocode, $country );
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );

				$office_id = $this->primary_office_id;
				update_post_meta( $post_id, '_office_id', $office_id );

				$department = 'residential-sales';

				update_post_meta( $post_id, '_department', $department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property['Bedrooms']) ) ? (string)$property['Bedrooms'] : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property['Bathrooms']) ) ? (string)$property['Bathrooms'] : '' ) );
				update_post_meta( $post_id, '_reception_rooms', '' );

				// Property Type
				$prefix = '';

				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property['PropertyType']['NameType']) && !empty($property['PropertyType']['NameType']) )
				{
					if ( !empty($mapping) && isset($mapping[$property['PropertyType']['NameType']]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[$property['PropertyType']['NameType']], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . $property['PropertyType']['NameType'] . ') that is not mapped', $post_id, $property['Reference'] );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['PropertyType']['NameType'], $post_id );
					}
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
				}

				// Residential Sales Details
				if ( $department == 'residential-sales' )
				{
					$price = round(preg_replace("/[^0-9.]/", '', $property['Price']));
					update_post_meta( $post_id, '_price', $price );

					update_post_meta( $post_id, '_currency', $property['Currency'] );

					update_post_meta( $post_id, '_poa', '' );

				}

				// Parking
				$mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();

				$parking_term_ids = array();
				if ( !empty($mapping) && isset($property['PropertyFeatures']["Category"]) )
				{
					foreach ( $property['PropertyFeatures']["Category"] as $feature_category )
					{
						if ( $feature_category['Type'] == 'Parking' )
						{
							foreach ( $feature_category['Value'] as $parking_space )
							{
								if ( isset($mapping[$parking_space]) )
								{
									$parking_term_ids[] = (int)$mapping[$parking_space];
								}
							}
						}
					}
				}
				if ( !empty($parking_term_ids) )
				{
					wp_set_object_terms( $post_id, $parking_term_ids, 'parking' );
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, 'parking' );
				}

				// Outside Space
				$mapping = isset($import_settings['mappings']['outside_space']) ? $import_settings['mappings']['outside_space'] : array();
				
				$outside_space_term_ids = array();
				if ( !empty($mapping) && isset($property['PropertyFeatures']["Category"]) )
				{
					foreach ( $property['PropertyFeatures']["Category"] as $feature_category )
					{
						if ( $feature_category['Type'] == 'Garden' )
						{
							foreach ( $feature_category['Value'] as $outside_space )
							{
								if ( isset($mapping[$outside_space]) )
								{
									$outside_space_term_ids[] = (int)$mapping[$outside_space];
								}
							}
						}
					}
				}
				if ( !empty($outside_space_term_ids) )
				{
					wp_set_object_terms( $post_id, $outside_space_term_ids, 'outside_space' );
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, 'outside_space' );
				}

				// Store price in common currency (GBP) used for ordering
	            $ph_countries = new PH_Countries();
	            $ph_countries->update_property_price_actual( $post_id );

				// Marketing
				$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', 'yes' );
                }
                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
				if ( $featured_by_default === true )
				{
					update_post_meta( $post_id, '_featured', '' );
				}

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property['Status']['system']) && isset($mapping[$property['Status']['system']]) )
				{
					wp_set_object_terms( $post_id, (int)$mapping[$property['Status']['system']], 'availability' );
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, 'availability' );
				}

				// Rooms / Descriptions
				// For now put the whole description in one room / description
				$rooms = 0;

				update_post_meta( $post_id, '_rooms', $rooms );

				// Media - Images
			    $media = array();
			    if (isset($property['Pictures']['Picture']) && !empty($property['Pictures']['Picture']))
				{
					foreach ($property['Pictures']['Picture'] as $photo)
					{
						$url = $photo['PictureURL'];

						$explode_url = explode("?", $url);
						$filename = basename( $explode_url[0] );

						$media[] = array(
							'url' => $url,
							'filename' => $filename,
							'description' => ( (isset($photo['PictureCaption'])) ? $photo['PictureCaption'] : '' ),
						);
					}
				}

				$this->import_media( $post_id, $property['Reference'], 'photo', $media, false );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_resales_online_api", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['Reference'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		update_option( 'propertyhive_property_import_property_' . $this->import_id, '', false );
		
		do_action( "propertyhive_post_import_properties_resales_online_api" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['Reference'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}
	
	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Available' => 'Available',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
            ),
            'property_type' => array(
                'Bungalow' => 'Bungalow',
                'Detached Villa' => 'Detached Villa',
                'Duplex' => 'Duplex',
                'Garage' => 'Garage',
                'Middle Floor Apartment' => 'Middle Floor Apartment',
                'Middle Floor Studio' => 'Middle Floor Studio',
                'Parking Space' => 'Parking Space',
                'Semi-Detached House' => 'Semi-Detached House',
                'Top Floor Apartment' => 'Top Floor Apartment',
                'Townhouse' => 'Townhouse',
            ),
            'parking' => array(
                'Covered' => 'Covered',
                'Open' => 'Open',
                'Street' => 'Street',
                'Communal' => 'Communal',
            ),
            'outside_space' => array(
                'Private' => 'Private',
                'Communal' => 'Communal',
            ),
        );
	}
}

}