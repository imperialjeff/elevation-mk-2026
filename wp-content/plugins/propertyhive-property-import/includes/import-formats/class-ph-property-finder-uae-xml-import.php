<?php
/**
 * Class for managing the import process of a Property Finder UAE XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Property_Finder_UAE_XML_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['xml_url'] ) ) 
			{
			    $import_settings['xml_url'] = wp_unslash( $_POST['xml_url'] );
			}
		}

		if ( empty( trim($import_settings['xml_url']) ) )
		{
			$this->log_error( 'No URLs to process' );
		    return false;
		}

		$urls = explode( ",", trim($import_settings['xml_url']) );

		if ( empty( $urls ) )
		{
			$this->log_error( 'No URLs to process' );
		    return false;
		}

		$limit = $this->get_property_limit();

		foreach ( $urls as $url )
		{
			$contents = '';

			$response = wp_remote_get( sanitize_url(trim($url)), array( 'timeout' => 120 ) );
			if ( !is_wp_error($response) && is_array( $response ) ) 
			{
				$contents = $response['body'];
			}
			else
			{
				$this->log_error( "Failed to obtain XML from " . trim($url) . ". Dump of response as follows: " . print_r($response, TRUE) );

	        	return false;
			}

			$xml = simplexml_load_string($contents);

			if ($xml !== FALSE)
			{
				$this->log("Parsing properties");

				foreach ($xml->property as $property)
    			{
    				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
	                {
	                    return true;
	                }

		            $this->properties[] = $property;
	            }
	        }
	        else
	        {
	        	// Failed to parse XML
	        	$this->log_error( 'Failed to parse XML file at ' . $url . '. Possibly invalid XML: ' . print_r($contents, true) );
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

        do_action( "propertyhive_pre_import_properties_property_finder_uae_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_property_finder_uae_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_property_finder_uae_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->reference_number == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->reference_number );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->reference_number, false );

			$this->log( 'Importing property ' . $property_row . ' with reference ' . (string)$property->reference_number, 0, (string)$property->reference_number, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = (string)$property->title_en;

			$property_attributes = $property->attributes();

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->reference_number, $property, $display_address, (string)$property->description_en, '', ( isset($property_attributes['last_update']) ) ? date( 'Y-m-d H:i:s', strtotime( (string)$property_attributes['last_update'] )) : '' );

			if ( $inserted_updated !== false )
			{
				// Inserted property ok. Continue

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->reference_number );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

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

				update_post_meta( $post_id, $imported_ref_key, (string)$property->reference_number );

				$previous_update_date = get_post_meta( $post_id, '_property_finder_uae_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property_attributes['last_update']) ||
						(
							isset($property_attributes['last_update']) &&
							(string)$property_attributes['last_update'] == ''
						) ||
						$previous_update_date == '' ||
						(
							isset($property_attributes['last_update']) &&
							(string)$property_attributes['last_update'] != '' &&
							$previous_update_date != '' &&
							strtotime((string)$property_attributes['last_update']) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', (string)$property->reference_number );
					update_post_meta( $post_id, '_address_name_number', ( ( isset($property->property_name) ) ? (string)$property->property_name : '' ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->sub_community) ) ? (string)$property->sub_community : '' ) );
					update_post_meta( $post_id, '_address_two', '' );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->community) ) ? (string)$property->community : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->city) ) ? (string)$property->city : '' ) );
					update_post_meta( $post_id, '_address_postcode', '' );

					$country = get_option( 'propertyhive_default_country', 'AE' );
					update_post_meta( $post_id, '_address_country', $country );

					$ph_countries = new PH_Countries();
					$country = $ph_countries->get_country( $country );
					$currency = isset($country['currency_code']) ? $country['currency_code'] : 'AED';

					$geopoints = isset($property->geopoints) ? explode(",", (string)$property->geopoints) : array();
					$lat = '';
					$lng = '';
					if ( count($geopoints) == 2 )
					{
						$lat = $geopoints[1];
						$lng = $geopoints[0];
					}
					update_post_meta( $post_id, '_latitude', $lat );
				    update_post_meta( $post_id, '_longitude', $lng );

	            	$address_fields_to_check = apply_filters( 'propertyhive_property_finder_uae_xml_address_fields_to_check', array('sub_community', 'community', 'city') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property->{$address_field}) && trim((string)$property->{$address_field}) != '' ) 
						{
							$term = term_exists( trim((string)$property->{$address_field}), 'location');
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
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
						
					$office_id = $this->primary_office_id; // Needs mapping properly
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'residential-sales';
					$prefix = '';
					if ( (string)$property->offering_type == 'RR' )
					{
						$department = 'residential-lettings';
					}
					if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' && ( (string)$property->offering_type == 'CS' || (string)$property->offering_type == 'CR' ) )
					{  
						$department = 'commercial';
						$prefix  = 'commercial_';
					}

					// Residential Details
					update_post_meta( $post_id, '_department', $department );
					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedroom) ) ? (string)$property->bedroom : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathroom) ) ? (string)$property->bathroom : '' ) );
					update_post_meta( $post_id, '_reception_rooms', '' );

					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property->property_type ) && (string)$property->property_type  != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->property_type ]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->property_type ], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property->property_type  . ') that is not mapped', $post_id, (string)$property->reference_number );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->property_type, $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					if ( $department == 'residential-sales' )
					{
						// Clean price
						$price = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                	if ( is_numeric($price) )
	                	{
		                	$price = round($price);
		                }
		                
						update_post_meta( $post_id, '_price', $price);
						update_post_meta( $post_id, '_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '' ) );
						
						update_post_meta( $post_id, '_currency', $currency );
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                	if ( is_numeric($price) )
	                	{
		                	$price = round($price);
		                }

						update_post_meta( $post_id, '_rent', $price );

						$rent_frequency = 'pcm';
						if ( isset($property->rental_period) )
						{
							switch ((string)$property->rental_period)
							{
								case "Y": { $rent_frequency = 'pa'; break; }
								case "W": { $rent_frequency = 'pw'; break; }
								case "D": { $rent_frequency = 'pd'; break; }
							}
						}
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

						update_post_meta( $post_id, '_currency', $currency );
						
						update_post_meta( $post_id, '_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '' ) );

						update_post_meta( $post_id, '_deposit', '' );

						$available_date = ( ( isset($property->availability_date) && (string)$property->availability_date != '' ) ? date("Y-m-d", strtotime((string)$property->availability_date)) : '' );
		                update_post_meta( $post_id, '_available_date', $available_date );
		        		
		        		// Furnished 
		        		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

						if ( !empty($mapping) && isset($property->furnished) && isset($mapping[(string)$property->furnished]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->furnished], 'furnished' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'furnished' );
			            }
			        }
			        elseif ( $department == 'commercial' )
					{
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

	            		if ( (string)$property->offering_type == 'CS' )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', $currency );

		                    $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '' ) );
		                }

		                if ( (string)$property->offering_type == 'CR' )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', $currency );

		                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
		                    update_post_meta( $post_id, '_rent_from', $rent );
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    $rent_frequency = 'pcm';
							if ( isset($property->rental_period) )
							{
								switch ((string)$property->rental_period)
								{
									case "Y": { $rent_frequency = 'pa'; break; }
									case "W": { $rent_frequency = 'pw'; break; }
									case "D": { $rent_frequency = 'pd'; break; }
								}
							}
							update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

		                    update_post_meta( $post_id, '_rent_poa', ( ( isset($property->price_on_application) && strtolower((string)$property->price_on_application) == 'yes' ) ? 'yes' : '' ) );
		                }

			            $size = '';
			            update_post_meta( $post_id, '_floor_area_from', $size );
			            update_post_meta( $post_id, '_floor_area_from_sqft', $size );
			            update_post_meta( $post_id, '_floor_area_to', $size );
			            update_post_meta( $post_id, '_floor_area_to_sqft', $size );
			            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

			            update_post_meta( $post_id, '_site_area_from', $size );
			            update_post_meta( $post_id, '_site_area_from_sqft', $size );
			            update_post_meta( $post_id, '_site_area_to', $size );
			            update_post_meta( $post_id, '_site_area_to_sqft', $size );
			            update_post_meta( $post_id, '_site_area_units', 'sqft' );
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
					$availability = 'For Sale';
					if ( $department == 'residential-lettings' )
					{
						$availability = 'To Let';
					}
					elseif ( $department == 'commercial' )
					{
						if ( (string)$property->offering_type == 'CR' )
		                {
							$availability = 'To Let';
						}
					}
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					if ( !empty($mapping) && isset($mapping[$availability]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[$availability], 'availability' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'availability' );
		            }

			        // Rooms
			        $rooms = 0;
					update_post_meta( $post_id, '_rooms', $rooms );

					// Media - Images
				    $media = array();
				    if ( isset($property->photo->url) && !empty($property->photo->url) )
		            {
	    				foreach ( $property->photo->url as $url )
						{
							$photo_attributes = $url->attributes();

							$media[] = array(
								'url' => (string)$url,
								'modified' => (string)$photo_attributes['last_update'],
							);
						}
					}

					$this->import_media( $post_id, (string)$property->reference_number, 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property->floor_plan->url) && !empty($property->floor_plan->url) )
		            {
	    				foreach ( $property->floor_plan->url as $url )
						{
							$photo_attributes = $url->attributes();

							$media[] = array(
								'url' => (string)$url,
								'modified' => (string)$photo_attributes['last_update'],
							);
						}
					}

					$this->import_media( $post_id, (string)$property->reference_number, 'floorplan', $media, true );

					// Media - Virtual Tours
					$virtual_tours = array();
					if (isset($property->view360) && (string)$property->view360 != '')
	                {
	                    $virtual_tours[] = (string)$property->view360;          
	                }

	                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
	                foreach ($virtual_tours as $i => $virtual_tour)
	                {
	                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
	                }

					$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->reference_number );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->reference_number );
				}

				if ( isset($property_attributes['last_update']) ) { update_post_meta( $post_id, '_property_finder_uae_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime((string)$property_attributes['last_update'])) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_property_finder_uae_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->reference_number, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_property_finder_uae_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->reference_number;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'For Sale' => 'For Sale',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
            ),
            'commercial_availability' => array(
                'For Sale' => 'For Sale',
                'To Let' => 'To Let',
            ),
            'property_type' => array(
                'AP' => 'Apartment/Flat',
                'BW' => 'Bungalow',
                'DX' => 'Duplex',
                'FF' => 'Full Floor',
                'HF' => 'Half Floor',
                'LP' => 'Land/Plot',
                'PH' => 'Penthouse',
                'TH' => 'Townhouse',
                'VH' => 'Villa/House',
                'WB' => 'Whole Building',
            ),
            'commercial_property_type' => array(
                'BU' => 'Bulk Units',
                'CD' => 'Compound',
                'FA' => 'Factory',
                'LC' => 'Labor Camp',
                'LP' => 'Land/Plot',
                'OF' => 'Office Space',
                'BC' => 'Business Centre',
                'RE' => 'Retail',
                'RT' => 'Restaurant',
                'SA' => 'Staff Accommodation',
                'SH' => 'Shop',
                'SR' => 'Showroom',
                'CW' => 'Co-working Space',
                'ST' => 'Storage',
                'WH' => 'Warehouse',
            ),
            'furnished' => array(
                'Yes' => 'Yes',
                'No' => 'No',
            ),
        );
	}
}

}