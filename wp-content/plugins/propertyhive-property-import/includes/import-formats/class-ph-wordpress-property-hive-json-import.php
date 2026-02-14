<?php
/**
 * Class for managing the import process of a WordPress Property Hive JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_WordPress_Property_Hive_JSON_Import extends PH_Property_Import_Process {

    public function __construct( $instance_id = '', $import_id = '')
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
			if ( isset( $_POST['url'] ) ) 
			{
			    $import_settings['url'] = sanitize_url( wp_unslash( $_POST['url'] ) );
			}
		}

		// get active departments so can loop through and only get properties from those departments
		$departments = array();
		if ( get_option( 'propertyhive_active_departments_sales', '' ) == 'yes' )
		{
			$departments[] = 'residential-sales';
		}
		if ( get_option( 'propertyhive_active_departments_lettings', '' ) == 'yes' )
		{
			$departments[] = 'residential-lettings';
		}
		if ( get_option( 'propertyhive_active_departments_commercial', '' ) == 'yes' )
		{
			$departments[] = 'commercial';
		}

		foreach ( $departments as $department )
		{
	        $this->log("Parsing " . $department . " properties");

	        $per_page = apply_filters( 'propertyhive_property_import_wp_property_hive_per_page', 100 );
			$current_page = 1;
			$more_properties = true;

			while ( $more_properties )
			{
				$this->log("Obtaining properties on page " . $current_page);

				$url = ( isset($import_settings['url']) && !empty($import_settings['url']) ) ? rtrim($import_settings['url'], '/') : '';
				$url .= '/wp-json/wp/v2/property?department=' . $department . '&per_page=' . $per_page . '&page=' . $current_page;

				$url = apply_filters( 'propertyhive_property_import_wp_property_hive_properties_url', $url, $this->import_id );

				$response = wp_remote_request(
					$url,
					array(
						'method' => 'GET',
						'timeout' => 120,
					)
				);

				if ( is_wp_error( $response ) )
				{
					$this->log_error( 'Response: ' . $response->get_error_message() );

					return false;
				}

				if ( wp_remote_retrieve_response_code( $response ) != 200 && wp_remote_retrieve_response_code( $response ) != 400 )
				{
					$this->log_error( 'Received an invalid response: ' . print_r($response, true) );

					return false;
				}

				if ( wp_remote_retrieve_response_code( $response ) == 400 )
				{
					// Hit the end of pages
					$more_properties = false;
				}
				else
				{
					$json = json_decode( $response['body'], TRUE );

					if ( $json !== FALSE )
					{
						if ( !empty($json) )
						{
							$this->log("Parsing properties on page " . $current_page);

							$limit = $this->get_property_limit();

							foreach ( $json as $property )
							{
								if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
				                {
				                    return true;
				                }

								$this->properties[] = $property;
							}

							++$current_page;
						}
						else
						{
							$this->log("No " . $department . " properties found");
							$more_properties = false;
						}
					}
					else
					{
						// Failed to parse JSON
						$this->log_error( 'Failed to parse JSON.' );

						return false;
					}
				}
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

        do_action( "propertyhive_pre_import_properties_wordpress_property_hive_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_wordpress_property_hive_json_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_wordpress_property_hive_json", $property, $this->import_id, $this->instance_id );
			
        	if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['id'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['id'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['id'], false );

            $this->log( 'Importing property ' . $property_row .' with reference ' . $property['id'], 0, $property['id'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = $property['title']['rendered'];

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $property['excerpt']['rendered'], '', date("Y-m-d H:i:s", strtotime($property['date'])) );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['id'] );

                update_post_meta( $post_id, $imported_ref_key, $property['id'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                $previous_update_date = get_post_meta( $post_id, '_property_hive_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['modified_gmt']) ||
						(
							isset($property['modified_gmt']) &&
							empty($property['modified_gmt'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['modified_gmt']) &&
							$property['modified_gmt'] != '' &&
							$previous_update_date != '' &&
							strtotime($property['modified_gmt']) > strtotime($previous_update_date)
						)
					)
					{
						$skip_property = false;
					}
				}
				else
				{
					$skip_property = false;
				}

				if ( !$skip_property )
				{
	                // Address
	                update_post_meta( $post_id, '_reference_number', $property['reference_number'] );

	                update_post_meta( $post_id, '_address_name_number', '' ); // Not supplied as obtained from public REST API
	                update_post_meta( $post_id, '_address_street', ( ( isset($property['address_street']) ) ? $property['address_street'] : '' ) );
	                update_post_meta( $post_id, '_address_two', ( ( isset($property['address_two']) ) ? $property['address_two'] : '' ) );
	                update_post_meta( $post_id, '_address_three', ( ( isset($property['address_three']) ) ? $property['address_three'] : '' ) );
	                update_post_meta( $post_id, '_address_four', trim ( ( ( isset($property['address_four']) ) ? $property['address_four'] : '' ) . ' ' . ( ( isset($property['town']) ) ? $property['town'] : '' ) ) );
	                update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address_postcode']) ) ? $property['address_postcode'] : '' ) );

	                $country = get_option( 'propertyhive_default_country', 'GB' );
	                $country = ( ( isset($property['address_postcode']) ) ? $property['address_postcode'] : $country );
	                update_post_meta( $post_id, '_address_country', $country );

	                // Coordinates
	                update_post_meta( $post_id, '_latitude', ( ( isset($property['latitude']) ) ? $property['latitude'] : '' ) );
	                update_post_meta( $post_id, '_longitude', ( ( isset($property['longitude']) ) ? $property['longitude'] : '' ) );

	                // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
	                $address_fields_to_check = apply_filters( 'propertyhive_wordpress_property_hive_json_address_fields_to_check', array('address_two', 'address_three', 'address_four') );
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

	                // Owner
	                add_post_meta( $post_id, '_owner_contact_id', '', true );

	                // Record Details
	                $negotiator_id = get_current_user_id();
	                update_post_meta( $post_id, '_negotiator_id', $negotiator_id );

	                $office_id = $this->primary_office_id;

	                if ( isset( $property['office']['name'] ) )
	                {
	                    if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
	                    {
	                        foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
	                        {
	                            $explode_branch_code = explode(",", $branch_code);
	                            if ( in_array($property['office']['name'], $explode_branch_code) )
	                            {
	                                $office_id = $ph_office_id;
	                                break;
	                            }
	                        }
	                    }
	                }
	                update_post_meta( $post_id, '_office_id', $office_id );

	                $department = isset($property['department']) ? $property['department'] : '';
	                update_post_meta( $post_id, '_department', $department );

	                update_post_meta( $post_id, '_bedrooms', isset($property['bedrooms']) ? $property['bedrooms'] : '' );
	                update_post_meta( $post_id, '_bathrooms', isset($property['bathrooms']) ? $property['bathrooms'] : '' );
	                update_post_meta( $post_id, '_reception_rooms', isset($property['reception_rooms']) ? $property['reception_rooms'] : '' );
	                update_post_meta( $post_id, '_council_tax_band', ( isset($property['council_tax_band']) && !empty($property['council_tax_band']) ) ? $property['council_tax_band'] : '' );

	                $prefix = '';
	                if ( $department == 'commercial' )
	                {
	                	$prefix = 'commercial_';
	                }
	                $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

	                $property_type_ids = array();
	                if ( isset($property['property_type']) && !empty($property['property_type']) )
	                {
	                	$explode_property_types = explode(",", $property['property_type']);
	                	$explode_property_types = array_map('trim', $explode_property_types); // remove white spaces from around hours
		                $explode_property_types = array_filter($explode_property_types); // remove empty array elements

		                foreach ( $explode_property_types as $property_type )
		                {
	                        if ( !empty($mapping) && isset($mapping[$property_type]) )
	                        {
	                            $property_type_ids[] = (int)$mapping[$property_type];
	                        }
	                        else
	                        {
	                            $this->log( 'Property received with a type (' . $property_type . ') that is not mapped', $post_id, $property['id'] );

	                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property_type );
	                        }
	                    }
	                }
	                if ( !empty($property_type_ids) )
	                {
	                    wp_set_object_terms( $post_id, $property_type_ids, $prefix . 'property_type' );
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
	                }

	                // Residential Sales Details
	                if ( $department == 'residential-sales' )
	                {
	                    update_post_meta( $post_id, '_price', isset($property['price']) ? $property['price'] : '' );
	                    update_post_meta( $post_id, '_price_actual', isset($property['price_actual']) ? $property['price_actual'] : '' );

	                    update_post_meta( $post_id, '_currency', isset($property['currency']) ? $property['currency'] : 'GBP' );

	                    update_post_meta( $post_id, '_poa', '' );

	                    // Price Qualifier
	                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

	                    if ( !empty($mapping) && isset($property['price_qualifier']) && isset($mapping[$property['price_qualifier']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['price_qualifier']], 'price_qualifier' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
	                    }

	                    // Tenure
	                    $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

	                    if ( !empty($mapping) && isset($property['tenure']) && isset($mapping[$property['tenure']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['tenure']], 'tenure' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'tenure' );
	                    }

	                    // Sale By
	                    $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

	                    if ( !empty($mapping) && isset($property['sale_by']) && isset($mapping[$property['sale_by']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['sale_by']], 'sale_by' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'sale_by' );
	                    }
	                }
	                elseif ( $department == 'residential-lettings' )
	                {
	                    update_post_meta( $post_id, '_rent', isset($property['price']) ? $property['price'] : '' );
	                    update_post_meta( $post_id, '_rent_frequency', isset($property['rent_frequency']) ? $property['rent_frequency'] : '' );
	                    update_post_meta( $post_id, '_price_actual', isset($property['price_actual']) ? $property['price_actual'] : '' );

	                    update_post_meta( $post_id, '_currency', isset($property['currency']) ? $property['currency'] : 'GBP' );

	                    update_post_meta( $post_id, '_deposit', isset($property['deposit']) ? $property['deposit'] : '' );
	                    update_post_meta( $post_id, '_available_date', isset($property['available_date']) ? $property['available_date'] : '' );
	                }
	                elseif ( $department == 'commercial' )
	                {
	                	update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

	            		if ( isset($property['for_sale']) && $property['for_sale'] == 'yes' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', isset($property['currency']) ? $property['currency'] : 'GBP' );

		                    update_post_meta( $post_id, '_price_from', isset($property['price_from']) ? $property['price_from'] : '' );
		                    update_post_meta( $post_id, '_price_to', isset($property['price_to']) ? $property['price_to'] : '' );
		                    update_post_meta( $post_id, '_price_units', isset($property['price_units']) ? $property['price_units'] : '' );

		                    update_post_meta( $post_id, '_price_poa', '' );

		                    // Tenure
				            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

							if ( !empty($mapping) && isset($property['tenure']) && isset($mapping[$property['tenure']]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[$property['tenure']], 'commercial_tenure' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
				            }

				            // Sale By
				            $mapping = isset($import_settings['mappings']['sale_by']) ? $import_settings['mappings']['sale_by'] : array();

							if ( !empty($mapping) && isset($property['sale_by']) && isset($mapping[$property['sale_by']]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[$property['sale_by']], 'sale_by' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'sale_by' );
				            }
		                }

		                if ( isset($property['to_rent']) && $property['to_rent'] == 'yes' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', isset($property['currency']) ? $property['currency'] : 'GBP' );

		                    update_post_meta( $post_id, '_rent_from', isset($property['rent_from']) ? $property['rent_from'] : '' );
		                    update_post_meta( $post_id, '_rent_to', isset($property['rent_to']) ? $property['rent_to'] : '' );
		                    update_post_meta( $post_id, '_rent_units', isset($property['rent_units']) ? $property['rent_units'] : '' );

		                    update_post_meta( $post_id, '_rent_poa', '' );
		                }

		                // Store price in common currency (GBP) used for ordering
			            $ph_countries = new PH_Countries();
			            $ph_countries->update_property_price_actual( $post_id );

			            // Price Qualifier
	                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

	                    if ( !empty($mapping) && isset($property['price_qualifier']) && isset($mapping[$property['price_qualifier']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['price_qualifier']], 'price_qualifier' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
	                    }

			            update_post_meta( $post_id, '_floor_area_from', isset($property['floor_area_from']) ? $property['floor_area_from'] : '' );
			            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( isset($property['floor_area_from']) ? $property['floor_area_from'] : '', isset($property['floor_area_units']) ? $property['floor_area_units'] : 'sqft' ) );

			            update_post_meta( $post_id, '_floor_area_to', isset($property['floor_area_to']) ? $property['floor_area_to'] : '' );
			            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( isset($property['floor_area_to']) ? $property['floor_area_to'] : '', isset($property['floor_area_units']) ? $property['floor_area_units'] : 'sqft' ) );

			            update_post_meta( $post_id, '_floor_area_units', isset($property['floor_area_units']) ? $property['floor_area_units'] : '' );

			            update_post_meta( $post_id, '_site_area_from', isset($property['site_area_from']) ? $property['site_area_from'] : '' );
			            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( isset($property['site_area_from']) ? $property['site_area_from'] : '', isset($property['site_area_units']) ? $property['site_area_units'] : 'sqft' ) );

			            update_post_meta( $post_id, '_site_area_to', isset($property['site_area_to']) ? $property['site_area_to'] : '' );
			            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( isset($property['site_area_to']) ? $property['site_area_to'] : '', isset($property['site_area_units']) ? $property['site_area_units'] : 'sqft' ) );

			            update_post_meta( $post_id, '_site_area_units', isset($property['site_area_units']) ? $property['site_area_units'] : '' );
	                }

	                // Marketing
	                $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
	                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
	                if ( $featured_by_default === true )
	                {
	                    update_post_meta( $post_id, '_featured', isset($property['featured']) ? $property['featured'] : '' );
	                }
	                
	                // Availability
	                $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
	                    $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] :
	                    array();

	                if ( !empty($mapping) )
	                {
                		if (
                			isset($property['availability']) &&
                        	isset($mapping[$property['availability']])
                		)
                		{
                			wp_set_object_terms( $post_id, (int)$mapping[$property['availability']], 'availability' );
                		}
                		else
                		{
                			wp_delete_object_term_relationships( $post_id, 'availability' );
                		}
	                }

	                // Parking
	                $mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();
	                
	                $parking_ids = array();
	                if ( isset($property['parking']) && !empty($property['parking']) )
	                {
	                	$explode_parkings = explode(",", $property['parking']);
	                	$explode_parkings = array_map('trim', $explode_parkings); // remove white spaces from around hours
		                $explode_parkings = array_filter($explode_parkings); // remove empty array elements

		                foreach ( $explode_parkings as $parking )
		                {
		                    if ( !empty($mapping) && isset($mapping[$parking]) )
		                    {
		                        $parking_ids[] = (int)$mapping[$parking];
		                    }
		                    else
		                    {
		                        $this->log( 'Property received with a parking (' . $parking . ') that is not mapped', $post_id, $property['id'] );

		                        $import_settings = $this->add_missing_mapping( $mapping, 'parking', $parking );
		                    }
		                }
	                }
	                if ( !empty($parking_ids) )
	                {
	                    wp_set_object_terms( $post_id, $parking_ids, 'parking' );
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'parking' );
	                }

	                // Outside Space
	                $mapping = isset($import_settings['mappings']['outside_space']) ? $import_settings['mappings']['outside_space'] : array();

	                $outside_space_ids = array();
	                if ( isset($property['outside_space']) && !empty($property['outside_space']) )
	                {
	                	$explode_outside_spaces = explode(",", $property['outside_space']);
	                	$explode_outside_spaces = array_map('trim', $explode_outside_spaces); // remove white spaces from around hours
		                $explode_outside_spaces = array_filter($explode_outside_spaces); // remove empty array elements

		                foreach ( $explode_outside_spaces as $outside_space )
		                {
		                    if ( !empty($mapping) && isset($mapping[$outside_space]) )
		                    {
		                        wp_set_object_terms( $post_id, (int)$mapping[$outside_space], 'outside_space' );
		                    }
		                    else
		                    {
		                        $this->log( 'Property received with an outside_space (' . $outside_space . ') that is not mapped', $post_id, $property['id'] );

		                        $import_settings = $this->add_missing_mapping( $mapping, 'outside_space', $outside_space );
		                    }
		                }
	                }
	                if ( !empty($outside_space_ids) )
	                {
	                    wp_set_object_terms( $post_id, $outside_space_ids, 'outside_space' );
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'outside_space' );
	                }

	                // Features
	                $features = array();
	                if ( isset($property['features']) && !empty($property['features']) )
	                {
	                    foreach ( $property['features'] as $feature )
	                    {
	                        $features[] = trim($feature);
	                    }
	                }

	                update_post_meta( $post_id, '_features', count( $features ) );
	                
	                $i = 0;
	                foreach ( $features as $feature )
	                {
	                    update_post_meta( $post_id, '_feature_' . $i, $feature );
	                    ++$i;
	                }

	                // Rooms
	                $rooms_count = 0;
	                if ( isset( $property['description'] ) && !empty( $property['description'] ) )
	                {
	                    update_post_meta( $post_id, '_room_name_0', '' );
	                    update_post_meta( $post_id, '_room_dimensions_0', '' );
	                    update_post_meta( $post_id, '_room_description_0', $property['description'] );

	                    $rooms_count++;
	                }

	                update_post_meta( $post_id, '_rooms', $rooms_count );

	                // Media - Images
				    $media = array();
				    if (isset($property['images']) && !empty($property['images']))
	                {
	                    foreach ($property['images'] as $photo)
	                    {
							$media[] = array(
								'url' => $photo['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if (isset($property['floorplans']) && !empty($property['floorplans']))
	                {
	                    foreach ($property['floorplans'] as $floorplan)
	                    {
							$media[] = array(
								'url' => $floorplan['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if (isset($property['brochures']) && !empty($property['brochures']))
	                {
	                    foreach ($property['brochures'] as $brochure)
	                    {
							$media[] = array(
								'url' => $brochure['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if (isset($property['epcs']) && !empty($property['epcs']))
	                {
	                    foreach ($property['epcs'] as $epc)
	                    {
							$media[] = array(
								'url' => $epc['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'epc', $media, false );

					// Virtual Tours
					$urls = array();

					if (isset($property['virtual_tours']) && !empty($property['virtual_tours']))
	                {
	                    foreach ($property['virtual_tours'] as $i => $virtual_tour)
	                    {
							update_post_meta($post_id, '_virtual_tour_' . $i, $virtual_tour['url']);
							update_post_meta($post_id, '_virtual_tour_' . $i . '_label', $virtual_tour['label']);
						}

						update_post_meta($post_id, '_virtual_tours', count($property['virtual_tours']) );

						$this->log( 'Imported ' . count($property['virtual_tours']) . ' virtual tours', $post_id, $property['id'] );
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['modified_gmt']) ) { update_post_meta( $post_id, '_property_hive_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['modified_gmt'])) ); }
				
                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_wordpress_property_hive_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;
        }

        do_action( "propertyhive_post_import_properties_wordpress_property_hive_json" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $import_refs[] = $property['id'];
        }

        $this->do_remove_old_properties( $import_refs );

		unset($import_refs);
    }

    public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
                'Sold STC' => 'Sold STC',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
                'Let Agreed' => 'Let Agreed',
                'Let' => 'Let',
            ),
            'property_type' => array(
			    // HOUSE
			    'House' => 'House',
			    'Detached House' => 'Detached House',
			    'Semi-Detached House' => 'Semi-Detached House',
			    'Terraced House' => 'Terraced House',
			    'End of Terrace House' => 'End of Terrace House',
			    'Mews' => 'Mews',
			    'Link Detached House' => 'Link Detached House',
			    'Town House' => 'Town House',
			    'Cottage' => 'Cottage',

			    // BUNGALOW
			    'Bungalow' => 'Bungalow',
			    'Detached Bungalow' => 'Detached Bungalow',
			    'Semi-Detached Bungalow' => 'Semi-Detached Bungalow',
			    'Terraced Bungalow' => 'Terraced Bungalow',
			    'End of Terrace Bungalow' => 'End of Terrace Bungalow',

			    // FLATS
			    'Flat / Apartment' => 'Flat / Apartment',
			    'Flat' => 'Flat',
			    'Apartment' => 'Apartment',
			    'Maisonette' => 'Maisonette',
			    'Studio' => 'Studio',
			    'Penthouse' => 'Penthouse',
			    'Duplex' => 'Duplex',
			    'Triplex' => 'Triplex',

			    // OTHER
			    'Other' => 'Other',
			    'Commercial' => 'Commercial',
			    'Land' => 'Land',
			    'Parking' => 'Parking',
			),
			'commercial_property_type' => array(
				'Office' => 'Office',
			    'Industrial' => 'Industrial',
			    'Retail' => 'Retail',
			    'Land' => 'Land',
			    'Health' => 'Health',
			    'Motoring' => 'Motoring',
			    'Leisure' => 'Leisure',
			    'Investment' => 'Investment',
			),
            'price_qualifier' => array(
			    'Guide Price' => 'Guide Price',
			    'Fixed Price' => 'Fixed Price',
			    'Offers Over' => 'Offers Over',
			    'OIRO' => 'OIRO',
			),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
                'Share of Freehold' => 'Share of Freehold',
                'Commonhold' => 'Commonhold',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'sale_by' => array(
                'Tender' => 'Tender',
                'Private Treaty' => 'Private Treaty',
                'Auction' => 'Auction',
            ),
            'outside_space' => array(
                'Balcony' => 'Balcony',
                'South Facing Garden' => 'South Facing Garden',
            ),
            'parking' => array(
                'On Road Parking' => 'On Road Parking',
			    'Off Road Parking' => 'Off Road Parking',
			    'Driveway' => 'Driveway',
			    'Single Garage' => 'Single Garage',
			    'Double Garage' => 'Double Garage',
			    'Triple Garage' => 'Triple Garage',
			    'Carport' => 'Carport',
            ),
        );
	}
}

}