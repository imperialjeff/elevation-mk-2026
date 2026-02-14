<?php
/**
 * Class for managing the import process of a ReSales Online XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_ReSales_Online_XML_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['url'] ) ) 
			{
			    $import_settings['url'] = wp_unslash( $_POST['url'] );
			}
		}

		$contents = '';

		$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 600 ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		$this->properties = array(); // Reset properties in the event we're importing multiple files

		$xml = simplexml_load_string( $contents );

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			
            $properties_imported = 0;
            
			foreach ($xml->property as $property)
			{
				if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

				if ( (string)$property->availability != 'Off Market' )
				{
					$this->properties[] = $property;
				}
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML: ' . print_r($contents, true) );

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

        do_action( "propertyhive_pre_import_properties_resales_online_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_resales_online_xml_properties_due_import", $this->properties, $this->import_id );

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' );

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_resales_online_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->id == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->id );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->id, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->id, 0, (string)$property->id, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = '';
			if ( (string)$property->town != '' )
			{
				$display_address .= (string)$property->town;
			}
			if ( (string)$property->province != '' )
			{
				if ( $display_address != '' )
				{
					$display_address .= ', ';
				}
				$display_address .= (string)$property->province;
			}

			$description = isset($property->description->uk) ? (string)$property->description->uk : '';
			if ( empty($description) )
			{
				$description = isset($property->desc->en) ? (string)$property->desc->en : '';
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, $display_address, $description );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->id );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->id );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->ref );
				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', '' );
				update_post_meta( $post_id, '_address_two', ( ( isset($property->town) ) ? (string)$property->town : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->province) ) ? (string)$property->province : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->area) ) ? (string)$property->area : '' ) );
				update_post_meta( $post_id, '_address_postcode', '' );

				$country = 'ES';
				if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
				{
					$ph_countries = new PH_Countries();
					foreach ( $ph_countries->countries as $country_code => $country_details )
					{
						if ( strtolower((string)$property->country) == strtolower($country_details['name']) )
						{
							$country = $country_code;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_address_country', $country );

            	// Let's just look at address fields to see if we find a match
            	$address_fields_to_check = apply_filters( 'propertyhive_resales_online_xml_address_fields_to_check', array('town', 'province', 'area') );
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

				// Coordinates
				$lat = get_post_meta( $post_id, '_latitude', TRUE);
				$lng = get_post_meta( $post_id, '_longitude', TRUE);

				if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
				{
					// No lat lng. Let's get it
					$address_to_geocode = array();
					if ( isset($property->town) && trim((string)$property->town) != '' ) { $address_to_geocode[] = (string)$property->town; }
					if ( isset($property->province) && trim((string)$property->province) != '' ) { $address_to_geocode[] = (string)$property->province; }
					if ( isset($property->area) && trim((string)$property->area) != '' ) { $address_to_geocode[] = (string)$property->area; }
					$address_to_geocode_osm = $address_to_geocode;

					$return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode_osm, $country );
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $this->primary_office_id;
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-sales';
				if ( isset($property->shortterm_low) )
				{
					$department = 'residential-lettings';
				}

				update_post_meta( $post_id, '_department', $department );

				// Is the property portal add on activated
				if ( class_exists('PH_Property_Portal') )
	    		{
	    			$explode_agent_branch = array();
	    			
	    			if ( isset($this->branch_mappings[$this->import_id]) )
	    			{
	    				$explode_agent_branch = explode("|", $this->branch_mappings[$this->import_id]);
	    			}

	    			if ( !empty($explode_agent_branch) )
					{
						update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
						update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );
					}
					else
					{
						update_post_meta( $post_id, '_agent_id', '' );
						update_post_meta( $post_id, '_branch_id', '' );
					}
	    		}

				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->beds) ) ? (string)$property->beds : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->baths) ) ? (string)$property->baths : '' ) );
				update_post_meta( $post_id, '_reception_rooms', '' );

				$prefix = '';
				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property->type) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->type->uk . ' - ' . (string)$property->subtype->uk]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->type->uk . ' - ' . (string)$property->subtype->uk], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->type->uk . ' - ' . (string)$property->subtype->uk . ') that is not mapped', $post_id, (string)$property->id );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->type->uk . ' - ' . (string)$property->subtype->uk, $post_id );
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
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_currency', (string)$property->currency );
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = (string)$property->shortterm_low;
					if ( empty($price) && isset($property->shortterm_high) )
					{
						$price = (string)$property->shortterm_high;
					}
					if ( empty($price) && isset($property->longterm) )
					{
						$price = (string)$property->longterm;
					}
					$price = round(preg_replace("/[^0-9.]/", '', $price));

					update_post_meta( $post_id, '_rent', $price );
					update_post_meta( $post_id, '_rent_frequency', 'pcm' );
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_currency', (string)$property->currency );
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
				//update_post_meta( $post_id, '_featured', '' );

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->availability) && isset($mapping[(string)$property->availability]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->availability], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
	            }

		        // Rooms / Descriptions
		        // For now put the whole description in one room / description
				update_post_meta( $post_id, '_rooms', '0' );
				/*update_post_meta( $post_id, '_room_name_0', '' );
	            update_post_meta( $post_id, '_room_dimensions_0', '' );
	            update_post_meta( $post_id, '_room_description_0', '' );*/

	            // Media - Images
			    $media = array();
			    if (isset($property->images) && !empty($property->images))
                {
                    foreach ($property->images as $images)
                    {
                        if (!empty($images->image))
                        {
                            foreach ($images->image as $image)
                            {
								$media_attributes = $image->attributes();
								$filename = $post_id . '_' . $media_attributes['id'] . '.jpg';

								$media[] = array(
									'url' => (string)$image->url,
									'filename' => $filename,
									'modified' => (string)$property->last_updated,
								);
							}
						}
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'photo', $media, true );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_resales_online_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->id, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_resales_online_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->id;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}
	
	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
            ),
            'property_type' => array(
                'Apartment - Ground Floor' => 'Apartment - Ground Floor',
                'Apartment - Middle Floor' => 'Apartment - Middle Floor',
                'Apartment - Penthouse' => 'Apartment - Penthouse',
                'Plot - Land' => 'Plot - Land',
                'Plot - Residential' => 'Plot - Residential',
                'Townhouse - Terraced' => 'Townhouse - Terraced',
                'Villa - Detached' => 'Villa - Detached',
            )
        );
	}
}

}