<?php
/**
 * Class for managing the import process of an EstatesIT XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_EstatesIT_XML_Import extends PH_Property_Import_Process {

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

		$local_directory = isset($import_settings['local_directory']) ? $import_settings['local_directory'] : '';

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

            foreach ($xml_files as $mtime => $xml_file)
            {
            	$this->properties = array();
            	$this->branch_ids_processed = array();

            	$this->log("Parsing properties");

            	$parsed = false;

            	// Get XML contents into memory
            	if ( file_exists($xml_file) && filesize($xml_file) > 0 ) 
            	{
			        $xml = simplexml_load_file( $xml_file );

					if ($xml !== FALSE)
					{
						$limit = $this->get_property_limit();
						
			            foreach ($xml->property as $property)
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    break;
			                }

							if ( isset($property->market) && ( (string)$property->market == 1 || (string)$property->market == 2 ) )
							{
				                $this->properties[] = $property;
				            }
			            } // end foreach property
			        }
			        else
			        {
			        	// Failed to parse XML
			        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML' );

			        	return false;
			        }

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
	            	$this->log_error( 'File doesn\'t exist or is empty' );
	            }

                $this->archive( $xml_file );
            }
		}
		else
		{
			$this->log_error( 'No XML files found to process' );
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

        do_action( "propertyhive_pre_import_properties_estatesit_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_estatesit_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_estatesit_xml", $property, $this->import_id, $this->instance_id );
            
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->propcode == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->propcode );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property->propcode, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property->propcode, 0, (string)$property->propcode, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = (string)$property->address3;
            if ( (string)$property->address4 != '' )
            {
                if ($display_address != '')
                {
                    $display_address .= ', ';
                }
                $display_address .= (string)$property->address4;
            }
            if ( (string)$property->address5 != '' )
            {
                if ($display_address != '')
                {
                    $display_address .= ', ';
                }
                $display_address .= (string)$property->address5;
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->propcode, $property, $display_address, (string)$property->description );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->propcode );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->propcode );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->propcode );
				update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property->address0) ) ? (string)$property->address0 : '' ) . ' ' . ( ( isset($property->address1) ) ? (string)$property->address1 : '' ) . ' ' . ( ( isset($property->address2) ) ? (string)$property->address2 : '' ) ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property->address3) ) ? (string)$property->address3 : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property->address4) ) ? (string)$property->address4 : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->address5) ) ? (string)$property->address5 : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->address6) ) ? (string)$property->address6 : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

				$country = 'GB';
				update_post_meta( $post_id, '_address_country', $country );

            	$address_fields_to_check = apply_filters( 'propertyhive_estatesit_xml_address_fields_to_check', array('address4', 'address5', 'address6') );
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

				// Coordinates
				if ( isset($property->gcodey) && isset($property->gcodex) && (string)$property->gcodey != '' && (string)$property->gcodex != '' && (string)$property->gcodey != '0' && (string)$property->gcodex != '0' )
				{
					update_post_meta( $post_id, '_latitude', ( ( isset($property->gcodey) ) ? (string)$property->gcodey : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property->gcodex) ) ? (string)$property->gcodex : '' ) );
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
						if ( trim($property->address3) != '' ) { $address_to_geocode[] = (string)$property->address3; }
						if ( trim($property->address4) != '' ) { $address_to_geocode[] = (string)$property->address4; }
						if ( trim($property->address5) != '' ) { $address_to_geocode[] = (string)$property->address5; }
						if ( trim($property->address6) != '' ) { $address_to_geocode[] = (string)$property->address6; }
						if ( trim($property->postcode) != '' ) { $address_to_geocode[] = (string)$property->postcode; $address_to_geocode_osm[] = (string)$property->postcode; }

						$return = $this->do_geocoding_lookup( $post_id, (string)$property->propcode, $address_to_geocode, $address_to_geocode_osm, $country );
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
						if ( $branch_code == (string)$property->branch )
						{
							$office_id = $ph_office_id;
							break;
						}
					}
				}
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-sales';
				if ( (string)$property->pricetype != '1' )
				{
					$department = 'residential-lettings';
				}
				if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' )
                {
					if ( (string)$property->market == '2' )
					{
						$department = 'commercial';
					}
				}

				// Is the property portal add on activated
				if (class_exists('PH_Property_Portal'))
        		{
					// Use the branch code to map this property to the correct agent and branch
					$explode_agent_branch = array();
					if (
						isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch . '|' . $this->import_id]) &&
						$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch . '|' . $this->import_id] != ''
					)
					{
						// A branch is mapped with this branch code and this exact import_id, so choose that agent/branch
						$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch . '|' . $this->import_id]);
					}
					elseif (
						isset($this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch]) &&
						$this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch] != ''
					)
					{
						// No branch has been found that references this import_id, so choose any agent/branch that matches the branch code
						$explode_agent_branch = explode("|", $this->branch_mappings[str_replace("residential-", "", $department)][(string)$property->branch]);
					}

					if ( !empty($explode_agent_branch) )
					{
						update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
						update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );

						$this->branch_ids_processed[] = $explode_agent_branch[1];
					}
        		}

				update_post_meta( $post_id, '_department', $department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->propbedr) ) ? (string)$property->propbedr : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->propbath) ) ? (string)$property->propbath : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->proprecp) ) ? (string)$property->proprecp : '' ) );

				$prefix = '';
				if ( $department == 'commercial' )
                {
                    $prefix = 'commercial_';
                }
				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

				if ( isset($property->proptype) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->proptype]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->proptype], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->proptype . ') that is not mapped', $post_id, (string)$property->propcode );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->proptype, $post_id );
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
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->priceask));

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_price_actual', $price );
					update_post_meta( $post_id, '_poa', ( ( isset($property->priceaskp) && $property->priceaskp == 'Price On Application' ) ? 'yes' : '') );

					update_post_meta( $post_id, '_currency', 'GBP' );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

					if ( !empty($mapping) && isset($property->priceaskp) && isset($mapping[(string)$property->priceaskp]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->priceaskp], 'price_qualifier' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
		            }

		            // Tenure
		            $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

					if ( !empty($mapping) && isset($property->proptenu) && isset($mapping[(string)$property->proptenu]) )
					{
			            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->proptenu], 'tenure' );
		            }
		            else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'tenure' );
		            }
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->priceask));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					$price_actual = $price;
					switch ((string)$property->pricetype)
					{
						case "2": { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
						case "3": { $rent_frequency = 'pcm'; $price_actual = $price; break; }
						case "4": { $rent_frequency = 'pq'; $price_actual = ($price * 4) / 12; break; }
						case "5": { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
					}
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );

					update_post_meta( $post_id, '_currency', 'GBP' );
					
					update_post_meta( $post_id, '_poa', ( ( isset($property->priceaskp) && $property->priceaskp == 'Price On Application' ) ? 'yes' : '') );

					$deposit = preg_replace("/[^0-9.]/", '', (string)$property->deposits);
					update_post_meta( $post_id, '_deposit', $deposit );

					$available_date = isset($property->availabledate) ? (string)$property->availabledate : '';
					if ( $available_date != '' )
					{
						$available_date = substr($available_date, 0, 4) . '-' . substr($available_date, 4, 2) . '-' . substr($available_date, 6, 2);
					}
            		update_post_meta( $post_id, '_available_date', $available_date );

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

            		if ( (string)$property->pricetype == '1' )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', 'GBP' );

	                    $price = round(preg_replace("/[^0-9.]/", '', (string)$property->priceask));
	                    update_post_meta( $post_id, '_price_from', $price );
	                    update_post_meta( $post_id, '_price_to', $price );

	                    update_post_meta( $post_id, '_price_units', '' );

	                    update_post_meta( $post_id, '_price_poa', ( ( isset($property->priceaskp) && $property->priceaskp == 'Price On Application' ) ? 'yes' : '') );

	                    // Price Qualifier
						$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

						if ( !empty($mapping) && isset($property->priceaskp) && isset($mapping[(string)$property->priceaskp]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->priceaskp], 'price_qualifier' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
			            }

	                    // Tenure
			            $mapping = isset($import_settings['mappings']['commercial_tenure']) ? $import_settings['mappings']['commercial_tenure'] : array();

						if ( !empty($mapping) && isset($property->proptenu) && isset($mapping[(string)$property->proptenu]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[(string)$property->proptenu], 'commercial_tenure' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'commercial_tenure' );
			            }
	                }

	                if ( (string)$property->pricetype != '1' )
	                {
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', 'GBP' );

	                    $rent = round(preg_replace("/[^0-9.]/", '', (string)$property->priceask));
	                    update_post_meta( $post_id, '_rent_from', $rent );
	                    update_post_meta( $post_id, '_rent_to', $rent );

	                    $rent_frequency = 'pcm';
						switch ((string)$property->pricetype)
						{
							case "2": { $rent_frequency = 'pw';break; }
							case "3": { $rent_frequency = 'pcm'; break; }
							case "4": { $rent_frequency = 'pq'; break; }
							case "5": { $rent_frequency = 'pa'; break; }
						}
	                    update_post_meta( $post_id, '_rent_units', (string)$property->rentFrequency);

	                    update_post_meta( $post_id, '_rent_poa', ( ( isset($property->priceaskp) && $property->priceaskp == 'Price On Application' ) ? 'yes' : '') );
	                }

		            $size = preg_replace("/[^0-9.]/", '', (string)$property->propsqft);
		            if ( $size == '' )
		            {
		                $size = preg_replace("/[^0-9.]/", '', (string)$property->propsqft2);
		            }
		            update_post_meta( $post_id, '_floor_area_from', $size );

		            update_post_meta( $post_id, '_floor_area_from_sqft', $size );

		            $size = preg_replace("/[^0-9.]/", '', (string)$property->propsqft2);
		            if ( $size == '' )
		            {
		                $size = preg_replace("/[^0-9.]/", '', (string)$property->propsqft);
		            }
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

				// Store price in common currency (GBP) used for ordering
	            $ph_countries = new PH_Countries();
	            $ph_countries->update_property_price_actual( $post_id );

				if ( isset($property->matchflag) )
				{
					$matchflag = (string)$property->matchflag;

					$explode_matchflag = str_split($matchflag);

					// Parking
					$estatesit_parking_flags = $this->get_matchflag_values('parking');

					$parking_term_ids = array();

					foreach ( $estatesit_parking_flags as $i => $estatesit_parking_flag )
					{
						if ( isset($explode_matchflag[$i-1]) )
						{
							$flag_value = $explode_matchflag[$i-1];
							if ( $flag_value == 2 || $flag_value == 3 )
							{
								// it's on. See if this relates to a parking value setup in Property Hive
								$term = term_exists( $estatesit_parking_flag, 'parking' );
								if ( $term !== 0 && $term !== null && isset($term['term_id']) )
								{
									$parking_term_ids[] = (int)$term['term_id'];
								}
							}
						}
					}

					if ( !empty($parking_term_ids) )
					{
						wp_set_object_terms( $post_id, $parking_term_ids, 'parking' );
					}
					else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'parking' );
		            }

					// Outside Space
					$estatesit_outside_space_flags = $this->get_matchflag_values('outside_space');

					$outside_space_term_ids = array();

					foreach ( $estatesit_outside_space_flags as $i => $estatesit_outside_space_flag )
					{
						if ( isset($explode_matchflag[$i-1]) )
						{
							$flag_value = $explode_matchflag[$i-1];
							if ( $flag_value == 2 || $flag_value == 3 )
							{
								// it's on. See if this relates to a outside space value setup in Property Hive
								$term = term_exists( $estatesit_outside_space_flag, 'outside_space' );
								if ( $term !== 0 && $term !== null && isset($term['term_id']) )
								{
									$outside_space_term_ids[] = (int)$term['term_id'];
								}
							}
						}
					}

					if ( !empty($outside_space_term_ids) )
					{
						wp_set_object_terms( $post_id, $outside_space_term_ids, 'outside_space' );
					}
					else
		            {
		            	wp_delete_object_term_relationships( $post_id, 'outside_space' );
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
					update_post_meta( $post_id, '_featured', ( isset($property->featuredproperty) && (string)$property->featuredproperty == '1' ) ? 'yes' : '' );
				}
			
				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->propstat) && isset($mapping[(string)$property->propstat]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->propstat], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

	            // Features
				$features = array();
				for ( $i = 1; $i <= 10; ++$i )
				{
					if ( isset($property->{'bullet' . $i}) && trim((string)$property->{'bullet' . $i}) != '' )
					{
						$features[] = trim((string)$property->{'bullet' . $i});
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
		        if ( $department == 'commercial' )
				{
					$rooms = 0;
					if ( isset($property->description2) && (string)$property->description2 != '' )
					{
						update_post_meta( $post_id, '_description_name_' . $rooms, '' );
			            update_post_meta( $post_id, '_description_' . $rooms, str_replace(array("\r\n", "\n"), "", (string)$property->description2) );

						++$rooms;
					}

					if ( isset($property->rooms) && (string)$property->rooms != '' )
					{
						update_post_meta( $post_id, '_description_name_' . $rooms, '' );
			            update_post_meta( $post_id, '_description_' . $rooms, str_replace(array("\r\n", "\n"), "", (string)$property->rooms) );

						++$rooms;
					}
					
		            update_post_meta( $post_id, '_descriptions', $rooms );
				}
				else
				{
					$rooms = 0;
					if ( isset($property->description2) && (string)$property->description2 != '' )
					{
						update_post_meta( $post_id, '_room_name_' . $rooms, '' );
			            update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
			            update_post_meta( $post_id, '_room_description_' . $rooms, str_replace(array("\r\n", "\n"), "", (string)$property->description2) );

						++$rooms;
					}

					if ( isset($property->rooms) && (string)$property->rooms != '' )
					{
						update_post_meta( $post_id, '_room_name_' . $rooms, '' );
			            update_post_meta( $post_id, '_room_dimensions_' . $rooms, '' );
			            update_post_meta( $post_id, '_room_description_' . $rooms, str_replace(array("\r\n", "\n"), "", (string)$property->rooms) );

						++$rooms;
					}
					
		            update_post_meta( $post_id, '_rooms', $rooms );
		        }

		        // Media - Images
			    $media = array();
			    if (isset($property->photos) && !empty($property->photos))
                {
                    foreach ($property->photos as $photos)
                    {
                        if (!empty($photos->photo))
                        {
                            foreach ($photos->photo as $photo)
                            {
								if ( isset($photo->urlname) )
								{
									$media[] = array(
										'url' => (string)$photo->urlname,
										'description' => ( ( isset($photo->caption) && (string)$photo->caption != '' ) ? (string)$photo->caption : '' ),
									);
								}
							}
						}
					}
				}

				$this->import_media( $post_id, (string)$property->propcode, 'photo', $media, false );

				// Media - Floorplans
			    $media = array();
			    if (isset($property->floorplans) && !empty($property->floorplans))
                {
                    foreach ($property->floorplans as $floorplans)
                    {
                        if (!empty($floorplans->floorplan))
                        {
                            foreach ($floorplans->floorplan as $floorplan)
                            {
								if ( isset($floorplan->urlname) )
								{
									$media[] = array(
										'url' => (string)$floorplan->urlname,
										'description' => ( ( isset($floorplan->caption) && (string)$floorplan->caption != '' ) ? (string)$floorplan->caption : '' ),
									);
								}
							}
						}
					}
				}

				$this->import_media( $post_id, (string)$property->propcode, 'floorplan', $media, false );

				// Media - Brochures
			    $media = array();
			    if (isset($property->pdf) && !empty($property->pdf))
                {
					$media[] = array(
						'url' => (string)$property->pdf,
					);
				}

				$this->import_media( $post_id, (string)$property->propcode, 'brochure', $media, false );

				// Media - EPCs
			    $media = array();
			    if (isset($property->linkepc) && !empty($property->linkepc))
	            {
					$media[] = array(
						'url' => (string)$property->linkepc,
					);
				}
				if (isset($property->epcgraph) && !empty($property->epcgraph))
	            {
					$media[] = array(
						'url' => (string)$property->epcgraph,
					);
				}

				$this->import_media( $post_id, (string)$property->propcode, 'epc', $media, false );

				// Media - Virtual Tours
				$virtual_tours = array();
				if (isset($property->link360) && !empty($property->link360))
                {
                    $virtual_tours[] = (string)$property->link360;
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->propcode );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_estatesit_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->propcode, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_estatesit_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = (string)$property->propcode;
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	private function get_matchflag_values($custom_field)
	{
		switch ( $custom_field )
		{
			case "parking":
			{
				return array(
					'8' => 'Garage',
					'9' => 'Off-street Parking',
					'10' => 'Shared Driveway',
					'11' => 'Residents Parking',
					'12' => 'Parking',
					'115' => 'Allocated Parking',
					'116' => 'Communal Parking',
					'117' => 'Covered Parking',
					'118' => 'Driveway',
					'119' => 'Gated Parking',
					'120' => 'On Street Parking',
					'121' => 'Rear Parking',
					'122' => 'Permit Parking',
					'123' => 'Private Parking',
					'124' => 'Underground Parking',
					'125' => 'Single Garage',
					'126' => 'Double Garage',
				);
				break;
			}
			case "outside_space":
			{
				return array(
					'14' => 'Balcony',
					'17' => 'Patio',
					'18' => 'Garden',
					'19' => 'Front Garden',
					'20' => 'Back Garden',
					'21' => 'Roof Garden',
					'22' => 'Communal Garden',
					'24' => 'Paddock',
					'86' => 'Terrace',
					'87' => 'Roof Terrace',
					'88' => 'Private Garden',
					'89' => 'Enclosed Garden',
					'90' => 'Rear Garden',
					'91' => 'Outbuildings',
					'92' => 'Tennis Court',
					'97' => 'Shared Balcony',
					'98' => 'Shared Garden',
					'99' => 'Shared Terrace',
				);
				break;
			}
		}
	}

    private function clean_up_old_xmls()
    {
    	$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

    	$local_directory = $import_settings['local_directory'];

    	// Clean up processed .BLMs and unused media older than 7 days old (7 days = 604800 seconds)
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

    public function archive( $xml_file )
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
                'BOM' => 'Back On Market',
                'Exchanged' => 'Exchanged',
                'Promotion' => 'Promotion',
                'Reserved' => 'Reserved',
                'SSTC' => 'SSTC',
                'Sold' => 'Sold',
                'Unavailable' => 'Unavailable',
                'Under Offer' => 'Under Offer',
                'Valuation' => 'Valuation',
                'Withdrawn' => 'Withdrawn',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'BOM' => 'Back On Market',
                'Let' => 'Let',
                'Let Agreed' => 'Let Agreed',
                'Promotion' => 'Promotion',
                'Reserved' => 'Reserved',
                'Unavailable' => 'Unavailable',
                'Valuation' => 'Valuation',
                'Withdrawn' => 'Withdrawn',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'BOM' => 'Back On Market',
                'Exchanged' => 'Exchanged',
                'Promotion' => 'Promotion',
                'Reserved' => 'Reserved',
                'SSTC' => 'SSTC',
                'Sold' => 'Sold',
                'Unavailable' => 'Unavailable',
                'Under Offer' => 'Under Offer',
                'Let' => 'Let',
                'Let Agreed' => 'Let Agreed',
                'Valuation' => 'Valuation',
                'Withdrawn' => 'Withdrawn',
            ),
            'property_type' => array(
                'Apartment' => 'Apartment',
                'Barn Conversion' => 'Barn Conversion',
                'Bedsit' => 'Bedsit',
                'Building Plot' => 'Building Plot',
                'Bungalow' => 'Bungalow',
                'Conversion' => 'Conversion',
                'Cottage' => 'Cottage',
                'Detached' => 'Detached',
                'Duplex' => 'Duplex',
                'End Of Terrace' => 'End Of Terrace',
                'Flat' => 'Flat',
                'Flatshare' => 'Flatshare',
                'Garage' => 'Garage',
                'House' => 'House',
                'House Share' => 'House Share',
                'Houseboat' => 'Houseboat',
                'Land' => 'Land',
                'Live/Work' => 'Live/Work',
                'Loft' => 'Loft',
                'Maisonette' => 'Maisonette',
                'Mansion Block' => 'Mansion Block',
                'Mews' => 'Mews',
                'Mobile Home' => 'Mobile Home',
                'Parking' => 'Parking',
                'Penthouse' => 'Penthouse',
                'Purpose Built' => 'Purpose Built',
                'Retirement' => 'Retirement',
                'Room To Let' => 'Room To Let',
                'Semi Detached' => 'Semi Detached',
                'Serviced Apartment' => 'Serviced Apartment',
                'Studio' => 'Studio',
                'Studio Space' => 'Studio Space',
                'Terraced' => 'Terraced',
                'Town House' => 'Town House',
            ),
            'commercial_property_type' => array(
                'Commercial' => 'Commercial',
                'Light Industrial' => 'Light Industrial',
                'Office' => 'Office',
                'Public House' => 'Public House',
                'Restaurant' => 'Restaurant',
                'Shop' => 'Shop',
                'Warehouse' => 'Warehouse',
                'Warehouse Conversion' => 'Warehouse Conversion',
            ),
            'price_qualifier' => array(
                'Asking price of' => 'Asking price of',
                'Auction guide price of' => 'Auction guide price of',
                'Fixed Price' => 'Fixed Price',
                'Guide Price' => 'Guide Price',
                'Keen to sell' => 'Keen to sell',
                'Must be seen' => 'Must be seen',
                'Offers above' => 'Offers above',
                'Offers in excess of' => 'Offers in excess of',
                'Offers in the region of' => 'Offers in the region of',
                'Prices from' => 'Prices from',
                'Reduced' => 'Reduced',
                'Reduced for Quick Sale' => 'Reduced for Quick Sale',
                'Sale by Tender' => 'Sale by Tender',
                'Subject to contract' => 'Subject to contract',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
                'LH+ShareFH' => 'LH+ShareFH',
                'Share of Freehold' => 'Share of Freehold',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
                'LH+ShareFH' => 'LH+ShareFH',
                'Share of Freehold' => 'Share of Freehold',
            ),
            'furnished' => array(
                'Fully Furnished' => 'Fully Furnished',
                'Part Furnished' => 'Part Furnished',
                'Unfurnished' => 'Unfurnished',
                'Furnished' => 'Furnished',
            ),
        );
	}
}

}