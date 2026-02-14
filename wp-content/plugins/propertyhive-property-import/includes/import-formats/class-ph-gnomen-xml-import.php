<?php
/**
 * Class for managing the import process of a Gnomen XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Gnomen_XML_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['url'] ) ) 
			{
			    $import_settings['url'] = sanitize_url( wp_unslash( $_POST['url'] ) );
			}
		}

		$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 120 ) );

		if ( is_wp_error($response) ) 
		{
			$this->log_error( 'Failed to obtain properties XML file. ' . $response->get_error_message() );
	        return false;
		}

		if ( wp_remote_retrieve_response_code($response) !== 200 )
        {
            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
            return false;
        }

		$body = $response['body']; 
	
		$xml_string = $body;
		if ( substr($xml_string, 0, 5) != '<?xml' )
		{
			$xml_string = '<?xml version="1.0" encoding="utf-8"?>' . $xml_string;
		}

		libxml_use_internal_errors(true); // Enable internal error handling
		$xml = simplexml_load_string( $xml_string );
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

				if ( strpos($import_settings['url'], '?key=') !== FALSE )
				{
					// This feed has an API key which I think means it uses a format whereby we don't need to go off and get the full details

					$property->area = $property->town;
					$property->description = $property->short_description;
					$property->beds = $property->bedrooms;
					$property->baths = $property->bathrooms;
					$property->base_price = $property->price;
					$property->full_description = $property->full_details;
					$property->videotour = $property->video_tour;

					$property->branch_name = '';
					$property->tenure = '';

					if ( strtolower((string)$property->category) == 'commercial' )
					{
						$property->category = apply_filters( 'propertyhive_gnomen_commercial_category_id', 2 );
					}

                	$this->properties[] = $property;
				}
				else
				{
					$explode_url = explode("/", $import_settings['url']);
					if ( $explode_url[count($explode_url)-1] == 'xml-feed' )
					{
						$url = trim($import_settings['url'], '/') . '-details~action=detail,pid=' . (string)$property->id;
					}
					else
					{
						$url = trim($import_settings['url'], '/') . '/xml-feed-details~action=detail,pid=' . (string)$property->id;
					}

					$response = wp_remote_get( $url, array( 'timeout' => 120 ) );

					if ( is_wp_error($response) ) 
					{
						$this->log_error( 'Failed to obtain property (id: ' . (string)$property->id . ', url: ' . $url . ') XML file. ' . $response->get_error_message() );
				        return false;
					}

					if ( is_array( $response ) ) 
					{
						$body = $response['body']; 
					
						$xml_string = $body;
						if ( substr($xml_string, 0, 5) != '<?xml' )
						{
							$xml_string = '<?xml version="1.0" encoding="utf-8"?>' . $xml_string;
						}
						
						$property_xml = simplexml_load_string( $xml_string );

						if ($property_xml !== FALSE)
						{
							$property_xml->addChild( 'latitude' );
							$property_xml->latitude = ( isset($property->latitude) ? (string)$property->latitude : '' );
							$property_xml->addChild( 'longitude' );
							$property_xml->longitude = ( isset($property->longitude) ? (string)$property->longitude : '' );
							$property_xml->addChild( 'videotour' );
							$property_xml->videotour = ( isset($property->videotour) ? (string)$property->videotour : '' );
							$property_xml->addChild( 'virtualtour' );
							$property_xml->virtualtour = ( isset($property->virtualtour) ? (string)$property->virtualtour : '' );
							$property_xml->addChild( 'epc' );
							$property_xml->epc = ( isset($property->epc) ? (string)$property->epc : '' );
							$property_xml->addChild( 'brochure' );
							$property_xml->brochure = ( isset($property->brochure) ? (string)$property->brochure : '' );

		                	$this->properties[] = $property_xml;
		                }
		                else
				        {
				        	// Failed to parse XML
				        	$this->log_error( 'Failed to parse property (id: ' . (string)$property->id . ', url: ' . $url . ') XML file. Possibly invalid XML' );
				        	return false;
				        }
			        }
			        else
			        {
			        	$this->log_error( 'Failed to obtain property (id: ' . (string)$property->id . ', url: ' . $url . ') XML file.' );
				        return false;
			        }
			    }
            } // end foreach property
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse properties XML file. Possibly invalid XML' );
        	foreach (libxml_get_errors() as $error) {
		        $this->log_error( 'Libxml error: '. $error->message);
		    }
        	return false;
        }
        libxml_clear_errors();

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

        do_action( "propertyhive_pre_import_properties_gnomen_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_gnomen_xml_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties', $additional_message );

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_gnomen_xml", $property, $this->import_id, $this->instance_id );
            
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

			$display_address = isset($property->address1) ? trim((string)$property->address1) : '';
			if ( isset($property->address2) && trim((string)$property->address2) != '' )
			{
				if ( trim($display_address) != '' )
				{
					$display_address .= ', ';
				}
				$display_address .= (string)$property->address2;
			}
			elseif ( isset($property->area) && trim((string)$property->area) != '' )
			{
				if ( trim($display_address) != '' )
				{
					$display_address .= ', ';
				}
				$display_address .= (string)$property->area;
			}
			elseif ( isset($property->property_area) && trim((string)$property->property_area) != '' )
			{
				if ( trim($display_address) != '' )
				{
					$display_address .= ', ';
				}
				$display_address .= (string)$property->property_area;
			}
			$display_address = trim($display_address);

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, $display_address, (string)$property->description, '', (string)$property->date_added );

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
				update_post_meta( $post_id, '_reference_number', (string)$property->reference );

				update_post_meta( $post_id, '_address_name_number', trim(( isset($property->property_no) ? (string)$property->property_no : '' ) . ' ' . ( isset($property->property_name) ? (string)$property->property_name : '' )) );
				update_post_meta( $post_id, '_address_street', ( isset($property->address1) ? (string)$property->address1 : '' ) );
				update_post_meta( $post_id, '_address_two', ( isset($property->address2) ? (string)$property->address2 : '' ) );
				update_post_meta( $post_id, '_address_three', ( isset($property->area) ? (string)$property->area : ( isset($property->property_area) ? (string)$property->property_area : '' ) ) );
				update_post_meta( $post_id, '_address_four', ( isset($property->county) ? (string)$property->county : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( isset($property->postcode) ? (string)$property->postcode : '' ) );

				$country = 'GB';
				update_post_meta( $post_id, '_address_country', $country );

				// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				$address_fields_to_check = apply_filters( 'propertyhive_gnomen_xml_address_fields_to_check', array('address2', 'area', 'property_area', 'county') );
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
					update_post_meta( $post_id, '_latitude', trim((string)$property->latitude) );
					update_post_meta( $post_id, '_longitude', trim((string)$property->longitude) );
				}
				else
				{
					// No lat/lng passed. Let's go and get it if none entered
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array((string)$property->postcode);
						$address_to_geocode_osm = array((string)$property->postcode);

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->id, $address_to_geocode, $address_to_geocode_osm, $country );
					}
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				$negotiator_id = get_current_user_id();
				// Check if negotiator exists with this name
				if ( isset($property->agent_name) )
				{
					foreach ( $this->negotiators as $negotiator_key => $negotiator )
					{
						if ( strtolower(trim($negotiator['display_name'])) == strtolower(trim( (string)$property->agent_name )) )
						{
							$negotiator_id = $negotiator_key;
						}
					}
				}
				update_post_meta( $post_id, '_negotiator_id', $negotiator_id );
					
				$office_id = $this->primary_office_id;
				if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
				{
					foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
					{
						if ( $branch_code == (string)$property->branch_name )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
	            $department = ( ( isset($property->transaction) && (string)$property->transaction == 'Sale' ) ? 'residential-sales' : 'residential-lettings' );
	            if ( isset($property->category) && (string)$property->category == apply_filters( 'propertyhive_gnomen_commercial_category_id', 2 ) )
	            {
	            	$department = 'commercial';
	            }


	            // Is the property portal add on activated
				if (class_exists('PH_Property_Portal'))
        		{
					// Use the branch code to map this property to the correct agent and branch
					$explode_agent_branch = array();
					if (
						isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_name . '|' . $this->import_id]) &&
						$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_name . '|' . $this->import_id] != ''
					)
					{
						// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
						$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_name . '|' . $this->import_id]);
					}
					elseif (
						isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_name]) &&
						$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_name] != ''
					)
					{
						// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
						$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch_name]);
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

	            update_post_meta( $post_id, '_department', $department );

				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->beds) ) ? round((int)$property->beds) : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->baths) ) ? round((int)$property->baths) : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->receptions) ) ? round((int)$property->receptions) : '' ) );

				$prefix = '';
				if ( $department == 'commercial' )
				{
					$prefix = 'commercial_';
				}
				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property->property_type) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->property_type]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->property_type], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->property_type . ') that is not mapped', $post_id, (string)$property->id );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->property_type, $post_id );
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
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->base_price));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_currency', 'GBP' );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($property->price_qualifier) && isset($mapping[(string)$property->price_qualifier]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->price_qualifier], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }

		            // Tenure
		            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

					if ( !empty($mapping) && isset($property->tenure) && isset($mapping[(string)$property->tenure]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->tenure], 'tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'tenure' );
		            }
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->base_price));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = isset($property->frequency) ? strtolower((string)$property->frequency) : 'pcm';
					$price_actual = $price;
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );

					update_post_meta( $post_id, '_currency', 'GBP' );
					
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_deposit', '');
					$available_date = '';
					if ( isset($property->available_date) && (string)$property->available_date != '' )
					{
						$available_date = (string)$property->available_date;
					}
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

            		if ( isset($property->transaction) && (string)$property->transaction == 'Sale' )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->base_price));
	                    update_post_meta( $post_id, '_price_from', $price );
	                    update_post_meta( $post_id, '_price_to', $price );

	                    update_post_meta( $post_id, '_price_units', '' );

	                    update_post_meta( $post_id, '_price_poa', '' );

	                    // Tenure
			            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

						if ( !empty($mapping) && isset($property->tenure) && isset($mapping[(string)$property->tenure]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->tenure], 'commercial_tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
			            }
	                }

	                if ( isset($property->transaction) && (string)$property->transaction == 'Let' )
	                {
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	                    $rent = round(preg_replace("/[^0-9.]/", '', (string)$property->base_price));
	                    update_post_meta( $post_id, '_rent_from', $rent );
	                    update_post_meta( $post_id, '_rent_to', $rent );

	                    $rent_frequency = isset($property->frequency) ? strtolower((string)$property->frequency) : 'pcm';
	                    update_post_meta( $post_id, '_rent_units', $rent_frequency);

	                    update_post_meta( $post_id, '_rent_poa', '' );
	                }

	                // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

		            $size = '';
		            update_post_meta( $post_id, '_floor_area_from', $size );
		            update_post_meta( $post_id, '_floor_area_from_sqft', $size );
		            update_post_meta( $post_id, '_floor_area_to', $size );
		            update_post_meta( $post_id, '_floor_area_to_sqft', $size );
		            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

		            $size = '';
		            update_post_meta( $post_id, '_site_area_from', $size );
		            update_post_meta( $post_id, '_site_area_from_sqft', $size );
		            update_post_meta( $post_id, '_site_area_to', $size );
		            update_post_meta( $post_id, '_site_area_to_sqft', $size );
		            update_post_meta( $post_id, '_site_area_units', 'sqft' );
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
					if ( isset($property->featured) && strtolower((string)$property->featured) == 'yes' ) { update_post_meta( $post_id, '_featured', 'yes' ); }
				}

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();
				$mapping = array_change_key_case($mapping, CASE_LOWER);

				if ( !empty($mapping) && isset($property->status) && isset($mapping[strtolower((string)$property->status)]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[strtolower((string)$property->status)], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

	            // Features
				$features = array();
				if ( isset($property->features->feature) && !empty($property->features->feature) )
				{
					foreach ( $property->features->feature as $feature )
					{
						$features[] = (string)$feature;
					}
				}
				elseif ( isset($property->features) && !empty($property->features) )
				{
					$features = explode( ",", (string)$property->features );
				}
				$features = array_filter($features);

				update_post_meta( $post_id, '_features', count( $features ) );
        		
        		$i = 0;
		        foreach ( $features as $feature )
		        {
		            update_post_meta( $post_id, '_feature_' . $i, $feature );
		            ++$i;
		        }

		        // Rooms
	            $num_rooms = 0;
	            if ( isset($property->full_description) && (string)$property->full_description != '' )
	            {
	            	update_post_meta( $post_id, '_room_name_' . $num_rooms, '' );
		            update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, '' );
		            update_post_meta( $post_id, '_room_description_' . $num_rooms, html_entity_decode(html_entity_decode((string)$property->full_description)) );

	            	++$num_rooms;
	            }

	            update_post_meta( $post_id, '_rooms', $num_rooms );

	            // Media - Images
			    $media = array();
			    if (isset($property->images->image) && !empty($property->images->image))
                {
                    foreach ($property->images->image as $image)
                    {
						$media[] = array(
							'url' => (string)$image,
						);
					}
				}
				if (isset($property->property_images->image) && !empty($property->property_images->image))
                {
                    foreach ($property->property_images->image as $image)
                    {
						$media[] = array(
							'url' => (string)$image,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if (isset($property->floorplans->floorplan) && !empty($property->floorplans->floorplan))
                {
                    foreach ($property->floorplans->floorplan as $floorplan)
                    {
                    	$url = (string)$floorplan;

						$media[] = array(
							'url' => $url,
							'filename' => basename( $url ) . '.jpg'
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, false );

				// Media - Brochures
			    $media = array();
			    if (isset($property->brochure) && !empty($property->brochure))
                {
                    foreach ($property->brochure as $brochure)
                    {
                    	$url = (string)$brochure;

						$media[] = array(
							'url' => (string)$brochure,
						);
					}
				}
				if (isset($property->property_brochure) && !empty($property->property_brochure))
                {
                    foreach ($property->property_brochure as $brochure)
                    { 
						if ( isset($brochure->url) )
						{
							$url = (string)$brochure->url;

							$media[] = array(
								'url' => $url,
								'filename' => basename( $url ) . '.pdf',
							);
						}
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'brochure', $media, false );

				// Media - EPCs
			    $media = array();
			    if (isset($property->epc) && !empty($property->epc))
                {
                    foreach ($property->epc as $epc)
                    {
						$media[] = array(
							'url' => (string)$epc,
						);
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'epc', $media, false );

				// Media - Virtual Tours
				$virtual_tours = array();
				if ( isset($property->videotour) && (string)$property->videotour != '' )
                {
                    $virtual_tours[] = (string)$property->videotour;
                }
                if ( isset($property->virtualtour) && (string)$property->virtualtour != '' )
                {
                    $virtual_tours[] = (string)$property->virtualtour;
                }
                if ( isset($property->vtour) && (string)$property->vtour != '' )
                {
                    $virtual_tours[] = (string)$property->vtour;
                }
                if ( isset($property->virtual_tour) && trim((string)$property->virtual_tour) != '' )
                {
                    $virtual_tours[] = trim((string)$property->virtual_tour);
                }
                if ( isset($property->external_vtour) && trim((string)$property->external_vtour) != '' )
                {
                    $virtual_tours[] = trim((string)$property->external_vtour);
                }
                if ( isset($property->external_vtour2) && trim((string)$property->external_vtour2) != '' )
                {
                    $virtual_tours[] = trim((string)$property->external_vtour2);
                }
                
                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->id );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_gnomen_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_gnomen_xml" );

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
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
                'Sold Subject to Contract' => 'Sold Subject to Contract',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
                'Under Offer' => 'Under Offer',
                'Let Agreed' => 'Let Agreed',
            ),
            'commercial_availability' => array(
                'For Sale' => 'For Sale',
                'To Let' => 'To Let',
                'Sold Subject to Contract' => 'Sold Subject to Contract',
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
            'commercial_property_type' => array(
                'Office' => 'Office',
                'Warehouse conversion' => 'Warehouse conversion'
            ),
            'price_qualifier' => array(
                'Offers over' => 'Offers over',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'commercial_tenure' => array(
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