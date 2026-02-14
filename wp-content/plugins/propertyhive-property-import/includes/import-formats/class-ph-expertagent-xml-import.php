<?php
/**
 * Class for managing the import process of an ExpertAgent XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_ExpertAgent_XML_Import extends PH_Property_Import_Process {

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

		if ( isset($import_settings['data_source']) && $import_settings['data_source'] == 'local' )
		{
			$xml_file = $import_settings['local_directory'];
		}
		else
		{
			$ftp_conn = $this->open_ftp_connection($import_settings['ftp_host'], $import_settings['ftp_user'], $import_settings['ftp_pass'], $import_settings['ftp_dir'], $import_settings['ftp_passive']);
			if ( $ftp_conn === null)
			{
				$this->log_error( 'Incorrect FTP details provided' );
				return false;
			}

			$wp_upload_dir = wp_upload_dir();
			if( $wp_upload_dir['error'] !== FALSE )
			{
				$this->log_error( 'Unable to create uploads folder. Please check permissions' );
				return false;
			}

			$xml_file = $wp_upload_dir['basedir'] . '/ph_import/' . $import_settings['xml_filename'];

			// Get file
			if ( ftp_get( $ftp_conn, $xml_file, $import_settings['xml_filename'], FTP_ASCII ) )
			{

			}
			else
			{
				$this->log_error( 'Failed to get file ' . $import_settings['xml_filename'] . ' from FTP directory. Maybe try changing the FTP Passive option' );
				return false;
			}
			ftp_close( $ftp_conn );
		}
		
		if ( !file_exists( $xml_file ) )
		{
			// Failed to parse XML
        	$this->log_error( 'File ' . $this->target_file . ' doesn\'t exist' );

        	return false;
		}

		$xml = simplexml_load_file( $xml_file );

		$departments_to_import = array( 'sales', 'lettings', 'commercial' );
		$departments_to_import = apply_filters( 'propertyhive_expertagent_departments_to_import', $departments_to_import );

		if ($xml !== FALSE)
		{
			$limit = $this->get_property_limit();

			$this->log("Parsing properties");
			
            $properties_imported = 0;
            
			foreach ($xml->branches as $branches)
			{
			    foreach ($branches->branch as $branch)
                {
                	$branch_attributes = $branch->attributes();

                	$branch_name = (string)$branch_attributes['name'];

                    foreach ($branch->properties as $properties)
                    {
                        foreach ($properties->property as $property)
                        {
                        	if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
			                {
			                    return true;
			                }

                        	$property_attributes = $property->attributes();

                        	$department = (string)$property->department;

                        	$ok_to_import = false;
                        	foreach ( $departments_to_import as $department_to_import )
                        	{
                        		if ( strpos(strtolower($department), $department_to_import) !== FALSE )
                        		{
                        			$ok_to_import = true;
                        			break;
                        		}
                        	}

                        	if ( $ok_to_import )
                            { 
                            	// Add branch to the property object so we can access it later.
	                        	$property->addChild('branch', htmlentities($branch_name));

	                        	// Add branch to the property object so we can access it later.
	                        	$property->addChild('reference', apply_filters( 'propertyhive_expertagent_unique_identifier_field', $property_attributes['reference'], $property ));

	                            $this->properties[] = $property;
	                        }

                        } // end foreach property
                    } // end foreach properties
                } // end foreach branch
            } // end foreach branches
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML' );

        	return false;
		}

		unlink( $xml_file );

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

        do_action( "propertyhive_pre_import_properties_expertagent_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_expertagent_xml_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_expert_agent_xml", $property, $this->import_id, $this->instance_id );

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

			$this->log( 'Importing property ' . $property_row . ' with reference ' . (string)$property->reference, 0, (string)$property->reference, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$property_attributes = $property->attributes();

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property->reference, $property, (string)$property->advert_heading, (string)$property->main_advert );

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

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				$previous_hash = get_post_meta( $post_id, '_expertagent_hash_' . $this->import_id, TRUE);

				$skip_property = true;
				if (isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property_attributes['hash']) ||
						(
							isset($property_attributes['hash']) &&
							trim((string)$property_attributes['hash']) == ''
						) ||
						$previous_hash == '' ||
						(
							isset($property_attributes['hash']) &&
							(string)$property_attributes['hash'] != '' &&
							$previous_hash != '' &&
							(string)$property_attributes['hash'] != $previous_hash
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
					update_post_meta( $post_id, $imported_ref_key, (string)$property->reference );

					// Address
					update_post_meta( $post_id, '_reference_number', (string)$property->property_reference );
					update_post_meta( $post_id, '_address_name_number', ( ( isset($property->house_number) ) ? (string)$property->house_number : '' ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property->street) ) ? (string)$property->street : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property->district) ) ? (string)$property->district : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property->town) ) ? (string)$property->town : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property->county) ) ? (string)$property->county : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property->postcode) ) ? (string)$property->postcode : '' ) );

					$country = 'GB';
					if ( isset($property->country) && (string)$property->country != '' && class_exists('PH_Countries') )
					{
						$ph_countries = new PH_Countries();
						foreach ( $ph_countries->countries as $country_code => $country_details )
						{
							if ( strtolower((string)$property->country) == strtolower($country_details['name']) || ( strtolower((string)$property->country) == strtolower($country_code) ) )
							{
								$country = $country_code;
								break;
							}
						}
						if ( $country == '' )
						{
							switch (strtolower((string)$property->country))
							{
								case "uk": { $country = 'GB'; break; }
							}
						}
					}
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_expertagent_xml_address_fields_to_check', array('district', 'town', 'county') );
					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property->{$address_field}) && trim((string)$property->{$address_field}) != '' ) 
						{
							$term = term_exists( trim((string)$property->{$address_field}), 'location');
							if ( $term !== 0 && $term !== null && isset($term['term_id']) )
							{
								wp_set_object_terms( $post_id, (int)$term['term_id'], 'location' );
								break;
							}
						}
					}

					// Coordinates
					update_post_meta( $post_id, '_latitude', ( ( isset($property->latitude) ) ? (string)$property->latitude : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property->longitude) ) ? (string)$property->longitude : '' ) );

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
						
					$office_id = $this->primary_office_id;
					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( html_entity_decode($branch_code) == html_entity_decode((string)$property->branch) )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'residential-sales'; // default to sales
					
					$ea_lettings_departments = apply_filters( 'propertyhive_expertagent_lettings_departments', array('lettings') );
					if ( is_array($ea_lettings_departments) && !empty($ea_lettings_departments) )
					{
						foreach ( $ea_lettings_departments as $ea_department )
						{
							if ( strpos(strtolower((string)$property->department), $ea_department) !== FALSE )
							{
								$department = 'residential-lettings';
							}
						}
					}

					$ea_commercial_departments = apply_filters( 'propertyhive_expertagent_commercial_departments', array('commercial') );
					if ( is_array($ea_commercial_departments) && !empty($ea_commercial_departments) )
					{
						foreach ( $ea_commercial_departments as $ea_department )
						{
							if ( strpos(strtolower((string)$property->department), $ea_department) !== FALSE )
							{
								$department = 'commercial';
							}
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

		        	// Property Type
		        	$prefix = '';
		        	$expert_agent_type = (string)$property->property_type . ' - ' . (string)$property->property_style;
					if ( ( strpos(strtolower((string)$property->department), 'commercial') !== FALSE ) )
					{
						$prefix = 'commercial_';
						$expert_agent_type = (string)$property->commercial_type;
					}
		            $mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

		            if ( $expert_agent_type != '' )
		            {
						if ( !empty($mapping) && isset($mapping[$expert_agent_type]) )
						{
				            wp_set_object_terms( $post_id, (int)$mapping[$expert_agent_type], $prefix . 'property_type' );
			            }
			            else
						{
							wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

							$this->log( 'Property received with a type (' . $expert_agent_type . ') that is not mapped', $post_id, (string)$property->reference );

							$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $expert_agent_type, $post_id );
						}
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
					}

					// Clean price
					$price = '';
					if ( isset($property->numeric_price) && (string)$property->numeric_price != '' )
					{
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->numeric_price));
					}

					// Residential Details
		        	if ( $department != 'commercial' )
		        	{
		        		// Residential
						update_post_meta( $post_id, '_bedrooms', ( ( isset($property->bedrooms) ) ? (string)$property->bedrooms : '' ) );
						update_post_meta( $post_id, '_bathrooms', ( ( isset($property->bathrooms) ) ? (string)$property->bathrooms : '' ) );
						update_post_meta( $post_id, '_reception_rooms', ( ( isset($property->receptions) ) ? (string)$property->receptions : '' ) );

						update_post_meta( $post_id, '_council_tax_band', ( ( isset($property->councilTaxBand) ) ? (string)$property->councilTaxBand : '' ) );

						// Residential Sales Details
						if ( $department == 'residential-sales' )
						{
							update_post_meta( $post_id, '_price', $price );
							update_post_meta( $post_id, '_price_actual', $price );

							$poa = '';
							if (
								strpos(strtolower((string)$property->price_text), 'poa') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'p.o.a') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'on application') !== FALSE
							)
							{
								$poa = 'yes';
							}
							update_post_meta( $post_id, '_poa', $poa );

							// Price Qualifier
							$mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

							$price_qualifier_term_id = '';
							foreach ($mapping as $feed_value => $ph_term_id)
							{
								if (strpos(strtolower((string)$property->price_text), strtolower((string)$feed_value)) !== FALSE)
								{
									$price_qualifier_term_id = $ph_term_id;
									break;
								}
							}

							if ( $price_qualifier_term_id != '' )
							{
				                wp_set_object_terms( $post_id, (int)$price_qualifier_term_id, 'price_qualifier' );
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

				            if ( isset($property->tenure) && ( strtolower((string)$property->tenure) == 'leasehold' || strtolower((string)$property->tenure) == 'share of freehold' ) )
				            {
					            update_post_meta( $post_id, '_leasehold_years_remaining', ( ( isset($property->leaseRemainingYears) && !empty((string)$property->leaseRemainingYears) ) ? (string)$property->leaseRemainingYears : '' ) );
								update_post_meta( $post_id, '_service_charge', ( ( isset($property->annualServiceChargeAmount) && !empty((string)$property->annualServiceChargeAmount) && !empty(round((string)$property->annualServiceChargeAmount)) ) ? str_replace(".00", "", (string)$property->annualServiceChargeAmount) : '' ) );
								update_post_meta( $post_id, '_ground_rent', ( ( isset($property->groundRentAmount) && !empty((string)$property->groundRentAmount) && !empty(round((string)$property->groundRentAmount)) ) ? str_replace(".00", "", (string)$property->groundRentAmount) : '' ) );
								update_post_meta( $post_id, '_ground_rent_review_years', ( ( isset($property->groundRentReviewPeriod) && !empty((string)$property->groundRentReviewPeriod) && !empty(round((string)$property->groundRentReviewPeriod)) ) ? str_replace(".000", "", (string)$property->groundRentReviewPeriod ) : '' ) );
								update_post_meta( $post_id, '_shared_ownership', ( ( isset($property->sharedOwnership) && strtolower((string)$property->sharedOwnership) == 'yes' ) ? 'yes' : '' ) );
								update_post_meta( $post_id, '_shared_ownership_percentage', ( ( isset($property->sharedOwnership) && strtolower((string)$property->sharedOwnership) == 'yes' && isset($property->ownershipShare) && !empty((string)$property->ownershipShare) && !empty(round((string)$property->ownershipShare)) ) ? str_replace( "%", "", (string)$property->ownershipShare ) : '' ) );
							}
						}
						elseif ( $department == 'residential-lettings' )
						{
							update_post_meta( $post_id, '_rent', $price );

							$rent_frequency = 'pcm';
							$price_actual = $price;

							if (
								strpos(strtolower((string)$property->price_text), 'pcm') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'month') !== FALSE
							)
							{
								$rent_frequency = 'pcm';
								$price_actual = $price;
							}

							if (
								strpos(strtolower((string)$property->price_text), 'pw') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'week') !== FALSE
							)
							{
								$rent_frequency = 'pw';
								$price_actual = ($price * 52) / 12;
							}

							if (
								strpos(strtolower((string)$property->price_text), 'pq') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'quarter') !== FALSE
							)
							{
								$rent_frequency = 'pq';
								$price_actual = ($price * 4) / 12;
							}

							if (
								strpos(strtolower((string)$property->price_text), 'pa') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'annum') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'annual') !== FALSE
							)
							{
								$rent_frequency = 'pa';
								$price_actual = $price / 12;
							}

							update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
							update_post_meta( $post_id, '_price_actual', $price_actual );
							
							$poa = '';
							if (
								strpos(strtolower((string)$property->price_text), 'poa') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'p.o.a') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'on application') !== FALSE
							)
							{
								$poa = 'yes';
							}
							update_post_meta( $post_id, '_poa', $poa );

							update_post_meta( $post_id, '_deposit', ( ( isset($property->tenancyDepositPayable) ) ? str_replace(".00", "", (string)$property->tenancyDepositPayable) : '' ) );
		            		$available_date = '';
		            		if ( isset($property->availableFrom) && (string)$property->availableFrom != '' )
		            		{
		            			$explode_available_date = explode(" ", (string)$property->availableFrom);
		            			$explode_available_date = explode("/", $explode_available_date[0]);
		            			if ( count($explode_available_date) == 3 )
		            			{
		            				$available_date = $explode_available_date[2] . '-' . $explode_available_date[1] . '-' . $explode_available_date[0];
		            			}
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
					}
					else
					{
						// Commercial
						update_post_meta( $post_id, '_for_sale', '' );
	            		update_post_meta( $post_id, '_to_rent', '' );

	            		if ( strpos( (string)$property->price_text, 'Rental' ) === FALSE )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', (string)$property->currency );

		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    $poa = '';
							if (
								strpos(strtolower((string)$property->price_text), 'poa') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'p.o.a') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'on application') !== FALSE
							)
							{
								$poa = 'yes';
							}
		                    update_post_meta( $post_id, '_price_poa', $poa );

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
		                else
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', (string)$property->currency );

		                    update_post_meta( $post_id, '_rent_from', $price );
		                    update_post_meta( $post_id, '_rent_to', $price );

		                    $rent_frequency = 'pcm';
							if (
								strpos(strtolower((string)$property->price_text), 'pcm') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'month') !== FALSE
							)
							{
								$rent_frequency = 'pcm';
							}

							if (
								strpos(strtolower((string)$property->price_text), 'pw') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'week') !== FALSE
							)
							{
								$rent_frequency = 'pw';
							}

							if (
								strpos(strtolower((string)$property->price_text), 'pq') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'quarter') !== FALSE
							)
							{
								$rent_frequency = 'pq';
							}

							if (
								strpos(strtolower((string)$property->price_text), 'pa') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'annum') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'annual') !== FALSE
							)
							{
								$rent_frequency = 'pa';
							}
		                    update_post_meta( $post_id, '_rent_units', $rent_frequency);

		                    $poa = '';
							if (
								strpos(strtolower((string)$property->price_text), 'poa') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'p.o.a') !== FALSE || 
								strpos(strtolower((string)$property->price_text), 'on application') !== FALSE
							)
							{
								$poa = 'yes';
							}
		                    update_post_meta( $post_id, '_rent_poa', $poa );
		                }

		                // Store price in common currency (GBP) used for ordering
			            $ph_countries = new PH_Countries();
			            $ph_countries->update_property_price_actual( $post_id );

			            $size = '';
			            update_post_meta( $post_id, '_floor_area_from', $size );
			            update_post_meta( $post_id, '_floor_area_from_sqft', $size );

			            update_post_meta( $post_id, '_floor_area_to', $size );
			            update_post_meta( $post_id, '_floor_area_to_sqft', $size );

			            update_post_meta( $post_id, '_floor_area_units', '' );

			            update_post_meta( $post_id, '_site_area_from', $size );
			            update_post_meta( $post_id, '_site_area_from_sqft', $size );

			            update_post_meta( $post_id, '_site_area_to', $size );
			            update_post_meta( $post_id, '_site_area_to_sqft', $size );

			            update_post_meta( $post_id, '_site_area_units', '' );
					}

					// Marketing
					$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
					$featured = '';
					if ( isset($property->featuredProperty) && strtolower((string)$property->featuredProperty) == 'yes' )
					{
						$featured = 'yes';
					}
					elseif ( isset($property->propertyofweek) && strtolower((string)$property->propertyofweek) == 'yes' )
					{
						$featured = 'yes';
					}
					$featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', $featured );
					}
				
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

	        		if ( isset($property->priority)  )
		            {
						if ( !empty($mapping) && isset($property->priority) && isset($mapping[(string)$property->priority]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->priority], 'availability' );
			            }
			            else
						{
							wp_delete_object_term_relationships( $post_id, 'availability' );

							$this->log( 'Property received with an availability (' . (string)$property->priority . ') that is not mapped', $post_id, (string)$property->reference );
						}
			        }
			        else
			        {
			        	wp_delete_object_term_relationships( $post_id, 'availability' );
			        }

		            // Features
					$features = array();
					for ( $i = 1; $i <= 20; ++$i )
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

			        // Rooms
			        $i = 0;
					if ( isset($property->rooms) && !empty($property->rooms) )
					{
						foreach ($property->rooms as $rooms)
						{
							foreach ( $rooms->room as $room )
							{
								$room_attributes = $room->attributes();

								update_post_meta( $post_id, '_room_name_' . $i, (string)$room_attributes['name'] );
					            update_post_meta( $post_id, '_room_dimensions_' . $i, (string)$room->measurement_text );
					            update_post_meta( $post_id, '_room_description_' . $i, (string)$room->description );

					            ++$i;
							}
						}
					}
					update_post_meta( $post_id, '_rooms', $i );

					// Media - Images
				    $media = array();
				    if ( isset($property->pictures) && !empty($property->pictures) )
					{
						foreach ( $property->pictures as $pictures )
						{
							foreach ( $pictures->picture as $picture )
							{
								if ( isset($picture->filename) && trim((string)$picture->filename) != '' )
								{
									$picture_attributes = $picture->attributes();

									$url = str_replace(" ", "%20", (string)$picture->filename);
									$url = str_replace("{", "%7B", $url);
									$url = str_replace("}", "%7D", $url);
									$url = str_replace("(", "%28", $url);
									$url = str_replace(")", "%29", $url);
									$url = str_replace("http://", "https://", $url);

									$media_attributes = $picture->attributes();
									$modified = date('Y-m-d H:i:s', strtotime ((string)$media_attributes['lastchanged']));

									$media[] = array(
										'url' => $url,
										'description' => ( ( isset($picture_attributes['name']) && (string)$picture_attributes['name'] != '' ) ? (string)$picture_attributes['name'] : '' ),
										'modified' => $modified,
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->reference, 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property->floorplans) && !empty($property->floorplans) )
					{
						foreach ( $property->floorplans as $floorplans )
						{
							foreach ( $floorplans->floorplan as $floorplan )
							{
								if ( isset($floorplan->filename) && trim((string)$floorplan->filename) != '' )
								{
									$floorplan_attributes = $floorplan->attributes();

									$url = str_replace(" ", "%20", (string)$floorplan->filename);
									$url = str_replace("{", "%7B", $url);
									$url = str_replace("}", "%7D", $url);
									$url = str_replace("(", "%28", $url);
									$url = str_replace(")", "%29", $url);
									$url = str_replace("http://", "https://", $url);

									$media[] = array(
										'url' => $url,
										'description' => ( ( isset($floorplan_attributes['name']) && (string)$floorplan_attributes['name'] != '' ) ? (string)$floorplan_attributes['name'] : '' ),
									);
								}
							}
						}
					}

					$this->import_media( $post_id, (string)$property->reference, 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if ( isset($property->brochure) && trim((string)$property->brochure) != '' )
					{
						$url = str_replace(" ", "%20", (string)$property->brochure);
						$url = str_replace("{", "%7B", $url);
						$url = str_replace("}", "%7D", $url);
						$url = str_replace("(", "%28", $url);
						$url = str_replace(")", "%29", $url);
						$url = str_replace("http://", "https://", $url);

						$media[] = array(
							'url' => $url,
							'description' => 'Brochure',
						);
					}

					$this->import_media( $post_id, (string)$property->reference, 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if ( isset($property->epc) && trim((string)$property->epc) != '' )
					{
						$url = str_replace(" ", "%20", (string)$property->epc);
						$url = str_replace("{", "%7B", $url);
						$url = str_replace("}", "%7D", $url);
						$url = str_replace("(", "%28", $url);
						$url = str_replace(")", "%29", $url);
						$url = str_replace("http://", "https://", $url);

						$media[] = array(
							'url' => $url,
							'description' => 'Brochure',
						);
					}

					$this->import_media( $post_id, (string)$property->reference, 'epc', $media, false );

					// Media - Virtual Tours
					if ( isset($property->virtual_tour_url) && trim((string)$property->virtual_tour_url) != '' )
					{
						if ( 
							substr( strtolower((string)$property->virtual_tour_url), 0, 2 ) == '//' || 
							substr( strtolower((string)$property->virtual_tour_url), 0, 4 ) == 'http'
						)
						{
							// This is a URL
							$url = (string)$property->virtual_tour_url;

							update_post_meta( $post_id, '_virtual_tours', 1 );
							update_post_meta( $post_id, '_virtual_tour_0', $url );

							$this->log( 'Imported 1 virtual tour', $post_id, (string)$property->reference );
						}
						else
						{
							$this->log( 'Imported 0 virtual tours', $post_id, (string)$property->reference );
						}
					}
					else
					{
						$this->log( 'Imported 0 virtual tours', $post_id, (string)$property->reference );
					}
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, (string)$property->reference );
				}

				if ( isset($property_attributes['hash']) ) { update_post_meta( $post_id, '_expertagent_hash_' . $this->import_id, (string)$property_attributes['hash'] ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_expert_agent_xml", $post_id, $property, $this->import_id );

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

		do_action( "propertyhive_post_import_properties_expertagent_xml" );

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
                'On Market' => 'On Market',
                'Sold STC' => 'Sold STC',
                'Under Offer' => 'Under Offer',
                'Exchanged' => 'Exchanged',
            ),
            'lettings_availability' => array(
                'On Market' => 'On Market',
                'Available to Let' => 'Available to Let',
                'Let' => 'Let',
                'Let STC' => 'Let STC',
            ),
            'commercial_availability' => array(
                'On Market' => 'On Market',
                'Available to Let' => 'Available to Let',
                'Sold STC' => 'Sold STC',
                'Under Offer' => 'Under Offer',
                'Exchanged' => 'Exchanged',
                'Let' => 'Let',
                'Let STC' => 'Let STC',
                'Withdrawn' => 'Withdrawn',
            ),
            'property_type' => array(
                'House - Detached' => 'House - Detached',
                'House - Semi Detached' => 'House - Semi Detached',
                'House - Terraced' => 'House - Terraced',
                'House - End of Terrace' => 'House - End of Terrace',

                'Flat - Lower Ground Floor Flat' => 'Flat - Lower Ground Floor Flat',
                'Flat - Ground Floor Flat' => 'Flat - Ground Floor Flat',
                'Flat - Upper Floor Flat' => 'Flat - Upper Floor Flat',

                'Bungalow - Detached' => 'Bungalow - Detached',
                'Bungalow - Semi Detached' => 'Bungalow - Semi Detached',
                'Bungalow - Terraced' => 'Bungalow - Terraced',
                'Bungalow - End of Terrace' => 'Bungalow - End of Terrace',
            ),
            'commercial_property_type' => array(
                'Leasehold Offices' => 'Leasehold Offices',
            ),
            'price_qualifier' => array(
                'Guide Price' => 'Guide Price',
                'Fixed Price' => 'Fixed Price',
                'Offers in Excess of' => 'Offers in Excess of',
                'OIRO' => 'OIRO',
                'Sale by Tender' => 'Sale by Tender',
                'From' => 'From',
                'Shared Ownership' => 'Shared Ownership',
                'Offers Over' => 'Offers Over',
                'Part Buy Part Rent' => 'Part Buy Part Rent',
                'Shared Equity' => 'Shared Equity',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Share of Freehold' => 'Share of Freehold',
                'Leasehold' => 'Leasehold',
                'Private Company Ownership' => 'Private Company Ownership',
                'Unknown' => 'Unknown',
            ),
            'commercial_tenure' => array(
                'Freehold' => 'Freehold',
                'Share of Freehold' => 'Share of Freehold',
                'Leasehold' => 'Leasehold',
                'Private Company Ownership' => 'Private Company Ownership',
                'Unknown' => 'Unknown',
            ),
            'furnished' => array(
                'Landlord Flexible' => 'Landlord Flexible',
                'Furnished' => 'Furnished',
                'Part Furnished' => 'Part Furnished',
                'Un-Furnished' => 'Un-Furnished',
            ),
        );
	}
}

}