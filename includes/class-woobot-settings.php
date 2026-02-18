<?php
/**
 * WooBot Settings - Plugin settings management.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Settings {

    public static function get_all() {
        return array(
            'server_url'          => get_option( 'woobot_server_url', '' ),
            'api_key'             => get_option( 'woobot_api_key', '' ),
            'store_key'           => get_option( 'woobot_store_key', '' ),
            'partner_id'          => get_option( 'woobot_partner_id', '' ),
            'default_status'      => get_option( 'woobot_default_status', 'draft' ),
            'max_file_size'       => (int) get_option( 'woobot_max_file_size', 10485760 ),
            'rate_limit'          => (int) get_option( 'woobot_rate_limit', 30 ),
            'log_retention'       => (int) get_option( 'woobot_log_retention', 30 ),
            'activated_at'        => get_option( 'woobot_activated_at', '' ),
            'total_uploads'       => (int) get_option( 'woobot_total_uploads', 0 ),
            'total_enhanced'      => (int) get_option( 'woobot_total_enhanced', 0 ),
            'last_upload'         => get_option( 'woobot_last_upload', '' ),
            'listing_credits'     => (int) get_option( 'woobot_listing_credits', 0 ),
            'enhancement_credits' => (int) get_option( 'woobot_enhancement_credits', 0 ),
            'store_name'          => get_option( 'woobot_store_name', '' ),
            'subscription_tier'   => get_option( 'woobot_subscription_tier', '' ),
            'credits_last_sync'   => get_option( 'woobot_credits_last_sync', '' ),
            'whatsapp_link'       => get_option( 'woobot_whatsapp_link', '' ),
        );
    }

    public static function update( $settings ) {
        $allowed = array(
            'server_url'     => 'sanitize_url',
            'partner_id'     => 'sanitize_text_field',
            'default_status' => 'sanitize_text_field',
            'max_file_size'  => 'intval',
            'rate_limit'     => 'intval',
            'log_retention'  => 'intval',
        );

        foreach ( $settings as $key => $value ) {
            if ( isset( $allowed[ $key ] ) ) {
                $sanitized = call_user_func( $allowed[ $key ], $value );
                update_option( 'woobot_' . $key, $sanitized );
            }
        }

        return true;
    }

    public static function regenerate_api_key() {
        $new_key = wp_generate_password( 32, false, false );
        update_option( 'woobot_api_key', $new_key );
        update_option( 'woobot_api_key_hash', wp_hash( $new_key ) );
        return $new_key;
    }

    public static function get_whatsapp_link() {
        $stored = get_option( 'woobot_whatsapp_link', '' );
        if ( ! empty( $stored ) ) {
            return $stored;
        }
        $store_key = get_option( 'woobot_store_key', '' );
        return 'https://wa.me/972XXXXXXXXX?text=' . urlencode( $store_key );
    }

    public static function check_connection() {
        $status = array(
            'woocommerce'  => class_exists( 'WooCommerce' ),
            'api_key'      => ! empty( get_option( 'woobot_api_key' ) ),
            'store_key'    => ! empty( get_option( 'woobot_store_key' ) ),
            'server_url'   => ! empty( get_option( 'woobot_server_url' ) ),
        );

        // Try to sync credits to test server connection
        $sync_result = WooBot_Credits::sync();
        $status['server_connected'] = ( false !== $sync_result );

        $status['all_ok'] = $status['woocommerce'] && $status['api_key'] && $status['store_key'] && $status['server_connected'];

        return $status;
    }
}
