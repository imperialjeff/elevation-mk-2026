<?php
/**
 * TODO: See about only import updated option (don't have a valid sample feed)
 * 
 * Class for managing the import process of an InfoCasa XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_InfoCasa_XML_Import extends PH_Property_Import_Process {

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

		$limit = $this->get_property_limit();

		$api_url = 'https://api.infocasa.com/xmlservice';

		$requests = array(
			'sales' => array(
				'sf' => true,
			),
			'lettings' => array(
				'sf' => false,
			),
		);

		$requests = apply_filters( 'propertyhive_infocasa_xml_requests', $requests );

		foreach ( $requests as $department => $request_details )
		{
			$page = 0;
			$per_page = 200;
			$more_properties = true;

			while ( $more_properties )
			{
				$index = $page * $per_page;

				$this->log("Parsing " . $department . " properties on page " . ( $page + 1 ) );

				$search_request_body = <<<XML
<operation name="SearchProperties">
    <header>
        <lk>{$import_settings['license_key']}</lk>
    </header>
    <input>
		<SearchProperties xmlns="urn:schemas-infocasa-com:client-service:2005-11">
		    <searchProperties>
		        <idre>{$import_settings['idre']}</idre>
		        <ci>{$import_settings['language']}</ci>
		        <sf>{$request_details['sf']}</sf>  <!-- Sale flag: true for sale properties -->
		        <arc>false</arc> <!-- Not archived -->
		        <index>{$index}</index>
		    </searchProperties>
		</SearchProperties>
	</input>
</operation>
XML;

				$search_response = wp_remote_post( 
					$api_url,
					array(
						'body' => $search_request_body,
						'headers' => [ 'Content-Type' => 'application/xml' ],
						'timeout' => 120,
				    )
				);

				if ( !is_wp_error( $search_response ) && is_array( $search_response ) ) 
				{
					$search_contents = $search_response['body'];

					$search_xml = simplexml_load_string( $search_contents );

					if ( $search_xml !== FALSE )
					{
						if ( isset($search_xml->error) && (string)$search_xml->error != '' )
						{
							$this->log_error( 'Error in search response: ' . (string)$search_xml->error );
							return false;
						}

			            if ( isset($search_xml->propertyIds) && isset($search_xml->propertyIds->id) ) 
			            {
				            foreach ( $search_xml->propertyIds->id as $id ) 
				            {
				            	if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
				                {
				                    return true;
				                }

				            	$property_id = (string)$id;

				                $details_request_body = <<<XML
<operation name="GetPropertyDetails">
    <header>
        <lk>{$import_settings['license_key']}</lk>
    </header>
    <input>
		<GetPropertyDetails xmlns="urn:schemas-infocasa-com:client-service:2005-11">
		    <getPropertyDetails>
		        <idre>{$import_settings['idre']}</idre>
		        <ci>{$import_settings['language']}</ci>
		        <pi>{$property_id}</pi> <!-- Property ID -->
		        <arc>false</arc> <!-- Not archived -->
		        <sf>{$request_details['sf']}</sf> <!-- Sale flag: true for sale properties -->
		        <rtg>true</rtg>
		        <rln>true</rln>
		    </getPropertyDetails>
		</GetPropertyDetails>
	</input>
</operation>
XML;

					            $details_response = wp_remote_post( 
									$api_url,
									array(
										'body' => $details_request_body,
										'headers' => [ 'Content-Type' => 'application/xml' ],
										'timeout' => 120,
								    )
								);

					            if ( !is_wp_error( $details_response ) && is_array( $details_response ) ) 
								{
									$details_contents = $details_response['body'];

									$details_xml = simplexml_load_string( $details_contents );

									if ( $details_xml !== FALSE )
									{
										if ( isset($details_xml->error) && (string)$details_xml->error != '' )
										{
											$this->log_error( 'Error in details response: ' . (string)$details_xml->error );
											return false;
										}

										if ( isset($details_xml->propertyDetails->properties->p) )
										{
											$this->properties[] = $details_xml->propertyDetails->properties->p;
										}
										else
										{
											$this->log_error( 'Missing property data: ' . print_r($details_contents, TRUE) );
							        		return false;
										}
									}
							        else
							        {
							        	// Failed to parse XML
							        	$this->log_error( 'Failed to parse details XML file: ' . print_r($details_contents, TRUE) );
							        	return false;
							        }
								}
						        else
						        {
						        	$this->log_error( 'Failed to obtain details XML. Dump of response as follows: ' . print_r($details_response, TRUE) );
						        	return false;
						        }
				            }
				        }
				        else
				        {
				        	$more_properties = false;
				        }
			        }
			        else
			        {
			        	// Failed to parse XML
			        	$this->log_error( 'Failed to parse search XML file: ' . print_r($search_contents, TRUE) );
			        	return false;
			        }
			    }
		        else
		        {
		        	$this->log_error( 'Failed to obtain search XML. Dump of response as follows: ' . print_r($search_response, TRUE) );
		        	return false;
		        }

		        ++$page;
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

	private function get_additional_field_value( $property, $type )
	{
		if ( isset($property->fields) && isset($property->fields->fld) )
		{
			foreach ( $property->fields->fld as $field )
			{
				$field_attributes = $field->attributes();

				if ( isset($field_attributes['tp']) && (string)$field_attributes['tp'] == $type )
				{
					return (string)$field->value;
				}
			}
		}

		return '';
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

        $geocoding_denied = apply_filters( 'propertyhive_infocasa_xml_import_prevent_geocoding', false, $this->import_id );

        do_action( "propertyhive_pre_import_properties_infocasa_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_infocasa_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_infocasa_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->pi == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->pi );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->pi, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->pi, 0, (string)$property->pi, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = $this->get_additional_field_value($property, 'HTMLT');

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->pi, $property, $display_address, $this->get_additional_field_value($property, 'SHD') );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->pi );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->pi );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', $this->get_additional_field_value($property, 'PI') );
				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', '' );
				update_post_meta( $post_id, '_address_two', '' );
				update_post_meta( $post_id, '_address_three', $this->get_additional_field_value($property, 'PRO') );
				update_post_meta( $post_id, '_address_four', '' );
				update_post_meta( $post_id, '_address_postcode', $this->get_additional_field_value($property, 'ZIP') );

				$country = 'ES';
				$currency = 'EUR';
				update_post_meta( $post_id, '_address_country', $country );

				$mapping = isset($import_settings['mappings']['location']) ? $import_settings['mappings']['location'] : array();

            	// Let's just look at address fields to see if we find a match
            	$address_fields_to_check = apply_filters( 'propertyhive_infocasa_xml_address_fields_to_check', array('PRO'), $this->import_id );
				$location_term_ids = array();

				foreach ( $address_fields_to_check as $address_field )
				{
					if ( trim($this->get_additional_field_value($property, $address_field)) != '' ) 
					{
						$term = term_exists( trim($this->get_additional_field_value($property, $address_field)), 'location');
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
				if ( $this->get_additional_field_value($property, 'MAPX') != '' && $this->get_additional_field_value($property, 'MAPY') != '' && $this->get_additional_field_value($property, 'MAPX') != '0' && $this->get_additional_field_value($property, 'MAPY') != '0' )
				{
					update_post_meta( $post_id, '_latitude', $this->get_additional_field_value($property, 'MAPX') );
					update_post_meta( $post_id, '_longitude', $this->get_additional_field_value($property, 'MAPY') );
				}
				else
				{
					// No lat/lng passed. Let's go and get it if none entered
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( !$geocoding_denied && ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' ) )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array();
						if ( $this->get_additional_field_value($property, 'PRO') != '' ) { $address_to_geocode[] = $this->get_additional_field_value($property, 'PRO'); }
						if ( $this->get_additional_field_value($property, 'ZIP') != '' ) { $address_to_geocode[] = $this->get_additional_field_value($property, 'ZIP'); }

						$geocoding_return = $this->do_geocoding_lookup( $post_id, (string)$property->pi, $address_to_geocode, $address_to_geocode, $country );
						if ( $geocoding_return === 'denied' )
						{
							$geocoding_denied = true;
						}
					}
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $this->primary_office_id;
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = ( $this->get_additional_field_value($property, 'FSL') != '1' ? 'residential-lettings' : 'residential-sales' );

				update_post_meta( $post_id, '_department', $department );

				// Is the property portal add on activated
				if (class_exists('PH_Property_Portal'))
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

				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bd) ) ? (string)$property->bd : '' ) );
				update_post_meta( $post_id, '_bathrooms', $this->get_additional_field_value($property, 'BR') );
				update_post_meta( $post_id, '_reception_rooms', $this->get_additional_field_value($property, 'NR') );

				$prefix = '';
				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property->pt) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->pt]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->pt], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->pt . ') that is not mapped', $post_id, (string)$property->pi );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->pt, $post_id );
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
					if ( isset($property->pr) && is_numeric((string)$property->pr) )
					{
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->pr));
					}

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_poa', $this->get_additional_field_value($property, 'POA') === true ? 'yes' : '' );

					update_post_meta( $post_id, '_currency', $currency );
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = '';
					if ( isset($property->pr) && is_numeric((string)$property->pr) )
					{
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->pr));
					}

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					switch ($this->get_additional_field_value($property, 'RPF'))
					{
						//case "month": { $rent_frequency = 'pcm'; break; }
						//case "week": { $rent_frequency = 'pw'; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					
					update_post_meta( $post_id, '_poa', $this->get_additional_field_value($property, 'POA') === true ? 'yes' : '' );

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
				update_post_meta( $post_id, '_featured', $this->get_additional_field_value($property, 'WH') === true ? 'yes' : '' );

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();
				
				if ( !empty($mapping) && $this->get_additional_field_value($property, 'STA') != '' && isset($mapping[$this->get_additional_field_value($property, 'STA')]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[$this->get_additional_field_value($property, 'STA')], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

		        // Rooms / Descriptions
		        // For now put the whole description in one room / description
				update_post_meta( $post_id, '_rooms', '0' );
				if ( $this->get_additional_field_value($property, 'DE') != '' )
				{
					update_post_meta( $post_id, '_room_name_0', '' );
		            update_post_meta( $post_id, '_room_dimensions_0', '' );
		            update_post_meta( $post_id, '_room_description_0', $this->get_additional_field_value($property, 'DE') );
		        }

		        // Media - Images
			    $media = array();
			    if ( isset($property->attachments->at) ) 
				{
        			foreach ( $property->attachments->at as $attachment ) 
        			{
        				$attachment_attributes = $attachment->attributes();

        				if (
							(
								isset($attachment_attributes['tp']) &&
								(string)$attachment_attributes['tp'] == 'image'
							)
							&& 
							(
								isset($attachment_attributes['st']) &&
								(string)$attachment_attributes['st'] == ''
							)
						)
						{
					        $url = 'https://cdn.infocasa.com/property-image/xxlarge_' . $attachment->na;

					        $modified = (string)$attachment->lmd;
							if ( !empty($modified) )
							{
								$dateTime = new DateTime($modified);
								$modified = $dateTime->format('Y-m-d H:i:s');
							}

							$media[] = array(
								'url' => $url,
								'description' => ( ( isset($attachment->ca) && (string)$attachment->ca != '' ) ? (string)$attachment->ca : '' ),
								'modified' => $modified,
							);
						}
					}
				}

				$this->import_media( $post_id, (string)$property->pi, 'photo', $media, true );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_infocasa_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->pi, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_infocasa_xml", $this->import_id );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->pi;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                '2' => 'Available',
                '3' => 'Offer Made',
                '14' => 'Sold',
                '15' => 'Subject To Contract',
            ),
            'lettings_availability' => array(
                '2' => 'Available',
                '3' => 'Offer Made',
                '4' => 'Reserved',
                '9' => 'Empty',
            ),
            'property_type' => array()
        );
	}
}

}