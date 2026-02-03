<?php
declare(strict_types=1);
/**
 * Plugin Name: AI Search Summary
 * Plugin URI: https://github.com/josevarghese/AI-Search-Summary
 * Description: Add an OpenAI powered AI summary to WordPress search results without delaying normal results, with analytics, cache control, and collapsible sources.
 * Version: 4.0.1
 * Author: Developer
 * License: GPL v2 or later
 */

define( 'AI_SEARCH_VERSION', '4.0.1' );
define( 'AISS_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );
define( 'AISS_MIN_CACHE_TTL', 60 );
define( 'AISS_MAX_CACHE_TTL', 86400 );
define( 'AISS_DEFAULT_CACHE_TTL', 3600 );
define( 'AISS_CONTENT_LENGTH', 400 );
define( 'AISS_EXCERPT_LENGTH', 200 );
define( 'AISS_MAX_SOURCES_DISPLAY', 5 );
define( 'AISS_API_TIMEOUT', 60 );
define( 'AISS_RATE_LIMIT_WINDOW', 70 );
define( 'AISS_MAX_TOKENS', 1500 );
define( 'AISS_IP_RATE_LIMIT', 10 ); // Requests per minute per IP

// Error codes for structured API responses
define( 'AISS_ERROR_BOT_DETECTED', 'bot_detected' );
define( 'AISS_ERROR_RATE_LIMITED', 'rate_limited' );
define( 'AISS_ERROR_NOT_CONFIGURED', 'not_configured' );
define( 'AISS_ERROR_INVALID_QUERY', 'invalid_query' );
define( 'AISS_ERROR_API_ERROR', 'api_error' );
define( 'AISS_ERROR_NO_RESULTS', 'no_results' );


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AI_Search_Summary {

    private $option_name         = 'aiss_options';
    private $models_cache_option = 'aiss_models_cache';
    private $cache_keys_option      = 'aiss_cache_keys';
    private $cache_namespace_option = 'aiss_cache_namespace';
    private $cache_prefix;
    private $cache_ttl           = 3600;

    private $logs_table_checked = false;
    private $logs_table_exists  = false;
    private $options_cache      = null;

    public function __construct() {

        $this->cache_prefix = 'aiss_v' . str_replace( '.', '_', AI_SEARCH_VERSION ) . '_';

        // Register settings on admin_init (the recommended hook for Settings API)
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'maybe_run_migrations' ) );
        add_action( 'admin_init', array( $this, 'add_security_headers' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_post_dispatch', array( $this, 'add_rate_limit_headers' ), 10, 3 );
        add_action( 'wp_ajax_aiss_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_aiss_refresh_models', array( $this, 'ajax_refresh_models' ) );
        add_action( 'wp_ajax_aiss_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_print_styles-index.php', array( $this, 'enqueue_dashboard_widget_css' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_settings_link' ) );
    }

    /**
     * Add security headers for plugin admin pages.
     */
    public function add_security_headers() {
        // Only add headers on our plugin pages
        if ( ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'aiss' ) !== 0 ) {
            return;
        }

        // Prevent MIME type sniffing
        header( 'X-Content-Type-Options: nosniff' );

        // Prevent clickjacking - allow same origin only
        header( 'X-Frame-Options: SAMEORIGIN' );

        // Control referrer information
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );

        // Prevent XSS attacks in older browsers
        header( 'X-XSS-Protection: 1; mode=block' );
    }

    public function add_plugin_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=aiss-settings' );
        $settings_link = '<a href="' . esc_url( $url ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function enqueue_dashboard_widget_css() {
        wp_enqueue_style(
            'aiss-admin',
            plugin_dir_url( __FILE__ ) . 'assets/aiss-admin.css',
            array(),
            AI_SEARCH_VERSION
        );
    }

    private static function get_logs_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'aiss_logs';
    }

    /**
     * Get the logs table name with backtick escaping for use in queries.
     * Note: Do not use with dbDelta() which requires unquoted table names.
     *
     * @return string Backtick-escaped table name.
     */
    private static function get_escaped_table_name() {
        return '`' . self::get_logs_table_name() . '`';
    }

    private static function create_logs_table() {
        global $wpdb;

        $table_name      = self::get_logs_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            search_query text NOT NULL,
            results_count int unsigned NOT NULL DEFAULT 0,
            ai_success tinyint(1) NOT NULL DEFAULT 0,
            ai_error text NULL,
            cache_hit tinyint(1) NULL DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY search_query_created (search_query(100), created_at),
            KEY ai_success_created (ai_success, created_at),
            KEY cache_hit_created (cache_hit, created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    private static function add_missing_indexes() {
        global $wpdb;
        $table_name = self::get_logs_table_name();
        $table_escaped = self::get_escaped_table_name();

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        // Get existing indexes
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_escaped}" );
        $index_names = array();
        foreach ( $indexes as $index ) {
            $index_names[] = $index->Key_name;
        }

        // Add search_query_created index if missing
        if ( ! in_array( 'search_query_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE {$table_escaped}
                 ADD INDEX search_query_created (search_query(100), created_at)"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Added search_query_created index' );
            }
        }

        // Add ai_success_created index if missing
        if ( ! in_array( 'ai_success_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE {$table_escaped}
                 ADD INDEX ai_success_created (ai_success, created_at)"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Added ai_success_created index' );
            }
        }

        // Add cache_hit_created index if missing
        if ( ! in_array( 'cache_hit_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE {$table_escaped}
                 ADD INDEX cache_hit_created (cache_hit, created_at)"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Added cache_hit_created index' );
            }
        }

        return true;
    }

    private static function add_missing_columns() {
        global $wpdb;
        $table_name = self::get_logs_table_name();
        $table_escaped = self::get_escaped_table_name();

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        // Get existing columns
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table_escaped}" );
        $column_names = array();
        foreach ( $columns as $column ) {
            $column_names[] = $column->Field;
        }

        // Add cache_hit column if missing
        if ( ! in_array( 'cache_hit', $column_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE {$table_escaped}
                 ADD COLUMN cache_hit tinyint(1) NULL DEFAULT NULL AFTER ai_error"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Added cache_hit column' );
            }
        }

        return true;
    }

    public static function activate() {
        self::create_logs_table();
        self::add_missing_columns(); // Add columns to existing tables
        self::add_missing_indexes(); // Add indexes to existing tables
    }

    private function ensure_logs_table() {
        self::create_logs_table();
        self::add_missing_columns(); // Ensure columns exist
        self::add_missing_indexes(); // Ensure indexes exist
        $this->logs_table_checked = false;
        return $this->logs_table_is_available();
    }

    /**
     * Run database migrations if needed.
     * Called on admin_init to ensure schema is up to date.
     */
    public function maybe_run_migrations() {
        $db_version = get_option( 'aiss_db_version', '1.0' );

        // Version 1.1 adds cache_hit column
        if ( version_compare( $db_version, '1.1', '<' ) ) {
            self::add_missing_columns();
            self::add_missing_indexes();
            update_option( 'aiss_db_version', '1.1' );
        }
    }

    private function logs_table_is_available() {
        if ( $this->logs_table_checked ) {
            return $this->logs_table_exists;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        // Do not attempt to create or repair tables during normal requests.
        // Table creation is handled on plugin activation and via the explicit admin action.
        $this->logs_table_checked = true;
        $this->logs_table_exists  = ( $result === $table_name );

        return $this->logs_table_exists;
    }

    /**
     * Purge logs older than specified number of days.
     *
     * @param int $days Number of days to keep. Logs older than this will be deleted.
     * @return int|false Number of rows deleted, or false on failure.
     */
    private function purge_old_logs( $days = 30 ) {
        if ( ! $this->logs_table_is_available() ) {
            return false;
        }

        global $wpdb;
        $table_escaped = self::get_escaped_table_name();
        $cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table_escaped} WHERE created_at < %s",
                $cutoff_date
            )
        );

        return $deleted;
    }

    private function log_search_event( $search_query, $results_count, $ai_success, $ai_error = '', $cache_hit = null ) {
        if ( empty( $search_query ) ) {
            return;
        }

        if ( ! $this->logs_table_is_available() ) {
            return;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        $now = current_time( 'mysql' );

        $data = array(
            'search_query'  => $search_query,
            'results_count' => (int) $results_count,
            'ai_success'    => $ai_success ? 1 : 0,
            'ai_error'      => $ai_error,
            'cache_hit'     => $cache_hit,
            'created_at'    => $now,
        );

        $formats = array(
            '%s',
            '%d',
            '%d',
            '%s',
            $cache_hit === null ? null : '%d',
            '%s',
        );

        // Remove null format for cache_hit if null value
        if ( $cache_hit === null ) {
            $formats[4] = '%s'; // Will be stored as NULL
            $data['cache_hit'] = null;
        } else {
            $data['cache_hit'] = $cache_hit ? 1 : 0;
        }

        $result = $wpdb->insert(
            $table_name,
            $data,
            $formats
        );

        if ( false === $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[AI Search Summary] Failed to log search event: ' .
                $wpdb->last_error .
                ' | Query: ' . substr( $search_query, 0, 50 )
            );
        }
    }

    /**
     * Check if API key is defined via constant.
     *
     * @return bool True if API key constant is defined and not empty.
     */
    public function is_api_key_from_constant() {
        return defined( 'AISS_API_KEY' ) && ! empty( AISS_API_KEY );
    }

    public function get_options() {
        if ( is_array( $this->options_cache ) ) {
            return $this->options_cache;
        }

        $defaults = array(
            'api_key'              => '',
            'model'                => '',
            'max_posts'            => 20,
            'enable'               => 0,
            'max_calls_per_minute' => 30,
            'cache_ttl'            => AISS_DEFAULT_CACHE_TTL,
            'request_timeout'      => 60,
            'site_name'            => get_bloginfo( 'name' ),
            'site_description'     => '',
            'show_openai_badge'    => 1,
            'show_sources'         => 1,
            'color_background'     => '#121e2b',
            'color_text'           => '#e5e7eb',
            'color_accent'         => '#fba919',
            'color_border'         => '#94a3b8',
            'custom_css'           => '',
        );

        $opts = get_option( $this->option_name, array() );
        $this->options_cache = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

        // Override API key if defined via constant (more secure than database storage)
        if ( $this->is_api_key_from_constant() ) {
            $this->options_cache['api_key'] = AISS_API_KEY;
        }

        return $this->options_cache;
    }

    public function sanitize_options( $input ) {
        if (!is_array($input)) {
            $input = array();
        }
        
        $output = array();

        $output['api_key']   = isset($input['api_key']) ? sanitize_text_field( trim($input['api_key']) ) : '';
        $output['model']     = isset($input['model']) ? sanitize_text_field($input['model']) : '';
        $output['max_posts'] = isset($input['max_posts']) ? max(1, intval($input['max_posts'])) : 20;
        
        $output['enable'] = isset($input['enable']) && $input['enable'] ? 1 : 0;
        
        $output['max_calls_per_minute'] = isset($input['max_calls_per_minute'])
            ? max(0, intval($input['max_calls_per_minute']))
            : 30;
            
        if (isset($input['cache_ttl'])) {
            $ttl = intval($input['cache_ttl']);
            if ($ttl < AISS_MIN_CACHE_TTL) {
                $ttl = AISS_MIN_CACHE_TTL;
            } elseif ($ttl > AISS_MAX_CACHE_TTL) {
                $ttl = AISS_MAX_CACHE_TTL;
            }
            $output['cache_ttl'] = $ttl;
        } else {
            $output['cache_ttl'] = AISS_DEFAULT_CACHE_TTL;
        }

        // Request timeout: min 10 seconds, max 120 seconds, default 60
        $output['request_timeout'] = isset($input['request_timeout'])
            ? max(10, min(120, intval($input['request_timeout'])))
            : 60;

        // Site name and description for AI prompt
        $output['site_name'] = isset($input['site_name'])
            ? sanitize_text_field( trim($input['site_name']) )
            : get_bloginfo( 'name' );
        $output['site_description'] = isset($input['site_description'])
            ? sanitize_textarea_field( trim($input['site_description']) )
            : '';

        $output['show_openai_badge'] = isset($input['show_openai_badge']) && $input['show_openai_badge'] ? 1 : 0;
        $output['show_sources'] = isset($input['show_sources']) && $input['show_sources'] ? 1 : 0;

        // Color settings
        $output['color_background'] = isset($input['color_background']) ? $this->sanitize_color($input['color_background'], '#121e2b') : '#121e2b';
        $output['color_text'] = isset($input['color_text']) ? $this->sanitize_color($input['color_text'], '#e5e7eb') : '#e5e7eb';
        $output['color_accent'] = isset($input['color_accent']) ? $this->sanitize_color($input['color_accent'], '#fba919') : '#fba919';
        $output['color_border'] = isset($input['color_border']) ? $this->sanitize_color($input['color_border'], '#94a3b8') : '#94a3b8';

        $output['custom_css'] = isset($input['custom_css']) ? $this->sanitize_custom_css($input['custom_css']) : '';

        // Auto-clear cache when model or display settings change
        $old_options = get_option( $this->option_name, array() );
        $old_model = isset( $old_options['model'] ) ? $old_options['model'] : '';
        $old_show_sources = isset( $old_options['show_sources'] ) ? $old_options['show_sources'] : 1;

        $cache_invalidating_change = false;
        if ( $output['model'] !== $old_model && ! empty( $output['model'] ) ) {
            $cache_invalidating_change = true;
        }
        if ( $output['show_sources'] !== $old_show_sources ) {
            $cache_invalidating_change = true;
        }

        if ( $cache_invalidating_change ) {
            $this->bump_cache_namespace();
        }

        $this->options_cache = null;

        return $output;
    }

    /**
     * Sanitize custom CSS input to prevent XSS and other attacks.
     *
     * @param string $css Raw CSS input.
     * @return string Sanitized CSS.
     */
    private function sanitize_custom_css( $css ) {
        if ( empty( $css ) ) {
            return '';
        }

        // Strip HTML tags first
        $css = wp_strip_all_tags( $css );

        // Remove null bytes and other control characters
        $css = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $css );

        // Dangerous patterns to remove (case-insensitive)
        $dangerous_patterns = array(
            '/expression\s*\(/i',           // IE CSS expressions
            '/javascript\s*:/i',            // JavaScript URLs
            '/vbscript\s*:/i',              // VBScript URLs
            '/behavior\s*:/i',              // IE behaviors
            '/-moz-binding\s*:/i',          // Firefox XBL
            '/@import/i',                   // External CSS imports
            '/@charset/i',                  // Charset declarations
            '/binding\s*:/i',               // Generic binding
            '/\\\\[0-9a-f]+/i',             // Escaped unicode (can bypass filters)
        );

        foreach ( $dangerous_patterns as $pattern ) {
            $css = preg_replace( $pattern, '', $css );
        }

        // Remove url() with potentially dangerous schemes
        $css = preg_replace_callback(
            '/url\s*\(\s*["\']?\s*([^)]+?)\s*["\']?\s*\)/i',
            function( $matches ) {
                $url = trim( $matches[1], " \t\n\r\0\x0B\"'" );
                // Only allow relative URLs, http, https, and data:image
                if ( preg_match( '/^(https?:|data:image\/)/i', $url ) || ! preg_match( '/^[a-z]+:/i', $url ) ) {
                    return $matches[0];
                }
                return ''; // Remove dangerous URLs
            },
            $css
        );

        // Limit length to prevent DoS
        $max_length = 10000;
        if ( strlen( $css ) > $max_length ) {
            $css = substr( $css, 0, $max_length );
        }

        return trim( $css );
    }

    /**
     * Sanitize a color value.
     *
     * @param string $color   The color to sanitize.
     * @param string $default Default color if invalid.
     * @return string Sanitized hex color.
     */
    private function sanitize_color( $color, $default = '#000000' ) {
        $color = trim( $color );

        // Use WordPress sanitize_hex_color if available
        if ( function_exists( 'sanitize_hex_color' ) ) {
            $sanitized = sanitize_hex_color( $color );
            return $sanitized ? $sanitized : $default;
        }

        // Fallback: validate hex color manually
        if ( preg_match( '/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color ) ) {
            return $color;
        }

        return $default;
    }

    /**
     * Generate dynamic CSS for custom colors.
     *
     * @param array $options Plugin options.
     * @return string CSS string.
     */
    private function generate_color_css( $options ) {
        $bg     = isset( $options['color_background'] ) ? $options['color_background'] : '#121e2b';
        $text   = isset( $options['color_text'] ) ? $options['color_text'] : '#e5e7eb';
        $accent = isset( $options['color_accent'] ) ? $options['color_accent'] : '#fba919';
        $border = isset( $options['color_border'] ) ? $options['color_border'] : '#94a3b8';

        // Convert hex to rgba for semi-transparent backgrounds
        $bg_rgb = $this->hex_to_rgb( $bg );
        $text_rgb = $this->hex_to_rgb( $text );
        $border_rgb = $this->hex_to_rgb( $border );

        if ( ! $bg_rgb || ! $text_rgb || ! $border_rgb ) {
            return '';
        }

        $border_rgba = "rgba({$border_rgb['r']},{$border_rgb['g']},{$border_rgb['b']},0.4)";

        // Target the summary container and content (badge keeps its default dark style)
        $css = "
.aiss-summary-inner {
    background-color: {$bg};
    border-color: {$border_rgba};
}
.aiss-summary-inner,
.aiss-summary-inner .aiss-summary-header h2,
.aiss-summary-inner .aiss-disclaimer {
    color: {$text};
}
.aiss-openai-badge,
.aiss-openai-badge .aiss-openai-text {
    color: #e5e7eb;
}
.aiss-search-summary-content {
    color: {$text};
}
.aiss-search-summary-content a,
.aiss-search-summary-content a:visited {
    color: {$accent};
}
.aiss-search-summary-content a:hover,
.aiss-search-summary-content a:active,
.aiss-search-summary-content a:focus {
    color: {$accent};
    opacity: 0.8;
}
.aiss-sources-toggle {
    color: {$text};
}
.aiss-sources-toggle:hover,
.aiss-sources-toggle:active,
.aiss-sources-toggle:focus {
    color: {$accent};
}
.aiss-sources-list a,
.aiss-sources-list a:visited {
    color: {$accent};
}
.aiss-sources-list a:hover,
.aiss-sources-list a:active,
.aiss-sources-list a:focus {
    color: {$accent};
    opacity: 0.8;
}
.aiss-sources-list span {
    color: {$text};
    opacity: 0.8;
}
.aiss-spinner {
    border-color: rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.3);
    border-top-color: {$accent};
}
.aiss-skeleton-line {
    background-color: rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.15);
    background-image: linear-gradient(
        90deg,
        rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.1) 25%,
        rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.25) 50%,
        rgba({$text_rgb['r']},{$text_rgb['g']},{$text_rgb['b']},0.1) 75%
    );
}";

        return trim( $css );
    }

    /**
     * Convert hex color to RGB array.
     *
     * @param string $hex Hex color code.
     * @return array|false RGB array or false on failure.
     */
    private function hex_to_rgb( $hex ) {
        $hex = ltrim( $hex, '#' );

        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        if ( strlen( $hex ) !== 6 ) {
            return false;
        }

        return array(
            'r' => hexdec( substr( $hex, 0, 2 ) ),
            'g' => hexdec( substr( $hex, 2, 2 ) ),
            'b' => hexdec( substr( $hex, 4, 2 ) ),
        );
    }

    public function add_settings_page() {
        $capability  = 'manage_options';
        $parent_slug = 'aiss-settings';

        add_menu_page(
            'AI Search',
            'AI Search',
            $capability,
            $parent_slug,
            array( $this, 'render_settings_page' ),
            'dashicons-search',
            65
        );

        add_submenu_page(
            $parent_slug,
            'AI Search Settings',
            'Settings',
            $capability,
            $parent_slug,
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            $parent_slug,
            'AI Search Analytics',
            'Analytics',
            $capability,
            'aiss-analytics',
            array( $this, 'render_analytics_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'aiss_group',
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array( $this, 'sanitize_options' ),
                'default' => array(
                    'api_key'              => '',
                    'model'                => '',
                    'max_posts'            => 20,
                    'enable'               => 0,
                    'max_calls_per_minute' => 30,
                    'cache_ttl'            => AISS_DEFAULT_CACHE_TTL,
                    'request_timeout'      => 60,
                    'site_name'            => '',
                    'site_description'     => '',
                    'custom_css'           => '',
                )
            )
        );
        
    }

    private function test_api_key( $api_key ) {
        if ( empty( $api_key ) ) {
            return array(
                'success' => false,
                'message' => 'API key is empty.',
            );
        }

        $response = wp_safe_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => 'Connection error: ' . $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code === 401 ) {
            return array(
                'success' => false,
                'message' => 'Invalid API key. Please check your key and try again.',
            );
        }

        if ( $code === 429 ) {
            return array(
                'success' => false,
                'message' => 'Rate limit exceeded. Your API key works but has hit rate limits.',
            );
        }

        if ( $code < 200 || $code >= 300 ) {
            return array(
                'success' => false,
                'message' => 'API error (HTTP ' . $code . '). Please try again later.',
            );
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return array(
                'success' => false,
                'message' => 'Could not parse API response.',
            );
        }

        // Count available models
        $model_count = isset( $data['data'] ) ? count( $data['data'] ) : 0;
        
        // Check for chat models specifically
        $chat_models = array();
        if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
            foreach ( $data['data'] as $model ) {
                if ( isset( $model['id'] ) ) {
                    $id = $model['id'];
                    if ( strpos( $id, 'gpt-4' ) === 0 || strpos( $id, 'gpt-3.5' ) === 0 ) {
                        $chat_models[] = $id;
                    }
                }
            }
        }

        return array(
            'success'      => true,
            'message'      => 'API key is valid and working!',
            'model_count'  => $model_count,
            'chat_models'  => count( $chat_models ),
        );
    }

    public function ajax_test_api_key() {
        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        // Verify nonce
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'aiss_test_key' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        // Get API key - use constant if specified, otherwise from POST
        $api_key = isset( $_POST['api_key'] ) ? trim( $_POST['api_key'] ) : '';

        if ( $api_key === '__USE_CONSTANT__' ) {
            if ( $this->is_api_key_from_constant() ) {
                $api_key = AISS_API_KEY;
            } else {
                wp_send_json_error( array( 'message' => 'API key constant is not defined.' ) );
                return;
            }
        }

        // Test the key
        $result = $this->test_api_key( $api_key );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function ajax_refresh_models() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'aiss_refresh_models' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        $options = $this->get_options();
        if ( empty( $options['api_key'] ) ) {
            wp_send_json_error( array( 'message' => 'Cannot refresh models because no API key is set.' ) );
        }

        $refreshed = $this->refresh_model_cache( $options['api_key'] );
        if ( $refreshed ) {
            wp_send_json_success( array( 'message' => 'Model list refreshed from OpenAI.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Could not refresh models. Check your API key or try again later.' ) );
        }
    }

    public function ajax_clear_cache() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'aiss_clear_cache' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid security token. Please refresh the page.' ) );
        }

        $cleared = $this->clear_ai_cache();
        if ( $cleared ) {
            wp_send_json_success( array( 'message' => 'AI summary cache cleared. New searches will fetch fresh answers.' ) );
        } else {
            wp_send_json_error( array( 'message' => 'Could not clear cache.' ) );
        }
    }

    public function field_custom_css() {
        $options = $this->get_options();
        $custom_css = isset( $options['custom_css'] ) ? $options['custom_css'] : '';
        ?>
        <div class="aiss-css-editor-wrapper">
            <textarea 
                name="<?php echo esc_attr( $this->option_name ); ?>[custom_css]"
                id="aiss-custom-css"
                class="aiss-css-editor"
                rows="15"
                placeholder="/* Add your custom CSS here */
    .aiss-summary {
        /* Your custom styles */
    }"><?php echo esc_textarea( $custom_css ); ?></textarea>
        </div>
        
        <p class="description">
            Add custom CSS to style the AI search summary. This will override the default styles.
            <br>
            <strong>Tip:</strong> Target classes like <code>.aiss-summary</code>, <code>.aiss-summary-inner</code>, <code>.aiss-openai-badge</code>, etc.
        </p>
        
        <div class="aiss-css-buttons">
            <button type="button" id="aiss-reset-css" class="aiss-button aiss-button-secondary">
                Reset to Empty
            </button>
            <button type="button" id="aiss-view-default-css" class="aiss-button aiss-button-secondary">
                View Default CSS
            </button>
        </div>
        
        <!-- Modal HTML -->
        <div id="aiss-default-css-modal" class="aiss-modal-overlay">
            <div class="aiss-modal-content">
                <button type="button" id="aiss-close-modal" class="aiss-modal-close" aria-label="Close">×</button>
                <h2 class="aiss-modal-header">Default CSS Reference</h2>
                <p class="aiss-modal-description">
                    Copy and modify these default styles to customize your AI search summary.
                </p>
                <pre class="aiss-modal-code"><code><?php echo esc_html( $this->get_default_css() ); ?></code></pre>
            </div>
        </div>
        
        <!-- JavaScript -->
        <script>
        (function($) {
            $(document).ready(function() {
                var modal = $('#aiss-default-css-modal');
                var textarea = $('#aiss-custom-css');
                
                // Reset CSS
                $('#aiss-reset-css').on('click', function() {
                    if (confirm('Reset custom CSS? This will clear all your custom styles.')) {
                        textarea.val('');
                    }
                });
                
                // View default CSS
                $('#aiss-view-default-css').on('click', function() {
                    modal.addClass('aiss-modal-open');
                });
                
                // Close modal - X button
                $('#aiss-close-modal').on('click', function() {
                    modal.removeClass('aiss-modal-open');
                });
                
                // Close on background click
                modal.on('click', function(e) {
                    if (e.target === this) {
                        modal.removeClass('aiss-modal-open');
                    }
                });
                
                // Close on ESC key
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && modal.hasClass('aiss-modal-open')) {
                        modal.removeClass('aiss-modal-open');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    private function get_default_css() {
        return '@keyframes aiss-spin {
  to { transform: rotate(360deg); }
}

.aiss-summary-content {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.aiss-spinner {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid rgba(148,163,184,0.5);
  border-top-color: #fba919;
  display: inline-block;
  animation: aiss-spin 0.7s linear infinite;
  flex-shrink: 0;
}

.aiss-loading-text {
  margin: 0;
  opacity: 0.8;
}

.aiss-summary-content.aiss-loaded {
  display: block;
}

.aiss-openai-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.15rem 0.55rem;
  border-radius: 999px;
  border: 1px solid rgba(148,163,184,0.5);
  background: rgba(15,23,42,0.9);
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  white-space: nowrap;
  opacity: 0.95;
}

.aiss-openai-mark {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  border: 1px solid rgba(148,163,184,0.8);
  position: relative;
  flex-shrink: 0;
}

.aiss-openai-mark::after {
  content: "";
  position: absolute;
  inset: 2px;
  border-radius: 999px;
  background: linear-gradient(135deg,#fba919,#3b82f6);
}

.aiss-sources {
  margin-top: 1rem;
  font-size: 0.85rem;
}

.aiss-sources-toggle {
  border: none;
  background: none;
  padding: 0;
  margin: 0 0 0.4rem 0;
  font-size: 0.85rem;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
  opacity: 0.95;
  color: #e5e7eb;
}

.aiss-sources-list {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}

.aiss-sources-list li {
  margin-bottom: 0.4rem;
}

.aiss-sources-list li:last-child {
  margin-bottom: 0;
}

.aiss-sources-list a {
  color: #fba919;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.aiss-sources-list a:hover {
  opacity: 0.9;
}

.aiss-sources-list span {
  display: block;
  opacity: 0.8;
  color: #cbd5f5;
}';
    }

    private function fetch_models_from_openai( $api_key ) {
        if ( empty( $api_key ) ) {
            return array();
        }

        $response = wp_remote_get(
            'https://api.openai.com/v1/models',
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                ),
                'timeout' => 15,
            )
        );

        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Model list error: ' . $response->get_error_message() );
            }
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Model list HTTP error ' . $code . ' body: ' . $body );
            }
            return array();
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE || empty( $data['data'] ) ) {
            return array();
        }

        $models = array();

        foreach ( $data['data'] as $model ) {
            if ( empty( $model['id'] ) ) {
                continue;
            }

            $id = $model['id'];

            if (
                        strpos( $id, 'gpt-5.1' ) === 0 ||
                        strpos( $id, 'gpt-5' ) === 0 ||
                        strpos( $id, 'gpt-4.1' ) === 0 ||
                        strpos( $id, 'gpt-4o' ) === 0 ||
                        strpos( $id, 'gpt-4-turbo' ) === 0 ||
                        strpos( $id, 'gpt-4-' ) === 0 ||
                        strpos( $id, 'gpt-4' ) === 0 ||
                        strpos( $id, 'gpt-3.5-turbo' ) === 0
                    ) {
                        $models[] = $id;
                    }
                }

        $models = array_unique( $models );
        sort( $models );

        return $models;
    }

    private function get_available_models_for_dropdown( $api_key ) {
        // Clean, curated default list - only chat completion models
        $default_models = array(
            'gpt-4o-mini',
            'gpt-4o',
            'gpt-4-turbo',
            'gpt-4.1-mini',
            'gpt-4.1-nano',
            'gpt-4.1',
            'gpt-4',
            'gpt-3.5-turbo',
            // Future models (base names only)
            'gpt-5.2',
            'gpt-5.1',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
        );

        if ( empty( $api_key ) ) {
            return $default_models;
        }

        $cache         = get_option( $this->models_cache_option );
        $cached_models = ( is_array( $cache ) && ! empty( $cache['models'] ) ) ? $cache['models'] : array();
        $updated_at    = ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) ? absint( $cache['updated_at'] ) : 0;

        // Use cached models if they exist and are still within TTL.
        if ( ! empty( $cached_models ) && $updated_at > 0 ) {
            $age = time() - $updated_at;
            if ( $age >= 0 && $age < AISS_MODELS_CACHE_TTL ) {
                return $cached_models;
            }
        }

        // Cache is missing or stale, try to refresh from OpenAI.
        $models = $this->fetch_models_from_openai( $api_key );

        if ( ! empty( $models ) ) {
            update_option(
                $this->models_cache_option,
                array(
                    'models'     => $models,
                    'updated_at' => time(),
                )
            );

            return $models;
        }

        // If refresh failed, fall back to cached models if available, otherwise defaults.
        if ( ! empty( $cached_models ) ) {
            return $cached_models;
        }

        return $default_models;
    }

    private function refresh_model_cache( $api_key ) {
        if ( empty( $api_key ) ) {
            return false;
        }

        $models = $this->fetch_models_from_openai( $api_key );

        if ( empty( $models ) ) {
            return false;
        }

        update_option(
            $this->models_cache_option,
            array(
                'models'     => $models,
                'updated_at' => time(),
            )
        );

        return true;
    }

    private function get_cache_namespace() {
        $ns = (int) get_option( $this->cache_namespace_option, 1 );
        if ( $ns < 1 ) {
            $ns = 1;
            update_option( $this->cache_namespace_option, $ns );
        }
        return $ns;
    }

    private function bump_cache_namespace() {
        $ns = $this->get_cache_namespace();
        $ns++;
        update_option( $this->cache_namespace_option, $ns );
        return $ns;
    }

    private function clear_ai_cache() {
        // Namespace based invalidation: bump namespace so all previous cache keys become unreachable.
        $this->bump_cache_namespace();

        // Backward compatibility cleanup: if older versions stored explicit transient keys, delete them too.
        $keys = get_option( $this->cache_keys_option, array() );
        if ( is_array( $keys ) ) {
            foreach ( $keys as $key ) {
                delete_transient( $key );
            }
        }
        delete_option( $this->cache_keys_option );

        return true;
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $options = $this->get_options();
        $cache   = get_option( $this->models_cache_option );

        // Check if setup is complete
        $has_api_key = ! empty( $options['api_key'] );
        $is_enabled  = ! empty( $options['enable'] );
        $setup_complete = $has_api_key && $is_enabled;
        ?>
        
        <div class="aiss-settings-wrap">
            <!-- Header -->
            <div class="aiss-header">
                <h1>AI Search Settings</h1>
                <p>Configure OpenAI-powered search summaries for your site.</p>
            </div>

            <!-- Status Card -->
            <div class="aiss-status-card <?php echo $setup_complete ? 'active' : ''; ?>">
                <div class="aiss-status-icon">
                    <?php echo $setup_complete ? '✓' : '○'; ?>
                </div>
                <div class="aiss-status-content">
                    <h3><?php echo $setup_complete ? 'AI Search Active' : 'Setup Required'; ?></h3>
                    <p>
                        <?php 
                        if ( $setup_complete ) {
                            echo 'Your AI search is configured and running.';
                        } elseif ( ! $has_api_key ) {
                            echo 'Add your OpenAI API key to get started.';
                        } else {
                            echo 'Enable AI search to start generating summaries.';
                        }
                        ?>
                    </p>
                </div>
            </div>

            <?php
            // WordPress settings API handles success/error messages automatically
            settings_errors( 'aiss_group' );
            ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'aiss_group' ); ?>

                <!-- Section 1: Getting Started (Most Important) -->
                <div class="aiss-section">
                    <div class="aiss-section-header">
                        <h2>Getting Started</h2>
                        <p>Essential settings to enable AI search</p>
                    </div>
                    <div class="aiss-section-content">
                        <!-- Enable Toggle -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>AI Search</label>
                            </div>
                            <div class="aiss-field-description">
                                Enable or disable AI-powered search summaries site-wide
                            </div>
                            <div class="aiss-toggle-wrapper">
                                <label class="aiss-toggle">
                                    <input type="checkbox" 
                                           name="<?php echo esc_attr( $this->option_name ); ?>[enable]"
                                           value="1" 
                                           <?php checked( $options['enable'], 1 ); ?> />
                                    <span class="aiss-toggle-slider"></span>
                                </label>
                                <span class="aiss-toggle-label">
                                    <?php echo $options['enable'] ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- API Key -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label for="aiss-api-key">OpenAI API Key</label>
                                <span class="aiss-field-required">Required</span>
                            </div>
                            <?php if ( $this->is_api_key_from_constant() ) : ?>
                                <div class="aiss-field-description" style="background: #d1fae5; border: 1px solid #10b981; padding: 12px; border-radius: 6px; margin-bottom: 12px;">
                                    <strong style="color: #065f46;">&#x1F512; Secure Mode:</strong>
                                    API key is defined via <code>AISS_API_KEY</code> constant in wp-config.php.
                                    <br><span style="color: #047857;">This is more secure than storing in the database.</span>
                                </div>
                                <div class="aiss-field-input">
                                    <input type="password"
                                           id="aiss-api-key"
                                           value="<?php echo esc_attr( str_repeat( '•', 20 ) ); ?>"
                                           disabled
                                           style="background: #f3f4f6; cursor: not-allowed;" />
                                    <input type="hidden"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                                           value="" />
                                </div>
                            <?php else : ?>
                                <div class="aiss-field-description">
                                    Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>.
                                    <br><em style="color: #6b7280; font-size: 12px;">Tip: For better security, define <code>AISS_API_KEY</code> in wp-config.php instead.</em>
                                </div>
                                <div class="aiss-field-input">
                                    <input type="password"
                                           id="aiss-api-key"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                                           value="<?php echo esc_attr( $options['api_key'] ); ?>"
                                           placeholder="sk-proj-..."
                                           autocomplete="off" />
                                </div>
                            <?php endif; ?>
                            <div class="aiss-field-actions">
                                <button type="button"
                                        id="aiss-test-key-btn"
                                        class="aiss-button aiss-button-secondary">
                                    Test Connection
                                </button>
                            </div>
                            <div id="aiss-test-result" style="margin-top: 12px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Section: Site Configuration -->
                <div class="aiss-section">
                    <div class="aiss-section-header">
                        <h2>Site Configuration</h2>
                        <p>Configure how your site is described to the AI</p>
                    </div>
                    <div class="aiss-section-content">
                        <!-- Site Name -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label for="aiss-site-name">Site Name</label>
                            </div>
                            <div class="aiss-field-description">
                                The name of your site (used in AI responses and loading text)
                            </div>
                            <div class="aiss-field-input">
                                <input type="text"
                                       id="aiss-site-name"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[site_name]"
                                       value="<?php echo esc_attr( isset( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' ) ); ?>"
                                       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
                            </div>
                        </div>

                        <!-- Site Description -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label for="aiss-site-description">Site Description</label>
                            </div>
                            <div class="aiss-field-description">
                                Describe your site's focus/niche (e.g., "a technology news site" or "a cooking and recipe blog"). This helps the AI understand context.
                            </div>
                            <div class="aiss-field-input">
                                <input type="text"
                                       id="aiss-site-description"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[site_description]"
                                       value="<?php echo esc_attr( isset( $options['site_description'] ) ? $options['site_description'] : '' ); ?>"
                                       placeholder="e.g., a technology news and reviews site" />
                            </div>
                        </div>

                        <!-- Show OpenAI Badge -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Show "Powered by OpenAI" Badge</label>
                            </div>
                            <div class="aiss-field-description">
                                Display the OpenAI attribution badge on search summaries
                            </div>
                            <div class="aiss-toggle-wrapper">
                                <label class="aiss-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[show_openai_badge]"
                                           value="1"
                                           <?php checked( isset( $options['show_openai_badge'] ) ? $options['show_openai_badge'] : 1, 1 ); ?> />
                                    <span class="aiss-toggle-slider"></span>
                                </label>
                                <span class="aiss-toggle-label">
                                    <?php echo ( isset( $options['show_openai_badge'] ) && $options['show_openai_badge'] ) ? 'Visible' : 'Hidden'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Show Sources -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Show Sources</label>
                            </div>
                            <div class="aiss-field-description">
                                Display a collapsible list of source articles used to generate the summary
                            </div>
                            <div class="aiss-toggle-wrapper">
                                <label class="aiss-toggle">
                                    <input type="checkbox"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[show_sources]"
                                           value="1"
                                           <?php checked( isset( $options['show_sources'] ) ? $options['show_sources'] : 1, 1 ); ?> />
                                    <span class="aiss-toggle-slider"></span>
                                </label>
                                <span class="aiss-toggle-label">
                                    <?php echo ( isset( $options['show_sources'] ) && $options['show_sources'] ) ? 'Visible' : 'Hidden'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: AI Configuration -->
                <div class="aiss-section">
                    <div class="aiss-section-header">
                        <h2>AI Configuration</h2>
                        <p>Customize how AI generates search summaries</p>
                    </div>
                    <div class="aiss-section-content">
                        <!-- Model Selection -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>AI Model</label>
                            </div>
                            <div class="aiss-field-input">
                                <?php
                                $models = $this->get_available_models_for_dropdown( $options['api_key'] );
                                if ( ! empty( $options['model'] ) && ! in_array( $options['model'], $models, true ) ) {
                                    $models[] = $options['model'];
                                }
                                $models = array_unique( $models );
                                sort( $models );
                                ?>
                                <select name="<?php echo esc_attr( $this->option_name ); ?>[model]">
                                    <?php foreach ( $models as $model_id ) : ?>
                                        <option value="<?php echo esc_attr( $model_id ); ?>" 
                                                <?php selected( $options['model'], $model_id ); ?>>
                                            <?php echo esc_html( $model_id ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="aiss-field-actions">
                                <button type="button" id="aiss-refresh-models-btn"
                                        class="aiss-button aiss-button-secondary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'aiss_refresh_models' ) ); ?>">
                                    Refresh Models
                                </button>
                                <span id="aiss-refresh-models-result" style="margin-left: 12px;"></span>
                            </div>
                            <?php if ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) : ?>
                                <div style="margin-top: 8px; font-size: 13px; color: #86868b;">
                                    Last updated: <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $cache['updated_at'] ) ) ); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Max Posts -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Context Size</label>
                            </div>
                            <div class="aiss-field-description">
                                Number of posts to send as context (more posts = better answers, higher cost)
                            </div>
                            <div class="aiss-field-input">
                                <input type="number" 
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_posts]"
                                       value="<?php echo esc_attr( $options['max_posts'] ); ?>"
                                       min="1" 
                                       max="50" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 3: Performance -->
                <div class="aiss-section">
                    <div class="aiss-section-header">
                        <h2>Performance</h2>
                        <p>Control rate limits and caching behavior</p>
                    </div>
                    <div class="aiss-section-content">
                        <!-- Cache TTL -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Cache Duration</label>
                            </div>
                            <div class="aiss-field-description">
                                How long to cache AI summaries (60 seconds to 24 hours)
                            </div>
                            <div class="aiss-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[cache_ttl]"
                                       value="<?php echo esc_attr( isset( $options['cache_ttl'] ) ? $options['cache_ttl'] : 3600 ); ?>"
                                       min="60"
                                       max="86400"
                                       step="60" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">seconds</span>
                            </div>
                            <div class="aiss-field-actions">
                                <button type="button" id="aiss-clear-cache-btn"
                                        class="aiss-button aiss-button-secondary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'aiss_clear_cache' ) ); ?>">
                                    Clear Cache Now
                                </button>
                                <span id="aiss-clear-cache-result" style="margin-left: 12px;"></span>
                            </div>
                        </div>

                        <!-- Rate Limit -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Rate Limit</label>
                            </div>
                            <div class="aiss-field-description">
                                Maximum AI calls per minute across the entire site (0 = unlimited)
                            </div>
                            <div class="aiss-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_calls_per_minute]"
                                       value="<?php echo esc_attr( isset( $options['max_calls_per_minute'] ) ? $options['max_calls_per_minute'] : 30 ); ?>"
                                       min="0"
                                       step="1" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">calls/minute</span>
                            </div>
                        </div>

                        <!-- Request Timeout -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Request Timeout</label>
                            </div>
                            <div class="aiss-field-description">
                                How long to wait for AI response before timing out (10-120 seconds). Increase for slower reasoning models.
                            </div>
                            <div class="aiss-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[request_timeout]"
                                       value="<?php echo esc_attr( isset( $options['request_timeout'] ) ? $options['request_timeout'] : 60 ); ?>"
                                       min="10"
                                       max="120"
                                       step="5" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">seconds</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 4: Appearance -->
                <div class="aiss-section">
                    <div class="aiss-section-header">
                        <h2>Appearance</h2>
                        <p>Customize the look of AI summaries to match your theme</p>
                    </div>
                    <div class="aiss-section-content">
                        <div class="aiss-color-grid">
                            <!-- Background Color -->
                            <div class="aiss-field aiss-field-color">
                                <div class="aiss-field-label">
                                    <label for="aiss-color-background">Background Color</label>
                                </div>
                                <div class="aiss-field-input">
                                    <input type="text"
                                           id="aiss-color-background"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[color_background]"
                                           value="<?php echo esc_attr( isset( $options['color_background'] ) ? $options['color_background'] : '#121e2b' ); ?>"
                                           class="aiss-color-picker"
                                           data-default-color="#121e2b" />
                                </div>
                            </div>

                            <!-- Text Color -->
                            <div class="aiss-field aiss-field-color">
                                <div class="aiss-field-label">
                                    <label for="aiss-color-text">Text Color</label>
                                </div>
                                <div class="aiss-field-input">
                                    <input type="text"
                                           id="aiss-color-text"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[color_text]"
                                           value="<?php echo esc_attr( isset( $options['color_text'] ) ? $options['color_text'] : '#e5e7eb' ); ?>"
                                           class="aiss-color-picker"
                                           data-default-color="#e5e7eb" />
                                </div>
                            </div>

                            <!-- Accent Color -->
                            <div class="aiss-field aiss-field-color">
                                <div class="aiss-field-label">
                                    <label for="aiss-color-accent">Accent Color</label>
                                </div>
                                <div class="aiss-field-description">
                                    Used for links and highlights
                                </div>
                                <div class="aiss-field-input">
                                    <input type="text"
                                           id="aiss-color-accent"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[color_accent]"
                                           value="<?php echo esc_attr( isset( $options['color_accent'] ) ? $options['color_accent'] : '#fba919' ); ?>"
                                           class="aiss-color-picker"
                                           data-default-color="#fba919" />
                                </div>
                            </div>

                            <!-- Border Color -->
                            <div class="aiss-field aiss-field-color">
                                <div class="aiss-field-label">
                                    <label for="aiss-color-border">Border Color</label>
                                </div>
                                <div class="aiss-field-input">
                                    <input type="text"
                                           id="aiss-color-border"
                                           name="<?php echo esc_attr( $this->option_name ); ?>[color_border]"
                                           value="<?php echo esc_attr( isset( $options['color_border'] ) ? $options['color_border'] : '#94a3b8' ); ?>"
                                           class="aiss-color-picker"
                                           data-default-color="#94a3b8" />
                                </div>
                            </div>
                        </div>

                        <!-- Color Presets -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Quick Presets</label>
                            </div>
                            <div class="aiss-color-presets">
                                <button type="button" class="aiss-preset-btn" data-preset="dark">
                                    <span class="aiss-preset-preview" style="background:#121e2b;border-color:#94a3b8;"></span>
                                    Dark
                                </button>
                                <button type="button" class="aiss-preset-btn" data-preset="light">
                                    <span class="aiss-preset-preview" style="background:#f9fafb;border-color:#6b7280;"></span>
                                    Light
                                </button>
                                <button type="button" class="aiss-preset-btn" data-preset="blue">
                                    <span class="aiss-preset-preview" style="background:#1e3a5f;border-color:#60a5fa;"></span>
                                    Blue
                                </button>
                                <button type="button" class="aiss-preset-btn" data-preset="purple">
                                    <span class="aiss-preset-preview" style="background:#2d1b4e;border-color:#a78bfa;"></span>
                                    Purple
                                </button>
                            </div>
                        </div>

                        <!-- Custom CSS -->
                        <div class="aiss-field">
                            <div class="aiss-field-label">
                                <label>Custom CSS</label>
                            </div>
                            <div class="aiss-field-description">
                                Override default styles with your own CSS for complete control
                            </div>
                            <?php $this->field_custom_css(); ?>
                        </div>
                    </div>
                </div>

                <div class="aiss-footer-actions">
                    <?php submit_button( 'Save Settings', 'primary aiss-button aiss-button-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>

        <script>
        (function($) {
            $(document).ready(function() {
                $('#aiss-test-key-btn').on('click', function() {
                    var btn = $(this);
                    var useConstant = <?php echo $this->is_api_key_from_constant() ? 'true' : 'false'; ?>;
                    var apiKey = useConstant ? '__USE_CONSTANT__' : $('#aiss-api-key').val().trim();
                    var resultDiv = $('#aiss-test-result');

                    if (!useConstant && !apiKey) {
                        resultDiv.html('<div class="aiss-test-result error"><p>Please enter an API key first.</p></div>');
                        return;
                    }

                    btn.prop('disabled', true).text('Testing...');
                    resultDiv.html('<div class="aiss-test-result info"><p>Testing API key...</p></div>');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aiss_test_api_key',
                            api_key: apiKey,
                            nonce: '<?php echo wp_create_nonce( 'aiss_test_key' ); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Test Connection');
                            
                            if (response.success) {
                                var msg = '<strong>✓ ' + response.data.message + '</strong>';
                                if (response.data.model_count) {
                                    msg += '<br>Available models: ' + response.data.model_count + ' (Chat models: ' + response.data.chat_models + ')';
                                }
                                resultDiv.html('<div class="aiss-test-result success"><p>' + msg + '</p></div>');
                            } else {
                                resultDiv.html('<div class="aiss-test-result error"><p><strong>✗ Test failed:</strong> ' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Test Connection');
                            resultDiv.html('<div class="aiss-test-result error"><p>Request failed. Please try again.</p></div>');
                        }
                    });
                });

                // Refresh Models button
                $('#aiss-refresh-models-btn').on('click', function() {
                    var btn = $(this);
                    var resultSpan = $('#aiss-refresh-models-result');
                    var nonce = btn.data('nonce');

                    btn.prop('disabled', true).text('Refreshing...');
                    resultSpan.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aiss_refresh_models',
                            nonce: nonce
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Refresh Models');
                            if (response.success) {
                                resultSpan.html('<span style="color: #fba919;">✓ ' + response.data.message + '</span>');
                                // Reload page to show updated model list
                                setTimeout(function() { location.reload(); }, 1000);
                            } else {
                                resultSpan.html('<span style="color: #ef4444;">✗ ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Refresh Models');
                            resultSpan.html('<span style="color: #ef4444;">✗ Request failed. Please try again.</span>');
                        }
                    });
                });

                // Clear Cache button
                $('#aiss-clear-cache-btn').on('click', function() {
                    var btn = $(this);
                    var resultSpan = $('#aiss-clear-cache-result');
                    var nonce = btn.data('nonce');

                    btn.prop('disabled', true).text('Clearing...');
                    resultSpan.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'aiss_clear_cache',
                            nonce: nonce
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Clear Cache Now');
                            if (response.success) {
                                resultSpan.html('<span style="color: #fba919;">✓ ' + response.data.message + '</span>');
                            } else {
                                resultSpan.html('<span style="color: #ef4444;">✗ ' + response.data.message + '</span>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Clear Cache Now');
                            resultSpan.html('<span style="color: #ef4444;">✗ Request failed. Please try again.</span>');
                        }
                    });
                });

                // Initialize color pickers
                $('.aiss-color-picker').wpColorPicker();

                // Color presets
                var colorPresets = {
                    dark: {
                        background: '#121e2b',
                        text: '#e5e7eb',
                        accent: '#fba919',
                        border: '#94a3b8'
                    },
                    light: {
                        background: '#f9fafb',
                        text: '#1f2937',
                        accent: '#059669',
                        border: '#6b7280'
                    },
                    blue: {
                        background: '#1e3a5f',
                        text: '#e0f2fe',
                        accent: '#38bdf8',
                        border: '#60a5fa'
                    },
                    purple: {
                        background: '#2d1b4e',
                        text: '#f3e8ff',
                        accent: '#a78bfa',
                        border: '#8b5cf6'
                    }
                };

                $('.aiss-preset-btn').on('click', function() {
                    var preset = $(this).data('preset');
                    if (colorPresets[preset]) {
                        var colors = colorPresets[preset];
                        $('#aiss-color-background').wpColorPicker('color', colors.background);
                        $('#aiss-color-text').wpColorPicker('color', colors.text);
                        $('#aiss-color-accent').wpColorPicker('color', colors.accent);
                        $('#aiss-color-border').wpColorPicker('color', colors.border);
                    }
                });
            });
        })(jQuery);
        </script>

        <?php
    }

    public function render_analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $logs_built      = false;
        $logs_error      = '';
        $logs_purged     = false;
        $purge_count     = 0;
        $purge_error     = '';

        // Handle the create/repair action (POST for security)
        if (
            isset( $_POST['aiss_build_logs'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( $_POST['_wpnonce'], 'aiss_build_logs' )
        ) {
            $logs_built = $this->ensure_logs_table();
            if ( ! $logs_built ) {
                $logs_error = 'Could not create or repair the analytics table. Check error logs for details.';
            }
        }

        // Handle the purge old logs action (POST for security)
        if (
            isset( $_POST['aiss_purge_logs'] ) &&
            isset( $_POST['aiss_purge_days'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( $_POST['_wpnonce'], 'aiss_purge_logs' )
        ) {
            $days = absint( $_POST['aiss_purge_days'] );
            if ( $days < 1 ) {
                $days = 30;
            }
            $result = $this->purge_old_logs( $days );
            if ( false === $result ) {
                $purge_error = 'Could not purge logs. The analytics table may not exist.';
            } else {
                $logs_purged = true;
                $purge_count = $result;
            }
        }
        ?>

        <div class="aiss-settings-wrap">
            <!-- Header -->
            <div class="aiss-header">
                <h1>Analytics</h1>
                <p>Track AI search usage, success rates, and identify trends.</p>
            </div>

            <!-- Notifications -->
            <?php if ( $logs_built && empty( $logs_error ) ) : ?>
                <div class="aiss-notice aiss-notice-success">
                    Analytics table has been created or repaired successfully.
                </div>
            <?php elseif ( ! empty( $logs_error ) ) : ?>
                <div class="aiss-notice aiss-notice-error">
                    <?php echo esc_html( $logs_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $logs_purged ) : ?>
                <div class="aiss-notice aiss-notice-success">
                    <?php echo esc_html( number_format( $purge_count ) ); ?> old log entries have been deleted.
                </div>
            <?php elseif ( ! empty( $purge_error ) ) : ?>
                <div class="aiss-notice aiss-notice-error">
                    <?php echo esc_html( $purge_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $this->logs_table_is_available() ) : ?>
                <!-- No Data State -->
                <div class="aiss-empty-state">
                    <div class="aiss-empty-icon">📊</div>
                    <h3>No Analytics Data Yet</h3>
                    <p>After visitors use search, analytics data will appear here.</p>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field( 'aiss_build_logs' ); ?>
                        <button type="submit" name="aiss_build_logs" value="1"
                                class="aiss-button aiss-button-primary">
                            Create Analytics Table
                        </button>
                    </form>
                </div>
            <?php else : ?>
                <?php $this->render_analytics_content(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_analytics_content() {
        global $wpdb;
        $table_escaped = self::get_escaped_table_name();

        // Get overview stats
        // cache_hit values: 0 = miss, 1 = server cache hit, 2 = session/browser cache hit
        $totals = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(ai_success) AS success_count,
                SUM(CASE WHEN ai_success = 0 AND (ai_error IS NOT NULL AND ai_error <> '') THEN 1 ELSE 0 END) AS error_count,
                SUM(CASE WHEN cache_hit IN (1, 2) THEN 1 ELSE 0 END) AS cache_hits,
                SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses
             FROM {$table_escaped}"
        );

        $total_searches      = $totals ? (int) $totals->total : 0;
        $success_count       = $totals ? (int) $totals->success_count : 0;
        $error_count         = $totals ? (int) $totals->error_count : 0;
        $cache_hits          = $totals ? (int) $totals->cache_hits : 0;
        $cache_misses        = $totals ? (int) $totals->cache_misses : 0;
        $cache_total         = $cache_hits + $cache_misses;
        $cache_hit_rate      = $cache_total > 0 ? round( ( $cache_hits / $cache_total ) * 100, 1 ) : 0;
        $success_rate        = $this->calculate_success_rate( $success_count, $total_searches );

        $no_results_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_escaped} WHERE results_count = 0"
        );

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - 24 * 60 * 60 );
        $last_24   = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_escaped} WHERE created_at >= %s",
                $since_24h
            )
        );

        $daily_stats = $wpdb->get_results(
            "SELECT
                DATE(created_at) AS day,
                COUNT(*) AS total,
                SUM(ai_success) AS success_count,
                SUM(CASE WHEN cache_hit IN (1, 2) THEN 1 ELSE 0 END) AS cache_hits,
                SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses
             FROM {$table_escaped}
             GROUP BY DATE(created_at)
             ORDER BY day DESC
             LIMIT 14"
        );

        $top_queries = $wpdb->get_results(
            "SELECT search_query, COUNT(*) AS total, SUM(ai_success) AS success_count
             FROM {$table_escaped}
             GROUP BY search_query
             ORDER BY total DESC
             LIMIT 20"
        );

        $top_errors = $wpdb->get_results(
            "SELECT ai_error, COUNT(*) AS total
             FROM {$table_escaped}
             WHERE ai_error IS NOT NULL AND ai_error <> ''
             GROUP BY ai_error
             ORDER BY total DESC
             LIMIT 10"
        );

        $recent_events = $wpdb->get_results(
            "SELECT *
             FROM {$table_escaped}
             ORDER BY created_at DESC
             LIMIT 50"
        );
        ?>

        <!-- Overview Stats Grid -->
        <div class="aiss-stats-grid">
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Total Searches</div>
                <div class="aiss-stat-value"><?php echo number_format( $total_searches ); ?></div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Success Rate</div>
                <div class="aiss-stat-value"><?php echo esc_html( $success_rate ); ?>%</div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Cache Hit Rate</div>
                <div class="aiss-stat-value"><?php echo esc_html( $cache_hit_rate ); ?>%</div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Cache Hits</div>
                <div class="aiss-stat-value"><?php echo number_format( $cache_hits ); ?></div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Cache Misses</div>
                <div class="aiss-stat-value"><?php echo number_format( $cache_misses ); ?></div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Last 24 Hours</div>
                <div class="aiss-stat-value"><?php echo number_format( $last_24 ); ?></div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">Total Errors</div>
                <div class="aiss-stat-value"><?php echo number_format( $error_count ); ?></div>
            </div>
            <div class="aiss-stat-card">
                <div class="aiss-stat-label">No Results</div>
                <div class="aiss-stat-value"><?php echo number_format( $no_results_count ); ?></div>
            </div>
        </div>

        <!-- Daily Stats Section -->
        <div class="aiss-section">
            <div class="aiss-section-header">
                <h2>Last 14 Days</h2>
                <p>Daily search volume and success rates</p>
            </div>
            <div class="aiss-section-content">
                <?php if ( ! empty( $daily_stats ) ) : ?>
                    <div class="aiss-table-wrapper">
                        <table class="aiss-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Total Searches</th>
                                    <th>Success Rate</th>
                                    <th>Cache Hit Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $daily_stats as $row ) : ?>
                                    <?php
                                    $day_total = (int) $row->total;
                                    $day_success = (int) $row->success_count;
                                    $day_rate = $this->calculate_success_rate( $day_success, $day_total );
                                    $day_cache_hits = (int) $row->cache_hits;
                                    $day_cache_misses = (int) $row->cache_misses;
                                    $day_cache_total = $day_cache_hits + $day_cache_misses;
                                    $day_cache_rate = $day_cache_total > 0 ? round( ( $day_cache_hits / $day_cache_total ) * 100, 1 ) : 0;
                                    ?>
                                    <tr>
                                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->day ) ) ); ?></td>
                                        <td><?php echo number_format( $day_total ); ?></td>
                                        <td>
                                            <span class="aiss-badge aiss-badge-<?php echo $day_rate >= 90 ? 'success' : ( $day_rate >= 70 ? 'warning' : 'error' ); ?>">
                                                <?php echo esc_html( $day_rate ); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( $day_cache_total > 0 ) : ?>
                                                <span class="aiss-badge aiss-badge-<?php echo $day_cache_rate >= 50 ? 'success' : ( $day_cache_rate >= 25 ? 'warning' : 'error' ); ?>">
                                                    <?php echo esc_html( $day_cache_rate ); ?>%
                                                </span>
                                            <?php else : ?>
                                                <span class="aiss-badge">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="aiss-empty-message">No recent activity yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Queries Section -->
        <div class="aiss-section">
            <div class="aiss-section-header">
                <h2>Top Search Queries</h2>
                <p>Most frequently searched terms</p>
            </div>
            <div class="aiss-section-content">
                <?php if ( ! empty( $top_queries ) ) : ?>
                    <div class="aiss-table-wrapper">
                        <table class="aiss-table">
                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th>Total Searches</th>
                                    <th>AI Success Rate</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $top_queries as $row ) : ?>
                                    <?php
                                    $total_q = (int) $row->total;
                                    $success_q = (int) $row->success_count;
                                    $success_q_rate = $this->calculate_success_rate( $success_q, $total_q );
                                    ?>
                                    <tr>
                                        <td class="aiss-query-cell"><?php echo esc_html( $row->search_query ); ?></td>
                                        <td><?php echo number_format( $total_q ); ?></td>
                                        <td>
                                            <span class="aiss-badge aiss-badge-<?php echo $success_q_rate >= 90 ? 'success' : ( $success_q_rate >= 70 ? 'warning' : 'error' ); ?>">
                                                <?php echo esc_html( $success_q_rate ); ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="aiss-empty-message">No search data yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Errors Section -->
        <?php if ( ! empty( $top_errors ) ) : ?>
            <div class="aiss-section">
                <div class="aiss-section-header">
                    <h2>Top AI Errors</h2>
                    <p>Most common error messages</p>
                </div>
                <div class="aiss-section-content">
                    <div class="aiss-table-wrapper">
                        <table class="aiss-table">
                            <thead>
                                <tr>
                                    <th>Error Message</th>
                                    <th>Occurrences</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $top_errors as $err ) : ?>
                                    <tr>
                                        <td class="aiss-error-cell">
                                            <?php
                                            $msg = (string) $err->ai_error;
                                            if ( strlen( $msg ) > 80 ) {
                                                $msg = substr( $msg, 0, 77 ) . '...';
                                            }
                                            echo esc_html( $msg );
                                            ?>
                                        </td>
                                        <td><?php echo number_format( (int) $err->total ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Recent Events Section -->
        <div class="aiss-section">
            <div class="aiss-section-header">
                <h2>Recent AI Search Events</h2>
                <p>Latest 50 search requests</p>
            </div>
            <div class="aiss-section-content">
                <?php if ( ! empty( $recent_events ) ) : ?>
                    <div class="aiss-table-wrapper">
                        <table class="aiss-table aiss-table-compact">
                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th>Status</th>
                                    <th>Cache</th>
                                    <th>Error</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_events as $event ) : ?>
                                    <tr>
                                        <td class="aiss-query-cell"><?php echo esc_html( $event->search_query ); ?></td>
                                        <td>
                                            <?php if ( (int) $event->ai_success === 1 ) : ?>
                                                <span class="aiss-badge aiss-badge-success">Success</span>
                                            <?php else : ?>
                                                <span class="aiss-badge aiss-badge-error">Error</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $cache_val = $event->cache_hit !== null ? (int) $event->cache_hit : null;
                                            if ( $cache_val === 1 ) : ?>
                                                <span class="aiss-badge aiss-badge-success" title="Server cache hit">Hit</span>
                                            <?php elseif ( $cache_val === 2 ) : ?>
                                                <span class="aiss-badge aiss-badge-info" title="Browser session cache hit">Session</span>
                                            <?php elseif ( $cache_val === 0 ) : ?>
                                                <span class="aiss-badge aiss-badge-warning">Miss</span>
                                            <?php else : ?>
                                                <span class="aiss-badge aiss-badge-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="aiss-error-cell" <?php if ( ! empty( $event->ai_error ) ) : ?>title="<?php echo esc_attr( $event->ai_error ); ?>"<?php endif; ?>>
                                            <?php echo esc_html( $event->ai_error ); ?>
                                        </td>
                                        <td class="aiss-date-cell">
                                            <?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $event->created_at ) ) ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="aiss-empty-message">No recent search events logged yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Data Management Section -->
        <div class="aiss-section">
            <div class="aiss-section-header">
                <h2>Data Management</h2>
                <p>Manage analytics log data</p>
            </div>
            <div class="aiss-section-content">
                <div class="aiss-field">
                    <div class="aiss-field-label">
                        <label>Purge Old Logs</label>
                    </div>
                    <div class="aiss-field-description">
                        Delete log entries older than the specified number of days to free up database space.
                    </div>
                    <form method="post" style="display: flex; align-items: center; gap: 12px; margin-top: 12px;">
                        <?php wp_nonce_field( 'aiss_purge_logs' ); ?>
                        <span>Delete logs older than</span>
                        <input type="number" name="aiss_purge_days" value="30" min="1" max="365"
                               style="width: 80px;" />
                        <span>days</span>
                        <button type="submit" name="aiss_purge_logs" value="1"
                                class="aiss-button aiss-button-secondary"
                                onclick="return confirm('Are you sure you want to delete old log entries? This action cannot be undone.');">
                            Purge Old Logs
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     *  Analytics helper methods
     * --------------------------------------------------------- */

    /**
     * Calculate success rate percentage from success count and total.
     *
     * @param int $success_count Number of successful operations.
     * @param int $total Total number of operations.
     * @return int Success rate as a percentage (0-100).
     */
    private function calculate_success_rate( $success_count, $total ) {
        if ( $total <= 0 ) {
            return 0;
        }
        
        return (int) round( ( $success_count / $total ) * 100 );
    }

    /* ---------------------------------------------------------
     *  Dashboard widget
     * --------------------------------------------------------- */

    public function register_dashboard_widget() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'aiss_dashboard_widget',
            'AI Search Summary',
            array( $this, 'render_dashboard_widget' )
        );
    }

    public function render_dashboard_widget() {
        if ( ! $this->logs_table_is_available() ) {
            ?>
            <div style="padding: 20px; text-align: center;">
                <div style="font-size: 48px; opacity: 0.3; margin-bottom: 12px;">📊</div>
                <p style="margin: 0 0 8px 0; font-size: 15px; font-weight: 600; color: #1d1d1f;">
                    No Analytics Data Yet
                </p>
                <p style="margin: 0 0 16px 0; font-size: 13px; color: #6e6e73;">
                    Once visitors use search, stats will appear here.
                </p>
                <a href="<?php echo admin_url( 'admin.php?page=aiss-settings' ); ?>" 
                   style="display: inline-block; padding: 6px 14px; background: #0071e3; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500;">
                    Configure Plugin
                </a>
            </div>
            <?php
            return;
        }

        global $wpdb;
        $table_escaped = self::get_escaped_table_name();

        $totals = $wpdb->get_row(
            "SELECT COUNT(*) AS total, SUM(ai_success) AS success_count
             FROM {$table_escaped}"
        );

        $total_searches = $totals ? (int) $totals->total : 0;
        $success_count  = $totals ? (int) $totals->success_count : 0;
        $success_rate   = $this->calculate_success_rate( $success_count, $total_searches );

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $last_24   = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_escaped} WHERE created_at >= %s",
                $since_24h
            )
        );

        $top_queries = $wpdb->get_results(
            "SELECT search_query, COUNT(*) AS total, SUM(ai_success) AS success_count
             FROM {$table_escaped}
             GROUP BY search_query
             ORDER BY total DESC
             LIMIT 5"
        );
        ?>
        
        <div class="aiss-widget-container">
            <!-- Stats Grid -->
            <div class="aiss-widget-stats-grid">
                <div class="aiss-widget-stat">
                    <span class="aiss-widget-stat-value"><?php echo number_format( $total_searches ); ?></span>
                    <span class="aiss-widget-stat-label">Total Searches</span>
                </div>
                <div class="aiss-widget-stat">
                    <span class="aiss-widget-stat-value"><?php echo esc_html( $success_rate ); ?>%</span>
                    <span class="aiss-widget-stat-label">Success Rate</span>
                </div>
                <div class="aiss-widget-stat">
                    <span class="aiss-widget-stat-value"><?php echo number_format( $last_24 ); ?></span>
                    <span class="aiss-widget-stat-label">Last 24 Hours</span>
                </div>
            </div>

            <div class="aiss-widget-section">
                <h4 class="aiss-widget-section-title">Top Search Queries</h4>
                
                <?php if ( ! empty( $top_queries ) ) : ?>
                    <table class="aiss-widget-table">
                        <thead>
                            <tr>
                                <th>Query</th>
                                <th style="text-align: center; width: 60px;">Count</th>
                                <th style="text-align: center; width: 80px;">Success</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $top_queries as $row ) : ?>
                                <?php
                                $total_q = (int) $row->total;
                                $success_q = (int) $row->success_count;
                                $success_q_rate = $this->calculate_success_rate( $success_q, $total_q );
                                
                                // Determine badge class
                                if ( $success_q_rate >= 90 ) {
                                    $badge_class = 'aiss-widget-badge-success';
                                } elseif ( $success_q_rate >= 70 ) {
                                    $badge_class = 'aiss-widget-badge-warning';
                                } else {
                                    $badge_class = 'aiss-widget-badge-error';
                                }
                                ?>
                                <tr>
                                    <td class="aiss-widget-query">
                                        <?php 
                                        $query_display = esc_html( $row->search_query );
                                        if ( strlen( $query_display ) > 35 ) {
                                            $query_display = substr( $query_display, 0, 32 ) . '...';
                                        }
                                        echo $query_display;
                                        ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="aiss-widget-count"><?php echo number_format( $total_q ); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="aiss-widget-badge <?php echo esc_attr( $badge_class ); ?>">
                                            <?php echo esc_html( $success_q_rate ); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="aiss-widget-empty">
                        No search data yet. Waiting for visitors to use AI search.
                    </div>
                <?php endif; ?>
            </div>

            <div class="aiss-widget-footer">
                <a href="<?php echo admin_url( 'admin.php?page=aiss-analytics' ); ?>" 
                   class="aiss-widget-link">
                    View Full Analytics →
                </a>
            </div>
        </div>
        <?php
    }

    public function enqueue_frontend_assets() {
        if ( is_admin() || ! is_search() ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            return;
        }

        // Add preconnect hint for REST API (helps with subdomains or CDN setups)
        $rest_url = rest_url();
        $parsed = wp_parse_url( $rest_url );
        if ( ! empty( $parsed['host'] ) ) {
            $origin = ( ! empty( $parsed['scheme'] ) ? $parsed['scheme'] : 'https' ) . '://' . $parsed['host'];
            echo '<link rel="preconnect" href="' . esc_url( $origin ) . '" crossorigin>' . "\n";
            echo '<link rel="dns-prefetch" href="' . esc_url( $origin ) . '">' . "\n";
        }

        $version = AI_SEARCH_VERSION;

        wp_enqueue_style(
            'aiss',
            plugin_dir_url( __FILE__ ) . 'assets/aiss.css',
            array(),
            $version
        );

        // Add dynamic color styles
        $color_css = $this->generate_color_css( $options );
        if ( ! empty( $color_css ) ) {
            wp_add_inline_style( 'aiss', $color_css );
        }

        if ( ! empty( $options['custom_css'] ) ) {
            // Defense in depth: sanitize again on output
            $custom_css = $this->sanitize_custom_css( $options['custom_css'] );
            wp_add_inline_style( 'aiss', $custom_css );
        }

        wp_enqueue_script(
            'aiss',
            plugin_dir_url( __FILE__ ) . 'assets/aiss.js',
            array(),
            $version,
            true
        );

        wp_localize_script(
            'aiss',
            'AISSearch',
            array(
                'endpoint'       => rest_url( 'aiss/v1/summary' ),
                'query'          => get_search_query(),
                'cacheVersion'   => $this->get_cache_namespace(),
                'requestTimeout' => isset( $options['request_timeout'] ) ? (int) $options['request_timeout'] : 60,
            )
        );
    }

    public function inject_ai_summary_placeholder( $query ) {
        if ( ! $query->is_main_query() || ! $query->is_search() || is_admin() ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            return;
        }

        static $done = false;
        if ( $done ) {
            return;
        }
        $done = true;

        $paged = get_query_var( 'paged' ) ? get_query_var( 'paged' ) : 1;
        if ( $paged > 1 ) {
            return;
        }

        $search_query = get_search_query();
        $site_name = ! empty( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' );
        $show_badge = isset( $options['show_openai_badge'] ) ? $options['show_openai_badge'] : 1;
        ?>
        <div class="aiss-summary" style="margin-bottom: 1.5rem;">
            <div class="aiss-summary-inner" style="padding: 1.25rem 1.25rem; border-radius: 10px; border-width: 1px; border-style: solid; display:flex; flex-direction:column; gap:0.6rem;">
                <div class="aiss-summary-header" style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
                    <h2 style="margin:0; font-size:1.1rem;">
                        AI summary for "<?php echo esc_html( $search_query ); ?>"
                    </h2>
                    <?php if ( $show_badge ) : ?>
                    <span class="aiss-openai-badge" aria-label="Powered by OpenAI">
                        <span class="aiss-openai-mark" aria-hidden="true"></span>
                        <span class="aiss-openai-text">Powered by OpenAI</span>
                    </span>
                    <?php endif; ?>
                </div>

                <div id="aiss-search-summary-content" class="aiss-search-summary-content" aria-live="polite">
                    <span class="aiss-spinner" role="status" aria-label="Loading AI summary"></span>
                    <p class="aiss-loading-text">Generating summary based on your search and <?php echo esc_html( $site_name ); ?> articles...</p>
                </div>

                <div class="aiss-disclaimer" style="margin-top:0.75rem; font-size:0.75rem; line-height:1.4; opacity:0.65;">
                    AI summaries are generated automatically based on <?php echo esc_html( $site_name ); ?> articles and may be inaccurate or incomplete. Always verify important details.
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_assets( $hook ) {
        $allowed_hooks = array(
            'toplevel_page_aiss-settings',
            'ai-search_page_aiss-analytics',
        );

        $is_our_page = in_array( $hook, $allowed_hooks, true ) ||
                       strpos( $hook, 'aiss' ) !== false;

        if ( ! $is_our_page ) {
            return;
        }

        $version = AI_SEARCH_VERSION;

        // Enqueue WordPress color picker
        wp_enqueue_style( 'wp-color-picker' );
        wp_enqueue_script( 'wp-color-picker' );

        wp_enqueue_style(
            'aiss-admin',
            plugin_dir_url( __FILE__ ) . 'assets/aiss-admin.css',
            array( 'wp-color-picker' ),
            $version
        );
    }

    private function is_likely_bot() {
        // No user agent = definitely suspicious
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) || empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return true;
        }

        $user_agent = strtolower( $_SERVER['HTTP_USER_AGENT'] );

        // Very short user agents are suspicious (real browsers have long UA strings)
        if ( strlen( $user_agent ) < 20 ) {
            return true;
        }

        // Common bot patterns in user agent
        $bot_patterns = array(
            'bot', 'crawl', 'spider', 'slurp', 'scanner',
            'scraper', 'curl', 'wget', 'python', 'java/',
            'libwww', 'httpunit', 'nutch', 'phpcrawl',
            'msnbot', 'adidxbot', 'blekkobot', 'teoma',
            'gigabot', 'dotbot', 'yandex', 'seokicks',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'baiduspider',
            'headless', 'phantom', 'selenium', 'puppeteer',
            'playwright', 'webdriver', 'httpclient', 'okhttp',
            'go-http-client', 'apache-httpclient', 'node-fetch',
            'axios', 'request/', 'postman', 'insomnia',
        );

        foreach ( $bot_patterns as $pattern ) {
            if ( strpos( $user_agent, $pattern ) !== false ) {
                return true;
            }
        }

        // Check for missing standard browser headers
        // Real browsers always send Accept-Language
        if ( empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
            return true;
        }

        // Real browsers send Accept header with text/html or */*
        if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
            return true;
        }

        // Check for headless browser indicators
        // Headless Chrome often has specific patterns
        if ( strpos( $user_agent, 'headlesschrome' ) !== false ) {
            return true;
        }

        // Check if user agent claims to be a browser but lacks typical browser headers
        $claims_browser = (
            strpos( $user_agent, 'mozilla' ) !== false ||
            strpos( $user_agent, 'chrome' ) !== false ||
            strpos( $user_agent, 'safari' ) !== false ||
            strpos( $user_agent, 'firefox' ) !== false ||
            strpos( $user_agent, 'edge' ) !== false
        );

        if ( $claims_browser ) {
            // Browsers should have Accept-Encoding header
            if ( empty( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP is rate limited and track the request.
     *
     * @param string $ip Client IP address.
     * @return bool True if rate limited.
     */
    private function is_ip_rate_limited( $ip ) {
        $rate_info = $this->get_rate_limit_info( $ip );

        if ( $rate_info['remaining'] <= 0 ) {
            return true;
        }

        // Increment the counter
        $key = 'aiss_ip_rate_' . md5( $ip ) . '_' . gmdate( 'YmdHi' );
        set_transient( $key, $rate_info['used'] + 1, AISS_RATE_LIMIT_WINDOW );

        return false;
    }

    /**
     * Get rate limit information for an IP.
     *
     * @param string $ip Client IP address.
     * @return array Rate limit info with 'limit', 'remaining', 'used', and 'reset' keys.
     */
    private function get_rate_limit_info( $ip ) {
        $key   = 'aiss_ip_rate_' . md5( $ip ) . '_' . gmdate( 'YmdHi' );
        $limit = AISS_IP_RATE_LIMIT;
        $used  = (int) get_transient( $key );

        // Reset time is the start of the next minute
        $current_minute = (int) gmdate( 'i' );
        $current_second = (int) gmdate( 's' );
        $reset_in = 60 - $current_second;

        return array(
            'limit'     => $limit,
            'remaining' => max( 0, $limit - $used ),
            'used'      => $used,
            'reset'     => time() + $reset_in,
        );
    }

    private function get_client_ip() {
        // Use REMOTE_ADDR by default - it's the only non-spoofable source.
        // Sites behind trusted reverse proxies can define AISS_TRUSTED_PROXY_HEADER
        // to read from X-Forwarded-For or similar headers.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

        // Allow sites behind trusted proxies to use forwarded headers
        if ( defined( 'AISS_TRUSTED_PROXY_HEADER' ) && AISS_TRUSTED_PROXY_HEADER ) {
            $header = 'HTTP_' . strtoupper( str_replace( '-', '_', AISS_TRUSTED_PROXY_HEADER ) );
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // Take the first IP in the list (original client)
                $ips = explode( ',', $_SERVER[ $header ] );
                $forwarded_ip = trim( $ips[0] );
                if ( filter_var( $forwarded_ip, FILTER_VALIDATE_IP ) ) {
                    $ip = $forwarded_ip;
                }
            }
        }

        // Validate IP
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }

        return 'unknown';
    }

    public function register_rest_routes() {
        register_rest_route(
            'aiss/v1',
            '/summary',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_summary' ),
                'permission_callback' => array( $this, 'rest_permission_check' ),
                'args'                => array(
                    'q' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array( $this, 'validate_search_query' ),
                    ),
                ),
            )
        );

        // Lightweight endpoint for logging frontend (session) cache hits
        register_rest_route(
            'aiss/v1',
            '/log-session-hit',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_log_session_cache_hit' ),
                'permission_callback' => array( $this, 'rest_permission_check' ),
                'args'                => array(
                    'q' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array( $this, 'validate_search_query' ),
                    ),
                    'results_count' => array(
                        'required'          => false,
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                    ),
                ),
            )
        );
    }

    /**
     * Log a frontend session cache hit to analytics.
     * This is a lightweight endpoint that only logs - no AI processing.
     *
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function rest_log_session_cache_hit( $request ) {
        $search_query  = $request->get_param( 'q' );
        $results_count = $request->get_param( 'results_count' );

        if ( empty( $search_query ) ) {
            return rest_ensure_response( array( 'logged' => false ) );
        }

        // Log as a session cache hit (cache_hit = 2 to distinguish from server cache hits)
        // We use 2 to indicate "session/browser cache hit" vs 1 for "server cache hit"
        $this->log_search_event( $search_query, $results_count, 1, '', 2 );

        return rest_ensure_response( array( 'logged' => true ) );
    }

    /**
     * Add rate limit headers to REST API responses.
     *
     * @param WP_REST_Response $response Response object.
     * @param WP_REST_Server   $server   Server instance.
     * @param WP_REST_Request  $request  Request object.
     * @return WP_REST_Response Modified response.
     */
    public function add_rate_limit_headers( $response, $server, $request ) {
        // Only add headers to our plugin's endpoints
        $route = $request->get_route();
        if ( strpos( $route, '/aiss/' ) === false ) {
            return $response;
        }

        $client_ip  = $this->get_client_ip();
        $rate_info  = $this->get_rate_limit_info( $client_ip );

        $response->header( 'X-RateLimit-Limit', $rate_info['limit'] );
        $response->header( 'X-RateLimit-Remaining', max( 0, $rate_info['remaining'] - 1 ) );
        $response->header( 'X-RateLimit-Reset', $rate_info['reset'] );

        return $response;
    }

    /**
     * Permission callback for REST API endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if allowed, WP_Error if blocked.
     */
    public function rest_permission_check( WP_REST_Request $request ) {
        // Block obvious bots to save API costs
        if ( $this->is_likely_bot() ) {
            return new WP_Error(
                AISS_ERROR_BOT_DETECTED,
                'AI search is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

        // Per-IP rate limiting (more aggressive than global limit)
        $client_ip = $this->get_client_ip();
        if ( $this->is_ip_rate_limited( $client_ip ) ) {
            $rate_info = $this->get_rate_limit_info( $client_ip );
            return new WP_Error(
                AISS_ERROR_RATE_LIMITED,
                'Too many requests from your IP address. Please try again in a minute.',
                array(
                    'status'     => 429,
                    'retry_after' => $rate_info['reset'] - time(),
                )
            );
        }

        return true;
    }

    /**
     * Validate search query parameter.
     *
     * @param mixed           $value   Query value.
     * @param WP_REST_Request $request Request object.
     * @param string          $param   Parameter name.
     * @return bool True if valid.
     */
    public function validate_search_query( $value, $request, $param ) {
        // Query must be a string
        if ( ! is_string( $value ) ) {
            return false;
        }

        // Must not be empty after trimming
        if ( empty( trim( $value ) ) ) {
            return false;
        }

        // Reasonable length limits (prevent abuse)
        $length = strlen( $value );
        if ( $length < 2 || $length > 200 ) {
            return false;
        }

        return true;
    }

    /**
     * Intelligently truncate text at sentence boundaries.
     * 
     * Attempts to cut at the last complete sentence within the limit.
     * Falls back to word boundary if no sentence ending is found.
     *
     * @param string $text Text to truncate.
     * @param int    $limit Maximum length in characters.
     * @return string Truncated text.
     */

    private function safe_substr( $text, $start, $length ) {
        if ( function_exists( 'mb_substr' ) ) {
            return mb_substr( $text, $start, $length );
        }
        return substr( $text, $start, $length );
    }
    
    private function smart_truncate( $text, $limit ) {
        if ( empty( $text ) ) {
            return '';
        }

        // Use safe_substr for multibyte support
        if ( $this->safe_substr( $text, 0, $limit ) === $text ) {
            // Text is already shorter than limit
            return $text;
        }

        // Get text up to limit
        $truncated = $this->safe_substr( $text, 0, $limit );

        // Try to find last sentence ending (., !, ?)
        $sentence_endings = array( '. ', '! ', '? ', '."', '!"', '?"', ".'", "!'", "?'" );
        $last_sentence_pos = 0;

        foreach ( $sentence_endings as $ending ) {
            $pos = strrpos( $truncated, $ending );
            if ( $pos !== false && $pos > $last_sentence_pos ) {
                $last_sentence_pos = $pos + strlen( $ending );
            }
        }

        // If we found a sentence ending and it's not too early (at least 50% of limit)
        if ( $last_sentence_pos > 0 && $last_sentence_pos >= ( $limit * 0.5 ) ) {
            return trim( $this->safe_substr( $truncated, 0, $last_sentence_pos ) );
        }

        // Fall back to word boundary
        $last_space = strrpos( $truncated, ' ' );
        if ( $last_space !== false && $last_space >= ( $limit * 0.7 ) ) {
            return trim( $this->safe_substr( $truncated, 0, $last_space ) ) . '...';
        }

        // Last resort: hard cut with ellipsis
        return $truncated . '...';
    }

    // Updated rest_get_summary() to use smart truncation
    public function rest_get_summary( WP_REST_Request $request ) {
        $options = $this->get_options();

        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            $this->log_search_event( $request->get_param( 'q' ), 0, 0, 'AI search not enabled or API key missing' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => 'AI search is not enabled.',
                    'error_code'  => AISS_ERROR_NOT_CONFIGURED,
                )
            );
        }

        $search_query = $request->get_param( 'q' );
        if ( ! $search_query ) {
            $this->log_search_event( '', 0, 0, 'Missing search query' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => 'Missing search query.',
                    'error_code'  => AISS_ERROR_INVALID_QUERY,
                )
            );
        }

        $max_posts = (int) $options['max_posts'];
        if ( $max_posts < 1 ) {
            $max_posts = 20;
        }

        $post_type = 'any';

        // Single optimized query that gets all posts sorted by relevance and recency
        $search_args = array(
            's'              => $search_query,
            'post_type'      => $post_type,
            'posts_per_page' => $max_posts,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $search_results = new WP_Query( $search_args );

        $posts_for_ai = array();

        if ( $search_results->have_posts() ) {
            foreach ( $search_results->posts as $post ) {
                $content = wp_strip_all_tags( $post->post_content );
                
                // Use smart truncation for better sentence boundaries
                $truncated_content = $this->smart_truncate( $content, AISS_CONTENT_LENGTH );
                $excerpt = $this->smart_truncate( $content, AISS_EXCERPT_LENGTH );

                $posts_for_ai[] = array(
                    'id'      => $post->ID,
                    'title'   => get_the_title( $post ),
                    'url'     => get_permalink( $post ),
                    'excerpt' => $excerpt,
                    'content' => $truncated_content,
                    'type'    => $post->post_type,
                    'date'    => get_the_date( 'Y-m-d', $post ),
                );
            }
        }

        $results_count = count( $posts_for_ai );
        $ai_error      = '';
        $cache_hit     = null;
        $ai_data       = $this->get_ai_data_for_search( $search_query, $posts_for_ai, $ai_error, $cache_hit );

        if ( ! $ai_data ) {
            $this->log_search_event( $search_query, $results_count, 0, $ai_error ? $ai_error : 'AI summary not available', $cache_hit );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => $ai_error ? $ai_error : 'AI summary is not available right now.',
                    'error_code'  => AISS_ERROR_API_ERROR,
                )
            );
        }

        $this->log_search_event( $search_query, $results_count, 1, '', $cache_hit );

        $answer_html = isset( $ai_data['answer_html'] ) ? (string) $ai_data['answer_html'] : '';
        $sources     = isset( $ai_data['results'] ) && is_array( $ai_data['results'] ) ? $ai_data['results'] : array();

        $allowed_tags = array(
            'p'  => array(),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'h3' => array(),
            'h4' => array(),
            'a'  => array(
                'href'   => array(),
                'title'  => array(),
                'target' => array(),
                'rel'    => array(),
            ),
        );

        $answer_html = wp_kses( $answer_html, $allowed_tags );

        // Add sources if enabled in settings
        $options = $this->get_options();
        $show_sources = isset( $options['show_sources'] ) ? $options['show_sources'] : 1;
        if ( $show_sources && ! empty( $sources ) ) {
            $answer_html .= $this->render_sources_html( $sources );
        }

        return rest_ensure_response(
            array(
                'answer_html'   => $answer_html,
                'results_count' => $results_count,
                'error'         => '',
            )
        );
    }

    private function is_rate_limited_for_ai_calls() {
        $options = $this->get_options();
        $limit   = isset( $options['max_calls_per_minute'] ) ? (int) $options['max_calls_per_minute'] : 0;

        if ( $limit <= 0 ) {
            return false;
        }

        $key   = 'aiss_rate_' . gmdate( 'YmdHi' );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return true;
        }

        $count++;
        set_transient( $key, $count, AISS_RATE_LIMIT_WINDOW );

        return false;
    }

    private function get_ai_data_for_search( $search_query, $posts_for_ai, &$ai_error = '', &$cache_hit = null ) {
        $options = $this->get_options();
        if ( empty( $options['api_key'] ) || empty( $options['enable'] ) ) {
            $ai_error = 'AI search is not configured. Please contact the site administrator.';
            $cache_hit = null; // Not applicable - config error
            return null;
        }

        $normalized_query = strtolower( trim( $search_query ) );
        $namespace        = $this->get_cache_namespace();

        $cache_key_data = implode( '|', array(
            $options['model'],
            $options['max_posts'],
            $normalized_query
        ) );

        $cache_key        = $this->cache_prefix . 'ns' . $namespace . '_' . md5( $cache_key_data );
        $cached_raw       = get_transient( $cache_key );

        if ( $cached_raw ) {
            $ai_data = json_decode( $cached_raw, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $ai_data ) ) {
                $cache_hit = true;
                return $ai_data;
            }
        }

        // Cache miss - will make API call
        $cache_hit = false;

        if ( $this->is_rate_limited_for_ai_calls() ) {
            $ai_error = 'Too many AI requests right now. Please try again in a minute.';
            return null;
        }

        $api_response = $this->call_openai_for_search(
            $options['api_key'],
            $options['model'],
            $search_query,
            $posts_for_ai
        );

        if ( isset( $api_response['error'] ) ) {
            // Log detailed error for debugging, but show generic message to users
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] API error: ' . $api_response['error'] );
            }
            $ai_error = 'The AI service encountered an error. Please try again later.';
            return null;
        }

        // Check for model refusal (newer models)
        if ( ! empty( $api_response['choices'][0]['message']['refusal'] ) ) {
            $ai_error = 'The AI model declined to answer this query.';
            return null;
        }

        // Get content - check multiple possible locations
        $raw_content = null;
        if ( ! empty( $api_response['choices'][0]['message']['content'] ) ) {
            $raw_content = $api_response['choices'][0]['message']['content'];
        } elseif ( ! empty( $api_response['choices'][0]['text'] ) ) {
            // Legacy completion format
            $raw_content = $api_response['choices'][0]['text'];
        } elseif ( ! empty( $api_response['output'] ) ) {
            // Some newer models use 'output' field
            $raw_content = is_array( $api_response['output'] )
                ? wp_json_encode( $api_response['output'] )
                : $api_response['output'];
        }

        if ( empty( $raw_content ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Empty response. Full API response: ' . wp_json_encode( $api_response ) );
            }
            // Check if there's a finish_reason that explains the empty response
            $finish_reason = $api_response['choices'][0]['finish_reason'] ?? 'unknown';
            // Log detailed reason for debugging
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Empty response with finish_reason: ' . $finish_reason );
            }
            if ( $finish_reason === 'content_filter' ) {
                $ai_error = 'The response was filtered by content policy. Please try a different search.';
            } elseif ( $finish_reason === 'length' ) {
                $ai_error = 'The response was truncated. Please try a simpler search.';
            } else {
                $ai_error = 'AI summary is not available for this search. Please try again.';
            }
            return null;
        }

        if ( is_array( $raw_content ) ) {
            $decoded = $raw_content;
        } else {
            $decoded = json_decode( $raw_content, true );

            if ( json_last_error() !== JSON_ERROR_NONE ) {
                $first = strpos( $raw_content, '{' );
                $last  = strrpos( $raw_content, '}' );
                if ( $first !== false && $last !== false && $last > $first ) {
                    $json_candidate = substr( $raw_content, $first, $last - $first + 1 );
                    $decoded        = json_decode( $json_candidate, true );
                }
            }
        }

        if ( ! is_array( $decoded ) ) {
            $ai_error = 'Could not parse AI response. The service may be experiencing issues.';
            return null;
        }

        if ( isset( $decoded['answer_html'] ) && is_string( $decoded['answer_html'] ) ) {
            $inner = trim( $decoded['answer_html'] );
            if ( strlen( $inner ) > 0 && $inner[0] === '{' && strpos( $inner, '"answer_html"' ) !== false ) {
                $inner_decoded = json_decode( $inner, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $inner_decoded ) && isset( $inner_decoded['answer_html'] ) ) {
                    $decoded = $inner_decoded;
                }
            }
        }

        if ( empty( $decoded['answer_html'] ) ) {
            $decoded['answer_html'] = '<p>AI summary did not return a valid answer.</p>';
        }

        if ( empty( $decoded['results'] ) || ! is_array( $decoded['results'] ) ) {
            $decoded['results'] = array();
        }

        $ttl_option = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 0;
        $ttl        = $ttl_option > 0 ? $ttl_option : $this->cache_ttl;

        set_transient( $cache_key, wp_json_encode( $decoded ), $ttl );

        return $decoded;
    }

    // Updated call_openai_for_search() with retry logic for transient errors
    private function call_openai_for_search( $api_key, $model, $user_query, $posts ) {
        if ( empty( $api_key ) ) {
            return array( 'error' => 'API key is missing. Please configure the plugin settings.' );
        }

        $endpoint = 'https://api.openai.com/v1/chat/completions';

        $posts_text = '';
        foreach ( $posts as $p ) {
            $date = isset( $p['date'] ) ? $p['date'] : '';
            $posts_text .= "ID: {$p['id']}\n";
            $posts_text .= "Title: {$p['title']}\n";
            $posts_text .= "URL: {$p['url']}\n";
            $posts_text .= "Type: {$p['type']}\n";
            if ( $date ) {
                $posts_text .= "Published: {$date}\n";
            }
            $posts_text .= "Content: {$p['content']}\n";
            $posts_text .= "-----\n";
        }

        // Get site configuration for the prompt
        $options = $this->get_options();
        $site_name = ! empty( $options['site_name'] ) ? $options['site_name'] : get_bloginfo( 'name' );
        $site_desc = ! empty( $options['site_description'] ) ? ', ' . $options['site_description'] : '';

        $system_message = "You are the AI search engine for {$site_name}{$site_desc}.
    Use the provided posts as your entire knowledge base.
    Answer the user query based only on these posts.
    Prefer newer posts over older ones when there is conflicting or overlapping information, especially for news, software updates, or product changes.
    If something is not covered, say that the site does not have that information yet instead of making something up.

    IMPORTANT: This is a one-way search interface - users cannot reply or provide clarification. Never ask follow-up questions, never ask the user to clarify, and never suggest they tell you more. Instead, provide the most comprehensive answer possible covering all likely interpretations of their query. If a query is ambiguous, briefly cover the most relevant possibilities.

    Always respond as a single JSON object using this structure:
    {
      \"answer_html\": \"HTML formatted summary answer for the user\",
      \"results\": [
         {
           \"id\": 123,
           \"title\": \"Post title\",
           \"url\": \"https://...\",
           \"excerpt\": \"Short snippet\",
           \"type\": \"post or page\"
         }
      ]
    }

    The results array should list up to 5 of the most relevant posts you used when creating the summary, so they can be shown as sources under the answer.";

        $user_message  = "User search query: {$user_query}\n\n";
        $user_message .= "Here are the posts from the site (with newer posts listed first where possible):\n\n{$posts_text}";

        // Determine model capabilities
        $is_gpt5 = strpos( $model, 'gpt-5' ) === 0;
        $is_o_series = strpos( $model, 'o1' ) === 0 || strpos( $model, 'o3' ) === 0;

        // GPT-4o and GPT-4.1 support json_object response format
        // GPT-5 and o-series may have different requirements
        $supports_response_format = (
            strpos( $model, 'gpt-4o' ) === 0 ||
            strpos( $model, 'gpt-4.1' ) === 0
        );

        $body = array(
            'model'    => $model,
            'messages' => array(
                array(
                    'role'    => 'system',
                    'content' => $system_message,
                ),
                array(
                    'role'    => 'user',
                    'content' => $user_message,
                ),
            ),
        );

        // Newer models (gpt-5, o1, o3) use max_completion_tokens instead of max_tokens
        // These models also use "reasoning tokens" which count against the limit,
        // so we need a much higher limit to leave room for actual output
        if ( $is_gpt5 || $is_o_series ) {
            // Reasoning models need higher limits: reasoning tokens + output tokens
            // Using 16000 to allow for extensive reasoning while still getting output
            $body['max_completion_tokens'] = 16000;
        } else {
            $body['max_tokens'] = AISS_MAX_TOKENS;
        }

        // o-series (reasoning models) don't support temperature
        // gpt-5 may have different defaults
        if ( ! $is_o_series && ! $is_gpt5 ) {
            $body['temperature'] = 0.2;
        }

        if ( $supports_response_format ) {
            $body['response_format'] = array( 'type' => 'json_object' );
        }

        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $body ),
            'timeout' => AISS_API_TIMEOUT,
        );

        // Retry logic: attempt up to 3 times with exponential backoff for transient errors
        $max_retries = 2; // 2 retries = 3 total attempts
        $attempt = 0;
        $last_error = null;

        while ( $attempt <= $max_retries ) {
            $result = $this->make_openai_request( $endpoint, $args );

            // Success - return the decoded response
            if ( isset( $result['success'] ) && $result['success'] ) {
                return $result['data'];
            }

            // Check if error is retryable
            $is_retryable = isset( $result['retryable'] ) && $result['retryable'];
            $last_error = $result;

            if ( ! $is_retryable || $attempt >= $max_retries ) {
                // Non-retryable error or max retries reached
                break;
            }

            // Exponential backoff: 1s, 2s
            $delay = pow( 2, $attempt );
            sleep( $delay );

            $attempt++;

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Retry attempt ' . ( $attempt + 1 ) . ' after ' . $delay . 's delay' );
            }
        }

        // Return the last error
        return array( 'error' => $last_error['error'] ?? 'Unknown error occurred.' );
    }

    /**
     * Make the actual HTTP request to OpenAI.
     * Returns array with 'success', 'data'/'error', and 'retryable' flag.
     */
    private function make_openai_request( $endpoint, $args ) {
        $response = wp_safe_remote_post( $endpoint, $args );

        // Connection/network errors
        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] API request error: ' . $error_msg );
            }

            // Timeouts and connection errors are retryable
            $is_timeout = strpos( $error_msg, 'cURL error 28' ) !== false || strpos( $error_msg, 'timed out' ) !== false;
            $is_connection = strpos( $error_msg, 'cURL error 6' ) !== false || strpos( $error_msg, 'resolve host' ) !== false;

            if ( $is_timeout ) {
                return array(
                    'success'   => false,
                    'error'     => 'Request timed out. The AI service may be slow right now. Please try again.',
                    'retryable' => true,
                );
            }
            if ( $is_connection ) {
                return array(
                    'success'   => false,
                    'error'     => 'Could not connect to AI service. Please check your internet connection.',
                    'retryable' => true,
                );
            }

            // Generic message - detailed error already logged above
            return array(
                'success'   => false,
                'error'     => 'Could not connect to AI service. Please try again.',
                'retryable' => true, // Most connection errors are worth retrying
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        // HTTP errors
        if ( $code < 200 || $code >= 300 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] API HTTP error ' . $code . ' body: ' . $body );
            }

            $decoded_error = json_decode( $body, true );
            $api_error = isset( $decoded_error['error']['message'] ) ? $decoded_error['error']['message'] : null;

            // 429 Rate limit - retryable
            if ( $code === 429 ) {
                return array(
                    'success'   => false,
                    'error'     => 'OpenAI rate limit exceeded. Please try again in a few moments.',
                    'retryable' => true,
                );
            }

            // 5xx Server errors - retryable
            if ( $code >= 500 && $code < 600 ) {
                return array(
                    'success'   => false,
                    'error'     => 'OpenAI service temporarily unavailable. Please try again later.',
                    'retryable' => true,
                );
            }

            // 401 Invalid API key - NOT retryable
            if ( $code === 401 ) {
                return array(
                    'success'   => false,
                    'error'     => 'Invalid API key. Please check your plugin settings.',
                    'retryable' => false,
                );
            }

            // 400 Bad request - NOT retryable
            if ( $code === 400 ) {
                return array(
                    'success'   => false,
                    'error'     => 'The request could not be processed. Please try a different search.',
                    'retryable' => false,
                );
            }

            // Other errors - details already logged above
            return array(
                'success'   => false,
                'error'     => 'AI service error. Please try again later.',
                'retryable' => false,
            );
        }

        // Parse JSON response
        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[AI Search Summary] Failed to decode OpenAI response: ' . json_last_error_msg() );
            }
            return array(
                'success'   => false,
                'error'     => 'Could not understand AI response. Please try again.',
                'retryable' => true, // Malformed responses might be transient
            );
        }

        // Success
        return array(
            'success' => true,
            'data'    => $decoded,
        );
    }

    private function render_sources_html( $sources ) {
        if ( empty( $sources ) || ! is_array( $sources ) ) {
            return '';
        }

        $sources = array_slice( $sources, 0, AISS_MAX_SOURCES_DISPLAY );
        $count   = count( $sources );

        $show_label = 'Show sources (' . intval( $count ) . ')';
        $hide_label = 'Hide sources';

        $html  = '<div class="aiss-sources">';
        $html .= '<button type="button" class="aiss-sources-toggle" aria-expanded="false" aria-controls="aiss-sources-list" data-label-show="' . esc_attr( $show_label ) . '" data-label-hide="' . esc_attr( $hide_label ) . '">';
        $html .= esc_html( $show_label );
        $html .= '</button>';
        $html .= '<ul id="aiss-sources-list" class="aiss-sources-list" hidden>';

        foreach ( $sources as $src ) {
            $title   = isset( $src['title'] ) ? $src['title'] : '';
            $url     = isset( $src['url'] ) ? $src['url'] : '';
            $excerpt = isset( $src['excerpt'] ) ? $src['excerpt'] : '';

            if ( ! $title && ! $url ) {
                continue;
            }

            $html .= '<li>';

            if ( $url ) {
                $html .= '<a href="' . esc_url( $url ) . '">';
                $html .= $title ? esc_html( $title ) : esc_html( $url );
                $html .= '</a>';
            } else {
                $html .= esc_html( $title );
            }

            if ( $excerpt ) {
                $html .= '<span>' . esc_html( $excerpt ) . '</span>';
            }

            $html .= '</li>';
        }

        $html .= '</ul></div>';

        return $html;
    }
}

register_activation_hook( __FILE__, array( 'AI_Search_Summary', 'activate' ) );

new AI_Search_Summary();