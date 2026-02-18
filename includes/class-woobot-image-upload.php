<?php
/**
 * WooBot Image Upload - Handles image download and media library registration.
 *
 * @package WooBot
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WooBot_Image_Upload {

    private $allowed_types = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    );

    public function upload_from_url( $image_url, $filename = '' ) {
        $max_size = (int) get_option( 'woobot_max_file_size', 10485760 );

        $response = wp_remote_get( $image_url, array(
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => array( 'Accept' => 'image/*' ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'woobot_download_failed',
                sprintf( __( 'Failed to download image: %s', 'woobot-whatsapp-uploader' ), $response->get_error_message() ),
                array( 'status' => 500 )
            );
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return new WP_Error( 'woobot_download_error',
                sprintf( __( 'Image download returned HTTP status %d.', 'woobot-whatsapp-uploader' ), $status_code ),
                array( 'status' => 500 )
            );
        }

        $image_data = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        if ( strpos( $content_type, ';' ) !== false ) {
            $content_type = trim( explode( ';', $content_type )[0] );
        }

        if ( ! isset( $this->allowed_types[ $content_type ] ) ) {
            $finfo = new finfo( FILEINFO_MIME_TYPE );
            $detected_type = $finfo->buffer( $image_data );
            if ( ! isset( $this->allowed_types[ $detected_type ] ) ) {
                return new WP_Error( 'woobot_invalid_type',
                    sprintf( __( 'Unsupported image type: %s. Allowed: JPEG, PNG, GIF, WebP.', 'woobot-whatsapp-uploader' ), $detected_type ),
                    array( 'status' => 400 )
                );
            }
            $content_type = $detected_type;
        }

        $size = strlen( $image_data );
        if ( $size > $max_size ) {
            return new WP_Error( 'woobot_file_too_large',
                sprintf( __( 'Image size exceeds maximum of %s.', 'woobot-whatsapp-uploader' ), size_format( $max_size ) ),
                array( 'status' => 400 )
            );
        }

        $extension = $this->allowed_types[ $content_type ];
        if ( empty( $filename ) ) {
            $filename = 'woobot-' . wp_generate_password( 12, false, false ) . '.' . $extension;
        } elseif ( ! pathinfo( $filename, PATHINFO_EXTENSION ) ) {
            $filename .= '.' . $extension;
        }

        $filename = sanitize_file_name( $filename );
        $upload = wp_upload_bits( $filename, null, $image_data );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'woobot_upload_failed',
                sprintf( __( 'Failed to save image: %s', 'woobot-whatsapp-uploader' ), $upload['error'] ),
                array( 'status' => 500 )
            );
        }

        $attachment = array(
            'post_mime_type' => $content_type,
            'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $upload['file'] );
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        return array(
            'id'       => $attachment_id,
            'url'      => $upload['url'],
            'file'     => $upload['file'],
            'type'     => $content_type,
            'size'     => $size,
            'filename' => $filename,
        );
    }

    public function upload_from_base64( $base64_data, $filename = '' ) {
        if ( strpos( $base64_data, 'base64,' ) !== false ) {
            $base64_data = explode( 'base64,', $base64_data )[1];
        }

        $image_data = base64_decode( $base64_data, true );
        if ( false === $image_data ) {
            return new WP_Error( 'woobot_invalid_base64', __( 'Invalid base64 encoded data.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        $max_size = (int) get_option( 'woobot_max_file_size', 10485760 );
        if ( strlen( $image_data ) > $max_size ) {
            return new WP_Error( 'woobot_file_too_large',
                sprintf( __( 'Image size exceeds maximum of %s.', 'woobot-whatsapp-uploader' ), size_format( $max_size ) ),
                array( 'status' => 400 )
            );
        }

        $finfo = new finfo( FILEINFO_MIME_TYPE );
        $content_type = $finfo->buffer( $image_data );

        if ( ! isset( $this->allowed_types[ $content_type ] ) ) {
            return new WP_Error( 'woobot_invalid_type', __( 'Unsupported image type.', 'woobot-whatsapp-uploader' ), array( 'status' => 400 ) );
        }

        $extension = $this->allowed_types[ $content_type ];
        if ( empty( $filename ) ) {
            $filename = 'woobot-' . wp_generate_password( 12, false, false ) . '.' . $extension;
        }

        $filename = sanitize_file_name( $filename );
        $upload = wp_upload_bits( $filename, null, $image_data );

        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'woobot_upload_failed', $upload['error'], array( 'status' => 500 ) );
        }

        $attachment = array(
            'post_mime_type' => $content_type,
            'post_title'     => pathinfo( $filename, PATHINFO_FILENAME ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );

        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );
        if ( is_wp_error( $attachment_id ) ) {
            wp_delete_file( $upload['file'] );
            return $attachment_id;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $metadata );

        return array(
            'id'       => $attachment_id,
            'url'      => $upload['url'],
            'file'     => $upload['file'],
            'type'     => $content_type,
            'size'     => strlen( $image_data ),
            'filename' => $filename,
        );
    }
}
