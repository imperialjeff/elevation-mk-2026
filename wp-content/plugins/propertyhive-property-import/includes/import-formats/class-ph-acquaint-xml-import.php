<?php
/**
 * Class for managing the import process of an Acquaint XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Acquaint_XML_Import extends PH_Property_Import_Process {

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

		foreach ($urls as $url)
		{
			$xml = simplexml_load_file( trim($url) );

			if ($xml !== FALSE)
			{
				$this->log("Parsing properties");

				$target_filename = basename($url);
				$explode_target_filename = explode(".", $target_filename);
				$branch = $explode_target_filename[0];
				
				foreach ($xml->properties->property as $property)
    			{
    				if ( isset($property->status) && (string)$property->status != 'ERROR' )
					{
						if ( isset($property->feedto) && (string)$property->feedto != 'none' )
						{
							if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
					        {
					            return true;
					        }

							$property->addChild('branch', $branch);

		                	$this->properties[] = $property;
		                }
		            }
	            } // end foreach property
	        }
	        else
	        {
	        	// Failed to parse XML
	        	$this->log_error( 'Failed to parse XML file at ' . $url . '. Possibly invalid XML' );
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

        do_action( "propertyhive_pre_import_properties_acquaint_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_acquaint_xml_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_acquaint_xml", $property, $this->import_id, $this->instance_id );
			
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

			$this->log( 'Importing property ' . $property_row . ' with reference ' . (string)$property->id, 0, (string)$property->id, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = (string)$property->displayaddress;
            if ($display_address == '')
            {
                $display_address = (string)$property->address->street;
                if ((string)$property->address->town != '')
                {
                    if ($display_address != '')
                    {
                        $display_address .= ', ';
                    }
                    $display_address .= (string)$property->address->town;
                }
                if ((string)$property->address->region != '')
                {
                    if ($display_address != '')
                    {
                        $display_address .= ', ';
                    }
                    $display_address .= (string)$property->address->region;
                }
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->id, $property, $display_address, (string)$property->descriptionbrief );

			if ( $inserted_updated !== false )
			{
				// Inserted property ok. Continue

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property->id );

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

				update_post_meta( $post_id, $imported_ref_key, (string)$property->id );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->id );
				update_post_meta( $post_id, '_address_name_number', ( ( isset($property->address->propertyname) ) ? (string)$property->address->propertyname : '' ) );
				update_post_meta( $post_id, '_address_street', ( ( isset($property->address->street) ) ? (string)$property->address->street : '' ) );
				update_post_meta( $post_id, '_address_two', ( ( isset($property->address->locality) ) ? (string)$property->address->locality : '' ) );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->address->town) ) ? (string)$property->address->town : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->address->region) ) ? (string)$property->address->region : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->address->postcode) ) ? (string)$property->address->postcode : '' ) );

				$country = get_option( 'propertyhive_default_country', 'GB' );
				update_post_meta( $post_id, '_address_country', $country );

				$ph_countries = new PH_Countries();
				$country = $ph_countries->get_country( $country );
				$currency = isset($country['currency_code']) ? $country['currency_code'] : 'GBP';

				update_post_meta( $post_id, '_latitude', ( ( isset($property->address->latitude) ) ? (string)$property->address->latitude : '' ) );
			    update_post_meta( $post_id, '_longitude', ( ( isset($property->address->longitude) ) ? (string)$property->address->longitude : '' ) );

			    // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
				if ( isset($property->address->area) && trim((string)$property->address->area) != '' ) 
				{
					$term = term_exists( trim((string)$property->address->area), 'location');
					if ( $term !== 0 && $term !== null && isset($term['term_id']) )
					{
						wp_set_object_terms( $post_id, (int)$term['term_id'], 'location' );
					}
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $this->primary_office_id; // Needs mapping properly
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

				$department = 'residential-sales';
				$category_attributes = $property->category->attributes();
				$prefix = '';
				if ( (string)$category_attributes['id'] != 0 )
				{
					$department = 'residential-lettings';
				}
				if ( get_option( 'propertyhive_active_departments_commercial' ) == 'yes' && isset($property->usage) && strtolower((string)$property->usage) == 'commercial' )
				{  
					$department = 'commercial';
					$prefix  = 'commercial_';
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
					else
					{
						update_post_meta( $post_id, '_agent_id', '' );
						update_post_meta( $post_id, '_branch_id', '' );
					}
        		}

				// Residential Details
				update_post_meta( $post_id, '_department', $department );
				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedrooms) ) ? (string)$property->bedrooms : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathrooms) ) ? (string)$property->bathrooms : '' ) );
				update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->receptions) ) ? (string)$property->receptions : '' ) );

				$council_tax_band = '';
				if ( isset($property->counciltaxband) && strpos((string)$property->counciltaxband, 'Band ') != '')
				{
					$council_tax_band = str_replace('Band ', '', (string)$property->counciltaxband);
				}
				update_post_meta( $post_id, '_council_tax_band', $council_tax_band );

				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
				
				if ( isset($property->type ) && (string)$property->type  != '' )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->type ]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->type ], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->type  . ') that is not mapped', $post_id, (string)$property->id );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->type );
					}
				}
				else
				{
					wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
				}

				if ( $department == 'residential-sales' )
				{
					update_post_meta( $post_id, '_price', (string)$property->price);
					update_post_meta( $post_id, '_price_actual', (string)$property->price);
					update_post_meta( $post_id, '_poa', '' );
					
					update_post_meta( $post_id, '_currency', $currency );

					// Price Qualifier
					$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
					
					if ( isset($property->priceprefix ) && (string)$property->priceprefix  != '' )
					{
						if ( !empty($mapping) && isset($mapping[(string)$property->priceprefix]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->priceprefix], 'price_qualifier' );
			            }
			            else
						{
							wp_delete_object_term_relationships( $post_id, 'price_qualifier' );

							$this->log( 'Property received with a price qualifier (' . (string)$property->priceprefix  . ') that is not mapped', $post_id, (string)$property->id );

							$import_settings = $this->add_missing_mapping( $mapping, 'price_qualifier', (string)$property->priceprefix );
						}
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

		            if ( isset($property->tenure) && (string)$property->tenure == 'Leasehold' )
		            {
			            update_post_meta( $post_id, '_leasehold_years_remaining', ( ( isset($property->leaseyears) && !empty((string)$property->leaseyears) ) ? (string)$property->leaseyears : '' ) );
						update_post_meta( $post_id, '_service_charge', ( ( isset($property->servicecharge) && !empty((string)$property->servicecharge) && (string)$property->servicecharge != '0.00' ) ? (string)$property->servicecharge : '' ) );
						update_post_meta( $post_id, '_ground_rent', ( ( isset($property->groundrent) && !empty((string)$property->groundrent) && (string)$property->groundrent != '0.00' ) ? (string)$property->groundrent : '' ) );
						//update_post_meta( $post_id, '_ground_rent_review_years', ( ( isset($property->ground_rent_review_period_years) && !empty((string)$property->ground_rent_review_period_years) ) ? (string)$property->ground_rent_review_period_years : '' ) );
						//update_post_meta( $post_id, '_shared_ownership', ( (string)$property->shared_ownership == '1' ? 'yes' : '' ) );
						//update_post_meta( $post_id, '_shared_ownership_percentage', ( (string)$property->shared_ownership == '1' ? str_replace( "%", "", (string)$property->shared_ownership_percentage ) : '' ) );
					}
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = round(preg_replace("/[^0-9.]/", '', (string)$property->price));

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = (string)$property->pricefrequency;
					$price_actual = $price;
					switch ((string)$property->pricefrequency)
					{
						case "pw": { $price_actual = ($price * 52) / 12; break; }
						case "pq": { $price_actual = ($price * 4) / 12; break; }
						case "pa": { $price_actual = $price / 12; break; }
					}
					
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					update_post_meta( $post_id, '_price_actual', $price_actual );

					update_post_meta( $post_id, '_currency', $currency );
					
					update_post_meta( $post_id, '_poa', '' );

					$deposit = '';
					if ( isset($property->rentaldetails->deposit) )
					{
						$deposit = (float)$property->rentaldetails->deposit;
						if ( empty($deposit) )
						{
							$deposit = '';
						}
					}
					update_post_meta( $post_id, '_deposit', $deposit );
	        		
	        		$available_date = isset($property->rentaldetails->rentalavailabledate) && (string)$property->rentaldetails->rentalavailabledate != '' ? (string)$property->rentaldetails->rentalavailabledate : '';
	        		$available_date = explode(" ", $available_date);
	        		$available_date = $available_date[0];
	        		update_post_meta( $post_id, '_available_date', $available_date );

	        		// Furnished
					$mapping = isset($import_settings['mappings']['furnished']) ? $import_settings['mappings']['furnished'] : array();

					if ( !empty($mapping) && isset($property->rentaldetails->rentalfurnished) && isset($mapping[(string)$property->rentaldetails->rentalfurnished]) )
					{
		                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->rentaldetails->rentalfurnished], 'furnished' );
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

            		if ( (string)$category_attributes['id'] == 0 )
	                {
	                    update_post_meta( $post_id, '_for_sale', 'yes' );

	                    update_post_meta( $post_id, '_commercial_price_currency', $currency );

	                    $price = preg_replace("/[^0-9.]/", '', (string)$property->price);
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

	                if ( (string)$category_attributes['id'] == 1 )
	                {
	                    update_post_meta( $post_id, '_to_rent', 'yes' );

	                    update_post_meta( $post_id, '_commercial_rent_currency', $currency );

	                    $rent = preg_replace("/[^0-9.]/", '', (string)$property->price);
	                    update_post_meta( $post_id, '_rent_from', $rent );
	                    update_post_meta( $post_id, '_rent_to', $rent );

	                    $rent_frequency = (string)$property->pricefrequency;
						update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

	                    update_post_meta( $post_id, '_rent_poa', '' );
	                }

	                // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

		            $size = isset($property->floorarea) && (string)$property->floorarea != '' && (string)$property->floorarea != '0' ? (string)$property->floorarea : '';
		            update_post_meta( $post_id, '_floor_area_from', $size );
		            update_post_meta( $post_id, '_floor_area_from_sqft', $size );
		            update_post_meta( $post_id, '_floor_area_to', $size );
		            update_post_meta( $post_id, '_floor_area_to_sqft', $size );
		            update_post_meta( $post_id, '_floor_area_units', 'sqft' );

		            $size = isset($property->landarea) && (string)$property->landarea != '' && (string)$property->landarea != '0' ? (string)$property->landarea : '';
		            update_post_meta( $post_id, '_site_area_from', $size );
		            update_post_meta( $post_id, '_site_area_from_sqft', $size );
		            update_post_meta( $post_id, '_site_area_to', $size );
		            update_post_meta( $post_id, '_site_area_to_sqft', $size );
		            update_post_meta( $post_id, '_site_area_units', 'sqft' );
				}

				$departments_with_residential_details = apply_filters( 'propertyhive_departments_with_residential_details', array( 'residential-sales', 'residential-lettings' ) );
				if ( in_array($department, $departments_with_residential_details) )
				{
					// Electricity
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property->electric ) ) 
					{
				        $supply_value = trim((string)$property->electric);
				        switch ( $supply_value ) 
				        {
				            case 'Mains Supply': $utility_type[] = 'mains_supply'; break;
				            case 'Private Supply': $utility_type[] = 'private_supply'; break;
				            case 'Solar PV Panels': $utility_type[] = 'solar_pv_panels'; break;
				            case 'Wind Turbine': $utility_type[] = 'wind_turbine'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_electricity_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_electricity_type_other', $utility_type_other );
					}

                    // Water
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property->water ) ) 
					{
				        $supply_value = trim((string)$property->water);
				        switch ( $supply_value ) 
				        {
				            case 'Mains Supply': $utility_type[] = 'mains_supply'; break;
				            case 'Private Supply': $utility_type[] = 'private_supply'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_water_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_water_type_other', $utility_type_other );
					}
					
					// Heating
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property->centralheating ) ) 
					{
				        $source_value = trim((string)$property->centralheating);
				        switch ( $source_value ) 
				        {
				            case 'Gas Central Heating': $utility_type[] = 'night_storage'; break;
				            case 'Electric Storage Heaters': $utility_type[] = 'night_storage'; break;
				            case 'Electric Heaters': $utility_type[] = 'electric'; break;
				            case 'Oil Central Heatings': $utility_type[] = 'oil'; break;
				            case 'Solar': $utility_type[] = 'solar'; break;
				            case 'Electric Central Heating': $utility_type[] = 'electric'; break;
				            case 'Central Heating': $utility_type[] = 'central'; break;
				            case 'Under Floor Heating': $utility_type[] = 'under_floor'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $source_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_heating_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_heating_type_other', $utility_type_other );
					}

					// Broadband
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property->broadband ) ) 
					{
				        $supply_value = trim((string)$property->broadband);
				        switch ( $supply_value ) 
				        {
				            case 'ADSL': $utility_type[] = 'adsl'; break;
				            case 'Cable': $utility_type[] = 'cable'; break;
				            case 'FTTC': $utility_type[] = 'fttc'; break;
				            case 'FTTP': $utility_type[] = 'fttp'; break;
				            case 'None': $utility_type[] = 'none'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_broadband_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_broadband_type_other', $utility_type_other );
					}

					// Sewerage
					$utility_type = [];
					$utility_type_other = '';
					if ( isset( $property->sewerage ) ) 
					{
				        $supply_value = trim((string)$property->sewerage);
				        switch ( $supply_value ) 
				        {
				            case 'Mains Supply': $utility_type[] = 'mains_supply'; break;
				            case 'Private Supply': $utility_type[] = 'private_supply'; break;
				            default: 
				                $utility_type[] = 'other'; 
				                $utility_type_other .= ( $utility_type_other ? ', ' : '' ) . $supply_value; 
				                break;
				        }
					    $utility_type = array_unique($utility_type);
					}
					update_post_meta( $post_id, '_sewerage_type', $utility_type );
					if ( in_array( 'other', $utility_type ) ) 
					{
					    update_post_meta( $post_id, '_sewerage_type_other', $utility_type_other );
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
					update_post_meta( $post_id, '_featured', ( ( isset($property->featured) && $property->featured == 'Yes' ) ? 'yes' : '' ) );
				}

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->status) && isset($mapping[(string)$property->status]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->status], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

	            // Features
				$features = array();
				for ( $i = 1; $i <= 20; ++$i )
				{
					if ( isset($property->bulletpoints->{'bulletpoint' . $i}) && trim((string)$property->bulletpoints->{'bulletpoint' . $i}) != '' )
					{
						$features[] = trim((string)$property->bulletpoints->{'bulletpoint' . $i});
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
		        $rooms = 1;

		        // For now put the whole description in one room
		        $full_description = (string)$property->descriptionfull;
		        if ( isset($property->rooms) && (string)$property->rooms != '' )
		        {
		        	if ( trim(strip_tags($full_description)) != '' )
		        	{
		        		$full_description .= '<br><br>';
		        	}
		        	$full_description .= (string)$property->rooms;
		        }
				update_post_meta( $post_id, '_room_name_0', '' );
	            update_post_meta( $post_id, '_room_dimensions_0', '' );
	            update_post_meta( $post_id, '_room_description_0', $full_description );

	            if ( isset($property->rentaldetails->fees) && (string)$property->rentaldetails->fees != '' )
				{
					update_post_meta( $post_id, '_room_name_1', 'Fees' );
		            update_post_meta( $post_id, '_room_dimensions_1', '' );
		            update_post_meta( $post_id, '_room_description_1', (string)$property->rentaldetails->fees );

					++$rooms;
				}

				update_post_meta( $post_id, '_rooms', $rooms );

				// Media - Images
			    $media = array();
				if (isset($property->pictures) && !empty($property->pictures))
                {
                    for ($i = 1; $i <= 50; ++$i)
                    {
                        if ( isset( $property->pictures->{"picture" . $i} ) && (string)$property->pictures->{"picture" . $i} != '' )
                        {
							$media[] = array(
								'url' => (string)$property->pictures->{"picture" . $i},
								'modified' => date("Y-m-d H:i:s", strtotime((string)$property->updateddate)),
							);
						}
					}
				}

				$this->import_media( $post_id, (string)$property->id, 'photo', $media, true );

				// Media - Floorplans
			    $media = array();
				if ( isset($property->floorplan) && (string)$property->floorplan != '' )
                {
					$media[] = array(
						'url' => (string)$property->floorplan,
						'modified' => date("Y-m-d H:i:s", strtotime((string)$property->updateddate)),
					);
				}

				$this->import_media( $post_id, (string)$property->id, 'floorplan', $media, true );

				// Media - Brochure
			    $media = array();
				if ( isset($property->brochure) && (string)$property->brochure != '' )
                {
					$media[] = array(
						'url' => (string)$property->brochure,
						'modified' => date("Y-m-d H:i:s", strtotime((string)$property->updateddate)),
					);
				}

				$this->import_media( $post_id, (string)$property->id, 'brochure', $media, true );

				// Media - EPCs
			    $media = array();
				if ( isset($property->energyperformance->eerchart) && (string)$property->energyperformance->eerchart != '' )
                {
					$media[] = array(
						'url' => (string)$property->energyperformance->eerchart,
						'modified' => date("Y-m-d H:i:s", strtotime((string)$property->updateddate)),
					);
				}
				if ( isset($property->energyperformance->eirchart) && (string)$property->energyperformance->eirchart != '' )
                {
					$media[] = array(
						'url' => (string)$property->energyperformance->eirchart,
						'modified' => date("Y-m-d H:i:s", strtotime((string)$property->updateddate)),
					);
				}

				$this->import_media( $post_id, (string)$property->id, 'epc', $media, true );

				// Media - Virtual Tours
				$virtual_tours = array();
				if (isset($property->virtualtour) && (string)$property->virtualtour != '')
                {
                    $virtual_tours[] = (string)$property->virtualtour;          
                }
                if (isset($property->virtualtour2) && (string)$property->virtualtour2 != '')
                {
                    $virtual_tours[] = (string)$property->virtualtour2;          
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                	update_post_meta( $post_id, '_virtual_tour_' . $i, (string)$virtual_tour );
                }

				$this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, (string)$property->id );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_acquaint_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->propertyID, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_acquaint_xml" );

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
                'Sold STC' => 'Sold STC',
                'Under Offer' => 'Under Offer',
                'Sold' => 'Sold',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
                'Let' => 'Let',
            ),
            'commercial_availability' => array(
                'Available' => 'Available',
                'Sold STC' => 'Sold STC',
                'Under Offer' => 'Under Offer',
                'Let' => 'Let',
                'Sold' => 'Sold',
            ),
            'property_type' => array(
                'House' => 'House',
                'Detached' => 'Detached',
                'Semi-Detached' => 'Semi-Detached',
                'Terrace' => 'Terrace',
                'End Terrace' => 'End Terrace',
                'Flat' => 'Flat',
                'Apartment' => 'Apartment',
                'Studio' => 'Studio',
                'Maisonette' => 'Maisonette',
                'Bungalow' => 'Bungalow',
                'Garage' => 'Garage',
            ),
            'commercial_property_type' => array(
                'Land' => 'Land',
                'Office' => 'Office',
            ),
            'price_qualifier' => array(
                'Offers Over' => 'Offers Over',
                'OIRO' => 'OIRO',
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
                'Part Furnished' => 'Part Furnished',
            )
        );
	}

}

}