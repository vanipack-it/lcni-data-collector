<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LCNINotificationAdminPage
 *
 * Trang admin quản lý cấu hình email thông báo.
 * Đăng ký submenu vào menu LCNi Settings.
 *
 * URL: Admin → LCNi → Thông báo
 */
if ( ! class_exists( 'LCNINotificationAdminPage' ) ) :
class LCNINotificationAdminPage {

    const PAGE_SLUG = 'lcni-notifications';
    const OPTION    = LCNINotificationManager::OPTION_KEY;

    public function __construct() {
        add_action( 'admin_menu',         [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media' ] );
        add_action( 'admin_post_lcni_save_notifications',      [ $this, 'handle_save' ] );
        add_action( 'admin_post_lcni_send_test_notification',  [ $this, 'handle_send_test' ] );
        add_action( 'wp_ajax_lcni_send_test_notification',     [ $this, 'handle_send_test_ajax' ] );
    }

    /** Enqueue WP Media Library cho trang notification settings */
    public function enqueue_media( $hook ): void {
        // Chỉ load trên trang lcni-notifications
        if ( ! $hook || strpos( (string)$hook, self::PAGE_SLUG ) === false ) return;
        wp_enqueue_media();
    }

    public function register_menu(): void {
        add_submenu_page(
            'lcni-settings',
            'Thông báo Email',
            '🔔 Thông báo',
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    // =========================================================================
    // SAVE
    // =========================================================================

    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        check_admin_referer( 'lcni_save_notifications' );

        $raw     = isset( $_POST['lcni_notif'] ) ? wp_unslash( (array) $_POST['lcni_notif'] ) : [];
        $current = get_option( self::OPTION, [] );
        if ( ! is_array( $current ) ) $current = [];

        // Global settings
        $current['from_name']     = sanitize_text_field( $raw['from_name']     ?? '' );
        $current['from_email']    = sanitize_email(      $raw['from_email']    ?? '' );
        $current['logo_url']      = esc_url_raw(         $raw['logo_url']      ?? '' );
        $current['primary_color'] = sanitize_hex_color(  $raw['primary_color'] ?? '' ) ?: '#0a1628';
        $current['footer_text']   = wp_kses_post(        $raw['footer_text']   ?? '' );

        // Per-type settings
        foreach ( [ 'register_success', 'follow_rule', 'new_signal',
                    'upgrade_submitted', 'upgrade_admin_notify', 'upgrade_contacted',
                    'upgrade_approved', 'upgrade_rejected' ] as $type ) {
            $t = $raw[ $type ] ?? [];
            $current[ $type ] = [
                'enabled' => ! empty( $t['enabled'] ),
                'subject' => sanitize_text_field( $t['subject'] ?? '' ),
                'heading' => sanitize_text_field( $t['heading'] ?? '' ),
                'body'    => wp_kses_post( $t['body'] ?? '' ),
                'extra'   => wp_kses_post( $t['extra'] ?? '' ),
            ];
        }

        update_option( self::OPTION, $current );

        wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&saved=1' ) );
        exit;
    }

    // =========================================================================
    // RENDER
    // =========================================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $s       = LCNINotificationManager::get_settings();
        $tab     = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'global';
        $saved   = ! empty( $_GET['saved'] );
        $types   = [
            'register_success'     => [ 'label' => 'Đăng ký thành công',        'icon' => 'dashicons-welcome-add-page' ],
            'follow_rule'          => [ 'label' => 'Theo dõi Rule',              'icon' => 'dashicons-bell' ],
            'new_signal'           => [ 'label' => 'Tín hiệu mới',               'icon' => 'dashicons-chart-line' ],
            'upgrade_submitted'    => [ 'label' => 'Nâng cấp – Xác nhận',        'icon' => 'dashicons-email-alt' ],
            'upgrade_admin_notify' => [ 'label' => 'Nâng cấp – Thông báo Admin', 'icon' => 'dashicons-admin-users' ],
            'upgrade_contacted'    => [ 'label' => 'Nâng cấp – Đã liên hệ',      'icon' => 'dashicons-phone' ],
            'upgrade_approved'     => [ 'label' => 'Nâng cấp – Duyệt thành công','icon' => 'dashicons-yes-alt' ],
            'upgrade_rejected'     => [ 'label' => 'Nâng cấp – Từ chối',          'icon' => 'dashicons-dismiss' ],
        ];
        $all_tabs = array_merge(
            [ 'global' => [ 'label' => '⚙️ Cài đặt chung', 'icon' => 'dashicons-admin-generic' ] ],
            $types,
            [
                'email_marketing' => [ 'label' => '📧 Email Marketing', 'icon' => 'dashicons-megaphone' ],
                'diagnostics'     => [ 'label' => '🔧 Kiểm tra SMTP',   'icon' => 'dashicons-admin-tools' ],
            ]
        );

        echo '<div class="wrap">';
        echo '<h1>Cấu hình Thông báo Email</h1>';

        // Test sent notice
        if ( ! empty( $_GET['test_sent'] ) ) {
            $test_to  = sanitize_email( rawurldecode( $_GET['test_to'] ?? '' ) );
            $ok_test  = $_GET['test_sent'] === '1';
            $msg      = $ok_test
                ? "Đã gửi email test đến <strong>" . esc_html($test_to) . "</strong>"
                : "Gửi email test thất bại. Kiểm tra cấu hình SMTP và trạng thái bật/tắt của loại thông báo.";
            $cls      = $ok_test ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . $cls . ' is-dismissible"><p>' . $msg . '</p></div>';
        }

        if ( $saved ) {
            echo '<div class="notice notice-success is-dismissible"><p>Đã lưu cấu hình thông báo.</p></div>';
        }

        // Tabs
        echo '<h2 class="nav-tab-wrapper">';
        foreach ( $all_tabs as $key => $info ) {
            $url    = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $key );
            $active = $tab === $key ? 'nav-tab-active' : '';
            echo '<a href="' . esc_url($url) . '" class="nav-tab ' . $active . '">' . esc_html($info['label']) . '</a>';
        }
        // Tab User Rule (hook-based)
        do_action( 'lcni_notification_admin_tabs', $tab );

        echo '</h2>';

        // If user-rule tab — delegate entirely to hook
        if ( $tab === 'user-rule' ) {
            do_action( 'lcni_notification_admin_content', $tab );
            // Phải gọi render_preview_styles() để JS test email (lcni-test-email-btn) hoạt động
            $this->render_preview_styles();
            echo '</div>';
            return;
        }

        echo '<form method="post" action="' . esc_url( admin_url('admin-post.php') ) . '">';
        wp_nonce_field( 'lcni_save_notifications' );
        echo '<input type="hidden" name="action" value="lcni_save_notifications">';

        if ( $tab === 'global' ) {
            $this->render_global_tab( $s );
        } elseif ( $tab === 'diagnostics' ) {
            $this->render_diagnostics_tab();
            // No save button needed for diagnostics
            echo '</form>';
            $this->render_preview_styles();
            echo '</div>';
            return;
        } elseif ( $tab === 'email_marketing' ) {
            echo '</form>'; // close the auto-opened form — email_marketing has its own forms
            $this->render_email_marketing_tab();
            echo '</div>';
            return;
        } elseif ( isset( $types[$tab] ) ) {
            $this->render_type_tab( $tab, $s[$tab] ?? [], $types[$tab]['label'] );
        }

        echo '<p>';
        echo '<button type="submit" class="button button-primary button-large">💾 Lưu cài đặt</button>';
        echo ' &nbsp; ';
        // Test email button — outside form, uses AJAX
        $current_user = wp_get_current_user();
        echo '<button type="button" class="button button-secondary lcni-test-email-btn" '
            . 'data-nonce="' . esc_attr( wp_create_nonce('lcni_send_test_notification') ) . '" '
            . 'data-tab="' . esc_attr( $tab ) . '" '
            . 'data-email="' . esc_attr( $current_user->user_email ) . '">'
            . '📨 Gửi email test</button>';
        echo '<span class="lcni-test-email-status" style="margin-left:10px;font-size:13px;"></span>';
        echo '</p>';
        echo '</form>';

        $this->render_preview_styles();
        echo '</div>';
    }

    // ── Global settings tab ──────────────────────────────────────────────────

    private function render_global_tab( array $s ): void {
        $vars_help = $this->vars_help_html( [ 'site_name', 'site_url', 'user_name', 'user_email' ] );
        ?>
        <table class="form-table lcni-notif-table">
            <tr>
                <th><label>Tên người gửi</label></th>
                <td>
                    <input type="text" name="lcni_notif[from_name]"
                           value="<?php echo esc_attr($s['from_name'] ?? ''); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
                    <p class="description">Để trống = dùng tên website</p>
                </td>
            </tr>
            <tr>
                <th><label>Email người gửi</label></th>
                <td>
                    <input type="email" name="lcni_notif[from_email]"
                           value="<?php echo esc_attr($s['from_email'] ?? ''); ?>"
                           class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>">
                    <p class="description">Để trống = dùng email admin WordPress</p>
                </td>
            </tr>
            <tr>
                <th><label for="lcni_logo_url">Logo Email</label></th>
                <td>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <input type="url" id="lcni_logo_url" name="lcni_notif[logo_url]"
                               value="<?php echo esc_attr($s['logo_url'] ?? ''); ?>"
                               class="regular-text" placeholder="https://... hoặc chọn từ thư viện ảnh"
                               style="flex:1;min-width:280px;">
                        <button type="button" id="lcni_logo_select_btn" class="button">
                            📁 Chọn từ thư viện
                        </button>
                        <?php if (!empty($s['logo_url'])): ?>
                        <button type="button" id="lcni_logo_clear_btn" class="button" style="color:#dc2626;">
                            ✕ Xoá
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Preview -->
                    <div id="lcni_logo_preview" style="margin-top:10px;<?php echo empty($s['logo_url']) ? 'display:none;' : ''; ?>">
                        <p style="font-size:12px;color:#6b7280;margin:0 0 4px;">Xem trước trong header email:</p>
                        <div id="lcni_logo_preview_bg" style="background:<?php echo esc_attr($s['primary_color'] ?? '#0a1628'); ?>;padding:16px 20px;border-radius:6px;display:inline-block;min-width:220px;">
                            <?php if (!empty($s['logo_url'])): ?>
                                <img id="lcni_logo_img" src="<?php echo esc_url($s['logo_url']); ?>"
                                     alt="Logo" style="max-height:44px;max-width:180px;border:0;display:block;">
                            <?php else: ?>
                                <span id="lcni_logo_img" style="font-size:18px;font-weight:700;color:#fff;display:block;">
                                    <?php echo esc_html(get_bloginfo('name')); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <p class="description" style="margin-top:6px;">
                        Logo hiển thị trong header email (khuyến nghị: PNG nền trong, cao ~44px).<br>
                        <strong>Để trống</strong> = hiện tên website dạng text màu trắng.
                    </p>

                    <script>
                    (function(){
                        var urlInput  = document.getElementById('lcni_logo_url');
                        var selectBtn = document.getElementById('lcni_logo_select_btn');
                        var clearBtn  = document.getElementById('lcni_logo_clear_btn');
                        var preview   = document.getElementById('lcni_logo_preview');
                        var img       = document.getElementById('lcni_logo_img');
                        var siteName  = <?php echo wp_json_encode( get_bloginfo('name') ); ?>;

                        // Update preview khi URL thay đổi
                        function updatePreview(url) {
                            if (!url || !url.trim()) {
                                // Không có logo — hiện tên website
                                if (img.tagName === 'IMG') {
                                    var span = document.createElement('span');
                                    span.id = 'lcni_logo_img';
                                    span.style.cssText = 'font-size:18px;font-weight:700;color:#fff;display:block;';
                                    span.textContent = siteName;
                                    img.parentNode.replaceChild(span, img);
                                    img = span;
                                } else {
                                    img.textContent = siteName;
                                }
                                preview.style.display = 'block';
                            } else {
                                // Có logo — hiện ảnh
                                if (img.tagName !== 'IMG') {
                                    var imgEl = document.createElement('img');
                                    imgEl.id = 'lcni_logo_img';
                                    imgEl.style.cssText = 'max-height:44px;max-width:180px;border:0;display:block;';
                                    imgEl.alt = 'Logo';
                                    img.parentNode.replaceChild(imgEl, img);
                                    img = imgEl;
                                }
                                img.src = url;
                                preview.style.display = 'block';
                            }
                        }

                        urlInput.addEventListener('input', function(){ updatePreview(this.value.trim()); });

                        // Nút xoá
                        if (clearBtn) {
                            clearBtn.addEventListener('click', function(){
                                urlInput.value = '';
                                updatePreview('');
                                clearBtn.style.display = 'none';
                            });
                        }

                        // WP Media Library
                        if (selectBtn) {
                            selectBtn.addEventListener('click', function(e){
                                e.preventDefault();
                                if (typeof wp === 'undefined' || !wp.media) {
                                    alert('WordPress Media Library chưa được load trên trang này.');
                                    return;
                                }
                                var frame = wp.media({
                                    title: 'Chọn Logo Email',
                                    button: { text: 'Dùng ảnh này làm logo' },
                                    multiple: false,
                                    library: { type: 'image' },
                                });
                                frame.on('select', function(){
                                    var attachment = frame.state().get('selection').first().toJSON();
                                    var url = attachment.url || '';
                                    urlInput.value = url;
                                    updatePreview(url);
                                    if (clearBtn) clearBtn.style.display = 'inline-block';
                                });
                                frame.open();
                            });
                        }
                    })();
                    </script>
                </td>
            </tr>
            <tr>
                <th><label>Màu chủ đạo (Hex)</label></th>
                <td>
                    <?php $pc = esc_attr($s['primary_color'] ?? '#0a1628'); ?>
                    <!-- Hidden field là giá trị thực sự submit — color picker + text chỉ là UI -->
                    <input type="hidden"  id="lcni_pc_value" name="lcni_notif[primary_color]"
                           value="<?php echo $pc; ?>">
                    <input type="color"   id="lcni_pc_picker"
                           value="<?php echo $pc; ?>"
                           style="height:36px;width:56px;padding:2px;cursor:pointer;border:1px solid #8c8f94;border-radius:4px;">
                    <input type="text"    id="lcni_pc_text"
                           value="<?php echo $pc; ?>"
                           style="width:90px;margin-left:6px;font-family:monospace;"
                           maxlength="7" placeholder="#0a1628">
                    <span id="lcni_pc_swatch"
                          style="display:inline-block;width:28px;height:28px;border-radius:4px;vertical-align:middle;margin-left:6px;border:1px solid #d1d5db;background:<?php echo $pc; ?>"></span>
                    <p class="description">Màu header email và nút CTA. Mặc định: #0a1628 (xanh navy)</p>
                    <script>
                    (function(){
                        var hidden  = document.getElementById('lcni_pc_value');
                        var picker  = document.getElementById('lcni_pc_picker');
                        var text    = document.getElementById('lcni_pc_text');
                        var swatch  = document.getElementById('lcni_pc_swatch');

                        function sync(hex) {
                            // Validate hex — chỉ chấp nhận #rrggbb hoặc #rgb
                            var ok = /^#([0-9a-fA-F]{3}){1,2}$/.test(hex);
                            if (!ok) return;
                            hidden.value  = hex;
                            picker.value  = hex;
                            text.value    = hex;
                            swatch.style.background = hex;
                        }

                        var logoBg = document.getElementById('lcni_logo_preview_bg');

                        function sync(hex) {
                            // Validate hex — chỉ chấp nhận #rrggbb hoặc #rgb
                            var ok = /^#([0-9a-fA-F]{3}){1,2}$/.test(hex);
                            if (!ok) return;
                            hidden.value  = hex;
                            picker.value  = hex;
                            text.value    = hex;
                            swatch.style.background = hex;
                            // Cập nhật màu nền logo preview realtime
                            if (logoBg) logoBg.style.background = hex;
                        }

                        picker.addEventListener('input',  function(){ sync(this.value); });
                        picker.addEventListener('change', function(){ sync(this.value); });

                        text.addEventListener('input', function(){
                            var v = this.value.trim();
                            if (v && v[0] !== '#') v = '#' + v;
                            sync(v);
                        });
                    })();
                    </script>
                </td>
            </tr>
            <tr>
                <th><label>Nội dung Footer</label></th>
                <td>
                    <textarea name="lcni_notif[footer_text]" rows="3" class="large-text"
                              placeholder="Tên website &bull; địa chỉ website"><?php echo esc_textarea($s['footer_text'] ?? ''); ?></textarea>
                    <p class="description">HTML được phép. Để trống = tên & URL website. <?php echo $vars_help; ?></p>
                </td>
            </tr>
        </table>

        <div class="lcni-notif-preview-section">
            <h3>👁 Xem trước email</h3>
            <p>Chọn tab từng loại thông báo để xem trước và chỉnh sửa nội dung.</p>
        </div>
        <?php
        // Giữ các values của tab type vẫn submit
        foreach ( ['register_success','follow_rule','new_signal',
                   'upgrade_submitted','upgrade_admin_notify','upgrade_contacted',
                   'upgrade_approved','upgrade_rejected'] as $type ) {
            $ts = LCNINotificationManager::get_type_settings($type);
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($type) . '][enabled]" value="' . ($ts['enabled'] ? '1' : '0') . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($type) . '][subject]" value="' . esc_attr($ts['subject']) . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($type) . '][heading]" value="' . esc_attr($ts['heading']) . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($type) . '][body]" value="' . esc_attr($ts['body']) . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($type) . '][extra]" value="' . esc_attr($ts['extra']) . '">';
        }
    }

    // ── Per-type tab ─────────────────────────────────────────────────────────

    private function render_type_tab( string $type, array $ts, string $label ): void {
        $defaults = LCNINotificationManager::get_defaults();
        $def_type = $defaults[$type] ?? [];
        $ts       = array_merge( $def_type, $ts );

        // Available vars by type
        $var_map = [
            'register_success' => [ 'site_name', 'site_url', 'user_name', 'user_email' ],
            'follow_rule'      => [ 'site_name', 'user_name', 'user_email', 'rule_name' ],
            'new_signal'       => [ 'site_name', 'user_name', 'user_email', 'rule_name', 'symbol', 'price', 'signal_date', 'signal_card', 'signals_url', 'unsubscribe_url' ],
        ];
        $vars_help = $this->vars_help_html( $var_map[$type] ?? [] );
        ?>
        <div class="lcni-notif-type-wrap">
            <div class="lcni-notif-editor">
                <table class="form-table lcni-notif-table">
                    <tr>
                        <th>Bật/tắt</th>
                        <td>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                <input type="checkbox" name="lcni_notif[<?php echo esc_attr($type); ?>][enabled]"
                                       value="1" <?php checked(!empty($ts['enabled'])); ?> style="width:18px;height:18px;">
                                <span>Gửi email thông báo loại này</span>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Tiêu đề (Subject)</label></th>
                        <td>
                            <input type="text" name="lcni_notif[<?php echo esc_attr($type); ?>][subject]"
                                   value="<?php echo esc_attr($ts['subject']); ?>"
                                   class="large-text">
                            <p class="description"><?php echo $vars_help; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Tiêu đề trong email (Heading)</label></th>
                        <td>
                            <input type="text" name="lcni_notif[<?php echo esc_attr($type); ?>][heading]"
                                   value="<?php echo esc_attr($ts['heading']); ?>"
                                   class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label>Nội dung chính (Body)</label></th>
                        <td>
                            <?php
                            $editor_id = 'lcni_notif_body_' . $type;
                            wp_editor( $ts['body'], $editor_id, [
                                'textarea_name' => 'lcni_notif[' . $type . '][body]',
                                'media_buttons' => false,
                                'textarea_rows' => 10,
                                'teeny'         => true,
                                'tinymce'       => [ 'toolbar1' => 'bold,italic,link,bullist,numlist,blockquote,code' ],
                            ] );
                            ?>
                            <p class="description">HTML được phép. <?php echo $vars_help; ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th>
                            <label>Nội dung bổ sung</label>
                            <p class="description" style="font-weight:400;color:#6b7280;font-size:12px;">Admin chèn thêm thông tin tùy ý. Hiển thị sau nội dung chính.</p>
                        </th>
                        <td>
                            <?php
                            $extra_id = 'lcni_notif_extra_' . $type;
                            wp_editor( $ts['extra'], $extra_id, [
                                'textarea_name' => 'lcni_notif[' . $type . '][extra]',
                                'media_buttons' => true,
                                'textarea_rows' => 6,
                                'teeny'         => false,
                            ] );
                            ?>
                            <p class="description">Ví dụ: banner khuyến mãi, link tài liệu, thông báo bảo trì...</p>
                        </td>
                    </tr>
                </table>

                <div class="lcni-notif-reset-btn">
                    <button type="button" class="button button-secondary"
                            onclick="lcniResetNotifTemplate('<?php echo esc_js($type); ?>')">
                        🔄 Khôi phục template mặc định
                    </button>
                </div>
            </div>

            <div class="lcni-notif-preview-panel" id="lcni-preview-<?php echo esc_attr($type); ?>">
                <h3 style="margin:0 0 12px;font-size:14px;color:#374151;">📧 Preview</h3>
                <iframe id="lcni-preview-frame-<?php echo esc_attr($type); ?>"
                        style="width:100%;height:560px;border:1px solid #e5e7eb;border-radius:8px;background:#f3f4f6;"
                        src="<?php echo esc_url( add_query_arg([
                            'lcni_notif_preview' => $type,
                            '_nonce' => wp_create_nonce('lcni_notif_preview'),
                        ], admin_url('admin-ajax.php')) ); ?>">
                </iframe>
                <p style="margin-top:6px;font-size:11px;color:#9ca3af;text-align:center;">Preview dùng dữ liệu mẫu. Lưu để cập nhật preview.</p>
            </div>
        </div>

        <!-- Default templates JS data (for reset button) -->
        <script>
        var lcniNotifDefaults = lcniNotifDefaults || {};
        lcniNotifDefaults[<?php echo wp_json_encode($type); ?>] = <?php
            $def  = LCNINotificationManager::get_defaults();
            echo wp_json_encode( $def[$type] ?? [] );
        ?>;

        function lcniResetNotifTemplate(type) {
            if (!confirm('Khôi phục về template mặc định? Nội dung hiện tại sẽ bị mất.')) return;
            var d = lcniNotifDefaults[type];
            if (!d) return;
            ['subject','heading'].forEach(function(k) {
                var el = document.querySelector('input[name="lcni_notif['+type+']['+k+']"]');
                if (el) el.value = d[k] || '';
            });
            // body / extra: WP editor
            if (typeof tinyMCE !== 'undefined') {
                var bodyEd = tinyMCE.get('lcni_notif_body_' + type);
                if (bodyEd) bodyEd.setContent(d.body || '');
                var extraEd = tinyMCE.get('lcni_notif_extra_' + type);
                if (extraEd) extraEd.setContent(d.extra || '');
            }
        }
        </script>

        <!-- Hidden inputs for other types so they don't get blanked on save -->
        <?php
        $global_keys = ['from_name','from_email','logo_url','primary_color','footer_text'];
        $global_vals = LCNINotificationManager::get_settings();
        foreach ($global_keys as $k) {
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($k) . ']" value="' . esc_attr($global_vals[$k] ?? '') . '">';
        }
        foreach ( ['register_success','follow_rule','new_signal'] as $ot ) {
            if ($ot === $type) continue;
            $ots = LCNINotificationManager::get_type_settings($ot);
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($ot) . '][enabled]" value="' . ($ots['enabled'] ? '1' : '0') . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($ot) . '][subject]" value="' . esc_attr($ots['subject']) . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($ot) . '][heading]" value="' . esc_attr($ots['heading']) . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($ot) . '][body]" value="' . esc_attr($ots['body']) . '">';
            echo '<input type="hidden" name="lcni_notif[' . esc_attr($ot) . '][extra]" value="' . esc_attr($ots['extra']) . '">';
        }
        ?>
        <?php
    }

    // ── Variables help HTML ──────────────────────────────────────────────────

    private function vars_help_html( array $vars ): string {
        $labels = [
            'site_name'       => 'Tên website',
            'site_url'        => 'URL website',
            'user_name'       => 'Tên user',
            'user_email'      => 'Email user',
            'rule_name'       => 'Tên rule',
            'symbol'          => 'Mã CK',
            'price'           => 'Giá vào',
            'signal_date'     => 'Ngày tín hiệu',
            'signal_card'     => 'Thẻ thông tin signal (HTML)',
            'signals_url'     => 'Link xem signals',
            'unsubscribe_url' => 'Link hủy theo dõi',
        ];
        $items = array_map( static function( $v ) use ( $labels ) {
            return '<code title="' . esc_attr( $labels[$v] ?? $v ) . '">{{' . esc_html($v) . '}}</code>';
        }, $vars );
        return '<strong>Biến:</strong> ' . implode( ' ', $items );
    }

    // ── Test email handler (admin_post) ─────────────────────────────────────

    public function handle_send_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden', 403 );
        check_admin_referer( 'lcni_send_test_notification' );

        $type  = sanitize_key( $_POST['test_type']  ?? 'register_success' );
        $email = sanitize_email( $_POST['test_email'] ?? get_option('admin_email') );

        $ok  = $this->do_send_test( $type, $email );
        $tab = sanitize_key( $_POST['tab'] ?? $type );
        wp_safe_redirect( add_query_arg([
            'page'       => self::PAGE_SLUG,
            'tab'        => $tab,
            'test_sent'  => $ok ? '1' : '0',
            'test_to'    => rawurlencode($email),
        ], admin_url('admin.php') ) );
        exit;
    }

    /** AJAX handler cho test email button */
    public function handle_send_test_ajax(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Forbidden', 403 );
        check_ajax_referer( 'lcni_send_test_notification' );

        $type  = sanitize_key( $_POST['type']  ?? 'register_success' );
        $email = sanitize_email( $_POST['email'] ?? get_option('admin_email') );
        if ( ! is_email($email) ) wp_send_json_error( 'Email không hợp lệ.' );

        // Route ur_* types sang UserRuleNotifier
        if ( str_starts_with( $type, 'ur_' ) ) {
            $this->do_send_ur_test( $type, $email );
            return; // do_send_ur_test calls wp_send_json_* internally
        }

        $ok = $this->do_send_test( $type, $email );
        if ( $ok ) {
            // wp_mail returned true — nhưng cần check server thực sự nhận không
            // Trả về cả info SMTP để admin biết email đi qua đâu
            $mailer_info = $this->get_mailer_info();
            wp_send_json_success( [
                'message' => "✅ Đã gửi email test đến <strong>{$email}</strong>",
                'info'    => $mailer_info,
            ] );
        } else {
            $err = $this->_last_mail_error ?: 'wp_mail() trả về false. Xem wp-content/debug.log để biết chi tiết.';
            wp_send_json_error( [
                'message'     => "❌ Gửi thất bại",
                'error'       => $err,
                'mailer_info' => $this->get_mailer_info(),
                'suggestion'  => $this->get_fix_suggestion( $err ),
            ] );
        }
    }

    /**
     * Gửi email test cho các loại ur_* (UserRuleNotifier).
     * Gọi wp_send_json_* trực tiếp vì được gọi từ AJAX handler.
     */
    /**
     * Gửi email test cho các loại ur_* (UserRuleNotifier).
     * Mỗi type dùng bộ vars mẫu riêng phù hợp với nội dung template.
     * Gọi UserRuleNotifier::send() — đúng luồng thực tế 100%.
     */
    private function do_send_ur_test( string $type, string $email ): void {
        if ( ! class_exists( 'UserRuleNotifier' ) ) {
            wp_send_json_error( [ 'message' => 'UserRuleNotifier chưa được load.' ] );
            return;
        }

        // Tìm user nhận test — ưu tiên user có email này
        $user = get_user_by( 'email', $email );
        if ( ! $user ) $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            wp_send_json_error( [ 'message' => 'Không tìm thấy user để gửi test.' ] );
            return;
        }
        $user_id = (int) $user->ID;

        // Vars mẫu riêng cho từng type — đúng với logic thực tế của từng trường hợp
        $vars_by_type = [

            // Signal mới mirror vào UserRule (paper hoặc real)
            'ur_signal_opened' => [
                'rule_name'         => 'RADA Breakout Daily',
                'symbol'            => 'VHM',
                'entry_price'       => '42.500 đ',
                'initial_sl'        => '39.100 đ',
                'shares'            => '1.000',
                'allocated_capital' => '42.500.000 đ',
                'trade_type'        => '📄 Paper Trade (mô phỏng)',
            ],

            // Signal đóng — có R-multiple, PnL, lý do đóng
            'ur_signal_closed' => [
                'rule_name'         => 'RADA Breakout Daily',
                'symbol'            => 'VNM',
                'entry_price'       => '75.500 đ',
                'exit_price'        => '82.300 đ',
                'final_r'           => '+1.85',
                'pnl_vnd'           => '+6.800.000 đ',
                'pnl_color'         => 'style="color:#16a34a;font-weight:700"',
                'exit_reason'       => 'take_profit',
                'exit_reason_label' => '✅ Take Profit',
                'holding_days'      => '12',
            ],

            // Đặt lệnh DNSE thành công — auto_order tài khoản thật
            'ur_order_placed' => [
                'rule_name'     => 'RADA Breakout Daily',
                'symbol'        => 'HPG',
                'entry_price'   => '28.700 đ',
                'shares'        => '1.500',
                'dnse_order_id' => 'ORD-20260318-9876543',
                'account_no'    => '0123456789',
            ],

            // Đặt lệnh DNSE thất bại — lỗi API hoặc số dư không đủ
            'ur_order_failed' => [
                'rule_name'     => 'Momentum Weekly',
                'symbol'        => 'FPT',
                'error_message' => 'Số dư khả dụng không đủ để đặt lệnh. Cần ít nhất 28.700.000 đ.',
            ],

            // Token DNSE hết hạn — user cần đăng nhập lại lấy OTP mới
            'ur_dnse_token_expired' => [
                'rule_name' => 'RADA Breakout Daily',
                'symbol'    => 'VIC',
            ],

            // Signal bị bỏ qua vì đạt giới hạn max_symbols
            'ur_max_symbols' => [
                'rule_name'   => 'Momentum Weekly',
                'symbol'      => 'MBB',
                'max_symbols' => '5',
            ],
        ];

        $vars = $vars_by_type[ $type ] ?? null;
        if ( $vars === null ) {
            wp_send_json_error( [ 'message' => "Loại '{$type}' không có dữ liệu mẫu." ] );
            return;
        }

        // Override email user tạm thời để test gửi đến địa chỉ chỉ định
        $original_email   = $user->user_email;
        $user->user_email = $email;
        wp_cache_set( $user_id, $user, 'users' );

        // Bật type tạm thời nếu đang tắt
        $settings     = UserRuleNotifier::get_settings();
        $was_disabled = empty( $settings[ $type ]['enabled'] );
        if ( $was_disabled ) {
            $settings[ $type ]['enabled'] = true;
            update_option( UserRuleNotifier::OPTION_KEY, $settings );
        }

        $this->_last_mail_error = null;
        add_action( 'wp_mail_failed', [ $this, 'log_mail_error' ] );

        // Gọi đúng UserRuleNotifier::send() — giống luồng thực tế 100%
        $ok = UserRuleNotifier::send( $type, $user_id, $vars );

        remove_action( 'wp_mail_failed', [ $this, 'log_mail_error' ] );

        // Restore user email trong cache
        $user->user_email = $original_email;
        wp_cache_set( $user_id, $user, 'users' );

        if ( $was_disabled ) {
            $settings[ $type ]['enabled'] = false;
            update_option( UserRuleNotifier::OPTION_KEY, $settings );
        }

        if ( $ok ) {
            wp_send_json_success( [
                'message' => "✅ Đã gửi email test đến <strong>{$email}</strong>",
                'info'    => $this->get_mailer_info(),
            ] );
        } else {
            $err = $this->_last_mail_error ?: 'wp_mail() trả về false. Kiểm tra cấu hình SMTP.';
            wp_send_json_error( [
                'message'     => '❌ Gửi thất bại',
                'error'       => $err,
                'mailer_info' => $this->get_mailer_info(),
                'suggestion'  => $this->get_fix_suggestion( $err ),
            ] );
        }
    }


    /** Build HTML email wrapper cho UserRule notifications */
    private static function build_ur_email_html( string $site_name, string $heading, string $body, string $extra ): string {
        $year = gmdate('Y');
        return "<!DOCTYPE html><html lang='vi'><head><meta charset='UTF-8'></head>"
             . "<body style='margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;'>"
             . "<table width='100%' cellpadding='0' cellspacing='0' style='background:#f3f4f6;padding:32px 0;'>"
             . "<tr><td align='center'>"
             . "<table width='560' cellpadding='0' cellspacing='0' style='background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);max-width:560px;width:100%;'>"
             . "<tr><td style='background:#1e40af;padding:24px 32px;'>"
             . "<p style='margin:0;color:#fff;font-size:20px;font-weight:700;'>📊 " . esc_html($site_name) . "</p>"
             . "<p style='margin:4px 0 0;color:#bfdbfe;font-size:13px;'>Thông báo chiến lược</p>"
             . "</td></tr>"
             . "<tr><td style='padding:28px 32px;'>"
             . "<h2 style='margin:0 0 16px;color:#111827;font-size:18px;'>" . wp_kses_post($heading) . "</h2>"
             . "<div style='color:#374151;font-size:14px;line-height:1.7;'>" . wp_kses_post($body) . "</div>"
             . wp_kses_post($extra)
             . "<p style='margin:20px 0 0;color:#9ca3af;font-size:12px;'>Email tự động. Không phải lời khuyên đầu tư.</p>"
             . "</td></tr>"
             . "<tr><td style='background:#f9fafb;padding:14px 32px;border-top:1px solid #e5e7eb;'>"
             . "<p style='margin:0;color:#9ca3af;font-size:12px;text-align:center;'>" . esc_html($site_name) . " &bull; " . $year . "</p>"
             . "</td></tr></table></td></tr></table></body></html>";
    }

    /** Thực sự gửi email test với dữ liệu mẫu */
    private function do_send_test( string $type, string $email ): bool {
        // Cho phép tất cả các type đã đăng ký trong LCNINotificationManager
        $valid = array_keys( LCNINotificationManager::get_defaults() );
        if ( ! in_array( $type, $valid, true ) ) return false;

        $user = wp_get_current_user();

        $signal_card = LCNINotificationManager::build_signal_card(
            'RADA Breakout', 'VNM', '75.500 đ', date_i18n('d/m/Y')
        );

        $vars = [
            'user_name'       => $user->display_name ?: $user->user_login,
            'user_email'      => $email,
            'rule_name'       => 'RADA Breakout (mẫu)',
            'symbol'          => 'VNM',
            'price'           => '75.500 đ',
            'signal_date'     => date_i18n('d/m/Y'),
            'signal_card'     => $signal_card,
            'signals_url'     => home_url('/'),
            'unsubscribe_url' => home_url('/'),
        ];

        // Capture phpmailer error để debug
        add_action( 'wp_mail_failed', [ $this, 'log_mail_error' ] );

        // Tạm thời bật type nếu đang tắt
        $settings     = LCNINotificationManager::get_settings();
        $was_disabled = empty( $settings[$type]['enabled'] );
        if ( $was_disabled ) {
            $settings[$type]['enabled'] = true;
            update_option( LCNINotificationManager::OPTION_KEY, $settings );
        }

        $this->_last_mail_error = null;
        $ok = LCNINotificationManager::send( $type, $email, $vars );

        if ( $was_disabled ) {
            $settings[$type]['enabled'] = false;
            update_option( LCNINotificationManager::OPTION_KEY, $settings );
        }

        remove_action( 'wp_mail_failed', [ $this, 'log_mail_error' ] );
        return $ok;
    }

    /** @var string|null */
    private $_last_mail_error = null;

    public function log_mail_error( $wp_error ): void {
        if ( $wp_error instanceof WP_Error ) {
            $this->_last_mail_error = $wp_error->get_error_message();
            error_log( '[LCNI Email Test] wp_mail failed: ' . $this->_last_mail_error );
        }
    }

    // ── Diagnostics tab ──────────────────────────────────────────────────────

    private function render_diagnostics_tab(): void {
        $info = $this->get_mailer_info();
        $smtp_plugin = $info['wp_smtp_plugin'] ?? 'none';
        $has_smtp = $smtp_plugin !== 'none (PHP mail())';
        ?>
        <div style="max-width:680px;margin-top:20px;">
            <h3 style="margin-top:0;">🔧 Trạng thái SMTP</h3>

            <!-- Status card -->
            <div style="background:<?php echo $has_smtp ? '#f0fdf4' : '#fffbeb'; ?>;
                        border:1px solid <?php echo $has_smtp ? '#bbf7d0' : '#fde68a'; ?>;
                        border-radius:10px;padding:16px 20px;margin-bottom:20px;">
                <p style="margin:0 0 4px;font-weight:700;font-size:15px;color:<?php echo $has_smtp ? '#166534' : '#92400e'; ?>">
                    <?php echo $has_smtp ? '✅ SMTP plugin đang hoạt động' : '⚠️ Chưa cài SMTP plugin'; ?>
                </p>
                <p style="margin:0;font-size:13px;color:#6b7280;">
                    <?php if ($has_smtp): ?>
                        Plugin: <strong><?php echo esc_html($smtp_plugin); ?></strong>. Email sẽ được gửi qua cấu hình SMTP của plugin này.
                    <?php else: ?>
                        Đang dùng PHP <code>mail()</code>. Nhiều hosting chặn function này hoặc email vào spam.
                        <br>Khuyến nghị: cài <a href="<?php echo esc_url(admin_url('plugin-install.php?s=WP+Mail+SMTP&tab=search&type=term')); ?>" target="_blank">WP Mail SMTP</a> hoặc <a href="<?php echo esc_url(admin_url('plugin-install.php?s=FluentSMTP&tab=search&type=term')); ?>" target="_blank">FluentSMTP</a>.
                    <?php endif; ?>
                </p>
            </div>

            <!-- Info table -->
            <table class="widefat striped" style="border-radius:8px;overflow:hidden;">
                <thead><tr><th>Thông tin</th><th>Giá trị</th></tr></thead>
                <tbody>
                    <tr><td>SMTP Plugin</td><td><?php echo esc_html($smtp_plugin); ?></td></tr>
                    <tr><td>From Email (WordPress)</td><td><?php echo esc_html(get_option('admin_email')); ?></td></tr>
                    <tr><td>From Name (cấu hình LCNI)</td>
                        <td><?php $s = LCNINotificationManager::get_settings();
                            echo esc_html($s['from_name'] ?: get_bloginfo('name') . ' (mặc định)'); ?></td></tr>
                    <tr><td>From Email (cấu hình LCNI)</td>
                        <td><?php echo esc_html($s['from_email'] ?: get_option('admin_email') . ' (mặc định)'); ?></td></tr>
                    <?php if (!empty($info['host'])): ?>
                    <tr><td>SMTP Host</td><td><?php echo esc_html($info['host']); ?></td></tr>
                    <tr><td>SMTP Port</td><td><?php echo esc_html($info['port']); ?></td></tr>
                    <tr><td>SMTP Auth</td><td><?php echo esc_html($info['smtp_auth'] ?? ''); ?></td></tr>
                    <?php endif; ?>
                    <tr><td>PHP mail()</td><td><?php echo esc_html($info['php_mail_function']); ?></td></tr>
                    <tr><td>WordPress version</td><td><?php echo esc_html($info['wp_version']); ?></td></tr>
                </tbody>
            </table>

            <!-- Quick send test -->
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:20px;margin-top:20px;">
                <h4 style="margin:0 0 12px;">📨 Gửi email test nhanh</h4>
                <p style="font-size:13px;color:#6b7280;margin:0 0 12px;">Gửi email test đến địa chỉ bất kỳ để kiểm tra cấu hình.</p>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">
                    <?php foreach(['register_success'=>'🎉 Đăng ký','follow_rule'=>'🔔 Follow Rule','new_signal'=>'📈 Signal mới'] as $t => $lbl): ?>
                    <button type="button" class="button button-secondary lcni-test-email-btn"
                            data-nonce="<?php echo esc_attr(wp_create_nonce('lcni_send_test_notification')); ?>"
                            data-tab="<?php echo esc_attr($t); ?>"
                            data-email="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                        <?php echo esc_html($lbl); ?>
                    </button>
                    <?php endforeach; ?>
                </div>
                <span class="lcni-test-email-status" style="font-size:13px;display:block;margin-top:6px;"></span>
            </div>

            <!-- WP_MAIL debug tip -->
            <div style="margin-top:16px;padding:12px 16px;background:#eff6ff;border-radius:8px;border-left:4px solid #2563eb;">
                <strong style="font-size:13px;">💡 Bật debug log WordPress</strong>
                <p style="margin:6px 0 0;font-size:12px;color:#374151;">
                    Thêm vào <code>wp-config.php</code> để xem lỗi chi tiết trong <code>wp-content/debug.log</code>:<br>
                    <code>define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);</code>
                </p>
            </div>
        </div>
        <?php
    }

    // ── Mailer diagnostics ──────────────────────────────────────────────────

    /** Thu thập thông tin SMTP/mailer hiện tại để debug */
    private function get_mailer_info(): array {
        global $phpmailer;
        $smtp_plugin = $this->detect_smtp_plugin();
        $info = [
            'php_mail_function' => function_exists('mail') ? 'available' : 'not available',
            'wp_smtp_plugin'    => $smtp_plugin,
            'from_email'        => get_option('admin_email'),
            'wp_version'        => get_bloginfo('version'),
        ];
        if ( $smtp_plugin === 'WP Mail SMTP' ) {
            $wpms = get_option('wp_mail_smtp', []);
            if ( is_array($wpms) ) {
                $smtp = $wpms['smtp'] ?? [];
                $mail = $wpms['mail'] ?? [];
                $info['host']               = $smtp['host']       ?? '';
                $info['port']               = (string) ($smtp['port'] ?? '');
                $info['smtp_auth']          = !empty($smtp['auth']) ? 'yes' : 'no';
                $info['username']           = $smtp['user']       ?? '';
                $info['encryption']         = $smtp['encryption'] ?? '';
                $info['from_email_setting'] = $mail['from_email'] ?? '';
                $info['mailer']             = 'smtp';
            }
        } elseif ( $smtp_plugin === 'FluentSMTP' ) {
            $fms  = get_option('fluentmail-settings', []);
            $conn = is_array($fms) ? ($fms['connections'] ?? []) : [];
            if ( !empty($conn) ) {
                $c = reset($conn);
                $info['host']     = $c['host']     ?? '';
                $info['port']     = (string) ($c['port'] ?? '');
                $info['username'] = $c['username'] ?? '';
                $info['mailer']   = 'smtp';
            }
        } elseif ( isset($phpmailer) && is_object($phpmailer) ) {
            $info['mailer']    = $phpmailer->Mailer  ?? 'unknown';
            $info['host']      = $phpmailer->Host    ?? '';
            $info['port']      = (string) ($phpmailer->Port ?? '');
            $info['smtp_auth'] = isset($phpmailer->SMTPAuth) ? ($phpmailer->SMTPAuth ? 'yes' : 'no') : '';
            $info['username']  = $phpmailer->Username ?? '';
        }
        return $info;
    }


    private function detect_smtp_plugin(): string {
        if ( defined('FLUENTMAIL') || class_exists('FluentMail\\App\\Services\\Mailer\\Manager') ) return 'FluentSMTP';
        if ( defined('WPMS_PLUGIN_VER') || defined('WPMS_ON') ) return 'WP Mail SMTP';
        if ( class_exists('WPMailSMTP\\Core') )     return 'WP Mail SMTP';
        if ( class_exists('WPMailSMTP\\WPMailSMTP') ) return 'WP Mail SMTP';
        if ( function_exists('wp_mail_smtp') )       return 'WP Mail SMTP';
        if ( get_option('wp_mail_smtp') !== false )  return 'WP Mail SMTP';
        if ( defined('POSTMAN_EMAIL_LOG_TABLE') )    return 'Post SMTP';
        if ( function_exists('mg_api_last_error')  ) return 'WP Mailgun';
        if ( defined('SENDGRID_PLUGIN_DIR')        ) return 'SendGrid';
        if ( defined('EASY_WP_SMTP_VERSION')       ) return 'Easy WP SMTP';
        return 'none (PHP mail())';
    }


    private function get_fix_suggestion( string $error ): string {
        $error_lower = strtolower( $error );
        if ( strpos( $error_lower, 'smtp' ) !== false || strpos( $error_lower, 'connect' ) !== false ) {
            return 'Lỗi kết nối SMTP. Kiểm tra Host/Port/Username/Password trong plugin SMTP của bạn.';
        }
        if ( strpos( $error_lower, 'authentication' ) !== false || strpos( $error_lower, 'auth' ) !== false ) {
            return 'Sai thông tin xác thực SMTP. Kiểm tra username/password/app password.';
        }
        if ( strpos( $error_lower, 'ssl' ) !== false || strpos( $error_lower, 'tls' ) !== false ) {
            return 'Lỗi SSL/TLS. Thử đổi Encryption sang TLS hoặc SSL trong cài đặt SMTP.';
        }
        if ( strpos( $error_lower, 'recipient' ) !== false || strpos( $error_lower, 'address' ) !== false ) {
            return 'Địa chỉ email người nhận không hợp lệ hoặc bị từ chối bởi server.';
        }
        return 'Cài đặt plugin SMTP (WP Mail SMTP / FluentSMTP) để đảm bảo email được gửi đi.';
    }

    // ── Page styles ──────────────────────────────────────────────────────────

    private function render_preview_styles(): void {
        ?>
        <style>
        .lcni-notif-table th { width: 200px; padding-top: 12px; }
        .lcni-notif-table td { padding-top: 8px; }
        .lcni-notif-type-wrap { display: grid; grid-template-columns: 1fr 480px; gap: 24px; margin-top: 16px; }
        .lcni-notif-editor { }
        .lcni-notif-preview-panel { position: sticky; top: 32px; height: fit-content; }
        .lcni-notif-reset-btn { margin: 16px 0; }
        @media (max-width: 1200px) { .lcni-notif-type-wrap { grid-template-columns: 1fr; } }
        code { font-size: 12px; background: #f3f4f6; padding: 1px 5px; border-radius: 3px; cursor: pointer; }
        code:hover { background: #dbeafe; }
        .lcni-notif-preview-section { background: #f0f7ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 16px 20px; margin: 12px 0; }
        </style>
        <script>
        // Test email AJAX
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.lcni-test-email-btn');
            if (!btn) return;
            e.preventDefault();

            var nonce = btn.dataset.nonce;
            var type  = btn.dataset.tab || 'register_success';
            var def   = btn.dataset.email || '';
            var email = prompt('Gửi email test đến địa chỉ:', def);
            if (!email || !email.trim()) return;

            var status = btn.parentElement.querySelector('.lcni-test-email-status');
            btn.disabled = true;
            btn.textContent = '⏳ Đang gửi...';
            if (status) { status.textContent = ''; status.style.color = ''; }

            fetch(ajaxurl, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'lcni_send_test_notification',
                    _ajax_nonce: nonce,
                    type: type,
                    email: email.trim(),
                })
            })
            .then(function(r){ return r.json(); })
            .then(function(res){
                if (res.success) {
                    var msg = res.data.message || '✅ Đã gửi';
                    var info = res.data.info || {};
                    var infoTxt = info.wp_smtp_plugin ? ' (qua ' + info.wp_smtp_plugin + ')' : '';
                    if (status) {
                        status.innerHTML = msg + infoTxt;
                        status.style.color = '#166534';
                    }
                } else {
                    var d = res.data || {};
                    var errMsg = (d.message || '❌ Gửi thất bại');
                    var detail = d.error ? '<br><small style="color:#6b7280">' + d.error + '</small>' : '';
                    var suggest = d.suggestion ? '<br><small style="color:#b45309">💡 ' + d.suggestion + '</small>' : '';
                    var mailer = (d.mailer_info && d.mailer_info.wp_smtp_plugin)
                        ? '<br><small>Plugin: ' + d.mailer_info.wp_smtp_plugin + '</small>' : '';
                    if (status) {
                        status.innerHTML = errMsg + detail + suggest + mailer;
                        status.style.color = '#991b1b';
                    }
                }
            })
            .catch(function(){ if (status) { status.textContent = '❌ Lỗi kết nối.'; status.style.color = '#991b1b'; } })
            .finally(function(){ btn.disabled = false; btn.textContent = '📨 Gửi email test'; });
        });

        // Click code → copy to clipboard
        document.querySelectorAll('code').forEach(function(el) {
            el.addEventListener('click', function() {
                navigator.clipboard && navigator.clipboard.writeText(el.textContent);
                el.style.background = '#bbf7d0';
                setTimeout(function(){ el.style.background = ''; }, 800);
            });
        });
        </script>
        <?php
    }

    // =========================================================================
    // Tab: Email Marketing — quản lý mẫu email chiến dịch
    // =========================================================================
    private function render_email_marketing_tab(): void {
        $option_key = 'lcni_mkt_email_templates';
        $templates  = get_option( $option_key, [] );
        if ( ! is_array($templates) ) $templates = [];

        // Seed mẫu mặc định nếu chưa có dữ liệu
        if ( empty($templates) ) {
            $templates = $this->get_default_email_templates();
            update_option( $option_key, $templates );
        }

        // Handle save / delete / restore
        if ( ! empty($_POST['lcni_mkt_tpl_action']) && check_admin_referer('lcni_mkt_tpl_nonce') ) {
            $action = sanitize_key( $_POST['lcni_mkt_tpl_action'] );

            if ( $action === 'restore_defaults' ) {
                $defaults  = $this->get_default_email_templates();
                $templates = array_merge( $defaults, $templates ); // giữ mẫu tùy chỉnh, chỉ thêm mặc định còn thiếu
                foreach ( $defaults as $k => $v ) {
                    if ( isset($_POST['overwrite_defaults']) ) $templates[$k] = $v;
                    elseif ( ! isset($templates[$k]) )         $templates[$k] = $v;
                }
                update_option( $option_key, $templates );
                echo '<div class="notice notice-success is-dismissible"><p>✅ Đã khôi phục mẫu email mặc định.</p></div>';
            }

            if ( $action === 'save' ) {
                $tid  = sanitize_key( $_POST['tpl_id'] ?? '' );
                $name = sanitize_text_field( $_POST['tpl_name'] ?? '' );
                if ( ! $tid ) $tid = 'tpl_' . time();
                $templates[$tid] = [
                    'name'    => $name ?: 'Mẫu email',
                    'subject' => sanitize_text_field( $_POST['tpl_subject'] ?? '' ),
                    'body'    => wp_kses_post( $_POST['tpl_body'] ?? '' ),
                ];
                update_option( $option_key, $templates );
                echo '<div class="notice notice-success is-dismissible"><p>✅ Đã lưu mẫu email.</p></div>';
            } elseif ( $action === 'delete' ) {
                $tid = sanitize_key( $_POST['tpl_id'] ?? '' );
                if ( isset($templates[$tid]) ) {
                    unset( $templates[$tid] );
                    update_option( $option_key, $templates );
                }
                echo '<div class="notice notice-success is-dismissible"><p>🗑 Đã xóa mẫu email.</p></div>';
            }
        }

        $edit_id  = sanitize_key( $_GET['edit_tpl'] ?? '' );
        $edit_tpl = $edit_id && isset($templates[$edit_id]) ? $templates[$edit_id] : null;

        // Available placeholders
        $placeholders = [
            '{user_name}'     => 'Tên hiển thị người nhận',
            '{user_email}'    => 'Email người nhận',
            '{site_name}'     => 'Tên website',
            '{site_url}'      => 'URL website',
            '{campaign_name}' => 'Tên chiến dịch',
            '{share_url}'     => 'Link chia sẻ cá nhân của user',
            '{platform}'      => 'Nền tảng (FACEBOOK / TIKTOK)',
        ];
        ?>
        <h2>📧 Mẫu Email Marketing</h2>
        <p style="color:#6b7280;font-size:13px">Tạo các mẫu email để gửi thông báo chiến dịch chia sẻ đến user. Admin có thể tạo nhiều mẫu khác nhau.</p>

        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap">

            <!-- Danh sách mẫu -->
            <div style="flex:1;min-width:260px">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
                    <h3 style="margin:0">Danh sách mẫu</h3>
                    <div style="display:flex;gap:6px">
                        <form method="post" style="display:inline" onsubmit="return confirm('Khôi phục các mẫu mặc định? Mẫu hiện có sẽ không bị xóa, chỉ thêm mẫu còn thiếu.')">
                            <?php wp_nonce_field('lcni_mkt_tpl_nonce'); ?>
                            <input type="hidden" name="lcni_mkt_tpl_action" value="restore_defaults">
                            <button type="submit" class="button button-small">🔄 Khôi phục mặc định</button>
                        </form>
                        <a href="<?php echo admin_url('admin.php?page=lcni-notifications&tab=email_marketing'); ?>" class="button button-small button-primary">+ Tạo mẫu mới</a>
                    </div>
                </div>
                <?php if ( empty($templates) ): ?>
                <p style="color:#9ca3af;font-size:13px;background:#f9fafb;padding:16px;border-radius:6px;text-align:center">Chưa có mẫu email nào.</p>
                <?php else: ?>
                <table class="wp-list-table widefat fixed striped" style="font-size:13px">
                    <thead><tr>
                        <th>Tên mẫu</th><th>Tiêu đề email</th><th style="width:110px">Thao tác</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ( $templates as $tid => $tpl ): ?>
                    <tr <?php echo $edit_id === $tid ? 'style="background:#eff6ff"' : ''; ?>>
                        <td><strong><?php echo esc_html($tpl['name'] ?? ''); ?></strong><br><code style="font-size:10px;color:#9ca3af"><?php echo esc_html($tid); ?></code></td>
                        <td style="color:#6b7280"><?php echo esc_html(mb_strimwidth($tpl['subject'] ?? '', 0, 50, '…')); ?></td>
                        <td style="display:flex;gap:4px">
                            <a href="<?php echo admin_url('admin.php?page=lcni-notifications&tab=email_marketing&edit_tpl='.esc_attr($tid)); ?>" class="button button-small">✏️ Sửa</a>
                            <form method="post" onsubmit="return confirm('Xóa mẫu này?')" style="display:inline">
                                <?php wp_nonce_field('lcni_mkt_tpl_nonce'); ?>
                                <input type="hidden" name="lcni_mkt_tpl_action" value="delete">
                                <input type="hidden" name="tpl_id" value="<?php echo esc_attr($tid); ?>">
                                <button type="submit" class="button button-small" style="color:#dc2626">🗑</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Form tạo/sửa mẫu -->
            <div style="flex:1;min-width:340px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px">
                <h3 style="margin:0 0 14px"><?php echo $edit_tpl ? '✏️ Sửa mẫu: ' . esc_html($edit_tpl['name']) : '➕ Tạo mẫu email mới'; ?></h3>
                <form method="post">
                    <?php wp_nonce_field('lcni_mkt_tpl_nonce'); ?>
                    <input type="hidden" name="lcni_mkt_tpl_action" value="save">
                    <input type="hidden" name="tpl_id" value="<?php echo esc_attr($edit_id); ?>">

                    <table class="form-table" style="font-size:13px">
                        <tr>
                            <th style="width:120px"><label>Tên mẫu *</label></th>
                            <td><input type="text" name="tpl_name" class="regular-text" required
                                       value="<?php echo esc_attr($edit_tpl['name'] ?? ''); ?>"
                                       placeholder="VD: Mời chia sẻ Facebook tháng 4"></td>
                        </tr>
                        <tr>
                            <th><label>Tiêu đề *</label></th>
                            <td><input type="text" name="tpl_subject" class="large-text" required
                                       value="<?php echo esc_attr($edit_tpl['subject'] ?? ''); ?>"
                                       placeholder="VD: 🎁 Chia sẻ để nhận {campaign_name} miễn phí!"></td>
                        </tr>
                        <tr>
                            <th><label>Nội dung *</label></th>
                            <td>
                                <?php
                                wp_editor(
                                    $edit_tpl['body'] ?? '',
                                    'lcni_mkt_tpl_body_editor',
                                    [
                                        'textarea_name' => 'tpl_body',
                                        'media_buttons' => false,
                                        'teeny'         => true,
                                        'textarea_rows' => 12,
                                        'quicktags'     => true,
                                    ]
                                );
                                ?>
                            </td>
                        </tr>
                    </table>

                    <!-- Placeholder guide -->
                    <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px;margin-bottom:14px">
                        <p style="font-weight:600;font-size:12px;margin:0 0 8px;color:#374151">📌 Biến có thể dùng trong tiêu đề và nội dung:</p>
                        <div style="display:flex;flex-wrap:wrap;gap:6px">
                            <?php foreach ( $placeholders as $var => $desc ): ?>
                            <span title="<?php echo esc_attr($desc); ?>"
                                  style="font-size:11px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;border-radius:4px;padding:2px 8px;cursor:pointer;font-family:monospace"
                                  onclick="navigator.clipboard.writeText('<?php echo esc_attr($var); ?>')"><?php echo esc_html($var); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <p style="font-size:11px;color:#9ca3af;margin:6px 0 0">Click vào biến để sao chép. Hover để xem mô tả.</p>
                    </div>

                    <p class="submit" style="margin:0">
                        <button type="submit" class="button button-primary">💾 Lưu mẫu email</button>
                        <?php if ($edit_tpl): ?>
                        <a href="<?php echo admin_url('admin.php?page=lcni-notifications&tab=email_marketing'); ?>" class="button" style="margin-left:8px">Tạo mẫu mới</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    /**
     * Trả về 4 mẫu email mặc định cho chiến dịch chia sẻ Facebook / TikTok.
     */
    private function get_default_email_templates(): array {
        $site = get_bloginfo('name') ?: 'LCNi';

        // Wrapper HTML dùng chung — responsive, tương thích Gmail/Outlook
        $wrap_open  = '<div style="font-family:\'Segoe UI\',Arial,sans-serif;max-width:600px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden">'
                    . '<div style="background:#1e3a8a;padding:24px 32px;text-align:center">'
                    . '<h1 style="margin:0;color:#ffffff;font-size:20px;font-weight:700">' . esc_html($site) . '</h1>'
                    . '</div>'
                    . '<div style="padding:28px 32px">';
        $wrap_close = '</div>'
                    . '<div style="background:#f9fafb;padding:14px 32px;text-align:center;font-size:11px;color:#9ca3af;border-top:1px solid #e5e7eb">'
                    . '<p style="margin:0">' . esc_html($site) . ' &bull; <a href="{site_url}" style="color:#6b7280">{site_url}</a> &bull; Bạn nhận email này vì đã đăng ký tài khoản.</p>'
                    . '</div>'
                    . '</div>';

        $btn = '<p style="text-align:center;margin:24px 0">'
             . '<a href="{share_url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;font-weight:600">🚀 Tham gia ngay</a>'
             . '</p>';

        return [

            /* ── 1. Mời tham gia — Facebook ── */
            'default_fb_invite' => [
                'name'    => '📘 [Facebook] Mời tham gia chiến dịch chia sẻ',
                'subject' => '🎁 Chia sẻ 1 bài Facebook — Nhận {campaign_name} miễn phí!',
                'body'    => $wrap_open
                    . '<p style="color:#374151;font-size:15px">Xin chào <strong>{user_name}</strong>,</p>'
                    . '<p style="color:#374151">Chúng tôi đang tổ chức chương trình <strong style="color:#1d4ed8">"{campaign_name}"</strong> — chỉ cần chia sẻ <strong>1 bài lên Facebook</strong> là nhận ngay gói thành viên hoàn toàn <strong style="color:#16a34a">miễn phí</strong>!</p>'
                    . '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:14px 18px;border-radius:6px;margin:18px 0">'
                    . '<p style="margin:0 0 8px;font-weight:700;color:#1d4ed8;font-size:15px">🎯 3 bước đơn giản:</p>'
                    . '<ol style="margin:0;padding-left:18px;color:#374151;line-height:2">'
                    . '<li>Truy cập link bên dưới để lấy <strong>link chia sẻ cá nhân</strong></li>'
                    . '<li>Đăng link lên <strong>Facebook</strong> (chọn chế độ <strong>Công khai</strong>)</li>'
                    . '<li>Dán link bài đăng vào ô xác nhận → tài khoản nâng cấp <strong>tự động</strong></li>'
                    . '</ol>'
                    . '</div>'
                    . $btn
                    . '<p style="background:#fef3c7;border:1px solid #fcd34d;border-radius:6px;padding:10px 14px;font-size:13px;color:#92400e">⏰ <strong>Lưu ý:</strong> Bài đăng phải ở chế độ <strong>Công khai</strong> và chương trình có thời hạn. Hành động ngay!</p>'
                    . '<p style="color:#6b7280;font-size:13px;margin-top:20px">Trân trọng,<br><strong>' . esc_html($site) . '</strong></p>'
                    . $wrap_close,
            ],

            /* ── 2. Mời tham gia — TikTok ── */
            'default_tiktok_invite' => [
                'name'    => '🎵 [TikTok] Mời tham gia chiến dịch chia sẻ',
                'subject' => '🎵 Đăng 1 video TikTok — Nhận {campaign_name} miễn phí!',
                'body'    => $wrap_open
                    . '<p style="color:#374151;font-size:15px">Xin chào <strong>{user_name}</strong>,</p>'
                    . '<p style="color:#374151">Bạn có muốn nhận <strong style="color:#1d4ed8">"{campaign_name}"</strong> hoàn toàn miễn phí? Chỉ cần đăng <strong>1 video TikTok</strong> ngắn giới thiệu về <strong>' . esc_html($site) . '</strong> là xong!</p>'
                    . '<div style="background:#f0fdf4;border-left:4px solid #16a34a;padding:14px 18px;border-radius:6px;margin:18px 0">'
                    . '<p style="margin:0 0 8px;font-weight:700;color:#15803d;font-size:15px">🎬 Cách thực hiện:</p>'
                    . '<ol style="margin:0;padding-left:18px;color:#374151;line-height:2">'
                    . '<li>Truy cập link bên dưới để lấy <strong>link chia sẻ cá nhân</strong></li>'
                    . '<li>Quay video ngắn 15–60s giới thiệu tính năng bạn thích trên <strong>' . esc_html($site) . '</strong></li>'
                    . '<li>Đăng TikTok kèm link (chế độ <strong>Công khai</strong>)</li>'
                    . '<li>Dán link video TikTok vào ô xác nhận → được nâng cấp <strong>ngay lập tức</strong></li>'
                    . '</ol>'
                    . '</div>'
                    . '<p style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:6px;padding:10px 14px;font-size:13px;color:#0369a1">💡 <strong>Mẹo:</strong> Video ngắn 15–30 giây nói về lý do bạn thích dùng ' . esc_html($site) . ' là đủ. Không cần chỉnh sửa cầu kỳ!</p>'
                    . $btn
                    . '<p style="color:#6b7280;font-size:13px;margin-top:20px">Trân trọng,<br><strong>' . esc_html($site) . '</strong></p>'
                    . $wrap_close,
            ],

            /* ── 3. Nhắc nhở sắp hết hạn ── */
            'default_expiry_reminder' => [
                'name'    => '⏰ Nhắc nhở sắp hết hạn — Gia hạn miễn phí qua chia sẻ',
                'subject' => '⚠️ Gói của bạn sắp hết hạn — Gia hạn miễn phí ngay hôm nay!',
                'body'    => $wrap_open
                    . '<p style="color:#374151;font-size:15px">Xin chào <strong>{user_name}</strong>,</p>'
                    . '<p style="color:#374151">Gói thành viên của bạn tại <strong>' . esc_html($site) . '</strong> sắp hết hạn. Đừng để gián đoạn trải nghiệm phân tích và theo dõi tín hiệu!</p>'
                    . '<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:14px 18px;border-radius:6px;margin:18px 0">'
                    . '<p style="margin:0;color:#92400e;font-size:14px">🔔 <strong>Tin vui:</strong> Bạn có thể <strong>gia hạn hoàn toàn miễn phí</strong> bằng cách tham gia chương trình <strong>"{campaign_name}"</strong> — chỉ cần chia sẻ 1 bài lên <strong>{platform}</strong>!</p>'
                    . '</div>'
                    . '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:14px 18px;margin:18px 0">'
                    . '<p style="margin:0 0 6px;font-weight:700;color:#374151">✅ Gia hạn miễn phí trong 3 bước:</p>'
                    . '<ol style="margin:0;padding-left:18px;color:#374151;line-height:2">'
                    . '<li>Nhấn nút bên dưới để vào trang chia sẻ</li>'
                    . '<li>Lấy link và đăng lên <strong>{platform}</strong> (Công khai)</li>'
                    . '<li>Dán link bài đăng → gia hạn tự động ngay lập tức</li>'
                    . '</ol>'
                    . '</div>'
                    . $btn
                    . '<p style="color:#6b7280;font-size:13px;margin-top:20px">Trân trọng,<br><strong>' . esc_html($site) . '</strong></p>'
                    . $wrap_close,
            ],

            /* ── 4. Nhắc lần 2 — khẩn cấp ── */
            'default_expiry_urgent' => [
                'name'    => '🚨 Nhắc lần 2 — Sắp hết hạn (khẩn)',
                'subject' => '🚨 Chỉ còn vài ngày! Gia hạn {campaign_name} trước khi quá muộn',
                'body'    => $wrap_open
                    . '<div style="background:#fef2f2;border:2px solid #dc2626;border-radius:8px;padding:14px 18px;text-align:center;margin-bottom:20px">'
                    . '<p style="margin:0;color:#dc2626;font-size:16px;font-weight:700">🚨 Gói thành viên của bạn sắp hết hạn!</p>'
                    . '</div>'
                    . '<p style="color:#374151;font-size:15px">Xin chào <strong>{user_name}</strong>,</p>'
                    . '<p style="color:#374151">Đây là nhắc nhở cuối — gói <strong>' . esc_html($site) . '</strong> của bạn sắp hết hạn. Sau khi hết hạn, bạn sẽ mất quyền truy cập các tính năng cao cấp.</p>'
                    . '<p style="color:#374151">🎁 Bạn vẫn còn cơ hội <strong style="color:#16a34a">gia hạn miễn phí</strong> qua chương trình <strong>"{campaign_name}"</strong>:</p>'
                    . '<div style="background:#f9fafb;border-radius:8px;padding:14px 18px;margin:14px 0;border:1px solid #e5e7eb">'
                    . '<p style="margin:0;color:#374151;line-height:1.9">1. Truy cập trang chia sẻ → Lấy link cá nhân<br>2. Đăng lên <strong>{platform}</strong> (Công khai)<br>3. Dán link bài đăng → Gia hạn ngay!</p>'
                    . '</div>'
                    . '<p style="text-align:center;margin:24px 0">'
                    . '<a href="{share_url}" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;font-weight:600">⚡ Gia hạn ngay bây giờ</a>'
                    . '</p>'
                    . '<p style="color:#9ca3af;font-size:12px;text-align:center">Đừng để mất quyền truy cập tín hiệu và phân tích chuyên sâu bạn đang dùng.</p>'
                    . '<p style="color:#6b7280;font-size:13px;margin-top:20px">Trân trọng,<br><strong>' . esc_html($site) . '</strong></p>'
                    . $wrap_close,
            ],

            /* ── 5. Cảm ơn sau khi chia sẻ thành công ── */
            'default_thankyou' => [
                'name'    => '🎉 Cảm ơn đã chia sẻ — Xác nhận nâng cấp',
                'subject' => '🎉 Nâng cấp thành công — Cảm ơn bạn đã chia sẻ về ' . esc_html($site) . '!',
                'body'    => $wrap_open
                    . '<div style="background:#dcfce7;border-radius:8px;padding:16px;text-align:center;margin-bottom:20px">'
                    . '<p style="margin:0;font-size:22px">🎉</p>'
                    . '<p style="margin:4px 0 0;font-weight:700;color:#166534;font-size:16px">Tài khoản đã được nâng cấp thành công!</p>'
                    . '</div>'
                    . '<p style="color:#374151;font-size:15px">Xin chào <strong>{user_name}</strong>,</p>'
                    . '<p style="color:#374151">Cảm ơn bạn đã tham gia chương trình <strong>"{campaign_name}"</strong> và chia sẻ lên <strong>{platform}</strong>. Sự ủng hộ của bạn giúp cộng đồng ' . esc_html($site) . ' ngày càng phát triển! 🙌</p>'
                    . '<div style="background:#f9fafb;border-radius:8px;padding:14px 18px;margin:18px 0;border:1px solid #e5e7eb">'
                    . '<p style="margin:0 0 8px;font-weight:700;color:#374151">🚀 Bạn có thể khám phá ngay:</p>'
                    . '<ul style="margin:0;padding-left:20px;color:#374151;line-height:2">'
                    . '<li>📊 <strong>Tín hiệu & Chiến lược</strong> — theo dõi giao dịch chuyên sâu</li>'
                    . '<li>📈 <strong>Hiệu suất chiến lược</strong> — xem thống kê winrate, R:R</li>'
                    . '<li>🤖 <strong>Tự động hóa</strong> — áp dụng rule giao dịch tự động</li>'
                    . '</ul>'
                    . '</div>'
                    . '<p style="text-align:center;margin:24px 0">'
                    . '<a href="{site_url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:13px 28px;border-radius:8px;font-size:15px;font-weight:600">Khám phá ngay →</a>'
                    . '</p>'
                    . '<p style="color:#6b7280;font-size:13px">Một lần nữa, cảm ơn bạn đã đồng hành cùng <strong>' . esc_html($site) . '</strong>! 💙</p>'
                    . '<p style="color:#6b7280;font-size:13px">Trân trọng,<br><strong>' . esc_html($site) . '</strong></p>'
                    . $wrap_close,
            ],

            /* ── 6. Giới thiệu ngắn — thân thiện ── */
            'default_casual_invite' => [
                'name'    => '💬 Lời mời thân thiện — Ngắn gọn',
                'subject' => '💬 {user_name} ơi, có quà dành cho bạn từ ' . esc_html($site) . '!',
                'body'    => $wrap_open
                    . '<p style="color:#374151;font-size:15px">Hey <strong>{user_name}</strong>! 👋</p>'
                    . '<p style="color:#374151;font-size:15px;line-height:1.8">Chúng mình đang có <strong style="color:#1d4ed8">chương trình đặc biệt</strong> — bạn chỉ cần <strong>chia sẻ 1 bài</strong> lên <strong>{platform}</strong> là nhận ngay <strong style="color:#16a34a">{campaign_name}</strong> miễn phí! 🎁</p>'
                    . '<div style="border:2px dashed #bfdbfe;border-radius:10px;padding:16px 20px;margin:20px 0;text-align:center;background:#f0f9ff">'
                    . '<p style="margin:0 0 6px;font-size:14px;color:#374151">Link tham gia của bạn:</p>'
                    . '<p style="margin:0"><a href="{share_url}" style="color:#1d4ed8;font-weight:700;word-break:break-all">{share_url}</a></p>'
                    . '</div>'
                    . '<p style="color:#374151;font-size:13px;line-height:1.8">Đăng link lên <strong>{platform}</strong> (công khai) → dán link bài đăng vào trang → xong! Cực kỳ đơn giản 😊</p>'
                    . $btn
                    . '<p style="color:#6b7280;font-size:12px;margin-top:16px">P.S. Nếu bạn có bạn bè cũng hay dùng công cụ phân tích chứng khoán, đừng quên giới thiệu nhé! 🤝</p>'
                    . '<p style="color:#6b7280;font-size:13px">Cheers,<br><strong>Team ' . esc_html($site) . '</strong></p>'
                    . $wrap_close,
            ],

        ];
    }
}
endif; // class_exists LCNINotificationAdminPage
