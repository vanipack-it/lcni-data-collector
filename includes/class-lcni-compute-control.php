<?php
/**
 * LCNI Compute Control
 *
 * Tab "Compute Control" trong admin — cho phép bật/tắt từng nhóm cron
 * và tính toán của plugin bằng checkbox, lưu vào wp_options.
 *
 * Các nhóm có thể kiểm soát:
 *  - lcni_compute_incremental_sync   : cron hourly fetch OHLC mới
 *  - lcni_compute_seed_batch         : cron seed lịch sử
 *  - lcni_compute_rule_rebuild       : cron rebuild indicators / rule
 *  - lcni_compute_runtime_update     : LCNI_Update_Manager (cron mỗi phút)
 *  - lcni_compute_ohlc_latest        : LCNI_OHLC_Latest_Manager (snapshot)
 *  - lcni_compute_industry_metrics   : industry metrics extra
 *  - lcni_compute_recommend_cron     : Recommend daily cron
 *
 * Cách dùng trong các class khác:
 *   LCNI_Compute_Control::is_enabled('lcni_compute_runtime_update')
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class LCNI_Compute_Control {

    // option key lưu trạng thái
    const OPTION_KEY = 'lcni_compute_control_settings';

    // Danh sách các nhóm compute — key => label
    const GROUPS = [
        'lcni_compute_incremental_sync'  => 'Incremental Sync (cron fetch OHLC mới hàng giờ)',
        'lcni_compute_seed_batch'        => 'Seed Batch (cron seed lịch sử theo queue)',
        'lcni_compute_rule_rebuild'      => 'Rule Rebuild (cron tính toán lại indicators & rules)',
        'lcni_compute_runtime_update'    => 'Runtime Update Manager (cron mỗi phút, cập nhật OHLC trong giờ)',
        'lcni_compute_ohlc_latest'       => 'OHLC Latest Snapshot (sync bảng ohlc_latest)',
        'lcni_compute_industry_metrics'  => 'Industry Metrics (tính toán thống kê ngành)',
        'lcni_compute_recommend_cron'    => 'Recommend Daily Cron (engine gợi ý mua/bán)',
    ];

    // Mô tả bổ sung cho mỗi nhóm
    const DESCRIPTIONS = [
        'lcni_compute_incremental_sync'  => 'Hook: <code>lcni_collect_data_cron</code>. Tắt để ngừng tự động fetch OHLC từ nguồn ngoài (DNSE/Entrade).',
        'lcni_compute_seed_batch'        => 'Hook: <code>lcni_seed_batch_cron</code>. Tắt khi dùng Python local để seed thay thế.',
        'lcni_compute_rule_rebuild'      => 'Hook: <code>lcni_rule_rebuild_batch_cron</code>. Tắt khi đã tính toán đầy đủ từ Python local.',
        'lcni_compute_runtime_update'    => 'Hook: <code>lcni_runtime_update_cron</code>. Tắt để giảm tải web production khi dùng Python sync.',
        'lcni_compute_ohlc_latest'       => 'Hook: <code>lcni_ohlc_latest_snapshot_cron</code>. Tắt nếu snapshot đã được Python push trực tiếp.',
        'lcni_compute_industry_metrics'  => 'Hook: <code>lcni_compute_industry_metrics_extra</code>. Tắt nếu metrics ngành không cần realtime.',
        'lcni_compute_recommend_cron'    => 'Hook: <code>lcni_recommend_daily_cron</code>. Tắt nếu engine recommend không dùng.',
    ];

    // Nhóm nào mặc định BẬT
    const DEFAULTS = [
        'lcni_compute_incremental_sync'  => true,
        'lcni_compute_seed_batch'        => true,
        'lcni_compute_rule_rebuild'      => true,
        'lcni_compute_runtime_update'    => true,
        'lcni_compute_ohlc_latest'       => true,
        'lcni_compute_industry_metrics'  => true,
        'lcni_compute_recommend_cron'    => true,
    ];

    // ─── API ────────────────────────────────────────────────────────────────

    /**
     * Kiểm tra một nhóm compute có được bật không.
     * Dùng trong các class khác:
     *   if ( ! LCNI_Compute_Control::is_enabled('lcni_compute_runtime_update') ) return;
     *
     * @param string $group  Key trong self::GROUPS
     * @return bool
     */
    public static function is_enabled( string $group ): bool {
        $settings = self::get_settings();

        // Nếu key không tồn tại trong settings → dùng default
        if ( ! array_key_exists( $group, $settings ) ) {
            return (bool) ( self::DEFAULTS[ $group ] ?? true );
        }

        return (bool) $settings[ $group ];
    }

    /**
     * Lấy toàn bộ settings (merge với defaults).
     */
    public static function get_settings(): array {
        $saved = get_option( self::OPTION_KEY, [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        return array_merge( self::DEFAULTS, $saved );
    }

    /**
     * Lưu settings.
     */
    public static function save_settings( array $input ): void {
        $clean = [];
        foreach ( array_keys( self::GROUPS ) as $key ) {
            $clean[ $key ] = ! empty( $input[ $key ] );
        }
        update_option( self::OPTION_KEY, $clean, false );

        // Đồng bộ: tắt/bật cron hooks ngay lập tức
        self::sync_cron_schedule( $clean );
    }

    /**
     * Tắt hoặc đặt lại lịch cron dựa theo settings mới.
     */
    private static function sync_cron_schedule( array $settings ): void {
        $map = [
            'lcni_compute_incremental_sync' => LCNI_CRON_HOOK,
            'lcni_compute_seed_batch'       => LCNI_SEED_CRON_HOOK,
            'lcni_compute_rule_rebuild'     => LCNI_RULE_REBUILD_CRON_HOOK,
            'lcni_compute_runtime_update'   => defined('LCNI_Update_Manager::CRON_HOOK')
                                                ? LCNI_Update_Manager::CRON_HOOK
                                                : 'lcni_runtime_update_cron',
            'lcni_compute_ohlc_latest'      => defined('LCNI_OHLC_Latest_Manager::CRON_HOOK')
                                                ? LCNI_OHLC_Latest_Manager::CRON_HOOK
                                                : 'lcni_ohlc_latest_snapshot_cron',
            'lcni_compute_industry_metrics' => 'lcni_compute_industry_metrics_extra',
            'lcni_compute_recommend_cron'   => 'lcni_recommend_daily_cron',
        ];

        foreach ( $map as $group => $hook ) {
            if ( empty( $settings[ $group ] ) ) {
                // Tắt → xoá scheduled event nếu có
                wp_clear_scheduled_hook( $hook );
            }
        }
        // Khi bật lại, để lcni_ensure_cron_scheduled() tự re-schedule
        // trong lần request tiếp theo (init hook).
    }

    // ─── ADMIN TAB ──────────────────────────────────────────────────────────

    public static function init(): void {
        add_action( 'admin_init', [ __CLASS__, 'handle_save' ] );
    }

    public static function handle_save(): void {
        if (
            ! is_admin()
            || ! current_user_can( 'manage_options' )
            || ! isset( $_POST['lcni_compute_control_save'] )
        ) {
            return;
        }

        $nonce = isset( $_POST['lcni_compute_control_nonce'] )
            ? sanitize_text_field( wp_unslash( $_POST['lcni_compute_control_nonce'] ) )
            : '';

        if ( ! wp_verify_nonce( $nonce, 'lcni_compute_control_save' ) ) {
            return;
        }

        $input = isset( $_POST['lcni_compute'] ) && is_array( $_POST['lcni_compute'] )
            ? (array) wp_unslash( $_POST['lcni_compute'] )
            : [];

        self::save_settings( $input );

        set_transient(
            'lcni_compute_control_notice',
            [ 'type' => 'success', 'message' => 'Đã lưu cài đặt Compute Control.' ],
            30
        );

        $redirect = admin_url( 'admin.php?page=lcni-settings&tab=compute_control' );
        wp_safe_redirect( $redirect );
        exit;
    }

    /**
     * Render toàn bộ tab Compute Control.
     * Được gọi từ LCNI_Settings::settings_page().
     */
    public static function render_tab(): void {
        $settings = self::get_settings();
        $notice   = get_transient( 'lcni_compute_control_notice' );
        if ( $notice ) {
            delete_transient( 'lcni_compute_control_notice' );
        }

        $groups_on  = array_filter( $settings );
        $groups_off = array_diff_key( $settings, array_filter( $settings ) );
        ?>
        <style>
            .lcni-cc-wrap      { max-width: 860px; }
            .lcni-cc-summary   { display: flex; gap: 12px; margin: 12px 0 20px; flex-wrap: wrap; }
            .lcni-cc-badge     { padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; }
            .lcni-cc-badge-on  { background: #ecf9f1; border: 1px solid #6ee7b7; color: #065f46; }
            .lcni-cc-badge-off { background: #fff1f0; border: 1px solid #fca5a5; color: #991b1b; }
            .lcni-cc-card      { background: #fff; border: 1px solid #dcdcde; border-radius: 8px;
                                  padding: 16px 20px; margin-bottom: 12px; display: flex;
                                  align-items: flex-start; gap: 16px; transition: border-color .15s; }
            .lcni-cc-card:hover{ border-color: #2271b1; }
            .lcni-cc-card-body { flex: 1; }
            .lcni-cc-card-title{ font-size: 14px; font-weight: 600; color: #1d2327; margin: 0 0 4px; }
            .lcni-cc-card-desc { font-size: 12px; color: #50575e; margin: 0; line-height: 1.6; }
            .lcni-cc-toggle    { position: relative; display: inline-block; width: 44px; height: 24px;
                                  flex-shrink: 0; margin-top: 2px; }
            .lcni-cc-toggle input          { opacity: 0; width: 0; height: 0; }
            .lcni-cc-slider    { position: absolute; cursor: pointer; inset: 0;
                                  background: #c3c4c7; border-radius: 24px; transition: .25s; }
            .lcni-cc-slider:before{ content: ''; position: absolute; height: 18px; width: 18px;
                                    left: 3px; bottom: 3px; background: #fff; border-radius: 50%;
                                    transition: .25s; }
            .lcni-cc-toggle input:checked + .lcni-cc-slider            { background: #2271b1; }
            .lcni-cc-toggle input:checked + .lcni-cc-slider:before     { transform: translateX(20px); }
            .lcni-cc-status-pill{ display: inline-block; padding: 2px 8px; border-radius: 999px;
                                    font-size: 11px; font-weight: 600; margin-left: 8px; }
            .lcni-cc-on  { background: #ecf9f1; color: #065f46; }
            .lcni-cc-off { background: #fff1f0; color: #991b1b; }
            .lcni-cc-warning{ background: #fff8e5; border: 1px solid #f0d898; border-radius: 6px;
                               padding: 10px 14px; margin-bottom: 20px; font-size: 13px; color: #50575e; }
            .lcni-cc-actions { margin-top: 20px; display: flex; gap: 10px; }
        </style>

        <div class="lcni-cc-wrap">

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] === 'success' ? 'success' : 'error' ); ?> is-dismissible">
                    <p><?php echo esc_html( $notice['message'] ); ?></p>
                </div>
            <?php endif; ?>

            <h2>Compute Control</h2>
            <p>Bật / tắt từng nhóm cron và tác vụ tính toán của plugin. Thay đổi có hiệu lực ngay khi lưu.</p>

            <div class="lcni-cc-warning">
                ⚠ <strong>Lưu ý:</strong> Tắt một nhóm sẽ <strong>xoá lịch cron đang pending</strong> ngay lập tức.
                Bật lại sẽ re-schedule ở lần request tiếp theo. Nếu đang dùng
                <strong>Python Local Sync</strong>, hãy tắt tất cả nhóm trừ những nhóm bạn cần.
            </div>

            <!-- Summary badges -->
            <div class="lcni-cc-summary">
                <span class="lcni-cc-badge lcni-cc-badge-on">
                    ✓ <?php echo count( $groups_on ); ?> nhóm đang BẬT
                </span>
                <span class="lcni-cc-badge lcni-cc-badge-off">
                    ✗ <?php echo count( $groups_off ); ?> nhóm đang TẮT
                </span>
            </div>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=lcni-settings' ) ); ?>">
                <?php wp_nonce_field( 'lcni_compute_control_save', 'lcni_compute_control_nonce' ); ?>
                <input type="hidden" name="lcni_compute_control_save" value="1">
                <input type="hidden" name="lcni_redirect_tab" value="compute_control">

                <?php foreach ( self::GROUPS as $key => $label ) :
                    $is_on = ! empty( $settings[ $key ] );
                    $desc  = self::DESCRIPTIONS[ $key ] ?? '';
                ?>
                <div class="lcni-cc-card">
                    <!-- Toggle switch -->
                    <label class="lcni-cc-toggle" title="<?php echo esc_attr( $is_on ? 'Đang BẬT – click để tắt' : 'Đang TẮT – click để bật' ); ?>">
                        <input type="checkbox"
                               name="lcni_compute[<?php echo esc_attr( $key ); ?>]"
                               value="1"
                               <?php checked( $is_on ); ?>
                               onchange="this.closest('.lcni-cc-card').querySelector('.lcni-cc-status-pill').textContent = this.checked ? 'BẬT' : 'TẮT';
                                         this.closest('.lcni-cc-card').querySelector('.lcni-cc-status-pill').className = 'lcni-cc-status-pill ' + (this.checked ? 'lcni-cc-on' : 'lcni-cc-off');">
                        <span class="lcni-cc-slider"></span>
                    </label>

                    <!-- Info -->
                    <div class="lcni-cc-card-body">
                        <p class="lcni-cc-card-title">
                            <?php echo esc_html( $label ); ?>
                            <span class="lcni-cc-status-pill <?php echo $is_on ? 'lcni-cc-on' : 'lcni-cc-off'; ?>">
                                <?php echo $is_on ? 'BẬT' : 'TẮT'; ?>
                            </span>
                        </p>
                        <?php if ( $desc ) : ?>
                            <p class="lcni-cc-card-desc"><?php echo wp_kses( $desc, [ 'code' => [] ] ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="lcni-cc-actions">
                    <?php submit_button( 'Lưu cài đặt', 'primary', 'submit', false ); ?>
                    <button type="button" class="button button-secondary" id="lcni-cc-disable-all">Tắt tất cả</button>
                    <button type="button" class="button button-secondary" id="lcni-cc-enable-all">Bật tất cả</button>
                </div>
            </form>
        </div>

        <script>
        (function(){
            const disableAll = document.getElementById('lcni-cc-disable-all');
            const enableAll  = document.getElementById('lcni-cc-enable-all');

            const setAll = (state) => {
                document.querySelectorAll('.lcni-cc-toggle input[type=checkbox]').forEach((cb) => {
                    cb.checked = state;
                    const pill = cb.closest('.lcni-cc-card').querySelector('.lcni-cc-status-pill');
                    if (pill) {
                        pill.textContent  = state ? 'BẬT' : 'TẮT';
                        pill.className    = 'lcni-cc-status-pill ' + (state ? 'lcni-cc-on' : 'lcni-cc-off');
                    }
                });
            };

            if (disableAll) disableAll.addEventListener('click', () => setAll(false));
            if (enableAll)  enableAll.addEventListener('click',  () => setAll(true));
        })();
        </script>
        <?php
    }
}
