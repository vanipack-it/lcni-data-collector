<?php
/**
 * Custom Index Repository — CRUD cho bảng lcni_custom_index
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LCNI_Custom_Index_Repository {

    private wpdb $wpdb;

    public function __construct( wpdb $wpdb ) {
        $this->wpdb = $wpdb;
        LCNI_Custom_Index_DB::ensure();
    }

    // ── Read ──────────────────────────────────────────────────────────────────

    public function all(): array {
        return (array) $this->wpdb->get_results(
            "SELECT * FROM " . LCNI_Custom_Index_DB::index_table() . " ORDER BY id DESC",
            ARRAY_A
        );
    }

    public function find( int $id ): ?array {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM " . LCNI_Custom_Index_DB::index_table() . " WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function get_active(): array {
        return (array) $this->wpdb->get_results(
            "SELECT * FROM " . LCNI_Custom_Index_DB::index_table() . " WHERE is_active = 1 ORDER BY id ASC",
            ARRAY_A
        );
    }

    // ── Write ─────────────────────────────────────────────────────────────────

    public function create( array $data ): int {
        $payload = $this->build_payload( $data );
        $this->wpdb->insert( LCNI_Custom_Index_DB::index_table(), $payload );
        return (int) $this->wpdb->insert_id;
    }

    public function update( int $id, array $data ): bool {
        $payload = $this->build_payload( $data );
        // Reset base khi thay đổi bộ lọc → cần backfill lại
        if ( $this->filter_changed( $id, $payload ) ) {
            $payload['base_event_time'] = null;
            $payload['base_value']      = null;
        }
        $result = $this->wpdb->update(
            LCNI_Custom_Index_DB::index_table(),
            $payload,
            [ 'id' => $id ]
        );
        return $result !== false;
    }

    public function delete( int $id ): bool {
        // Xóa cả OHLC data
        $this->wpdb->delete(
            LCNI_Custom_Index_DB::ohlc_table(),
            [ 'index_id' => $id ],
            [ '%d' ]
        );
        return (bool) $this->wpdb->delete(
            LCNI_Custom_Index_DB::index_table(),
            [ 'id' => $id ],
            [ '%d' ]
        );
    }

    public function reset_ohlc( int $id ): void {
        $this->wpdb->delete(
            LCNI_Custom_Index_DB::ohlc_table(),
            [ 'index_id' => $id ],
            [ '%d' ]
        );
        $this->wpdb->update(
            LCNI_Custom_Index_DB::index_table(),
            [ 'base_event_time' => null, 'base_value' => null ],
            [ 'id' => $id ]
        );
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    public function get_ohlc_count( int $id, string $timeframe = '1D' ): int {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM " . LCNI_Custom_Index_DB::ohlc_table() .
                " WHERE index_id = %d AND timeframe = %s",
                $id, strtoupper( $timeframe )
            )
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function build_payload( array $data ): array {
        $scope = in_array( $data['symbol_scope'] ?? 'all', [ 'all', 'watchlist', 'custom' ], true )
                 ? $data['symbol_scope'] : 'all';

        // Normalize custom list: uppercase, trim, deduplicate
        $custom_list = '';
        if ( $scope === 'custom' && ! empty( $data['scope_custom_list'] ) ) {
            $symbols     = array_values( array_unique( array_filter( array_map(
                static fn( $s ) => strtoupper( trim( sanitize_text_field( $s ) ) ),
                explode( ',', (string) $data['scope_custom_list'] )
            ) ) ) );
            $custom_list = implode( ',', $symbols );
        }

        return [
            'name'               => sanitize_text_field( (string) ( $data['name'] ?? '' ) ),
            'description'        => sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ),
            'exchange'           => strtoupper( sanitize_text_field( (string) ( $data['exchange'] ?? '' ) ) ) ?: null,
            'id_icb2'            => absint( $data['id_icb2'] ?? 0 ) ?: null,
            'symbol_scope'       => $scope,
            'scope_watchlist_id' => absint( $data['scope_watchlist_id'] ?? 0 ) ?: null,
            'scope_custom_list'  => $custom_list ?: null,
            'is_active'          => ! empty( $data['is_active'] ) ? 1 : 0,
        ];
    }

    private function filter_changed( int $id, array $new_payload ): bool {
        $existing = $this->find( $id );
        if ( ! $existing ) return false;
        $filter_fields = [ 'exchange', 'id_icb2', 'symbol_scope', 'scope_watchlist_id', 'scope_custom_list' ];
        foreach ( $filter_fields as $f ) {
            if ( ( $existing[ $f ] ?? null ) !== ( $new_payload[ $f ] ?? null ) ) return true;
        }
        return false;
    }
}
