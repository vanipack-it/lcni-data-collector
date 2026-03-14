<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Member_Auth_Shortcodes {

    private $service;

    public function __construct( LCNI_SaaS_Service $service ) {
        $this->service = $service;
        add_shortcode( 'lcni_member_login',    [ $this, 'render_login' ] );
        add_shortcode( 'lcni_member_register', [ $this, 'render_register' ] );
        add_action( 'init', [ $this, 'handle_login_submit' ] );
        add_action( 'init', [ $this, 'handle_register_submit' ] );
        // Gán gói mặc định khi đăng ký qua frontend được xử lý bởi LCNI_Member_Admin_User_Fields
    }

    // =========================================================
    // Render forms
    // =========================================================

    public function render_login( $atts = [] ) {
        if ( ! $this->service->can( 'member-login', 'view' ) ) {
            return '<p>Bạn không có quyền truy cập module Login.</p>';
        }

        $settings = get_option( 'lcni_member_login_settings', [] );

        // Nếu đã đăng nhập → redirect ngay về redirect_url cài đặt hoặc home
        // Bỏ qua redirect khi đang ở trong admin (tránh loop khi edit page)
        if ( is_user_logged_in() && ! is_admin() ) {
            $already_url = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : home_url( '/' );
            $this->safe_redirect( $already_url );
        }

        $quote = $this->random_quote();
        $error = isset( $_GET['lcni_login_error'] )
            ? sanitize_text_field( wp_unslash( $_GET['lcni_login_error'] ) )
            : '';

        // Ưu tiên: GET param → settings redirect_url → trang hiện tại
        $redirect_to = $this->resolve_redirect_target();
        if ( $redirect_to === '' && ! empty( $settings['redirect_url'] ) ) {
            $redirect_to = $settings['redirect_url'];
        }

        ob_start();
        echo '<form method="post" class="lcni-member-form lcni-member-login" style="' . esc_attr( $this->container_style( $settings ) ) . '">';
        echo '<div class="lcni-member-form-box" style="' . esc_attr( $this->form_box_style( $settings ) ) . '">';
        wp_nonce_field( 'lcni_member_login_action', 'lcni_member_login_nonce' );
        echo '<input type="hidden" name="lcni_member_login_submit" value="1">';
        echo '<input type="hidden" name="lcni_login_page_url" value="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '">';
        // Nhúng redirect_url vào form để không mất qua redirect chain
        if ( $redirect_to !== '' ) {
            echo '<input type="hidden" name="lcni_redirect_to" value="' . esc_url( $redirect_to ) . '">';
        }

        if ( $quote !== '' ) {
            echo '<blockquote style="' . esc_attr( $this->quote_style() ) . '">' . esc_html( $quote ) . '</blockquote>';
        }
        if ( $error !== '' ) {
            echo '<p style="color:#b91c1c;">' . esc_html( $error ) . '</p>';
        }

        $username_label = $this->setting_text( $settings, 'label_username', 'Username' );
        $password_label = $this->setting_text( $settings, 'label_password', 'Password' );
        $button_label   = $this->setting_text( $settings, 'label_button',   'Submit' );
        $button_icon    = ! empty( $settings['button_icon_class'] )
            ? '<i class="' . esc_attr( $settings['button_icon_class'] ) . '"></i> '
            : '';

        echo '<p style="' . esc_attr( $this->field_group_style() ) . '"><label style="' . esc_attr( $this->label_style( $settings ) ) . '">' . esc_html( $username_label ) . '</label><input style="' . esc_attr( $this->input_style( $settings ) ) . '" type="text" name="log" required></p>';
        echo '<p style="' . esc_attr( $this->field_group_style() ) . '"><label style="' . esc_attr( $this->label_style( $settings ) ) . '">' . esc_html( $password_label ) . '</label><input style="' . esc_attr( $this->input_style( $settings ) ) . '" type="password" name="pwd" required></p>';

        if ( ! empty( $settings['remember_me'] ) ) {
            echo '<p style="margin:0;align-self:flex-start;"><label><input type="checkbox" name="rememberme" value="1"> ' . esc_html__( 'Remember Me' ) . '</label></p>';
        }

        $register_button_label = $this->setting_text( $settings, 'register_button_label', 'Đăng ký' );
        $register_url          = ! empty( $settings['register_page_id'] )
            ? get_permalink( absint( $settings['register_page_id'] ) )
            : '';

        echo '<div style="display:flex;width:100%;gap:0;">';
        echo '<button type="submit" style="' . esc_attr( $this->button_style( $settings, true ) ) . '">' . wp_kses_post( $button_icon ) . esc_html( $button_label ) . '</button>';
        if ( ! empty( $register_url ) ) {
            echo '<a href="' . esc_url( $register_url ) . '" style="' . esc_attr( $this->button_style( $settings, true ) ) . 'text-decoration:none;">' . esc_html( $register_button_label ) . '</a>';
        } else {
            echo '<button type="button" disabled style="' . esc_attr( $this->button_style( $settings, true ) ) . 'opacity:0.7;cursor:not-allowed;">' . esc_html( $register_button_label ) . '</button>';
        }
        echo '</div>'; // end button row

        // ── Nút Google One Tap (bên trong form-box) ───────────────
        $google_client_id = get_option( 'lcni_google_client_id', '' );
        if ( $google_client_id !== '' ) {
            $g_nonce    = wp_create_nonce( 'lcni_google_auth_nonce' );
            $g_redirect = $redirect_to !== '' ? $redirect_to : ( ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : home_url( '/' ) );

            echo '<div id="lcni-google-signin-wrap" style="width:100%;display:flex;flex-direction:column;align-items:center;gap:8px;">';
            echo '<div style="display:flex;align-items:center;gap:8px;width:100%;">';
            echo '<hr style="flex:1;border:none;border-top:1px solid #e5e7eb;margin:0;">';
            echo '<span style="font-size:12px;color:#6b7280;white-space:nowrap;">hoặc</span>';
            echo '<hr style="flex:1;border:none;border-top:1px solid #e5e7eb;margin:0;">';
            echo '</div>';

            // Google Identity Services — bật One Tap tự động khi Chrome có sẵn tài khoản
            echo '<div id="g_id_onload"'
                . ' data-client_id="' . esc_attr( $google_client_id ) . '"'
                . ' data-callback="lcniGoogleCallback"'
                . ' data-auto_prompt="true"'
                . ' data-itp_support="true"'
                . ' data-cancel_on_tap_outside="false"'
                . ' data-nonce="' . esc_attr( $g_nonce ) . '">'
                . '</div>';

            echo '<div class="g_id_signin"'
                . ' data-type="standard"'
                . ' data-shape="rectangular"'
                . ' data-theme="outline"'
                . ' data-text="signin_with"'
                . ' data-locale="vi"'
                . ' data-size="large"'
                . ' data-width="320">'
                . '</div>';

            echo '<input type="hidden" id="lcni_google_redirect" value="' . esc_url( $g_redirect ) . '">';
            echo '</div>'; // end lcni-google-signin-wrap

            wp_enqueue_script( 'lcni-google-gis', 'https://accounts.google.com/gsi/client', [], null, true );
            wp_enqueue_script( 'lcni-google-auth', LCNI_URL . 'assets/js/lcni-google-auth.js', [ 'lcni-google-gis' ], '1.0.1', true );
            wp_localize_script( 'lcni-google-auth', 'lcniGoogleAuth', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => $g_nonce,
            ] );
        }
        // ── /Nút Google One Tap ────────────────────────────────────

        echo '</div>'; // end form-box
        echo '</form>';

        return ob_get_clean();
    }

    public function render_register( $atts = [] ) {
        if ( ! $this->service->can( 'member-register', 'view' ) ) {
            return '<p>Bạn không có quyền truy cập module Register.</p>';
        }

        $settings = get_option( 'lcni_member_register_settings', [] );

        // Nếu đã đăng nhập → redirect về redirect_url hoặc home
        // Bỏ qua redirect khi đang ở trong admin (tránh loop khi edit page)
        if ( is_user_logged_in() && ! is_admin() ) {
            $already_url = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : home_url( '/' );
            $this->safe_redirect( $already_url );
        }

        $quote = $this->random_quote();
        $error = isset( $_GET['lcni_register_error'] )
            ? sanitize_text_field( wp_unslash( $_GET['lcni_register_error'] ) )
            : '';

        // Ưu tiên: GET param → settings redirect_url → trang hiện tại
        $redirect_to = $this->resolve_redirect_target();
        if ( $redirect_to === '' && ! empty( $settings['redirect_url'] ) ) {
            $redirect_to = $settings['redirect_url'];
        }

        ob_start();
        echo '<form method="post" class="lcni-member-form lcni-member-register" style="' . esc_attr( $this->container_style( $settings ) ) . '">';
        echo '<div class="lcni-member-form-box" style="' . esc_attr( $this->form_box_style( $settings ) ) . '">';
        wp_nonce_field( 'lcni_member_register_action', 'lcni_member_register_nonce' );
        echo '<input type="hidden" name="lcni_member_register_submit" value="1">';
        echo '<input type="hidden" name="lcni_register_page_url" value="' . esc_url( get_permalink() ?: home_url( '/' ) ) . '">';
        // Nhúng redirect_url vào form
        if ( $redirect_to !== '' ) {
            echo '<input type="hidden" name="lcni_redirect_to" value="' . esc_url( $redirect_to ) . '">';
        }

        if ( $quote !== '' ) {
            echo '<blockquote style="' . esc_attr( $this->quote_style() ) . '">' . esc_html( $quote ) . '</blockquote>';
        }
        if ( $error !== '' ) {
            echo '<p style="color:#b91c1c;">' . esc_html( $error ) . '</p>';
        }

        $username_label = $this->setting_text( $settings, 'label_username', 'Username' );
        $email_label    = $this->setting_text( $settings, 'label_email',    'Email' );
        $password_label = $this->setting_text( $settings, 'label_password', 'Password' );
        $button_label   = $this->setting_text( $settings, 'label_button',   'Submit' );
        $button_icon    = ! empty( $settings['button_icon_class'] )
            ? '<i class="' . esc_attr( $settings['button_icon_class'] ) . '"></i> '
            : '';

        echo '<p style="' . esc_attr( $this->field_group_style() ) . '"><label style="' . esc_attr( $this->label_style( $settings ) ) . '">' . esc_html( $username_label ) . '</label><input style="' . esc_attr( $this->input_style( $settings ) ) . '" type="text" name="username" required></p>';
        echo '<p style="' . esc_attr( $this->field_group_style() ) . '"><label style="' . esc_attr( $this->label_style( $settings ) ) . '">' . esc_html( $email_label ) . '</label><input style="' . esc_attr( $this->input_style( $settings ) ) . '" type="email" name="email" required></p>';
        echo '<p style="' . esc_attr( $this->field_group_style() ) . '"><label style="' . esc_attr( $this->label_style( $settings ) ) . '">' . esc_html( $password_label ) . '</label><input style="' . esc_attr( $this->input_style( $settings ) ) . '" type="password" name="password" required></p>';
        echo '<div style="display:flex;width:100%;">';
        echo '<button type="submit" style="' . esc_attr( $this->button_style( $settings, true ) ) . '">' . wp_kses_post( $button_icon ) . esc_html( $button_label ) . '</button>';
        echo '</div>';

        // ── Nút Google (trong form-box register) ──────────────────
        $google_client_id = get_option( 'lcni_google_client_id', '' );
        if ( $google_client_id !== '' ) {
            $g_nonce    = wp_create_nonce( 'lcni_google_auth_nonce' );
            $g_redirect = $redirect_to !== '' ? $redirect_to : ( ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : home_url( '/' ) );

            echo '<div id="lcni-google-signin-wrap" style="width:100%;display:flex;flex-direction:column;align-items:center;gap:8px;">';
            echo '<div style="display:flex;align-items:center;gap:8px;width:100%;">';
            echo '<hr style="flex:1;border:none;border-top:1px solid #e5e7eb;margin:0;">';
            echo '<span style="font-size:12px;color:#6b7280;white-space:nowrap;">hoặc</span>';
            echo '<hr style="flex:1;border:none;border-top:1px solid #e5e7eb;margin:0;">';
            echo '</div>';

            echo '<div id="g_id_onload"'
                . ' data-client_id="' . esc_attr( $google_client_id ) . '"'
                . ' data-callback="lcniGoogleCallback"'
                . ' data-auto_prompt="true"'
                . ' data-itp_support="true"'
                . ' data-cancel_on_tap_outside="false"'
                . ' data-nonce="' . esc_attr( $g_nonce ) . '">'
                . '</div>';

            echo '<div class="g_id_signin"'
                . ' data-type="standard"'
                . ' data-shape="rectangular"'
                . ' data-theme="outline"'
                . ' data-text="signup_with"'
                . ' data-locale="vi"'
                . ' data-size="large"'
                . ' data-width="320">'
                . '</div>';

            echo '<input type="hidden" id="lcni_google_redirect" value="' . esc_url( $g_redirect ) . '">';
            echo '</div>'; // end lcni-google-signin-wrap

            wp_enqueue_script( 'lcni-google-gis', 'https://accounts.google.com/gsi/client', [], null, true );
            wp_enqueue_script( 'lcni-google-auth', LCNI_URL . 'assets/js/lcni-google-auth.js', [ 'lcni-google-gis' ], '1.0.1', true );
            wp_localize_script( 'lcni-google-auth', 'lcniGoogleAuth', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => $g_nonce,
            ] );
        }
        // ── /Nút Google register ───────────────────────────────────

        echo '</div>'; // end form-box
        echo '</form>';

        return ob_get_clean();
    }

    // =========================================================
    // Form handlers
    // =========================================================

    public function handle_login_submit() {
        if ( empty( $_POST['lcni_member_login_submit'] ) ) {
            return;
        }
        if ( empty( $_POST['lcni_member_login_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lcni_member_login_nonce'] ) ), 'lcni_member_login_action' )
        ) {
            $login_page_url = isset( $_POST['lcni_login_page_url'] )
                ? wp_validate_redirect( wp_unslash( $_POST['lcni_login_page_url'] ), '' )
                : '';
            if ( $login_page_url === '' ) {
                $login_page_url = wp_get_referer() ?: home_url( '/' );
            }
            $this->safe_redirect( add_query_arg( 'lcni_login_error', rawurlencode( 'Phiên đăng nhập hết hạn, vui lòng thử lại.' ), $login_page_url ) );
            // safe_redirect calls exit — line below is safety net
            exit;
        }

        $credentials = [
            'user_login'    => isset( $_POST['log'] ) ? sanitize_user( wp_unslash( $_POST['log'] ) ) : '',
            'user_password' => isset( $_POST['pwd'] ) ? (string) wp_unslash( $_POST['pwd'] ) : '',
            'remember'      => ! empty( $_POST['rememberme'] ),
        ];

        $user = wp_signon( $credentials, false );

        $login_page_url = isset( $_POST['lcni_login_page_url'] )
            ? wp_validate_redirect( wp_unslash( $_POST['lcni_login_page_url'] ), '' )
            : '';
        if ( $login_page_url === '' ) {
            $login_page_url = wp_get_referer() ?: home_url( '/' );
        }

        if ( is_wp_error( $user ) ) {
            $url = add_query_arg( 'lcni_login_error', rawurlencode( $user->get_error_message() ), $login_page_url );
            $this->safe_redirect( $url );
        }

        // Ưu tiên redirect: POST param → settings redirect_url → trang login
        $settings = get_option( 'lcni_member_login_settings', [] );
        $from_req = isset( $_POST['lcni_redirect_to'] )
            ? wp_validate_redirect( (string) wp_unslash( $_POST['lcni_redirect_to'] ), '' )
            : '';
        $from_settings = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';
        $redirect = $from_req !== '' ? $from_req : ( $from_settings !== '' ? $from_settings : $login_page_url );
        $this->safe_redirect( $redirect ?: home_url( '/' ) );
    }

    public function handle_register_submit() {
        if ( empty( $_POST['lcni_member_register_submit'] ) ) {
            return;
        }
        if ( empty( $_POST['lcni_member_register_nonce'] )
            || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['lcni_member_register_nonce'] ) ), 'lcni_member_register_action' )
        ) {
            $register_page_url = isset( $_POST['lcni_register_page_url'] )
                ? wp_validate_redirect( wp_unslash( $_POST['lcni_register_page_url'] ), '' )
                : '';
            if ( $register_page_url === '' ) {
                $register_page_url = wp_get_referer() ?: home_url( '/' );
            }
            $this->safe_redirect( add_query_arg( 'lcni_register_error', rawurlencode( 'Phiên đăng ký hết hạn, vui lòng thử lại.' ), $register_page_url ) );
            exit;
        }

        $username = isset( $_POST['username'] ) ? sanitize_user( wp_unslash( $_POST['username'] ) ) : '';
        $email    = isset( $_POST['email'] )    ? sanitize_email( wp_unslash( $_POST['email'] ) )   : '';
        $password = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] )          : '';

        $register_page_url = isset( $_POST['lcni_register_page_url'] )
            ? wp_validate_redirect( wp_unslash( $_POST['lcni_register_page_url'] ), '' )
            : '';
        if ( $register_page_url === '' ) {
            $register_page_url = wp_get_referer() ?: home_url( '/' );
        }

        $error = '';
        if ( $username === '' || ! validate_username( $username ) || username_exists( $username ) ) {
            $error = 'Username không hợp lệ hoặc đã tồn tại.';
        } elseif ( ! is_email( $email ) || email_exists( $email ) ) {
            $error = 'Email không hợp lệ hoặc đã tồn tại.';
        } elseif ( strlen( $password ) < 6 ) {
            $error = 'Password tối thiểu 6 ký tự.';
        }

        if ( $error !== '' ) {
            $this->safe_redirect( add_query_arg( 'lcni_register_error', rawurlencode( $error ), $register_page_url ) );
        }

        $settings = get_option( 'lcni_member_register_settings', [] );
        $role     = ! empty( $settings['default_role'] ) ? sanitize_key( $settings['default_role'] ) : 'subscriber';

        $user_id = wp_create_user( $username, $password, $email );
        if ( is_wp_error( $user_id ) ) {
            $this->safe_redirect( add_query_arg( 'lcni_register_error', rawurlencode( $user_id->get_error_message() ), $register_page_url ) );
        }

        wp_update_user( [ 'ID' => $user_id, 'role' => $role ] );

        if ( ! empty( $settings['auto_login'] ) ) {
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );
        }

        // Ưu tiên redirect: POST param → settings redirect_url → trang register
        $from_req      = isset( $_POST['lcni_redirect_to'] )
            ? wp_validate_redirect( (string) wp_unslash( $_POST['lcni_redirect_to'] ), '' )
            : '';
        $from_settings = ! empty( $settings['redirect_url'] ) ? $settings['redirect_url'] : '';
        $redirect = $from_req !== '' ? $from_req : ( $from_settings !== '' ? $from_settings : $register_page_url );
        $this->safe_redirect( $redirect ?: home_url( '/' ) );
    }

    // =========================================================
    // Helpers
    // =========================================================

    private function safe_redirect( $url ) {
        if ( ! headers_sent() ) {
            wp_safe_redirect( $url );
        } else {
            echo '<script>window.location=' . wp_json_encode( $url ) . ';</script>';
        }
        exit;
    }

    private function random_quote() {
        $quote_settings = get_option( 'lcni_member_quote_settings', [] );
        $quotes = array_values( array_filter( array_map( 'trim', preg_split(
            '/\r\n|\r|\n/',
            (string) ( $quote_settings['quote_list'] ?? '' )
        ) ) ) );

        if ( ! empty( $quote_settings['quote_csv_url'] ) ) {
            $response = wp_remote_get( esc_url_raw( $quote_settings['quote_csv_url'] ), [ 'timeout' => 4 ] );
            if ( ! is_wp_error( $response ) ) {
                $content = wp_remote_retrieve_body( $response );
                if ( is_string( $content ) && $content !== '' ) {
                    foreach ( preg_split( '/\r\n|\r|\n/', $content ) as $row ) {
                        $row = trim( (string) $row );
                        if ( $row !== '' ) {
                            $quotes[] = $row;
                        }
                    }
                }
            }
        }

        return empty( $quotes ) ? '' : $quotes[ array_rand( $quotes ) ];
    }

    private function setting_text( $settings, $key, $default ) {
        return ( array_key_exists( $key, $settings ) && $settings[ $key ] !== '' )
            ? (string) $settings[ $key ]
            : $default;
    }

    private function resolve_redirect_target() {
        $v = isset( $_GET['lcni_redirect_to'] )
            ? wp_validate_redirect( (string) wp_unslash( $_GET['lcni_redirect_to'] ), '' )
            : '';
        return is_string( $v ) ? $v : '';
    }

    // ─── Styles ──────────────────────────────────────────────

    private function quote_style() {
        $s    = get_option( 'lcni_member_quote_settings', [] );
        $blur = absint( $s['background_blur'] ?? 0 );
        $blur_css = $blur > 0 ? "-webkit-backdrop-filter:blur({$blur}px);backdrop-filter:blur({$blur}px);" : '';
        $effect   = $s['effect'] ?? 'normal';
        return sprintf(
            'width:%dpx;min-height:%dpx;margin:0 auto 16px auto;padding:12px;border-radius:%dpx;border:1px solid %s;background:%s;color:%s;font-size:%dpx;font-family:%s;text-align:%s;display:flex;align-items:center;justify-content:center;white-space:normal;overflow-wrap:anywhere;word-break:break-word;%s%s%s%s%s',
            max( 200, absint( $s['width'] ?? 500 ) ),
            max( 60,  absint( $s['height'] ?? 120 ) ),
            absint( $s['border_radius'] ?? 12 ),
            esc_attr( $s['border_color'] ?? '#d1d5db' ),
            esc_attr( $s['background']   ?? '#f8fafc' ),
            esc_attr( $s['text_color']   ?? '#334155' ),
            max( 10, absint( $s['font_size'] ?? 16 ) ),
            esc_attr( $s['font_family']  ?? 'inherit' ),
            esc_attr( $s['text_align']   ?? 'left' ),
            $blur_css,
            $effect === 'italic'    ? 'font-style:italic;'           : '',
            $effect === 'bold'      ? 'font-weight:700;'             : '',
            $effect === 'uppercase' ? 'text-transform:uppercase;'    : '',
            $effect === 'shadow'    ? 'text-shadow:1px 1px 2px rgba(15,23,42,0.35);' : ''
        );
    }

    private function input_style( $settings ) {
        return sprintf(
            'height:%dpx;width:%dpx;max-width:100%%;background:%s;border:1px solid %s;color:%s;border-radius:6px;padding:0 10px;box-sizing:border-box;display:block;text-align:left;margin:0 auto;',
            max( 32,  absint( $settings['input_height']       ?? 40 ) ),
            max( 120, absint( $settings['input_width']        ?? 320 ) ),
            esc_attr( $settings['input_bg']          ?? '#ffffff' ),
            esc_attr( $settings['input_border_color'] ?? '#d1d5db' ),
            esc_attr( $settings['input_text_color']   ?? '#111827' )
        );
    }

    private function field_group_style() {
        return 'margin:0;display:flex;flex-direction:column;gap:6px;align-items:center;';
    }

    private function label_style( $settings ) {
        return sprintf( 'text-align:left;width:%dpx;max-width:100%%;', max( 120, absint( $settings['input_width'] ?? 320 ) ) );
    }

    private function button_style( $settings, $full_width = false ) {
        $width = $full_width
            ? 'width:50%;flex:1 1 50%;'
            : sprintf( 'width:%dpx;', max( 100, absint( $settings['button_width'] ?? 180 ) ) );
        return sprintf(
            'height:%dpx;%smax-width:100%%;background:%s;border:1px solid %s;color:%s;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;box-sizing:border-box;',
            max( 30, absint( $settings['button_height']       ?? 42 ) ),
            $width,
            esc_attr( $settings['button_bg']           ?? '#2563eb' ),
            esc_attr( $settings['button_border_color']  ?? '#1d4ed8' ),
            esc_attr( $settings['button_text_color']    ?? '#ffffff' )
        );
    }

    private function form_box_style( $settings ) {
        return sprintf(
            'width:100%%;max-width:520px;background:%s;border:1px solid %s;border-radius:%dpx;padding:16px;display:flex;flex-direction:column;gap:12px;box-sizing:border-box;margin:0 auto;',
            esc_attr( $settings['form_box_background']    ?? '#ffffff' ),
            esc_attr( $settings['form_box_border_color']  ?? '#d1d5db' ),
            absint( $settings['form_box_border_radius']   ?? 10 )
        );
    }

    private function container_style( $settings ) {
        $bg_image = ! empty( $settings['background_image'] )
            ? 'background-image:url(' . esc_url( $settings['background_image'] ) . ');background-size:cover;background-position:center;'
            : '';
        return sprintf(
            'font-family:%s;color:%s;background:%s;%sborder:1px solid %s;border-radius:%dpx;padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;width:100%%;min-height:100dvh;box-sizing:border-box;margin:0 auto;',
            esc_attr( $settings['font']         ?? 'inherit' ),
            esc_attr( $settings['text_color']   ?? '#1f2937' ),
            esc_attr( $settings['background']   ?? '#ffffff' ),
            $bg_image,
            esc_attr( $settings['border_color'] ?? '#d1d5db' ),
            absint( $settings['border_radius']  ?? 8 )
        );
    }
}
