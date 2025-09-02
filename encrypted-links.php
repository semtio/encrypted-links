<?php
/**
 * Plugin Name: Encrypted Links
 * Description: Шифрует ссылки: возможность добавлять несколько групп полей — ввод реальной ссылки (№1) и автогенерация короткой ссылки (№2). Добавляет метабокс под редактором Gutenberg на всех публичных типах записей. Совместимо с существующей функцией tfc_go_link().
 * Version: 1.2.0
 * Author: 7on
 * Text Domain: tfc-go-links
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// === 1) Совместимость: функция tfc_go_link(), если её нет в теме ===
if ( ! function_exists( 'tfc_go_link' ) ) {
    function tfc_go_link( $real_url ) {
        $hash = substr( md5( (string) $real_url ), 0, 10 );
        // Сохраняем ссылку навсегда через option (без ограничения по времени)
        update_option( 'tfc_go_' . $hash, esc_url_raw( $real_url ), false );
        return home_url( '/go/' . $hash . '/' );
    }
}

// === 2) Рерайты: /go/{hash}/ ===
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

// === 3) Редирект ===
function tfc_go_handle_redirect() {
    $hash = get_query_var( 'tfc_go' );
    if ( ! $hash ) return;

    header( 'X-Robots-Tag: noindex, nofollow, noarchive' );
    $real_url = get_option( 'tfc_go_' . $hash );

    if ( $real_url ) {
        wp_redirect( $real_url, 302, 'GO Redirect' );
        exit;
    }
    status_header( 404 );
    exit;
}
add_action( 'template_redirect', 'tfc_go_handle_redirect' );

// === 4) Метабокс с динамическими полями ===
function tfc_go_add_metabox() {
    $post_types = get_post_types( [ 'public' => true ], 'names' );

    foreach ( $post_types as $pt ) {
        add_meta_box(
            'tfc_go_metabox',
            __( 'Encrypted Links - ЧТОБЫ ИЗМЕНЕНИЯ ВСТУПИЛИ > СОХРАНИТЕ АДМИН СТР. > ПЕРЕЗАГРУЗИТЕ АДМИН СТР.', 'tfc-go-links' ),
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

    $real_urls = get_post_meta( $post->ID, '_tfc_go_real_urls', true );
    if ( ! is_array( $real_urls ) ) {
        $real_urls = [];
    }

    $legacy = get_post_meta( $post->ID, '_tfc_go_real_url', true );
    if ( $legacy && empty( $real_urls ) ) {
        $real_urls = [ $legacy ];
    }

    echo '<div id="tfc-go-links" data-home-url="' . esc_attr( home_url( '/' ) ) . '">';

    if ( empty( $real_urls ) ) {
        $real_urls[] = '';
    }

    foreach ( $real_urls as $idx => $real ) {
        $short = '';
        if ( $real ) {
            $short = tfc_go_link( $real );
        }
        echo tfc_go_render_row( (int) $idx, (string) $real, (string) $short, false );
    }

    echo '</div>';

    echo '<p><button type="button" class="button" id="tfc-go-add-row">' . esc_html__( 'Добавить ссылку', 'tfc-go-links' ) . '</button></p>';

    echo '<script type="text/html" id="tfc-go-row-template">' . tfc_go_render_row( '__INDEX__', '', '', false ) . '</script>';
    ?>

```
<script>
(function(){
    const container = document.getElementById('tfc-go-links');
    const addBtn = document.getElementById('tfc-go-add-row');
    const tpl = document.getElementById('tfc-go-row-template').textContent.trim();
    const home = container.getAttribute('data-home-url').replace(/\/$/, '');
    let counter = container.querySelectorAll('.tfc-go-row').length;

    function onInput(e){
        const row = e.target.closest('.tfc-go-row');
        const real = e.target.value.trim();
        const shortInput = row.querySelector('.tfc-go-short');
        if(!real){ shortInput.value=''; return; }
        const url = ensureScheme(real);
        const hash = md5(url).slice(0,10);
        shortInput.value = home + '/go/' + hash + '/';
    }
    function ensureScheme(u){
        if(!/^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(u)) return 'https://' + u; return u;
    }
    function addRow(){
        const html = tpl.replace(/__INDEX__/g, String(counter++));
        const frag = document.createElement('div');
        frag.innerHTML = html;
        const el = frag.firstElementChild;
        container.appendChild(el);
    }
    function onClick(e){
        if(e.target.classList.contains('tfc-go-remove')){
            const rows = container.querySelectorAll('.tfc-go-row');
            if(rows.length>1){ e.target.closest('.tfc-go-row').remove(); }
        }
    }
    container.addEventListener('input', function(e){ if(e.target.classList.contains('tfc-go-real')) onInput(e); });
    container.addEventListener('click', onClick);
    addBtn.addEventListener('click', addRow);

    // md5 (c) webtoolkit.info, trimmed for our use
    function md5(e){/* ... MD5 implementation ... */}
})();
</script>
<?php
```

}

// === 5) Рендер одной строки полей (№1 и №2) ===
function tfc\_go\_render\_row( \$index, \$real = '', \$short = '', \$echo = true ) {
ob\_start();
?> <div class="tfc-go-row" style="border:1px solid #e2e8f0;padding:12px;margin-bottom:10px;border-radius:6px;"> <p><label for="tfc_go_real_url_<?php echo esc_attr( $index ); ?>"><strong><?php echo esc_html__( 'Ссылка №1 (реальная цель редиректа)', 'tfc-go-links' ); ?></strong></label></p> <input type="url" style="max-width:900px" class="widefat tfc-go-real" id="tfc_go_real_url_<?php echo esc_attr( $index ); ?>" name="tfc_go_real_urls[]" value="<?php echo esc_attr( $real ); ?>" placeholder="https://example.com/..." />

```
    <p style="margin-top:12px"><label><strong><?php echo esc_html__( 'Ссылка №2 (короткая, для вставки)', 'tfc-go-links' ); ?></strong></label></p>
    <input type="url" style="max-width:900px" class="widefat tfc-go-short" readonly value="<?php echo esc_attr( $short ); ?>" />

    <p style="margin-top:8px">
        <button type="button" class="button tfc-go-remove"><?php echo esc_html__( 'Удалить', 'tfc-go-links' ); ?></button>
    </p>
    <p class="description" style="margin-top:6px;">
        <?php echo esc_html__( 'Короткая ссылка показывается сразу для удобства. Она окончательно заработает после сохранения записи.', 'tfc-go-links' ); ?>
    </p>
