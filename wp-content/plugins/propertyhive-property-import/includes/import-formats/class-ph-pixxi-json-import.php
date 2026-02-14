<?php
/**
 * Class for managing the import process of a Pixxi CRM JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Pixxi_JSON_Import extends PH_Property_Import_Process {

	/**
	 * @var string
	 */
	private $target_file;

	public function __construct( $instance_id = '', $import_id = '' )
	{
		parent::__construct();
		
		$this->instance_id = $instance_id;
		$this->import_id = $import_id;

		if ( $this->instance_id != '' && isset($_GET['custom_property_import_cron']) )
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

		$limit = $this->get_property_limit();

		$this->log("Parsing properties");

		$page = 1;
		$more_properties = true;

		while ( $more_properties === true )
		{
			$body = '{}';
			if ( isset($import_settings['api_key']) && !empty($import_settings['api_key']) )
			{
				// if there's an API key they must be using new API which has pagination
				$body = '{"page":'.$page.',"size":100}';
			}
			else
			{
				$more_properties = false;
			}

			$data = array( 
				'body' => $body, 
				'headers' => array(
			        'Content-Type' => 'application/json',
			    ),
				'timeout' => 360 
			);

			if ( isset($import_settings['api_key']) && !empty($import_settings['api_key']) )
			{
				$data['headers']['X-PIXXI-TOKEN'] = $import_settings['api_key'];
				$this->log("Obtaining properties on page " . $page);
			}
			else
			{
				$more_properties = false;
			}

			$response = wp_remote_post( $import_settings['url'], $data );

			if ( !is_wp_error($response) && is_array( $response ) )
			{
				$contents = $response['body'];

				$json = json_decode( $contents, TRUE );

				if ($json !== FALSE )
				{
					if ( is_array($json) && isset($json['data']['list']) )
					{
						if ( !empty($json['data']['list']) )
						{
							$this->log("Found " . count($json['data']['list']) . " properties ready for parsing");

							foreach ($json['data']['list'] as $property)
							{
								if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
				                {
				                    return true;
				                }

								$this->properties[] = $property;
							}

							++$page;
						}
						else
						{
							$more_properties = false;
						}
					}
					else
					{
						$more_properties = false;
					}
				}
				else
				{
					// Failed to parse JSON
					$this->log_error( 'Failed to parse JSON file: ' . print_r($json, TRUE) );
					return false;
				}
			}
			else
			{
				$this->log_error( 'Failed to obtain JSON from ' . $import_settings['url'] . ': ' . print_r($response, TRUE) );
				return false;
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

        do_action( "propertyhive_pre_import_properties_pixxi_crm", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_pixxi_crm_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_pixxi_crm", $property, $this->import_id, $this->instance_id );
            
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

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['id'], 0, $property['id'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $property['title'], $property['description'] );

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

				$previous_update_date = get_post_meta( $post_id, '_pixxi_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['updateTime']) ||
						(
							isset($property['updateTime']) &&
							empty($property['updateTime'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['updateTime']) &&
							$property['updateTime'] != '' &&
							$previous_update_date != '' &&
							strtotime($property['updateTime']) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', ( ( isset($property['id']) ) ? $property['id'] : '' ) );
					update_post_meta( $post_id, '_address_name_number', '' );
					update_post_meta( $post_id, '_address_street', '' );
					update_post_meta( $post_id, '_address_two', '' );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['region']) ) ? $property['region'] : '' ) );
					update_post_meta( $post_id, '_address_four', '' );
					update_post_meta( $post_id, '_address_postcode', '' );

					$country = get_option( 'propertyhive_default_country', 'AE' );
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_pixxi_crm_address_fields_to_check', array('region') );
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
					if ( isset($property[strtolower($property['listingType']) . 'Param']['position']) && !empty($property[strtolower($property['listingType']) . 'Param']['position']) )
					{
						$explode_position = explode(",", $property[strtolower($property['listingType']) . 'Param']['position']);
						if ( count($explode_position) == 2 )
						{
							update_post_meta( $post_id, '_latitude', $explode_position[0] );
							update_post_meta( $post_id, '_longitude', $explode_position[1] );
						}
					}

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
					$office_id = $this->primary_office_id;
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$prefix = '';
					$department = isset($property['listingType']) && $property['listingType'] == 'RENT' ? 'residential-lettings' : 'residential-sales';
					$default_mappings = $this->get_default_mapping_values();
					$commercial_property_types = $default_mappings['commercial_property_type'];
					if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
					{
						if ( isset($property['propertyType']) && !empty($property['propertyType']) && is_array($property['propertyType']) )
						{
							foreach ( $property['propertyType'] as $property_type )
							{
								if ( isset($property['propertyType']) && in_array($property_type, $commercial_property_types) )
								{
									$department = 'commercial';
									$prefix = 'commercial_';
								}
							}
						}
					}
					update_post_meta( $post_id, '_department', $department );
					
					update_post_meta( $post_id, '_bedrooms', ( isset($property['bedRooms']) ? $property['bedRooms'] : ( isset($property[strtolower($property['listingType']) . 'Param']['bedroomMax']) ? $property[strtolower($property['listingType']) . 'Param']['bedroomMax'] : '' ) ) );
					update_post_meta( $post_id, '_bathrooms', ( isset($property[strtolower($property['listingType']) . 'Param']['bathrooms']) ? $property[strtolower($property['listingType']) . 'Param']['bathrooms'] : '' ) );
					update_post_meta( $post_id, '_reception_rooms', '' );

					update_post_meta( $post_id, '_council_tax_band', '' );

					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					$property_type_term_ids = array();
					if ( isset($property['propertyType']) && !empty($property['propertyType']) && is_array($property['propertyType']) )
					{
						foreach ( $property['propertyType'] as $property_type )
						{
							if ( !empty($mapping) && isset($mapping[$property_type]) )
							{
								$property_type_term_ids[] = (int)$mapping[$property_type];
							}
							else
							{
								$this->log( 'Property received with a type (' . $property_type . ') that is not mapped', $post_id, $property['id'] );

								$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $property_type, $post_id );
							}
						}
					}

					if ( !empty($property_type_term_ids) )
					{
						wp_set_object_terms( $post_id, $property_type_term_ids, $prefix . 'property_type' );
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
						if ( isset($property['price']) && !empty($property['price']) )
						{
							$price = round(preg_replace("/[^0-9.]/", '', $property['price']));
						}

						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_price_actual', $price );
						update_post_meta( $post_id, '_poa', '' );
						update_post_meta( $post_id, '_currency', 'AED' );
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = '';
						if ( isset($property['price']) && !empty($property['price']) )
						{
							$price = round(preg_replace("/[^0-9.]/", '', $property['price']));
						}

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						$price_actual = $price;
						switch ($property['rentParam']['priceType'])
						{
							case "YEAR":
							{
								$rent_frequency = 'pa'; $price_actual = $price / 12; break;
							}
							case "WEEK":
							{
								$rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break;
							}
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						update_post_meta( $post_id, '_price_actual', $price_actual );
						update_post_meta( $post_id, '_currency', 'AED' );

						update_post_meta( $post_id, '_poa', '' );

						update_post_meta( $post_id, '_deposit', $property['rentParam']['deposit'] );
						update_post_meta( $post_id, '_available_date', '' );
					}
					elseif ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

						if ( isset($property['listingType']) && $property['listingType'] != 'RENT' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', 'AED' );

		                    $price = round(preg_replace("/[^0-9.]/", '', $property['price']));

		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', '' );
						}
						
						if ( isset($property['listingType']) && $property['listingType'] == 'RENT' )
						{
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', 'AED' );

		                    $price = round(preg_replace("/[^0-9.]/", '', $property['price']));

		                    update_post_meta( $post_id, '_rent_from', $price );
		                    update_post_meta( $post_id, '_rent_to', $price );

		                    $rent_frequency = 'pcm';
		                    switch ($property['rentParam']['priceType'])
							{
								case "YEAR":
								{
									$rent_frequency = 'pa'; break;
								}
								case "WEEK":
								{
									$rent_frequency = 'pw';  break;
								}
							}
		                    update_post_meta( $post_id, '_rent_units', $rent_frequency );

		                    update_post_meta( $post_id, '_rent_poa', '' );
						}

						update_post_meta( $post_id, '_floor_area_from', '' );
						update_post_meta( $post_id, '_floor_area_from_sqft', '' );
						update_post_meta( $post_id, '_floor_area_to', '' );
						update_post_meta( $post_id, '_floor_area_to_sqft', '' );
						update_post_meta( $post_id, '_floor_area_units', 'sqft');
					}

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
					add_post_meta( $post_id, '_featured', '', true );

					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					$availability_set = false;
					if ( $department == 'residential-sales' )
					{
						$term = get_term_by('name', 'For Sale', 'availability');

						if ( $term !== FALSE && !empty($term) )
						{
							$availability_set = true;
							wp_set_object_terms( $post_id, (int)$term->term_id, 'availability' );
						}
						else
						{
							$term = get_term_by('name', 'Available', 'availability');

							if ( $term !== FALSE && !empty($term) )
							{
								$availability_set = true;
								wp_set_object_terms( $post_id, (int)$term->term_id, 'availability' );
							}
						}
					}
					else
					{
						if ( !empty($mapping) && isset($mapping[$property['rentParam']['occupancy']]) )
						{
							$availability_set = true;
							wp_set_object_terms( $post_id, (int)$mapping[$property['rentParam']['occupancy']], 'availability' );
						}
					}

					if ( !$availability_set )
					{
						wp_delete_object_term_relationships( $post_id, 'availability' );
					}
					
					// Media - Images
				    $media = array();
				    if ( isset($property['photos']) && is_array($property['photos']) && !empty($property['photos']) )
					{
						foreach ( $property['photos'] as $image )
						{
							$media[] = array(
								'url' => $image,
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property[strtolower($property['listingType']) . 'Param']['floorPlan']) && is_array($property[strtolower($property['listingType']) . 'Param']['floorPlan']) && !empty($property[strtolower($property['listingType']) . 'Param']['floorPlan']) )
					{
						foreach ( $property[strtolower($property['listingType']) . 'Param']['floorPlan'] as $image )
						{
							if ( isset($image['imgUrl']) && !empty($image['imgUrl']) && is_array($image['imgUrl']) )
							{
								foreach ( $image['imgUrl'] as $floorplan_img_url )
								{
									$media[] = array(
										'url' => $floorplan_img_url,
										'description' => $image['name']
									);
								}
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'floorplan', $media, false );

					// Media - Virtual Tours
					$virtual_tours = array();
					if ( isset($property[strtolower($property['listingType']) . 'Param']['videoLink']) && !empty($property[strtolower($property['listingType']) . 'Param']['videoLink']) )
					{
						$virtual_tours[] = $property[strtolower($property['listingType']) . 'Param']['videoLink'];
					}
					if ( isset($property[strtolower($property['listingType']) . 'Param']['view360']) && !empty($property[strtolower($property['listingType']) . 'Param']['view360']) )
					{
						$virtual_tours[] = $property[strtolower($property['listingType']) . 'Param']['view360'];
					}

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ( $virtual_tours as $i => $virtual_tour )
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property['id'] );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['updateTime']) ) { update_post_meta( $post_id, '_pixxi_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['updateTime'])) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_pixxi_crm", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_pixxi_crm" );

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
            'lettings_availability' => array(
                'RENTED' => 'RENTED',
                'VACANT' => 'VACANT',
                'OWNER_OCCUPIED' => 'OWNER_OCCUPIED',
            ),
            'property_type' => array(
                'APARTMENT' => 'APARTMENT',
                'VILLA' => 'VILLA',
                'TOWNHOUSE' => 'TOWNHOUSE',
                'PENTHOUSE' => 'PENTHOUSE',
                'HOTEL_APARTMENT' => 'HOTEL_APARTMENT',
                'DUPLEX' => 'DUPLEX',
                'RESIDENTIAL_FLOOR' => 'RESIDENTIAL_FLOOR',
                'RESIDENTIAL_PLOT' => 'RESIDENTIAL_PLOT',
                'RESIDENTIAL_BUILDING' => 'RESIDENTIAL_BUILDING',
                'COMPOUND' => 'COMPOUND',
            ),
            'commercial_property_type' => apply_filters( 'propertyhive_property_import_pixxi_commercial_property_types', array(
                'OFFICE' => 'OFFICE',
                'SHOP' => 'SHOP',
                'COMMERCIAL_BUILDING' => 'COMMERCIAL_BUILDING',
                'COMMERCIAL_FLOOR' => 'COMMERCIAL_FLOOR',
                'COMMERCIAL_PLOT' => 'COMMERCIAL_PLOT',
                'LABOR_CAMP' => 'LABOR_CAMP',
                'RETAIL' => 'RETAIL',
                'SHOW_ROOM' => 'SHOW_ROOM',
                'COMMERCIAL_VILLA' => 'COMMERCIAL_VILLA',
                'WAREHOUSE' => 'WAREHOUSE',
                'FARM' => 'FARM',
                'FACTORY' => 'FACTORY',
                'HOTEL' => 'HOTEL',
            ) ),
        );
	}
}

}