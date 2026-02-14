<?php
/**
 * Class for managing the import process of an Agency Pilot JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Agency_Pilot_JSON_Import extends PH_Property_Import_Process {

	/**
	 * @var array
	 */
	private $terms;

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
		global $post, $wpdb;

		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

		$this->properties = array();
		$this->branch_ids_processed = array();

		$parameters = array(
			'w' => '103',
			'fr' => 'true',
			'full' => 'true',
			'pw' => $import_settings['password']
		);

		if ( $date_ran_before === false )
		{
			$parameters['a'] = 'true';
		}
		else
		{
			$parameters['upd'] = date("d-M-y", strtotime($date_ran_before));
		}

		$parameters = apply_filters( 'propertyhive_agency_pilot_json_properties_parameters', $parameters );

		$url = 'https://' . $import_settings['url'] . '/services/getPropertyJSON.aspx?' . http_build_query($parameters);

		$contents = '';

		$response = wp_remote_get( $url, array( 'timeout' => 120 ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain JSON. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		$json = json_decode( $contents, TRUE );

		if ($json !== FALSE && isset($json['CRITERIA']) && !empty($json['CRITERIA']))
		{
			$date_ran_before = false;
            if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
            {
                $query = "
                    SELECT
                        id, start_date
                    FROM
                        " .$wpdb->prefix . "ph_propertyimport_instance_v3
                    WHERE
                        start_date <= end_date
                        AND
                        import_id = '" . $this->import_id . "'
                        AND
                        media = 0
                    ORDER BY
                        start_date DESC
                    LIMIT 1
                ";
                $results = $wpdb->get_results( $query );
                if ( $results )
                {
                    foreach ( $results as $result )
                    {
                        $date_ran_before = $result->start_date;
                    }
                }
            }

            if ( $date_ran_before !== FALSE )
            {
                if ( date("I", strtotime($date_ran_before)) == 1 )
                {
                    $date_ran_before = date("Y-m-d H:i:s", strtotime($date_ran_before) - 86400); // - Just get all changed properties in last 24 hours. This avoids any issues with timestamps, BST etc
                }
            }

			$this->log("Parsing properties");

            $properties = $json['CRITERIA'];

			$this->log("Found " . count($properties) . " properties in JSON ready for parsing");

			foreach ($properties as $property)
			{
				$this->properties[] = $property;

				if ( $date_ran_before !== false )
				{
					// We're only importing updated properties.
					// Check if unavailable more recent than available
					// In which case we need to remove this property
					$last_available = isset($property['Last_Available_Date']) ? str_replace(array("/Date(", ")/"), "", $property['Last_Available_Date']) : '';
					$last_unavailable = isset($property['Last_Unavailable_Date']) ? str_replace(array("/Date(", ")/"), "", $property['Last_Unavailable_Date']) : '';

					$remove_property = false;
					
					if ( !empty($last_available) && !empty($last_unavailable) )
					{
						if ( $last_unavailable > $last_available )
						{
							// This is unavailable. Need to remove
							$remove_property = true;
						}
					}

					// if property has been removed from the market
					// need to figure out exactly how this is demarcated. Most likely a mix of Last_Unavailable_Date, Last_Available_Date and Status_Name

					if ( $remove_property )
					{
						$this->remove_property( $property['Key'] );
					}
				}
			}
        }
        else
        {
        	// Failed to parse JSON
        	$this->log_error( 'Failed to parse properties JSON file. Possibly invalid JSON: ' . print_r($contents, true) );

        	return false;
        }

        $json = json_decode( file_get_contents( 'https://' . $import_settings['url'] . '/services/getPropertyJSON.aspx?w=1145&pw=' . $import_settings['password'] ), TRUE );

		if ($json !== FALSE && isset($json['S_TERMS']) && !empty($json['S_TERMS']))
		{
			$this->log("Parsing terms");
			
            $terms = $json['S_TERMS'];

			$this->log("Found " . count($properties) . " terms in JSON ready for parsing");

			foreach ($terms as $term)
			{
				$this->terms[$term['NO']] = strtolower($term['NAME']);
			}
        }
        else
        {
        	// Failed to parse JSON
        	$this->log_error( 'Failed to parse terms JSON file. Possibly invalid JSON' );

        	return false;
        }

        return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_agency_pilot_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_agency_pilot_json_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_agency_pilot_json", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['Key'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['Key'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['Key'], false );

			$this->log( 'Importing property with reference ' . $property['Key'], 0, $property['Key'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = $property['Full_Address'];
            if ($display_address == '')
            {
                $display_address = $property['Street'];
                if ($property['District'] != '')
                {
                    if ($display_address != '')
                    {
                        $display_address .= ', ';
                    }
                    $display_address .= $property['District'];
                }
                if ($property['Town'] != '')
                {
                    if ($display_address != '')
                    {
                        $display_address .= ', ';
                    }
                    $display_address .= $property['Town'];
                }
            }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['Key'], $property, $display_address, $property['Description'] );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['Key'] );

				update_post_meta( $post_id, $imported_ref_key, $property['Key'] );

				update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

				// Address
				update_post_meta( $post_id, '_reference_number',  ( ( isset($property['Number']) ) ? $property['Number'] : '' ) );
				update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['Building_Name']) ) ? $property['Building_Name'] : '' ) . ' ' . ( ( isset($property['Address']['Number']) ) ? $property['Address']['Number'] : '' ) ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property['Street']) ) ? $property['Street'] : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property['District']) ) ? $property['District'] : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property['Town']) ) ? $property['Town'] : '' ) );
				update_post_meta( $post_id, '_address_four', '' );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property['Postcode']) ) ? $property['Postcode'] : '' ) );

				$country = get_option( 'propertyhive_default_country', 'GB' );
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = apply_filters( 'propertyhive_agency_pilot_json_address_fields_to_check', array('District', 'Town') );
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
				update_post_meta( $post_id, '_latitude', ( ( isset($property['Latitude']) ) ? $property['Latitude'] : '' ) );
				update_post_meta( $post_id, '_longitude', ( ( isset($property['Longitude']) ) ? $property['Longitude'] : '' ) );

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
				
				$office_id = $this->primary_office_id;
				if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
				{
					foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
					{
						if ( $branch_code == $property['PartnerOffice'] )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				// Commercial Details
				update_post_meta( $post_id, '_department', 'commercial' );

				update_post_meta( $post_id, '_for_sale', '' );
        		update_post_meta( $post_id, '_to_rent', '' );

        		if ( $property['Freehold'] == '1' )
                {
                    update_post_meta( $post_id, '_for_sale', 'yes' );

                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

                    $price = preg_replace("/[^0-9.]/", '', $property['Freehold_From']);
                    if ( $price == '' )
                    {
                        $price = preg_replace("/[^0-9.]/", '', $property['Freehold_To']);
                    }
                    update_post_meta( $post_id, '_price_from', $price );

                    $price = preg_replace("/[^0-9.]/", '', $property['Freehold_To']);
                    if ( $price == '' || $price == '0' )
                    {
                        $price = preg_replace("/[^0-9.]/", '', $property['Freehold_From']);
                    }
                    update_post_meta( $post_id, '_price_to', $price );

                    update_post_meta( $post_id, '_price_units', '' );

                    $poa = '';
                    if ( $property['Freehold_Term'] != '' && isset($this->terms[$property['Freehold_Term']]) )
                    {
	                    if ( strpos( $this->terms[$property['Freehold_Term']], 'application' ) !== FALSE || strpos( $this->terms[$property['Freehold_Term']], 'poa' ) !== FALSE )
	                    {
	                    	$poa = 'yes';
	                    }
	                }
                    update_post_meta( $post_id, '_price_poa', $poa );

                    // Tenure
		            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();
					
					if ( !empty($mapping) && isset($property['Freehold_Term']) && isset($mapping[$property['Freehold_Term']]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[$property['Freehold_Term']], 'commercial_tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
		            }
                }

                if ( $property['Leasehold'] == '1' )
                {
                    update_post_meta( $post_id, '_to_rent', 'yes' );

                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

                    $rent = preg_replace("/[^0-9.]/", '', $property['Leasehold_From']);
                    if ( $rent == '' )
                    {
                        $rent = preg_replace("/[^0-9.]/", '', $property['Leasehold_To']);
                    }
                    update_post_meta( $post_id, '_rent_from', $rent );

                    $rent = preg_replace("/[^0-9.]/", '', $property['Leasehold_To']);
                    if ( $rent == '' || $rent == '0' )
                    {
                        $rent = preg_replace("/[^0-9.]/", '', $property['Leasehold_From']);
                    }
                    update_post_meta( $post_id, '_rent_to', $rent );

                    $rent_units = 'pa';
                    if ( $property['Leasehold_Term'] != '' && isset($this->terms[$property['Leasehold_Term']]) )
                    {
                    	if ( strpos( $this->terms[$property['Leasehold_Term']], 'month' ) !== FALSE || strpos( $this->terms[$property['Leasehold_Term']], 'pcm' ) !== FALSE )
	                    {
	                    	$rent_units = 'pcm';
	                    }
	                    elseif ( strpos( $this->terms[$property['Leasehold_Term']], 'week' ) !== FALSE || strpos( $this->terms[$property['Leasehold_Term']], 'pw' ) !== FALSE )
	                    {
	                    	$rent_units = 'pw';
	                    }
	                    elseif ( strpos( $this->terms[$property['Leasehold_Term']], 'quarter' ) !== FALSE || strpos( $this->terms[$property['Leasehold_Term']], 'pq' ) !== FALSE )
	                    {
	                    	$rent_units = 'pq';
	                    }
	                    elseif ( strpos( $this->terms[$property['Leasehold_Term']], 'sq ft' ) !== FALSE || strpos( $this->terms[$property['Leasehold_Term']], 'foot' ) !== FALSE )
	                    {
	                    	$rent_units = 'psf';
	                    }
	                    elseif ( strpos( $this->terms[$property['Leasehold_Term']], 'sq m' ) !== FALSE || strpos( $this->terms[$property['Leasehold_Term']], 'metre' ) !== FALSE )
	                    {
	                    	$rent_units = 'psf';
	                    }
                    }
                    update_post_meta( $post_id, '_rent_units', $rent_units);

                    $poa = '';
                    if ( $property['Leasehold_Term'] != '' && isset($this->terms[$property['Leasehold_Term']]) )
                    {
	                    if ( strpos( $this->terms[$property['Leasehold_Term']], 'application' ) !== FALSE || strpos( $this->terms[$property['Leasehold_Term']], 'poa' ) !== FALSE )
	                    {
	                    	$poa = 'yes';
	                    }
	                }
                    update_post_meta( $post_id, '_rent_poa', $poa );
                }

                // Store price in common currency (GBP) used for ordering
	            $ph_countries = new PH_Countries();
	            $ph_countries->update_property_price_actual( $post_id );

	            $size = preg_replace("/[^0-9.]/", '', $property['Min_Size']);
	            if ( $size == '' )
	            {
	                $size = preg_replace("/[^0-9.]/", '', $property['Max_Size']);
	            }
	            update_post_meta( $post_id, '_floor_area_from', $size );

	            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

	            $size = preg_replace("/[^0-9.]/", '', $property['Max_Size']);
	            if ( $size == '' )
	            {
	                $size = preg_replace("/[^0-9.]/", '', $property['Min_Size']);
	            }
	            update_post_meta( $post_id, '_floor_area_to', $size );

	            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, 'sqft' ) );

	            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

	            $size = '';

	            update_post_meta( $post_id, '_site_area_from', $size );

	            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

	            update_post_meta( $post_id, '_site_area_to', $size );

	            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, 'sqft' ) );

	            update_post_meta( $post_id, '_site_area_units', 'sqft' );
				
				// Property Type
				$import_settings = isset($options['mappings']['commercial_property_type']) ? $import_settings['mappings']['commercial_property_type'] : array();
				
				if ( isset($property['UnitIDs']) && $property['UnitIDs'] != '' )
				{
					$explode_unit_ids = explode(",", $property['UnitIDs']);
					$term_ids = array();

					foreach ( $explode_unit_ids as $unit_id )
					{
						if ( !empty($mapping) && isset($mapping[$unit_id]) )
						{
							$term_ids[] = (int)$mapping[$unit_id];
				            
			            }
					}

					if ( !empty($term_ids) )
					{
						wp_set_object_terms( $post_id, $term_ids, 'commercial_property_type' );
					}					
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'commercial_property_type' );

		            	$this->log( 'Property received with type (' . $property['UnitIDs'] . ') that are not mapped', $post_id, $property['Key'] );

		            	$import_settings = $this->add_missing_mapping( $mapping, 'commercial_property_type', $property['UnitIDs'] );
		            }
		        }
		        else
		        {
		        	wp_delete_object_term_relationships( $post_id, 'commercial_property_type' );
		        }

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
				$mapping = isset($import_settings['mappings']['commercial_availability']) ? $import_settings['mappings']['commercial_availability'] : array();
				
        		if ( isset($property['Market_Status']) && $property['Market_Status'] != '' )
        		{
					if ( !empty($mapping) && isset($mapping[$property['Market_Status']]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$property['Market_Status']], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
					}
		        }
		        else
		        {
		        	wp_delete_object_term_relationships( $post_id, 'availability' );
		        }

				$features = array();
				for ($i = 1; $i <= 10; ++$i)
				{
					if ( isset( $property['BulletPoint' . $i] ) && $property['BulletPoint' . $i] != '' )
					{
						$features[] = $property['BulletPoint' . $i];
					}
				}

				update_post_meta( $post_id, '_features', count( $features ) );
	
        		$i = 0;
		        foreach ( $features as $feature )
		        {
		            update_post_meta( $post_id, '_feature_' . $i, $feature );
		            ++$i;
		        }

		        // Media - Images
			    $media = array();
			    if (isset($property['AllPhotoKeys']) && $property['AllPhotoKeys'] != '')
                {
                	$images_array = explode(",", $property['AllPhotoKeys']);

					foreach ( $images_array as $image )
					{
						$image = str_replace("_sm.", ".", $image);
						$image = str_replace("_web.", ".", $image);
						$url = 'https://' . $import_settings['url'] . '/store/property/' . $image;

						$media[] = array(
							'url' => $url,
						);
					}
				}

				$this->import_media( $post_id, $property['Key'], 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if (isset($property['PhotoFloorPlanURL']) && $property['PhotoFloorPlanURL'] != '')
                {
					$image = str_replace("_sm.", ".", $property['PhotoFloorPlanURL']);
					$image = str_replace("_web.", ".", $image);
					$url = $image;

					$media[] = array(
						'url' => $url,
					);
				}

				$this->import_media( $post_id, $property['Key'], 'floorplan', $media, false );

				// Media - Floorplans
			    $media = array();
			    if (isset($property['PhotoFloorPlanURL']) && $property['PhotoFloorPlanURL'] != '')
                {
					$image = str_replace("_sm.", ".", $property['PhotoFloorPlanURL']);
					$image = str_replace("_web.", ".", $image);
					$url = $image;

					$media[] = array(
						'url' => $url,
					);
				}

				$this->import_media( $post_id, $property['Key'], 'floorplan', $media, false );

				// Media - Brochures
			    $media = array();
			    if ( isset($property['BrochureURL1']) && !empty($property['BrochureURL1']) )
				{
					$url = 'https://' . $import_settings['url'] . '/store/documents/other/' . $property['BrochureURL1'];
					$explode_url = explode( "?", $url );

					$media[] = array(
						'url' => $url,
						'filename' => $explode_url[0],
					);
				}
				if ( isset($property['BrochureURL2']) && !empty($property['BrochureURL2']) )
				{
					$url = 'https://' . $import_settings['url'] . '/store/documents/other/' . $property['BrochureURL2'];
					$explode_url = explode( "?", $url );

					$media[] = array(
						'url' => $url,
						'filename' => $explode_url[0],
					);
				}
				if ( isset($property['BrochureURL3']) && !empty($property['BrochureURL3']) )
				{
					$url = 'https://' . $import_settings['url'] . '/store/documents/other/' . $property['BrochureURL3'];
					$explode_url = explode( "?", $url );

					$media[] = array(
						'url' => $url,
						'filename' => $explode_url[0],
					);
				}

				$this->import_media( $post_id, $property['Key'], 'brochure', $media, false );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_agency_pilot_json", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['Key'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_agency_pilot_json" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property['Key'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values() 
	{
		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$return = array();

        $options = array();

    	$json = json_decode( file_get_contents( 'https://' . $import_settings['url'] . '/services/getPropertyJSON.aspx?w=1143&pw=' . $import_settings['password'] ), TRUE );

		if ($json !== FALSE && isset($json['S_MKTSTATUS']) && !empty($json['S_MKTSTATUS']))
		{
			foreach ( $json['S_MKTSTATUS'] as $value )
			{
				$options[$value['NO']] = $value['NAME'];
			}
			ksort($options);
		}

		$return['commercial_availability'] = $options;

        $options = array();

    	$json = json_decode( file_get_contents( 'https://' . $import_settings['url'] . '/services/getPropertyJSON.aspx?w=1146&pw=' . $import_settings['password'] ), TRUE );

		if ($json !== FALSE && isset($json['S_UNIT']) && !empty($json['S_UNIT']))
		{
			foreach ( $json['S_UNIT'] as $value )
			{
				$options[$value['NO']] = $value['NAME'];
			}
			ksort($options);
		}

        $return['commercial_property_type'] = $options;

        $options = array();

        $json = json_decode( file_get_contents( 'https://' . $import_settings['url'] . '/services/getPropertyJSON.aspx?w=1145&pw=' . $import_settings['password'] ), TRUE );

		if ($json !== FALSE && isset($json['S_TERMS']) && !empty($json['S_TERMS']))
		{
			$options = array();
			foreach ( $json['S_TERMS'] as $value )
			{
				$options[$value['NO']] = $value['NAME'];
			}
			ksort($options);
			return $options;
		}

		$return['commercial_tenure'] = $options;

		return $return;
    }
}

}