<?php
/**
 * Plugin Name: Encrypted Links
 * Description: Шифрует ссылки: поле для ввода реальной ссылки (№1) и автогенерация короткой ссылки (№2), которая редиректит на №1. Ссылки работают бессрочно. Совместимо с функцией tfc_go_link().
 * Version: 1.1.0
 * Author: 7on
 * Text Domain: tfc-go-links
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// === tfc_go_link(): короткая ссылка на основе md5-хэша ===
if ( ! function_exists( 'tfc_go_link' ) ) {
    function tfc_go_link( $real_url ) {
        $real_url = esc_url_raw( (string) $real_url );
        if ( stripos( $real_url, '://' ) === false ) {
            $real_url = 'https://' . ltrim( $real_url );
        }
        $hash = substr( md5( $real_url ), 0, 10 );

        // Сохраняем соответствие в опции (бессрочно)
        $map = get_option( 'tfc_go_links_map', [] );
        if ( ! is_array( $map ) ) $map = [];
        $map[$hash] = $real_url;
        update_option( 'tfc_go_links_map', $map );

        return home_url( '/go/' . $hash . '/' );
    }
}

// === Рерайты ===
function tfc_go_register_rewrites() {
    add_rewrite_rule( '^go/([a-zA-Z0-9]+)/?$', 'index.php?tfc_go=$matches[1]', 'top' );
    add_rewrite_tag( '%tfc_go%', '([^&]+)' );
}
add_action( 'init', 'tfc_go_register_rewrites' );

function tfc_go_activate() {
    tfc_go_register_rewrites();
    flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'tfc_go_activate' );

function tfc_go_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'tfc_go_deactivate' );

// === Обработка редиректа ===
function tfc_go_handle_redirect() {
    $hash = get_query_var( 'tfc_go' );
    if ( ! $hash ) return;

    header( 'X-Robots-Tag: noindex, nofollow, noarchive' );

    $map = get_option( 'tfc_go_links_map', [] );
    if ( is_array( $map ) && isset( $map[$hash] ) ) {
        wp_redirect( $map[$hash], 302, 'GO Redirect' );
        exit;
    }

    status_header( 404 );
    exit;
}
add_action( 'template_redirect', 'tfc_go_handle_redirect' );

// === Метабокс ===
function tfc_go_add_metabox() {
    $post_types = get_post_types( [ 'public' => true ], 'names' );
    foreach ( $post_types as $pt ) {
        add_meta_box(
            'tfc_go_metabox',
            __( 'TFC Short Links', 'tfc-go-links' ),
            'tfc_go_metabox_cb',
            $pt,
            'normal',
            'high'
        );
    }
}
add_action( 'add_meta_boxes', 'tfc_go_add_metabox' );

function tfc_go_metabox_cb( $post ) {
    wp_nonce_field( 'tfc_go_save_metabox', 'tfc_go_nonce' );

    // 1) читаем массив ссылок нового формата
    $real_urls = get_post_meta( $post->ID, '_tfc_go_real_urls', true );
    if ( ! is_array( $real_urls ) ) $real_urls = [];

    // 2) back-compat: если массива нет, забираем старое одиночное поле
    if ( empty( $real_urls ) ) {
        $legacy = get_post_meta( $post->ID, '_tfc_go_real_url', true );
        if ( $legacy ) {
            $real_urls = [ $legacy ];
        }
    }

    echo '<div id="tfc-go-links" data-home-url="' . esc_attr( home_url( '/' ) ) . '">';
    if ( empty( $real_urls ) ) $real_urls[] = '';

    foreach ( $real_urls as $idx => $real ) {
        $short = $real ? tfc_go_link( $real ) : '';
        echo tfc_go_render_row( $idx, $real, $short, false );
    }
    echo '</div>';

    echo '<p><button type="button" class="button" id="tfc-go-add-row">' . esc_html__( 'Добавить ссылку', 'tfc-go-links' ) . '</button></p>';
    echo '<script type="text/html" id="tfc-go-row-template">' . tfc_go_render_row( '__INDEX__', '', '', false ) . '</script>';
}

function tfc_go_render_row( $index, $real = '', $short = '', $echo = true ) {
    ob_start(); ?>
    <div class="tfc-go-row" style="border:1px solid #e2e8f0;padding:12px;margin-bottom:10px;border-radius:6px;">
        <p><label for="tfc_go_real_url_<?php echo esc_attr( $index ); ?>"><strong><?php _e( 'Ссылка №1 (реальная цель редиректа)', 'tfc-go-links' ); ?></strong></label></p>
        <input type="url" class="widefat tfc-go-real" id="tfc_go_real_url_<?php echo esc_attr( $index ); ?>" name="tfc_go_real_urls[]" value="<?php echo esc_attr( $real ); ?>" placeholder="https://example.com/..." />
        <p style="margin-top:12px"><label><strong><?php _e( 'Ссылка №2 (короткая, для вставки)', 'tfc-go-links' ); ?></strong></label></p>
        <input type="url" class="widefat tfc-go-short" readonly value="<?php echo esc_attr( $short ); ?>" />
        <p style="margin-top:8px"><button type="button" class="button tfc-go-remove"><?php _e( 'Удалить', 'tfc-go-links' ); ?></button></p>
    </div>
    <?php
    $html = ob_get_clean();
    if ( $echo ) echo $html;
    return $html;
}

// === Сохранение ===
function tfc_go_save_metabox( $post_id ) {
    if ( ! isset( $_POST['tfc_go_nonce'] ) || ! wp_verify_nonce( $_POST['tfc_go_nonce'], 'tfc_go_save_metabox' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( isset( $_POST['tfc_go_real_urls'] ) && is_array( $_POST['tfc_go_real_urls'] ) ) {
        $clean = [];
        foreach ( $_POST['tfc_go_real_urls'] as $real ) {
            $real = trim( $real );
            if ( $real === '' ) continue;
            if ( stripos( $real, '://' ) === false ) $real = 'https://' . $real;
            $real = esc_url_raw( $real );
            if ( $real ) {
                $clean[] = $real;
                $hash = substr( md5( $real ), 0, 10 );
                $map = get_option( 'tfc_go_links_map', [] );
                if ( ! is_array( $map ) ) $map = [];
                $map[$hash] = $real;
                update_option( 'tfc_go_links_map', $map );
            }
        }
        if ( $clean ) {
            update_post_meta( $post_id, '_tfc_go_real_urls', $clean );
            update_post_meta( $post_id, '_tfc_go_real_url', $clean[0] );
        } else {
            delete_post_meta( $post_id, '_tfc_go_real_urls' );
            delete_post_meta( $post_id, '_tfc_go_real_url' );
        }
    }
}
add_action( 'save_post', 'tfc_go_save_metabox' );

// === Конец файла ===
