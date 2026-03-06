<?php

if (!defined('ABSPATH')) {
    exit;
}

class LCNI_Industry_Data_Page {

    const PAGE_SLUG = 'lcni-industry-data';
    const ACTION_HANDLE = 'lcni_industry_data_action';
    const NONCE_ACTION = 'lcni_industry_data_nonce_action';

    const TAB_MATERIALIZED = 'materialized';
    const TAB_INDEX = 'index';
    const TAB_RETURN = 'return';
    const TAB_METRICS = 'metrics';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::ACTION_HANDLE, [$this, 'handle_actions']);
        add_action('wp_ajax_lcni_industry_rebuild_chunk', [$this, 'handle_ajax_rebuild_chunk']);
    }

    public function register_menu() {
        add_submenu_page(
            'lcni-settings',
            'Industry Data',
            'Industry Data',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function handle_actions() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized request.', 'lcni-data-collector'));
        }

        check_admin_referer(self::NONCE_ACTION);

        $control_action = isset($_POST['lcni_industry_action']) ? sanitize_key(wp_unslash($_POST['lcni_industry_action'])) : '';
        $message = '';
        $type = 'success';

        if ($control_action === 'rebuild_industry_tables') {
            $result = LCNI_DB::rebuild_industry_analysis_snapshot_chunked(['1D'], 5, true);

            if (!empty($result['processed']) || (!empty($result['done']) && ($result['status'] ?? '') === 'completed')) {
                if (!empty($result['done'])) {
                    $message = sprintf(
                        'Đã rebuild hoàn tất dữ liệu ngành theo lô. Số phiên xử lý: %d/%d.',
                        (int) ($result['total'] ?? 0),
                        (int) ($result['total'] ?? 0)
                    );
                } else {
                    $processed = max(0, (int) ($result['total'] ?? 0) - (int) ($result['remaining'] ?? 0));
                    $message = sprintf(
                        'Đã khởi động rebuild dữ liệu ngành theo lô để giảm tải. Tiến độ: %d/%d phiên, còn lại %d phiên. Bấm nút thêm để chạy lô kế tiếp.',
                        $processed,
                        (int) ($result['total'] ?? 0),
                        (int) ($result['remaining'] ?? 0)
                    );
                }
            } elseif (($result['status'] ?? '') === 'empty_source') {
                $type = 'error';
                $message = 'Không có dữ liệu nguồn để rebuild dữ liệu ngành.';
            } else {
                $type = 'error';
                $message = 'Không rebuild được dữ liệu ngành. Hãy kiểm tra bảng OHLC, mapping ngành hoặc dữ liệu nguồn đầu vào.';
            }
        } elseif ($control_action === 'rebuild_industry_tables_continue') {
            $result = LCNI_DB::rebuild_industry_analysis_snapshot_chunked(['1D'], 5, false);

            if (($result['status'] ?? '') === 'completed') {
                $message = sprintf(
                    'Đã rebuild hoàn tất dữ liệu ngành theo lô. Số phiên xử lý: %d/%d.',
                    (int) ($result['total'] ?? 0),
                    (int) ($result['total'] ?? 0)
                );
            } elseif (($result['status'] ?? '') === 'in_progress') {
                $processed = max(0, (int) ($result['total'] ?? 0) - (int) ($result['remaining'] ?? 0));
                $message = sprintf(
                    'Đã chạy thêm 1 lô rebuild dữ liệu ngành. Tiến độ: %d/%d phiên, còn lại %d phiên.',
                    $processed,
                    (int) ($result['total'] ?? 0),
                    (int) ($result['remaining'] ?? 0)
                );
            } else {
                $type = 'error';
                $message = 'Không thể tiếp tục rebuild theo lô. Hãy thử chạy lại từ đầu.';
            }
        } else {
            $type = 'error';
            $message = 'Unknown action.';
        }

        $redirect = add_query_arg(
            [
                'page' => self::PAGE_SLUG,
                'lcni_notice' => rawurlencode($message),
                'lcni_notice_type' => $type,
            ],
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_ajax_rebuild_chunk() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => 'Unauthorized request.',
            ], 403);
        }

        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        $is_start = isset($_POST['is_start']) && sanitize_key(wp_unslash($_POST['is_start'])) === '1';
        $result = LCNI_DB::rebuild_industry_analysis_snapshot_chunked(['1D'], 5, $is_start);

        $status = (string) ($result['status'] ?? 'unknown');
        if ($status === 'missing_tables') {
            wp_send_json_error([
                'status' => $status,
                'message' => 'Không thể rebuild vì thiếu bảng dữ liệu ngành.',
            ]);
        }

        if ($status === 'empty_source') {
            wp_send_json_error([
                'status' => $status,
                'message' => 'Không có dữ liệu nguồn để rebuild dữ liệu ngành.',
            ]);
        }

        if ($status !== 'in_progress' && $status !== 'completed') {
            wp_send_json_error([
                'status' => $status,
                'message' => 'Không thể tiếp tục rebuild theo lô. Hãy thử chạy lại từ đầu.',
            ]);
        }

        $total = (int) ($result['total'] ?? 0);
        $remaining = max(0, (int) ($result['remaining'] ?? 0));
        $processed = max(0, $total - $remaining);

        wp_send_json_success([
            'status' => $status,
            'total' => $total,
            'remaining' => $remaining,
            'processed' => $processed,
        ]);
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $stats = LCNI_DB::get_industry_analysis_table_stats();
        $rebuild_progress = LCNI_DB::get_industry_rebuild_progress();
        global $wpdb;
        $table_prefix = (string) $wpdb->prefix;
        $table_stats_by_name = $this->index_stats_by_table_name($stats);

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : self::TAB_MATERIALIZED;
        $allowed_tabs = [
            self::TAB_MATERIALIZED,
            self::TAB_INDEX,
            self::TAB_RETURN,
            self::TAB_METRICS,
        ];
        if (!in_array($tab, $allowed_tabs, true)) {
            $tab = self::TAB_MATERIALIZED;
        }

        $notice = isset($_GET['lcni_notice']) ? sanitize_text_field(wp_unslash($_GET['lcni_notice'])) : '';
        $notice_type = isset($_GET['lcni_notice_type']) ? sanitize_key(wp_unslash($_GET['lcni_notice_type'])) : 'success';
        $notice_css = $notice_type === 'error' ? 'notice notice-error' : 'notice notice-success';

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Industry Data', 'lcni-data-collector'); ?></h1>

            <?php if ($notice !== '') : ?>
                <div class="<?php echo esc_attr($notice_css); ?> is-dismissible"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <p><?php echo esc_html__('Trang này giúp kiểm tra nhanh tình trạng dữ liệu ngành và chạy rebuild thủ công khi bảng dữ liệu đang trống.', 'lcni-data-collector'); ?></p>

            <h2 class="nav-tab-wrapper" style="max-width: 980px;">
                <?php
                $tabs = [
                    self::TAB_MATERIALIZED => 'Materialized',
                    self::TAB_INDEX => 'Index',
                    self::TAB_RETURN => 'Return',
                    self::TAB_METRICS => 'Metrics',
                ];
                foreach ($tabs as $tab_key => $tab_label) {
                    $tab_url = add_query_arg(
                        [
                            'page' => self::PAGE_SLUG,
                            'tab' => $tab_key,
                        ],
                        admin_url('admin.php')
                    );
                    $active_class = $tab === $tab_key ? ' nav-tab-active' : '';
                    ?>
                    <a href="<?php echo esc_url($tab_url); ?>" class="nav-tab<?php echo esc_attr($active_class); ?>"><?php echo esc_html($tab_label); ?></a>
                    <?php
                }
                ?>
            </h2>

            <?php if ($tab === self::TAB_MATERIALIZED) : ?>
                <h2><?php echo esc_html__('Industry Materialized Tables', 'lcni-data-collector'); ?></h2>
                <?php $this->render_stats_table($stats); ?>
            <?php elseif ($tab === self::TAB_INDEX) : ?>
                <h2><?php echo esc_html__('Industry Index Table', 'lcni-data-collector'); ?></h2>
                <?php $this->render_stats_table([$table_stats_by_name[$table_prefix . 'lcni_industry_index'] ?? null]); ?>
                <?php $this->render_table_data($table_prefix . 'lcni_industry_index'); ?>
            <?php elseif ($tab === self::TAB_RETURN) : ?>
                <h2><?php echo esc_html__('Industry Return Table', 'lcni-data-collector'); ?></h2>
                <?php $this->render_stats_table([$table_stats_by_name[$table_prefix . 'lcni_industry_return'] ?? null]); ?>
                <?php $this->render_table_data($table_prefix . 'lcni_industry_return'); ?>
            <?php else : ?>
                <h2><?php echo esc_html__('Industry Metrics Table', 'lcni-data-collector'); ?></h2>
                <?php $this->render_stats_table([$table_stats_by_name[$table_prefix . 'lcni_industry_metrics'] ?? null]); ?>
                <?php $this->render_table_data($table_prefix . 'lcni_industry_metrics'); ?>
            <?php endif; ?>

            <h2 style="margin-top: 24px;"><?php echo esc_html__('Manual Controls', 'lcni-data-collector'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_HANDLE); ?>">
                <p>
                    <button type="submit" class="button button-primary" name="lcni_industry_action" value="rebuild_industry_tables" id="lcni-industry-start-chunked-rebuild">
                        <?php echo esc_html__('Start Chunked Rebuild Industry Tables', 'lcni-data-collector'); ?>
                    </button>
                    <button type="submit" class="button" name="lcni_industry_action" value="rebuild_industry_tables_continue" style="margin-left: 8px;">
                        <?php echo esc_html__('Run Next Chunk', 'lcni-data-collector'); ?>
                    </button>
                </p>
            </form>

            <?php if (!empty($rebuild_progress)) : ?>
                <p>
                    <?php
                    echo esc_html(sprintf(
                        'Chunk progress: %d/%d phiên đã xử lý, còn lại %d phiên.',
                        (int) ($rebuild_progress['processed'] ?? 0),
                        (int) ($rebuild_progress['total'] ?? 0),
                        (int) ($rebuild_progress['remaining'] ?? 0)
                    ));
                    ?>
                </p>
            <?php endif; ?>

            <p id="lcni-industry-rebuild-auto-status" style="display:none;"></p>
        </div>
        <script>
            (function () {
                const startButton = document.getElementById('lcni-industry-start-chunked-rebuild');
                const statusEl = document.getElementById('lcni-industry-rebuild-auto-status');

                if (!startButton || !statusEl) {
                    return;
                }

                const form = startButton.closest('form');
                const nonceField = form ? form.querySelector('input[name="_wpnonce"]') : null;

                if (!form || !nonceField || !nonceField.value) {
                    return;
                }

                const originalStartText = startButton.textContent;

                const setStatus = function (message, isError) {
                    statusEl.style.display = 'block';
                    statusEl.style.color = isError ? '#b32d2e' : '#1d2327';
                    statusEl.textContent = message;
                };

                const setWorkingState = function (working) {
                    startButton.disabled = working;
                    if (working) {
                        startButton.textContent = 'Running...';
                    } else {
                        startButton.textContent = originalStartText;
                    }
                };

                const runChunk = async function (isStart) {
                    const body = new URLSearchParams();
                    body.append('action', 'lcni_industry_rebuild_chunk');
                    body.append('nonce', nonceField.value);
                    body.append('is_start', isStart ? '1' : '0');

                    const response = await fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                        },
                        body: body.toString(),
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const payload = await response.json();
                    if (!payload || typeof payload !== 'object') {
                        throw new Error('Invalid response payload.');
                    }

                    if (!payload.success) {
                        const message = payload.data && payload.data.message ? payload.data.message : 'Không thể chạy rebuild theo lô.';
                        throw new Error(message);
                    }

                    return payload.data || {};
                };

                startButton.addEventListener('click', async function (event) {
                    event.preventDefault();

                    setWorkingState(true);
                    setStatus('Đang chạy tự động theo từng lô, vui lòng chờ...', false);

                    try {
                        let isStart = true;
                        let loopGuard = 0;

                        while (loopGuard < 10000) {
                            const result = await runChunk(isStart);
                            const processed = Number(result.processed || 0);
                            const total = Number(result.total || 0);
                            const remaining = Number(result.remaining || 0);
                            const status = String(result.status || 'unknown');

                            setStatus('Chunk progress: ' + processed + '/' + total + ' phiên đã xử lý, còn lại ' + remaining + ' phiên.', false);

                            if (status === 'completed' || remaining <= 0) {
                                setStatus('Đã rebuild hoàn tất dữ liệu ngành theo lô. Số phiên xử lý: ' + total + '/' + total + '.', false);
                                window.location.reload();
                                return;
                            }

                            if (status !== 'in_progress') {
                                throw new Error('Không thể tiếp tục rebuild theo lô.');
                            }

                            isStart = false;
                            loopGuard += 1;
                        }

                        throw new Error('Đã vượt quá số lô cho phép trong một lần chạy tự động.');
                    } catch (error) {
                        const message = error && error.message ? error.message : 'Không thể chạy rebuild theo lô.';
                        setStatus(message, true);
                    } finally {
                        setWorkingState(false);
                    }
                });
            })();
        </script>
        <?php
    }

    private function index_stats_by_table_name($stats) {
        $indexed = [];

        foreach ((array) $stats as $item) {
            if (!is_array($item)) {
                continue;
            }

            $table_name = isset($item['table']) ? (string) $item['table'] : '';
            if ($table_name === '') {
                continue;
            }

            $indexed[$table_name] = $item;
        }

        return $indexed;
    }

    private function render_stats_table($rows) {
        ?>
        <table class="widefat striped" style="max-width: 980px;">
            <thead>
            <tr>
                <th><?php echo esc_html__('Table', 'lcni-data-collector'); ?></th>
                <th><?php echo esc_html__('Exists', 'lcni-data-collector'); ?></th>
                <th><?php echo esc_html__('Rows', 'lcni-data-collector'); ?></th>
                <th><?php echo esc_html__('Latest event_time', 'lcni-data-collector'); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php
            $has_rows = false;
            foreach ((array) $rows as $item) :
                if (!is_array($item)) {
                    continue;
                }
                $has_rows = true;
                ?>
                <tr>
                    <td><code><?php echo esc_html((string) ($item['table'] ?? '')); ?></code></td>
                    <td><?php echo esc_html(!empty($item['exists']) ? 'Yes' : 'No'); ?></td>
                    <td><?php echo esc_html((string) ((int) ($item['rows'] ?? 0))); ?></td>
                    <td><?php echo esc_html((string) ((int) ($item['latest_event_time'] ?? 0))); ?></td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$has_rows) : ?>
                <tr>
                    <td colspan="4"><?php echo esc_html__('No table statistics found.', 'lcni-data-collector'); ?></td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_table_data($table_name, $limit = 100) {
        global $wpdb;

        $table = sanitize_text_field((string) $table_name);
        $safe_limit = max(1, (int) $limit);

        if ($table === '') {
            return;
        }

        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`", 0);

        if (empty($columns)) {
            echo '<p>' . esc_html__('Không đọc được danh sách cột hoặc bảng không tồn tại.', 'lcni-data-collector') . '</p>';
            return;
        }

        $rows = $wpdb->get_results("SELECT * FROM `{$table}` ORDER BY event_time DESC LIMIT {$safe_limit}", ARRAY_A);
        ?>
        <h3 style="margin-top: 16px;"><?php echo esc_html(sprintf('Latest %d rows', $safe_limit)); ?></h3>
        <div style="max-width: 100%; overflow-x: auto;">
            <table class="widefat striped" style="min-width: 980px;">
                <thead>
                <tr>
                    <?php foreach ($columns as $column) : ?>
                        <th><code><?php echo esc_html((string) $column); ?></code></th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($rows)) : ?>
                    <tr>
                        <td colspan="<?php echo esc_attr((string) count($columns)); ?>"><?php echo esc_html__('No data found.', 'lcni-data-collector'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <?php foreach ($columns as $column) : ?>
                                <td><?php echo esc_html((string) ($row[$column] ?? '')); ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
