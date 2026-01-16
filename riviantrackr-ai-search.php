<?php
declare(strict_types=1);
/**
 * Plugin Name: RivianTrackr AI Search
 * Plugin URI: https://github.com/RivianTrackr/RivianTrackr-AI-Search
 * Description: Add an OpenAI powered AI summary to WordPress search on RivianTrackr.com without delaying normal results, with analytics, cache control, and collapsible sources.
 * Version: 3.3.0
 * Author URI: https://riviantrackr.com
 * Author: RivianTrackr
 * License: GPL v2 or later
 */

define( 'RT_AI_SEARCH_VERSION', '3.3.0' );
define( 'RT_AI_SEARCH_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );

// Cache settings
define( 'RT_AI_SEARCH_MIN_CACHE_TTL', 60 );
define( 'RT_AI_SEARCH_MAX_CACHE_TTL', 86400 );
define( 'RT_AI_SEARCH_DEFAULT_CACHE_TTL', 3600 );

// Content length limits
define( 'RT_AI_SEARCH_CONTENT_LENGTH', 400 );
define( 'RT_AI_SEARCH_EXCERPT_LENGTH', 200 );

// Display limits
define( 'RT_AI_SEARCH_MAX_SOURCES_DISPLAY', 5 );

// API settings
define( 'RT_AI_SEARCH_API_TIMEOUT', 60 );
define( 'RT_AI_SEARCH_RATE_LIMIT_WINDOW', 70 );


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
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_rt_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Adds "Settings" link on Plugins page
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_settings_link' ) );
    }

    public function add_plugin_settings_link( $links ) {
        $url = admin_url( 'admin.php?page=rt-ai-search-settings' );
        $settings_link = '<a href="' . esc_url( $url ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /* ---------------------------------------------------------
     *  Logs table helpers
     * --------------------------------------------------------- */

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
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY search_query_created (search_query(100), created_at),
            KEY ai_success_created (ai_success, created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Add missing indexes to existing table (for upgrades).
     * Called during plugin activation or via admin action.
     */
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
            error_log( '[RivianTrackr AI Search] Added search_query_created index' );
        }

        // Add ai_success_created index if missing
        if ( ! in_array( 'ai_success_created', $index_names, true ) ) {
            $wpdb->query(
                "ALTER TABLE $table_name 
                 ADD INDEX ai_success_created (ai_success, created_at)"
            );
            error_log( '[RivianTrackr AI Search] Added ai_success_created index' );
        }

        return true;
    }

    public static function activate() {
        self::create_logs_table();
        self::add_missing_indexes(); // Add indexes to existing tables
    }

    private function ensure_logs_table() {
        self::create_logs_table();
        self::add_missing_indexes(); // Ensure indexes exist
        $this->logs_table_checked = false;
        return $this->logs_table_is_available();
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

    private function log_search_event( $search_query, $results_count, $ai_success, $ai_error = '' ) {
        if ( empty( $search_query ) ) {
            return;
        }

        if ( ! $this->logs_table_is_available() ) {
            return;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        $now = current_time( 'mysql' );

        $result = $wpdb->insert(
            $table_name,
            array(
                'search_query'  => $search_query,
                'results_count' => (int) $results_count,
                'ai_success'    => $ai_success ? 1 : 0,
                'ai_error'      => $ai_error,
                'created_at'    => $now,
            ),
            array(
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
            )
        );

        // Log database errors for debugging
        if ( false === $result ) {
            error_log( 
                '[RivianTrackr AI Search] Failed to log search event: ' . 
                $wpdb->last_error . 
                ' | Query: ' . substr( $search_query, 0, 50 )
            );
        }
    }

    /* ---------------------------------------------------------
     *  Options and settings
     * --------------------------------------------------------- */

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
        );

        $opts = get_option( $this->option_name, array() );
        $this->options_cache = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

        return $this->options_cache;
    }

    public function sanitize_options( $input ) {
        $output = array();

        $output['api_key']   = isset( $input['api_key'] ) ? trim( $input['api_key'] ) : '';
        $output['model']     = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'gpt-4o-mini';  // Updated default
        $output['max_posts'] = isset( $input['max_posts'] ) ? max( 1, intval( $input['max_posts'] ) ) : 10;
        $output['enable']    = ! empty( $input['enable'] ) ? 1 : 0;

        $output['max_calls_per_minute'] = isset( $input['max_calls_per_minute'] )
            ? max( 0, intval( $input['max_calls_per_minute'] ) )
            : 30;

        if ( isset( $input['cache_ttl'] ) ) {
            $ttl = intval( $input['cache_ttl'] );
            if ( $ttl < RT_AI_SEARCH_MIN_CACHE_TTL ) {
                $ttl = RT_AI_SEARCH_MIN_CACHE_TTL;
            } elseif ( $ttl > RT_AI_SEARCH_MAX_CACHE_TTL ) {
                $ttl = RT_AI_SEARCH_MAX_CACHE_TTL;
            }
            $output['cache_ttl'] = $ttl;
        } else {
            $output['cache_ttl'] = RT_AI_SEARCH_DEFAULT_CACHE_TTL;
        }

        $this->options_cache = null;

        return $output;
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
            array( $this, 'sanitize_options' )
        );

        add_settings_section(
            'rt_ai_search_main',
            'AI Search Settings',
            '__return_false',
            'rt-ai-search'
        );

        add_settings_field(
            'api_key',
            'OpenAI API Key',
            array( $this, 'field_api_key' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );

        add_settings_field(
            'model',
            'Model',
            array( $this, 'field_model' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );

        add_settings_field(
            'max_posts',
            'Maximum posts to send to OpenAI',
            array( $this, 'field_max_posts' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );

        add_settings_field(
            'enable',
            'Enable AI search summary',
            array( $this, 'field_enable' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );

        add_settings_field(
            'max_calls_per_minute',
            'Max AI calls per minute',
            array( $this, 'field_max_calls_per_minute' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );

        add_settings_field(
            'cache_ttl',
            'AI cache lifetime (seconds)',
            array( $this, 'field_cache_ttl' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );
    }

    public function field_api_key() {
        $options = $this->get_options();
        ?>
        <div style="display: flex; align-items: flex-start; gap: 0.5rem;">
            <input type="password" 
                   id="rt-ai-api-key"
                   name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
                   value="<?php echo esc_attr( $options['api_key'] ); ?>"
                   style="width: 400px;" 
                   autocomplete="off" />
            
            <button type="button" 
                    id="rt-ai-test-key-btn" 
                    class="button"
                    style="white-space: nowrap;">
                Test Connection
            </button>
        </div>
        
        <div id="rt-ai-test-result" style="margin-top: 0.5rem;"></div>
        
        <p class="description">
            Create an API key in the OpenAI dashboard and paste it here. 
            Use the "Test Connection" button to verify it works.
        </p>
        
        <script>
        (function($) {
            $(document).ready(function() {
                $('#rt-ai-test-key-btn').on('click', function() {
                    var btn = $(this);
                    var apiKey = $('#rt-ai-api-key').val().trim();
                    var resultDiv = $('#rt-ai-test-result');
                    
                    if (!apiKey) {
                        resultDiv.html('<div class="notice notice-error inline"><p>Please enter an API key first.</p></div>');
                        return;
                    }
                    
                    // Disable button and show loading
                    btn.prop('disabled', true).text('Testing...');
                    resultDiv.html('<div class="notice notice-info inline"><p>Testing API key...</p></div>');
                    
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
                                var msg = '<strong>' + response.data.message + '</strong>';
                                if (response.data.model_count) {
                                    msg += '<br>Available models: ' + response.data.model_count;
                                    msg += ' (Chat models: ' + response.data.chat_models + ')';
                                }
                                resultDiv.html('<div class="notice notice-success inline"><p>' + msg + '</p></div>');
                            } else {
                                resultDiv.html('<div class="notice notice-error inline"><p><strong>Test failed:</strong> ' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Test Connection');
                            resultDiv.html('<div class="notice notice-error inline"><p>Request failed. Please try again.</p></div>');
                        }
                    });
                });
            });
        })(jQuery);
        </script>
        
        <style>
        .notice.inline {
            display: inline-block;
            margin: 0;
            padding: 0.5rem 0.75rem;
        }
        .notice.inline p {
            margin: 0;
        }
        </style>
        <?php
    }

    private function test_api_key( $api_key ) {
        if ( empty( $api_key ) ) {
            return array(
                'success' => false,
                'message' => 'API key is empty.',
            );
        }

        // Make a simple API call to verify the key works
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

    public function field_model() {
        $options = $this->get_options();
        $models  = $this->get_available_models_for_dropdown( $options['api_key'] );

        if ( ! empty( $options['model'] ) && ! in_array( $options['model'], $models, true ) ) {
            $models[] = $options['model'];
        }

        $models = array_unique( $models );
        sort( $models );
        ?>
        <select name="<?php echo esc_attr( $this->option_name ); ?>[model]" style="min-width: 260px;">
            <?php foreach ( $models as $model_id ) : ?>
                <option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $options['model'], $model_id ); ?>>
                    <?php echo esc_html( $model_id ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            Pick the OpenAI model to use for AI search. 
            <strong>Recommended: gpt-4o-mini</strong> (fastest & cheapest, ~2-3 seconds per summary).
            Use the button below to refresh the list from OpenAI.
        </p>
        <?php
    }

    public function field_max_posts() {
        $options = $this->get_options();
        ?>
        <input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_posts]"
               value="<?php echo esc_attr( $options['max_posts'] ); ?>"
               min="1" max="20" />
        <p class="description">
            How many posts or pages to pass as context for each search. Default: 10. 
            More posts provide better context but increase API costs slightly.
        </p>
        <?php
    }

    public function field_enable() {
        $options = $this->get_options();
        ?>
        <label>
            <input type="checkbox" name="<?php echo esc_attr( $this->option_name ); ?>[enable]"
                   value="1" <?php checked( $options['enable'], 1 ); ?> />
            Enable AI search summary
        </label>
        <?php
    }

    public function field_max_calls_per_minute() {
        $options = $this->get_options();
        $value   = isset( $options['max_calls_per_minute'] ) ? (int) $options['max_calls_per_minute'] : 30;
        ?>
        <input type="number"
               name="<?php echo esc_attr( $this->option_name ); ?>[max_calls_per_minute]"
               value="<?php echo esc_attr( $value ); ?>"
               min="0"
               step="1"
               style="width: 90px;" />
        <p class="description">
            Maximum number of OpenAI calls allowed per minute across the whole site.
            Set to 0 for no limit. Cache hits do not count against this.
        </p>
        <?php
    }

    // Update field_cache_ttl() to use constants
    public function field_cache_ttl() {
        $options = $this->get_options();
        $value   = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : RT_AI_SEARCH_DEFAULT_CACHE_TTL;
        ?>
        <input type="number"
               name="<?php echo esc_attr( $this->option_name ); ?>[cache_ttl]"
               value="<?php echo esc_attr( $value ); ?>"
               min="<?php echo RT_AI_SEARCH_MIN_CACHE_TTL; ?>"
               max="<?php echo RT_AI_SEARCH_MAX_CACHE_TTL; ?>"
               step="60"
               style="width: 100px;" />
        <p class="description">
            How long to cache each AI summary in seconds. 
            Minimum <?php echo RT_AI_SEARCH_MIN_CACHE_TTL; ?> seconds, 
            maximum <?php echo number_format( RT_AI_SEARCH_MAX_CACHE_TTL ); ?> seconds (24 hours).
        </p>
        <?php
    }

    /* ---------------------------------------------------------
     *  Model list helpers - Updated with better filtering
     * --------------------------------------------------------- */

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
            error_log( '[RivianTrackr AI Search] Model list error: ' . $response->get_error_message() );
            return array();
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[RivianTrackr AI Search] Model list HTTP error ' . $code . ' body: ' . $body );
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

    /* ---------------------------------------------------------
     *  Cache helpers
     * --------------------------------------------------------- */

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

        $options           = $this->get_options();
        $cache             = get_option( $this->models_cache_option );
        $refreshed         = false;
        $error             = '';
        $cache_cleared     = false;
        $cache_clear_error = '';

        if (
            isset( $_GET['rt_ai_refresh_models'] ) &&
            $_GET['rt_ai_refresh_models'] === '1' &&
            isset( $_GET['_wpnonce'] ) &&
            wp_verify_nonce( $_GET['_wpnonce'], 'rt_ai_refresh_models' )
        ) {
            if ( empty( $options['api_key'] ) ) {
                $error = 'Cannot refresh models because no API key is set yet.';
            } else {
                $refreshed = $this->refresh_model_cache( $options['api_key'] );
                if ( ! $refreshed ) {
                    $error = 'Could not refresh models from OpenAI. Check your API key or try again later.';
                }
            }

            $cache = get_option( $this->models_cache_option );
        }

        if (
            isset( $_GET['rt_ai_clear_cache'] ) &&
            $_GET['rt_ai_clear_cache'] === '1' &&
            isset( $_GET['_wpnonce'] ) &&
            wp_verify_nonce( $_GET['_wpnonce'], 'rt_ai_clear_cache' )
        ) {
            $cache_cleared = $this->clear_ai_cache();
            if ( ! $cache_cleared ) {
                $cache_clear_error = 'Could not clear AI cache.';
            }
        }

        $refresh_url = wp_nonce_url(
            admin_url( 'admin.php?page=rt-ai-search-settings&rt_ai_refresh_models=1' ),
            'rt_ai_refresh_models'
        );

        $clear_cache_url = wp_nonce_url(
            admin_url( 'admin.php?page=rt-ai-search-settings&rt_ai_clear_cache=1' ),
            'rt_ai_clear_cache'
        );

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

            <!-- Notifications -->
            <?php if ( $refreshed ) : ?>
                <div class="rt-ai-notice rt-ai-notice-success">
                    Model list refreshed from OpenAI.
                </div>
            <?php elseif ( ! empty( $error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-error">
                    <?php echo esc_html( $error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( $cache_cleared && empty( $cache_clear_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-success">
                    AI summary cache cleared. New searches will fetch fresh answers.
                </div>
            <?php elseif ( ! empty( $cache_clear_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-error">
                    <?php echo esc_html( $cache_clear_error ); ?>
                </div>
            <?php endif; ?>

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
                                <a href="<?php echo esc_url( $refresh_url ); ?>" 
                                   class="rt-ai-button rt-ai-button-secondary">
                                    Refresh Models
                                </a>
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
                                       max="20" />
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
                                <a href="<?php echo esc_url( $clear_cache_url ); ?>" 
                                   class="rt-ai-button rt-ai-button-secondary">
                                    Clear Cache Now
                                </a>
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

                <!-- Save Button -->
                <div class="rt-ai-footer-actions">
                    <?php submit_button( 'Save Settings', 'primary rt-ai-button rt-ai-button-primary', 'submit', false ); ?>
                </div>
            </form>
        </div>

        <!-- Test API Key JavaScript (keep existing) -->
        <script>
        (function($) {
            $(document).ready(function() {
                $('#rt-ai-test-key-btn').on('click', function() {
                    var btn = $(this);
                    var apiKey = $('#rt-ai-api-key').val().trim();
                    var resultDiv = $('#rt-ai-test-result');
                    
                    if (!apiKey) {
                        resultDiv.html('<div style="color: #c41e3a; font-size: 14px;">Please enter an API key first.</div>');
                        return;
                    }
                    
                    btn.prop('disabled', true).text('Testing...');
                    resultDiv.html('<div style="color: #86868b; font-size: 14px;">Testing API key...</div>');
                    
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
                                var msg = '<strong style="color: #0a5e2a;">✓ ' + response.data.message + '</strong>';
                                if (response.data.model_count) {
                                    msg += '<br><span style="color: #86868b; font-size: 13px;">Available models: ' + response.data.model_count + ' (Chat models: ' + response.data.chat_models + ')</span>';
                                }
                                resultDiv.html('<div style="color: #0a5e2a; font-size: 14px;">' + msg + '</div>');
                            } else {
                                resultDiv.html('<div style="color: #c41e3a; font-size: 14px;"><strong>✗ Test failed:</strong> ' + response.data.message + '</div>');
                            }
                        },
                        error: function() {
                            btn.prop('disabled', false).text('Test Connection');
                            resultDiv.html('<div style="color: #c41e3a; font-size: 14px;">Request failed. Please try again.</div>');
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

        // Handle the create/repair action
        if (
            isset( $_GET['rt_ai_build_logs'] ) &&
            $_GET['rt_ai_build_logs'] === '1' &&
            isset( $_GET['_wpnonce'] ) &&
            wp_verify_nonce( $_GET['_wpnonce'], 'rt_ai_build_logs' )
        ) {
            $logs_built = $this->ensure_logs_table();
            if ( ! $logs_built ) {
                $logs_error = 'Could not create or repair the analytics table. Check error logs for details.';
            }
        }

        // Create the URL for the create/repair button
        $logs_url = wp_nonce_url(
            admin_url( 'admin.php?page=rt-ai-search-analytics&rt_ai_build_logs=1' ),
            'rt_ai_build_logs'
        );

        ?>
        <div class="wrap">
            <h1>AI Search Analytics</h1>

            <?php if ( $logs_built && empty( $logs_error ) ) : ?>
                <div class="updated notice">
                    <p>Analytics table has been created or repaired successfully.</p>
                </div>
            <?php elseif ( ! empty( $logs_error ) ) : ?>
                <div class="error notice">
                    <p><?php echo esc_html( $logs_error ); ?></p>
                </div>
            <?php endif; ?>

            <p style="margin-bottom:1rem;">
                <a href="<?php echo esc_url( $logs_url ); ?>" class="button">
                    Create or repair analytics table
                </a>
            </p>

            <?php $this->render_analytics_section(); ?>
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
     *  Analytics page (updated to use helper)
     * --------------------------------------------------------- */

    private function render_analytics_section() {
        if ( ! $this->logs_table_is_available() ) {
            ?>
            <p>No analytics data yet. After visitors use search, you will see top queries and recent events here.</p>
            <?php
            return;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        $totals = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(ai_success) AS success_count,
                SUM(CASE WHEN ai_success = 0 AND (ai_error IS NOT NULL AND ai_error <> '') THEN 1 ELSE 0 END) AS error_count
             FROM $table_name"
        );

        $total_searches = $totals ? (int) $totals->total : 0;
        $success_count  = $totals ? (int) $totals->success_count : 0;
        $error_count    = $totals ? (int) $totals->error_count : 0;
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
                SUM(ai_success) AS success_count
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

        <h2>Overview</h2>
        <div style="display:flex; flex-wrap:wrap; gap:1rem; margin-bottom:1.5rem;">
            <div style="flex:1 1 180px; min-width:180px; padding:0.75rem 1rem; border:1px solid #ccd0d4; border-radius:6px; background:#fff;">
                <h3 style="margin:0 0 0.25rem 0; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; opacity:0.7;">Total AI searches</h3>
                <p style="margin:0; font-size:20px; font-weight:600;"><?php echo esc_html( $total_searches ); ?></p>
            </div>
            <div style="flex:1 1 180px; min-width:180px; padding:0.75rem 1rem; border:1px solid #ccd0d4; border-radius:6px; background:#fff;">
                <h3 style="margin:0 0 0.25rem 0; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; opacity:0.7;">Overall success rate</h3>
                <p style="margin:0; font-size:20px; font-weight:600;"><?php echo esc_html( $success_rate ); ?>%</p>
            </div>
            <div style="flex:1 1 180px; min-width:180px; padding:0.75rem 1rem; border:1px solid #ccd0d4; border-radius:6px; background:#fff;">
                <h3 style="margin:0 0 0.25rem 0; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; opacity:0.7;">Searches last 24 hours</h3>
                <p style="margin:0; font-size:20px; font-weight:600;"><?php echo esc_html( $last_24 ); ?></p>
            </div>
            <div style="flex:1 1 180px; min-width:180px; padding:0.75rem 1rem; border:1px solid #ccd0d4; border-radius:6px; background:#fff;">
                <h3 style="margin:0 0 0.25rem 0; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; opacity:0.7;">Total AI errors</h3>
                <p style="margin:0; font-size:20px; font-weight:600;"><?php echo esc_html( $error_count ); ?></p>
            </div>
            <div style="flex:1 1 180px; min-width:180px; padding:0.75rem 1rem; border:1px solid #ccd0d4; border-radius:6px; background:#fff;">
                <h3 style="margin:0 0 0.25rem 0; font-size:13px; text-transform:uppercase; letter-spacing:0.04em; opacity:0.7;">Searches with no results</h3>
                <p style="margin:0; font-size:20px; font-weight:600;"><?php echo esc_html( $no_results_count ); ?></p>
            </div>
        </div>

        <h2>Last 14 days</h2>
        <?php if ( ! empty( $daily_stats ) ) : ?>
            <table class="widefat striped" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Total searches</th>
                        <th>Success rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $daily_stats as $row ) : ?>
                        <?php
                        $day_total = (int) $row->total;
                        $day_success = (int) $row->success_count;
                        $day_rate = $this->calculate_success_rate( $day_success, $day_total );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $row->day ); ?></td>
                            <td><?php echo esc_html( $day_total ); ?></td>
                            <td><?php echo esc_html( $day_rate ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No recent activity yet.</p>
        <?php endif; ?>

        <h2 style="margin-top: 2rem;">Top search queries</h2>
        <?php if ( ! empty( $top_queries ) ) : ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead>
                    <tr>
                        <th style="width: 50%;">Query</th>
                        <th>Total searches</th>
                        <th>AI success rate</th>
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
                            <td><?php echo esc_html( $row->search_query ); ?></td>
                            <td><?php echo esc_html( $total_q ); ?></td>
                            <td><?php echo esc_html( $success_q_rate ); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No search data yet.</p>
        <?php endif; ?>

        <h2 style="margin-top: 2rem;">Top AI errors</h2>
        <?php if ( ! empty( $top_errors ) ) : ?>
            <table class="widefat striped" style="max-width: 900px;">
                <thead>
                    <tr>
                        <th style="width: 65%;">Error message</th>
                        <th>Occurrences</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_errors as $err ) : ?>
                        <tr>
                            <td>
                                <?php
                                $msg = (string) $err->ai_error;
                                if ( strlen( $msg ) > 120 ) {
                                    $msg = substr( $msg, 0, 117 ) . '...';
                                }
                                echo esc_html( $msg );
                                ?>
                            </td>
                            <td><?php echo esc_html( (int) $err->total ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No AI errors logged yet.</p>
        <?php endif; ?>

        <h2 style="margin-top: 2rem;">Recent AI search events</h2>
        <?php if ( ! empty( $recent_events ) ) : ?>
            <table class="widefat striped" style="max-width: 1000px;">
                <thead>
                    <tr>
                        <th style="width: 35%;">Query</th>
                        <th>Results</th>
                        <th>AI status</th>
                        <th>Error (short)</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $recent_events as $event ) : ?>
                        <tr>
                            <td><?php echo esc_html( $event->search_query ); ?></td>
                            <td><?php echo esc_html( (int) $event->results_count ); ?></td>
                            <td><?php echo (int) $event->ai_success === 1 ? 'Success' : 'Error'; ?></td>
                            <td>
                                <?php
                                $err = (string) $event->ai_error;
                                if ( strlen( $err ) > 80 ) {
                                    $err = substr( $err, 0, 77 ) . '...';
                                }
                                echo esc_html( $err );
                                ?>
                            </td>
                            <td><?php echo esc_html( $event->created_at ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p>No recent search events logged yet.</p>
        <?php endif; ?>
        <?php
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
            echo '<p>No AI search analytics data yet. Once visitors use search, stats will appear here.</p>';
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

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - 24 * 60 * 60 );
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
        <div style="font-size:13px; line-height:1.5;">
            <p style="margin-top:0;">Quick snapshot of how often visitors are using AI search on RivianTrackr.</p>

            <ul style="margin:0 0 1rem 1.2rem; padding:0;">
                <li>Total AI searches: <?php echo esc_html( $total_searches ); ?></li>
                <li>Overall AI success rate: <?php echo esc_html( $success_rate ); ?>%</li>
                <li>Searches in the last 24 hours: <?php echo esc_html( $last_24 ); ?></li>
            </ul>

            <h4 style="margin:0 0 0.4rem 0; font-size:12px; text-transform:uppercase; letter-spacing:0.05em; opacity:0.7;">Top queries</h4>

            <?php if ( ! empty( $top_queries ) ) : ?>
                <table class="widefat striped" style="margin-top:0; font-size:12px;">
                    <thead>
                        <tr>
                            <th>Query</th>
                            <th>Total</th>
                            <th>Success</th>
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
                                <td><?php echo esc_html( $row->search_query ); ?></td>
                                <td><?php echo esc_html( $total_q ); ?></td>
                                <td><?php echo esc_html( $success_q_rate ); ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="margin-top:0.3rem;">No searches logged yet.</p>
            <?php endif; ?>

            <p style="margin-top:0.8rem; font-size:11px; opacity:0.7;">For more detail, go to AI Search in the sidebar and click Analytics.</p>
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

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'rt-ai-search' ) === false ) {
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

                <div id="rt-ai-search-summary-content" class="rt-ai-search-summary-content">
                    <span class="rt-ai-spinner"></span>
                    <p class="rt-ai-loading-text">Generating summary based on your search and RivianTrackr articles...</p>
                </div>

                <div class="rt-ai-search-disclaimer" style="margin-top:0.75rem; font-size:0.75rem; line-height:1.4; opacity:0.65;">
                    AI summaries are generated automatically based on RivianTrackr articles and may be inaccurate or incomplete. Always verify important details.
                </div>
            </div>
        </div>
        <?php
    }


    /* ---------------------------------------------------------
     *  REST API security helpers
     * --------------------------------------------------------- */

    /**
     * Check if request is from a likely bot/crawler based on user agent.
     *
     * @return bool True if likely a bot, false otherwise.
     */
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
     * Rate limit check per IP address to prevent individual abuse.
     *
     * @param string $ip IP address to check.
     * @return bool True if rate limited, false otherwise.
     */
    private function is_ip_rate_limited( $ip ) {
        $key   = 'rt_ai_ip_rate_' . md5( $ip ) . '_' . gmdate( 'YmdHi' );
        $limit = 10; // 10 requests per minute per IP
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return true;
        }

        $count++;
        set_transient( $key, $count, 70 );

        return false;
    }

    /**
     * Get client IP address, accounting for proxies.
     *
     * @return string IP address or 'unknown'.
     */
    private function get_client_ip() {
        $ip = 'unknown';

        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            // Take first IP in list (original client)
            $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip  = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

        // Validate IP
        if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return $ip;
        }

        return 'unknown';
    }

    /* ---------------------------------------------------------
     *  Updated REST route with security
     * --------------------------------------------------------- */

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
     * Permission callback for REST API endpoint.
     *
     * @param WP_REST_Request $request Request object.
     * @return bool|WP_Error True if allowed, WP_Error if blocked.
     */
    public function rest_permission_check( WP_REST_Request $request ) {
        // Block obvious bots to save API costs
        if ( $this->is_likely_bot() ) {
            return new WP_Error(
                'rest_forbidden',
                'AI search is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

        // Per-IP rate limiting (more aggressive than global limit)
        $client_ip = $this->get_client_ip();
        if ( $this->is_ip_rate_limited( $client_ip ) ) {
            return new WP_Error(
                'rest_too_many_requests',
                'Too many requests from your IP address. Please try again in a minute.',
                array( 'status' => 429 )
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
        $ai_data       = $this->get_ai_data_for_search( $search_query, $posts_for_ai, $ai_error );

        if ( ! $ai_data ) {
            $this->log_search_event( $search_query, $results_count, 0, $ai_error ? $ai_error : 'AI summary not available' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error'       => $ai_error ? $ai_error : 'AI summary is not available right now.',
                )
            );
        }

        $this->log_search_event( $search_query, $results_count, 1, '' );

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

    private function get_ai_data_for_search( $search_query, $posts_for_ai, &$ai_error = '' ) {
        $options = $this->get_options();
        if ( empty( $options['api_key'] ) || empty( $options['enable'] ) ) {
            $ai_error = 'AI search is not configured. Please contact the site administrator.';
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
                return $ai_data;
            }
        }

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

        if ( empty( $api_response['choices'][0]['message']['content'] ) ) {
            $ai_error = 'OpenAI returned an empty response. Please try again.';
            return null;
        }

        $raw_content = $api_response['choices'][0]['message']['content'];

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

    // Updated call_openai_for_search() with better error messages
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

        $supports_response_format = (
            strpos( $model, 'gpt-4o' ) === 0 ||
            strpos( $model, 'gpt-4.1' ) === 0 ||
            strpos( $model, 'gpt-5' ) === 0
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

        if ( strpos( $model, 'gpt-5' ) !== 0 ) {
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

        $response = wp_safe_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $error_msg = $response->get_error_message();
            error_log( '[RivianTrackr AI Search] API request error: ' . $error_msg );
            
            // Provide user-friendly error messages based on common errors
            if ( strpos( $error_msg, 'cURL error 28' ) !== false || strpos( $error_msg, 'timed out' ) !== false ) {
                return array( 'error' => 'Request timed out. The AI service may be slow right now. Please try again.' );
            }
            if ( strpos( $error_msg, 'cURL error 6' ) !== false || strpos( $error_msg, 'resolve host' ) !== false ) {
                return array( 'error' => 'Could not connect to AI service. Please check your internet connection.' );
            }
            
            return array( 'error' => 'Connection error: ' . $error_msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[RivianTrackr AI Search] API HTTP error ' . $code . ' body: ' . $body );
            $decoded_error = json_decode( $body, true );
            
            if ( isset( $decoded_error['error']['message'] ) ) {
                $api_error = $decoded_error['error']['message'];
                
                // Provide context for common API errors
                if ( $code === 401 ) {
                    return array( 'error' => 'Invalid API key. Please check your plugin settings.' );
                }
                if ( $code === 429 ) {
                    return array( 'error' => 'OpenAI rate limit exceeded. Please try again in a few moments.' );
                }
                if ( $code === 500 || $code === 503 ) {
                    return array( 'error' => 'OpenAI service temporarily unavailable. Please try again later.' );
                }
                
                return array( 'error' => $api_error );
            }
            
            return array( 'error' => 'API error (HTTP ' . $code . '). Please try again later.' );
        }

        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( '[RivianTrackr AI Search] Failed to decode OpenAI response: ' . json_last_error_msg() );
            return array( 'error' => 'Could not understand AI response. Please try again.' );
        }

        return $decoded;
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
        $html .= '<button type="button" class="rt-ai-sources-toggle" data-label-show="' . esc_attr( $show_label ) . '" data-label-hide="' . esc_attr( $hide_label ) . '">';
        $html .= esc_html( $show_label );
        $html .= '</button>';
        $html .= '<ul class="rt-ai-sources-list" hidden>';

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