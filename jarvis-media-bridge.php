<?php
/**
 * Plugin Name: Jarvis Media Bridge
 * Description: Temporary authenticated JSON/base64 upload bridge for Jarvis-generated images.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'rest_api_init', function () {
    register_rest_route( 'jarvis-media-bridge/v1', '/upload', array(
        'methods'             => 'POST',
        'callback'            => 'jarvis_media_bridge_upload',
        'permission_callback' => function () {
            return current_user_can( 'upload_files' );
        },
    ) );
} );

function jarvis_media_bridge_upload( WP_REST_Request $request ) {
    $params = $request->get_json_params();
    if ( ! is_array( $params ) ) {
        return new WP_Error( 'jmb_invalid_json', 'JSON inválido.', array( 'status' => 400 ) );
    }

    $filename = sanitize_file_name( isset( $params['filename'] ) ? (string) $params['filename'] : '' );
    $mime     = sanitize_text_field( isset( $params['mime_type'] ) ? (string) $params['mime_type'] : '' );
    $encoded  = isset( $params['data_base64'] ) ? (string) $params['data_base64'] : '';

    $allowed = array(
        'image/jpeg' => array( 'jpg', 'jpeg' ),
        'image/png'  => array( 'png' ),
        'image/webp' => array( 'webp' ),
        'image/gif'  => array( 'gif' ),
    );

    if ( '' === $filename || ! isset( $allowed[ $mime ] ) || '' === $encoded ) {
        return new WP_Error( 'jmb_missing_fields', 'Informe filename, mime_type e data_base64 válidos.', array( 'status' => 400 ) );
    }

    $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
    if ( ! in_array( $ext, $allowed[ $mime ], true ) ) {
        return new WP_Error( 'jmb_bad_extension', 'A extensão não corresponde ao MIME informado.', array( 'status' => 415 ) );
    }

    if ( strlen( $encoded ) > 8 * 1024 * 1024 ) {
        return new WP_Error( 'jmb_payload_too_large', 'Payload base64 acima do limite.', array( 'status' => 413 ) );
    }

    $binary = base64_decode( $encoded, true );
    if ( false === $binary || strlen( $binary ) < 32 || strlen( $binary ) > 6 * 1024 * 1024 ) {
        return new WP_Error( 'jmb_invalid_base64', 'Arquivo base64 inválido ou fora do limite.', array( 'status' => 400 ) );
    }

    $image_info = @getimagesizefromstring( $binary );
    if ( ! is_array( $image_info ) || empty( $image_info['mime'] ) || $image_info['mime'] !== $mime ) {
        return new WP_Error( 'jmb_not_image', 'Os bytes enviados não correspondem a uma imagem válida do MIME informado.', array( 'status' => 415 ) );
    }

    $parent_id = isset( $params['parent_id'] ) ? absint( $params['parent_id'] ) : 0;
    if ( $parent_id ) {
        $parent = get_post( $parent_id );
        if ( ! $parent || 'page' !== $parent->post_type || 'draft' !== $parent->post_status ) {
            return new WP_Error( 'jmb_invalid_parent', 'parent_id deve apontar para uma página em rascunho.', array( 'status' => 409 ) );
        }
    }

    $upload = wp_upload_bits( $filename, null, $binary );
    if ( ! empty( $upload['error'] ) ) {
        return new WP_Error( 'jmb_upload_error', $upload['error'], array( 'status' => 500 ) );
    }

    require_once ABSPATH . 'wp-admin/includes/image.php';

    $title = sanitize_text_field( isset( $params['title'] ) ? (string) $params['title'] : '' );
    if ( '' === $title ) {
        $title = pathinfo( $filename, PATHINFO_FILENAME );
    }

    $attachment_id = wp_insert_attachment( array(
        'post_mime_type' => $mime,
        'post_title'     => $title,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ), $upload['file'], $parent_id, true );

    if ( is_wp_error( $attachment_id ) ) {
        @unlink( $upload['file'] );
        return $attachment_id;
    }

    $metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
    if ( is_array( $metadata ) ) {
        wp_update_attachment_metadata( $attachment_id, $metadata );
    }

    $alt = sanitize_text_field( isset( $params['alt_text'] ) ? (string) $params['alt_text'] : '' );
    if ( '' !== $alt ) {
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
    }

    update_post_meta( $attachment_id, '_jarvis_media_bridge_uploaded', '1' );

    return new WP_REST_Response( array(
        'ok' => true,
        'media' => array(
            'id' => $attachment_id,
            'url' => wp_get_attachment_url( $attachment_id ),
            'mime_type' => get_post_mime_type( $attachment_id ),
            'width' => isset( $metadata['width'] ) ? (int) $metadata['width'] : (int) $image_info[0],
            'height' => isset( $metadata['height'] ) ? (int) $metadata['height'] : (int) $image_info[1],
        ),
    ), 201 );
}
