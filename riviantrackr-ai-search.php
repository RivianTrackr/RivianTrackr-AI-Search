<?php
declare(strict_types=1);
/**
 * Plugin Name: RivianTrackr AI Search
 * Plugin URI: https://github.com/RivianTrackr/RivianTrackr-AI-Search
 * Description: Add an OpenAI powered AI summary to WordPress search on RivianTrackr.com without delaying normal results, with analytics, cache control, and collapsible sources.
 * Version: 3.3.7
 * Author URI: https://riviantrackr.com
 * Author: RivianTrackr
 * License: GPL v2 or later
 */

define( 'RT_AI_SEARCH_VERSION', '3.3.8' );
define( 'RT_AI_SEARCH_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );
define( 'RT_AI_SEARCH_MIN_CACHE_TTL', 60 );
define( 'RT_AI_SEARCH_MAX_CACHE_TTL', 86400 );
define( 'RT_AI_SEARCH_DEFAULT_CACHE_TTL', 3600 );
define( 'RT_AI_SEARCH_CONTENT_LENGTH', 400 );
define( 'RT_AI_SEARCH_EXCERPT_LENGTH', 200 );
define( 'RT_AI_SEARCH_MAX_SOURCES_DISPLAY', 5 );
define( 'RT_AI_SEARCH_API_TIMEOUT', 60 );
define( 'RT_AI_SEARCH_RATE_LIMIT_WINDOW', 70 );
define( 'RT_AI_SEARCH_MAX_TOKENS', 1500 );
define( 'RT_AI_SEARCH_IP_RATE_LIMIT', 10 ); // Requests per minute per IP

// Error codes for structured API responses
define( 'RT_AI_ERROR_BOT_DETECTED', 'bot_detected' );
define( 'RT_AI_ERROR_RATE_LIMITED', 'rate_limited' );
define( 'RT_AI_ERROR_NOT_CONFIGURED', 'not_configured' );
define( 'RT_AI_ERROR_INVALID_QUERY', 'invalid_query' );
define( 'RT_AI_ERROR_API_ERROR', 'api_error' );
define( 'RT_AI_ERROR_NO_RESULTS', 'no_results' );


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RivianTrackr_AI_Search {

    private $option_name         = 'rt_ai_search_options';
    private $models_cache_option = 'rt_ai_search_models_cache';
    private $cache_keys_option      = 'rt_ai_search_cache_keys';
    private $cache_namespace_option = 'rt_ai_search_cache_namespace';
    private $cache_prefix;
    private $cache_ttl           = 3600;

    private $logs_table_checked = false;
    private $logs_table_exists  = false;
    private $options_cache      = null;

    public function __construct() {

        $this->cache_prefix = 'rt_ai_search_v' . str_replace( '.', '_', RT_AI_SEARCH_VERSION ) . '_';

        // Register settings on admin_init (the recommended hook for Settings API)
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_init', array( $this, 'maybe_run_migrations' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_post_dispatch', array( $this, 'add_rate_limit_headers' ), 10, 3 );
        add_action( 'wp_ajax_rt_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'wp_ajax_rt_ai_refresh_models', array( $this, 'ajax_refresh_models' ) );
        add_action( 'wp_ajax_rt_ai_clear_cache', array( $this, 'ajax_clear_cache' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_print_styles-index.php', array( $this, 'enqueue_dashboard_widget_css' ) );

        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_settings_link' ) );
    }

    public function add_plugin_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=rt-ai-search-settings' );
        $settings_link = '<a href="' . esc_url( $url ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    public function enqueue_dashboard_widget_css() {
        wp_enqueue_style(
            'rt-ai-search-admin',
            plugin_dir_url( __FILE__ ) . 'assets/rt-ai-search-admin.css',
            array(),
            RT_AI_SEARCH_VERSION
        );
    }

    private static function get_logs_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'rt_ai_search_logs';
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

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        // Get existing indexes
        $indexes = $wpdb->get_results( "SHOW INDEX FROM $table_name" );
        $index_names = array();
        foreach ( $indexes as $index ) {
            $index_names[] = $index->Key_name;
        }

        // Add search_query_created index if missing
        if ( ! in_array( 'search_query_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD INDEX search_query_created (search_query(100), created_at)"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] Added search_query_created index' );
            }
        }

        // Add ai_success_created index if missing
        if ( ! in_array( 'ai_success_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD INDEX ai_success_created (ai_success, created_at)"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] Added ai_success_created index' );
            }
        }

        // Add cache_hit_created index if missing
        if ( ! in_array( 'cache_hit_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD INDEX cache_hit_created (cache_hit, created_at)"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] Added cache_hit_created index' );
            }
        }

        return true;
    }

    private static function add_missing_columns() {
        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Check if table exists
        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        // Get existing columns
        $columns = $wpdb->get_results( "SHOW COLUMNS FROM $table_name" );
        $column_names = array();
        foreach ( $columns as $column ) {
            $column_names[] = $column->Field;
        }

        // Add cache_hit column if missing
        if ( ! in_array( 'cache_hit', $column_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE $table_name
                 ADD COLUMN cache_hit tinyint(1) NULL DEFAULT NULL AFTER ai_error"
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] Added cache_hit column' );
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
        $db_version = get_option( 'rt_ai_search_db_version', '1.0' );

        // Version 1.1 adds cache_hit column
        if ( version_compare( $db_version, '1.1', '<' ) ) {
            self::add_missing_columns();
            self::add_missing_indexes();
            update_option( 'rt_ai_search_db_version', '1.1' );
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
        $table_name = self::get_logs_table_name();
        $cutoff_date = gmdate( 'Y-m-d H:i:s', time() - ( absint( $days ) * DAY_IN_SECONDS ) );

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE created_at < %s",
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
                '[RivianTrackr AI Search] Failed to log search event: ' .
                $wpdb->last_error .
                ' | Query: ' . substr( $search_query, 0, 50 )
            );
        }
    }

    public function get_options() {
        if ( is_array( $this->options_cache ) ) {
            return $this->options_cache;
        }

        $defaults = array(
            'api_key'              => '',
            'model'                => 'gpt-4o-mini',
            'max_posts'            => 10,
            'enable'               => 0,
            'max_calls_per_minute' => 30,
            'cache_ttl'            => RT_AI_SEARCH_DEFAULT_CACHE_TTL,
            'custom_css'           => '',
        );

        $opts = get_option( $this->option_name, array() );
        $this->options_cache = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

        return $this->options_cache;
    }

    public function sanitize_options( $input ) {
        if (!is_array($input)) {
            $input = array();
        }
        
        $output = array();

        $output['api_key']   = isset($input['api_key']) ? sanitize_text_field( trim($input['api_key']) ) : '';
        $output['model']     = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4o-mini';
        $output['max_posts'] = isset($input['max_posts']) ? max(1, intval($input['max_posts'])) : 10;
        
        $output['enable'] = isset($input['enable']) && $input['enable'] ? 1 : 0;
        
        $output['max_calls_per_minute'] = isset($input['max_calls_per_minute'])
            ? max(0, intval($input['max_calls_per_minute']))
            : 30;
            
        if (isset($input['cache_ttl'])) {
            $ttl = intval($input['cache_ttl']);
            if ($ttl < RT_AI_SEARCH_MIN_CACHE_TTL) {
                $ttl = RT_AI_SEARCH_MIN_CACHE_TTL;
            } elseif ($ttl > RT_AI_SEARCH_MAX_CACHE_TTL) {
                $ttl = RT_AI_SEARCH_MAX_CACHE_TTL;
            }
            $output['cache_ttl'] = $ttl;
        } else {
            $output['cache_ttl'] = RT_AI_SEARCH_DEFAULT_CACHE_TTL;
        }
        
        $output['custom_css'] = isset($input['custom_css']) ? $this->sanitize_custom_css($input['custom_css']) : '';

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

    public function add_settings_page() {
        $capability  = 'manage_options';
        $parent_slug = 'rt-ai-search-settings';

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
            'rt-ai-search-analytics',
            array( $this, 'render_analytics_page' )
        );
    }

    public function register_settings() {
        register_setting(
            'rt_ai_search_group',
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array( $this, 'sanitize_options' ),
                'default' => array(
                    'api_key'              => '',
                    'model'                => 'gpt-4o-mini',
                    'max_posts'            => 10,
                    'enable'               => 0,
                    'max_calls_per_minute' => 30,
                    'cache_ttl'            => RT_AI_SEARCH_DEFAULT_CACHE_TTL,
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
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rt_ai_test_key' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        // Get API key from POST
        $api_key = isset( $_POST['api_key'] ) ? trim( $_POST['api_key'] ) : '';

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

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rt_ai_refresh_models' ) ) {
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

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rt_ai_clear_cache' ) ) {
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
        <div class="rt-ai-css-editor-wrapper">
            <textarea 
                name="<?php echo esc_attr( $this->option_name ); ?>[custom_css]"
                id="rt-ai-custom-css"
                class="rt-ai-css-editor"
                rows="15"
                placeholder="/* Add your custom CSS here */
    .rt-ai-search-summary {
        /* Your custom styles */
    }"><?php echo esc_textarea( $custom_css ); ?></textarea>
        </div>
        
        <p class="description">
            Add custom CSS to style the AI search summary. This will override the default styles.
            <br>
            <strong>Tip:</strong> Target classes like <code>.rt-ai-search-summary</code>, <code>.rt-ai-search-summary-inner</code>, <code>.rt-ai-openai-badge</code>, etc.
        </p>
        
        <div class="rt-ai-css-buttons">
            <button type="button" id="rt-ai-reset-css" class="rt-ai-button rt-ai-button-secondary">
                Reset to Empty
            </button>
            <button type="button" id="rt-ai-view-default-css" class="rt-ai-button rt-ai-button-secondary">
                View Default CSS
            </button>
        </div>
        
        <!-- Modal HTML -->
        <div id="rt-ai-default-css-modal" class="rt-ai-modal-overlay">
            <div class="rt-ai-modal-content">
                <button type="button" id="rt-ai-close-modal" class="rt-ai-modal-close" aria-label="Close">×</button>
                <h2 class="rt-ai-modal-header">Default CSS Reference</h2>
                <p class="rt-ai-modal-description">
                    Copy and modify these default styles to customize your AI search summary.
                </p>
                <pre class="rt-ai-modal-code"><code><?php echo esc_html( $this->get_default_css() ); ?></code></pre>
            </div>
        </div>
        
        <!-- JavaScript -->
        <script>
        (function($) {
            $(document).ready(function() {
                var modal = $('#rt-ai-default-css-modal');
                var textarea = $('#rt-ai-custom-css');
                
                // Reset CSS
                $('#rt-ai-reset-css').on('click', function() {
                    if (confirm('Reset custom CSS? This will clear all your custom styles.')) {
                        textarea.val('');
                    }
                });
                
                // View default CSS
                $('#rt-ai-view-default-css').on('click', function() {
                    modal.addClass('rt-ai-modal-open');
                });
                
                // Close modal - X button
                $('#rt-ai-close-modal').on('click', function() {
                    modal.removeClass('rt-ai-modal-open');
                });
                
                // Close on background click
                modal.on('click', function(e) {
                    if (e.target === this) {
                        modal.removeClass('rt-ai-modal-open');
                    }
                });
                
                // Close on ESC key
                $(document).on('keydown', function(e) {
                    if (e.key === 'Escape' && modal.hasClass('rt-ai-modal-open')) {
                        modal.removeClass('rt-ai-modal-open');
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    private function get_default_css() {
        return '@keyframes rt-ai-spin {
  to { transform: rotate(360deg); }
}

.rt-ai-search-summary-content {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.rt-ai-spinner {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid rgba(148,163,184,0.5);
  border-top-color: #22c55e;
  display: inline-block;
  animation: rt-ai-spin 0.7s linear infinite;
  flex-shrink: 0;
}

.rt-ai-loading-text {
  margin: 0;
  opacity: 0.8;
}

.rt-ai-search-summary-content.rt-ai-loaded {
  display: block;
}

.rt-ai-openai-badge {
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

.rt-ai-openai-mark {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  border: 1px solid rgba(148,163,184,0.8);
  position: relative;
  flex-shrink: 0;
}

.rt-ai-openai-mark::after {
  content: "";
  position: absolute;
  inset: 2px;
  border-radius: 999px;
  background: linear-gradient(135deg,#22c55e,#3b82f6);
}

.rt-ai-sources {
  margin-top: 1rem;
  font-size: 0.85rem;
}

.rt-ai-sources-toggle {
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

.rt-ai-sources-list {
  margin: 0;
  padding-left: 1.1rem;
  font-size: 0.85rem;
}

.rt-ai-sources-list li {
  margin-bottom: 0.4rem;
}

.rt-ai-sources-list li:last-child {
  margin-bottom: 0;
}

.rt-ai-sources-list a {
  color: #22c55e;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.rt-ai-sources-list a:hover {
  opacity: 0.9;
}

.rt-ai-sources-list span {
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
                error_log( '[RivianTrackr AI Search] Model list error: ' . $response->get_error_message() );
            }
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] Model list HTTP error ' . $code . ' body: ' . $body );
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
            if ( $age >= 0 && $age < RT_AI_SEARCH_MODELS_CACHE_TTL ) {
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
        
        <div class="rt-ai-settings-wrap">
            <!-- Header -->
            <div class="rt-ai-header">
                <h1>AI Search Settings</h1>
                <p>Configure OpenAI-powered search summaries for your site.</p>
            </div>

            <!-- Status Card -->
            <div class="rt-ai-status-card <?php echo $setup_complete ? 'active' : ''; ?>">
                <div class="rt-ai-status-icon">
                    <?php echo $setup_complete ? '✓' : '○'; ?>
                </div>
                <div class="rt-ai-status-content">
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
            settings_errors( 'rt_ai_search_group' );
            ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'rt_ai_search_group' ); ?>

                <!-- Section 1: Getting Started (Most Important) -->
                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>Getting Started</h2>
                        <p>Essential settings to enable AI search</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <!-- Enable Toggle -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>AI Search</label>
                            </div>
                            <div class="rt-ai-field-description">
                                Enable or disable AI-powered search summaries site-wide
                            </div>
                            <div class="rt-ai-toggle-wrapper">
                                <label class="rt-ai-toggle">
                                    <input type="checkbox" 
                                           name="<?php echo esc_attr( $this->option_name ); ?>[enable]"
                                           value="1" 
                                           <?php checked( $options['enable'], 1 ); ?> />
                                    <span class="rt-ai-toggle-slider"></span>
                                </label>
                                <span class="rt-ai-toggle-label">
                                    <?php echo $options['enable'] ? 'Enabled' : 'Disabled'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- API Key -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label for="rt-ai-api-key">OpenAI API Key</label>
                                <span class="rt-ai-field-required">Required</span>
                            </div>
                            <div class="rt-ai-field-description">
                                Get your API key from <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
                            </div>
                            <div class="rt-ai-field-input">
                                <input type="password" 
                                       id="rt-ai-api-key"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                                       value="<?php echo esc_attr( $options['api_key'] ); ?>"
                                       placeholder="sk-proj-..." 
                                       autocomplete="off" />
                            </div>
                            <div class="rt-ai-field-actions">
                                <button type="button" 
                                        id="rt-ai-test-key-btn" 
                                        class="rt-ai-button rt-ai-button-secondary">
                                    Test Connection
                                </button>
                            </div>
                            <div id="rt-ai-test-result" style="margin-top: 12px;"></div>
                        </div>
                    </div>
                </div>

                <!-- Section 2: AI Configuration -->
                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>AI Configuration</h2>
                        <p>Customize how AI generates search summaries</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <!-- Model Selection -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>AI Model</label>
                            </div>
                            <div class="rt-ai-field-description">
                                Recommended: <strong>gpt-4o-mini</strong> (fastest & most cost-effective)
                            </div>
                            <div class="rt-ai-field-input">
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
                            <div class="rt-ai-field-actions">
                                <button type="button" id="rt-ai-refresh-models-btn"
                                        class="rt-ai-button rt-ai-button-secondary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'rt_ai_refresh_models' ) ); ?>">
                                    Refresh Models
                                </button>
                                <span id="rt-ai-refresh-models-result" style="margin-left: 12px;"></span>
                            </div>
                            <?php if ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) : ?>
                                <div style="margin-top: 8px; font-size: 13px; color: #86868b;">
                                    Last updated: <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $cache['updated_at'] ) ) ); ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Max Posts -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Context Size</label>
                            </div>
                            <div class="rt-ai-field-description">
                                Number of posts to send as context (more posts = better answers, higher cost)
                            </div>
                            <div class="rt-ai-field-input">
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
                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>Performance</h2>
                        <p>Control rate limits and caching behavior</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <!-- Cache TTL -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Cache Duration</label>
                            </div>
                            <div class="rt-ai-field-description">
                                How long to cache AI summaries (60 seconds to 24 hours)
                            </div>
                            <div class="rt-ai-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[cache_ttl]"
                                       value="<?php echo esc_attr( isset( $options['cache_ttl'] ) ? $options['cache_ttl'] : 3600 ); ?>"
                                       min="60"
                                       max="86400"
                                       step="60" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">seconds</span>
                            </div>
                            <div class="rt-ai-field-actions">
                                <button type="button" id="rt-ai-clear-cache-btn"
                                        class="rt-ai-button rt-ai-button-secondary"
                                        data-nonce="<?php echo esc_attr( wp_create_nonce( 'rt_ai_clear_cache' ) ); ?>">
                                    Clear Cache Now
                                </button>
                                <span id="rt-ai-clear-cache-result" style="margin-left: 12px;"></span>
                            </div>
                        </div>

                        <!-- Rate Limit -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Rate Limit</label>
                            </div>
                            <div class="rt-ai-field-description">
                                Maximum AI calls per minute across the entire site (0 = unlimited)
                            </div>
                            <div class="rt-ai-field-input">
                                <input type="number"
                                       name="<?php echo esc_attr( $this->option_name ); ?>[max_calls_per_minute]"
                                       value="<?php echo esc_attr( isset( $options['max_calls_per_minute'] ) ? $options['max_calls_per_minute'] : 30 ); ?>"
                                       min="0"
                                       step="1" />
                                <span style="margin-left: 8px; color: #86868b; font-size: 14px;">calls/minute</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Section 4: Appearance -->
                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>Appearance</h2>
                        <p>Customize how the AI search summary looks on your site</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <!-- Custom CSS Only -->
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Custom CSS</label>
                            </div>
                            <div class="rt-ai-field-description">
                                Override default styles with your own CSS for complete control
                            </div>
                            <?php $this->field_custom_css(); ?>
                        </div>
                    </div>
                </div>

                <div class="rt-ai-footer-actions">
                    <?php submit_button( 'Save Settings', 'primary rt-ai-button rt-ai-button-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>

        <script>
        (function($) {
            $(document).ready(function() {
                $('#rt-ai-test-key-btn').on('click', function() {
                    var btn = $(this);
                    var apiKey = $('#rt-ai-api-key').val().trim();
                    var resultDiv = $('#rt-ai-test-result');
                    
                    if (!apiKey) {
                        resultDiv.html('<div class="rt-ai-test-result error"><p>Please enter an API key first.</p></div>');
                        return;
                    }
                    
                    btn.prop('disabled', true).text('Testing...');
                    resultDiv.html('<div class="rt-ai-test-result info"><p>Testing API key...</p></div>');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rt_ai_test_api_key',
                            api_key: apiKey,
                            nonce: '<?php echo wp_create_nonce( 'rt_ai_test_key' ); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Test Connection');
                            
                            if (response.success) {
                                var msg = '<strong>✓ ' + response.data.message + '</strong>';
                                if (response.data.model_count) {
                                    msg += '<br>Available models: ' + response.data.model_count + ' (Chat models: ' + response.data.chat_models + ')';
                                }
                                resultDiv.html('<div class="rt-ai-test-result success"><p>' + msg + '</p></div>');
                            } else {
                                resultDiv.html('<div class="rt-ai-test-result error"><p><strong>✗ Test failed:</strong> ' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Test Connection');
                            resultDiv.html('<div class="rt-ai-test-result error"><p>Request failed. Please try again.</p></div>');
                        }
                    });
                });

                // Refresh Models button
                $('#rt-ai-refresh-models-btn').on('click', function() {
                    var btn = $(this);
                    var resultSpan = $('#rt-ai-refresh-models-result');
                    var nonce = btn.data('nonce');

                    btn.prop('disabled', true).text('Refreshing...');
                    resultSpan.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rt_ai_refresh_models',
                            nonce: nonce
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Refresh Models');
                            if (response.success) {
                                resultSpan.html('<span style="color: #22c55e;">✓ ' + response.data.message + '</span>');
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
                $('#rt-ai-clear-cache-btn').on('click', function() {
                    var btn = $(this);
                    var resultSpan = $('#rt-ai-clear-cache-result');
                    var nonce = btn.data('nonce');

                    btn.prop('disabled', true).text('Clearing...');
                    resultSpan.html('');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rt_ai_clear_cache',
                            nonce: nonce
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Clear Cache Now');
                            if (response.success) {
                                resultSpan.html('<span style="color: #22c55e;">✓ ' + response.data.message + '</span>');
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
            isset( $_POST['rt_ai_build_logs'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( $_POST['_wpnonce'], 'rt_ai_build_logs' )
        ) {
            $logs_built = $this->ensure_logs_table();
            if ( ! $logs_built ) {
                $logs_error = 'Could not create or repair the analytics table. Check error logs for details.';
            }
        }

        // Handle the purge old logs action (POST for security)
        if (
            isset( $_POST['rt_ai_purge_logs'] ) &&
            isset( $_POST['rt_ai_purge_days'] ) &&
            isset( $_POST['_wpnonce'] ) &&
            wp_verify_nonce( $_POST['_wpnonce'], 'rt_ai_purge_logs' )
        ) {
            $days = absint( $_POST['rt_ai_purge_days'] );
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

        <div class="rt-ai-settings-wrap">
            <!-- Header -->
            <div class="rt-ai-header">
                <h1>Analytics</h1>
                <p>Track AI search usage, success rates, and identify trends.</p>
            </div>

            <!-- Notifications -->
            <?php if ( $logs_built && empty( $logs_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-success">
                    Analytics table has been created or repaired successfully.
                </div>
            <?php elseif ( ! empty( $logs_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-error">
                    <?php echo esc_html( $logs_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $logs_purged ) : ?>
                <div class="rt-ai-notice rt-ai-notice-success">
                    <?php echo esc_html( number_format( $purge_count ) ); ?> old log entries have been deleted.
                </div>
            <?php elseif ( ! empty( $purge_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-error">
                    <?php echo esc_html( $purge_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $this->logs_table_is_available() ) : ?>
                <!-- No Data State -->
                <div class="rt-ai-empty-state">
                    <div class="rt-ai-empty-icon">📊</div>
                    <h3>No Analytics Data Yet</h3>
                    <p>After visitors use search, analytics data will appear here.</p>
                    <form method="post" style="display: inline;">
                        <?php wp_nonce_field( 'rt_ai_build_logs' ); ?>
                        <button type="submit" name="rt_ai_build_logs" value="1"
                                class="rt-ai-button rt-ai-button-primary">
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
        $table_name = self::get_logs_table_name();

        // Get overview stats
        $totals = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(ai_success) AS success_count,
                SUM(CASE WHEN ai_success = 0 AND (ai_error IS NOT NULL AND ai_error <> '') THEN 1 ELSE 0 END) AS error_count,
                SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) AS cache_hits,
                SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses
             FROM $table_name"
        );

        $total_searches = $totals ? (int) $totals->total : 0;
        $success_count  = $totals ? (int) $totals->success_count : 0;
        $error_count    = $totals ? (int) $totals->error_count : 0;
        $cache_hits     = $totals ? (int) $totals->cache_hits : 0;
        $cache_misses   = $totals ? (int) $totals->cache_misses : 0;
        $cache_total    = $cache_hits + $cache_misses;
        $cache_hit_rate = $cache_total > 0 ? round( ( $cache_hits / $cache_total ) * 100, 1 ) : 0;
        $success_rate   = $this->calculate_success_rate( $success_count, $total_searches );

        $no_results_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE results_count = 0"
        );

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - 24 * 60 * 60 );
        $last_24   = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE created_at >= %s",
                $since_24h
            )
        );

        $daily_stats = $wpdb->get_results(
            "SELECT
                DATE(created_at) AS day,
                COUNT(*) AS total,
                SUM(ai_success) AS success_count,
                SUM(CASE WHEN cache_hit = 1 THEN 1 ELSE 0 END) AS cache_hits,
                SUM(CASE WHEN cache_hit = 0 THEN 1 ELSE 0 END) AS cache_misses
             FROM $table_name
             GROUP BY DATE(created_at)
             ORDER BY day DESC
             LIMIT 14"
        );

        $top_queries = $wpdb->get_results(
            "SELECT search_query, COUNT(*) AS total, SUM(ai_success) AS success_count
             FROM $table_name
             GROUP BY search_query
             ORDER BY total DESC
             LIMIT 20"
        );

        $top_errors = $wpdb->get_results(
            "SELECT ai_error, COUNT(*) AS total
             FROM $table_name
             WHERE ai_error IS NOT NULL AND ai_error <> ''
             GROUP BY ai_error
             ORDER BY total DESC
             LIMIT 10"
        );

        $recent_events = $wpdb->get_results(
            "SELECT *
             FROM $table_name
             ORDER BY created_at DESC
             LIMIT 50"
        );
        ?>

        <!-- Overview Stats Grid -->
        <div class="rt-ai-stats-grid">
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Total Searches</div>
                <div class="rt-ai-stat-value"><?php echo number_format( $total_searches ); ?></div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Success Rate</div>
                <div class="rt-ai-stat-value"><?php echo esc_html( $success_rate ); ?>%</div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Cache Hit Rate</div>
                <div class="rt-ai-stat-value"><?php echo esc_html( $cache_hit_rate ); ?>%</div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Cache Hits</div>
                <div class="rt-ai-stat-value"><?php echo number_format( $cache_hits ); ?></div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Cache Misses</div>
                <div class="rt-ai-stat-value"><?php echo number_format( $cache_misses ); ?></div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Last 24 Hours</div>
                <div class="rt-ai-stat-value"><?php echo number_format( $last_24 ); ?></div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">Total Errors</div>
                <div class="rt-ai-stat-value"><?php echo number_format( $error_count ); ?></div>
            </div>
            <div class="rt-ai-stat-card">
                <div class="rt-ai-stat-label">No Results</div>
                <div class="rt-ai-stat-value"><?php echo number_format( $no_results_count ); ?></div>
            </div>
        </div>

        <!-- Daily Stats Section -->
        <div class="rt-ai-section">
            <div class="rt-ai-section-header">
                <h2>Last 14 Days</h2>
                <p>Daily search volume and success rates</p>
            </div>
            <div class="rt-ai-section-content">
                <?php if ( ! empty( $daily_stats ) ) : ?>
                    <div class="rt-ai-table-wrapper">
                        <table class="rt-ai-table">
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
                                            <span class="rt-ai-badge rt-ai-badge-<?php echo $day_rate >= 90 ? 'success' : ( $day_rate >= 70 ? 'warning' : 'error' ); ?>">
                                                <?php echo esc_html( $day_rate ); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ( $day_cache_total > 0 ) : ?>
                                                <span class="rt-ai-badge rt-ai-badge-<?php echo $day_cache_rate >= 50 ? 'success' : ( $day_cache_rate >= 25 ? 'warning' : 'error' ); ?>">
                                                    <?php echo esc_html( $day_cache_rate ); ?>%
                                                </span>
                                            <?php else : ?>
                                                <span class="rt-ai-badge">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="rt-ai-empty-message">No recent activity yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Queries Section -->
        <div class="rt-ai-section">
            <div class="rt-ai-section-header">
                <h2>Top Search Queries</h2>
                <p>Most frequently searched terms</p>
            </div>
            <div class="rt-ai-section-content">
                <?php if ( ! empty( $top_queries ) ) : ?>
                    <div class="rt-ai-table-wrapper">
                        <table class="rt-ai-table">
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
                                        <td class="rt-ai-query-cell"><?php echo esc_html( $row->search_query ); ?></td>
                                        <td><?php echo number_format( $total_q ); ?></td>
                                        <td>
                                            <span class="rt-ai-badge rt-ai-badge-<?php echo $success_q_rate >= 90 ? 'success' : ( $success_q_rate >= 70 ? 'warning' : 'error' ); ?>">
                                                <?php echo esc_html( $success_q_rate ); ?>%
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="rt-ai-empty-message">No search data yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Errors Section -->
        <?php if ( ! empty( $top_errors ) ) : ?>
            <div class="rt-ai-section">
                <div class="rt-ai-section-header">
                    <h2>Top AI Errors</h2>
                    <p>Most common error messages</p>
                </div>
                <div class="rt-ai-section-content">
                    <div class="rt-ai-table-wrapper">
                        <table class="rt-ai-table">
                            <thead>
                                <tr>
                                    <th>Error Message</th>
                                    <th>Occurrences</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $top_errors as $err ) : ?>
                                    <tr>
                                        <td class="rt-ai-error-cell">
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
        <div class="rt-ai-section">
            <div class="rt-ai-section-header">
                <h2>Recent AI Search Events</h2>
                <p>Latest 50 search requests</p>
            </div>
            <div class="rt-ai-section-content">
                <?php if ( ! empty( $recent_events ) ) : ?>
                    <div class="rt-ai-table-wrapper">
                        <table class="rt-ai-table rt-ai-table-compact">
                            <thead>
                                <tr>
                                    <th>Query</th>
                                    <th>Results</th>
                                    <th>Status</th>
                                    <th>Cache</th>
                                    <th>Error</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $recent_events as $event ) : ?>
                                    <tr>
                                        <td class="rt-ai-query-cell"><?php echo esc_html( $event->search_query ); ?></td>
                                        <td><?php echo esc_html( (int) $event->results_count ); ?></td>
                                        <td>
                                            <?php if ( (int) $event->ai_success === 1 ) : ?>
                                                <span class="rt-ai-badge rt-ai-badge-success">Success</span>
                                            <?php else : ?>
                                                <span class="rt-ai-badge rt-ai-badge-error">Error</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( $event->cache_hit === '1' || $event->cache_hit === 1 ) : ?>
                                                <span class="rt-ai-badge rt-ai-badge-success">Hit</span>
                                            <?php elseif ( $event->cache_hit === '0' || $event->cache_hit === 0 ) : ?>
                                                <span class="rt-ai-badge rt-ai-badge-warning">Miss</span>
                                            <?php else : ?>
                                                <span class="rt-ai-badge rt-ai-badge-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="rt-ai-error-cell" <?php if ( ! empty( $event->ai_error ) ) : ?>title="<?php echo esc_attr( $event->ai_error ); ?>"<?php endif; ?>>
                                            <?php echo esc_html( $event->ai_error ); ?>
                                        </td>
                                        <td class="rt-ai-date-cell">
                                            <?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $event->created_at ) ) ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else : ?>
                    <div class="rt-ai-empty-message">No recent search events logged yet.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Data Management Section -->
        <div class="rt-ai-section">
            <div class="rt-ai-section-header">
                <h2>Data Management</h2>
                <p>Manage analytics log data</p>
            </div>
            <div class="rt-ai-section-content">
                <div class="rt-ai-field">
                    <div class="rt-ai-field-label">
                        <label>Purge Old Logs</label>
                    </div>
                    <div class="rt-ai-field-description">
                        Delete log entries older than the specified number of days to free up database space.
                    </div>
                    <form method="post" style="display: flex; align-items: center; gap: 12px; margin-top: 12px;">
                        <?php wp_nonce_field( 'rt_ai_purge_logs' ); ?>
                        <span>Delete logs older than</span>
                        <input type="number" name="rt_ai_purge_days" value="30" min="1" max="365"
                               style="width: 80px;" />
                        <span>days</span>
                        <button type="submit" name="rt_ai_purge_logs" value="1"
                                class="rt-ai-button rt-ai-button-secondary"
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
            'rt_ai_search_dashboard_widget',
            'RivianTrackr AI Search',
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
                <a href="<?php echo admin_url( 'admin.php?page=rt-ai-search-settings' ); ?>" 
                   style="display: inline-block; padding: 6px 14px; background: #0071e3; color: #fff; text-decoration: none; border-radius: 6px; font-size: 13px; font-weight: 500;">
                    Configure Plugin
                </a>
            </div>
            <?php
            return;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        $totals = $wpdb->get_row(
            "SELECT COUNT(*) AS total, SUM(ai_success) AS success_count
             FROM $table_name"
        );

        $total_searches = $totals ? (int) $totals->total : 0;
        $success_count  = $totals ? (int) $totals->success_count : 0;
        $success_rate   = $this->calculate_success_rate( $success_count, $total_searches );

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $last_24   = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE created_at >= %s",
                $since_24h
            )
        );

        $top_queries = $wpdb->get_results(
            "SELECT search_query, COUNT(*) AS total, SUM(ai_success) AS success_count
             FROM $table_name
             GROUP BY search_query
             ORDER BY total DESC
             LIMIT 5"
        );
        ?>
        
        <div class="rt-ai-widget-container">
            <!-- Stats Grid -->
            <div class="rt-ai-widget-stats-grid">
                <div class="rt-ai-widget-stat">
                    <span class="rt-ai-widget-stat-value"><?php echo number_format( $total_searches ); ?></span>
                    <span class="rt-ai-widget-stat-label">Total Searches</span>
                </div>
                <div class="rt-ai-widget-stat">
                    <span class="rt-ai-widget-stat-value"><?php echo esc_html( $success_rate ); ?>%</span>
                    <span class="rt-ai-widget-stat-label">Success Rate</span>
                </div>
                <div class="rt-ai-widget-stat">
                    <span class="rt-ai-widget-stat-value"><?php echo number_format( $last_24 ); ?></span>
                    <span class="rt-ai-widget-stat-label">Last 24 Hours</span>
                </div>
            </div>

            <div class="rt-ai-widget-section">
                <h4 class="rt-ai-widget-section-title">Top Search Queries</h4>
                
                <?php if ( ! empty( $top_queries ) ) : ?>
                    <table class="rt-ai-widget-table">
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
                                    $badge_class = 'rt-ai-widget-badge-success';
                                } elseif ( $success_q_rate >= 70 ) {
                                    $badge_class = 'rt-ai-widget-badge-warning';
                                } else {
                                    $badge_class = 'rt-ai-widget-badge-error';
                                }
                                ?>
                                <tr>
                                    <td class="rt-ai-widget-query">
                                        <?php 
                                        $query_display = esc_html( $row->search_query );
                                        if ( strlen( $query_display ) > 35 ) {
                                            $query_display = substr( $query_display, 0, 32 ) . '...';
                                        }
                                        echo $query_display;
                                        ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="rt-ai-widget-count"><?php echo number_format( $total_q ); ?></span>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="rt-ai-widget-badge <?php echo esc_attr( $badge_class ); ?>">
                                            <?php echo esc_html( $success_q_rate ); ?>%
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else : ?>
                    <div class="rt-ai-widget-empty">
                        No search data yet. Waiting for visitors to use AI search.
                    </div>
                <?php endif; ?>
            </div>

            <div class="rt-ai-widget-footer">
                <a href="<?php echo admin_url( 'admin.php?page=rt-ai-search-analytics' ); ?>" 
                   class="rt-ai-widget-link">
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

        $version = RT_AI_SEARCH_VERSION;

        wp_enqueue_style(
            'rt-ai-search',
            plugin_dir_url( __FILE__ ) . 'assets/rt-ai-search.css',
            array(),
            $version
        );

        if ( ! empty( $options['custom_css'] ) ) {
            // Defense in depth: sanitize again on output
            $custom_css = $this->sanitize_custom_css( $options['custom_css'] );
            wp_add_inline_style( 'rt-ai-search', $custom_css );
        }

        wp_enqueue_script(
            'rt-ai-search',
            plugin_dir_url( __FILE__ ) . 'assets/rt-ai-search.js',
            array(),
            $version,
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
        ?>
        <div class="rt-ai-search-summary" style="margin-bottom: 1.5rem;">
            <div class="rt-ai-search-summary-inner" style="padding: 1.25rem 1.25rem; border-radius: 10px; border: 1px solid rgba(148,163,184,0.4); display:flex; flex-direction:column; gap:0.6rem;">
                <div class="rt-ai-summary-header" style="display:flex; align-items:center; justify-content:space-between; gap:0.75rem;">
                    <h2 style="margin:0; font-size:1.1rem;">
                        AI summary for "<?php echo esc_html( $search_query ); ?>"
                    </h2>
                    <span class="rt-ai-openai-badge" aria-label="Powered by OpenAI">
                        <span class="rt-ai-openai-mark" aria-hidden="true"></span>
                        <span class="rt-ai-openai-text">Powered by OpenAI</span>
                    </span>
                </div>

                <div id="rt-ai-search-summary-content" class="rt-ai-search-summary-content" aria-live="polite">
                    <span class="rt-ai-spinner" role="status" aria-label="Loading AI summary"></span>
                    <p class="rt-ai-loading-text">Generating summary based on your search and RivianTrackr articles...</p>
                </div>

                <div class="rt-ai-search-disclaimer" style="margin-top:0.75rem; font-size:0.75rem; line-height:1.4; opacity:0.65;">
                    AI summaries are generated automatically based on RivianTrackr articles and may be inaccurate or incomplete. Always verify important details.
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_assets( $hook ) {
        $allowed_hooks = array(
            'toplevel_page_rt-ai-search-settings',
            'ai-search_page_rt-ai-search-analytics',
            'riviantrackr-ai-search_page_rt-ai-search-analytics',
        );
        
        $is_our_page = in_array( $hook, $allowed_hooks, true ) || 
                       strpos( $hook, 'rt-ai-search' ) !== false;
        
        if ( ! $is_our_page ) {
            return;
        }

        $version = RT_AI_SEARCH_VERSION;

        wp_enqueue_style(
            'rt-ai-search-admin',
            plugin_dir_url( __FILE__ ) . 'assets/rt-ai-search-admin.css',
            array(),
            $version
        );
    }

    private function is_likely_bot() {
        if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return true; // No user agent = suspicious
        }

        $user_agent = strtolower( $_SERVER['HTTP_USER_AGENT'] );
        
        // Common bot patterns
        $bot_patterns = array(
            'bot', 'crawl', 'spider', 'slurp', 'scanner',
            'scraper', 'curl', 'wget', 'python', 'java',
        );

        foreach ( $bot_patterns as $pattern ) {
            if ( strpos( $user_agent, $pattern ) !== false ) {
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
        $key = 'rt_ai_ip_rate_' . md5( $ip ) . '_' . gmdate( 'YmdHi' );
        set_transient( $key, $rate_info['used'] + 1, RT_AI_SEARCH_RATE_LIMIT_WINDOW );

        return false;
    }

    /**
     * Get rate limit information for an IP.
     *
     * @param string $ip Client IP address.
     * @return array Rate limit info with 'limit', 'remaining', 'used', and 'reset' keys.
     */
    private function get_rate_limit_info( $ip ) {
        $key   = 'rt_ai_ip_rate_' . md5( $ip ) . '_' . gmdate( 'YmdHi' );
        $limit = RT_AI_SEARCH_IP_RATE_LIMIT;
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
        // Sites behind trusted reverse proxies can define RT_AI_SEARCH_TRUSTED_PROXY_HEADER
        // to read from X-Forwarded-For or similar headers.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';

        // Allow sites behind trusted proxies to use forwarded headers
        if ( defined( 'RT_AI_SEARCH_TRUSTED_PROXY_HEADER' ) && RT_AI_SEARCH_TRUSTED_PROXY_HEADER ) {
            $header = 'HTTP_' . strtoupper( str_replace( '-', '_', RT_AI_SEARCH_TRUSTED_PROXY_HEADER ) );
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
            'rt-ai-search/v1',
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
        if ( strpos( $route, '/rt-ai-search/' ) === false ) {
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
                RT_AI_ERROR_BOT_DETECTED,
                'AI search is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

        // Per-IP rate limiting (more aggressive than global limit)
        $client_ip = $this->get_client_ip();
        if ( $this->is_ip_rate_limited( $client_ip ) ) {
            $rate_info = $this->get_rate_limit_info( $client_ip );
            return new WP_Error(
                RT_AI_ERROR_RATE_LIMITED,
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
                    'error_code'  => RT_AI_ERROR_NOT_CONFIGURED,
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
                    'error_code'  => RT_AI_ERROR_INVALID_QUERY,
                )
            );
        }

        $max_posts = (int) $options['max_posts'];
        if ( $max_posts < 1 ) {
            $max_posts = 10;
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
                $truncated_content = $this->smart_truncate( $content, RT_AI_SEARCH_CONTENT_LENGTH );
                $excerpt = $this->smart_truncate( $content, RT_AI_SEARCH_EXCERPT_LENGTH );

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
                    'error_code'  => RT_AI_ERROR_API_ERROR,
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

        if ( ! empty( $sources ) ) {
            $answer_html .= $this->render_sources_html( $sources );
        }

        return rest_ensure_response(
            array(
                'answer_html' => $answer_html,
                'error'       => '',
            )
        );
    }

    private function is_rate_limited_for_ai_calls() {
        $options = $this->get_options();
        $limit   = isset( $options['max_calls_per_minute'] ) ? (int) $options['max_calls_per_minute'] : 0;

        if ( $limit <= 0 ) {
            return false;
        }

        $key   = 'rt_ai_rate_' . gmdate( 'YmdHi' );
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return true;
        }

        $count++;
        set_transient( $key, $count, RT_AI_SEARCH_RATE_LIMIT_WINDOW );

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
            $ai_error = 'OpenAI API error: ' . $api_response['error'];
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
                error_log( '[RivianTrackr AI Search] Empty response. Full API response: ' . wp_json_encode( $api_response ) );
            }
            // Check if there's a finish_reason that explains the empty response
            $finish_reason = $api_response['choices'][0]['finish_reason'] ?? 'unknown';
            if ( $finish_reason === 'content_filter' ) {
                $ai_error = 'The response was filtered by content policy. Please try a different search.';
            } elseif ( $finish_reason === 'length' ) {
                $ai_error = 'The response was truncated. Please try a simpler search.';
            } else {
                $ai_error = 'OpenAI returned an empty response (reason: ' . $finish_reason . '). Please try again.';
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

        $system_message = "You are the AI search engine for RivianTrackr.com, a Rivian focused news and guide site.
    Use the provided posts as your entire knowledge base.
    Answer the user query based only on these posts.
    Prefer newer posts over older ones when there is conflicting or overlapping information, especially for news, software updates, or product changes.
    If something is not covered, say that the site does not have that information yet instead of making something up.

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
            $body['max_tokens'] = RT_AI_SEARCH_MAX_TOKENS;
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
            'timeout' => RT_AI_SEARCH_API_TIMEOUT,
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
                error_log( '[RivianTrackr AI Search] Retry attempt ' . ( $attempt + 1 ) . ' after ' . $delay . 's delay' );
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
                error_log( '[RivianTrackr AI Search] API request error: ' . $error_msg );
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

            return array(
                'success'   => false,
                'error'     => 'Connection error: ' . $error_msg,
                'retryable' => true, // Most connection errors are worth retrying
            );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        // HTTP errors
        if ( $code < 200 || $code >= 300 ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] API HTTP error ' . $code . ' body: ' . $body );
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
                    'error'     => $api_error ?? 'Bad request to AI service.',
                    'retryable' => false,
                );
            }

            // Other errors
            return array(
                'success'   => false,
                'error'     => $api_error ?? 'API error (HTTP ' . $code . '). Please try again later.',
                'retryable' => false,
            );
        }

        // Parse JSON response
        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[RivianTrackr AI Search] Failed to decode OpenAI response: ' . json_last_error_msg() );
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

        $sources = array_slice( $sources, 0, RT_AI_SEARCH_MAX_SOURCES_DISPLAY );
        $count   = count( $sources );

        $show_label = 'Show sources (' . intval( $count ) . ')';
        $hide_label = 'Hide sources';

        $html  = '<div class="rt-ai-sources">';
        $html .= '<button type="button" class="rt-ai-sources-toggle" aria-expanded="false" aria-controls="rt-ai-sources-list" data-label-show="' . esc_attr( $show_label ) . '" data-label-hide="' . esc_attr( $hide_label ) . '">';
        $html .= esc_html( $show_label );
        $html .= '</button>';
        $html .= '<ul id="rt-ai-sources-list" class="rt-ai-sources-list" hidden>';

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

register_activation_hook( __FILE__, array( 'RivianTrackr_AI_Search', 'activate' ) );

new RivianTrackr_AI_Search();