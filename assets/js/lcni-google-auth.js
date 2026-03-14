/**
 * LCNI Google Auth — Frontend handler
 *
 * Callback được Google GIS gọi sau khi user chọn tài khoản.
 * lcniGoogleAuth được wp_localize_script inject với:
 *   - ajax_url : admin-ajax.php URL
 *   - nonce    : wp_nonce cho action lcni_google_auth_nonce
 */

/* global lcniGoogleAuth */

/**
 * Callback toàn cục — Google GIS gọi hàm này khi user chọn tài khoản.
 * Phải là global (window.*) để GIS data-callback tìm được.
 *
 * @param {{ credential: string }} response
 */
window.lcniGoogleCallback = function ( response ) {
    // Lấy redirect từ element gần nhất (hỗ trợ nhiều block trong trang)
    var redirectEls = document.querySelectorAll( '#lcni_google_redirect' );
    var redirect = redirectEls.length > 0
        ? redirectEls[ redirectEls.length - 1 ].value || '/'
        : window.location.href;

    // Tìm wrap gần nhất (login form hoặc denied block)
    var btn = document.querySelector( '.lcni-denied-google-wrap' )
           || document.getElementById( 'lcni-google-signin-wrap' );

    // Hiển thị trạng thái loading
    if ( btn ) {
        btn.style.opacity = '0.6';
        btn.style.pointerEvents = 'none';
    }

    var body = new URLSearchParams( {
        action:      'lcni_google_login',
        credential:  response.credential,
        nonce:       lcniGoogleAuth.nonce,
        redirect_to: redirect,
    } );

    fetch( lcniGoogleAuth.ajax_url, {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    body.toString(),
    } )
        .then( function ( r ) {
            return r.json();
        } )
        .then( function ( data ) {
            if ( data.success && data.data && data.data.redirect ) {
                window.location.href = data.data.redirect;
            } else {
                var msg = ( data.data ) ? data.data : 'Đăng nhập Google thất bại.';
                lcniShowGoogleError( msg );
                lcniResetGoogleBtn( btn );
            }
        } )
        .catch( function () {
            lcniShowGoogleError( 'Lỗi kết nối, vui lòng thử lại.' );
            lcniResetGoogleBtn( btn );
        } );
};

/**
 * Hiển thị thông báo lỗi bên dưới nút Google.
 * @param {string} msg
 */
function lcniShowGoogleError( msg ) {
    var wrap = document.querySelector( '.lcni-denied-google-wrap' )
            || document.getElementById( 'lcni-google-signin-wrap' );
    if ( ! wrap ) return;

    var existing = wrap.querySelector( '.lcni-google-error' );
    if ( existing ) existing.remove();

    var p = document.createElement( 'p' );
    p.className   = 'lcni-google-error';
    p.textContent = msg;
    p.style.cssText = 'color:#b91c1c;font-size:13px;margin:8px 0 0;text-align:center;';
    wrap.appendChild( p );
}

/**
 * Khôi phục nút về trạng thái bình thường khi có lỗi.
 * @param {HTMLElement|null} btn
 */
function lcniResetGoogleBtn( btn ) {
    if ( ! btn ) return;
    btn.style.opacity       = '1';
    btn.style.pointerEvents = 'auto';
}
