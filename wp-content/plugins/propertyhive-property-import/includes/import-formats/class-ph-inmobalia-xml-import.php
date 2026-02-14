<?php
/**
 * Class for managing the import process of a Inmobalia XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Inmobalia_XML_Import extends PH_Property_Import_Process {

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
			    $import_settings['url'] = sanitize_url( wp_unslash( $_POST['url'] ) );
			}
		}

		$contents = '';

		$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 120 ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		if ( wp_remote_retrieve_response_code($response) !== 200 )
        {
            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
            return false;
        }

		$xml = simplexml_load_string($contents);

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			
			foreach ($xml->property as $property)
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

        do_action( "propertyhive_pre_import_properties_inmobalia_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_inmobalia_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_inmobalia_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->reference == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->reference );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->reference, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->reference, 0, (string)$property->reference, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = '';

			$summary_description = $property->xpath('descriptions/short_description[@lang="en"]');

			if ( $summary_description )
			{
		        $display_address = (string)$summary_description[0];
		    }

		    $full_description = '';
		    $full_description_xpath = $property->xpath('descriptions/long_description[@lang="en"]');

			if ( $full_description_xpath )
			{
		        $full_description = (string)$full_description_xpath[0];
		    }

			if ( $display_address == '' )
			{
				$display_address = array();

				if ( isset($property->subarea) && (string)$property->subarea != '' )
				{
					$display_address[] = (string)$property->subarea;
				}
				if ( isset($property->area) && (string)$property->area != '' )
				{
					$display_address[] = (string)$property->area;
				}
				if ( isset($property->city) && (string)$property->city != '' )
				{
					$display_address[] = (string)$property->city;
				}
				if ( isset($property->province) && (string)$property->province != '' )
				{
					$display_address[] = (string)$property->province;
				}

				$display_address = array_unique($display_address);

				$display_address = implode(", ", $display_address);
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->reference, $property, $display_address, (string)$summary_description[0] );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->reference );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->reference );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_update_date = get_post_meta( $post_id, '_inmobalia_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property->dt_last_updated) ||
						(
							isset($property->dt_last_updated) &&
							trim((string)$property->dt_last_updated) == ''
						) ||
						$previous_update_date == '' ||
						(
							isset($property->dt_last_updated) &&
							(string)$property->dt_last_updated != '' &&
							$previous_update_date != '' &&
							strtotime((string)$property->dt_last_updated) > strtotime($previous_update_date)
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
					update_post_meta( $post_id, '_reference_number', (string)$property->client_ref_num );
					update_post_meta( $post_id, '_address_name_number', '' );
					update_post_meta( $post_id, '_address_street', '' );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->area ) ) ? (string)$property->area  : ( ( isset($property->subarea ) ) ? (string)$property->subarea  : '' ) ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->city ) ) ? (string)$property->city  : ( ( isset($property->cityname ) ) ? (string)$property->cityname  : '' ) ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->province) ) ? (string)$property->province : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

					$country = 'GB';
					$currency = 'GBP';
					if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower((string)$property->country) == strtolower($country_details['name']) )
							{
								$country = $country_code;
								$currency = $country_details['currency_code'];
								break;
							}
						}
					}
					else
					{
						$country = get_option( 'propertyhive_default_country', 'GB' );
					}
					update_post_meta( $post_id, '_address_country', $country );
					
	            	// Let's just look at address fields to see if we find a match
	            	$address_fields_to_check = apply_filters( 'propertyhive_inmobalia_xml_address_fields_to_check', array('subarea', 'area', 'city', 'province'), $this->import_id );
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
					if ( isset($property->latitude) && isset($property->longitude) && (string)$property->latitude != '' && (string)$property->longitude != '' && (string)$property->latitude != '0' && (string)$property->longitude != '0' )
					{
						update_post_meta( $post_id, '_latitude', ( ( isset($property->latitude) ) ? (string)$property->latitude : '' ) );
						update_post_meta( $post_id, '_longitude', ( ( isset($property->longitude) ) ? (string)$property->longitude : '' ) );
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
							if ( isset($property->subarea) && trim((string)$property->subarea) != '' ) { $address_to_geocode[] = (string)$property->subarea; }
							if ( isset($property->area) && trim((string)$property->area) != '' ) { $address_to_geocode[] = (string)$property->area; }
							if ( isset($property->city) && trim((string)$property->city) != '' ) { $address_to_geocode[] = (string)$property->city; }
							if ( isset($property->province) && trim((string)$property->province) != '' ) { $address_to_geocode[] = (string)$property->province; }

							$return = $this->do_geocoding_lookup( $post_id, (string)$property->reference, $address_to_geocode, $address_to_geocode, $country );
						}
					}

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
						
					$office_id = $this->primary_office_id;
					update_post_meta( $post_id, '_office_id', $office_id );

					// Residential Details
					$department = 'residential-sales';
					if ( 
						(
							isset($property->is_for_rent) && 
							(string)$property->is_for_rent == '1'
						)
						||
						(
							isset($property->is_rented) && 
							(string)$property->is_rented == '1'
						)
					)
					{
						$department = 'residential-lettings';
					}

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

					update_post_meta( $post_id, '_bedrooms', ( ( isset($property->nr_bedrooms) ) ? (string)$property->nr_bedrooms : '' ) );
					update_post_meta( $post_id, '_bathrooms', ( ( isset($property->nr_bathrooms) ) ? (string)$property->nr_bathrooms : '' ) );
					update_post_meta( $post_id, '_reception_rooms', '' );

					$prefix = '';
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property->propertytype) )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->propertytype]) )
						{
							wp_set_object_terms( $post_id, (int)$mapping[(string)$property->propertytype], $prefix . 'property_type' );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . (string)$property->propertytype . ') that is not mapped', $post_id, (string)$property->reference );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->propertytype, $post_id );
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
						if ( (string)$property->selling_price_eur != '' ) {
							$price = round(preg_replace("/[^0-9.]/", '', (string)$property->selling_price_eur));
						}

						update_post_meta( $post_id, '_price', $price );
						update_post_meta( $post_id, '_poa', '' );

						/*if ( isset($property->currency) && (string)$property->currency != '' )
						{
							$currency = (string)$property->currency;
						}*/
						update_post_meta( $post_id, '_currency', $currency );
					}
					elseif ( $department == 'residential-lettings' )
					{
						// Clean price
						$price = preg_replace("/[^0-9.]/", '', (string)$property->rental_price_eur);
						$rent_frequency = 'pcm';
						if ( $price != '' )
						{
							if ( strpos(strtolower((string)$property->rental_price_comment), 'week') )
							{
								$rent_frequency = 'pw';
							}
						}
						else
						{
							$price = preg_replace("/[^0-9.]/", '', (string)$property->rental_price_eur_long);
							if ( $price != '' )
							{
								if ( strpos(strtolower((string)$property->rental_price_comment_long), 'week') )
								{
									$rent_frequency = 'pw';
								}
							}
						}

						update_post_meta( $post_id, '_rent', $price );

						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
						
						update_post_meta( $post_id, '_poa', '' );

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
						update_post_meta( $post_id, '_featured', ( ( isset($property->is_featured) && (string)$property->is_featured == '1' ) ? 'yes' : '' ) );
					}
				
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

					$availability = '';
					if ( $department == 'residential-sales' )
					{
						if ( isset($property->is_for_sale) && (string)$property->is_for_sale == '1' )
						{
							$availability = 'For Sale';
						}
						if ( isset($property->is_under_offer) && (string)$property->is_under_offer == '1' )
						{
							$availability = 'Under Offer';
						}
						if ( isset($property->is_sold) && (string)$property->is_sold == '1' )
						{
							$availability = 'Sold';
						}
					}
					else
					{
						if ( isset($property->is_for_rent) && (string)$property->is_for_rent == '1' )
						{
							$availability = 'For Rent';
						}
						if ( isset($property->is_rented) && (string)$property->is_rented == '1' )
						{
							$availability = 'Rented';
						}
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
					if ( isset($property->options) && !empty($property->options) )
					{
						foreach ( $property->options->option as $feature )
						{
							$features[] = trim((string)$feature);
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
				    if (isset($property->images) && !empty($property->images))
	                {
	                    foreach ($property->images as $images)
	                    {
	                        if (!empty($images->image))
	                        {
	                            foreach ($images->image as $image)
	                            {
	                            	$image_attributes = $image->attributes();

									$url = trim((string)$image_attributes['url']);

									$explode_url = explode("?", $url);
									$filename = basename( $explode_url[0] );

									$media[] = array(
										'url' => $url,
										'filename' => $filename,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->reference, 'photo', $media, false );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->reference );
				}

				$update_date = '';
				if ( isset($property->dt_last_updated) && (string)$property->dt_last_updated != '' )
				{
					$update_date = (string)$property->dt_last_updated;
				}
				update_post_meta( $post_id, '_inmobalia_update_date_' . $this->import_id, $update_date );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_inmobalia_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->reference, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_inmobalia_xml", $this->import_id );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->reference;
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