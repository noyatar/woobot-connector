<?php
/**
 * WooBot Security - Authentication, rate limiting, validation.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Security {

    public function validate_api_key( $request ) {
        $secret_key = '';

        // Check headers (support both formats)
        $auth_header = $request->get_header( 'X-WooBot-Key' );
        if ( empty( $auth_header ) ) {
            $auth_header = $request->get_header( 'x-woobot-api-key' );
        }
        if ( ! empty( $auth_header ) ) {
            $secret_key = sanitize_text_field( $auth_header );
        }

        // Fallback to body parameter
        if ( empty( $secret_key ) ) {
            $secret_key = $request->get_param( 'secret_key' );
        }

        if ( empty( $secret_key ) ) {
            return new WP_Error(
                'woobot_missing_key',
                __( 'API key is required. Provide it via X-WooBot-Key header or secret_key parameter.', 'woobot-whatsapp-uploader' ),
                array( 'status' => 401 )
            );
        }

        $stored_key = get_option( 'woobot_api_key' );

        if ( ! hash_equals( $stored_key, $secret_key ) ) {
            return new WP_Error(
                'woobot_invalid_key',
                __( 'Invalid API key.', 'woobot-whatsapp-uploader' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    public function check_rate_limit() {
        $limit = (int) get_option( 'woobot_rate_limit', 30 );
        if ( $limit <= 0 ) {
            return true;
        }

        $ip = $this->get_client_ip();
        $transient_key = 'woobot_rate_' . md5( $ip );
        $current = (int) get_transient( $transient_key );

        if ( $current >= $limit ) {
            return new WP_Error(
                'woobot_rate_limit',
                sprintf(
                    __( 'Rate limit exceeded. Maximum %d requests per minute.', 'woobot-whatsapp-uploader' ),
                    $limit
                ),
                array( 'status' => 429 )
            );
        }

        set_transient( $transient_key, $current + 1, MINUTE_IN_SECONDS );
        return true;
    }

    public function validate_image_url( $url ) {
        if ( empty( $url ) || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error(
                'woobot_invalid_url',
                __( 'A valid image URL is required.', 'woobot-whatsapp-uploader' ),
                array( 'status' => 400 )
            );
        }

        $scheme = wp_parse_url( $url, PHP_URL_SCHEME );
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error(
                'woobot_invalid_scheme',
                __( 'Only HTTP and HTTPS URLs are allowed.', 'woobot-whatsapp-uploader' ),
                array( 'status' => 400 )
            );
        }

        return true;
    }

    public function validate_product_data( $data ) {
        if ( empty( $data['name'] ) ) {
            return new WP_Error(
                'woobot_missing_name',
                __( 'Product name is required.', 'woobot-whatsapp-uploader' ),
                array( 'status' => 400 )
            );
        }

        if ( isset( $data['price'] ) && ! is_numeric( $data['price'] ) ) {
            return new WP_Error( 'woobot_invalid_price', __( 'Price must be a number.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        if ( isset( $data['price'] ) && floatval( $data['price'] ) < 0 ) {
            return new WP_Error( 'woobot_negative_price', __( 'Price cannot be negative.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        if ( isset( $data['stock_quantity'] ) && ! is_numeric( $data['stock_quantity'] ) ) {
            return new WP_Error( 'woobot_invalid_stock', __( 'Stock quantity must be a number.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        $allowed_statuses = array( 'draft', 'publish', 'pending' );
        if ( isset( $data['status'] ) && ! in_array( $data['status'], $allowed_statuses, true ) ) {
            return new WP_Error( 'woobot_invalid_status', __( 'Status must be: draft, publish, or pending.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        return true;
    }

    public function sanitize_product_data( $data ) {
        $sanitized = array();

        if ( isset( $data['name'] ) ) {
            $sanitized['name'] = sanitize_text_field( $data['name'] );
        }
        if ( isset( $data['price'] ) ) {
            $sanitized['price'] = floatval( $data['price'] );
        }
        if ( isset( $data['description'] ) ) {
            $sanitized['description'] = wp_kses_post( $data['description'] );
        }
        if ( isset( $data['short_description'] ) ) {
            $sanitized['short_description'] = wp_kses_post( $data['short_description'] );
        }
        if ( isset( $data['stock_quantity'] ) ) {
            $sanitized['stock_quantity'] = intval( $data['stock_quantity'] );
        }
        if ( isset( $data['status'] ) ) {
            $sanitized['status'] = sanitize_text_field( $data['status'] );
        } else {
            $sanitized['status'] = get_option( 'woobot_default_status', 'draft' );
        }
        if ( isset( $data['sku'] ) ) {
            $sanitized['sku'] = sanitize_text_field( $data['sku'] );
        }
        if ( isset( $data['categories'] ) && is_array( $data['categories'] ) ) {
            $sanitized['categories'] = array_map( 'intval', $data['categories'] );
        }
        if ( isset( $data['tags'] ) && is_array( $data['tags'] ) ) {
            $sanitized['tags'] = array_map( 'sanitize_text_field', $data['tags'] );
        }
        if ( isset( $data['image_id'] ) ) {
            $sanitized['image_id'] = intval( $data['image_id'] );
        }
        if ( isset( $data['image_url'] ) ) {
            $sanitized['image_url'] = esc_url_raw( $data['image_url'] );
        }

        return $sanitized;
    }

    private function get_client_ip() {
        $ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
        foreach ( $ip_keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                if ( strpos( $ip, ',' ) !== false ) {
                    $ip = trim( explode( ',', $ip )[0] );
                }
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }
}
