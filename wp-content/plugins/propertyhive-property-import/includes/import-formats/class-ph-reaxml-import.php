<?php
/**
 * Class for managing the import process of a REAXML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_REAXML_Import extends PH_Property_Import_Process {

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

            	// Get XML contents into memory
            	if ( file_exists($xml_file) && filesize($xml_file) > 0 ) 
            	{
					$xml = simplexml_load_file($xml_file);

					if ($xml !== FALSE)
					{
						$this->log("Parsing properties");
						
						if (isset($xml->residential))
			            {
							foreach ($xml->residential as $property)
							{
								$property->addChild('department', 'residential-sales');
				                $this->properties[] = $property;
				            } // end foreach property
				        }

				        if (isset($xml->rental))
			            {
							foreach ($xml->rental as $property)
							{
								$property->addChild('department', 'residential-lettings');
				                $this->properties[] = $property;
				            } // end foreach property
				        }

	                	// Parsed it succesfully. Ok to continue
	                	if ( empty($this->properties) )
						{
							$this->log_error( 'No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.' );
						}
						else
						{
		                    $this->import();
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

		$local_directory = $import_settings['local_directory'];

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_reaxml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_reaxml_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$PH_Countries = new PH_Countries();

		$statuses_imported = apply_filters( 'propertyhive_reaxml_statuses_imported', array('current') );

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
			do_action( "propertyhive_property_importing_reaxml", $property, $this->import_id, $this->instance_id );

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

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->uniqueID, 0, (string)$property->uniqueID, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$property_attributes = $property->attributes();

			if ( !in_array($property_attributes['status'], $statuses_imported) )
			{
				$this->remove_property( (string)$property->uniqueID );

		        ++$property_row;

				continue;
			}

			// From this point forward we will be processing current properties

			$display_address = '';
			if ( (string)$property->address->street != '' )
			{
				$display_address .= (string)$property->address->street;
			}
			if ( (string)$property->address->suburb != '' )
			{
				$suburb_attributes = $property->address->suburb->attributes();
				if ( $suburb_attributes['display'] == 'yes' )
				{
					if ( $display_address != '' ) { $display_address .= ', '; }
					$display_address .= (string)$property->address->suburb;
				}
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->uniqueID, $property, $display_address, (string)$property->headline );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->uniqueID );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->uniqueID );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->uniqueID );
				update_post_meta( $post_id, '_address_name_number', ( ( isset($property->address->streetNumber) ) ? (string)$property->address->streetNumber : '' ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property->address->street) ) ? (string)$property->address->street : '' ) );
				update_post_meta( $post_id, '_address_two', '' );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->address->suburb ) ) ? (string)$property->address->suburb  : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->address->state) ) ? (string)$property->address->state : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->address->postcode) ) ? (string)$property->address->postcode : '' ) );

				$country = 'AU';
				/*if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
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
				}*/
				update_post_meta( $post_id, '_address_country', $country );

				// Coordinates
				$lat = get_post_meta( $post_id, '_latitude', TRUE);
				$lng = get_post_meta( $post_id, '_longitude', TRUE);

				if ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' )
				{
					// No lat lng. Let's get it
					$address_to_geocode = array();
					$address_to_geocode_osm = array();
					if ( isset($property->address->streetNumber) && trim((string)$property->address->streetNumber) != '' ) { $address_to_geocode[] = (string)$property->address->streetNumber; }
					if ( isset($property->address->street) && trim((string)$property->address->street) != '' ) { $address_to_geocode[] = (string)$property->address->street; }
					if ( isset($property->address->suburb) && trim((string)$property->address->suburb) != '' ) { $address_to_geocode[] = (string)$property->address->suburb; }
					if ( isset($property->address->state) && trim((string)$property->address->state) != '' ) { $address_to_geocode[] = (string)$property->address->state; }
					if ( isset($property->address->postcode) && trim((string)$property->address->postcode) != '' ) { $address_to_geocode[] = (string)$property->address->postcode; $address_to_geocode_osm[] = (string)$property->address->postcode; }

					$return = $this->do_geocoding_lookup( $post_id, (string)$property->uniqueID, $address_to_geocode, $address_to_geocode_osm, $country );
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $this->primary_office_id;
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				update_post_meta( $post_id, '_department', (string)$property->department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->features->bedrooms) ) ? (string)$property->features->bedrooms : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->features->bathrooms) ) ? (string)$property->features->bathrooms : '' ) );
				update_post_meta( $post_id, '_reception_rooms', '' );

				$mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();

				$property_type = '';
				$category_attributes = $property->category->attributes();
				if ( isset($category_attributes['name']) )
				{
					$property_type = (string)$category_attributes['name'];
				}

				if ( !empty($mapping) && isset($mapping[$property_type]) )
				{
					wp_set_post_terms( $post_id, $mapping[$property_type], 'property_type' );
				}
				else
				{
					$this->log( 'Property received with a type (' . $property_type . ') that is not mapped', $post_id, (string)$property->uniqueID );

					$import_settings = $this->add_missing_mapping( $mapping, 'property_type', $property_type, $post_id );

					wp_delete_object_term_relationships( $post_id, 'property_type' );
				}

				$default_country = $PH_Countries->get_country( $country );
				$currency_to_insert = $default_country['currency_code'];
				update_post_meta( $post_id, '_currency', $currency_to_insert );

				// Residential Sales Details
				if ( (string)$property->department == 'residential-sales' )
				{
					$price_attributes = $property->price->attributes();

					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', ( ( isset($price_attributes['display']) && $price_attributes['display'] == 'no' ) ? 'yes' : '') );
				}
				elseif ( (string)$property->department == 'residential-lettings' )
				{
					$rent_attributes = $property->rent->attributes();

					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->rent));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					switch ($rent_attributes['period'])
					{
						case "month":
						case "monthly": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						default: { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );
					
					update_post_meta( $post_id, '_poa', ( ( isset($rent_attributes['display']) && $rent_attributes['display'] == 'no' ) ? 'yes' : '') );

					update_post_meta( $post_id, '_deposit', ( ( isset($property->bond) ) ? (string)$property->bond : '' ) );
            		update_post_meta( $post_id, '_available_date', '' ); // TO DO: Fix date available. Received in format 2009-01-26-12:30:00

            		// Furnished
            		$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

					if ( !empty($mapping) && isset($property->allowances->furnished) && isset($mapping[(string)$property->allowances->furnished]) )
					{
		                wp_set_post_terms( $post_id, $mapping[(string)$property->allowances->furnished], 'furnished' );
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
					update_post_meta( $post_id, '_featured', '' );
				}
			
				// Availability
				$mapping = isset($import_settings['mappings']['availability']) ? $import_settings['mappings']['availability'] : array();

        		$availability = '';
        		if ( isset($property->underOffer) )
        		{
        			$underoffer_attributes = $property->underOffer->attributes();
        			if (isset($underoffer_attributes['value']) && strtolower($underoffer_attributes['value']) == 'yes')
        			{
	        			$availability = 'Under Offer';
	        		}
        		}
        		if ( $availability == '' )
        		{
        			if ( (string)$property->department == 'residential-sales' )
        			{
        				$availability = 'For Sale';
        			}
        			elseif ( (string)$property->department == 'residential-lettings' )
        			{
        				$availability = 'To Let';
        			}
        		}
        		if ( $property_attributes['status'] == 'sold' || (isset($property->soldDetails) && isset($property->soldDetails->price)) )
        		{
        			$availability = 'Sold';
        		}
        		
				if ( !empty($mapping) && isset($mapping[$availability]) )
				{
	                wp_set_post_terms( $post_id, $mapping[$availability], 'availability' );
	            }
	            else
	            {
		            $this->log( 'Property received with an availability (' . $availability . ') that is not mapped', $post_id, (string)$property->uniqueID );

		            $import_settings = $this->add_missing_mapping( $mapping, 'availability', $availability, $post_id );
		        }

		        // Rooms
		        // For now put the whole description in one room
				update_post_meta( $post_id, '_rooms', '1' );
				update_post_meta( $post_id, '_room_name_0', '' );
	            update_post_meta( $post_id, '_room_dimensions_0', '' );
	            update_post_meta( $post_id, '_room_description_0', (string)$property->description );

	            // Media - Images
	            $media = array();
				if (isset($property->images) && !empty($property->images))
                {
                	foreach ($property->images as $images)
                    {
                    	if (isset($images->img))
	                    {
                            foreach ($images->img as $image)
                            {
                            	$image_attributes = $image->attributes();

                            	if ( 
									(isset($image_attributes['url']) &&
									trim((string)$image_attributes['url']) != '')
								)
                            	{
		                			$media[] = array(
										'url' => (string)$image_attributes['url'],
										'modified' => (string)$image_attributes['modTime'],
									);
		                		}
		                		else
		                		{
		                			if ( 
										(isset($image_attributes['file']) &&
										trim((string)$image_attributes['file']) != '')
									)
	                            	{
			                			$media[] = array(
											'url' => (string)$image_attributes['file'],
											'local' => true,
											'local_directory' => $local_directory,
											'modified' => (string)$image_attributes['modTime'],
										);
			                		}
		                		}
                			}
                		}
                	}
                }
                if ( empty($property_images) )
                {
	                if (isset($property->objects) && !empty($property->objects))
	                {
	                	foreach ($property->objects as $images)
	                    {
	                    	if (isset($images->img))
	                        {
	                            foreach ($images->img as $image)
	                            {
	                            	$image_attributes = $image->attributes();

		                			if ( 
										(isset($image_attributes['url']) &&
										trim((string)$image_attributes['url']) != '')
									)
	                            	{
			                			$media[] = array(
											'url' => (string)$image_attributes['url'],
											'modified' => (string)$image_attributes['modTime'],
										);
			                		}
			                		else
			                		{
			                			if ( 
											(isset($image_attributes['file']) &&
											trim((string)$image_attributes['file']) != '')
										)
		                            	{
				                			$media[] = array(
												'url' => (string)$image_attributes['file'],
												'local' => true,
												'local_directory' => $local_directory,
												'modified' => (string)$image_attributes['modTime'],
											);
				                		}
			                		}
	                			}
	                		}
	                	}
	                }
	            }

	            $this->import_media( $post_id, (string)$property->uniqueID, 'photo', $media, true );

				// Media - Floorplans
				$media = array();
                if (isset($property->objects) && !empty($property->objects))
                {
                	foreach ($property->objects as $images)
                    {
                    	if (isset($images->floorplan))
                        {
                            foreach ($images->floorplan as $floorplan)
                            {
                            	$floorplan_attributes = $floorplan->attributes();

                            	if ( 
									(isset($floorplan_attributes['url']) &&
									trim((string)$floorplan_attributes['url']) != '')
								)
                            	{
		                			$media[] = array(
										'url' => (string)$floorplan_attributes['url'],
										'modified' => (string)$floorplan_attributes['modTime'],
									);
		                		}
		                		else
		                		{
		                			if ( 
										(isset($floorplan_attributes['file']) &&
										trim((string)$floorplan_attributes['file']) != '')
									)
	                            	{
			                			$media[] = array(
											'url' => (string)$floorplan_attributes['file'],
											'local' => true,
											'local_directory' => $local_directory,
											'modified' => (string)$floorplan_attributes['modTime'],
										);
			                		}
		                		}
                			}
                		}
                	}
                }

                $this->import_media( $post_id, (string)$property->Refnumber, 'floorplan', $media, true );

				// Media - Virtual Tours
				$virtual_tours = array();
				if (isset($property->videoLink))
                {
                	$video_link_attributes = $property->videoLink->attributes();
                	if ( isset($video_link_attributes['href']) && $video_link_attributes['href'] != '' )
                	{
	                    $virtual_tours[] = $video_link_attributes['href'];
	                }
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->uniqueID );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_reaxml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->uniqueID, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;
			
		} // end foreach property

		do_action( "propertyhive_post_import_properties_reaxml" );

		$this->import_end();
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
                'For Sale' => 'For Sale',
                'Under Offer' => 'Under Offer',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'To Let' => 'To Let',
                'Under Offer' => 'Under Offer',
            ),
            'property_type' => array(
                'House' => 'House',
                'Unit' => 'Unit',
                'Townhouse' => 'Townhouse',
                'Villa' => 'Villa',
                'Apartment' => 'Apartment',
                'Flat' => 'Flat',
                'Studio' => 'Studio',
                'Warehouse' => 'Warehouse',
                'DuplexSemi-detached' => 'DuplexSemi-detached',
                'Alpine' => 'Alpine',
                'AcreageSemi-rural' => 'AcreageSemi-rural',
                'BlockOfUnits' => 'BlockOfUnits',
                'Terrace' => 'Terrace',
                'Retirement' => 'Retirement',
                'ServicedApartment' => 'ServicedApartment',
                'Other' => 'Other',
            ),
            'furnished' => array(
                '1' => '1',
            	'yes' => 'yes',
            	'true' => 'true',
            ),
        );
	}

}

}