<?php
/**
 * Class for managing the export process of a Fotocasa feed
 *
 * @package WordPress
 */
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if ( class_exists( 'Houzez_Property_Feed_Process' ) ) {

class Houzez_Property_Feed_Format_Fotocasa extends Houzez_Property_Feed_Process {

    private $source_id = '3dfaf105-e6e9-448b-825a-489d2f4f352f';

    private $remote_properties = array();

    public function __construct()
    {
        $this->is_import = false;

        add_action( 'save_post', array( $this, 'send_realtime_feed_request' ), 99 );

        add_filter( 'houzez_before_submit_property', array( $this, 'remove_save_post_hook' ) );
        add_filter( 'houzez_before_update_property', array( $this, 'remove_save_post_hook' ) );

        add_action( 'houzez_after_property_submit', array( $this, 'send_realtime_feed_request' ), 99 );
        add_action( 'houzez_after_property_update', array( $this, 'send_realtime_feed_request' ), 99 );

        add_action( 'before_delete_post', array( $this, 'delete_property' ) );

        add_action( 'houzezpropertyfeedreconcilecronhook', array( $this, 'reconcile' ) );
        
        add_action( 'houzez_property_feed_push_all', array( $this, 'push_all_properties' ) );
    }

    public function remove_save_post_hook( $new_property )
    {
        remove_action( 'save_post', array( $this, 'send_realtime_feed_request' ), 99 );
        return $new_property;
    }

    private function get_exports()
    {
        $options = get_option( 'houzez_property_feed', array() );
        $exports = isset($options['exports']) && is_array($options['exports']) ? $options['exports'] : array();

        foreach ( $exports as $export_id => $export )
        {
            if ( !isset($export['format']) || $export['format'] !== 'fotocasa' || empty($export['running']) || !empty($export['deleted']) )
            {
                unset($exports[$export_id]);
            }
        }

        if ( apply_filters( 'houzez_property_feed_pro_active', false ) !== true )
        {
            $exports = array_slice( $exports, 0, 1, true );
        }

        return $exports;
    }

    private function start_instance( $export_id )
    {
        global $wpdb;

        $this->export_id = $export_id;
        $this->remote_properties = array();
        $keep_logs_days = (string)apply_filters( 'houzez_property_feed_keep_logs_days', '1' );
        if ( !preg_match('/^\d+$/', $keep_logs_days) )
        {
            $keep_logs_days = '1';
        }
        $wpdb->query( "DELETE FROM " . $wpdb->prefix . "houzez_property_feed_export_logs_instance WHERE start_date < DATE_SUB(NOW(), INTERVAL " . $keep_logs_days . " DAY)" );
        $wpdb->query( "DELETE FROM " . $wpdb->prefix . "houzez_property_feed_export_logs_instance_log WHERE log_date < DATE_SUB(NOW(), INTERVAL " . $keep_logs_days . " DAY)" );
        $wpdb->insert( $wpdb->prefix . 'houzez_property_feed_export_logs_instance', array(
            'export_id' => $export_id,
            'start_date' => gmdate('Y-m-d H:i:s'),
        ) );
        $this->instance_id = $wpdb->insert_id;
    }

    private function end_instance()
    {
        global $wpdb;

        $wpdb->update( $wpdb->prefix . 'houzez_property_feed_export_logs_instance', array(
            'end_date' => gmdate('Y-m-d H:i:s'),
        ), array( 'id' => $this->instance_id ) );
    }

    private function get_property_args( $post_id = 0 )
    {
        $args = array(
            'post_type' => 'property',
            'post_status' => 'publish',
            'nopaging' => true,
            'meta_query' => array(),
            'tax_query' => array(),
        );
        if ( $post_id )
        {
            $args['p'] = $post_id;
        }
        $args = apply_filters( 'houzez_property_feed_export_property_args', $args, $this->export_id );
        return apply_filters( 'houzez_property_feed_export_fotocasa_property_args', $args, $this->export_id );
    }

    private function should_send( $post_id )
    {
        if ( get_post_type($post_id) !== 'property' || get_post_status($post_id) !== 'publish' )
        {
            return false;
        }
        $query = new WP_Query( $this->get_property_args($post_id) );
        return in_array( (int)$post_id, array_map( 'intval', wp_list_pluck($query->posts, 'ID') ), true );
    }

    public function send_realtime_feed_request( $post_id )
    {
        if ( empty($post_id) || get_post_type($post_id) !== 'property' || wp_is_post_revision($post_id) ||
            ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) || get_post_status($post_id) === 'auto-draft' )
        {
            return;
        }

        foreach ( $this->get_exports() as $export_id => $export )
        {
            $this->start_instance( $export_id );
            if ( $this->should_send($post_id) )
            {
                $this->create_send_property_request( $post_id );
            }
            else
            {
                $this->create_remove_property_request( $post_id );
            }
            $this->end_instance();
        }
    }

    public function delete_property( $post_id )
    {
        if ( get_post_type($post_id) !== 'property' )
        {
            return;
        }
        foreach ( $this->get_exports() as $export_id => $export )
        {
            $this->start_instance( $export_id );
            $this->create_remove_property_request( $post_id );
            $this->end_instance();
        }
    }

    private function get_external_id( $post_id )
    {
        // Only reconcile properties belonging to this export, never other agency stock.
        return 'hpf_' . $this->export_id . '_' . (int)$post_id;
    }

    private function get_remote_properties()
    {
        if ( isset($this->remote_properties[$this->export_id]) )
        {
            return $this->remote_properties[$this->export_id];
        }
        $response = $this->do_request( 'GET', 'api/property' );
        if ( $response === false )
        {
            return false;
        }
        // This endpoint returns a JSON-encoded string containing a JSON array.
        if ( is_string($response) )
        {
            $response = json_decode( $response, true );
        }
        if ( !is_array($response) || array_values($response) !== $response )
        {
            $this->log_error( 'Invalid Fotocasa property list. No properties will be sent or removed.' );
            return false;
        }
        $properties = array();
        foreach ( $response as $property )
        {
            if ( !is_array($property) || !isset($property['ExternalId']) || !is_scalar($property['ExternalId']) )
            {
                $this->log_error( 'Invalid property in Fotocasa property list.' );
                return false;
            }
            $properties[(string)$property['ExternalId']] = $property;
        }
        $this->remote_properties[$this->export_id] = $properties;
        return $properties;
    }

    public function create_send_property_request( $post_id, $force = false )
    {
        $export_settings = houzez_property_feed_get_export_settings_from_id( $this->export_id );
        $request_data = $this->get_property_data( $post_id, $export_settings );
        if ( $request_data === false )
        {
            return false;
        }
        $mapped_type = array( $request_data['TypeId'], $request_data['SubTypeId'] );
        $request_data = apply_filters( 'houzez_property_feed_export_property_data', $request_data, $post_id, $this->export_id );
        $request_data = apply_filters( 'houzez_property_feed_export_fotocasa_property_data', $request_data, $post_id, $this->export_id );
        $filtered_type = array( isset($request_data['TypeId']) ? $request_data['TypeId'] : null, isset($request_data['SubTypeId']) ? $request_data['SubTypeId'] : null );
        if ( $mapped_type != $filtered_type )
        {
            $this->log( 'Fotocasa property type changed by field mapping or a data filter: ' . wp_json_encode($mapped_type) . ' -> ' . wp_json_encode($filtered_type), '', $post_id );
        }
        // The identifier must remain stable for updates and safe reconciliation.
        $external_id = $this->get_external_id( $post_id );
        $request_data['ExternalId'] = $external_id;
        $properties = $this->get_remote_properties();
        if ( $properties === false )
        {
            return false;
        }
        $exists = isset($properties[$external_id]);
        $limit = apply_filters( 'houzez_property_feed_property_limit', 25 );
        if ( !$exists && $limit !== false && count($properties) >= (int)$limit )
        {
            $this->log_error( 'Property export limit reached. Upgrade to PRO to export more properties.', '', $post_id );
            return false;
        }
        $hash = sha1( wp_json_encode($request_data) . $export_settings['api_key'] . $this->source_id );
        if ( !$force && $exists && isset($export_settings['only_send_if_different']) && $export_settings['only_send_if_different'] === 'yes' &&
            get_post_meta($post_id, '_fotocasa_sha1_' . $this->export_id, true) === $hash )
        {
            $this->log( 'Property data unchanged. Not sending to Fotocasa.', '', $post_id );
            return true;
        }
        $response = $this->do_request( $exists ? 'PUT' : 'POST', 'api/property', $request_data, $post_id );
        if ( $response === false )
        {
            return false;
        }
        $this->remote_properties[$this->export_id][$external_id] = array( 'ExternalId' => $external_id );
        update_post_meta( $post_id, '_fotocasa_sha1_' . $this->export_id, $hash );
        return true;
    }

    public function create_remove_property_request( $post_id )
    {
        $external_id = $this->get_external_id( $post_id );
        $properties = $this->get_remote_properties();
        if ( $properties === false )
        {
            return false;
        }
        if ( !isset($properties[$external_id]) )
        {
            return true;
        }
        $response = $this->do_request( 'DELETE', 'api/v2/property/' . rawurlencode(base64_encode($external_id)), null, $post_id );
        if ( $response === false )
        {
            return false;
        }
        unset($this->remote_properties[$this->export_id][$external_id]);
        delete_post_meta( $post_id, '_fotocasa_sha1_' . $this->export_id );
        return true;
    }

    private function get_property_data( $post_id, $export_settings )
    {
        $mapped_type = $this->get_export_mapped_value( $post_id, 'property_type' );
        $terms = get_the_terms( $post_id, 'property_type' );
        $type_mappings = array();
        if ( !is_wp_error($terms) && !empty($terms) )
        {
            foreach ( $terms as $term )
            {
                $type_mappings[] = array(
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'parent' => $term->parent,
                    'mapping' => isset($export_settings['mappings']['property_type'][$term->term_id]) ? $export_settings['mappings']['property_type'][$term->term_id] : '',
                );
            }
        }
        $type = explode( ':', $mapped_type );
        if ( count($type) !== 2 || !ctype_digit($type[0]) || !ctype_digit($type[1]) )
        {
            $this->log_error( 'No Fotocasa property type mapped. Check the export taxonomy mappings.', '', $post_id );
            return false;
        }
        $location = explode( ',', get_post_meta($post_id, 'fave_property_location', true) );
        if ( count($location) < 2 || !is_numeric(trim($location[0])) || !is_numeric(trim($location[1])) ||
            abs((float)$location[0]) > 90 || abs((float)$location[1]) > 180 )
        {
            $this->log_error( 'Valid latitude and longitude are required by Fotocasa.', '', $post_id );
            return false;
        }
        $area = $this->get_area( get_post_meta($post_id, 'fave_property_size', true), get_post_meta($post_id, 'fave_property_size_prefix', true) );
        if ( $area === false )
        {
            $this->log_error( 'Fotocasa requires a positive property size with a recognised area unit.', '', $post_id );
            return false;
        }
        $department = $this->get_department( $post_id );
        $transaction = ( $department == 'lettings' ? 3 : 1 );
        $price = str_replace( ',', '', get_post_meta($post_id, 'fave_property_price', true) );
        if ( !is_numeric($price) || (float)$price <= 0 )
        {
            $this->log_error( 'A positive price in euros is required by Fotocasa.', '', $post_id );
            return false;
        }
        $title = $this->get_text( get_post_field('post_title', $post_id), 130 );
        if ( $title === '' )
        {
            $this->log_error( 'A property title is required by Fotocasa.', '', $post_id );
            return false;
        }
        $reference = get_post_meta( $post_id, 'fave_property_id', true );
        $data = array(
            'ExternalId' => $this->get_external_id($post_id),
            'AgencyReference' => $this->get_text( $reference !== '' ? $reference : (string)$post_id, 50 ),
            'TypeId' => (int)$type[0],
            'SubTypeId' => (int)$type[1],
            'ContactTypeId' => 1,
            'PropertyAddress' => array(array(
                'x' => (float)$location[1],
                'y' => (float)$location[0],
                'VisibilityModeId' => isset($export_settings['address_visibility']) ? (int)$export_settings['address_visibility'] : 2,
                'ZipCode' => (string)get_post_meta($post_id, 'fave_property_zip', true),
                'Street' => (string)get_post_meta($post_id, 'fave_property_address', true),
            )),
            'PropertyFeature' => array(
                array( 'FeatureId' => 1, 'DecimalValue' => $area ),
                array( 'FeatureId' => 2, 'TextValue' => $title ),
                array( 'FeatureId' => 3, 'TextValue' => $this->get_text(get_post_field('post_content', $post_id), 5000) ),
            ),
            'PropertyContactInfo' => array(),
            'PropertyTransaction' => array(array(
                'TransactionTypeId' => (int)$transaction,
                'Price' => (float)$price,
                'ShowPrice' => true,
            )),
            'PropertyDocument' => array(),
        );
        if ( $department == 'lettings' )
        {
            $data['PropertyTransaction'][0]['RentalType'] = 'LONG_TERM';
        }
        // Use the agency's main contact details unless explicit overrides are provided.
        foreach ( array('contact_email' => 1, 'contact_phone' => 2) as $field => $contact_type )
        {
            if ( !empty($export_settings[$field]) )
            {
                $data['ContactTypeId'] = 3;
                $data['PropertyContactInfo'][] = array( 'TypeId' => $contact_type, 'Value' => $export_settings[$field] );
            }
        }
        $numeric_features = array(
            'fave_property_bedrooms' => array(11, array(1, 2, 5)),
            'fave_property_bathrooms' => array(12, array(1, 2, 3, 5, 7)),
            'fave_property_year' => array(231, array(1, 2, 3, 4, 5, 7, 8)),
        );
        foreach ( $numeric_features as $field => $feature )
        {
            $value = get_post_meta( $post_id, $field, true );
            if ( in_array((int)$type[0], $feature[1], true) && is_numeric($value) && (float)$value >= 0 )
            {
                $data['PropertyFeature'][] = array( 'FeatureId' => $feature[0], 'DecimalValue' => (float)$value );
            }
        }
        // Fotocasa accepts land area for houses, land and industrial buildings.
        if ( in_array((int)$type[0], array(2, 6, 7), true) )
        {
            $land = get_post_meta( $post_id, 'fave_property_land', true );
            if ( $land !== '' )
            {
                $land_area = $this->get_area( $land, get_post_meta($post_id, 'fave_property_land_postfix', true) );
                if ( $land_area !== false )
                {
                    $data['PropertyFeature'][] = array( 'FeatureId' => 69, 'DecimalValue' => $land_area );
                }
                else
                {
                    $this->log( 'Fotocasa land area omitted. Enter a positive land area and a recognised area unit.', '', $post_id );
                }
            }
        }
        if ( in_array((int)$type[0], array(1, 2, 3, 4, 5), true) )
        {
            $data['PropertyFeature'] = array_merge( $data['PropertyFeature'], $this->get_energy_features($post_id) );
        }
        if ( get_post_meta($post_id, 'images_stored_as_urls', true) === '1' )
        {
            $images = get_post_meta( $post_id, 'image_urls', true );
            if ( is_array($images) )
            {
                foreach ( $images as $image )
                {
                    if ( !empty($image['url']) )
                    {
                        $data['PropertyDocument'][] = array( 'TypeId' => 1, 'Url' => $image['url'], 'SortingId' => count($data['PropertyDocument']) + 1 );
                    }
                }
            }
        }
        else
        {
            foreach ( get_post_meta($post_id, 'fave_property_images') as $attachment_id )
            {
                $url = wp_get_attachment_url( $attachment_id );
                if ( $url && wp_attachment_is_image($attachment_id) )
                {
                    $data['PropertyDocument'][] = array( 'TypeId' => 1, 'Url' => $url, 'SortingId' => count($data['PropertyDocument']) + 1 );
                }
            }
        }
        $floor_plans = get_post_meta( $post_id, 'floor_plans', true );
        if ( is_array($floor_plans) )
        {
            foreach ( $floor_plans as $floor_plan )
            {
                if ( !empty($floor_plan['fave_plan_image']) )
                {
                    $data['PropertyDocument'][] = array( 'TypeId' => 23, 'Url' => $floor_plan['fave_plan_image'], 'SortingId' => count($data['PropertyDocument']) + 1 );
                }
            }
        }
        return $data;
    }

    private function get_department( $post_id )
    {
        $department = 'sales';

        $options = get_option( 'houzez_property_feed' , array() );
        $sales_statuses = ( isset($options['sales_statuses']) && is_array($options['sales_statuses']) && !empty($options['sales_statuses']) ) ? $options['sales_statuses'] : array();
        $lettings_statuses = ( isset($options['lettings_statuses']) && is_array($options['lettings_statuses']) && !empty($options['lettings_statuses']) ) ? $options['lettings_statuses'] : array();

        $status_terms = get_the_terms( $post_id, 'property_status' );
        if ( !is_wp_error($status_terms) && !empty($status_terms) )
        {
            foreach ( $status_terms as $term )
            {
                if ( in_array($term->term_id, $sales_statuses) )
                {
                    $department = 'sales';
                }
                elseif ( in_array($term->term_id, $lettings_statuses) )
                {
                    $department = 'lettings';
                }
            }
        }

        return $department;
    }

    private function get_energy_features( $post_id )
    {
        // Houzez supplies consumption data. Emissions require separate custom fields;
        // the renewable energy index and EPC scores are not emissions measurements.
        $certificate = array(
            'consumption_class' => get_post_meta($post_id, 'fave_energy_class', true),
            'consumption_value' => get_post_meta($post_id, 'fave_energy_global_index', true),
            'emissions_class' => get_post_meta($post_id, 'fave_ghg_emissions_class', true),
            'emissions_value' => get_post_meta($post_id, 'fave_ghg_emissions_index', true),
        );
        $certificate = apply_filters( 'houzez_property_feed_export_fotocasa_energy_certificate', $certificate, $post_id, $this->export_id );
        $features = array();
        $missing_fields = array();

        foreach ( array('consumption' => array(323, 325), 'emissions' => array(324, 326)) as $measurement => $feature_ids )
        {
            $class = isset($certificate[$measurement . '_class']) && is_scalar($certificate[$measurement . '_class']) ?
                strtoupper(trim((string)$certificate[$measurement . '_class'])) : '';
            $value = isset($certificate[$measurement . '_value']) && is_scalar($certificate[$measurement . '_value']) ?
                trim((string)$certificate[$measurement . '_value']) : '';
            // Accept decimal commas without treating them as thousands separators.
            if ( preg_match('/^\d+,\d+$/', $value) )
            {
                $value = str_replace( ',', '.', $value );
            }
            if ( !preg_match('/^[A-G]$/', $class) )
            {
                $missing_fields[] = $measurement . ' rating (A-G)';
            }
            if ( !is_numeric($value) || !is_finite((float)$value) || (float)$value < 1 || (float)$value > 999 )
            {
                $missing_fields[] = $measurement . ' value (1-999)';
            }
            if ( preg_match('/^[A-G]$/', $class) && is_numeric($value) && is_finite((float)$value) && (float)$value >= 1 && (float)$value <= 999 )
            {
                $features[] = array( 'FeatureId' => $feature_ids[0], 'DecimalValue' => ord($class) - ord('A') + 1 );
                $features[] = array( 'FeatureId' => $feature_ids[1], 'DecimalValue' => (float)$value );
            }
        }
        if ( !empty($missing_fields) )
        {
            $this->log( 'Fotocasa energy certificate omitted. Missing or invalid: ' . implode(', ', $missing_fields) . '. Both consumption and emissions data are required to send an available certificate. Fotocasa may apply its G-999 fallback.', '', $post_id );
            return array();
        }
        $features[] = array( 'FeatureId' => 327, 'DecimalValue' => 1 );
        return $features;
    }

    private function get_text( $value, $length )
    {
        $value = trim( html_entity_decode(wp_strip_all_tags(strip_shortcodes($value)), ENT_QUOTES, 'UTF-8') );
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : wp_check_invalid_utf8(substr($value, 0, $length), true);
    }

    private function get_area( $value, $unit )
    {
        $value = str_replace( ',', '', trim((string)$value) );
        if ( !is_numeric($value) || (float)$value <= 0 )
        {
            return false;
        }
        $unit = strtolower( str_replace(array('²', '^2'), '2', html_entity_decode(trim((string)$unit), ENT_QUOTES, 'UTF-8')) );
        $unit = preg_replace( '/[^a-z0-9]+/', '', $unit );
        if ( in_array($unit, array('sqm', 'sqmetre', 'sqmetres', 'sqmeter', 'sqmeters', 'squaremetre', 'squaremetres', 'squaremeter', 'squaremeters', 'm2', 'metre2', 'metres2', 'meter2', 'meters2'), true) )
        {
            return (float)$value;
        }
        if ( in_array($unit, array('sqft', 'sqfeet', 'squarefoot', 'squarefeet', 'ft2', 'feet2', 'foot2'), true) )
        {
            return (float)$value * 0.09290304;
        }
        if ( in_array($unit, array('acre', 'acres'), true) )
        {
            return (float)$value * 4046.8564224;
        }
        if ( in_array($unit, array('hectare', 'hectares', 'ha'), true) )
        {
            return (float)$value * 10000;
        }
        return false;
    }

    private function do_request( $method, $path, $data = null, $post_id = 0 )
    {
        $export_settings = houzez_property_feed_get_export_settings_from_id( $this->export_id );
        if ( empty($export_settings['api_key']) )
        {
            $this->log_error( 'Enter the Fotocasa API key in the export settings.', '', $post_id );
            return false;
        }
        $args = array(
            'method' => $method,
            'timeout' => 60,
            'redirection' => 0,
            'headers' => array(
                'Api-Key' => $export_settings['api_key'],
                'X-Source' => $this->source_id,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ),
        );
        if ( $data !== null )
        {
            $args['body'] = wp_json_encode( $data );
            if ( $args['body'] === false )
            {
                $this->log_error( 'Unable to encode Fotocasa property data as JSON.', '', $post_id );
                return false;
            }
        }
        $url = 'https://imports.gw.fotocasa.pro/' . $path;
        $request_body = isset($args['body']) ? $args['body'] : '(no body)';
        // Log the actual payload after mapping and filters, without authentication headers.
        $this->log( 'Sending Fotocasa ' . $method . ' request to ' . $url . ': ' . htmlentities($this->redact($request_body, $export_settings), ENT_QUOTES, 'UTF-8'), '', $post_id );
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error($response) )
        {
            $this->log_error( 'Fotocasa request failed: ' . $this->redact($response->get_error_message(), $export_settings), '', $post_id );
            return false;
        }
        $status = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        // Keep the complete response, including errors and non-JSON responses.
        $this->log( 'Fotocasa ' . $method . ' response (HTTP ' . $status . '): ' . htmlentities($this->redact($response_body, $export_settings), ENT_QUOTES, 'UTF-8'), '', $post_id );
        $body = json_decode( $response_body, true );
        $code = is_array($body) && isset($body['Code']) ? strtoupper((string)$body['Code']) : '';
        if ( $method === 'DELETE' && in_array($code, array('ERROR_111', 'ERROR_1402'), true) )
        {
            return array( 'Code' => 'OK_202' );
        }
        if ( $status < 200 || $status >= 300 || json_last_error() !== JSON_ERROR_NONE ||
            ( $method !== 'GET' && ( strpos($code, 'OK_') !== 0 || (isset($body['StatusCode']) && ((int)$body['StatusCode'] < 200 || (int)$body['StatusCode'] >= 300)) ) ) )
        {
            $message = is_array($body) && isset($body['Message']) ? $this->redact((string)$body['Message'], $export_settings) : 'Invalid or unsuccessful API response';
            $this->log_error( 'Fotocasa HTTP ' . $status . ' ' . $code . ': ' . $message, '', $post_id );
            return false;
        }
        if ( $method !== 'GET' )
        {
            $message = isset($body['Message']) ? $this->redact((string)$body['Message'], $export_settings) : '';
            $this->log( 'Fotocasa ' . $code . ': ' . $message, '', $post_id );
        }
        return $body;
    }

    private function redact( $message, $export_settings )
    {
        return str_replace( array($export_settings['api_key'], $this->source_id), '[redacted]', $message );
    }

    public function push_all_properties()
    {
        $export_id = isset($_GET['export_id']) ? (int)$_GET['export_id'] : 0;
        $exports = $this->get_exports();
        if ( !isset($exports[$export_id]) )
        {
            return;
        }
        $this->start_instance( $export_id );
        $this->log( 'Pushing all properties to Fotocasa' );
        $this->sync_properties( true );
        $this->end_instance();
    }

    private function sync_properties( $force = false )
    {
        $properties = $this->get_remote_properties();
        if ( $properties === false )
        {
            return;
        }
        $query = new WP_Query( $this->get_property_args() );
        $post_ids = array_map( 'intval', wp_list_pluck($query->posts, 'ID') );
        $prefix = 'hpf_' . $this->export_id . '_';
        // Remove withdrawn, deleted and deselected listings before applying the limit.
        foreach ( $properties as $external_id => $property )
        {
            if ( strpos($external_id, $prefix) !== 0 )
            {
                continue;
            }
            $post_id = substr( $external_id, strlen($prefix) );
            if ( ctype_digit($post_id) && $external_id === $this->get_external_id($post_id) && !in_array((int)$post_id, $post_ids, true) )
            {
                $this->create_remove_property_request( (int)$post_id );
            }
        }
        foreach ( $post_ids as $post_id )
        {
            if ( get_post_type($post_id) === 'property' && get_post_status($post_id) === 'publish' )
            {
                $this->create_send_property_request( $post_id, $force );
            }
        }
    }

    public function reconcile()
    {
        foreach ( $this->get_exports() as $export_id => $export )
        {
            $this->start_instance( $export_id );
            $this->log( 'Reconciling Fotocasa properties' );
            $this->sync_properties();
            $this->log( 'Fotocasa reconciliation complete' );
            $this->end_instance();
            do_action( 'houzez_property_feed_cron_end', $this->instance_id, $export_id );
            do_action( 'houzez_property_feed_export_cron_end', $this->instance_id, $export_id );
        }
    }
}

new Houzez_Property_Feed_Format_Fotocasa();

}
