<?php
/**
 * TODO: only import updated? (no test feed to trial this with)
 * 
 * Class for managing the import process of a PropertyADD XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_PropertyADD_XML_Import extends PH_Property_Import_Process {

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

		$contents = '';

		$response = wp_remote_get( $import_settings['url'] . '/property-ajaxsearch.aspx?mode=fulldetails&roomdetail=1', array( 'timeout' => 120 ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		$xml = simplexml_load_string($contents);

		if ($xml !== FALSE)
		{
			$this->log("Parsing sales properties");
			
			foreach ($xml->Property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

                $this->properties[] = $property;
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse sales XML file. Possibly invalid XML: ' . print_r($contents, true) );
        	return false;
        }

        $contents = '';

		$response = wp_remote_get( $import_settings['url'] . '/property-ajaxsearch.aspx?mode=fulllettingsdetails&roomdetail=1', array( 'timeout' => 120 ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

        $xml = simplexml_load_string($contents);

		if ($xml !== FALSE)
		{
			$this->log("Parsing lettings properties");
			
			foreach ($xml->Property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

                $this->properties[] = $property;
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse lettings XML file. Possibly invalid XML: ' . print_r($contents, true) );
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

        do_action( "propertyhive_pre_import_properties_propertyadd_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_propertyadd_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_propertyadd_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->Property_ID == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->Property_ID );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->Property_ID, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->Property_ID, 0, (string)$property->Property_ID, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = (string)$property->Property_MarketAddress;

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->Property_ID, $property, $display_address, html_entity_decode(html_entity_decode((string)$property->Property_ShortMarketingText)) );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->Property_ID );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->Property_ID );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->Property_ID );

				$explode_market_address = explode(",", (string)$property->Property_MarketAddress);
				array_pop($explode_market_address); // remove last part of address. Likely to be postcode. Could maybe be done better to actually detect if last part is a postcode or not
				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', ( isset($explode_market_address[0]) ? $explode_market_address[0] : '' ) );
				update_post_meta( $post_id, '_address_two', ( isset($explode_market_address[1]) ? $explode_market_address[1] : '' ) );
				update_post_meta( $post_id, '_address_three', ( isset($explode_market_address[2]) ? $explode_market_address[2] : '' ) );
				update_post_meta( $post_id, '_address_four', ( isset($explode_market_address[3]) ? $explode_market_address[3] : '' ) );
				update_post_meta( $post_id, '_address_postcode', (string)$property->Property_PostCode );

				$country = 'GB';
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = explode(",", $display_address);
				$location_term_ids = array();

				foreach ( $address_fields_to_check as $address_field )
				{
					if ( isset($address_field) && trim($address_field) != '' ) 
					{
						$term = term_exists( trim($address_field), 'location');
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
				if ( isset($property->Property_Latitude) && isset($property->Property_Longitude) && (string)$property->Property_Latitude != '' && (string)$property->Property_Longitude != '' && (string)$property->Property_Latitude != '0' && (string)$property->Property_Longitude != '0' )
				{
					update_post_meta( $post_id, '_latitude', trim((string)$property->Property_Latitude) );
					update_post_meta( $post_id, '_longitude', trim((string)$property->Property_Longitude) );
				}
				else
				{
					// No lat/lng passed. Let's go and get it if none entered
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array((string)$property->Property_MarketAddress);
						$address_to_geocode_osm = array((string)$property->Property_PostCode);

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->Property_ID, $address_to_geocode, $address_to_geocode_osm, $country );
					}
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $this->primary_office_id;
				if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
				{
					foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
					{
						if ( $branch_code == (string)$property->Property_BranchID )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				$department = ( strtolower((string)$property->Property_Basis) != 'for sale' ? 'residential-lettings' : 'residential-sales' );

				// Residential Details
				update_post_meta( $post_id, '_department', $department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->Property_Bedrooms) ) ? round((string)$property->Property_Bedrooms) : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->Property_Bathrooms) ) ? round((string)$property->Property_Bathrooms) : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->Property_ReceptionRooms) ) ? round((string)$property->Property_ReceptionRooms) : '' ) );

				$mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();

				if ( isset($property->Property_Type) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->Property_Type]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->Property_Type], 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->Property_Type . ') that is not mapped', $post_id, (string)$property->Property_ID );

						$import_settings = $this->add_missing_mapping( $mapping, 'property_type', (string)$property->Property_Type, $post_id );
					}
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, 'property_type' );
				}

				// Residential Sales Details
				if ( $department == 'residential-sales' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->Property_PlainPrice));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', '' );
					update_post_meta( $post_id, '_currency', 'GBP' );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($property->Property_PriceQualifier) && isset($mapping[trim((string)$property->Property_PriceQualifier)]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[trim((string)$property->Property_PriceQualifier)], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }

		            // Tenure
		            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

					if ( !empty($mapping) && isset($property->Property_Tenure) && isset($mapping[(string)$property->Property_Tenure]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->Property_Tenure], 'tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'tenure' );
		            }
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->Property_PlainPrice));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					if ( isset($property->Property_PriceBasis) && in_array((string)$property->Property_PriceBasis, array('PCM', 'PW', 'PPPW', 'PQ', 'PA')) )
					{
						$rent_frequency = strtolower($property->Property_PriceBasis);
					}
					$price_actual = $price;
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					update_post_meta( $post_id, '_currency', 'GBP' );
					
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_deposit', (string)$property->Property_PlainDeposit);
					$available_date = '';
					if ( isset($property->Property_AvailableDate) && (string)$property->Property_AvailableDate != '' )
					{
						$available_date = (string)$property->Property_AvailableDate;
					}
            		update_post_meta( $post_id, '_available_date', $available_date );

            		// Furnished - not provided in XML
            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

					if ( !empty($mapping) && isset($property->Property_FurnishBasis) && isset($mapping[(string)$property->Property_FurnishBasis]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->Property_FurnishBasis], 'furnished' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'furnished' );
		            }
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
					update_post_meta( $post_id, '_featured', ( isset($property->Property_Featured) && strtolower((string)$property->Property_Featured) == 'True' ) ? 'yes' : '' );
				}
			
				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->PropertyStatus_Desc) && isset($mapping[(string)$property->PropertyStatus_Desc]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->PropertyStatus_Desc], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

	            // Features
				$features = array();
				if ( isset($property->SalesPoints->SalesPoint) && !empty($property->SalesPoints->SalesPoint) )
				{
					foreach ( $property->SalesPoints->SalesPoint as $bulletpoint )
					{
						$features[] = (string)$bulletpoint->Feature_Desc;
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
	            $num_rooms = 0;
	            if ( (string)$property->Property_LongMarketingText != '' )
	            {
	            	update_post_meta( $post_id, '_room_name_' . $num_rooms, '' );
		            update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, '' );
		            update_post_meta( $post_id, '_room_description_' . $num_rooms, html_entity_decode(html_entity_decode((string)$property->Property_LongMarketingText)) );

	            	++$num_rooms;
	            }

	            if ( isset($property->Rooms->Room) && !empty($property->Rooms->Room) )
				{
	            	foreach ( $property->Rooms->Room as $room )
	            	{
	            		update_post_meta( $post_id, '_room_name_' . $num_rooms, (string)$room->Room_Desc );
			            update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, (string)$room->Room_Measurements );
			            update_post_meta( $post_id, '_room_description_' . $num_rooms, (string)$room->Room_Notes );

		            	++$num_rooms;
	            	}
	            }

	            update_post_meta( $post_id, '_rooms', $num_rooms );

	            // Media - Images
			    $media = array();
			    if (isset($property->Images->Image) && !empty($property->Images->Image))
                {
                    foreach ($property->Images->Image as $image)
                    {
                    	$url = (string)$image->Image_Url;

						$media[] = array(
							'url' => $url,
							'filename' => basename( $url ) . '.jpg',
							'description' => (string)$image->Image_Description,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->Property_ID, 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if (isset($property->Floorplans->FloorPlan) && !empty($property->Floorplans->FloorPlan))
                {
                    foreach ($property->Floorplans->FloorPlan as $floorPlan)
                    {
                    	$url = (string)$floorPlan->FloorPlan_Url;

						$media[] = array(
							'url' => $url,
							'filename' => basename( $url ) . '.jpg',
							'description' => (string)$floorPlan->FloorPlan_Description,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->Property_ID, 'floorplan', $media, false );

				// Media - EPCs
			    $media = array();
			    if (isset($property->Property_EER) && !empty((string)$property->Property_EER))
	            {
					$media[] = array(
						'url' => (string)$property->Property_EER,
						'filename' => 'eer-' . (string)$property->Property_ID . '.jpg',
					);
				}

				$this->import_media( $post_id, (string)$property->Property_ID, 'epc', $media, false );

				// Media - Virtual Tours
				$virtual_tours = array();
				if ( isset($property->Property_VirtualTour) && (string)$property->Property_VirtualTour != '' )
                {
                    $virtual_tours[] = (string)$property->Property_VirtualTour;
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->Property_ID );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_propertyadd_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->Property_ID, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;
			
		} // end foreach property

		do_action( "propertyhive_post_import_properties_propertyadd_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->Property_ID;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'For Sale' => 'For Sale',
                'Sold Subject to Contract' => 'Sold Subject to Contract',
                'Under Offer' => 'Under Offer',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
                'Under Offer' => 'Under Offer',
                'Let Agreed' => 'Let Agreed',
            ),
            'property_type' => array(
                'Detached House' => 'Detached House',
                'Semi-Detached House' => 'Semi-Detached House',
                'Terraced House' => 'Terraced House',
                'End of Terrace House' => 'End of Terrace House',
                'Town House' => 'Town House',
                'Apartment' => 'Apartment',
                'Flat' => 'Flat',
                'Maisonette' => 'Maisonette'
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'Offers Over' => 'Offers Over',
                'Offers in Excess of' => 'Offers in Excess of',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'furnished' => array(
                'Furnished' => 'Furnished',
                'Unfurnished' => 'Unfurnished',
                'Unfurnished (White Goods)' => 'Unfurnished (White Goods)',
                'Part Furnished' => 'Part Furnished',
            ),
        );
	}
}

}