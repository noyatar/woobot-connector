<?php
/**
 * WooBot Uninstall - Clean up all plugin data.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'woobot_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_woobot_%'" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_woobot_%'" );

$table_name = $wpdb->prefix . 'woobot_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'woobot_%'" );

wp_clear_scheduled_hook( 'woobot_daily_log_cleanup' );
wp_clear_scheduled_hook( 'woobot_daily_credit_check' );
