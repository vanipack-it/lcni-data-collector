<?php
/**
 * LCNI_Table_Config  — Unified table appearance manager
 *
 * Đọc config từ một option duy nhất `lcni_global_table_config`
 * và inject CSS custom properties vào <head> một lần duy nhất.
 * Tất cả module (filter, watchlist, signal, portfolio...) đọc
 * cùng các biến --lcni-table-* này.
 *
 * Usage:
 *   LCNI_Table_Config::get()           // instance singleton
 *   LCNI_Table_Config::get_config()    // array config
 *   LCNI_Table_Config::css_vars()      // string CSS vars (dùng trong inline style)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Table_Config {

    const OPTION_KEY = 'lcni_global_table_config';

    // ── Defaults ──────────────────────────────────────────────────────────────
    const DEFAULTS = [
        // Header
        'header_bg'          => '#f3f4f6',
        'header_color'       => '#111827',
        'header_font_size'   => 12,      // px
        'header_height'      => 42,      // px

        // Row / Cell
        'row_bg'             => '#ffffff',
        'row_color'          => '#111827',
        'row_font_size'      => 13,      // px
        'row_height'         => 36,      // px

        // Divider (border-bottom giữa các row)
        'row_divider_color'  => '#e5e7eb',
        'row_divider_width'  => 1,       // px

        // Hover
        'row_hover_bg'       => '#eef2ff',

        // Scroll container
        'max_height'         => 70,      // vh — chiều cao cuộn tối đa

        // Sticky behaviour (boolean)
        'table_sticky_header'        => true,
        'table_sticky_first_column'  => true,
    ];

    /** @var LCNI_Table_Config|null */
    private static $instance = null;

    /** @var array */
    private $config;

    private function __construct() {
        $saved        = get_option( self::OPTION_KEY, [] );
        $this->config = $this->merge( is_array( $saved ) ? $saved : [] );
    }

    // ── Singleton ─────────────────────────────────────────────────────────────

    public static function get(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Shortcut tĩnh để lấy config array */
    public static function get_config(): array {
        return self::get()->config;
    }

    // ── Save / Sanitize ───────────────────────────────────────────────────────

    /**
     * Sanitize và save config mới. Trả về array đã sanitize.
     */
    public static function save( array $raw ): array {
        $instance = self::get();
        $clean    = $instance->sanitize( $raw );
        update_option( self::OPTION_KEY, $clean );
        $instance->config  = $clean;
        self::$instance    = $instance;
        return $clean;
    }

    // ── CSS output ────────────────────────────────────────────────────────────

    /**
     * Đăng ký hook để inject CSS vars — gọi một lần trong plugin init.
     *
     * Dùng wp_enqueue_scripts priority 20 (sau khi lcni-ui-table.css đã registered)
     * để append vars qua wp_add_inline_style → đảm bảo config vars nằm SAU
     * :root block trong lcni-ui-table.css và thắng cascade.
     */
    public static function register_wp_head(): void {
        add_action( 'wp_enqueue_scripts', [ self::get(), 'output_css_vars' ], 20 );
    }

    /**
     * Append CSS vars vào sau lcni-ui-table.css qua wp_add_inline_style.
     * Chạy ở wp_enqueue_scripts priority 20 — sau khi lcni-ui-table đã được register.
     */
    public function output_css_vars(): void {
        $vars = $this->build_css_vars();
        // wp_add_inline_style appends <style> ngay sau <link> của lcni-ui-table
        // → CSS vars từ config thắng :root fallbacks trong file CSS
        wp_add_inline_style( 'lcni-ui-table', ":root {\n" . $vars . "}" );
    }

    /**
     * Trả về chuỗi CSS vars để nhúng vào inline style của wrapper element.
     * Dùng khi cần scoped override (không muốn :root global).
     *
     * Example: style="<?php echo LCNI_Table_Config::inline_vars(); ?>"
     */
    public static function inline_vars(): string {
        return self::get()->build_css_vars( false );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function merge( array $saved ): array {
        return array_merge( self::DEFAULTS, array_intersect_key( $saved, self::DEFAULTS ) );
    }

    private function sanitize( array $raw ): array {
        $d = self::DEFAULTS;
        return [
            'header_bg'         => $this->sanitize_color( $raw['header_bg']         ?? $d['header_bg'] ),
            'header_color'      => $this->sanitize_color( $raw['header_color']      ?? $d['header_color'] ),
            'header_font_size'  => $this->clamp_int( $raw['header_font_size']  ?? $d['header_font_size'],  9, 30 ),
            'header_height'     => $this->clamp_int( $raw['header_height']     ?? $d['header_height'],    24, 80 ),
            'row_bg'            => $this->sanitize_color( $raw['row_bg']            ?? $d['row_bg'] ),
            'row_color'         => $this->sanitize_color( $raw['row_color']         ?? $d['row_color'] ),
            'row_font_size'     => $this->clamp_int( $raw['row_font_size']     ?? $d['row_font_size'],     9, 30 ),
            'row_height'        => $this->clamp_int( $raw['row_height']        ?? $d['row_height'],       20, 80 ),
            'row_divider_color' => $this->sanitize_color( $raw['row_divider_color'] ?? $d['row_divider_color'] ),
            'row_divider_width' => $this->clamp_int( $raw['row_divider_width'] ?? $d['row_divider_width'],  0,  6 ),
            'row_hover_bg'      => $this->sanitize_color( $raw['row_hover_bg']      ?? $d['row_hover_bg'] ),
            'max_height'        => $this->clamp_int( $raw['max_height']        ?? $d['max_height'],       20, 100 ),
            'table_sticky_header'        => ! empty( $raw['table_sticky_header'] ),
            'table_sticky_first_column'  => ! empty( $raw['table_sticky_first_column'] ),
        ];
    }

    /**
     * Build CSS custom property declarations.
     *
     * @param bool $indented true → indented (for :root block), false → inline (for style attr)
     */
    private function build_css_vars( bool $indented = true ): string {
        $c    = $this->config;
        $sep  = $indented ? "\n    " : '';
        $end  = $indented ? ";\n" : ';';

        $lines = [
            "--lcni-table-header-bg:{$c['header_bg']}",
            "--lcni-table-header-color:{$c['header_color']}",
            "--lcni-table-header-size:{$c['header_font_size']}px",
            "--lcni-table-header-height:{$c['header_height']}px",
            "--lcni-table-value-bg:{$c['row_bg']}",
            "--lcni-table-value-color:{$c['row_color']}",
            "--lcni-table-value-size:{$c['row_font_size']}px",
            "--lcni-table-row-height:{$c['row_height']}px",
            "--lcni-row-divider-color:{$c['row_divider_color']}",
            "--lcni-row-divider-width:{$c['row_divider_width']}px",
            "--lcni-row-hover-bg:{$c['row_hover_bg']}",
            "--lcni-table-max-height:{$c['max_height']}vh",
            // Watchlist compat aliases — map sang cùng giá trị
            "--lcni-watchlist-header-bg:{$c['header_bg']}",
            "--lcni-watchlist-header-color:{$c['header_color']}",
            "--lcni-watchlist-label-font-size:{$c['header_font_size']}px",
            "--lcni-watchlist-head-height:{$c['header_height']}px",
            "--lcni-watchlist-value-bg:{$c['row_bg']}",
            "--lcni-watchlist-value-color:{$c['row_color']}",
            "--lcni-watchlist-row-font-size:{$c['row_font_size']}px",
            "--lcni-watchlist-row-divider-color:{$c['row_divider_color']}",
            "--lcni-watchlist-row-divider-width:{$c['row_divider_width']}px",
            "--lcni-watchlist-row-hover-bg:{$c['row_hover_bg']}",
        ];

        if ( $indented ) {
            return '    ' . implode( ";\n    ", $lines ) . ";\n";
        }
        return implode( ';', $lines ) . ';';
    }

    /**
     * Trả về true nếu global config bật sticky header.
     * Dùng trong mọi module thay vì đọc per-module sticky_header.
     */
    public static function sticky_header(): bool {
        return ! empty( self::get_config()['table_sticky_header'] );
    }

    /**
     * Trả về true nếu global config bật sticky first column.
     */
    public static function sticky_first_col(): bool {
        return ! empty( self::get_config()['table_sticky_first_column'] );
    }

    /**
     * Trả về class string cho <table>: 'lcni-table' + has-sticky-header nếu bật.
     * @param string $extra  Extra classes (e.g. 'signal-table')
     */
    public static function table_class( string $extra = '' ): string {
        $cls = 'lcni-table';
        if ( $extra !== '' ) $cls .= ' ' . $extra;
        if ( self::sticky_header() ) $cls .= ' has-sticky-header';
        return $cls;
    }

    /**
     * Trả về class string cho th/td tại $index.
     * @param int    $index      Column index (0-based)
     * @param string $base_class Base class (e.g. 'lcni-table-cell')
     */
    public static function cell_class( int $index, string $base_class = '' ): string {
        $cls = $base_class;
        if ( self::sticky_first_col() && $index === 0 ) {
            $cls .= ( $cls !== '' ? ' ' : '' ) . 'is-sticky-col';
        }
        return $cls;
    }

        private function sanitize_color( $value ): string {
        $value = trim( (string) $value );
        // Chấp nhận hex (#xxx, #xxxxxx), rgb(), rgba(), hsl(), named colors
        if ( preg_match( '/^(#[0-9a-fA-F]{3,8}|rgb[a]?\([^)]+\)|hsl[a]?\([^)]+\)|[a-z]+)$/', $value ) ) {
            return $value;
        }
        return '#000000';
    }

    private function clamp_int( $value, int $min, int $max ): int {
        return max( $min, min( $max, (int) $value ) );
    }
}
