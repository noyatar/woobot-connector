<?php
/**
 * WooBot Credits - Credit sync from WooBot server.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Credits {

    /**
     * Sync credits from WooBot server via validate-api-key endpoint.
     *
     * @return array|false Synced data or false on failure.
     */
    public static function sync() {
        $server_url = get_option( 'woobot_server_url', '' );
        $api_key    = get_option( 'woobot_api_key', '' );

        if ( empty( $server_url ) || empty( $api_key ) ) {
            return false;
        }

        $response = wp_remote_post(
            rtrim( $server_url, '/' ) . '/api/plugin/validate-api-key',
            array(
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'x-woobot-api-key' => $api_key,
                ),
                'body'    => wp_json_encode( array(
                    'siteUrl'    => get_site_url(),
                    'webhookUrl' => rest_url( 'woobot/v1/create-product' ),
                ) ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return false;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['valid'] ) ) {
            return false;
        }

        update_option( 'woobot_listing_credits', intval( $data['listingCredits'] ?? 0 ) );
        update_option( 'woobot_enhancement_credits', intval( $data['enhancementCredits'] ?? 0 ) );
        update_option( 'woobot_store_name', sanitize_text_field( $data['storeName'] ?? '' ) );
        update_option( 'woobot_subscription_tier', sanitize_text_field( $data['subscriptionTier'] ?? '' ) );
        update_option( 'woobot_credits_last_sync', current_time( 'mysql' ) );

        if ( ! empty( $data['storeKey'] ) ) {
            update_option( 'woobot_store_key', sanitize_text_field( $data['storeKey'] ) );
        }

        return $data;
    }

    /**
     * Update local credits from _credits field in webhook payload.
     */
    public static function update_from_webhook( $credits_data ) {
        if ( isset( $credits_data['listing'] ) ) {
            update_option( 'woobot_listing_credits', intval( $credits_data['listing'] ) );
        }
        if ( isset( $credits_data['enhancement'] ) ) {
            update_option( 'woobot_enhancement_credits', intval( $credits_data['enhancement'] ) );
        }
        update_option( 'woobot_credits_last_sync', current_time( 'mysql' ) );
    }

    /**
     * Get locally stored credit balance.
     */
    public static function get_balance() {
        return array(
            'listing'     => intval( get_option( 'woobot_listing_credits', 0 ) ),
            'enhancement' => intval( get_option( 'woobot_enhancement_credits', 0 ) ),
            'last_sync'   => get_option( 'woobot_credits_last_sync', '' ),
        );
    }

    /**
     * Report product creation result back to WooBot server.
     */
    public static function report_product_created( $result_data ) {
        $server_url = get_option( 'woobot_server_url', '' );
        $api_key    = get_option( 'woobot_api_key', '' );

        if ( empty( $server_url ) || empty( $api_key ) ) {
            return false;
        }

        $payload = array_merge(
            array( 'storeKey' => get_option( 'woobot_store_key', '' ) ),
            $result_data
        );

        $response = wp_remote_post(
            rtrim( $server_url, '/' ) . '/api/plugin/product-created',
            array(
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'x-woobot-api-key' => $api_key,
                ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 15,
            )
        );

        return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
    }

    /**
     * Register a new store on WooBot server.
     * @param string $key Builder key (LISTY-XX-NNN) or owner key (WOOBOT_XXXX...).
     */
    public static function register_store( $key ) {
        $server_url = get_option( 'woobot_server_url', '' );

        if ( empty( $server_url ) ) {
            return new WP_Error( 'woobot_no_server', __( 'Server URL is not configured.', 'woobot-whatsapp-uploader' ) );
        }

        $webhook_secret = wp_generate_password( 32, false, false );

        // Determine if key is builder format or owner format
        $body = array(
            'siteUrl'       => get_site_url(),
            'siteName'      => get_bloginfo( 'name' ),
            'webhookUrl'    => rest_url( 'woobot/v1/create-product' ),
            'webhookSecret' => $webhook_secret,
        );

        // Builder key: LISTY-XX-NNN or WOOBOT-XX-NNN
        if ( preg_match( '/^(LISTY|WOOBOT)-[A-Z]{1,2}-\d{3}$/', $key ) ) {
            $body['partnerId'] = $key;
        }
        // Owner key: WOOBOT_XXXXXXXXXXXXXXXX
        elseif ( preg_match( '/^WOOBOT_[A-Za-z0-9]{16}$/', $key ) ) {
            $body['storeKey'] = $key;
        }
        // Fallback — send as partnerId
        else {
            $body['partnerId'] = $key;
        }

        $response = wp_remote_post(
            rtrim( $server_url, '/' ) . '/api/plugin/register-store',
            array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $status_code || empty( $data['success'] ) ) {
            return new WP_Error( 'woobot_register_failed', $data['message'] ?? __( 'Registration failed.', 'woobot-whatsapp-uploader' ) );
        }

        if ( ! empty( $data['storeKey'] ) ) {
            update_option( 'woobot_store_key', sanitize_text_field( $data['storeKey'] ) );
        }
        if ( ! empty( $data['apiKey'] ) ) {
            update_option( 'woobot_api_key', sanitize_text_field( $data['apiKey'] ) );
        }
        if ( ! empty( $data['whatsappLink'] ) ) {
            update_option( 'woobot_whatsapp_link', esc_url_raw( $data['whatsappLink'] ) );
        }
        update_option( 'woobot_webhook_secret', $webhook_secret );

        self::sync();
        return $data;
    }

    /**
     * Get the purchase credits URL.
     */
    public static function get_purchase_url() {
        $server_url = get_option( 'woobot_server_url', '' );
        $store_key  = get_option( 'woobot_store_key', '' );
        $base = ! empty( $server_url ) ? rtrim( $server_url, '/' ) : 'https://listybot.replit.app';
        return $base . '/dashboard/store/buy-credits';
    }
}
