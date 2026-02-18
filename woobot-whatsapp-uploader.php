<?php
/**
 * Plugin Name: WooBot - WhatsApp Product Uploader
 * Plugin URI: https://woobot.co.il
 * Description: Upload products to WooCommerce via WhatsApp. AI-powered image analysis extracts product details automatically.
 * Version: 1.1.0
 * Author: WooBot
 * Author URI: https://woobot.co.il
 * Text Domain: woobot-whatsapp-uploader
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * License: GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WOOBOT_VERSION', '1.1.0' );
define( 'WOOBOT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOOBOT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOOBOT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'WOOBOT_MIN_WP_VERSION', '5.8' );
define( 'WOOBOT_MIN_PHP_VERSION', '7.4' );
define( 'WOOBOT_TEXT_DOMAIN', 'woobot-whatsapp-uploader' );

function woobot_check_requirements() {
    $errors = array();
    if ( version_compare( PHP_VERSION, WOOBOT_MIN_PHP_VERSION, '<' ) ) {
        $errors[] = sprintf( __( 'WooBot requires PHP %s or higher.', 'woobot-whatsapp-uploader' ), WOOBOT_MIN_PHP_VERSION );
    }
    if ( version_compare( get_bloginfo( 'version' ), WOOBOT_MIN_WP_VERSION, '<' ) ) {
        $errors[] = sprintf( __( 'WooBot requires WordPress %s or higher.', 'woobot-whatsapp-uploader' ), WOOBOT_MIN_WP_VERSION );
    }
    if ( ! class_exists( 'WooCommerce' ) ) {
        $errors[] = __( 'WooBot requires WooCommerce to be installed and active.', 'woobot-whatsapp-uploader' );
    }
    return $errors;
}

function woobot_requirements_notice() {
    $errors = woobot_check_requirements();
    if ( ! empty( $errors ) ) {
        echo '<div class="notice notice-error"><p><strong>WooBot:</strong></p><ul>';
        foreach ( $errors as $error ) { echo '<li>' . esc_html( $error ) . '</li>'; }
        echo '</ul></div>';
    }
}

/**
 * Plugin activation.
 */
function woobot_activate() {
    $errors = woobot_check_requirements();
    if ( ! empty( $errors ) ) {
        deactivate_plugins( WOOBOT_PLUGIN_BASENAME );
        wp_die( esc_html( implode( '<br>', $errors ) ), 'Plugin Activation Error', array( 'back_link' => true ) );
    }

    global $wpdb;

    $table_name = $wpdb->prefix . 'woobot_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        action_type varchar(50) NOT NULL DEFAULT '',
        status varchar(20) NOT NULL DEFAULT '',
        request_data longtext DEFAULT NULL,
        response_data longtext DEFAULT NULL,
        response_time float DEFAULT 0,
        error_message text DEFAULT NULL,
        product_id bigint(20) unsigned DEFAULT NULL,
        image_id bigint(20) unsigned DEFAULT NULL,
        ip_address varchar(45) DEFAULT NULL,
        created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY action_type (action_type),
        KEY status (status),
        KEY created_at (created_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );

    // Generate store key if not exists
    if ( ! get_option( 'woobot_store_key' ) ) {
        update_option( 'woobot_store_key', 'WOOBOT_' . wp_generate_password( 16, false, false ) );
    }

    // Set default options
    $defaults = array(
        'woobot_default_status'      => 'draft',
        'woobot_max_file_size'       => 10485760,
        'woobot_rate_limit'          => 30,
        'woobot_log_retention'       => 30,
        'woobot_partner_id'          => '',
        'woobot_server_url'          => 'https://listybot.replit.app',
        'woobot_activated_at'        => current_time( 'mysql' ),
        'woobot_total_uploads'       => 0,
        'woobot_total_enhanced'      => 0,
        'woobot_last_upload'         => '',
        'woobot_listing_credits'     => 0,
        'woobot_enhancement_credits' => 0,
    );

    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) {
            update_option( $key, $value );
        }
    }

    flush_rewrite_rules();

    // Sync credits on activation (deferred to init because WP might not be fully loaded)
    wp_schedule_single_event( time() + 5, 'woobot_activation_sync' );
}
register_activation_hook( __FILE__, 'woobot_activate' );

