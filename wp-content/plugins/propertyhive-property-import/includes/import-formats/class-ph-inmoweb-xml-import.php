<?php
/**
 * TODO: See about only import updated (don't have a valid sample feed)
 * 
 * Class for managing the import process of an Inmoweb XML file
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Inmoweb_XML_Import extends PH_Property_Import_Process {

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
			if ( isset( $_POST['url'] ) ) 
			{
			    $import_settings['url'] = sanitize_url( wp_unslash( $_POST['url'] ) );
			}
		}

		$contents = '';

		$response = wp_remote_get( $import_settings['url'], array( 'timeout' => 600, 'sslverify' => false ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		if ( wp_remote_retrieve_response_code($response) !== 200 )
        {
            $this->log_error( wp_remote_retrieve_response_code($response) . ' response received when requesting properties. Error message: ' . wp_remote_retrieve_response_message($response) );
            return false;
        }

		$this->properties = array(); // Reset properties in the event we're importing multiple files

		$xml = simplexml_load_string( $contents );

		if ( $xml !== FALSE )
		{
			if ( isset($xml->error->message) )
			{
				$this->log_error( 'Error returned in XML response: ' . (string)$xml->error->message );

        		return false;
			}
			else
			{
				foreach ( $xml->propiedad as $property )
				{
	                $this->properties[] = $property;
	            }
	        }
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse XML file. Possibly invalid XML: ' . print_r($contents, true) );

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

        $geocoding_denied = apply_filters( 'propertyhive_inmoweb_xml_import_prevent_geocoding', false, $this->import_id );

        do_action( "propertyhive_pre_import_properties_inmoweb_xml", $this->properties, $this->import_id );
        $this->properties = apply_filters( "propertyhive_inmoweb_xml_properties_due_import", $this->properties, $this->import_id );

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
            do_action( "propertyhive_property_importing_inmoweb_xml", $property, $this->import_id, $this->instance_id );
            
			$property_attributes = $property->attributes();

			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property_attributes['id'] == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property_attributes['id'] );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'propertyhive_property_import_property_' . $this->import_id, (string)$property_attributes['id'], false );

			$this->log( 'Importing property ' . $property_row .' with reference ' . (string)$property_attributes['id'], 0, (string)$property_attributes['id'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = '';
	        $post_content = '';

	        if ( isset($property->descripciones->descripcion) )
	        {
	        	foreach ( $property->descripciones->descripcion as $description )
	        	{
	        		$description_attributes = $description->attributes();

	        		if ( isset($description_attributes['idioma']) && (string)$description_attributes['idioma'] == 'es' )
	        		{
	        			$display_address = (string)$description->titulo;
	        			$post_content = (string)$description->descripcion;
	        		}
	        	}
	        }

			if ( trim($display_address) == '' )
			{
				$display_address = array();

				if ( isset($property->localizacion->poblacion) && (string)$property->localizacion->poblacion != '' )
				{
					$display_address[] = (string)$property->localizacion->poblacion;
				}
				if ( isset($property->localizacion->provincia) && (string)$property->localizacion->provincia != '' )
				{
					$display_address[] = (string)$property->localizacion->provincia;
				}

				$display_address = implode(", ", $display_address);
			}

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( (string)$property_attributes['id'], $property, $display_address, $post_content );

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', $post_id, (string)$property_attributes['id'] );

				update_post_meta( $post_id, $imported_ref_key, (string)$property_attributes['id'] );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// Address
				update_post_meta( $post_id, '_reference_number', (string)$property->referencia );
				update_post_meta( $post_id, '_address_name_number', '' );
				update_post_meta( $post_id, '_address_street', '' );
				update_post_meta( $post_id, '_address_two', '' );
				update_post_meta( $post_id, '_address_three', ( ( isset($property->localizacion->poblacion) ) ? (string)$property->localizacion->poblacion : '' ) );
				update_post_meta( $post_id, '_address_four', ( ( isset($property->localizacion->provincia) ) ? (string)$property->localizacion->provincia : '' ) );
				update_post_meta( $post_id, '_address_postcode', ( ( isset($property->localizacion->cp) ) ? (string)$property->localizacion->cp : '' ) );

				$country = 'ES';
				$currency = 'EUR';
				update_post_meta( $post_id, '_address_country', $country );

            	// Let's just look at address fields to see if we find a match
            	$address_fields_to_check = apply_filters( 'propertyhive_inmoweb_xml_address_fields_to_check', array('poblacion', 'provincia'), $this->import_id );
				$location_term_ids = array();

				foreach ( $address_fields_to_check as $address_field )
				{
					if ( isset($property->localizacion->{$address_field}) && trim((string)$property->localizacion->{$address_field}) != '' ) 
					{
						$term = term_exists( trim((string)$property->localizacion->{$address_field}), 'location');
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
				if ( 
					isset($property->localizacion->latitud) && isset($property->localizacion->longitud) && 
					(string)$property->localizacion->latitud != '' && (string)$property->location->longitude != '' && 
					(string)$property->localizacion->latitud != '0' && (string)$property->location->longitude != '0' 
				)
				{
					update_post_meta( $post_id, '_latitude', ( ( isset($property->localizacion->latitud) ) ? (string)$property->localizacion->latitud : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property->localizacion->longitud) ) ? (string)$property->location->longitude : '' ) );
				}
				else
				{
					// No lat/lng passed. Let's go and get it if none entered
					$lat = get_post_meta( $post_id, '_latitude', TRUE);
					$lng = get_post_meta( $post_id, '_longitude', TRUE);

					if ( !$geocoding_denied && ( $lat == '' || $lng == '' || $lat == '0' || $lng == '0' ) )
					{
						// No lat lng. Let's get it
						$address_to_geocode = array();
						if ( isset($property->localizacion->poblacion) && trim((string)$property->localizacion->poblacion) != '' ) { $address_to_geocode[] = (string)$property->localizacion->poblacion; }
						if ( isset($property->province) && trim((string)$property->localizacion->provincia) != '' ) { $address_to_geocode[] = (string)$property->localizacion->provincia; }
						if ( isset($property->cp) && trim((string)$property->localizacion->cp) != '' ) { $address_to_geocode[] = (string)$property->localizacion->cp; }

						$geocoding_return = $this->do_geocoding_lookup( $post_id, (string)$property_attributes['id'], $address_to_geocode, $address_to_geocode, $country );
						if ( $geocoding_return === 'denied' )
						{
							$geocoding_denied = true;
						}
					}
				}

				// Owner
				add_post_meta( $post_id, '_owner_contact_id', '', true );

				// Record Details
				add_post_meta( $post_id, '_negotiator_id', get_current_user_id(), true );
					
				$office_id = $this->primary_office_id;
				update_post_meta( $post_id, '_office_id', $office_id );

				// Residential Details
				$department = 'residential-sales';
				if ( isset($property->price_freq) && ( (string)$property->price_freq == 'week' || (string)$property->price_freq == 'month' ) )
				{
					$department = 'residential-lettings';
				}

				update_post_meta( $post_id, '_department', $department );

				// Is the property portal add on activated
				if (class_exists('PH_Property_Portal'))
	    		{
	    			$explode_agent_branch = array();
	    			
	    			if ( isset($this->branch_mappings[$this->import_id]) )
	    			{
	    				$explode_agent_branch = explode("|", $this->branch_mappings[$this->import_id]);
	    			}

	    			if ( !empty($explode_agent_branch) )
					{
						update_post_meta( $post_id, '_agent_id', $explode_agent_branch[0] );
						update_post_meta( $post_id, '_branch_id', $explode_agent_branch[1] );
					}
					else
					{
						update_post_meta( $post_id, '_agent_id', '' );
						update_post_meta( $post_id, '_branch_id', '' );
					}
	    		}

				update_post_meta( $post_id, '_bedrooms', ( ( isset($property->beds) ) ? (string)$property->beds : '' ) );
				update_post_meta( $post_id, '_bathrooms', ( ( isset($property->baths) ) ? (string)$property->baths : '' ) );
				update_post_meta( $post_id, '_reception_rooms', '' );

				$prefix = '';
				$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();
				
				if ( isset($property->familia) )
				{
					if ( !empty($mapping) && isset($mapping[(string)$property->familia]) )
					{
						wp_set_object_terms( $post_id, (int)$mapping[(string)$property->familia], $prefix . 'property_type' );
					}
					else
					{
						wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );

						$this->log( 'Property received with a type (' . (string)$property->familia . ') that is not mapped', $post_id, (string)$property_attributes['id'] );

						$import_settings = $this->add_missing_mapping( $mapping, $prefix . 'property_type', (string)$property->familia, $post_id );
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
					$price = '';
					if ( isset($property->precio) && is_numeric((string)$property->precio) )
					{
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->precio));
					}

					update_post_meta( $post_id, '_price', $price );
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_currency', $currency );
				}
				elseif ( $department == 'residential-lettings' )
				{
					// Clean price
					$price = '';
					if ( isset($property->precio) && is_numeric((string)$property->precio) )
					{
						$price = round(preg_replace("/[^0-9.]/", '', (string)$property->precio));
					}

					update_post_meta( $post_id, '_rent', $price );

					$rent_frequency = 'pcm';
					update_post_meta( $post_id, '_rent_frequency', $rent_frequency );
					
					update_post_meta( $post_id, '_poa', '' );

					update_post_meta( $post_id, '_currency', $currency );

					update_post_meta( $post_id, '_deposit', '' );
            		update_post_meta( $post_id, '_available_date', '' );
				}

				// Store price in common currency (GBP) used for ordering
	            $ph_countries = new PH_Countries();
	            $ph_countries->update_property_price_actual( $post_id );

				// Marketing
				$on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
                if ( $on_market_by_default === true )
                {
                    update_post_meta( $post_id, '_on_market', 'yes' );
                }
				add_post_meta( $post_id, '_featured', '', true );

				// Availability
				$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
					$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
					array();

				if ( !empty($mapping) && isset($property->status_id) && isset($mapping[(string)$property->status_id]) )
				{
	                wp_set_object_terms( $post_id, (int)$mapping[(string)$property->status_id], 'availability' );
	            }
	            else
	            {
	            	wp_delete_object_term_relationships( $post_id, 'availability' );
	            }

	            // Features
				$feature_term_ids = array();
	            $features = array();
	            foreach ( $property->caracteristicas->caracteristica as $caracteristica ) 
	            {
	            	$caracteristica_attributes = $caracteristica->attributes();

				    if ( isset($caracteristica_attributes['id']) && (string)$caracteristica != '' && strtolower((string)$caracteristica) !== "no" ) 
				    { 
				        $features[] = ucfirst(str_replace("_", " ", (string)$caracteristica_attributes['id'])) . ": " . ucfirst((string)$caracteristica);
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
				update_post_meta( $post_id, '_rooms', '0' );
				if ( !empty($post_content) )
				{
					update_post_meta( $post_id, '_room_name_0', '' );
		            update_post_meta( $post_id, '_room_dimensions_0', '' );
		            update_post_meta( $post_id, '_room_description_0', $post_content );
		        }

		        // Media - Images
			    $media = array();
			    if (isset($property->imagenes) && !empty($property->imagenes))
                {
                    foreach ($property->imagenes as $imagenes)
                    {
                    	if (isset($imagenes->imagen))
		                {
	                        foreach ($imagenes->imagen as $image)
	                        {
	                        	$image_attributes = $image->attributes();

								$media[] = array(
									'url' => trim((string)$image_attributes['url']),
									'modified' => ( ( isset($image_attributes['modified']) && (string)$image_attributes['modified'] != '' ) ? (string)$image_attributes['modified'] : '' ),
								);
							}
						}
					}
				}

				$this->import_media( $post_id, (string)$property_attributes['id'], 'photo', $media, true );

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_inmoweb_xml", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property_attributes['id'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_inmoweb_xml", $this->import_id );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		$import_refs = array();
		foreach ($this->properties as $property)
		{
			$property_attributes = $property->attributes();

			$import_refs[] = (string)$property_attributes['id'];
		}

		$this->do_remove_old_properties( $import_refs );

		unset($import_refs);
	}

	public function get_default_mapping_values()
	{
		return array(
            'sales_availability' => array(
                'activo' => 'activo',
                'reservado' => 'reservado',
            ),
            'lettings_availability' => array(
                'activo' => 'activo',
                'reservado' => 'reservado',
            ),
            'property_type' => array(
                'Apartamento' => 'Apartamento',
                'Local comercial' => 'Local comercial',
                'Casa de campo' => 'Casa de campo',
                'Bungalow' => 'Bungalow',
                'Casa de pueblo' => 'Casa de pueblo',
                'Casa adosada' => 'Casa adosada',
                'Dúplex' => 'Dúplex',
                'Piso' => 'Piso',
                'Hotel' => 'Hotel',
                'Triplex' => 'Triplex',
                'Solar Urbano' => 'Solar Urbano',
                'Edificio' => 'Edificio',
                'Casa / Chalet' => 'Casa / Chalet',
                'Parcela' => 'Parcela',
                'Villa de Lujo' => 'Villa de Lujo',
                'Finca rústica' => 'Finca rústica'
            )
        );
	}
}

}