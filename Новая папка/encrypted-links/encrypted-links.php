<?php
/**
 * Plugin Name: Encrypted Links
 * Description: Шифрует ссылки: возможность добавлять несколько групп полей — ввод реальной ссылки (№1) и автогенерация короткой ссылки (№2). Добавляет метабокс под редактором Gutenberg на всех публичных типах записей. Совместимо с существующей функцией tfc_go_link().
 * Version: 1.1.0
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
        set_transient( 'tfc_go_' . $hash, esc_url_raw( $real_url ), DAY_IN_SECONDS * 30 );
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
    $real_url = get_transient( 'tfc_go_' . $hash );

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
    // Все публичные типы записей (post, page, и публичные кастомные)
    $post_types = get_post_types( [ 'public' => true ], 'names' );

    foreach ( $post_types as $pt ) {
        add_meta_box(
            'tfc_go_metabox',
            __( 'Encrypted Links - ЧТОБЫ ИЗМЕНЕНИЯ ВСТУПИЛИ > СОХРАНИТЕ АДМИН СТР. > ПЕРЕЗАГРУЗИТЕ АДМИН СТР.', 'tfc-go-links' ),
            'tfc_go_metabox_cb',
            $pt,
            'normal', // «после редактора»
            'high'
        );
    }
}
add_action( 'add_meta_boxes', 'tfc_go_add_metabox' );

function tfc_go_metabox_cb( $post ) {
    wp_nonce_field( 'tfc_go_save_metabox', 'tfc_go_nonce' );

    // Основной массив реальных ссылок (повторяемые поля)
    $real_urls = get_post_meta( $post->ID, '_tfc_go_real_urls', true );
    if ( ! is_array( $real_urls ) ) {
        $real_urls = [];
    }

    // Back-compat: если ранее использовалось одиночное поле — покажем как первую строку
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
            $short = tfc_go_link( $real ); // продлим transient и покажем актуальную короткую
        }
        echo tfc_go_render_row( (int) $idx, (string) $real, (string) $short, false );
    }

    echo '</div>';

    echo '<p><button type="button" class="button" id="tfc-go-add-row">' . esc_html__( 'Добавить ссылку', 'tfc-go-links' ) . '</button></p>';

    echo '<script type="text/html" id="tfc-go-row-template">' . tfc_go_render_row( '__INDEX__', '', '', false ) . '</script>';
    ?>
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

        /* md5 (c) webtoolkit.info, trimmed for our use */
        function md5(e){function t(e,t){var n=(65535&e)+(65535&t);return(e>>16)+(t>>16)+(n>>16)<<16|65535&n}function n(e,t){return e<<t|e>>>32-t}function r(e,r,o,a,i,u){return t(n(t(t(r,e),t(a,u)),i),o)}function o(e,t,n,o,a,i,u){return r(t&n|~t&o,e,t,a,i,u)}function a(e,t,n,o,a,i,u){return r(t&o|n&~o,e,t,a,i,u)}function i(e,t,n,o,a,i,u){return r(t^n^o,e,t,a,i,u)}function u(e,t,n,o,a,i,u){return r(n^(t|~o),e,t,a,i,u)}function s(e,n){e[n>>5]|=128<<n%32,e[14+(n+64>>>9<<4)]=n;var s=1732584193,c=-271733879,f=-1732584194,l=271733878;for(var d=0;d<e.length;d+=16){var p=s,v=c,g=f,h=l;s=o(s,c,f,l,e[d],7,-680876936),l=o(l,s,c,f,e[d+1],12,-389564586),f=o(f,l,s,c,e[d+2],17,606105819),c=o(c,f,l,s,e[d+3],22,-1044525330),s=o(s,c,f,l,e[d+4],7,-176418897),l=o(l,s,c,f,e[d+5],12,1200080426),f=o(f,l,s,c,e[d+6],17,-1473231341),c=o(c,f,l,s,e[d+7],22,-45705983),s=o(s,c,f,l,e[d+8],7,1770035416),l=o(l,s,c,f,e[d+9],12,-1958414417),f=o(f,l,s,c,e[d+10],17,-42063),c=o(c,f,l,s,e[d+11],22,-1990404162),s=o(s,c,f,l,e[d+12],7,1804603682),l=o(l,s,c,f,e[d+13],12,-40341101),f=o(f,l,s,c,e[d+14],17,-1502002290),c=o(c,f,l,s,e[d+15],22,1236535329),s=a(s,c,f,l,e[d+1],5,-165796510),l=a(l,s,c,f,e[d+6],9,-1069501632),f=a(f,l,s,c,e[d+11],14,643717713),c=a(c,f,l,s,e[d],20,-373897302),s=a(s,c,f,l,e[d+5],5,-701558691),l=a(l,s,c,f,e[d+10],9,38016083),f=a(f,l,s,c,e[d+15],14,-660478335),c=a(c,f,l,s,e[d+4],20,-405537848),s=a(s,c,f,l,e[d+9],5,568446438),l=a(l,s,c,f,e[d+14],9,-1019803690),f=a(f,l,s,c,e[d+3],14,-187363961),c=a(c,f,l,s,e[d+8],20,1163531501),s=i(s,c,f,l,e[d+5],4,-1444681467),l=i(l,s,c,f,e[d+8],11,-51403784),f=i(f,l,s,c,e[d+11],16,1735328473),c=i(c,f,l,s,e[d+14],23,-1926607734),s=i(s,c,f,l,e[d+1],4,-378558),l=i(l,s,c,f,e[d+4],11,-2022574463),f=i(f,l,s,c,e[d+7],16,1839030562),c=i(c,f,l,s,e[d+10],23,-35309556),s=i(s,c,f,l,e[d+13],4,-1530992060),l=i(l,s,c,f,e[d],11,1272893353),f=i(f,l,s,c,e[d+3],16,-155497632),c=i(c,f,l,s,e[d+6],23,-1094730640),s=u(s,c,f,l,e[d],6,681279174),l=u(l,s,c,f,e[d+7],10,-358537222),f=u(f,l,s,c,e[d+14],15,-722521979),c=u(c,f,l,s,e[d+5],21,76029189),s=u(s,c,f,l,e[d+12],6,-640364487),l=u(l,s,c,f,e[d+3],10,-421815835),f=u(f,l,s,c,e[d+10],15,530742520),c=u(c,f,l,s,e[d+1],21,-995338651),s=u(s,c,f,l,e[d+8],6,-198630844),l=u(l,s,c,f,e[d+13],10,1126891415),f=u(f,l,s,c,e[d+2],15,-1416354905),c=u(c,f,l,s,e[d+7],21,-57434055),s=t(s,p),c=t(c,v),f=t(f,g),l=t(l,h)}return[s,c,f,l]}function c(e){for(var t="",n=32*e.length,r=0;r<n;r+=8)t+=String.fromCharCode(e[r>>5]>>>r%32&255);return t}function f(e){var t=[];for(t[(e.length>>2)-1]=void 0,r=0;r<t.length;r+=1)t[r]=0;for(var n=8*e.length,r=0;r<n;r+=8)t[r>>5]|=(255&e.charCodeAt(r/8))<<r%32;return t}function l(e){for(var t,n="0123456789abcdef",r="",o=0;o<e.length;o+=1)t=e.charCodeAt(o),r+=n.charAt(t>>>4&15)+n.charAt(15&t);return r}function d(e){return l(c(s(f(e),8*e.length)))}return d(unescape(encodeURIComponent(e))); }
    })();
    </script>
    <?php
}