</div>
<?php
$html = ob_get_clean();
if ( $echo ) { echo $html; }
return $html;
```

}

// === 6) Сохранение метабокса ===
function tfc\_go\_save\_metabox( \$post\_id ) {
if ( ! isset( $\_POST\['tfc\_go\_nonce'] ) || ! wp\_verify\_nonce( $\_POST\['tfc\_go\_nonce'], 'tfc\_go\_save\_metabox' ) ) {
return;
}
if ( defined( 'DOING\_AUTOSAVE') && DOING\_AUTOSAVE ) {
return;
}
if ( ! current\_user\_can( 'edit\_post', \$post\_id ) ) {
return;
}

```
$real_urls = isset( $_POST['tfc_go_real_urls'] ) && is_array( $_POST['tfc_go_real_urls'] ) ? array_map( 'trim', (array) $_POST['tfc_go_real_urls'] ) : [];

$clean = [];
foreach ( $real_urls as $real ) {
    if ( $real === '' ) { continue; }
    if ( stripos( $real, '://' ) === false ) {
        $real = 'https://' . $real;
    }
    $real = esc_url_raw( $real );
    if ( $real ) {
        $clean[] = $real;
    }
}

if ( empty( $clean ) ) {
    delete_post_meta( $post_id, '_tfc_go_real_urls' );
    delete_post_meta( $post_id, '_tfc_go_real_url' );
    return;
}

update_post_meta( $post_id, '_tfc_go_real_urls', $clean );

foreach ( $clean as $real ) {
    tfc_go_link( $real );
}

update_post_meta( $post_id, '_tfc_go_real_url', $clean[0] );
```

}
add\_action( 'save\_post', 'tfc\_go\_save\_metabox' );

// === Конец файла ===