function woobot_deactivate() {
    wp_clear_scheduled_hook( 'woobot_daily_log_cleanup' );
    wp_clear_scheduled_hook( 'woobot_hourly_credit_sync' );
    wp_clear_scheduled_hook( 'woobot_activation_sync' );

    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woobot_rate_%' OR option_name LIKE '_transient_timeout_woobot_rate_%'"
    );
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'woobot_deactivate' );

/**
 * Initialize the plugin.
 */
function woobot_init() {
    $errors = woobot_check_requirements();
    if ( ! empty( $errors ) ) {
        add_action( 'admin_notices', 'woobot_requirements_notice' );
        return;
    }

    // Load classes
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-logger.php';
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-security.php';
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-image-upload.php';
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-product-creator.php';
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-credits.php';
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-settings.php';
    require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-rest-api.php';

    if ( is_admin() ) {
        require_once WOOBOT_PLUGIN_DIR . 'includes/class-woobot-admin.php';
        new WooBot_Admin();
    }

    add_action( 'rest_api_init', array( new WooBot_REST_API(), 'register_routes' ) );

    // Schedule cron: log cleanup daily
    if ( ! wp_next_scheduled( 'woobot_daily_log_cleanup' ) ) {
        wp_schedule_event( time(), 'daily', 'woobot_daily_log_cleanup' );
    }
    add_action( 'woobot_daily_log_cleanup', 'woobot_cleanup_old_logs' );

    // Schedule cron: credit sync hourly
    if ( ! wp_next_scheduled( 'woobot_hourly_credit_sync' ) ) {
        wp_schedule_event( time(), 'hourly', 'woobot_hourly_credit_sync' );
    }
    add_action( 'woobot_hourly_credit_sync', array( 'WooBot_Credits', 'sync' ) );

    // Activation sync handler
    add_action( 'woobot_activation_sync', array( 'WooBot_Credits', 'sync' ) );
}
add_action( 'plugins_loaded', 'woobot_init' );

/**
 * Load textdomain on init (WP 6.7+ requires this).
 */
function woobot_load_textdomain() {
    load_plugin_textdomain( 'woobot-whatsapp-uploader', false, dirname( WOOBOT_PLUGIN_BASENAME ) . '/languages' );
}
add_action( 'init', 'woobot_load_textdomain' );

function woobot_cleanup_old_logs() {
    global $wpdb;
    $retention = (int) get_option( 'woobot_log_retention', 30 );
    $table_name = $wpdb->prefix . 'woobot_logs';
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$table_name} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $retention
    ) );
}

function woobot_plugin_action_links( $links ) {
    $settings_link = sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=woobot-dashboard' ), __( 'Settings', 'woobot-whatsapp-uploader' ) );
    array_unshift( $links, $settings_link );
    return $links;
}
add_filter( 'plugin_action_links_' . WOOBOT_PLUGIN_BASENAME, 'woobot_plugin_action_links' );

/**
 * Admin bar quick-access button.
 */
function woobot_admin_bar_button( $wp_admin_bar ) {
    if ( ! current_user_can( 'manage_woocommerce' ) ) { return; }
    $wp_admin_bar->add_node( array(
        'id'    => 'woobot-dashboard',
        'title' => '🤖 WooBot',
        'href'  => admin_url( 'admin.php?page=woobot-dashboard' ),
        'meta'  => array( 'title' => __( 'Go to WooBot Dashboard', 'woobot-whatsapp-uploader' ) ),
    ) );
}
add_action( 'admin_bar_menu', 'woobot_admin_bar_button', 100 );
