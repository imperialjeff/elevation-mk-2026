<?php
/**
 * Class for managing the import process of a BDP JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_BDP_JSON_Import extends PH_Property_Import_Process {

    public function __construct( $instance_id = '', $import_id = '')
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

        $date = date('r');

        $string_to_sign = "GET\n";
        $string_to_sign .= "\n" . $import_settings['account_id'];
        $string_to_sign .= "\n" . strtolower($date);

        $signature = base64_encode(
            hash_hmac('sha1', utf8_encode($string_to_sign), $import_settings['secret'], true)
        );

        $url = ( isset($import_settings['api_base_url']) && !empty($import_settings['api_base_url']) ) ? $import_settings['api_base_url'] : 'https://api.bdphq.com';
        $url .= "/restapi/props";

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $headers = array(
           "AccountId: " . $import_settings['account_id'],
           "Date: " . $date,
           "Authorization: BDWS " . $import_settings['api_key'] . ":" . $signature
        );
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        //for debug only!
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($curl);
        curl_close($curl);

        $json = json_decode( $response, TRUE );

        if ( $json !== null && isset( $json['properties'] ) && is_array( $json['properties'] ) )
        {
        	$limit = $this->get_property_limit();

            foreach ($json['properties'] as $property)
            {
            	if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
                {
                    return true;
                }

                if ( $test === false )
                {
	                $property_id = $property['property_id'];

	                $url = ( isset($import_settings['api_base_url']) && !empty($import_settings['api_base_url']) ) ? $import_settings['api_base_url'] : 'https://api.bdphq.com';
	                $url .= "/restapi/property/" . $property_id;

	                $curl = curl_init($url);
	                curl_setopt($curl, CURLOPT_URL, $url);
	                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

	                $headers = array(
	                   "AccountId: " . $import_settings['account_id'],
	                   "Date: " . $date,
	                   "Authorization: BDWS " . $import_settings['api_key'] . ":" . $signature
	                );
	                curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	                //for debug only!
	                curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
	                curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);

	                $property_response = curl_exec($curl);
	                curl_close($curl);

	                $property_json = json_decode( $property_response, TRUE );

	                if ( $property_json !== null && is_array( $property_json ) )
	                {
	                    $this->properties[] = $property_json;
	                }
	                else
	                {
	                    // Failed to parse JSON
	                    $this->log_error( 'Failed to parse property ' . $property_id . ' JSON file. Possibly invalid JSON' );
	                    return false;
	                }
	            }
	            else
	            {
	            	$this->properties[] = $property;
	            }
            }
        }
        else
        {
            // Failed to parse JSON
            $this->log_error( 'Failed to parse JSON file: ' . print_r($response, TRUE) );
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

        do_action( "propertyhive_pre_import_properties_bdp_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_bdp_json_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_bdp_json", $property, $this->import_id, $this->instance_id );
			
        	if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['property_id'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['property_id'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['property_id'], false );

            $this->log( 'Importing property ' . $property_row .' with reference ' . $property['property_id'], 0, $property['property_id'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = $property['dispAddress'];

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['property_id'], $property, $display_address, $property['summaryText'], '', date("Y-m-d H:i:s", $property['datecreated']) );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['property_id'] );

                update_post_meta( $post_id, $imported_ref_key, $property['property_id'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                $previous_update_date = get_post_meta( $post_id, '_bdp_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['lastUpdated']) ||
						(
							isset($property['lastUpdated']) &&
							empty($property['lastUpdated'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['lastUpdated']) &&
							$property['lastUpdated'] != '' &&
							$previous_update_date != '' &&
							strtotime($property['lastUpdated']) > strtotime($previous_update_date)
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
	                update_post_meta( $post_id, '_reference_number', $property['property_id'] );

	                $name_number_parts = array_filter( array(
	                    isset($property['houseNo']) ? trim( $property['houseNo'] ) : '',
	                ));
	                update_post_meta( $post_id, '_address_name_number', implode( ', ', $name_number_parts ) );

	                update_post_meta( $post_id, '_address_street', ( ( isset($property['streetName']) ) ? $property['streetName'] : '' ) );
	                update_post_meta( $post_id, '_address_two', ( ( isset($property['addrL1']) ) ? $property['addrL1'] : '' ) );
	                update_post_meta( $post_id, '_address_three', ( ( isset($property['addrL2']) ) ? $property['addrL2'] : '' ) );
	                update_post_meta( $post_id, '_address_four', trim ( ( ( isset($property['addrL3']) ) ? $property['addrL3'] : '' ) . ' ' . ( ( isset($property['town']) ) ? $property['town'] : '' ) ) );
	                update_post_meta( $post_id, '_address_postcode', ( ( isset($property['postcode']) ) ? $property['postcode'] : '' ) );

	                $country = get_option( 'propertyhive_default_country', 'GB' );
	                update_post_meta( $post_id, '_address_country', $country );

	                // Coordinates
	                update_post_meta( $post_id, '_latitude', ( ( isset($property['lat']) ) ? $property['lat'] : '' ) );
	                update_post_meta( $post_id, '_longitude', ( ( isset($property['lng']) ) ? $property['lng'] : '' ) );

	                // Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
	                $address_fields_to_check = apply_filters( 'propertyhive_bdp_json_address_fields_to_check', array('addrL1', 'addrL2', 'addrL3', 'town') );
	                $location_term_ids = array();

	                foreach ( $address_fields_to_check as $address_field )
	                {
	                    if ( isset($property[$address_field]) && trim($property[$address_field]) != '' ) 
	                    {
	                        $term = term_exists( trim($property[$address_field]), 'location');
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
	                $negotiator_id = get_current_user_id();
	                update_post_meta( $post_id, '_negotiator_id', $negotiator_id );

	                $office_id = $this->primary_office_id;

	                if ( isset( $property['branch_id'] ) )
	                {
	                    if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
	                    {
	                        foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
	                        {
	                            $explode_branch_code = explode(",", $branch_code);
	                            if ( in_array($property['branch_id'], $explode_branch_code) || in_array($property['branchName'], $explode_branch_code) )
	                            {
	                                $office_id = $ph_office_id;
	                                break;
	                            }
	                        }
	                    }
	                }
	                update_post_meta( $post_id, '_office_id', $office_id );

	                $department = !empty($property['letting']) ? 'residential-lettings' : 'residential-sales';
	                update_post_meta( $post_id, '_department', $department );

	                update_post_meta( $post_id, '_bedrooms', isset($property['bedRooms']) ? $property['bedRooms'] : '' );
	                update_post_meta( $post_id, '_bathrooms', isset($property['bathRooms']) ? $property['bathRooms'] : '' );
	                update_post_meta( $post_id, '_reception_rooms', isset($property['livingRooms']) ? $property['livingRooms'] : '' );
	                //update_post_meta( $post_id, '_council_tax_band', ( isset($property['councilTax']) && !empty($property['councilTax']) ) ? $property['councilTax'] : '' );

	                $prefix = '';
	                $mapping = isset($import_settings['mappings']['property_type']) ? $import_settings['mappings']['property_type'] : array();

	                $property_type_ids = array();
	                if ( isset($property['typeNames']) && is_array($property['typeNames']) && !empty($property['typeNames']) )
	                {
	                    foreach ( $property['typeNames'] as $bdp_type )
	                    {
	                        if ( !empty($mapping) && isset($mapping[$bdp_type]) )
	                        {
	                            $property_type_ids[] = (int)$mapping[$bdp_type];
	                        }
	                        else
	                        {
	                            $this->log( 'Property received with a type (' . $bdp_type . ') that is not mapped', $post_id, $property['property_id'] );

	                            $import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', $bdp_type );
	                        }
	                    }
	                }
	                if ( !empty($property_type_ids) )
	                {
	                    wp_set_object_terms( $post_id, $property_type_ids, $prefix . 'property_type' );
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'property_type' );
	                }

	                // Residential Sales Details
	                if ( $department == 'residential-sales' )
	                {
	                    $price = isset( $property['floatAskingPrice'] ) ? $property['floatAskingPrice'] : 0;

	                    update_post_meta( $post_id, '_price', $price );
	                    update_post_meta( $post_id, '_price_actual', $price );

	                    update_post_meta( $post_id, '_currency', 'GBP' );

	                    $poa = ( strpos( strtolower($property['priceTypeLabel']), 'application' ) !== FALSE || strpos( strtolower($property['priceTypeLabel']), 'poa' ) !== FALSE ) ? 'yes' : '';
	                    update_post_meta( $post_id, '_poa', $poa );

	                    // Price Qualifier
	                    $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();

	                    if ( !empty($mapping) && isset($property['priceTypeLabel']) && isset($mapping[$property['priceTypeLabel']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['priceTypeLabel']], 'price_qualifier' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
	                    }

	                    // Tenure
	                    $mapping = isset($import_settings['mappings']['tenure']) ? $import_settings['mappings']['tenure'] : array();

	                    if ( !empty($mapping) && isset($property['tenureType']) && isset($mapping[$property['tenureType']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['tenureType']], 'tenure' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'tenure' );
	                    }
	                }
	                elseif ( $department == 'residential-lettings' )
	                {
	                    $price = isset( $property['floatAskingPrice'] ) ? $property['floatAskingPrice'] : 0;

	                    update_post_meta( $post_id, '_rent', $price );

	                    $rent_frequency = 'pcm';
	                    $price_actual = $price;
	                    if ( isset( $property['letFrequency'] ) )
	                    {
	                        /*switch ( $property['letFrequency'] )
	                        {
	                            case 'monthly': { $rent_frequency = 'pcm'; $price_actual = $price; break; }
	                            case 'weekly': { $rent_frequency = 'pw'; $price_actual = ($price * 52) / 12; break; }
	                            case 'yearly': { $rent_frequency = 'pa'; $price_actual = $price / 12; break; }
	                        }*/
	                    }
	                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
	                    update_post_meta( $post_id, '_price_actual', $price_actual );

	                    update_post_meta( $post_id, '_currency', 'GBP' );

	                    update_post_meta( $post_id, '_deposit', '' );
	                    update_post_meta( $post_id, '_available_date', '' );

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
	                    update_post_meta( $post_id, '_featured', $property['featured'] == '1' ? 'yes' : '' );
	                }
	                
	                // Availability
	                $mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
	                    $import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] :
	                    array();

	                if ( !empty($mapping) )
	                {
	                	if ( $department == 'residential-sales' )
	                	{
	                		if (
	                			isset($property['sellingStatus']) &&
	                        	isset($mapping[$property['sellingStatus']])
	                		)
	                		{
	                			wp_set_object_terms( $post_id, (int)$mapping[$property['sellingStatus']], 'availability' );
	                		}
	                		else
	                		{
	                			wp_delete_object_term_relationships( $post_id, 'availability' );
	                		}
	                	}
	                	elseif ( $department == 'residential-lettings' )
	                	{
	                		if (
	                			isset($property['letting']['status']) &&
	                        	isset($mapping[$property['letting']['status']])
	                		)
	                		{
	                			wp_set_object_terms( $post_id, (int)$mapping[$property['letting']['status']], 'availability' );
	                		}
	                		else
	                		{
	                			wp_delete_object_term_relationships( $post_id, 'availability' );
	                		}
	                	}
	                }

	                // Parking
	                $mapping = isset($import_settings['mappings']['parking']) ? $import_settings['mappings']['parking'] : array();
	                
	                if ( isset($property['parkingType']) && !empty($property['parkingType']) )
	                {
	                    if ( !empty($mapping) && isset($mapping[$property['parkingType']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['parkingType']], 'parking' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'parking' );

	                        $this->log( 'Property received with a parking (' . $property['parkingType'] . ') that is not mapped', $post_id, $property['property_id'] );

	                        $import_settings = $this->add_missing_mapping( $mapping, 'parking', $property['parkingType'] );
	                    }
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'parking' );
	                }

	                // Outside Space
	                $mapping = isset($import_settings['mappings']['outside_space']) ? $import_settings['mappings']['outside_space'] : array();

	                if ( isset($property['gardenType']) && !empty($property['gardenType']) )
	                {
	                    if ( !empty($mapping) && isset($mapping[$property['gardenType']]) )
	                    {
	                        wp_set_object_terms( $post_id, (int)$mapping[$property['gardenType']], 'outside_space' );
	                    }
	                    else
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'outside_space' );

	                        $this->log( 'Property received with an outside_space (' . $property['gardenType'] . ') that is not mapped', $post_id, $property['property_id'] );

	                        $import_settings = $this->add_missing_mapping( $mapping, 'outside_space', $property['gardenType'] );
	                    }
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'outside_space' );
	                }

	                // Features
	                $features = array();
	                if ( isset($property['featureList']) && !empty($property['featureList']) )
	                {
	                    /*foreach ( $property['specialFeatures'] as $feature )
	                    {
	                        $features[] = trim($feature);
	                    }*/
	                }

	                update_post_meta( $post_id, '_features', count( $features ) );
	                
	                $i = 0;
	                foreach ( $features as $feature )
	                {
	                    update_post_meta( $post_id, '_feature_' . $i, $feature );
	                    ++$i;
	                }

	                // Rooms
	                $rooms_count = 0;
	                if ( isset( $property['descText'] ) && !empty( $property['descText'] ) )
	                {
	                    update_post_meta( $post_id, '_room_name_0', '' );
	                    update_post_meta( $post_id, '_room_dimensions_0', '' );
	                    update_post_meta( $post_id, '_room_description_0', $property['descText'] );

	                    $rooms_count++;
	                }

	                if ( isset( $property['rooms'] ) && is_array( $property['rooms'] ) )
	                {
	                    foreach( $property['rooms'] as $room )
	                    {
	                        if ( $room['active'] == 1 )
	                        {
	                            update_post_meta( $post_id, '_room_name_' . $rooms_count, $room['roomName'] );
	                            update_post_meta( $post_id, '_room_dimensions_' . $rooms_count, ( ( isset($room['roomWidth']) && !empty($room['roomWidth']) ) ? $room['roomWidth'] . ' x ' . $room['roomLength'] . '' : '' ) );
	                            update_post_meta( $post_id, '_room_description_' . $rooms_count, $room['roomDesc'] );

	                            $rooms_count++;
	                        }
	                    }
	                }

	                if ( $rooms_count > 0 )
	                {
	                    update_post_meta( $post_id, '_rooms', $rooms_count );
	                }

	                // Media - Images
				    $media = array();
				    if (isset($property['images']) && !empty($property['images']))
	                {
	                    foreach ($property['images'] as $photo)
	                    {
							$media[] = array(
								'url' => $photo['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['property_id'], 'photo', $media, false );

					// Media - Floorplans
				    $media = array();
				    if (isset($property['floorPlanPdfPathPublic']) && !empty($property['floorPlanPdfPathPublic']))
	                {
						$media[] = array(
							'url' => $property['floorPlanPdfPathPublic'],
						);
					}

					$this->import_media( $post_id, $property['property_id'], 'floorplan', $media, false );

					// Media - Brochures
				    $media = array();
				    if (isset($property['brochurePathPublic']) && !empty($property['brochurePathPublic']))
	                {
						$media[] = array(
							'url' => $property['brochurePathPublic'],
						);
					}

					$this->import_media( $post_id, $property['property_id'], 'brochure', $media, false );

					// Media - EPCs
				    $media = array();
				    if (isset($property['epcDocPathPublic']) && !empty($property['epcDocPathPublic']))
	                {
						$media[] = array(
							'url' => $property['epcDocPathPublic'],
						);
					}

					$this->import_media( $post_id, $property['property_id'], 'epc', $media, false );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['property_id'] );
				}

				if ( isset($property['lastUpdated']) ) { update_post_meta( $post_id, '_bdp_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['lastUpdated'])) ); }
				
                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_bdp_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['property_id'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;
        }

        do_action( "propertyhive_post_import_properties_bdp_json" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $import_refs[] = $property['property_id'];
        }

        $this->do_remove_old_properties( $import_refs );

		unset($import_refs);
    }

    public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'Available' => 'Available',
                'Under Offer' => 'Under Offer',
            ),
            'lettings_availability' => array(
                'Available' => 'Available',
            ),
            'property_type' => array(
                'Bungalow' => 'Bungalow',
                'Detached Bungalow' => 'Detached Bungalow',
                'House' => 'House',
                'Detached' => 'Detached',
                'Semi-Detached' => 'Semi-Detached',
                'Terraced' => 'Terraced',
                'Townhouse' => 'Townhouse',
                'Flat / Apartment' => 'Flat / Apartment',
            ),
            'price_qualifier' => array(
                'Fixed Price' => 'Fixed Price',
                'Offers Over' => 'Offers Over',
            ),
            'tenure' => array(
                'Freehold' => 'Freehold',
                'Leasehold' => 'Leasehold',
            ),
            'outside_space' => array(
                'Rear' => 'Rear',
                'Front &amp; Rear' => 'Front &amp; Rear',
                'Shared/Communal' => 'Shared/Communal',
                'Private' => 'Private',
            ),
            'parking' => array(
                'Communal' => 'Communal',
                'Permit Holders' => 'Permit Holders',
                'Residents Car Park' => 'Residents Car Park',
                'Driveway' => 'Driveway',
                'Garage' => 'Garage',
            ),
        );
	}
}

}