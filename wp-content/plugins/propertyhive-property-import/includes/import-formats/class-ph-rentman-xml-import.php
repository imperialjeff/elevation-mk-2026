<?php
/**
 * Class for managing the import process of an Apex27 XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Rentman_XML_Import extends PH_Property_Import_Process {

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

	public function parse_and_import()
	{
		$this->properties = array();
		$this->branch_ids_processed = array();

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$local_directory = $import_settings['local_directory'];

		// Now they've all been extracted, get XML files in date order
		$xml_files = array();
		if ($handle = opendir($local_directory)) 
		{
		    while (false !== ($file = readdir($handle))) 
		    {
		        if (
		        	$file != "." && $file != ".." && 
		        	substr(strtolower($file), -3) == 'xml'
		        ) 
		        {
		           $xml_files[filemtime($local_directory . '/' . $file)] = $local_directory . '/' . $file;
		        }
		    }
		    closedir($handle);
		}

		if (!empty($xml_files))
		{
			ksort($xml_files); // sort by date modified

			// We've got at least one XML to process

			$limit = $this->get_property_limit();

            foreach ($xml_files as $mtime => $xml_file)
            {
            	$this->properties = array();
            	$this->branch_ids_processed = array();

            	$parsed = false;

            	// Get XML contents into memory
            	if ( file_exists($xml_file) && filesize($xml_file) > 0 ) 
            	{
					$xml = simplexml_load_file($xml_file);

					if ($xml !== FALSE)
					{
						$this->log("Parsing properties");
						
						foreach ($xml->Properties as $properties)
						{
							foreach ($properties->Property as $property)
							{
				                if ((string)$property->Rentorbuy == 1 || (string)$property->Rentorbuy == 2)
				                {
				                    $this->properties[] = $property;
				                }
				            } // end foreach property
			            } // end foreach properties

	                	// Parsed it succesfully. Ok to continue
	                	if ( empty($this->properties) )
						{
							$this->log_error( 'No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.' );
						}
						else
						{
		                    $this->import();

		                    $this->remove_old_properties();
		                }
	                }
			        else
			        {
			        	// Failed to parse XML
			        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML: ' . print_r($xml, true) );

			        	return false;
			        }
	            }
	            else
	            {
	            	$this->log_error( 'File ' . $xml_file . ' doesn\'t exist or is empty' );
	            }

                $this->archive( $xml_file );
            }
		}
		else
		{
			$this->log_error( 'No XML\'s found to process' );
		}

		$this->clean_up_old_xmls();

        return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

		$local_directory = $import_settings['local_directory'];

        do_action( "propertyhive_pre_import_properties_rentman_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_rentman_xml_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_rentman_xml", $property, $this->import_id, $this->instance_id );
			
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->Refnumber == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->Refnumber );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->Refnumber, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->Refnumber, 0, (string)$property->Refnumber, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = '';
			if ( (string)$property->Street != '' )
			{
				$display_address .= (string)$property->Street;
			}
			if ( (string)$property->Address3 != '' )
			{
				if ( $display_address != '' ) { $display_address .= ', '; }
				$display_address .= (string)$property->Address3;
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->Refnumber, $property, $display_address, (string)$property->Description );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->Refnumber );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->Refnumber );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->Refnumber );
				update_post_meta( $post_id, '_address_name_number', ( ( isset($property->Number) ) ? (string)$property->Number : '' ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property->Street) ) ? (string)$property->Street : '' ) );
				update_post_meta( $post_id, '_address_two', '' );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->Address3) ) ? (string)$property->Address3 : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->Address4) ) ? (string)$property->Address4 : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->Postcode) ) ? (string)$property->Postcode : '' ) );

				$country = 'GB';
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

				// Coordinates
				if ( isset($property->Gloc) && (string)$property->Gloc != '' && count( explode(",", (string)$property->Gloc) ) == 2 )
				{
					$exploded_gloc = explode(",", (string)$property->Gloc);
					update_post_meta( $post_id, '_latitude', trim($exploded_gloc[0]) );
					update_post_meta( $post_id, '_longitude', trim($exploded_gloc[1]) );
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
						$address_to_geocode_osm = array();
						if ( trim($property->Number) != '' ) { $address_to_geocode[] = (string)$property->Number; }
						if ( trim($property->Street) != '' ) { $address_to_geocode[] = (string)$property->Street; }
						if ( trim($property->Address3) != '' ) { $address_to_geocode[] = (string)$property->Address3; }
						if ( trim($property->Address4) != '' ) { $address_to_geocode[] = (string)$property->Address4; }
						if ( trim($property->Postcode) != '' ) { $address_to_geocode[] = (string)$property->Postcode; $address_to_geocode_osm[] = (string)$property->Postcode; }

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->Refnumber, $address_to_geocode, $address_to_geocode_osm, $country );
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
						if ( $branch_code == (string)$property->Branch )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				$department = ( (string)$property->Rentorbuy == 1 ? 'residential-lettings' : 'residential-sales' );

				// Residential Details
				update_post_meta( $post_id, '_department', $department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->Beds) ) ? round((string)$property->Beds) : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->Baths) ) ? round((string)$property->Baths) : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->Receps) ) ? round((string)$property->Receps) : '' ) );

				$mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();
				
				if ( isset($property->Type) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->Type]) )
					{
						wp_set_post_terms( $post_id, $mapping[(string)$property->Type], 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $taxonomy );

						$this->propertyhive_property_import_add_log( 'Property received with a type (' . (string)$property->Type . ') that is not mapped', $post_id, (string)$property->Refnumber );

						$import_settings = $this->add_missing_mapping( $mapping, 'property_type', (string)$property->Type, $post_id );
					}
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, $taxonomy );
				}

				// Residential Sales Details
				if ( (string)$property->Rentorbuy == 2 )
				{
					$price_attributes = $property->Saleprice->attributes();

					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->Saleprice));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', ( ( isset($price_attributes['Qualifier']) && $price_attributes['Qualifier'] == '2' ) ? 'yes' : '') );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($price_attributes['Qualifier']) && isset($mapping[(string)$price_attributes['Qualifier']]) )
					{
		                wp_set_post_terms( $post_id, $mapping[(string)$price_attributes['Qualifier']], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }

		            // Tenure
					$mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();
					
					if ( !empty($mapping) && isset($price_attributes['Ownership']) && isset($mapping[(string)$price_attributes['Ownership']]) )
					{
			            wp_set_post_terms( $post_id, $mapping[(string)$price_attributes['Ownership']], 'tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'tenure' );
		            }
				}
				elseif ( (string)$property->Rentorbuy == 1 )
				{
					$price_attributes = $property->Rent->attributes();

					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->Rent));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					switch ((string)$price_attributes['Period'])
					{
						case "Month": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						case "Week": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					
					update_post_meta( $post_id, '_poa', ( ( isset($price_attributes['Qualifier']) && $price_attributes['Qualifier'] == '2' ) ? 'yes' : '') );

					update_post_meta( $post_id, '_deposit', '' );
					$available_date = '';
					if ( isset($property->Available) && (string)$property->Available != '' )
					{
						$explode_available_date = explode("/", (string)$property->Available);
						if ( count($explode_available_date) == 3 )
						{
							$available_date = $explode_available_date[2] . '-' . $explode_available_date[1] . '-' . $explode_available_date[0];
						}
					}
            		update_post_meta( $post_id, '_available_date', $available_date );

            		// Furnished
					$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();
					
					if ( !empty($mapping) && isset($property->Furnished) && isset($mapping[round((string)$property->Furnished)]) )
					{
		                wp_set_post_terms( $post_id, $mapping[round((string)$property->Furnished)], 'furnished' );
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
					update_post_meta( $post_id, '_featured', ( isset($property->Featured) && strtolower((string)$property->Featured) == 'true' ) ? 'yes' : '' );
				}
			
				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->Status) && isset($mapping[(string)$property->Status]) )
				{
	                wp_set_post_terms( $post_id, $mapping[(string)$property->Status], 'availability' );
	            }

	            // Features
				$features = array();
				if ( isset($property->Bulletpoints->Bulletpoint) && !empty($property->Bulletpoints->Bulletpoint) )
				{
					foreach ( $property->Bulletpoints->Bulletpoint as $bulletpoint )
					{
						$features[] = (string)$bulletpoint;
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
	            if ( (string)$property->Comments != '' )
	            {
	            	update_post_meta( $post_id, '_room_name_' . $num_rooms, '' );
		            update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, '' );
		            update_post_meta( $post_id, '_room_description_' . $num_rooms, (string)$property->Comments );

	            	++$num_rooms;
	            }

	            if ( isset($property->Rooms->Room) && !empty($property->Rooms->Room) )
				{
	            	foreach ( $property->Rooms->Room as $room )
	            	{
	            		update_post_meta( $post_id, '_room_name_' . $num_rooms, (string)$room->Title );
			            update_post_meta( $post_id, '_room_dimensions_' . $num_rooms, '' );
			            update_post_meta( $post_id, '_room_description_' . $num_rooms, (string)$room->Description );

		            	++$num_rooms;
	            	}
	            }

	            update_post_meta( $post_id, '_rooms', $num_rooms );

		        // Media - Images
			    $media = array();
			   	if ( isset($property->Media->Item) && !empty($property->Media->Item) )
				{
	            	foreach ( $property->Media->Item as $image )
	            	{
						$media[] = array(
							'url' => (string)$image,
							'local' => true,
							'local_directory' => $local_directory
						);
					}
				}

				$this->import_media( $post_id, (string)$property->Refnumber, 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if ( isset($property->Floorplan) && (string)$property->Floorplan != '' )
	            {
					$media[] = array(
						'url' => (string)$property->Floorplan,
						'local' => true,
						'local_directory' => $local_directory
					);
				}

				$this->import_media( $post_id, (string)$property->Refnumber, 'floorplan', $media, false );

				// Media - Brochures
			    $media = array();
			    if (isset($property->Brochure) && (string)$property->Brochure != '')
	           	{
					$media[] = array(
						'url' => (string)$property->Brochure,
						'local' => true,
						'local_directory' => $local_directory
					);
				}

				$this->import_media( $post_id, (string)$property->Refnumber, 'brochure', $media, false );

				// Media - EPCs
			    $media = array();
			    if ( isset($property->Epc) && (string)$property->Epc != '')
	            {
					$media[] = array(
						'url' => (string)$property->Epc,
						'local' => true,
						'local_directory' => $local_directory
					);
				}

				$this->import_media( $post_id, (string)$property->Refnumber, 'epc', $media, false );

				// Media - Virtual Tours
				$virtual_tours = array();
				if (isset($property->Evt) && (string)$property->Evt != '')
                {
                	$virtual_tours[] = array(
                		'url' => (string)$property->Evt
                	);
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
                    update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->Refnumber );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_rentman_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->Refnumber, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_rentman_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->Refnumber;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	private function clean_up_old_xmls()
    {
    	$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

    	$local_directory = $import_settings['local_directory'];

    	// Clean up processed .XMLs and unused media older than 7 days old (7 days = 604800 seconds)
		if ($handle = opendir($local_directory)) 
		{
		    while (false !== ($file = readdir($handle))) 
		    {
		        if (
		        	$file != "." && $file != ".." && 
		        	(
		        		substr($file, -9) == 'processed' || 
		        		substr(strtolower($file), -4) == '.jpg' || 
		        		substr(strtolower($file), -4) == '.gif' || 
		        		substr(strtolower($file), -5) == '.jpeg' || 
		        		substr(strtolower($file), -4) == '.png' || 
		        		substr(strtolower($file), -4) == '.bmp' || 
		        		substr(strtolower($file), -4) == '.pdf'
		        	)
		        ) 
		        {
		        	if ( filemtime($local_directory . '/' . $file) !== FALSE && filemtime($local_directory . '/' . $file) < (time() - 604800) )
		        	{
		        		unlink($local_directory . '/' . $file);
		        	}
		        }
		    }
		    closedir($handle);
		}
		else
		{
			$this->log_error( 'Failed to read from directory ' . $local_directory . '. Please ensure the local directory specified exists, is the full server path and is readable.' );
			return false;
		}
	}

    public function archive($xml_file)
    {
    	// Rename to append the date and '.processed' as to not get picked up again. Will be cleaned up every 7 days
    	$new_target_file = $xml_file . '-' . time() .'.processed';
		rename( $xml_file, $new_target_file );
		
		$this->log( "Archived XML. Available for download for 7 days: " . str_replace("/includes/import-formats", "", plugin_dir_url( __FILE__ )) . "/download.php?import_id=" . $this->import_id . "&file=" . base64_encode(basename($new_target_file)));
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Unavailable' => 'Unavailable',
                'Withdrawn' => 'Withdrawn',
                'Valuation' => 'Valuation',
                'For Sale' => 'For Sale',
                'ForSale&ToLet' => 'ForSale&ToLet',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Unavailable' => 'Unavailable',
                'Withdrawn' => 'Withdrawn',
                'Valuation' => 'Valuation',
                'ForSale&ToLet' => 'ForSale&ToLet',
            ),
            'property_type' => array(
                'Detached' => 'Detached',
                'Semi' => 'Semi',
                'Terrace' => 'Terrace',
                'Apartment' => 'Apartment',
                'Flat' => 'Flat',
                'Studio' => 'Studio',
                'Cottage' => 'Cottage',
                'Bungalow' => 'Bungalow',
            ),
            'price_qualifier' => array(
                '1' => 'Asking',
        		'2' => 'Price on application',
        		'3' => 'Guide Price',
        		'4' => 'Offers in excess of',
        		'6' => 'Fixed',
        		'5' => 'Offers in region of',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
                'ShareFreehold' => 'ShareFreehold',
            ),
            'furnished' => array(
                '2' => 'Furnished',
                '3' => 'Unfurnished',
                '4' => 'Part Furnished ',
                '5' => 'Furnished / Unfurnished',
                '1' => 'Unknown',
            ),
        );
	}
}

}