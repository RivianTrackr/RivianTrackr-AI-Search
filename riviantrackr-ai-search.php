<?php
/**
 * Plugin Name: RivianTrackr AI Search
 * Description: Adds AI-generated summaries to WordPress search results.
 * Version: 3.2.3
 * Author: RivianTrackr
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RT_AI_SEARCH_VERSION', '3.2.3' );
define( 'RT_AI_SEARCH_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );

class RivianTrackr_AI_Search {

    private $cache_prefix;
    private $logs_table;

    public function __construct() {
        global $wpdb;

        $this->cache_prefix =
            'rt_ai_search_v' .
            str_replace( '.', '_', RT_AI_SEARCH_VERSION ) .
            '_ns' . $this->get_cache_namespace() . '_';

        $this->logs_table = $wpdb->prefix . 'rt_ai_search_logs';

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'wp_footer', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

        register_activation_hook( __FILE__, array( $this, 'create_logs_table' ) );
    }

    public function enqueue_frontend_assets() {
        if ( is_admin() || ! is_search() ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            return;
        }

        $asset_base = plugin_dir_url( __FILE__ ) . 'assets/';

        wp_enqueue_style(
            'rt-ai-search',
            $asset_base . 'rt-ai-search.css',
            array(),
            RT_AI_SEARCH_VERSION
        );

        wp_enqueue_script(
            'rt-ai-search',
            $asset_base . 'rt-ai-search.js',
            array(),
            RT_AI_SEARCH_VERSION,
            true
        );

        wp_localize_script(
            'rt-ai-search',
            'RTAISearch',
            array(
                'endpoint' => rest_url( 'rt-ai-search/v1/summary' ),
                'query'    => get_search_query(),
            )
        );
    }

    public function inject_ai_summary_placeholder() {
        if ( ! is_search() ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            return;
        }

        echo '<div id="rt-ai-search-summary" class="rt-ai-search-summary"></div>';
    }

    public function register_rest_routes() {
        register_rest_route(
            'rt-ai-search/v1',
            '/summary',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'handle_summary_request' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function handle_summary_request( $request ) {
        $query = sanitize_text_field( $request->get_param( 'query' ) );
        if ( empty( $query ) ) {
            return new WP_REST_Response( array( 'error' => 'Empty search query.' ), 400 );
        }

        $cache_key = $this->cache_prefix . md5( $query );
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            return new WP_REST_Response( $cached, 200 );
        }

        $response = array(
            'answer_html' => '<p>AI summary placeholder</p>',
            'sources'     => array(),
        );

        set_transient( $cache_key, $response, HOUR_IN_SECONDS );
        $this->log_search( $query );

        return new WP_REST_Response( $response, 200 );
    }

    private function log_search( $query ) {
        global $wpdb;

        if ( ! $this->logs_table_is_available() ) {
            return;
        }

        $wpdb->insert(
            $this->logs_table,
            array(
                'search_query' => $query,
                'created_at'   => current_time( 'mysql' ),
            ),
            array( '%s', '%s' )
        );
    }

    private function logs_table_is_available() {
        global $wpdb;

        return $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $this->logs_table
            )
        ) === $this->logs_table;
    }

    public function create_logs_table() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->logs_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            search_query text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY created_at (created_at)
        ) $charset_collate;";

        dbDelta( $sql );
    }

    private function get_options() {
        return wp_parse_args(
            get_option( 'rt_ai_search_options', array() ),
            array(
                'enable'    => 1,
                'api_key'   => '',
                'model'     => 'gpt-4.1-mini',
                'max_posts' => 6,
            )
        );
    }

    private function get_cache_namespace() {
        return (int) get_option( 'rt_ai_search_cache_namespace', 1 );
    }
}

new RivianTrackr_AI_Search();
