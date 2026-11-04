<?php
/**
 * Class for managing the import process of a miogest XML file
 *
 * @package WordPress
 */
if ( class_exists( 'Houzez_Property_Feed_Process' ) ) {

class Houzez_Property_Feed_Format_Miogest extends Houzez_Property_Feed_Process {

	/**
	 * @var SimpleXMLObject
	 */
	private $agent_xml;

	public function __construct( $instance_id = '', $import_id = '' )
	{
		$this->instance_id = $instance_id;
		$this->import_id = $import_id;

		if ( $this->instance_id != '' && isset($_GET['custom_property_import_cron']) )
	    {
	    	$current_user = wp_get_current_user();

	    	$this->log("Executed manually by " . ( ( isset($current_user->display_name) ) ? $current_user->display_name : '' ), '', 0, '', false );
	    }
	}

	public function parse()
	{
		$this->properties = array(); // Reset properties in the event we're importing multiple files

		$this->log("Parsing properties", '', 0, '', false);

		$import_settings = houzez_property_feed_get_import_settings_from_id( $this->import_id );

		$contents = '';

		$response = wp_remote_get( $import_settings['xml_url'], array( 'timeout' => 360, 'sslverify' => false ) );
		if ( !is_wp_error($response) && is_array( $response ) ) 
		{
			$contents = $response['body'];
		}
		else
		{
			$this->log_error( "Failed to obtain XML. Dump of response as follows: " . print_r($response, TRUE) );

        	return false;
		}

		$xml = simplexml_load_string($contents);

		if ( $xml !== FALSE )
		{
			foreach ( $xml->Annuncio as $property )
			{
				if ( !in_array(trim((string)$property->Tipologia), array('V', 'A'), true) )
	            {
	                $this->log_error( 'Skipping unsupported Tipologia: ' . (string)$property->Tipologia, (string)$property->AnnuncioId );
	                continue;
	            }

                $this->properties[] = $property;
            }
        }
        else
        {
        	// Failed to parse XML
        	$this->log_error( 'Failed to parse XML file: ' . $contents );

        	return false;
        }

		if ( empty($this->properties) )
		{
			$this->log_error( 'No properties found. We\'re not going to continue as this could likely be wrong and all properties will get removed if we continue.' );

			return false;
		}

		return true;
	}

	public function import()
	{
		global $wpdb;

		$imported_ref_key = ( ( $this->import_id != '' ) ? '_imported_ref_' . $this->import_id : '_imported_ref' );
		$imported_ref_key = apply_filters( 'houzez_property_feed_property_imported_ref_key', $imported_ref_key, $this->import_id );

		$import_settings = houzez_property_feed_get_import_settings_from_id( $this->import_id );

		$pro_active = apply_filters( 'houzez_property_feed_pro_active', false );

		$this->import_start();

		do_action( "houzez_property_feed_pre_import_properties", $this->properties, $this->import_id );
        do_action( "houzez_property_feed_pre_import_properties_miogest", $this->properties, $this->import_id );

        $this->properties = apply_filters( "houzez_property_feed_properties_due_import", $this->properties, $this->import_id );
        $this->properties = apply_filters( "houzez_property_feed_properties_due_import_miogest", $this->properties, $this->import_id );

        $limit = apply_filters( "houzez_property_feed_property_limit", 25 );
        $additional_message = '';
        if ( $limit !== false )
        {
        	$this->properties = array_slice( $this->properties, 0, $limit );
        	$additional_message = '. <a href="https://houzezpropertyfeed.com/pricing" target="_blank">Upgrade to PRO</a> to import unlimited properties';
        }
        else
        {
        	// using pro, but check for limit setting
        	if ( isset($import_settings['limit']) && !empty((int)$import_settings['limit']) && is_numeric($import_settings['limit']) )
        	{
        		$this->properties = array_slice( $this->properties, 0, (int)$import_settings['limit'] );
        		$additional_message = '. Limited to ' . number_format((int)$import_settings['limit']) . ' properties due to advanced setting in <a href="' . admin_url('admin.php?page=houzez-property-feed-import&action=editimport&import_id=' . $this->import_id) . '">import settings</a>.';
        	}
        }

		$this->log( 'Beginning to loop through ' . count($this->properties) . ' properties' . $additional_message );

		$start_at_property = get_option( 'houzez_property_feed_property_' . $this->import_id );

		$property_row = 1;
		foreach ( $this->properties as $property )
		{
            if ( trim((string)$property->AnnuncioId) === '' )
            {
                $this->log_error( 'Skipping property without AnnuncioId' );
                ++$property_row;
                continue;
            }
			if ( !empty($start_at_property) )
			{
				// we need to start on a certain property
				if ( (string)$property->AnnuncioId == $start_at_property )
				{
					// we found the property. We'll continue for this property onwards
					$this->log( 'Previous import failed to complete. Continuing from property ' . $property_row . ' with ID ' . (string)$property->AnnuncioId );
					$start_at_property = false;
				}
				else
				{
					++$property_row;
					continue;
				}
			}

			update_option( 'houzez_property_feed_property_' . $this->import_id, (string)$property->AnnuncioId, false );
			
			$this->log( 'Importing property ' . $property_row . ' with reference ' . (string)$property->AnnuncioId, (string)$property->AnnuncioId, 0, '', false );

			$this->ping(array('status' => 'importing', 'property' => $property_row, 'total' => count($this->properties)));

			$inserted_updated = false;

			$args = array(
	            'post_type' => 'property',
	            'posts_per_page' => 1,
	            'post_status' => 'any',
	            'meta_query' => array(
	            	array(
		            	'key' => $imported_ref_key,
		            	'value' => (string)$property->AnnuncioId
		            )
	            )
	        );
	        $property_query = new WP_Query($args);

	        $display_address = array();
			if ( isset($property->Comune) && !empty((string)$property->Comune) )
			{
				$display_address[] = (string)$property->Comune;
			}
			if ( isset($property->Provincia) && !empty((string)$property->Provincia) )
			{
				$display_address[] = (string)$property->Provincia;
			}
			$display_address = implode(", ", $display_address);
	        
	        if ($property_query->have_posts())
	        {
	        	// We've imported this property before
	            while ($property_query->have_posts())
	            {
	                $property_query->the_post();

	                $post_id = get_the_ID();

	                $this->log( 'This property has been imported before. Updating it', (string)$property->AnnuncioId, $post_id );

	                $my_post = array(
				    	'ID'          	 => $post_id,
				    	'post_title'     => wp_strip_all_tags( trim((string)$property->Titolo) !== '' ? (string)$property->Titolo : $display_address ),
				    	'post_excerpt'   => (string)$property->Descrizione,
				    	'post_content' 	 => (string)$property->Descrizione,
				    	'post_status'    => 'publish',
				  	);

				  	$my_post = apply_filters( 'houzez_property_feed_update_postarr', $my_post, $property, $this->import_id, $post_id );

				 	// Update the post into the database
				    $post_id = wp_update_post( $my_post, true );

				    if ( is_wp_error( $post_id ) ) 
					{
						$this->log_error( 'Failed to update post. The error was as follows: ' . $post_id->get_error_message(), (string)$property->AnnuncioId );
					}
					else
					{
						$inserted_updated = 'updated';
					}
	            }
	        }
	        else
	        {
	        	$this->log( 'This property hasn\'t been imported before. Inserting it', (string)$property->AnnuncioId );

	        	// We've not imported this property before
				$postdata = array(
					'post_excerpt'   => (string)$property->Descrizione,
					'post_content' 	 => (string)$property->Descrizione,
					'post_title'     => wp_strip_all_tags( trim((string)$property->Titolo) !== '' ? (string)$property->Titolo : $display_address ),
					'post_status'    => 'publish',
					'post_type'      => 'property',
					'comment_status' => 'closed',
				);

				$postdata = apply_filters( 'houzez_property_feed_insert_postarr', $postdata, $property, $this->import_id );

				$post_id = wp_insert_post( $postdata, true );

				if ( is_wp_error( $post_id ) ) 
				{
					$this->log_error( 'Failed to insert post. The error was as follows: ' . $post_id->get_error_message(), (string)$property->AnnuncioId );
				}
				else
				{
					$inserted_updated = 'inserted';
				}
			}
			$property_query->reset_postdata();

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

				$this->log( 'Successfully ' . $inserted_updated . ' post', (string)$property->AnnuncioId, $post_id );

				update_post_meta( $post_id, $imported_ref_key, (string)$property->AnnuncioId );

				update_post_meta( $post_id, '_property_import_data', $property->asXML() );

				// V = vendita (sale); A = affitto (rental), as illustrated in the demo.
				$department = ( trim((string)$property->Tipologia) === 'A' ) ? 'residential-lettings' : 'residential-sales';

				// TODO: Confirm whether Prezzo_Riservato = 1 should display POA.
                // Existing behaviour retained until confirmed.
				$poa = false;

				if ( $poa === true ) 
                {
                    update_post_meta( $post_id, 'fave_property_price_prefix', '' );
                    update_post_meta( $post_id, 'fave_property_price', 'POA');
                    update_post_meta( $post_id, 'fave_property_price_postfix', '' );
                }
                else
                {
                	$price = round((float)str_replace(',', '.', trim((string)$property->Prezzo)));

                    update_post_meta( $post_id, 'fave_property_price_prefix', '' );
                    update_post_meta( $post_id, 'fave_property_price', $price );
                    update_post_meta( $post_id, 'fave_property_price_postfix', '' );

                    if ( $department == 'residential-lettings' )
                    {
                    	// TODO: Confirm rental period; the XML has no frequency field.
                        $rent_frequency = '';
						update_post_meta( $post_id, 'fave_property_price_postfix', $rent_frequency );
                    }
                }

                update_post_meta( $post_id, 'fave_property_bedrooms', ( ( isset($property->Camere) ) ? (string)$property->Camere : '' ) );
	            update_post_meta( $post_id, 'fave_property_bathrooms', ( ( isset($property->Bagni) ) ? (string)$property->Bagni : '' ) );
	            update_post_meta( $post_id, 'fave_property_rooms', (string)$property->Vani );
	            
	            update_post_meta( $post_id, 'fave_property_size', ( ( isset($property->Mq) && trim((string)$property->Mq) !== '' ) ? (string)$property->Mq : '' ) );
	            update_post_meta( $post_id, 'fave_property_size_prefix', ( ( isset($property->Mq) && trim((string)$property->Mq) !== '' ) ? 'Sq M' : '' ) );
	            update_post_meta( $post_id, 'fave_property_land', '' );
	            update_post_meta( $post_id, 'fave_property_land_postfix', '' );

	            update_post_meta( $post_id, 'fave_property_garage', (string)$property->Box );
	            update_post_meta( $post_id, 'fave_property_id', (string)$property->Codice );

	            $address_parts = array();
	            if ( isset($property->Comune) && (string)$property->Comune != '' )
	            {
	                $address_parts[] = (string)$property->Comune;
	            }
	            if ( isset($property->Provincia) && (string)$property->Provincia != '' )
	            {
	                $address_parts[] = (string)$property->Provincia;
	            }
	            $address_parts = array_unique($address_parts);

	            update_post_meta( $post_id, 'fave_property_map', '1' );
	            update_post_meta( $post_id, 'fave_property_map_address', implode(", ", $address_parts) );
                $lat = trim((string)$property->Latitudine);
                $lng = trim((string)$property->Longitudine);
                $valid_location = is_numeric($lat) && is_numeric($lng)
                    && (float)$lat >= -90 && (float)$lat <= 90
                    && (float)$lng >= -180 && (float)$lng <= 180;
                update_post_meta( $post_id, 'houzez_geolocation_lat', $valid_location ? $lat : '' );
                update_post_meta( $post_id, 'houzez_geolocation_long', $valid_location ? $lng : '' );
                update_post_meta( $post_id, 'fave_property_location', $valid_location ? $lat . ',' . $lng . ',14' : '' );
                
	            // Keep the feed's country name; confirm whether this Houzez install expects a country code.
                update_post_meta( $post_id, 'fave_property_country', 'IT' );

	            update_post_meta( $post_id, 'fave_property_address', trim((string)$property->Indirizzo . ' ' . (string)$property->Civico) );
	            update_post_meta( $post_id, 'fave_property_zip', (string)$property->Cap );

	            update_post_meta( $post_id, 'fave_featured', '' );
	            update_post_meta( $post_id, 'fave_agent_display_option', ( isset($import_settings['agent_display_option']) ? $import_settings['agent_display_option'] : 'none' ) );

	            if ( 
	            	isset($import_settings['agent_display_option']) && 
	            	isset($import_settings['agent_display_option_rules']) && 
	            	is_array($import_settings['agent_display_option_rules']) && 
	            	!empty($import_settings['agent_display_option_rules']) 
	            )
	            {
		            switch ( $import_settings['agent_display_option'] )
		            {
		            	case "author_info":
		            	{
		            		foreach ( $import_settings['agent_display_option_rules'] as $rule )
		            		{
		            			$value_in_feed_to_check = '';
		            			switch ( $rule['field'] )
		            			{
		            				default:
		            				{
		            					$value_in_feed_to_check = isset($property->{$rule['field']}) ? (string)$property->{$rule['field']} : '';
		            				}
		            			}

		            			if ( $value_in_feed_to_check == $rule['equal'] || $rule['equal'] == '*' )
		            			{
		            				// set post author
		            				$my_post = array(
								    	'ID'          	 => $post_id,
								    	'post_author'    => $rule['result'],
								  	);

								 	// Update the post into the database
								    wp_update_post( $my_post, true );

		            				break; // Rule matched. Lets not do anymore
		            			}
		            		}
		            		break;
		            	}
		            	case "agent_info":
		            	{
		            		foreach ( $import_settings['agent_display_option_rules'] as $rule )
		            		{
		            			$value_in_feed_to_check = '';
		            			switch ( $rule['field'] )
		            			{
		            				default:
		            				{
		            					$value_in_feed_to_check = isset($property->{$rule['field']}) ? (string)$property->{$rule['field']} : '';
		            				}
		            			}

		            			if ( $value_in_feed_to_check == $rule['equal'] || $rule['equal'] == '*' )
		            			{
		            				update_post_meta( $post_id, 'fave_agents', $rule['result'] );
		            				break; // Rule matched. Lets not do anymore
		            			}
		            		}
		            		break;
		            	}
		            	case "agency_info":
		            	{
		            		foreach ( $import_settings['agent_display_option_rules'] as $rule )
		            		{
		            			$value_in_feed_to_check = '';
		            			switch ( $rule['field'] )
		            			{
		            				default:
		            				{
		            					$value_in_feed_to_check = isset($property->{$rule['field']}) ? (string)$property->{$rule['field']} : '';
		            				}
		            			}

		            			if ( $value_in_feed_to_check == $rule['equal'] || $rule['equal'] == '*' )
		            			{
		            				update_post_meta( $post_id, 'fave_property_agency', $rule['result'] );
		            				break; // Rule matched. Lets not do anymore
		            			}
		            		}
		            		break;
		            	}
		            }
	        	}

				$mappings = ( isset($import_settings['mappings']) && is_array($import_settings['mappings']) && !empty($import_settings['mappings']) ) ? $import_settings['mappings'] : array();

				if ( $department == 'residential-sales' )
				{
					$mapping_name = 'sales_status';

					$taxonomy_mappings = ( isset($mappings[$mapping_name]) && is_array($mappings[$mapping_name]) && !empty($mappings[$mapping_name]) ) ? $mappings[$mapping_name] : array();

					if ( isset($taxonomy_mappings['Sales']) && !empty($taxonomy_mappings['Sales']) )
					{
						wp_set_object_terms( $post_id, (int)$taxonomy_mappings['Sales'], "property_status" );
					}
				}
				elseif ( $department == 'residential-lettings' )
				{
					$mapping_name = 'lettings_status';

					$taxonomy_mappings = ( isset($mappings[$mapping_name]) && is_array($mappings[$mapping_name]) && !empty($mappings[$mapping_name]) ) ? $mappings[$mapping_name] : array();

					if ( isset($taxonomy_mappings['Lettings']) && !empty($taxonomy_mappings['Lettings']) )
					{
						wp_set_object_terms( $post_id, (int)$taxonomy_mappings['Lettings'], "property_status" );
					}
				}

				// property type taxonomies
				$taxonomy_mappings = ( isset($mappings['property_type']) && is_array($mappings['property_type']) && !empty($mappings['property_type']) ) ? $mappings['property_type'] : array();

                // Categoria is repeated and contains codes, not names. Map each code in settings.
                $property_type_ids = array();
                foreach ( $property->Categoria as $category )
                {
                    $code = trim((string)$category);
                    if ( $code === '' ) continue;
                    if ( !empty($taxonomy_mappings[$code]) )
                    {
                        $property_type_ids[] = (int)$taxonomy_mappings[$code];
                    }
                    else
                    {
                        $this->log( 'Received unmapped Categoria code ' . $code, (string)$property->AnnuncioId, $post_id );
                        $import_settings = $this->add_missing_mapping( $mappings, 'property_type', $code, $this->import_id );
                        $mappings = isset($import_settings['mappings']) && is_array($import_settings['mappings']) ? $import_settings['mappings'] : $mappings;
                    }
                }
                if ( !empty($property_type_ids) )
                {
                    wp_set_object_terms( $post_id, array_values(array_unique($property_type_ids)), 'property_type' );
                }

				// Location taxonomies
				$create_location_taxonomy_terms = isset( $import_settings['create_location_taxonomy_terms'] ) ? $import_settings['create_location_taxonomy_terms'] : false;

				$houzez_tax_settings = get_option('houzez_tax_settings', array() );
				
				$location_taxonomies = array();
				if ( !isset($houzez_tax_settings['property_city']) || ( isset($houzez_tax_settings['property_city']) && $houzez_tax_settings['property_city'] != 'disabled' ) )
				{
					$location_taxonomies[] = 'property_city';
				}
				if ( !isset($houzez_tax_settings['property_area']) || ( isset($houzez_tax_settings['property_area']) && $houzez_tax_settings['property_area'] != 'disabled' ) )
				{
					$location_taxonomies[] = 'property_area';
				}
				if ( !isset($houzez_tax_settings['property_state']) || ( isset($houzez_tax_settings['property_state']) && $houzez_tax_settings['property_state'] != 'disabled' ) )
				{
					$location_taxonomies[] = 'property_state';
				}

				foreach ( $location_taxonomies as $location_taxonomy )
				{
					$address_field_to_use = isset( $import_settings[$location_taxonomy . '_address_field'] ) ? $import_settings[$location_taxonomy . '_address_field'] : '';
					$location_field_aliases = array('town' => 'Comune', 'province' => 'Provincia');
                    if ( isset($location_field_aliases[$address_field_to_use]) )
                    {
                        $address_field_to_use = $location_field_aliases[$address_field_to_use];
                    }
                    if ( !empty($address_field_to_use) )
					{
						$location_term_ids = array();
						if ( isset($property->{$address_field_to_use}) && !empty((string)$property->{$address_field_to_use}) )
		            	{
		            		$term = term_exists( trim((string)$property->{$address_field_to_use}), $location_taxonomy);
							if ( $term !== 0 && $term !== null && isset($term['term_id']) )
							{
								$location_term_ids[] = (int)$term['term_id'];
							}
							else
							{
								if ( $create_location_taxonomy_terms === true )
								{
									$term = wp_insert_term( trim((string)$property->{$address_field_to_use}), $location_taxonomy );
									if ( is_array($term) && isset($term['term_id']) )
									{
										$location_term_ids[] = (int)$term['term_id'];
									}
								}
							}
		            	}
		            	if ( !empty($location_term_ids) )
						{
							wp_set_object_terms( $post_id, $location_term_ids, $location_taxonomy );
						}
						else
						{
							wp_delete_object_term_relationships( $post_id, $location_taxonomy );
						}
					}
				}

				// Images: Foto order is retained; this includes floor plans present in Foto.
                // Typed AMMedias, Foto360, documents, videos and tours are not imported here.
				if ( 
					apply_filters('houzez_property_feed_images_stored_as_urls', false, $post_id, $property, $this->import_id) === true ||
					apply_filters('houzez_property_feed_images_stored_as_urls_miogest', false, $post_id, $property, $this->import_id) === true
				)
				{
					$urls = array();

					if (isset($property->Foto))
	                {
	                    foreach (array($property) as $images)
	                    {
	                        if (!empty($images->Foto))
	                        {
	                            foreach ($images->Foto as $image)
	                            {
	                            	if ( $pro_active === true )
									{
										if ( 
											isset($import_settings['limit_images']) && 
											!empty((int)$import_settings['limit_images']) && 
											is_numeric($import_settings['limit_images']) &&
											count($urls) >= $import_settings['limit_images']
										)
							        	{
							        		break 2;
							        	}
							        }

									$url = trim((string)$image);
									if ( 
										substr( strtolower($url), 0, 2 ) == '//' ||
										substr( strtolower($url), 0, 4 ) == 'http'
									)
									{
										$urls[] = array(
											'url' => $url
										);
									}
								}
							}
						}
					}

					update_post_meta( $post_id, 'image_urls', $urls );
					update_post_meta( $post_id, 'images_stored_as_urls', true );

					$this->log( 'Imported ' . count($urls) . ' photo URLs', (string)$property->AnnuncioId, $post_id );
				}
				else
				{
					$media_ids = array();
					$new = 0;
					$existing = 0;
					$deleted = 0;
					$image_i = 0;
					$queued = 0;
					$previous_media_ids = get_post_meta( $post_id, 'fave_property_images' );

					$start_at_image_i = false;
					$previous_import_media_ids = get_option( 'houzez_property_feed_property_image_media_ids_' . $this->import_id );

					if ( !empty($previous_import_media_ids) )
					{
						// an import stopped previously whilst doing images. Check if it was this post
						$explode_previous_import_media_ids = explode("|", $previous_import_media_ids);
						if ( $explode_previous_import_media_ids[0] == $post_id )
						{
							// yes it was this property. now loop through the media already imported to ensure it's not imported again
							if ( isset($explode_previous_import_media_ids[1]) && !empty($explode_previous_import_media_ids[1]) )
							{
								$media_ids = explode(",", $explode_previous_import_media_ids[1]);
								$start_at_image_i = count($media_ids);

								$this->log( 'Imported ' . count($media_ids) . ' images before failing in the previous import. Continuing from here', (string)$property->AnnuncioId, $post_id );
							}
						}
					}

					if (isset($property->Foto))
	                {
	                    foreach (array($property) as $images)
	                    {
	                        if (!empty($images->Foto))
	                        {
	                            foreach ($images->Foto as $image)
	                            {
	                            	if ( $pro_active === true )
									{
										if ( 
											isset($import_settings['limit_images']) && 
											!empty((int)$import_settings['limit_images']) && 
											is_numeric($import_settings['limit_images']) &&
											(count($media_ids) + $queued) >= $import_settings['limit_images']
										)
							        	{
							        		break 2;
							        	}
							        }

									$url = trim((string)$image);
									if ( 
										substr( strtolower($url), 0, 2 ) == '//' ||
										substr( strtolower($url), 0, 4 ) == 'http'
									)
									{
										if ( $start_at_image_i !== false )
										{
											// we need to start at a specific image
											if ( $image_i < $start_at_image_i )
											{
												++$existing;
												++$image_i;
												continue;
											}
										}

										$description = '';

										$filename = sanitize_file_name( basename( (string)wp_parse_url($url, PHP_URL_PATH) ) );

										// Check, based on the URL, whether we have previously imported this media
										$imported_previously = false;
										$imported_previously_id = '';
										if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
										{
											foreach ( $previous_media_ids as $previous_media_id )
											{
												if ( 
													get_post_meta( $previous_media_id, '_imported_url', TRUE ) == $url
												)
												{
													$imported_previously = true;
													$imported_previously_id = $previous_media_id;
													break;
												}
											}
										}

										if ($imported_previously)
										{
											$media_ids[] = $imported_previously_id;

											if ( $description != '' )
											{
												$my_post = array(
											    	'ID'          	 => $imported_previously_id,
											    	'post_title'     => $description,
											    );

											 	// Update the post into the database
											    wp_update_post( $my_post );
											}

											if ( $image_i == 0 ) set_post_thumbnail( $post_id, $imported_previously_id );

											++$existing;

											++$image_i;

											update_option( 'houzez_property_feed_property_image_media_ids_' . $this->import_id, $post_id . '|' . implode(",", $media_ids), false );
										}
										else
										{
											if ( apply_filters( 'houzez_property_feed_import_media', true, $this->import_id, $post_id, (string)$property->AnnuncioId, $url, $url, $description, 'image', $image_i, '' ) === true )
											{
												$this->ping();
												
												$tmp = download_url( $url );

											    $file_array = array(
											        'name' => $filename,
											        'tmp_name' => $tmp
											    );

											    // Check for download errors
											    if ( is_wp_error( $tmp ) ) 
											    {
											        $this->log_error( 'An error occurred whilst importing ' . $url . '. The error was as follows: ' . $tmp->get_error_message(), (string)$property->AnnuncioId, $post_id );
											    }
											    else
											    {
												    $id = media_handle_sideload( $file_array, $post_id, $description );

												    // Check for handle sideload errors.
												    if ( is_wp_error( $id ) ) 
												    {
												        @unlink( $file_array['tmp_name'] );
												        
												        $this->log_error( 'ERROR: An error occurred whilst importing ' . $url . '. The error was as follows: ' . $id->get_error_message(), (string)$property->AnnuncioId, $post_id );
												    }
												    else
												    {
												    	$media_ids[] = $id;

												    	update_post_meta( $id, '_imported_url', $url);

												    	if ( $image_i == 0 ) set_post_thumbnail( $post_id, $id );

												    	++$new;

												    	++$image_i;

												    	update_option( 'houzez_property_feed_property_image_media_ids_' . $this->import_id, $post_id . '|' . implode(",", $media_ids), false );
												    }
												}
											}
											else
											{
												++$queued;
												++$image_i;
											}
										}
									}
								}
							}
						}
					}
					if ( $media_ids != $previous_media_ids )
					{
						delete_post_meta( $post_id, 'fave_property_images' );
						foreach ( $media_ids as $media_id )
						{
							add_post_meta( $post_id, 'fave_property_images', $media_id );
						}
					}

					update_post_meta( $post_id, 'images_stored_as_urls', false );

					// Loop through $previous_media_ids, check each one exists in $media_ids, and if it doesn't then delete
					if ( is_array($previous_media_ids) && !empty($previous_media_ids) )
					{
						foreach ( $previous_media_ids as $previous_media_id )
						{
							if ( !in_array($previous_media_id, $media_ids) )
							{
								if ( wp_delete_attachment( $previous_media_id, TRUE ) !== FALSE )
								{
									++$deleted;
								}
							}
						}
					}

					$this->log( 'Imported ' . count($media_ids) . ' photos (' . $new . ' new, ' . $existing . ' existing, ' . $deleted . ' deleted)', (string)$property->AnnuncioId, $post_id );
					if ( $queued > 0 ) 
					{
						$this->log( $queued . ' photos added to download queue', (string)$property->AnnuncioId, $post_id );
					}
					
					update_option( 'houzez_property_feed_property_image_media_ids_' . $this->import_id, '', false );
				}

				update_post_meta( $post_id, 'fave_video_url', '' );
				update_post_meta( $post_id, 'fave_virtual_tour', '' );

				if ( isset($property->VirtualTour) && (string)$property->VirtualTour != '' )
				{
					$url = trim((string)$property->VirtualTour);

					// Remove literal CDATA delimiters left by escaped CDATA in the feed.
					$url = preg_replace('/\A<!\[CDATA\[(.*?)\]\]>\z/s', '$1', $url);
					$url = trim($url);

					if ( !empty($url) )
					{
						if ( strpos(strtolower($url), 'youtu') !== false || strpos(strtolower($url), 'vimeo') !== false )
						{
							update_post_meta( $post_id, 'fave_video_url', $url );
						}
						else
						{
							$iframe = '<iframe src="' . esc_url($url) . '" style="border:0; height:360px; width:640px; max-width:100%" allowFullScreen="true"></iframe>';
							update_post_meta( $post_id, 'fave_virtual_tour', $iframe );
						}
					}
				}

				do_action( "houzez_property_feed_property_imported", $post_id, $property, $this->import_id, $this->instance_id );
				do_action( "houzez_property_feed_property_imported_miogest", $post_id, $property, $this->import_id, $this->instance_id );

				$post = get_post( $post_id );
				do_action( "save_post_property", $post_id, $post, false );
				do_action( "save_post", $post_id, $post, false );

				if ( $inserted_updated == 'updated' )
				{
					$this->compare_meta_and_taxonomy_data( $post_id, (string)$property->AnnuncioId, $metadata_before, $taxonomy_terms_before );
				}
			}

			++$property_row;

		} // end foreach property

		do_action( "houzez_property_feed_post_import_properties_miogest", $this->import_id );

		$this->import_end();
	}

	public function remove_old_properties()
	{
		global $wpdb, $post;

		if ( !empty($this->properties) )
		{
			$import_refs = array();
			foreach ($this->properties as $property)
			{
				$import_refs[] = (string)$property->AnnuncioId;
			}

			$this->do_remove_old_properties( $import_refs );

			unset($import_refs);
		}
	}
}

}