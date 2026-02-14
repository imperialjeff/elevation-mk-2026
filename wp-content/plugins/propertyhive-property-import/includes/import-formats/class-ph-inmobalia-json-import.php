<?php
/**
 * Class for managing the import process of a Inmobalia JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Inmobalia_JSON_Import extends PH_Property_Import_Process {

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

		$search_url = 'https://api.inmobalia.com/rest/property?page=1&size=999999';

		$response = wp_remote_get( 
			$search_url, 
			array(
				'timeout' => 120,
				'headers' => array(
					'x-token' => $import_settings['api_key']
				)
		    )
		);

		if ( !is_wp_error( $response ) && is_array( $response ) ) 
		{
			$contents = $response['body'];

			$json = json_decode( $contents, TRUE );

			if ($json !== FALSE && isset($json['content']) && !empty($json['content']))
			{
	            $properties_imported = 0;

	            $properties_array = $json['content'];

				foreach ($properties_array as $property)
				{
					$this->properties[] = $property;
				}
	        }
	        else
	        {
	        	// Failed to parse JSON
	        	$this->log_error( 'Failed to parse JSON file: ' . print_r($contents, TRUE) );
	        	return false;
	        }
	    }
        else
        {
        	$this->log_error( 'Failed to obtain JSON. Dump of response as follows: ' . print_r($response, TRUE) );
        	return false;
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
		
        do_action( "propertyhive_pre_import_properties_inmobalia_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_inmobalia_json_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_inmobalia_json", $property, $this->import_id, $this->instance_id );
            
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

			$display_address = array();
	        if ( isset($property['locationSubarea']['name']) && trim($property['locationSubarea']['name']) != '' )
	        {
	        	$display_address[] = trim($property['locationSubarea']['name']);
	        }
	        if ( isset($property['locationArea']['name']) && trim($property['locationArea']['name']) != '' )
	        {
	        	$display_address[] = trim($property['locationArea']['name']);
	        }
	        elseif ( isset($property['locationCity']['name']) && trim($property['locationCity']['name']) != '' )
	        {
	        	$display_address[] = trim($property['locationCity']['name']);
	        }
	        elseif ( isset($property['locationProvince']['name']) && trim($property['locationProvince']['name']) != '' )
	        {
	        	$display_address[] = trim($property['locationProvince']['name']);
	        }
	        $display_address = implode(", ", $display_address);

	        $summary_description = '';
	        $full_description = '';
	        if ( isset($property['propertyDescriptions']['shortDescription']) )
	        {
	        	$summary_description = isset($property['propertyDescriptions']['shortDescription']) ? $property['propertyDescriptions']['shortDescription'] : '';
	        	$full_description = isset($property['propertyDescriptions']['description']) ? $property['propertyDescriptions']['description'] : '';
	        }
	        elseif ( isset($property['propertyDescriptions'][0]['shortDescription']) )
	        {
	        	foreach ( $property['propertyDescriptions'] as $property_description )
	        	{
	        		if ( isset($property_description['language']) && $property_description['language'] == 'en' )
	        		{
	        			$summary_description = isset($property_description['shortDescription']) ? $property_description['shortDescription'] : '';
	        			$full_description = isset($property_description['description']) ? $property_description['description'] : '';
	        			break;
	        		}
	        	}
	        }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $summary_description );

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

				$previous_update_date = get_post_meta( $post_id, '_inmobalia_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['dateModified']) ||
						(
							isset($property['dateModified']) &&
							empty($property['dateModified'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['dateModified']) &&
							$property['dateModified'] != '' &&
							$previous_update_date != '' &&
							strtotime($property['dateModified']) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', ( ( isset($property['reference']) && !empty($property['reference']) ) ? $property['reference'] : $property['id'] ) );
					update_post_meta( $post_id, '_address_name_number', '' );
					update_post_meta( $post_id, '_address_street', ( ( isset($property['locationSubarea']['name']) ) ? $property['locationSubarea']['name'] : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property['locationArea']['name']) ) ? $property['locationArea']['name'] : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['locationCity']['name']) ) ? $property['locationCity']['name'] : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property['locationProvince']['name']) ) ? $property['locationProvince']['name'] : '' ) );
					update_post_meta( $post_id, '_address_postcode', '' );

					$country = get_option( 'propertyhive_default_country', 'ES' );
					$currency = 'EUR';
					if ( isset($property['locationCountry']['codeIso']) && $property['locationCountry']['codeIso'] != '' )
					{
						$country = $property['locationCountry']['codeIso'];
					}
					update_post_meta( $post_id, '_address_country', $country );
					
	            	// Let's just look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_inmobalia_json_address_fields_to_check', array('locationSubarea', 'locationArea', 'locationCity', 'locationProvince'), $this->import_id );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property[$address_field]['name']) && trim($property[$address_field]['name']) != '' ) 
						{
							$term = term_exists( trim($property[$address_field]['name']), 'location');
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
					if ( isset($property['latitude']) && isset($property['longitude']) && $property['latitude'] != '' && $property['longitude'] != '' && $property['latitude'] != '0' && $property['longitude'] != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property['latitude']) ) ? $property['latitude'] : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property['longitude']) ) ? $property['longitude'] : '' ) );
					}
					else
					{
						// No lat/lng passed. Let's go and get it if none entered
						$lat = get_post_meta( $post_id, '_latitude', TRUE);
						$lng = get_post_meta( $post_id, '_longitude', TRUE);

						if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
						{
							// No lat lng. Let's get it
							$address_to_geocode = array();
							if ( isset($property['locationSubarea']['name']) && trim($property['locationSubarea']['name']) != '' ) { $address_to_geocode[] = $property['locationSubarea']['name']; }
							if ( isset($property['locationArea']['name']) && trim($property['locationArea']['name']) != '' ) { $address_to_geocode[] = $property['locationArea']['name']; }
							if ( isset($property['locationCity']['name']) && trim($property['locationCity']['name']) != '' ) { $address_to_geocode[] = $property['locationCity']['name']; }
							if ( isset($property['locationProvince']['name']) && trim($property['locationProvince']['name']) != '' ) { $address_to_geocode[] = $property['locationProvince']['name']; }

							$return = $this->do_geocoding_lookup( $post_id, $property['id'], $address_to_geocode, $address_to_geocode, $country );
						}
					}

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
						
					$office_id = $this->primary_office_id;
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = ( $property['isRent'] == true ? 'residential-lettings' : 'residential-sales' );

					update_post_meta( $post_id, '_department', $department );

					// Is the property portal add on activated
					if (class_exists('PH_Property_Portal'))
	        		{
						// Use the branch code to map this property to the correct agent and branch
						$explode_agent_branch = array();
						if (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->agency_id . '|' . $this->import_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->agency_id . '|' . $this->import_id] != ''
						)
						{
							// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->agency_id . '|' . $this->import_id]);
						}
						elseif (
							isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->agency_id]) &&
							$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->agency_id] != ''
						)
						{
							// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
							$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->agency_id]);
						}

						if ( !empty($explode_agent_branch) )
						{
							update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
							update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );

							$this->branch_ids_processed[] = $explode_agent_branch[1];
						}
						else
						{
							update_post_meta( $post_id, '_agent_id', '' );
							update_post_meta( $post_id, '_branch_id', '' );
						}
	        		}

					update_post_meta( $post_id, '_bedrooms', ( ( isset($property['bedrooms']) ) ? $property['bedrooms'] : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property['bathrooms']) ) ? $property['bathrooms'] : '' ) );
					update_post_meta( $post_id, '_reception_rooms', '' );

					$prefix = '';
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property['propertyType']['name']) )
					{
						if ( !empty($mapping) && isset($mapping[$property['propertyType']['name']]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[$property['propertyType']['name']], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . $property['propertyType']['name'] . ') that is not mapped', $post_id, $property['id'] );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property['propertyType']['name'], $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					// Residential Sales Details
					if ( $department == 'residential-sales' )
					{
						// Clean price
						$price = '';
						if ( isset($property['salePrice']) && !empty($property['salePrice']) )
	            		{
	                		$price = preg_replace("/[^0-9.]/", '', $property['salePrice']);
	                	}

						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_poa', isset($property['salePriceShow']) && $property['salePriceShow'] !== true ? 'yes' : '' );

						update_post_meta( $post_id, '_currency', $currency );
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = preg_replace("/[^0-9.]/", '', (string)$property->rental_price_eur);
						if ( isset($property['rentalPrice']) && !empty($property['rentalPrice']) )
	            		{
	                		$price = preg_replace("/[^0-9.]/", '', $property['rentalPrice']);
	                	}

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = $property['rentalPricePeriod']['name'];
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						
						update_post_meta( $post_id, '_poa', isset($property['rentalPriceShow']) && $property['rentalPriceShow'] !== true ? 'yes' : '' );

						update_post_meta( $post_id, '_currency', $currency );

						update_post_meta( $post_id, '_deposit', '' );
	            		update_post_meta( $post_id, '_available_date', '' );
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
						update_post_meta( $post_id, '_featured', ( ( isset($property['isFeatured']) && $property['isFeatured'] === true ) ? 'yes' : '' ) );
					}
				
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					$availability = 'Available';
					if ( $property['isUnderOffer'] === true )
					{
						$availability = 'UnderOffer';
					}
					if ( $property['isSold'] === true )
					{
						$availability = 'Sold';
					}
					if ( $property['isRented'] === true )
					{
						$availability = 'Rented';
					}

					if ( !empty($mapping) && isset($mapping[$availability]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$availability], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

		            // Features
					$features = array();
					if ( isset($property['features']) && is_array($property['features']) && !empty($property['features']) )
					{
						foreach ( $property['features'] as $feature )
						{
							$features[] = trim($feature['name']);
						}
					}

					update_post_meta( $post_id, '_features', count( $features ) );
	        		
	        		$i = 0;
			        foreach ( $features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }	     

			        // Rooms / Descriptions
			        // For now put the whole description in one room / description
					update_post_meta( $post_id, '_rooms', '1' );
					update_post_meta( $post_id, '_room_name_0', '' );
		            update_post_meta( $post_id, '_room_dimensions_0', '' );
		            update_post_meta( $post_id, '_room_description_0', $full_description );

		            // Media - Images
				    $media = array();
				    if ( isset($property['propertyImages']) && !empty($property['propertyImages']) )
					{
						foreach ( $property['propertyImages'] as $image )
						{
							$media[] = array(
								'url' => $image['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, false );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['dateModified']) ) { update_post_meta( $post_id, '_inmobalia_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['dateModified'])) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_inmobalia_json", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_inmobalia_json", $this->import_id );

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
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'For Rent' => 'For Rent',
                'Rented' => 'Rented',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Bungalow' => 'Bungalow',
                'Country House' => 'Country House',
                'Duplex Penthouse' => 'Duplex Penthouse',
                'Ground Floor Apartment' => 'Ground Floor Apartment',
                'House' => 'House',
                'Penthouse' => 'Penthouse',
                'Plot' => 'Plot',
                'Town House' => 'Town House',
                'Villa' => 'Villa',
            )
        );
	}
}

}