/**
 * Рендер одной строки полей (№1 и №2)
 *
 * @param int|string $index Индекс строки или плейсхолдер '__INDEX__'
 * @param string     $real  Реальная ссылка
 * @param string     $short Короткая ссылка (опционально)
 * @param bool       $echo  Управляет выводом: если false — вернём строку
 * @return string
 */
function tfc_go_render_row( $index, $real = '', $short = '', $echo = true ) {
    ob_start();
    ?>
    <div class="tfc-go-row" style="border:1px solid #e2e8f0;padding:12px;margin-bottom:10px;border-radius:6px;">
        <p><label for="tfc_go_real_url_<?php echo esc_attr( $index ); ?>"><strong><?php echo esc_html__( 'Ссылка №1 (реальная цель редиректа)', 'tfc-go-links' ); ?></strong></label></p>
        <input type="url" style="max-width:900px" class="widefat tfc-go-real" id="tfc_go_real_url_<?php echo esc_attr( $index ); ?>" name="tfc_go_real_urls[]" value="<?php echo esc_attr( $real ); ?>" placeholder="https://example.com/..." />

        <p style="margin-top:12px"><label><strong><?php echo esc_html__( 'Ссылка №2 (короткая, для вставки)', 'tfc-go-links' ); ?></strong></label></p>
        <input type="url" style="max-width:900px" class="widefat tfc-go-short" readonly value="<?php echo esc_attr( $short ); ?>" />

        <p style="margin-top:8px">
            <button type="button" class="button tfc-go-remove"><?php echo esc_html__( 'Удалить', 'tfc-go-links' ); ?></button>
        </p>
        <p class="description" style="margin-top:6px;"><?php echo esc_html__( 'Короткая ссылка показывается сразу для удобства. Она окончательно заработает после сохранения записи.', 'tfc-go-links' ); ?></p>
    </div>
    <?php
    $html = ob_get_clean();
    if ( $echo ) { echo $html; }
    return $html;
}

function tfc_go_save_metabox( $post_id ) {
    // Нонсы и права
    if ( ! isset( $_POST['tfc_go_nonce'] ) || ! wp_verify_nonce( $_POST['tfc_go_nonce'], 'tfc_go_save_metabox' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Сохранить массив «Ссылка №1»
    $real_urls = isset( $_POST['tfc_go_real_urls'] ) && is_array( $_POST['tfc_go_real_urls'] ) ? array_map( 'trim', (array) $_POST['tfc_go_real_urls'] ) : [];

    // Валидация, очистка пустых и нормализация схемы
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
        // чистим легаси-мета
        delete_post_meta( $post_id, '_tfc_go_real_url' );
        return;
    }

    update_post_meta( $post_id, '_tfc_go_real_urls', $clean );

    // Создадим/продлим короткие по всем ссылкам
    foreach ( $clean as $real ) {
        tfc_go_link( $real );
    }

    // Легаси: положим первую ссылку в старый ключ, если где-то используется
    update_post_meta( $post_id, '_tfc_go_real_url', $clean[0] );
}
add_action( 'save_post', 'tfc_go_save_metabox' );
// === Конец файла ===
