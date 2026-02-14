<?php
/**
 * Class for managing the import process of an Apimo JSON file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Apimo_JSON_Import extends PH_Property_Import_Process {

    public function __construct(  $instance_id = '', $import_id = ''  )
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

        $current_page = 1;
        $per_page = 1000;
        $more_properties = true;

        $limit = $this->get_property_limit();

        while ( $more_properties )
        {
            $url = 'https://api.apimo.pro/agencies/' . $import_settings['agency_id'] . '/properties?limit=' . $per_page;
            $url .= '&offset=' . ( $per_page * ( $current_page - 1 ) );
            $url .= '&step=1';

            $headers = array(
                'Authorization' => 'Basic ' . base64_encode($import_settings['provider_id'] . ':' . $import_settings['token']),
            );

            $response = wp_remote_request(
                $url,
                array(
                    'method' => 'GET',
                    'timeout' => 360,
                    'headers' => $headers
                )
            );

            if ( is_wp_error( $response ) )
            {
                $this->log_error( 'Response: ' . $response->get_error_message() );

                return false;
            }

            $json = json_decode( $response['body'], TRUE );

            if ($json !== FALSE)
            {
                $this->log("Parsing properties on page " . $current_page);

                if ( isset($json['total_items']) )
                {
                    if ( $json['total_items'] == 0 )
                    {
                        $more_properties = false;
                    }
                    else
                    {
                        $total_pages = ceil( $json['total_items'] / $per_page );

                        if ( $current_page == $total_pages )
                        {
                            $more_properties = false;
                        }
                    }
                }
                else
                {
                    $this->log_error( 'No pagination element found in response. This should always exist so likely something went wrong. As a result we\'ll play it safe and not continue further.' );
                    
                    return false;
                }

                if ( isset($json['properties']) )
                {
                    foreach ($json['properties'] as $property)
                    {
                    	if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
		                {
		                    return true;
		                }

                        $this->properties[] = $property;
                    }
                }

                ++$current_page;
            }
            else
            {
                // Failed to parse JSON
                $this->log_error( 'Failed to parse JSON.' );

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

        do_action( "propertyhive_pre_import_properties_apimo_json", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_apimo_json_properties_due_import", $this->properties, $this->import_id );

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
			do_action( "propertyhive_property_importing_apimo_json", $property, $this->import_id, $this->instance_id );
			
        	if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property['id'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property['id'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property['id'], false );

            $this->log( 'Importing property ' . $property_row .' with reference ' . $property['id'], 0, $property['id'], '', false );

            $this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

            $display_address = '';
            $post_content = '';

            if ( isset($property['comments']) && !empty($property['comments']) )
            {
                foreach ( $property['comments'] as $comment )
                {
                    if ( isset($comment['language']) && $comment['language'] == 'en' )
                    {
                        if ( isset($comment['title']) && !empty($comment['title']) ) { $display_address = $comment['title']; }
                        if ( isset($comment['comment']) && !empty($comment['comment']) ) { $post_content = $comment['comment']; }
                        break;
                    }
                }
            }

            if ( empty($display_address) )
            {
                $display_address = array();
                if ( isset($property['publish_address']) && $property['publish_address'] === true && !empty($property['address']) )
                {
                    $display_address[] = $property['address'];
                }
                if ( isset($property['district']['name']) && !empty($property['district']['name']) )
                {
                    $display_address[] = $property['district']['name'];
                }
                if ( isset($property['city']['name']) && !empty($property['city']['name']) )
                {
                    $display_address[] = $property['city']['name'];
                }
                if ( isset($property['region']['name']) && !empty($property['region']['name']) )
                {
                    $display_address[] = $property['region']['name'];
                }
                $display_address = implode(", ", $display_address);
            }

            list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $post_content );

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

                $this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property['id'] );

                update_post_meta( $post_id, $imported_ref_key, $property['id'] );

                update_post_meta( $post_id, '_property_import_data', json_encode($property, JSON_PRETTY_PRINT) );

                $previous_update_date = get_post_meta( $post_id, '_apimo_update_date_' . $this->import_id, TRUE);

				$skip_property = true;
				if ( isset($import_settings['only_updated']) && $import_settings['only_updated'] == 'yes' )
				{
					if (
						$inserted_updated == 'inserted' ||
						!isset($property['updated_at']) ||
						(
							isset($property['updated_at']) &&
							empty($property['updated_at'])
						) ||
						$previous_update_date == '' ||
						(
							isset($property['updated_at']) &&
							$property['updated_at'] != '' &&
							$previous_update_date != '' &&
							strtotime($property['updated_at']) > strtotime($previous_update_date)
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
	                update_post_meta( $post_id, '_reference_number', $property['reference'] );

	                update_post_meta( $post_id, '_address_name_number', '' );
	                update_post_meta( $post_id, '_address_street', '' );
	                update_post_meta( $post_id, '_address_two', ( ( isset($property['district']['name']) ) ? $property['district']['name'] : '' ) );
	                update_post_meta( $post_id, '_address_three', ( ( isset($property['city']['name']) ) ? $property['city']['name'] : '' ) );
	                update_post_meta( $post_id, '_address_four', ( ( isset($property['region']['line_3']) ) ? $property['region']['line_3'] : '' ) );
	                update_post_meta( $post_id, '_address_postcode', ( ( isset($property['city']['zipcode']) ) ? $property['city']['zipcode'] : '' ) );

	                $country = get_option( 'propertyhive_default_country', 'GB' );
	                update_post_meta( $post_id, '_address_country', $country );

	                // Coordinates
	                update_post_meta( $post_id, '_latitude', ( ( isset($property['latitude']) ) ? $property['latitude'] : '' ) );
	                update_post_meta( $post_id, '_longitude', ( ( isset($property['longitude']) ) ? $property['longitude'] : '' ) );

	                // Owner
	                add_post_meta( $post_id, '_owner_contact_id', '', true );

	                // Record Details
	                $negotiator_id = get_current_user_id();
	                update_post_meta( $post_id, '_negotiator_id', $negotiator_id );

	                $office_id = $this->primary_office_id;
	                update_post_meta( $post_id, '_office_id', $office_id );

	                $department = 'residential-sales';
	                if ( $property['category'] == 2 || $property['category'] == 3 )
	                {
	                    $department = 'residential-lettings';
	                }

	                update_post_meta( $post_id, '_department', $department );

	                update_post_meta( $post_id, '_bedrooms', isset($property['bedrooms']) ? $property['bedrooms'] : '' );
	                update_post_meta( $post_id, '_bathrooms', '' );
	                update_post_meta( $post_id, '_reception_rooms', '' );

	                $mapping = isset($import_options['mappings']['property_type']) ? $import_options['mappings']['property_type'] : array();
	                
	                if ( isset($property['type']) && !empty($property['type']) )
	                {
	                    $type_mapped = false;

	                    if ( 
	                        isset($property['type']) && 
	                        $property['type'] != '' &&
	                        isset($property['subtype']) && 
	                        $property['subtype'] != ''
	                    )
	                    {
	                        if ( 
	                            isset($mapping[$property['type'] . ' - ' . $property['subtype']]) && 
	                            !empty($mapping[$property['type'] . ' - ' . $property['subtype']]) 
	                        )
	                        {
	                            wp_set_object_terms( $post_id, (int)$mapping[$property['type'] . ' - ' . $property['subtype']], "property_type" );
	                            $type_mapped = true;
	                        }
	                        else
	                        {
	                            $this->log( 'Received property type of ' . $property['type'] . ' - ' . $property['subtype'] . ' that is not mapped', $post_id, $property['id'] );

	                            $import_options = $this->add_missing_mapping( $mapping, 'property_type', $property['type'] . ' - ' . $property['subtype'], $post_id );
	                        }
	                    }

	                    if ( !$type_mapped )
	                    {
	                        if ( isset($mapping[$property['type']]) && !empty($mapping[$property['type']]) )
	                        {
	                            wp_set_object_terms( $post_id, (int)$mapping[$property['type']], "property_type" );
	                            $type_mapped = true;
	                        }
	                        else
	                        {
	                            $this->log( 'Received property type of ' . $property['type'] . ' that is not mapped', $post_id, $property['id'] );

	                            $import_options = $this->add_missing_mapping( $mapping, 'property_type', $property['type'], $post_id );
	                        }
	                    }

	                    if ( !$type_mapped )
	                    {
	                    	wp_delete_object_term_relationships( $post_id, 'property_type' );
	                    }
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'property_type' );
	                }

	                // Residential Sales Details
	                if ( $department == 'residential-sales' )
	                {
	                    $price = '';
	                    if ( isset($property['price']['value']) && !empty($property['price']['value']) )
	                    {
	                        $price = round(preg_replace("/[^0-9.]/", '', $property['price']['value']));
	                    }

	                    update_post_meta( $post_id, '_price', $price );

	                    update_post_meta( $post_id, '_currency', 'EUR' );

	                    $poa = ( isset($property['price']['hide']) && $property['price']['hide'] === true ) ? 'yes' : '';
	                    update_post_meta( $post_id, '_poa', $poa );
	                }
	                elseif ( $department == 'residential-lettings' )
	                {
	                    $price = '';
	                    if ( isset($property['price']['value']) && !empty($property['price']['value']) )
	                    {
	                        $price = round(preg_replace("/[^0-9.]/", '', $property['price']['value']));
	                    }

	                    update_post_meta( $post_id, '_rent', $price );

	                    $rent_frequency = 'pcm';
	                    if ( isset($property['price']['period']) )
	                    {
	                        switch ( $property['price']['period'] )
	                        {
	                            case "1": { $rent_frequency = 'pd'; }
	                            case "2": { $rent_frequency = 'pw'; }
	                            case "3": { $rent_frequency = 'pw'; }
	                            case "4": { $rent_frequency = 'pcm'; }
	                            case "5": { $rent_frequency = 'pq'; }
	                            case "6": { $rent_frequency = 'pcm'; }
	                            case "7": { $rent_frequency = 'pq'; }
	                            case "8": { $rent_frequency = 'pa'; }
	                        }
	                    }
	                    update_post_meta( $post_id, '_rent_frequency', $rent_frequency );

	                    update_post_meta( $post_id, '_currency', 'EUR' );

	                    update_post_meta( $post_id, '_deposit', '' );
	                    update_post_meta( $post_id, '_available_date', '' );
	                }

	                $ph_countries = new PH_Countries();
	                $ph_countries->update_property_price_actual( $post_id );

	                // Marketing
	                $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
	                add_post_meta( $post_id, '_featured', 0, true );
	                
	                // Availability
	                $mapping = isset($import_options['mappings'][str_replace('residential-', '', $department) . '_availability']) ?
	                    $import_options['mappings'][str_replace('residential-', '', $department) . '_availability'] :
	                    array();
	                
	                if ( 
	                    !empty($mapping) &&
	                    isset($property['status']) &&
	                    isset($mapping[$property['status']])
	                )
	                {
	                    wp_set_object_terms( $post_id, (int)$mapping[$property['status']], 'availability' );
	                }
	                else
	                {
	                	wp_delete_object_term_relationships( $post_id, 'availability' );
	                }

	                // Rooms
	                $rooms_count = 0;
	                if ( !empty($post_content) )
	                {
	                    update_post_meta( $post_id, '_room_name_0', '' );
	                    update_post_meta( $post_id, '_room_dimensions_0', '' );
	                    update_post_meta( $post_id, '_room_description_0', $post_content );

	                    ++$rooms_count;
	                }
	                update_post_meta( $post_id, '_rooms', $rooms_count );
	                
	                // Media - Images
				    $media = array();
				    if (isset($property['pictures']) && !empty($property['pictures']))
	                {
	                    foreach ($property['pictures'] as $image)
	                    {
							$media[] = array(
								'url' => $image['url'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, false );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['updated_at']) ) { update_post_meta( $post_id, '_apimo_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['updated_at'])) ); }

                do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
                do_action( "propertyhive_property_imported_apimo_json", $post_id, $property, $this->import_id );

                $post = get_post( $post_id );
                do_action( "save_post_property", $post_id, $post, false );
                do_action( "save_post", $post_id, $post, false );

                if ( $inserted_updated == 'updated' )
                {
                    $this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
                }
            }

            ++$property_row;
        }

        do_action( "propertyhive_post_import_properties_apimo_json" );

        $this->import_end();
    }

    public function remove_old_properties()
    {
        global $wpdb, $post;

        $import_refs = array();
        foreach ($this->properties as $property)
        {
            $import_refs[] = $property['id'];
        }

        $this->do_remove_old_properties( $import_refs );

		unset($import_refs);
    }

    public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                '1' => 'In progress',
                '20' => 'Waiting for agreement',
                '21' => 'Agreement ended',
                '22' => 'Offer',
                '24' => 'Processing sale',
                '25' => 'Waiting for contract',
                '28' => 'Pending approval',
            ),
            'lettings_availability' => array(
                '1' => 'In progress',
                '20' => 'Waiting for agreement',
                '21' => 'Agreement ended',
                '22' => 'Offer',
                '24' => 'Processing sale',
                '25' => 'Waiting for contract',
                '28' => 'Pending approval',
            ),
            'property_type' => array(
                '1' => 'Apartment',
                '2' => 'House',
                '3' => 'Land',
                '4' => 'Business',
                '5' => 'Garage/Parking',
                '6' => 'Building',
                '7' => 'Office',
                '8' => 'Boat',
                '9' => 'Warehouse',
                '10' => 'Cellar / Box',
            )
        );
	}
}

}