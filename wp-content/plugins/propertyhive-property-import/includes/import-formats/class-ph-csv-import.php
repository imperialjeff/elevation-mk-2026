<?php
/**
 * Class for managing the import process of a generic CSV file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_CSV_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['csv_url'] ) ) 
			{
			    $import_settings['csv_url'] = sanitize_url( wp_unslash( $_POST['csv_url'] ) );
			}
		}

		if ( !isset($import_settings['property_id_field']) || ( isset($import_settings['property_id_field']) && empty($import_settings['property_id_field']) ) )
		{
			$this->log_error( 'Please ensure you have a field specified that we can use as the unique property identifer in the import setting under the \'Import Format\' tab and that it has a value set in the CSV' );
			return false;
		}
		
		$contents = '';

		$args = array( 'timeout' => 360, 'sslverify' => false );
        $args = apply_filters( 'propertyhive_property_import_csv_request_args', $args, $this->import_id );
		$response = wp_remote_get( $import_settings['csv_url'], $args );

		if ( wp_remote_retrieve_response_code($response) !== 200 )
        {
            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
            return false;
        }
		
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain CSV. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		$encoding = mb_detect_encoding($contents, 'UTF-8, ISO-8859-1', true);
        if ( $encoding !== 'UTF-8' )
        {
            $contents = mb_convert_encoding($contents, 'UTF-8', $encoding);
        }

		$temp = tmpfile();
		fwrite($temp, $contents);
		fseek($temp, 0);

		$fields = array(); 
		$i = 0;

		while ( ($row = fgetcsv($temp, 10000, ( isset($import_settings['csv_delimiter']) ? $import_settings['csv_delimiter'] : ',' ))) !== false ) 
		{
	        if ( empty($fields) ) 
	        {
	            $fields = $row;
	            continue;
	        }
	        foreach ( $row as $k => $value ) 
	        {
	        	if ( !isset($this->properties[$i]) ) { $this->properties[$i] = array(); }
	            $this->properties[$i][$fields[$k]] = $value;
	        }
	        ++$i;
	    }
	    fclose($temp);

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

        do_action( "propertyhive_pre_import_properties_csv", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_csv_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$property_id_field = $import_settings['property_id_field'];

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_csv", $property, $this->import_id, $this->instance_id );
            
            if ( !isset($property[$property_id_field]) || empty($property[$property_id_field]) )
			{
				$this->log_error( 'Unique ID empty. Please ensure you have a field specified that we can use as the unique identifier in the import setting under the \'Format\' tab and that is has a value set in the CSV' );
				continue;
			}

			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property[$property_id_field] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property[$property_id_field] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property[$property_id_field], false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . $property[$property_id_field], 0, $property[$property_id_field], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property[$property_id_field], $property, apply_filters( 'propertyhive_property_import_csv_mapped_field_value', '', $property, 'post_title', $this->import_id ), apply_filters( 'propertyhive_property_import_csv_mapped_field_value', '', $property, 'post_excerpt', $this->import_id ) );

			if ( $inserted_updated !== FALSE )
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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property[$property_id_field] );

				update_post_meta( $post_id, $imported_ref_key, $property[$property_id_field] );

				update_post_meta( $post_id, '_property_import_data', print_r($property, true) );

			    // Media - Images
			    $media = array();
			    if ( !isset($import_settings['image_field_arrangement']) || ( isset($import_settings['image_field_arrangement']) && $import_settings['image_field_arrangement'] == '' ) )
				{
					if ( isset($import_settings['image_fields']) && !empty($import_settings['image_fields']) )
					{
						$explode_media = explode("\n", $import_settings['image_fields']);

						foreach ( $explode_media as $media_item )
						{
							$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

							$url = trim($explode_media_item[0]);
							$url = apply_filters( 'propertyhive_property_import_csv_image_url', $url, $this->import_id );
							$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

							preg_match_all('/{[^}]*}/', $url, $matches);
			                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
			                {
			                    foreach ( $matches[0] as $match )
			                    {
			                    	// foreach field in xpath
			                        $field_name = str_replace(array("{", "}"), "", $match);

			                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

		                            if ( $value_to_check === false )
		                            {
		                                $value_to_check = '';
		                            }

			                        $url = trim(str_replace($match, $value_to_check, $url));
			                    }
			                }

							if ( !empty($description) )
							{
								preg_match_all('/{[^}]*}/', $description, $matches);
				                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
				                {
				                    foreach ( $matches[0] as $match )
				                    {
				                    	// foreach field in xpath
				                        $field_name = str_replace(array("{", "}"), "", $match);

				                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

			                            if ( $value_to_check === false )
			                            {
			                                $value_to_check = '';
			                            }

				                        $description = trim(str_replace($match, $value_to_check, $description));
				                    }
				                }
							}

							$media[] = array(
								'url' => $url,
								'description' => $description,
							);
						}
					}
				}
				elseif ( isset($import_settings['image_field_arrangement']) && $import_settings['image_field_arrangement'] == 'comma_delimited' )
				{
					if ( isset($import_settings['image_field']) && !empty($import_settings['image_field']) )
					{
						$value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $import_settings['image_field'] );

                        if ( $value_to_check !== false )
                        {
                            // we found this field
                        	$delimiter = ( isset($import_settings['image_field_delimiter']) && !empty($import_settings['image_field_delimiter']) ) ? $import_settings['image_field_delimiter'] : ',';

                            $explode_image_urls = explode($delimiter, $value_to_check);
                            $explode_image_urls = array_map('trim', $explode_image_urls);
                            $explode_image_urls = array_filter($explode_image_urls);

                            if ( !empty($explode_image_urls) )
                            {
                            	// we found image URLs
                            	foreach ( $explode_image_urls as $url )
                            	{
                            		$url = apply_filters( 'propertyhive_property_import_csv_image_url', trim($url), $this->import_id );

                            		$media[] = array(
										'url' => $url,
									);
                            	}
                            }
                        }
                    }
                }

				$this->import_media( $post_id, $property[$property_id_field], 'photo', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - Floorplans
			    $media = array();
			    if ( !isset($import_settings['floorplan_field_arrangement']) || ( isset($import_settings['floorplan_field_arrangement']) && $import_settings['floorplan_field_arrangement'] == '' ) )
				{
					if ( isset($import_settings['floorplan_fields']) && !empty($import_settings['floorplan_fields']) )
					{
						$explode_media = explode("\n", $import_settings['floorplan_fields']);

						foreach ( $explode_media as $media_item )
						{
							$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

							$url = trim($explode_media_item[0]);
							$url = apply_filters( 'propertyhive_property_import_csv_floorplan_url', $url, $this->import_id );
							$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

							preg_match_all('/{[^}]*}/', $url, $matches);
			                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
			                {
			                    foreach ( $matches[0] as $match )
			                    {
			                    	// foreach field in xpath
			                        $field_name = str_replace(array("{", "}"), "", $match);

			                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

		                            if ( $value_to_check === false )
		                            {
		                                $value_to_check = '';
		                            }

			                        $url = trim(str_replace($match, $value_to_check, $url));
			                    }
			                }

							if ( !empty($description) )
							{
								preg_match_all('/{[^}]*}/', $description, $matches);
				                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
				                {
				                    foreach ( $matches[0] as $match )
				                    {
				                    	// foreach field in xpath
				                        $field_name = str_replace(array("{", "}"), "", $match);

				                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

			                            if ( $value_to_check === false )
			                            {
			                                $value_to_check = '';
			                            }

				                        $description = trim(str_replace($match, $value_to_check, $description));
				                    }
				                }
							}

							$media[] = array(
								'url' => $url,
								'description' => $description,
							);
						}
					}
				}
				elseif ( isset($import_settings['floorplan_field_arrangement']) && $import_settings['floorplan_field_arrangement'] == 'comma_delimited' )
				{
					if ( isset($import_settings['floorplan_field']) && !empty($import_settings['floorplan_field']) )
					{
						$value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $import_settings['floorplan_field'] );

                        if ( $value_to_check !== false )
                        {
                            // we found this field
                        	$delimiter = ( isset($import_settings['floorplan_field_delimiter']) && !empty($import_settings['floorplan_field_delimiter']) ) ? $import_settings['floorplan_field_delimiter'] : ',';

                            $explode_floorplan_urls = explode($delimiter, $value_to_check);
                            $explode_floorplan_urls = array_map('trim', $explode_floorplan_urls);
                            $explode_floorplan_urls = array_filter($explode_floorplan_urls);

                            if ( !empty($explode_floorplan_urls) )
                            {
                            	// we found floorplan URLs
                            	foreach ( $explode_floorplan_urls as $url )
                            	{
                            		$url = apply_filters( 'propertyhive_property_import_csv_floorplan_url', trim($url), $this->import_id );

                            		$media[] = array(
										'url' => $url,
									);
                            	}
                            }
                        }
                    }
                }

				$this->import_media( $post_id, $property[$property_id_field], 'floorplan', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - Brochures
			    $media = array();
			    if ( !isset($import_settings['brochure_field_arrangement']) || ( isset($import_settings['brochure_field_arrangement']) && $import_settings['brochure_field_arrangement'] == '' ) )
				{
					if ( isset($import_settings['brochure_fields']) && !empty($import_settings['brochure_fields']) )
					{
						$explode_media = explode("\n", $import_settings['brochure_fields']);

						foreach ( $explode_media as $media_item )
						{
							$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

							$url = trim($explode_media_item[0]);
							$url = apply_filters( 'propertyhive_property_import_csv_brochure_url', $url, $this->import_id );
							$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

							preg_match_all('/{[^}]*}/', $url, $matches);
			                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
			                {
			                    foreach ( $matches[0] as $match )
			                    {
			                    	// foreach field in xpath
			                        $field_name = str_replace(array("{", "}"), "", $match);

			                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

		                            if ( $value_to_check === false )
		                            {
		                                $value_to_check = '';
		                            }

			                        $url = trim(str_replace($match, $value_to_check, $url));
			                    }
			                }

							if ( !empty($description) )
							{
								preg_match_all('/{[^}]*}/', $description, $matches);
				                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
				                {
				                    foreach ( $matches[0] as $match )
				                    {
				                    	// foreach field in xpath
				                        $field_name = str_replace(array("{", "}"), "", $match);

				                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

			                            if ( $value_to_check === false )
			                            {
			                                $value_to_check = '';
			                            }

				                        $description = trim(str_replace($match, $value_to_check, $description));
				                    }
				                }
							}

							$media[] = array(
								'url' => $url,
								'description' => $description,
							);
						}
					}
				}
				elseif ( isset($import_settings['brochure_field_arrangement']) && $import_settings['brochure_field_arrangement'] == 'comma_delimited' )
				{
					if ( isset($import_settings['brochure_field']) && !empty($import_settings['brochure_field']) )
					{
						$value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $import_settings['brochure_field'] );

                        if ( $value_to_check !== false )
                        {
                            // we found this field
                        	$delimiter = ( isset($import_settings['brochure_field_delimiter']) && !empty($import_settings['brochure_field_delimiter']) ) ? $import_settings['brochure_field_delimiter'] : ',';

                            $explode_brochure_urls = explode($delimiter, $value_to_check);
                            $explode_brochure_urls = array_map('trim', $explode_brochure_urls);
                            $explode_brochure_urls = array_filter($explode_brochure_urls);

                            if ( !empty($explode_brochure_urls) )
                            {
                            	// we found brochure URLs
                            	foreach ( $explode_brochure_urls as $url )
                            	{
                            		$url = apply_filters( 'propertyhive_property_import_csv_brochure_url', trim($url), $this->import_id );

                            		$media[] = array(
										'url' => $url,
									);
                            	}
                            }
                        }
                    }
                }

				$this->import_media( $post_id, $property[$property_id_field], 'brochure', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - EPCs
			    $media = array();
			    if ( !isset($import_settings['epc_field_arrangement']) || ( isset($import_settings['epc_field_arrangement']) && $import_settings['epc_field_arrangement'] == '' ) )
				{
					if ( isset($import_settings['epc_fields']) && !empty($import_settings['epc_fields']) )
					{
						$explode_media = explode("\n", $import_settings['epc_fields']);

						foreach ( $explode_media as $media_item )
						{
							$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

							$url = trim($explode_media_item[0]);
							$url = apply_filters( 'propertyhive_property_import_csv_epc_url', $url, $this->import_id );
							$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

							preg_match_all('/{[^}]*}/', $url, $matches);
			                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
			                {
			                    foreach ( $matches[0] as $match )
			                    {
			                    	// foreach field in xpath
			                        $field_name = str_replace(array("{", "}"), "", $match);

			                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

		                            if ( $value_to_check === false )
		                            {
		                                $value_to_check = '';
		                            }

			                        $url = trim(str_replace($match, $value_to_check, $url));
			                    }
			                }

							if ( !empty($description) )
							{
								preg_match_all('/{[^}]*}/', $description, $matches);
				                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
				                {
				                    foreach ( $matches[0] as $match )
				                    {
				                    	// foreach field in xpath
				                        $field_name = str_replace(array("{", "}"), "", $match);

				                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

			                            if ( $value_to_check === false )
			                            {
			                                $value_to_check = '';
			                            }

				                        $description = trim(str_replace($match, $value_to_check, $description));
				                    }
				                }
							}

							$media[] = array(
								'url' => $url,
								'description' => $description,
							);
						}
					}
				}
				elseif ( isset($import_settings['epc_field_arrangement']) && $import_settings['epc_field_arrangement'] == 'comma_delimited' )
				{
					if ( isset($import_settings['epc_field']) && !empty($import_settings['epc_field']) )
					{
						$value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $import_settings['epc_field'] );

                        if ( $value_to_check !== false )
                        {
                            // we found this field
                        	$delimiter = ( isset($import_settings['epc_field_delimiter']) && !empty($import_settings['epc_field_delimiter']) ) ? $import_settings['epc_field_delimiter'] : ',';

                            $explode_epc_urls = explode($delimiter, $value_to_check);
                            $explode_epc_urls = array_map('trim', $explode_epc_urls);
                            $explode_epc_urls = array_filter($explode_epc_urls);

                            if ( !empty($explode_epc_urls) )
                            {
                            	// we found EPC URLs
                            	foreach ( $explode_epc_urls as $url )
                            	{
                            		$url = apply_filters( 'propertyhive_property_import_csv_epc_url', trim($url), $this->import_id );

                            		$media[] = array(
										'url' => $url,
									);
                            	}
                            }
                        }
                    }
                }

				$this->import_media( $post_id, $property[$property_id_field], 'epc', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - Virtual Tours
			    $virtual_tours = array();
			    if ( !isset($import_settings['virtual_tour_field_arrangement']) || ( isset($import_settings['virtual_tour_field_arrangement']) && $import_settings['virtual_tour_field_arrangement'] == '' ) )
				{
					if ( isset($import_settings['virtual_tour_fields']) && !empty($import_settings['virtual_tour_fields']) )
					{
						$explode_media = explode("\n", $import_settings['virtual_tour_fields']);

						foreach ( $explode_media as $media_item )
						{
							$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

							$url = trim($explode_media_item[0]);
							$url = apply_filters( 'propertyhive_property_import_csv_virtual_tour_url', $url, $this->import_id );
							$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

							preg_match_all('/{[^}]*}/', $url, $matches);
			                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
			                {
			                    foreach ( $matches[0] as $match )
			                    {
			                    	// foreach field in xpath
			                        $field_name = str_replace(array("{", "}"), "", $match);

			                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

		                            if ( $value_to_check === false )
		                            {
		                                $value_to_check = '';
		                            }

			                        $url = trim(str_replace($match, $value_to_check, $url));
			                    }
			                }

							if ( !empty($description) )
							{
								preg_match_all('/{[^}]*}/', $description, $matches);
				                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
				                {
				                    foreach ( $matches[0] as $match )
				                    {
				                    	// foreach field in xpath
				                        $field_name = str_replace(array("{", "}"), "", $match);

				                        $value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $field_name );

			                            if ( $value_to_check === false )
			                            {
			                                $value_to_check = '';
			                            }

				                        $description = trim(str_replace($match, $value_to_check, $description));
				                    }
				                }
							}

							$virtual_tours[] = array(
								'url' => $url,
								'label' => $description,
							);
						}
					}
				}
				elseif ( isset($import_settings['virtual_tour_field_arrangement']) && $import_settings['virtual_tour_field_arrangement'] == 'comma_delimited' )
				{
					if ( isset($import_settings['virtual_tour_field']) && !empty($import_settings['virtual_tour_field']) )
					{
						$value_to_check = propertyhive_property_import_check_array_for_matching_key( $property, $import_settings['virtual_tour_field'] );

                        if ( $value_to_check !== false )
                        {
                            // we found this field
                        	$delimiter = ( isset($import_settings['virtual_tour_field_delimiter']) && !empty($import_settings['virtual_tour_field_delimiter']) ) ? $import_settings['virtual_tour_field_delimiter'] : ',';

                            $explode_virtual_tour_urls = explode($delimiter, $value_to_check);
                            $explode_virtual_tour_urls = array_map('trim', $explode_virtual_tour_urls);
                            $explode_virtual_tour_urls = array_filter($explode_virtual_tour_urls);

                            if ( !empty($explode_virtual_tour_urls) )
                            {
                            	// we found virtual tour URLs
                            	foreach ( $explode_virtual_tour_urls as $url )
                            	{
                            		$url = apply_filters( 'propertyhive_property_import_csv_virtual_tour_url', trim($url), $this->import_id );

                            		$virtual_tours[] = array(
										'url' => $url,
									);
                            	}
                            }
                        }
                    }
                }

                update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
                    update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property[$property_id_field] );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_csv", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property[$property_id_field], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_csv" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$property_id_field = $import_settings['property_id_field'];

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$import_refs[] = $property[$property_id_field];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}
}

}