<?php
/**
 * Class for managing the import process using the Kato API
 *
 * @package WordPress
 */
if ( class_exists( 'PH_Property_Import_Process' ) ) {

class PH_Kato_JSON_Import extends PH_Property_Import_Process {

	/**
	 * @var string
	 */
	private $token;

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

	private function get_api_url_for_environment($environment)
	{
		if ( $environment == 'production' )
		{
			return 'https://client-api.kato.app';
		}

		return 'https://client-api.integrations.kato-sandbox.app';
	}

	private function get_token( $test = false )
	{
		if ( $test === false )
		{
			$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );
		}
		else
		{
			$import_settings = map_deep( wp_unslash($_POST), 'sanitize_text_field' );
		}

		$token = '';

		$url = $this->get_api_url_for_environment($import_settings['environment']);

		$response = wp_remote_post(
			$url . '/oauth/token',
			array(
				'method' => 'POST',
				'timeout' => 45,
				'headers' => array( 
					'Content-Type' => 'application/x-www-form-urlencoded', 
					'Accept' => 'application/json' 
				),
				'body' => array( 
					'grant_type' => 'client_credentials', 
					'client_id' => ( ( isset($import_settings['client_id']) ) ? $import_settings['client_id'] : '' ), 
					'client_secret' => ( ( isset($import_settings['client_secret']) ) ? $import_settings['client_secret'] : '' ),
					'scope' => '*'
				),
		    )
		);

		if ( is_wp_error( $response ) ) 
		{
			$this->log_error( 'Failed to request token: ' . $response->get_error_message() );
			return false;
		}
		else
		{
			$body = json_decode($response['body'], TRUE);

			if ( $body === false )
			{
				$this->log_error( 'Failed to decode token request body: ' . $response['body'] );
				return false;
			}
			else
			{
				if ( isset($body['access_token']) )
				{
					$token = $body['access_token'];

					$this->log("Got token " . $token );

					return $token;
				}
				else
				{
					$this->log_error( 'Failed to get access_token part of response body: ' . $response['body'] );
					return false;
				}
			}
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

		$this->token = $this->get_token( $test );

		if ( $this->token === false )
		{
			return false;
		}

		$url = $this->get_api_url_for_environment($import_settings['environment']);

		$more_properties = true;
		$page = 1;

		$args = array(
			"published_to_website" => "true"
		);

		$args = apply_filters( "propertyhive_kato_json_request_args", $args, $this->import_id );

		$limit = $this->get_property_limit();

		$this->log("Parsing properties");

		while ( $more_properties )
		{
			$new_args = $args;
			$new_args['page'] = $page;

			$this->log("Parsing properties on page " . $page);

			$response = wp_remote_post(
				$url . '/v2/availability?' . http_build_query($new_args),
				array(
					'method' => 'GET',
					'timeout' => 45,
					'headers' => array( 
						'Authorization' => 'Bearer ' . $this->token,
						'Accept' => 'application/json' 
					),
			    )
			);

			if ( is_wp_error( $response ) ) 
			{
				$this->log_error( 'Failed to request properties: ' . $response->get_error_message() );
				return false;
			}
			else
			{
				$body = json_decode($response['body'], TRUE);

				if ( $body === false )
				{
					$this->log_error( 'Failed to decode properties request body: ' . $response['body'] );
					return false;
				}
				else
				{
					if ( !isset($body['data']) )
					{
						$this->log_error( 'No data element present in body: ' . $response['body'] );
						return false;
					}

					if ( empty($body['data']) )
					{
						$this->log_error( 'Data element is empty so not continuing: ' . $response['body'] );
						return false;
					}

					if ( !isset($body['meta']['pagination']['total_pages']) || empty($body['meta']['pagination']['total_pages']) )
					{
						$this->log_error( 'Pagination data missing: ' . $response['body'] );
						return false;
					}

					foreach ( $body['data'] as $property ) 
					{
						if ( $test === false && $limit !== FALSE && count($this->properties) >= $limit )
		                {
		                    return true;
		                }

						$this->properties[] = $property;
					}

					if ( $page == $body['meta']['pagination']['total_pages'] )
					{
						$more_properties = false;
					}
				}
			}

			++$page;
		}

		if ( $test === false && empty($this->properties) )
        {
        	$this->log_error('No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.');

        	return false;
        }

		return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );

		$import_settings = propertyhive_property_import_get_import_settings_from_id( $this->import_id );

		$this->import_start();

        do_action( "propertyhive_pre_import_properties_kato_json", $this->properties, $this->import_id, $import_settings, $this->token );
        $this->properties = apply_filters( "propertyhive_kato_json_properties_due_import", $this->properties, $this->import_id, $import_settings, $this->token );

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
            do_action( "propertyhive_property_importing_kato_json", $property, $this->import_id, $this->instance_id );
            
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

			$this->log( 'Importing property ' . $property_row . ' with reference ' . $property['id'], 0, $property['id'], '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$display_address = array();
            if ($property['address']['name'] != '')
            {
                $display_address[] = $property['address']['name'];
            }
            if ($property['address']['line1'] != '')
            {
                $display_address[] = $property['address']['line1'];
            }
            if ($property['address']['line2'] != '')
            {
                $display_address[] = $property['address']['line2'];
            }
            if ($property['address']['town'] != '')
            {
                $display_address[] = $property['address']['town'];
            }
            $display_address = implode(", ", $display_address);

            $summary_description = '';
            if ( isset($property['marketing']['text']) && !empty($property['marketing']['text']) )
            {
            	foreach ($property['marketing']['text'] as $text)
            	{
            		if ( $text['key'] == 'summary' )
            		{
	            		$summary_description = $text['content'];
	            	}
            	}
            }

			list( $inserted_updated, $post_id ) = $this->insert_update_property_post( $property['id'], $property, $display_address, $summary_description, '', ( isset($property['created_at']) ? date( 'Y-m-d H:i:s', strtotime( $property['created_at'] ) ) : '' ) );

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

				$previous_update_date = get_post_meta( $post_id, '_kato_update_date_' . $this->import_id, TRUE);

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
					update_post_meta( $post_id, '_reference_number',  ( ( isset($property['external_ref']) ) ? $property['external_ref'] : '' ) );
					update_post_meta( $post_id, '_address_name_number', trim( ( ( isset($property['address']['name']) ) ? $property['address']['name'] : '' ) . ' ' . ( ( isset($property['address']['SecondaryName']) ) ? $property['address']['SecondaryName'] : '' ) ) );
					update_post_meta( $post_id, '_address_street', ( ( isset($property['address']['line1']) ) ? $property['address']['line1'] : '' ) );
					update_post_meta( $post_id, '_address_two', ( ( isset($property['address']['line2']) ) ? $property['address']['line2'] : '' ) );
					update_post_meta( $post_id, '_address_three', ( ( isset($property['address']['town']) ) ? $property['address']['town'] : '' ) );
					update_post_meta( $post_id, '_address_four', ( ( isset($property['address']['county']) ) ? $property['address']['county'] : '' ) );
					update_post_meta( $post_id, '_address_postcode', ( ( isset($property['address']['postcode']) ) ? $property['address']['postcode'] : '' ) );

					$country = get_option( 'propertyhive_default_country', 'GB' );
					if ( isset($property['address']['country']) && !empty($property['address']['country']) ) { $country = $property['address']['country']; }
					update_post_meta( $post_id, '_address_country', $country );

					// Check main address fields and see if this location exists as a taxonomy to try and assign properties to location
					$address_fields_to_check = apply_filters( 'propertyhive_kato_json_address_fields_to_check', array('line1', 'line2', 'town', 'county') );
					$location_term_ids = array();

					foreach ( $address_fields_to_check as $address_field )
					{
						if ( isset($property['address'][$address_field]) && trim($property['address'][$address_field]) != '' ) 
						{
							$term = term_exists( trim($property['address'][$address_field]), 'location' );
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
					update_post_meta( $post_id, '_latitude', ( ( isset($property['position']['lat']) ) ? $property['position']['lat'] : '' ) );
					update_post_meta( $post_id, '_longitude', ( ( isset($property['position']['lng']) ) ? $property['position']['lng'] : '' ) );

					// Owner
					add_post_meta( $post_id, '_owner_contact_id', '', true );

					// Record Details
					$negotiator_id = false;
					if ( isset($property['assignees']) && is_array($property['assignees']) && !empty($property['assignees']) )
					{
						foreach ( $property['assignees'] as $account_manager )
						{
							if ( $negotiator_id !== false )
							{
								continue;
							}

							$negotiator_row = $wpdb->get_row( $wpdb->prepare(
						        "SELECT `ID` FROM $wpdb->users WHERE `display_name` = %s", $account_manager['forename'] . ' ' . $account_manager['surname']
						    ) );
						    if ( null !== $negotiator_row )
						    {
						    	$negotiator_id = $negotiator_row->ID;
						    }
						}
					}
					if ( $negotiator_id === false )
					{
						$negotiator_id = get_current_user_id();
					}
					update_post_meta( $post_id, '_negotiator_id', (int)$negotiator_id );

					$office_id = $this->primary_office_id;

					$branch_name = '';
					if ( isset($property['assignees']) && is_array($property['assignees']) && !empty($property['assignees']) )
					{
						foreach ( $property['assignees'] as $account_manager )
						{
							if ( isset($account_manager['office']) && !empty($account_manager['office']) )
							{
								$branch_name = $account_manager['office'];
								break;
							}
						}
					}

					if ( isset($import_settings['offices']) && is_array($import_settings['offices']) && !empty($import_settings['offices']) )
					{
						foreach ( $import_settings['offices'] as $ph_office_id => $branch_code )
						{
							if ( $branch_code == $branch_name )
							{
								$office_id = $ph_office_id;
								break;
							}
						}
					}
					update_post_meta( $post_id, '_office_id', $office_id );

					$department = 'commercial';

					update_post_meta( $post_id, '_department', $department );

					if ( $department == 'residential-sales' || $department == 'residential-lettings' )
					{
						update_post_meta( $post_id, '_bedrooms', ( ( isset($property['size']['TotalSize']) ) ? $property['size']['TotalSize'] : '' ) );
						update_post_meta( $post_id, '_bathrooms', ( ( isset($property['size']['Bathrooms']) ) ? $property['size']['Bathrooms'] : '' ) );
						update_post_meta( $post_id, '_reception_rooms', ( ( isset($property['size']['ReceptionRooms']) ) ? $property['size']['ReceptionRooms'] : '' ) );
					}

	        		if ( $department == 'commercial' )
	        		{
						update_post_meta( $post_id, '_for_sale', '' );
		        		update_post_meta( $post_id, '_to_rent', '' );

		        		if ( $property['for_sale'] == true )
		                {
		                    update_post_meta( $post_id, '_for_sale', 'yes' );

		                    update_post_meta( $post_id, '_commercial_price_currency', $property['price']['currency'] );

		                    $price = preg_replace("/[^0-9.]/", '', $property['price']['value']);
		                    update_post_meta( $post_id, '_price_from', $price );
		                    update_post_meta( $post_id, '_price_to', $price );

		                    update_post_meta( $post_id, '_price_units', '' );

		                    update_post_meta( $post_id, '_price_poa', ( isset($property['price']['price_on_application']) && $property['price']['price_on_application'] === true ) ? 'yes' : '' );

		                    // Price Qualifier
				            $mapping = isset($import_settings['mappings']['price_qualifier']) ? $import_settings['mappings']['price_qualifier'] : array();
							
				            if ( !empty($mapping) && isset($property['price']['qualifier']) && isset($mapping[$property['price']['qualifier']]) )
							{
					            wp_set_object_terms( $post_id, (int)$mapping[$property['price']['qualifier']], 'price_qualifier' );
				            }
				            else
				            {
				            	wp_delete_object_term_relationships( $post_id, 'price_qualifier' );
				            }
		                }

		                if ( $property['to_let'] == true )
		                {
		                    update_post_meta( $post_id, '_to_rent', 'yes' );

		                    update_post_meta( $post_id, '_commercial_rent_currency', $property['rent']['currency'] );

		                    $rent = preg_replace("/[^0-9.]/", '', $property['rent']['from']);
		                    update_post_meta( $post_id, '_rent_from', $rent );

		                    $rent = preg_replace("/[^0-9.]/", '', $property['rent']['to']);
		                    if ( $rent == '' || $rent == '0' )
				            {
				                $rent = preg_replace("/[^0-9.]/", '', $property['rent']['from']);
				            }
		                    update_post_meta( $post_id, '_rent_to', $rent );

		                    $rent_units = 'pa';
			           		if ( isset($property['rent']['metric']) )
				            {
				            	switch ( strtolower($property['rent']['metric']) )
				            	{
				            		//case "per month":
				            		//case "pcm": { $rent_units = 'pcm'; break; }
				            		case "sqft": { $rent_units = 'psqft'; break; }
				            		//case "per sq m": { $rent_units = 'psqm'; break; }
				            	}
				            }
		                    update_post_meta( $post_id, '_rent_units', $rent_units);

		                    update_post_meta( $post_id, '_rent_poa', ( isset($property['rent']['rent_on_application']) && $property['rent']['rent_on_application'] === true ) ? 'yes' : '' );
		                }

			            $units = 'sqft';
			            if ( isset($property['size']['metric']) )
			            {
			            	switch ( $property['size']['metric'] )
			            	{
			            		case "sqm": { $units = 'sqm'; break; }
			            		//case "Acres": { $units = 'acre'; break; }
			            	}
			            }

			            $size = preg_replace("/[^0-9.]/", '', $property['size']['from']);
			            if ( $size == '' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', $property['size']['to']);
			            }
			            if ( $size != '' )
			            {
				            $size = str_replace(".00", "", number_format($size, 2, '.', ''));
				        }
			            update_post_meta( $post_id, '_floor_area_from', $size );

			            update_post_meta( $post_id, '_floor_area_from_sqft', convert_size_to_sqft( $size, $units ) );

			            $size = preg_replace("/[^0-9.]/", '', $property['size']['to']);
			            if ( $size == '' || $size == '0' )
			            {
			                $size = preg_replace("/[^0-9.]/", '', $property['size']['from']);
			            }
			            if ( $size != '' )
			            {
				            $size = str_replace(".00", "", number_format($size, 2, '.', ''));
				        }
			            update_post_meta( $post_id, '_floor_area_to', $size );

			            update_post_meta( $post_id, '_floor_area_to_sqft', convert_size_to_sqft( $size, $units ) );

			            update_post_meta( $post_id, '_floor_area_units', $units );

			            $size = '';

			            update_post_meta( $post_id, '_site_area_from', $size );

			            update_post_meta( $post_id, '_site_area_from_sqft', convert_size_to_sqft( $size, 'sqft' ) );

			            update_post_meta( $post_id, '_site_area_to', $size );

			            update_post_meta( $post_id, '_site_area_to_sqft', convert_size_to_sqft( $size, 'sqft' ) );

			            update_post_meta( $post_id, '_site_area_units', $units );
			        }

			        // Store price in common currency (GBP) used for ordering
		            $ph_countries = new PH_Countries();
		            $ph_countries->update_property_price_actual( $post_id );

					// Property Type
					$prefix = '';
					if ( $department == 'commercial' )
					{
						$prefix = 'commercial_';
					}
					$mapping = isset($import_settings['mappings'][$prefix . 'property_type']) ? $import_settings['mappings'][$prefix . 'property_type'] : array();

					if ( isset($property['property_types']) && is_array($property['property_types']) && !empty($property['property_types']) )
					{
						$term_ids = array();

						foreach ( $property['property_types'] as $property_type )
						{
							if ( !empty($mapping) && isset($mapping[$property_type['key']]) )
							{
								$term_ids[] = (int)$mapping[$property_type['key']];
				            }
						}

						if ( !empty($term_ids) )
						{
							wp_set_object_terms( $post_id, $term_ids, $prefix . 'property_type' );
						}					
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
			            }
			        }
			        else
			        {
			        	wp_delete_object_term_relationships( $post_id, $prefix . 'property_type' );
			        }

		            $on_market_by_default = apply_filters( 'propertyhive_property_import_on_market_by_default', true );
	                if ( $on_market_by_default === true )
	                {
	                    update_post_meta( $post_id, '_on_market', 'yes' );
	                }
	                $featured_by_default = apply_filters( 'propertyhive_property_import_featured_by_default', true );
					if ( $featured_by_default === true )
					{
						update_post_meta( $post_id, '_featured', ( isset($property['published_to_website']['featured']) && $property['published_to_website']['featured'] == true ) ? 'yes' : '' );
					}
					// Availability
					$mapping = isset($import_settings['mappings'][str_replace('residential-', '', $department) . '_availability']) ? 
						$import_settings['mappings'][str_replace('residential-', '', $department) . '_availability'] : 
						array();

	        		if ( isset($property['status']) && is_array($property['status']) )
	        		{
						if ( !empty($mapping) && isset($mapping[$property['status']]) )
						{
			                wp_set_object_terms( $post_id, (int)$mapping[$property['status']], 'availability' );
			            }
			            else
			            {
			            	wp_delete_object_term_relationships( $post_id, 'availability' );
						}
			        }
			        else
			        {
			        	wp_delete_object_term_relationships( $post_id, 'availability' );
			        }

					$features = array();
					if ( isset($property['marketing']['key_points']) && is_array($property['marketing']['key_points']) && !empty($property['marketing']['key_points']) )
					{
						foreach ( $property['marketing']['key_points'] as $bullet )
						{
							if ( $bullet != '' )
							{
								$features[] = $bullet;
							}
						}
					}

					update_post_meta( $post_id, '_features', count( $features ) );
		
	        		$i = 0;
			        foreach ( $features as $feature )
			        {
			            update_post_meta( $post_id, '_feature_' . $i, $feature );
			            ++$i;
			        }

			        $description_i = 0;
		            if ( isset($property['marketing']['text']) && !empty($property['marketing']['text']) )
		            {
		            	foreach ($property['marketing']['text'] as $text)
		            	{
		            		if ( $text['key'] != 'summary' && isset($text['content']) && !empty($text['content']) )
		            		{
			            		update_post_meta( $post_id, '_description_name_' . $description_i, $text['heading'] );
				            	update_post_meta( $post_id, '_description_' . $description_i, $text['content'] );

				            	++$description_i;
			            	}
		            	}
		            }

		            if ( apply_filters( 'propertyhive_import_kato_units_description_table', true) === true && isset($property['published_to_website']['floor_unit_table']['columns']) && isset($property['floor_units']) && !empty($property['floor_units']) )
					{
						$units_table_html = '
							<table>
								<thead>
									<tr>';
						foreach ( $property['published_to_website']['floor_unit_table']['columns'] as $column )
						{
							$units_table_html .= '<td>' . esc_html(ucwords($column)) . '</td>';
						}
						$units_table_html .= '</tr>
								</thead>
								<tbody>';

						$total_size_sqft = 0;
						$total_size_m = 0;

						$floor_units = $property['floor_units'];

						$num_units = count($floor_units);

						foreach ($floor_units as $floor_unit)
						{
							$unit_name = $floor_unit[''];

							$size_sqft_float = (float)preg_replace("/[^0-9.]/", '', (string)$floor_unit->size_sqft);
							$size_sqm_float = $size_sqft_float * 0.09290304;

							$units_table_html .= '<tr>';
							foreach ( $property['published_to_website']['floor_unit_table']['columns'] as $column )
							{
								$units_table_html .= '<td>' . esc_html($floor_unit['marketing_columns'][$column]) . '</td>';
								/*	<td>' . str_replace(".00", "", (string)number_format($size_sqft_float, 2)) . '</td>
									<td>' . str_replace(".00", "", (string)number_format($size_sqm_float, 2)) . '</td>
									<td>' . (string)$floor_unit->status . '</td>*/
							}
							$units_table_html .= '</tr>';

							$total_size_sqft += $size_sqft_float;
							$total_size_m += $size_sqm_float;
						}
						/*if ( $num_units > 1 && isset($property['published_to_website']['floor_unit_table']['totals']) && $property['published_to_website']['floor_unit_table']['totals'] === true )
						{
							// Adding zero to the totals prevents ".00" from showing after the figures
							$units_table_html .= '
								<tr>
									<td><strong>Total</strong></td>
									<td><strong>' . str_replace(".00", "", number_format($total_size_sqft, 2)) . '</strong></td>
									<td><strong>' . str_replace(".00", "", number_format($total_size_m, 2)) . '</strong></td>
									<td>&nbsp;</td>
								</tr>';
						}*/
						$units_table_html .= '</tbody></table>';

						update_post_meta( $post_id, '_description_name_' . $description_i, __( $property['published_to_website']['floor_unit_table']['content'], 'propertyhive' ) );
						update_post_meta( $post_id, '_description_' . $description_i, trim(preg_replace('/>\s+</', '><', $units_table_html)) );

						++$description_i;
					}

		            update_post_meta( $post_id, '_descriptions', $description_i );

		            // Media - Images
				    $media = array();
				    if ( isset($property['images']) && is_array($property['images']) && !empty($property['images']) )
	                {
						foreach ( $property['images'] as $image )
						{
							$url = $image['url'];
						    
						    $explode_url = explode("?", $url);
							$filename = basename( $explode_url[0] );

							$media[] = array(
								'url' => $url,
								'filename' => $filename,
								'modified' => $image['updated_at'],
							);
						}
					}

					$this->import_media( $post_id, $property['id'], 'photo', $media, true );

					// Media - Floorplans
				    $media = array();
				    if ( isset($property['files']) && is_array($property['files']) && !empty($property['files']) )
	                {
						foreach ( $property['files'] as $file )
						{
							if ( isset($file['type']) && $file['type'] == 'floor_plan' )
							{
								$modified = $file['updated_at'];

								$media[] = array(
									'url' => $file['url'],
									'modified' => $file['updated_at'],
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'floorplan', $media, true );

					// Media - Brochures
				    $media = array();
				    if ( isset($property['files']) && is_array($property['files']) && !empty($property['files']) )
	                {
						foreach ( $property['files'] as $file )
						{
							if ( isset($file['type']) && $file['type'] == 'brochure' )
							{
								$modified = $file['updated_at'];

								$media[] = array(
									'url' => $file['url'],
									'modified' => $file['updated_at'],
								);
							}
						}
					}

					$this->import_media( $post_id, $property['id'], 'brochure', $media, true );
				}
				else
				{
					$this->log( 'Skipping property as not been updated', $post_id, $property['id'] );
				}

				if ( isset($property['updated_at']) ) { update_post_meta( $post_id, '_kato_update_date_' . $this->import_id, date("Y-m-d H:i:s", strtotime($property['updated_at'])) ); }

				do_action( "propertyhive_property_imported", $post_id, $property, $this->import_id );
				do_action( "propertyhive_property_imported_kato_json", $post_id, $property, $this->import_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, $property['id'], $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "propertyhive_post_import_properties_kato_json" );

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
            'commercial_availability' => array(
                'available' => 'Available',
                'coming_soon' => 'Coming Soon',
                'under_offer' => 'Under Offer',
            ),
            'commercial_property_type' => array(
                'office' => 'Office',
                'retail' => 'Retail',
                'warehouse' => 'Warehouse',
            ),
            'price_qualifier' => array(

            )
        );
	}
}

}