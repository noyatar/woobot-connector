<?php
/**
 * WooBot Product Creator - Creates WooCommerce products.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Product_Creator {

    public function create_product( $data ) {
        if ( ! class_exists( 'WC_Product_Simple' ) ) {
            return new WP_Error( 'woobot_wc_missing', __( 'WooCommerce is not available.', 'woobot-whatsapp-uploader' ), array( 'status' => 500 ) );
        }

        try {
            $product = new WC_Product_Simple();
            $product->set_name( $data['name'] );

            if ( isset( $data['price'] ) ) {
                $product->set_regular_price( (string) $data['price'] );
            }
            if ( ! empty( $data['description'] ) ) {
                $product->set_description( $data['description'] );
            }
            if ( ! empty( $data['short_description'] ) ) {
                $product->set_short_description( $data['short_description'] );
            }
            if ( isset( $data['stock_quantity'] ) ) {
                $product->set_manage_stock( true );
                $product->set_stock_quantity( $data['stock_quantity'] );
                $product->set_stock_status( $data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
            } else {
                $product->set_stock_status( 'instock' );
            }

            $product->set_status( $data['status'] ?? get_option( 'woobot_default_status', 'draft' ) );

            if ( ! empty( $data['sku'] ) ) {
                $product->set_sku( $data['sku'] );
            }
            if ( ! empty( $data['image_id'] ) ) {
                $product->set_image_id( $data['image_id'] );
            }
            if ( ! empty( $data['categories'] ) ) {
                $product->set_category_ids( $data['categories'] );
            }
            if ( ! empty( $data['tags'] ) ) {
                $tag_ids = array();
                foreach ( $data['tags'] as $tag_name ) {
                    $term = get_term_by( 'name', $tag_name, 'product_tag' );
                    if ( $term ) {
                        $tag_ids[] = $term->term_id;
                    } else {
                        $new_term = wp_insert_term( $tag_name, 'product_tag' );
                        if ( ! is_wp_error( $new_term ) ) {
                            $tag_ids[] = $new_term['term_id'];
                        }
                    }
                }
                if ( ! empty( $tag_ids ) ) {
                    $product->set_tag_ids( $tag_ids );
                }
            }

            $product->add_meta_data( '_woobot_created', 'yes', true );
            $product->add_meta_data( '_woobot_created_at', current_time( 'mysql' ), true );

            $product_id = $product->save();

            if ( ! $product_id ) {
                return new WP_Error( 'woobot_create_failed', __( 'Failed to create product.', 'woobot-whatsapp-uploader' ), array( 'status' => 500 ) );
            }

            $total = (int) get_option( 'woobot_total_uploads', 0 );
            update_option( 'woobot_total_uploads', $total + 1 );
            update_option( 'woobot_last_upload', current_time( 'mysql' ) );

            return array(
                'product_id'   => $product_id,
                'name'         => $product->get_name(),
                'price'        => $product->get_regular_price(),
                'status'       => $product->get_status(),
                'permalink'    => get_permalink( $product_id ),
                'edit_url'     => admin_url( 'post.php?post=' . $product_id . '&action=edit' ),
                'image_id'     => $product->get_image_id(),
                'sku'          => $product->get_sku(),
            );

        } catch ( Exception $e ) {
            return new WP_Error( 'woobot_exception',
                sprintf( __( 'Error creating product: %s', 'woobot-whatsapp-uploader' ), $e->getMessage() ),
                array( 'status' => 500 )
            );
        }
    }
}
