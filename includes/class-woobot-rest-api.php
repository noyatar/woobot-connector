<?php
/**
 * WooBot REST API - Registers and handles API endpoints.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_REST_API {

    private $namespace = 'woobot/v1';
    private $security;
    private $logger;
    private $image_upload;
    private $product_creator;

    public function __construct() {
        $this->security        = new WooBot_Security();
        $this->logger          = new WooBot_Logger();
        $this->image_upload    = new WooBot_Image_Upload();
        $this->product_creator = new WooBot_Product_Creator();
    }

    public function register_routes() {
        // Existing endpoints (authenticated via X-WooBot-Key)
        register_rest_route( $this->namespace, '/upload-image', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_upload_image' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/create-product', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_create_product' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/full-upload', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'handle_full_upload' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/status', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_status' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );

        register_rest_route( $this->namespace, '/store-info', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_store_info' ),
            'permission_callback' => array( $this, 'check_permissions' ),
        ) );
    }

    public function check_permissions( $request ) {
        $rate_check = $this->security->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }
        return $this->security->validate_api_key( $request );
    }

    /**
     * Handle image upload endpoint.
     */
    public function handle_upload_image( $request ) {
        $start_time = microtime( true );
        $params = $request->get_json_params();

        $image_url = $params['image_url'] ?? '';
        $base64    = $params['image_base64'] ?? '';
        $filename  = $params['filename'] ?? '';

        if ( empty( $image_url ) && empty( $base64 ) ) {
            $this->logger->log( array(
                'action_type' => 'upload_image', 'status' => 'error',
                'request_data' => $params, 'error_message' => 'Missing image data',
                'response_time' => microtime( true ) - $start_time,
            ) );
            return new WP_Error( 'woobot_missing_image', __( 'Provide image_url or image_base64.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        if ( ! empty( $image_url ) ) {
            $url_check = $this->security->validate_image_url( $image_url );
            if ( is_wp_error( $url_check ) ) { return $url_check; }
            $result = $this->image_upload->upload_from_url( $image_url, $filename );
        } else {
            $result = $this->image_upload->upload_from_base64( $base64, $filename );
        }

        $elapsed = microtime( true ) - $start_time;

        if ( is_wp_error( $result ) ) {
            $this->logger->log( array(
                'action_type' => 'upload_image', 'status' => 'error', 'request_data' => $params,
                'error_message' => $result->get_error_message(), 'response_time' => $elapsed,
            ) );
            return $result;
        }

        $this->logger->log( array(
            'action_type' => 'upload_image', 'status' => 'success', 'request_data' => $params,
            'response_data' => $result, 'response_time' => $elapsed, 'image_id' => $result['id'],
        ) );

        return new WP_REST_Response( array( 'success' => true, 'data' => $result ), 200 );
    }

    /**
     * Handle product creation endpoint.
     * Supports webhook payload from WooBot server with _credits and requestId.
     */
    public function handle_create_product( $request ) {
        $start_time = microtime( true );
        $params = $request->get_json_params();

        // Extract meta fields from server payload
        $request_id  = $params['requestId'] ?? '';
        $credits_data = $params['_credits'] ?? null;

        // Validate product data
        $validation = $this->security->validate_product_data( $params );
        if ( is_wp_error( $validation ) ) {
            $this->logger->log( array(
                'action_type' => 'create_product', 'status' => 'error', 'request_data' => $params,
                'error_message' => $validation->get_error_message(), 'response_time' => microtime( true ) - $start_time,
            ) );

            // Report failure to server
            if ( ! empty( $request_id ) ) {
                WooBot_Credits::report_product_created( array(
                    'productName'  => $params['name'] ?? '',
                    'status'       => 'error',
                    'errorMessage' => $validation->get_error_message(),
                    'requestId'    => $request_id,
                ) );
            }

            return $validation;
        }

        $sanitized = $this->security->sanitize_product_data( $params );

        // Handle image upload if image_url provided
        if ( ! empty( $sanitized['image_url'] ) && empty( $sanitized['image_id'] ) ) {
            $image_result = $this->image_upload->upload_from_url( $sanitized['image_url'] );
            if ( is_wp_error( $image_result ) ) {
                $this->logger->log( array(
                    'action_type' => 'create_product', 'status' => 'error', 'request_data' => $params,
                    'error_message' => 'Image upload failed: ' . $image_result->get_error_message(),
                    'response_time' => microtime( true ) - $start_time,
                ) );

                if ( ! empty( $request_id ) ) {
                    WooBot_Credits::report_product_created( array(
                        'productName'  => $sanitized['name'] ?? '',
                        'status'       => 'error',
                        'errorMessage' => 'Image upload failed: ' . $image_result->get_error_message(),
                        'requestId'    => $request_id,
                    ) );
                }

                return $image_result;
            }
            $sanitized['image_id'] = $image_result['id'];
        }

        // Handle images array from server webhook (array of URLs)
        if ( ! empty( $params['images'] ) && is_array( $params['images'] ) && empty( $sanitized['image_id'] ) ) {
            foreach ( $params['images'] as $img ) {
                $img_url = is_array( $img ) ? ( $img['url'] ?? $img['src'] ?? '' ) : $img;
                if ( ! empty( $img_url ) ) {
                    $img_result = $this->image_upload->upload_from_url( $img_url );
                    if ( ! is_wp_error( $img_result ) ) {
                        $sanitized['image_id'] = $img_result['id'];
                        break; // Use first successful image
                    }
                }
            }
        }

        // Map server field names to plugin field names
        if ( ! empty( $params['regularPrice'] ) && empty( $sanitized['price'] ) ) {
            $sanitized['price'] = floatval( $params['regularPrice'] );
        }

        // Create the product
        $result = $this->product_creator->create_product( $sanitized );
        $elapsed = microtime( true ) - $start_time;

        if ( is_wp_error( $result ) ) {
            $this->logger->log( array(
                'action_type' => 'create_product', 'status' => 'error', 'request_data' => $params,
                'error_message' => $result->get_error_message(), 'response_time' => $elapsed,
            ) );

            if ( ! empty( $request_id ) ) {
                WooBot_Credits::report_product_created( array(
                    'productName'  => $sanitized['name'] ?? '',
                    'status'       => 'error',
                    'errorMessage' => $result->get_error_message(),
                    'requestId'    => $request_id,
                ) );
            }

            return $result;
        }

        // Update credits from _credits in webhook payload
        if ( ! empty( $credits_data ) && is_array( $credits_data ) ) {
            WooBot_Credits::update_from_webhook( $credits_data );
        }

        $this->logger->log( array(
            'action_type' => 'create_product', 'status' => 'success', 'request_data' => $params,
            'response_data' => $result, 'response_time' => $elapsed,
            'product_id' => $result['product_id'], 'image_id' => $sanitized['image_id'] ?? null,
        ) );

        // Report success to server
        if ( ! empty( $request_id ) ) {
            WooBot_Credits::report_product_created( array(
                'productId'   => $result['product_id'],
                'productName' => $result['name'],
                'productUrl'  => $result['permalink'],
                'status'      => 'success',
                'requestId'   => $request_id,
            ) );
        }

        // Sync credits after successful creation
        WooBot_Credits::sync();

        return new WP_REST_Response( array( 'success' => true, 'data' => $result ), 201 );
    }

    /**
     * Handle full upload endpoint (image + product).
     */
    public function handle_full_upload( $request ) {
        $start_time = microtime( true );
        $params = $request->get_json_params();

        $validation = $this->security->validate_product_data( $params );
        if ( is_wp_error( $validation ) ) {
            $this->logger->log( array(
                'action_type' => 'full_upload', 'status' => 'error', 'request_data' => $params,
                'error_message' => $validation->get_error_message(), 'response_time' => microtime( true ) - $start_time,
            ) );
            return $validation;
        }

        $sanitized = $this->security->sanitize_product_data( $params );

        $image_url = $params['image_url'] ?? '';
        $base64    = $params['image_base64'] ?? '';
        $image_result = null;

        if ( ! empty( $image_url ) ) {
            $url_check = $this->security->validate_image_url( $image_url );
            if ( is_wp_error( $url_check ) ) { return $url_check; }
            $image_result = $this->image_upload->upload_from_url( $image_url );
        } elseif ( ! empty( $base64 ) ) {
            $image_result = $this->image_upload->upload_from_base64( $base64 );
        }

        if ( $image_result !== null ) {
            if ( is_wp_error( $image_result ) ) {
                $this->logger->log( array(
                    'action_type' => 'full_upload', 'status' => 'error', 'request_data' => $params,
                    'error_message' => 'Image: ' . $image_result->get_error_message(),
                    'response_time' => microtime( true ) - $start_time,
                ) );
                return $image_result;
            }
            $sanitized['image_id'] = $image_result['id'];
        }

        $result = $this->product_creator->create_product( $sanitized );
        $elapsed = microtime( true ) - $start_time;

        if ( is_wp_error( $result ) ) {
            if ( $image_result && ! empty( $image_result['id'] ) ) {
                wp_delete_attachment( $image_result['id'], true );
            }
            $this->logger->log( array(
                'action_type' => 'full_upload', 'status' => 'error', 'request_data' => $params,
                'error_message' => $result->get_error_message(), 'response_time' => $elapsed,
            ) );
            return $result;
        }

        $response_data = array( 'product' => $result, 'image' => $image_result );

        $this->logger->log( array(
            'action_type' => 'full_upload', 'status' => 'success', 'request_data' => $params,
            'response_data' => $response_data, 'response_time' => $elapsed,
            'product_id' => $result['product_id'], 'image_id' => $image_result ? $image_result['id'] : null,
        ) );

        return new WP_REST_Response( array( 'success' => true, 'data' => $response_data ), 201 );
    }

    /**
     * Handle status endpoint - returns stored credits from sync.
     */
    public function handle_status( $request ) {
        $wc_active = class_exists( 'WooCommerce' );
        $balance = WooBot_Credits::get_balance();

        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'plugin_version' => WOOBOT_VERSION,
                'woocommerce'    => $wc_active,
                'wc_version'     => $wc_active ? WC()->version : null,
                'wp_version'     => get_bloginfo( 'version' ),
                'php_version'    => PHP_VERSION,
                'store_key'      => get_option( 'woobot_store_key' ),
                'credits'        => array(
                    'listing'     => $balance['listing'],
                    'enhancement' => $balance['enhancement'],
                    'last_sync'   => $balance['last_sync'],
                ),
                'stats'          => array(
                    'total_uploads'  => (int) get_option( 'woobot_total_uploads', 0 ),
                    'total_enhanced' => (int) get_option( 'woobot_total_enhanced', 0 ),
                    'last_upload'    => get_option( 'woobot_last_upload', '' ),
                ),
            ),
        ), 200 );
    }

    /**
     * Handle store info endpoint.
     */
    public function handle_store_info( $request ) {
        return new WP_REST_Response( array(
            'success' => true,
            'data'    => array(
                'store_name'  => get_bloginfo( 'name' ),
                'store_url'   => home_url(),
                'store_key'   => get_option( 'woobot_store_key' ),
                'partner_id'  => get_option( 'woobot_partner_id', '' ),
                'currency'    => get_woocommerce_currency(),
                'timezone'    => wp_timezone_string(),
                'locale'      => get_locale(),
            ),
        ), 200 );
    }
}
