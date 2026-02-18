<?php
/**
 * WooBot Logger - Handles logging of API activity.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Logger {

    private $table_name;

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'woobot_logs';
    }

    public function log( $data ) {
        global $wpdb;

        $defaults = array(
            'action_type'   => '',
            'status'        => 'success',
            'request_data'  => '',
            'response_data' => '',
            'response_time' => 0,
            'error_message' => '',
            'product_id'    => null,
            'image_id'      => null,
            'ip_address'    => $this->get_client_ip(),
            'created_at'    => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        if ( ! empty( $data['request_data'] ) && is_array( $data['request_data'] ) ) {
            if ( isset( $data['request_data']['secret_key'] ) ) {
                $data['request_data']['secret_key'] = '***REDACTED***';
            }
            $data['request_data'] = wp_json_encode( $data['request_data'] );
        }

        if ( ! empty( $data['response_data'] ) && is_array( $data['response_data'] ) ) {
            $data['response_data'] = wp_json_encode( $data['response_data'] );
        }

        $result = $wpdb->insert(
            $this->table_name,
            $data,
            array( '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%d', '%s', '%s' )
        );

        return $result ? $wpdb->insert_id : false;
    }

    public function get_logs( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'per_page'    => 20,
            'page'        => 1,
            'action_type' => '',
            'status'      => '',
            'date_from'   => '',
            'date_to'     => '',
            'orderby'     => 'created_at',
            'order'       => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['action_type'] ) ) {
            $where[] = 'action_type = %s';
            $values[] = $args['action_type'];
        }

        if ( ! empty( $args['status'] ) ) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode( ' AND ', $where );

        $allowed_orderby = array( 'created_at', 'action_type', 'status', 'response_time' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $count_sql = "SELECT COUNT(*) FROM {$this->table_name} WHERE {$where_sql}";
        if ( ! empty( $values ) ) {
            $count_sql = $wpdb->prepare( $count_sql, $values );
        }
        $total = (int) $wpdb->get_var( $count_sql );

        $offset = ( (int) $args['page'] - 1 ) * (int) $args['per_page'];
        $query = "SELECT * FROM {$this->table_name} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = (int) $args['per_page'];
        $values[] = $offset;

        $logs = $wpdb->get_results( $wpdb->prepare( $query, $values ) );

        return array(
            'logs'  => $logs,
            'total' => $total,
        );
    }

    public function clear_logs() {
        global $wpdb;
        return $wpdb->query( "TRUNCATE TABLE {$this->table_name}" );
    }

    public function export_csv( $args = array() ) {
        $args['per_page'] = 10000;
        $args['page'] = 1;
        $result = $this->get_logs( $args );

        $csv = "ID,Action Type,Status,Response Time (s),Error Message,Product ID,Image ID,IP Address,Created At\n";

        foreach ( $result['logs'] as $log ) {
            $csv .= sprintf(
                "%d,%s,%s,%.3f,%s,%s,%s,%s,%s\n",
                $log->id,
                $this->csv_escape( $log->action_type ),
                $this->csv_escape( $log->status ),
                $log->response_time,
                $this->csv_escape( $log->error_message ),
                $log->product_id ?: '',
                $log->image_id ?: '',
                $log->ip_address,
                $log->created_at
            );
        }

        return $csv;
    }

    private function csv_escape( $value ) {
        return '"' . str_replace( '"', '""', $value ) . '"';
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
