<?php
/**
 * Class for managing the import process of a generic XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_XML_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['xml_url'] ) ) 
			{
			    $import_settings['xml_url'] = wp_unslash( $_POST['xml_url'] );
			}
		}

		if ( !isset($import_settings['property_node']) || ( isset($import_settings['property_node']) && empty($import_settings['property_node']) ) )
		{
			$this->log_error( 'Please ensure you have a field specified that we can use as the property record identifer in the import setting under the \'Import Format\' tab' );
			return false;
		}

		if ( !isset($import_settings['property_id_node']) || ( isset($import_settings['property_id_node']) && empty($import_settings['property_id_node']) ) )
		{
			$this->log_error( 'Please ensure you have a field specified that we can use as the unique property identifer in the import setting under the \'Import Format\' tab and that it has a value set in the XML' );
			return false;
		}
		
		$contents = '';

		$args = array( 'timeout' => 360, 'sslverify' => false );
        $args = apply_filters( 'propertyhive_property_import_xml_request_args', $args, $import_settings['xml_url'] );
		$response = wp_remote_get( $import_settings['xml_url'], $args );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		// Remove namespaces. Done because if the namespace isn't a URL it had problems with xpath
		// Gets rid of all namespace definitions (https://stackoverflow.com/questions/1245902/remove-namespace-from-xml-using-php)
		$contents = preg_replace('/xmlns[^=]*="[^"]*"/i', '', $contents);

		// Gets rid of all namespace references (https://stackoverflow.com/questions/1245902/remove-namespace-from-xml-using-php)
		$contents = preg_replace('/[a-zA-Z]+:([a-zA-Z]+[=>])/', '$1', $contents);

		$test_xml = simplexml_load_string($contents);
		if ($test_xml === FALSE)
		{
			// Failed to parse XML
        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML' );

        	return false;
        }

		$xml = new SimpleXMLElement($contents);

		$property_xml = $xml->xpath($import_settings['property_node']);

		if ( $property_xml === false )
		{
			$this->log_error( 'Failed to find any properties in the XML with the property identifier' );

			return false;
		}

		if ( is_array($property_xml) && !empty($property_xml) )
		{
			foreach ($property_xml as $property)
			{
				$property = apply_filters( 'propertyhive_property_import_property_xml', $property, $xml, $this->import_id );

                $this->properties[] = $property;
            } // end foreach property
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

        do_action( "propertyhive_pre_import_properties_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_xml_properties_due_import", $this->properties, $this->import_id );

        $limit = $this->get_property_limit();
        $additional_message = '';
        if ( $limit !== false )
        {
    		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
    		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=propertyhive_import_properties&action=editimport&import_id=' . $this->import_id) . '#advanced">import settings</a>.';
       	}

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$property_node = $import_settings['property_node'];
		$explode_property_node = explode("/", $property_node);
		$property_node = $explode_property_node[count($explode_property_node)-1];

		$start_at_property = get_option( 'propertyhive_property_import_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
			do_action( "propertyhive_property_importing", $property, $this->import_id, $this->instance_id );
            do_action( "propertyhive_property_importing_xml", $property, $this->import_id, $this->instance_id );
            
			$property_id = '';
			$property = new SimpleXMLElement( $property->asXML() );
			$property_ids = $property->xpath('/' . $property_node . $import_settings['property_id_node']);

            if ( $property_ids === FALSE || empty($property_ids) )
            {
                //continue;
            }
            else
            {
            	$property_id = (string)$property_ids[0];
            }

			if ( empty($property_id) )
			{
				$this->log_error( 'Unique ID empty. Please ensure you have a field specified that we can use as the unique identifier in the import setting under the \'Format\' tab and that is has a value set in the XML' );
				continue;
			}

			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( $property_id == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . $property_id );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, $property_id, false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . $property_id, 0, $property_id, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property_id, $property, apply_filters( 'propertyhive_property_import_xml_mapped_field_value', '', $property, 'post_title', $this->import_id ), apply_filters( 'propertyhive_property_import_xml_mapped_field_value', '', $property, 'post_excerpt', $this->import_id ) );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, $property_id );

				update_post_meta( $post_id, $imported_ref_key, $property_id );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

			    // Media - Images
			    $media = array();
			    if ( isset($import_settings['image_fields']) && !empty($import_settings['image_fields']) )
				{
					$explode_media = explode("\n", $import_settings['image_fields']);

					foreach ( $explode_media as $media_item )
					{
						$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

						$url = trim($explode_media_item[0]);
						$url = apply_filters( 'propertyhive_property_import_xml_image_url', $url, $this->import_id );
						$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

						preg_match_all('/{[^}]*}/', $url, $matches);
		                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
		                {
		                    foreach ( $matches[0] as $match )
		                    {
		                    	// foreach field in xpath
		                        $field_name = str_replace(array("{", "}"), "", $match);

		                        $urls = $property->xpath('/' . $property_node . $field_name);

		                        $value_to_check = '';
								if ( $urls === FALSE || empty($urls) )
					            {
					                //continue;
					            }
					            else
					            {
					            	$value_to_check = (string)$urls[0];
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

			                        $descriptions = $property->xpath('/' . $property_node . $field_name);

			                        $value_to_check = '';
									if ( $descriptions === FALSE || empty($descriptions) )
						            {
						                //continue;
						            }
						            else
						            {
						            	$value_to_check = (string)$descriptions[0];
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

				$this->import_media( $post_id, $property_id, 'photo', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - Floorplans
			    $media = array();
			    if ( isset($import_settings['floorplan_fields']) && !empty($import_settings['floorplan_fields']) )
				{
					$explode_media = explode("\n", $import_settings['floorplan_fields']);

					foreach ( $explode_media as $media_item )
					{
						$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

						$url = trim($explode_media_item[0]);
						$url = apply_filters( 'propertyhive_property_import_xml_floorplan_url', $url, $this->import_id );
						$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

						preg_match_all('/{[^}]*}/', $url, $matches);
		                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
		                {
		                    foreach ( $matches[0] as $match )
		                    {
		                    	// foreach field in xpath
		                        $field_name = str_replace(array("{", "}"), "", $match);

		                        $urls = $property->xpath('/' . $property_node . $field_name);

		                        $value_to_check = '';
								if ( $urls === FALSE || empty($urls) )
					            {
					                //continue;
					            }
					            else
					            {
					            	$value_to_check = (string)$urls[0];
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

			                        $descriptions = $property->xpath('/' . $property_node . $field_name);

			                        $value_to_check = '';
									if ( $descriptions === FALSE || empty($descriptions) )
						            {
						                //continue;
						            }
						            else
						            {
						            	$value_to_check = (string)$descriptions[0];
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

				$this->import_media( $post_id, $property_id, 'floorplan', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - Brochures
			    $media = array();
			    if ( isset($import_settings['brochure_fields']) && !empty($import_settings['brochure_fields']) )
				{
					$explode_media = explode("\n", $import_settings['brochure_fields']);

					foreach ( $explode_media as $media_item )
					{
						$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

						$url = trim($explode_media_item[0]);
						$url = apply_filters( 'propertyhive_property_import_xml_brochure_url', $url, $this->import_id );
						$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

						preg_match_all('/{[^}]*}/', $url, $matches);
		                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
		                {
		                    foreach ( $matches[0] as $match )
		                    {
		                    	// foreach field in xpath
		                        $field_name = str_replace(array("{", "}"), "", $match);

		                        $urls = $property->xpath('/' . $property_node . $field_name);

		                        $value_to_check = '';
								if ( $urls === FALSE || empty($urls) )
					            {
					                //continue;
					            }
					            else
					            {
					            	$value_to_check = (string)$urls[0];
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

			                        $descriptions = $property->xpath('/' . $property_node . $field_name);

			                        $value_to_check = '';
									if ( $descriptions === FALSE || empty($descriptions) )
						            {
						                //continue;
						            }
						            else
						            {
						            	$value_to_check = (string)$descriptions[0];
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

				$this->import_media( $post_id, $property_id, 'brochure', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - EPCs
			    $media = array();
			    if ( isset($import_settings['epc_fields']) && !empty($import_settings['epc_fields']) )
				{
					$explode_media = explode("\n", $import_settings['epc_fields']);

					foreach ( $explode_media as $media_item )
					{
						$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

						$url = trim($explode_media_item[0]);
						$url = apply_filters( 'propertyhive_property_import_xml_epc_url', $url, $this->import_id );
						$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

						preg_match_all('/{[^}]*}/', $url, $matches);
		                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
		                {
		                    foreach ( $matches[0] as $match )
		                    {
		                    	// foreach field in xpath
		                        $field_name = str_replace(array("{", "}"), "", $match);

		                        $urls = $property->xpath('/' . $property_node . $field_name);

		                        $value_to_check = '';
								if ( $urls === FALSE || empty($urls) )
					            {
					                //continue;
					            }
					            else
					            {
					            	$value_to_check = (string)$urls[0];
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

			                        $descriptions = $property->xpath('/' . $property_node . $field_name);

			                        $value_to_check = '';
									if ( $descriptions === FALSE || empty($descriptions) )
						            {
						                //continue;
						            }
						            else
						            {
						            	$value_to_check = (string)$descriptions[0];
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

				$this->import_media( $post_id, $property_id, 'epc', $media, false, isset($import_settings['media_download_clause']) && $import_settings['media_download_clause'] == 'url_change' ? false : true );

				// Media - Virtual Tours
			    $virtual_tours = array();
			    if ( isset($import_settings['virtual_tour_fields']) && !empty($import_settings['virtual_tour_fields']) )
				{
					$explode_media = explode("\n", $import_settings['virtual_tour_fields']);

					foreach ( $explode_media as $media_item )
					{
						$explode_media_item = explode("|", $media_item); // 0 => URL, 1 => Description

						$url = trim($explode_media_item[0]);
						$url = apply_filters( 'propertyhive_property_import_xml_virtual_tour_url', $url, $this->import_id );
						$description = isset($explode_media_item[1]) ? trim($explode_media_item[1]) : '';

						preg_match_all('/{[^}]*}/', $url, $matches);
		                if ( $matches !== FALSE && isset($matches[0]) && is_array($matches[0]) && !empty($matches[0]) )
		                {
		                    foreach ( $matches[0] as $match )
		                    {
		                    	// foreach field in xpath
		                        $field_name = str_replace(array("{", "}"), "", $match);

		                        $urls = $property->xpath('/' . $property_node . $field_name);

		                        $value_to_check = '';
								if ( $urls === FALSE || empty($urls) )
					            {
					                //continue;
					            }
					            else
					            {
					            	$value_to_check = (string)$urls[0];
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

			                        $descriptions = $property->xpath('/' . $property_node . $field_name);

			                        $value_to_check = '';
									if ( $descriptions === FALSE || empty($descriptions) )
						            {
						                //continue;
						            }
						            else
						            {
						            	$value_to_check = (string)$descriptions[0];
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

				update_post_meta( $post_id, '_virtual_tours', count($virtual_tours) );
                foreach ($virtual_tours as $i => $virtual_tour)
                {
                    update_post_meta( $post_id, '_virtual_tour_' . $i, $virtual_tour['url'] );
                    update_post_meta( $post_id, '_virtual_tour_label_' . $i, $virtual_tour['label'] );
                }

                $this->log( 'Imported ' . count($virtual_tours) . ' virtual tours', $post_id, $property_id );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property_id, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_xml" );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$property_node = $import_settings['property_node'];
		$explode_property_node = explode("/", $property_node);
		$property_node = $explode_property_node[count($explode_property_node)-1];

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$property_id = '';
			$property = new SimpleXMLElement( $property->asXML() );
			$property_ids = $property->xpath('/' . $property_node . $import_settings['property_id_node']);

            if ( $property_ids === FALSE || empty($property_ids) )
            {
                //continue;
            }
            else
            {
            	$property_id = (string)$property_ids[0];
            }

            if ( !empty($property_id) )
            {
				$import_refs[] = $property_id;
			}
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}
}

}