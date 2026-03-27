<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * PushServiceWorkerEndpoint
 *
 * Serve /lcni-sw.js từ root domain.
 * Service Worker phải nằm ở root domain để có scope đầy đủ.
 *
 * Đăng ký trong plugin init bằng:
 *   new PushServiceWorkerEndpoint();
 */
class PushServiceWorkerEndpoint {

    public function __construct() {
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'add_query_var' ] );
        add_action( 'template_redirect', [ $this, 'serve_sw' ] );
    }

    public function add_rewrite_rule(): void {
        add_rewrite_rule( '^lcni-sw\.js$', 'index.php?lcni_sw=1', 'top' );
    }

    public function add_query_var( array $vars ): array {
        $vars[] = 'lcni_sw';
        return $vars;
    }

    public function serve_sw(): void {
        if ( ! get_query_var( 'lcni_sw' ) ) return;

        $sw_file = defined('LCNI_PATH')
            ? LCNI_PATH . 'lcni-sw.js'
            : plugin_dir_path( dirname( dirname( __FILE__ ) ) ) . 'lcni-sw.js';

        if ( ! file_exists( $sw_file ) ) {
            status_header( 404 );
            exit;
        }

        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Service-Worker-Allowed: /' );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        echo file_get_contents( $sw_file );
        exit;
    }
}
