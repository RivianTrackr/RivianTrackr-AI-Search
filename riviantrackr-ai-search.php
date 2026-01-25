<?php
declare(strict_types=1);
/**
 * Plugin Name: RivianTrackr AI Search
 * Plugin URI: https://github.com/RivianTrackr/RivianTrackr-AI-Search
 * Description: Add AI-powered search summaries using OpenAI, Gemini, or Claude. Fast, cached, and analytics-enabled for optimal performance.
 * Version: 4.0.0
 * Author URI: https://riviantrackr.com
 * Author: Jose Castillo
 * License: GPL v2 or later
 */

define( 'RT_AI_SEARCH_VERSION', '4.0.0' );
define( 'RT_AI_SEARCH_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );
define( 'RT_AI_SEARCH_MIN_CACHE_TTL', 60 );
define( 'RT_AI_SEARCH_MAX_CACHE_TTL', 86400 );
define( 'RT_AI_SEARCH_DEFAULT_CACHE_TTL', 3600 );
define( 'RT_AI_SEARCH_CONTENT_LENGTH', 400 );
define( 'RT_AI_SEARCH_EXCERPT_LENGTH', 200 );
define( 'RT_AI_SEARCH_MAX_SOURCES_DISPLAY', 5 );
define( 'RT_AI_SEARCH_API_TIMEOUT', 60 );
define( 'RT_AI_SEARCH_RATE_LIMIT_WINDOW', 70 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RivianTrackr_AI_Search {

    private $option_name = 'rt_ai_search_options';
    private $models_cache_option = 'rt_ai_search_models_cache';
    private $cache_keys_option = 'rt_ai_search_cache_keys';
    private $cache_namespace_option = 'rt_ai_search_cache_namespace';
    private $cache_prefix;
    private $cache_ttl = 3600;
    private $logs_table_checked = false;
    private $logs_table_exists = false;
    private $options_cache = null;

    public function __construct() {
        $this->load_provider_classes();
        $this->cache_prefix = 'rt_ai_search_v' . str_replace( '.', '_', RT_AI_SEARCH_VERSION ) . '_';
        
        add_action( 'plugins_loaded', array( $this, 'register_settings' ), 1 );
        add_action( 'init', array( $this, 'register_settings' ), 1 );
        add_action( 'admin_init', array( $this, 'register_settings' ), 1 );
        add_action( 'admin_head', array( $this, 'force_register_if_needed' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_rt_ai_test_api_key', array( $this, 'ajax_test_api_key' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_print_styles-index.php', array( $this, 'enqueue_dashboard_widget_css' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_settings_link' ) );
    }

    private function load_provider_classes() {
        require_once plugin_dir_path( __FILE__ ) . 'includes/class-rt-ai-provider-factory.php';
    }

    public function force_register_if_needed() {
        global $wp_registered_settings;
        
        if (!isset($wp_registered_settings[$this->option_name])) {
            error_log('[RivianTrackr AI Search] FORCING registration - option was not registered!');
            $this->register_settings();
        }
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
        $table_name = self::get_logs_table_name();
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

    private static function add_missing_indexes() {
        global $wpdb;
        $table_name = self::get_logs_table_name();

        $table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $table_exists !== $table_name ) {
            return false;
        }

        $indexes = $wpdb->get_results( "SHOW INDEX FROM $table_name" );
        $index_names = array();
        foreach ( $indexes as $index ) {
            $index_names[] = $index->Key_name;
        }

        if ( ! in_array( 'search_query_created', $index_names, true ) ) {
            $wpdb->query( 
                "ALTER TABLE $table_name 
                 ADD INDEX search_query_created (search_query(100), created_at)"
            );
            error_log( '[RivianTrackr AI Search] Added search_query_created index' );
        }

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
        self::add_missing_indexes();
    }

    private function ensure_logs_table() {
        self::create_logs_table();
        self::add_missing_indexes();
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

        $this->logs_table_checked = true;
        $this->logs_table_exists = ( $result === $table_name );

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
                'search_query' => $search_query,
                'results_count' => (int) $results_count,
                'ai_success' => $ai_success ? 1 : 0,
                'ai_error' => $ai_error,
                'created_at' => $now,
            ),
            array( '%s', '%d', '%d', '%s', '%s' )
        );

        if ( false === $result ) {
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
            'provider' => 'openai',
            'api_key' => '',
            'model' => 'gpt-4o-mini',
            'max_posts' => 10,
            'enable' => 0,
            'max_calls_per_minute' => 30,
            'cache_ttl' => RT_AI_SEARCH_DEFAULT_CACHE_TTL,
            'custom_css' => '',
        );

        $opts = get_option( $this->option_name, array() );
        $this->options_cache = wp_parse_args( is_array( $opts ) ? $opts : array(), $defaults );

        return $this->options_cache;
    }

    public function sanitize_options( $input ) {
        error_log('[RivianTrackr AI Search] sanitize_options() called');
        
        if (!is_array($input)) {
            error_log('[RivianTrackr AI Search] WARNING: Input is not an array!');
            $input = array();
        }
        
        $output = array();

        $output['provider'] = isset($input['provider']) ? sanitize_text_field($input['provider']) : 'openai';
        
        if ( ! RT_AI_Provider_Factory::is_valid_provider( $output['provider'] ) ) {
            $output['provider'] = 'openai';
        }

        $output['api_key'] = isset($input['api_key']) ? trim($input['api_key']) : '';
        $output['model'] = isset($input['model']) ? sanitize_text_field($input['model']) : 'gpt-4o-mini';
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
        
        $output['custom_css'] = isset($input['custom_css']) ? wp_strip_all_tags($input['custom_css']) : '';

        $this->options_cache = null;
        
        return $output;
    }

    public function add_settings_page() {
        $capability = 'manage_options';
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
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        
        error_log('[RivianTrackr AI Search] register_settings() executing at ' . current_time('mysql'));
        
        register_setting(
            'rt_ai_search_group',
            $this->option_name,
            array(
                'type' => 'array',
                'sanitize_callback' => array( $this, 'sanitize_options' ),
                'default' => array(
                    'api_key' => '',
                    'model' => 'gpt-4o-mini',
                    'max_posts' => 20,
                    'enable' => 0,
                    'max_calls_per_minute' => 30,
                    'cache_ttl' => RT_AI_SEARCH_DEFAULT_CACHE_TTL,
                    'custom_css' => '',
                )
            )
        );
        
        if (function_exists('add_settings_section')) {
            
            add_settings_section(
                'rt_ai_search_main',
                'AI Search Settings',
                '__return_false',
                'rt-ai-search'
            );

            add_settings_field(
                'provider',
                'AI Provider',
                array( $this, 'field_provider' ),
                'rt-ai-search',
                'rt_ai_search_main'
            );

            add_settings_field(
                'api_key',
                'API Key',
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

            add_settings_field(
                'custom_css',
                'Custom CSS',
                array( $this, 'field_custom_css' ),
                'rt-ai-search',
                'rt_ai_search_main'
            );
        }
        
        error_log('[RivianTrackr AI Search] register_settings() completed');
    }

    public function field_api_key() {
        $options = $this->get_options();
        ?>
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
                    class="button">
                Test Connection
            </button>
        </div>
        
        <div id="rt-ai-test-result"></div>
        
        <p class="description">
            Create an API key in the OpenAI dashboard and paste it here. 
            Use the "Test Connection" button to verify it works.
        </p>
        
        <script>
        (function($) {
            $(document).ready(function() {
                var btn = $('#rt-ai-test-key-btn');
                var apiKeyInput = $('#rt-ai-api-key');
                var providerSelect = $('#rt-ai-provider-select');
                var resultDiv = $('#rt-ai-test-result');
                
                btn.on('click', function() {
                    var apiKey = apiKeyInput.val().trim();
                    var provider = providerSelect.val();
                    
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
                            provider: provider,
                            nonce: '<?php echo wp_create_nonce( 'rt_ai_test_key' ); ?>'
                        },
                        success: function(response) {
                            btn.prop('disabled', false).text('Test Connection');
                            
                            if (response.success) {
                                var msg = '<strong>✓ ' + response.data.message + '</strong>';
                                if (response.data.model_count) {
                                    msg += '<br>Available models: ' + response.data.model_count;
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
            });
        })(jQuery);
        </script>
        <?php
    }

    public function field_provider() {
        $options = $this->get_options();
        $providers = RT_AI_Provider_Factory::get_available_providers();
        ?>
        <div class="rt-ai-field-input">
            <select name="<?php echo esc_attr( $this->option_name ); ?>[provider]" 
                    id="rt-ai-provider-select"
                    style="min-width: 260px;">
                <?php foreach ( $providers as $id => $name ) : ?>
                    <option value="<?php echo esc_attr( $id ); ?>" 
                            <?php selected( $options['provider'], $id ); ?>>
                        <?php echo esc_html( $name ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#rt-ai-provider-select').on('change', function() {
                var providerName = $(this).find('option:selected').text();
                alert('Provider changed to ' + providerName + '. Please save your settings to see available models for this provider.');
            });
        });
        </script>
        <?php
    }

    public function ajax_test_api_key() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ) );
        }

        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'rt_ai_test_key' ) ) {
            wp_send_json_error( array( 'message' => 'Invalid nonce.' ) );
        }

        $api_key = isset( $_POST['api_key'] ) ? trim( $_POST['api_key'] ) : '';
        $provider_id = isset( $_POST['provider'] ) ? sanitize_text_field( $_POST['provider'] ) : 'openai';

        $provider = RT_AI_Provider_Factory::create( $provider_id, $api_key );
        
        if ( ! $provider ) {
            wp_send_json_error( array( 'message' => 'Invalid provider selected.' ) );
        }

        $result = $provider->test_api_key();

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    public function field_model() {
        $options = $this->get_options();
        $provider_id = isset( $options['provider'] ) ? $options['provider'] : 'openai';
        $models = $this->get_available_models_for_dropdown( $provider_id, $options['api_key'] );

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
            <?php
            $provider_name = RT_AI_Provider_Factory::get_provider_name( $provider_id );
            echo 'Select a model from ' . esc_html( $provider_name ) . '. ';
            
            switch ( $provider_id ) {
                case 'openai':
                    echo '<strong>Recommended: gpt-4o-mini</strong> (fastest & cheapest)';
                    break;
                case 'gemini':
                    echo '<strong>Recommended: gemini-1.5-flash</strong> (fast & efficient)';
                    break;
                case 'claude':
                    echo '<strong>Recommended: claude-3-5-haiku-20241022</strong> (fast & cost-effective)';
                    break;
            }
            ?>
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

    public function field_max_calls_per_minute() {
        $options = $this->get_options();
        $value = isset( $options['max_calls_per_minute'] ) ? (int) $options['max_calls_per_minute'] : 30;
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

    public function field_cache_ttl() {
        $options = $this->get_options();
        $value = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : RT_AI_SEARCH_DEFAULT_CACHE_TTL;
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
        
        <div id="rt-ai-default-css-modal" class="rt-ai-modal-overlay">
            <div class="rt-ai-modal-content">
                <button type="button" id="rt-ai-close-modal" class="rt-ai-modal-close" aria-label="Close">×</button>
                <h2 class="rt-ai-modal-header">Default CSS Reference</h2>
                <p class="rt-ai-modal-description">
                    Copy and modify these default styles to customize your AI search summary.
                </p>
                <pre class="rt-ai-modal-code"><code><?php echo esc_html( file_get_contents( plugin_dir_path( __FILE__ ) . 'assets/rt-ai-search.css' ) ); ?></code></pre>
            </div>
        </div>
        
        <script>
        (function($) {
            $(document).ready(function() {
                var modal = $('#rt-ai-default-css-modal');
                var textarea = $('#rt-ai-custom-css');
                
                $('#rt-ai-reset-css').on('click', function() {
                    if (confirm('Reset custom CSS? This will clear all your custom styles.')) {
                        textarea.val('');
                    }
                });
                
                $('#rt-ai-view-default-css').on('click', function() {
                    modal.addClass('rt-ai-modal-open');
                });
                
                $('#rt-ai-close-modal').on('click', function() {
                    modal.removeClass('rt-ai-modal-open');
                });
                
                modal.on('click', function(e) {
                    if (e.target === this) {
                        modal.removeClass('rt-ai-modal-open');
                    }
                });
                
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

    private function get_available_models_for_dropdown( $provider_id, $api_key ) {
        $provider = RT_AI_Provider_Factory::create( $provider_id, $api_key );
        
        if ( ! $provider ) {
            return array( 'gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo' );
        }

        $cache_key = 'rt_ai_models_cache_' . $provider_id;
        $cache = get_option( $cache_key );
        $cached_models = ( is_array( $cache ) && ! empty( $cache['models'] ) ) ? $cache['models'] : array();
        $updated_at = ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) ? absint( $cache['updated_at'] ) : 0;

        if ( ! empty( $cached_models ) && $updated_at > 0 ) {
            $age = time() - $updated_at;
            if ( $age >= 0 && $age < RT_AI_SEARCH_MODELS_CACHE_TTL ) {
                return $cached_models;
            }
        }

        if ( ! empty( $api_key ) ) {
            $models = $provider->fetch_models_from_api();
            
            if ( ! empty( $models ) ) {
                update_option(
                    $cache_key,
                    array(
                        'models' => $models,
                        'updated_at' => time(),
                    )
                );
                return $models;
            }
        }

        return ! empty( $cached_models ) ? $cached_models : $provider->get_available_models();
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
                'models' => $models,
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
        $this->bump_cache_namespace();

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
        $cache = get_option( $this->models_cache_option );
        $refreshed = false;
        $error = '';
        $cache_cleared = false;
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

        $has_api_key = ! empty( $options['api_key'] );
        $is_enabled = ! empty( $options['enable'] );
        $setup_complete = $has_api_key && $is_enabled;
        ?>
        
        <div class="rt-ai-settings-wrap">
            <div class="rt-ai-header">
                <h1>AI Search Settings</h1>
                <p>Configure AI-powered search summaries for your site using OpenAI, Google Gemini, or Anthropic Claude.</p>
            </div>

            <div class="rt-ai-status-card <?php echo $setup_complete ? 'active' : ''; ?>">
                <div class="rt-ai-status-icon">
                    <?php echo $setup_complete ? '✓' : '○'; ?>
                </div>
                <div class="rt-ai-status-content">
                    <h3><?php echo $setup_complete ? 'AI Search Active' : 'Setup Required'; ?></h3>
                    <p>
                        <?php 
                        if ( $setup_complete ) {
                            $provider_name = isset( $options['provider'] ) ? $options['provider'] : 'openai';
                            $provider_names = array(
                                'openai' => 'OpenAI',
                                'gemini' => 'Google Gemini',
                                'claude' => 'Anthropic Claude'
                            );
                            $display_name = isset( $provider_names[$provider_name] ) ? $provider_names[$provider_name] : 'AI provider';
                            echo 'Your AI search is configured and running with ' . esc_html( $display_name ) . '.';
                        } elseif ( ! $has_api_key ) {
                            echo 'Add your API key to get started.';
                        } else {
                            echo 'Enable AI search to start generating summaries.';
                        }
                        ?>
                    </p>
                </div>
            </div>

            <?php if ( $refreshed ) : ?>
                <?php
                $provider_name = isset( $options['provider'] ) ? $options['provider'] : 'openai';
                $provider_names = array(
                    'openai' => 'OpenAI',
                    'gemini' => 'Google Gemini',
                    'claude' => 'Anthropic Claude'
                );
                $display_name = isset( $provider_names[$provider_name] ) ? $provider_names[$provider_name] : 'the provider';
                ?>
                <div class="rt-ai-notice rt-ai-notice-success">
                    Model list refreshed from <?php echo esc_html( $display_name ); ?>.
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

                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>Getting Started</h2>
                        <p>Essential settings to enable AI search</p>
                    </div>
                    <div class="rt-ai-section-content">
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

                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>AI Provider</label>
                            </div>
                            <div class="rt-ai-field-description">
                                Choose your AI provider. Each has different models, pricing, and strengths.
                            </div>
                            <?php $this->field_provider(); ?>
                        </div>

                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label for="rt-ai-api-key">API Key</label>
                                <span class="rt-ai-field-required">Required</span>
                            </div>
                            <?php $this->field_api_key(); ?>
                        </div>
                    </div>
                </div>

                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>AI Configuration</h2>
                        <p>Customize how AI generates search summaries</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>AI Model</label>
                            </div>
                            <?php $this->field_model(); ?>
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

                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Context Size</label>
                            </div>
                            <?php $this->field_max_posts(); ?>
                        </div>
                    </div>
                </div>

                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>Performance</h2>
                        <p>Control rate limits and caching behavior</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Cache Duration</label>
                            </div>
                            <?php $this->field_cache_ttl(); ?>
                            <div class="rt-ai-field-actions">
                                <a href="<?php echo esc_url( $clear_cache_url ); ?>" 
                                   class="rt-ai-button rt-ai-button-secondary">
                                    Clear Cache Now
                                </a>
                            </div>
                        </div>

                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Rate Limit</label>
                            </div>
                            <?php $this->field_max_calls_per_minute(); ?>
                        </div>
                    </div>
                </div>

                <div class="rt-ai-section">
                    <div class="rt-ai-section-header">
                        <h2>Appearance</h2>
                        <p>Customize how the AI search summary looks on your site</p>
                    </div>
                    <div class="rt-ai-section-content">
                        <div class="rt-ai-field">
                            <div class="rt-ai-field-label">
                                <label>Custom CSS</label>
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
                $('#rt-ai-provider-select').on('change', function() {
                    var providerName = $(this).find('option:selected').text();
                    var message = 'Provider changed to ' + providerName + '.\n\n';
                    message += 'Please save your settings to:\n';
                    message += '• Update the API key link\n';
                    message += '• Load models for this provider\n';
                    message += '• Update recommendations';
                    
                    alert(message);
                });
                
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
                            provider: $('#rt-ai-provider-select').val(),
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
            });
        })(jQuery);
        </script>

        <?php
    }

    public function render_analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $logs_built = false;
        $logs_error = '';

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

        $logs_url = wp_nonce_url(
            admin_url( 'admin.php?page=rt-ai-search-analytics&rt_ai_build_logs=1' ),
            'rt_ai_build_logs'
        );
        ?>
        
        <div class="rt-ai-settings-wrap">
            <div class="rt-ai-header">
                <h1>Analytics</h1>
                <p>Track AI search usage, success rates, and identify trends.</p>
            </div>

            <?php if ( $logs_built && empty( $logs_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-success">
                    Analytics table has been created or repaired successfully.
                </div>
            <?php elseif ( ! empty( $logs_error ) ) : ?>
                <div class="rt-ai-notice rt-ai-notice-error">
                    <?php echo esc_html( $logs_error ); ?>
                </div>
            <?php endif; ?>

            <?php if ( ! $this->logs_table_is_available() ) : ?>
                <div class="rt-ai-empty-state">
                    <div class="rt-ai-empty-icon">📊</div>
                    <h3>No Analytics Data Yet</h3>
                    <p>After visitors use search, analytics data will appear here.</p>
                    <a href="<?php echo esc_url( $logs_url ); ?>" class="rt-ai-button rt-ai-button-primary">
                        Create Analytics Table
                    </a>
                </div>
            <?php else : 
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
                $success_count = $totals ? (int) $totals->success_count : 0;
                $error_count = $totals ? (int) $totals->error_count : 0;
                $success_rate = $this->calculate_success_rate( $success_count, $total_searches );

                $no_results_count = (int) $wpdb->get_var(
                    "SELECT COUNT(*) FROM $table_name WHERE results_count = 0"
                );

                $since_24h = gmdate( 'Y-m-d H:i:s', time() - 24 * 60 * 60 );
                $last_24 = (int) $wpdb->get_var(
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
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $daily_stats as $row ) : 
                                            $day_total = (int) $row->total;
                                            $day_success = (int) $row->success_count;
                                            $day_rate = $this->calculate_success_rate( $day_success, $day_total );
                                        ?>
                                            <tr>
                                                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->day ) ) ); ?></td>
                                                <td><?php echo number_format( $day_total ); ?></td>
                                                <td>
                                                    <span class="rt-ai-badge rt-ai-badge-<?php echo $day_rate >= 90 ? 'success' : ( $day_rate >= 70 ? 'warning' : 'error' ); ?>">
                                                        <?php echo esc_html( $day_rate ); ?>%
                                                    </span>
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
                                        <?php foreach ( $top_queries as $row ) :
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
                                        <?php foreach ( $top_errors as $err ) : 
                                            $msg = (string) $err->ai_error;
                                            if ( strlen( $msg ) > 80 ) {
                                                $msg = substr( $msg, 0, 77 ) . '...';
                                            }
                                        ?>
                                            <tr>
                                                <td class="rt-ai-error-cell"><?php echo esc_html( $msg ); ?></td>
                                                <td><?php echo number_format( (int) $err->total ); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

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
                                            <th>Error</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $recent_events as $event ) : 
                                            $err = (string) $event->ai_error;
                                            if ( strlen( $err ) > 50 ) {
                                                $err = substr( $err, 0, 47 ) . '...';
                                            }
                                        ?>
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
                                                <td class="rt-ai-error-cell"><?php echo esc_html( $err ); ?></td>
                                                <td class="rt-ai-date-cell"><?php echo esc_html( date_i18n( 'M j, g:i a', strtotime( $event->created_at ) ) ); ?></td>
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
            <?php endif; ?>
        </div>
        <?php
    }

    private function calculate_success_rate( $success_count, $total ) {
        if ( $total <= 0 ) {
            return 0;
        }
        
        return (int) round( ( $success_count / $total ) * 100 );
    }

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
        $success_count = $totals ? (int) $totals->success_count : 0;
        $success_rate = $this->calculate_success_rate( $success_count, $total_searches );

        $since_24h = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
        $last_24 = (int) $wpdb->get_var(
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
                            <?php foreach ( $top_queries as $row ) : 
                                $total_q = (int) $row->total;
                                $success_q = (int) $row->success_count;
                                $success_q_rate = $this->calculate_success_rate( $success_q, $total_q );
                                
                                if ( $success_q_rate >= 90 ) {
                                    $badge_class = 'rt-ai-widget-badge-success';
                                } elseif ( $success_q_rate >= 70 ) {
                                    $badge_class = 'rt-ai-widget-badge-warning';
                                } else {
                                    $badge_class = 'rt-ai-widget-badge-error';
                                }
                                
                                $query_display = esc_html( $row->search_query );
                                if ( strlen( $query_display ) > 35 ) {
                                    $query_display = substr( $query_display, 0, 32 ) . '...';
                                }
                            ?>
                                <tr>
                                    <td class="rt-ai-widget-query"><?php echo $query_display; ?></td>
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
            $custom_css = wp_strip_all_tags( $options['custom_css'] );
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
                'query' => get_search_query(),
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
            return true;
        }

        $user_agent = strtolower( $_SERVER['HTTP_USER_AGENT'] );
        
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

    private function is_ip_rate_limited( $ip ) {
        $key = 'rt_ai_ip_rate_' . md5( $ip ) . '_' . gmdate( 'YmdHi' );
        $limit = 10;
        $count = (int) get_transient( $key );

        if ( $count >= $limit ) {
            return true;
        }

        $count++;
        set_transient( $key, $count, 70 );

        return false;
    }

    private function get_client_ip() {
        $ip = 'unknown';

        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $ips = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip = trim( $ips[0] );
        } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }

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
                'methods' => 'GET',
                'callback' => array( $this, 'rest_get_summary' ),
                'permission_callback' => array( $this, 'rest_permission_check' ),
                'args' => array(
                    'q' => array(
                        'required' => true,
                        'sanitize_callback' => 'sanitize_text_field',
                        'validate_callback' => array( $this, 'validate_search_query' ),
                    ),
                ),
            )
        );
    }

    public function rest_permission_check( WP_REST_Request $request ) {
        if ( $this->is_likely_bot() ) {
            return new WP_Error(
                'rest_forbidden',
                'AI search is not available for automated requests.',
                array( 'status' => 403 )
            );
        }

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

    public function validate_search_query( $value, $request, $param ) {
        if ( ! is_string( $value ) ) {
            return false;
        }

        if ( empty( trim( $value ) ) ) {
            return false;
        }

        $length = strlen( $value );
        if ( $length < 2 || $length > 200 ) {
            return false;
        }

        return true;
    }

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

        if ( $this->safe_substr( $text, 0, $limit ) === $text ) {
            return $text;
        }

        $truncated = $this->safe_substr( $text, 0, $limit );

        $sentence_endings = array( '. ', '! ', '? ', '."', '!"', '?"', ".'", "!'", "?'" );
        $last_sentence_pos = 0;

        foreach ( $sentence_endings as $ending ) {
            $pos = strrpos( $truncated, $ending );
            if ( $pos !== false && $pos > $last_sentence_pos ) {
                $last_sentence_pos = $pos + strlen( $ending );
            }
        }

        if ( $last_sentence_pos > 0 && $last_sentence_pos >= ( $limit * 0.5 ) ) {
            return trim( $this->safe_substr( $truncated, 0, $last_sentence_pos ) );
        }

        $last_space = strrpos( $truncated, ' ' );
        if ( $last_space !== false && $last_space >= ( $limit * 0.7 ) ) {
            return trim( $this->safe_substr( $truncated, 0, $last_space ) ) . '...';
        }

        return $truncated . '...';
    }

    public function rest_get_summary( WP_REST_Request $request ) {
        $options = $this->get_options();

        if ( empty( $options['enable'] ) || empty( $options['api_key'] ) ) {
            $this->log_search_event( $request->get_param( 'q' ), 0, 0, 'AI search not enabled or API key missing' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error' => 'AI search is not enabled.',
                )
            );
        }

        $search_query = $request->get_param( 'q' );
        if ( ! $search_query ) {
            $this->log_search_event( '', 0, 0, 'Missing search query' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error' => 'Missing search query.',
                )
            );
        }

        $max_posts = (int) $options['max_posts'];
        if ( $max_posts < 1 ) {
            $max_posts = 10;
        }

        $post_type = 'any';

        $search_args = array(
            's' => $search_query,
            'post_type' => $post_type,
            'posts_per_page' => $max_posts,
            'post_status' => 'publish',
            'orderby' => 'date',
            'order' => 'DESC',
        );

        $search_results = new WP_Query( $search_args );
        
        $posts_for_ai = array();

        if ( $search_results->have_posts() ) {
            foreach ( $search_results->posts as $post ) {
                $content = wp_strip_all_tags( $post->post_content );
                
                $truncated_content = $this->smart_truncate( $content, RT_AI_SEARCH_CONTENT_LENGTH );
                $excerpt = $this->smart_truncate( $content, RT_AI_SEARCH_EXCERPT_LENGTH );

                $posts_for_ai[] = array(
                    'id' => $post->ID,
                    'title' => get_the_title( $post ),
                    'url' => get_permalink( $post ),
                    'excerpt' => $excerpt,
                    'content' => $truncated_content,
                    'type' => $post->post_type,
                    'date' => get_the_date( 'Y-m-d', $post ),
                );
            }
        }

        $results_count = count( $posts_for_ai );
        $ai_error = '';
        $ai_data = $this->get_ai_data_for_search( $search_query, $posts_for_ai, $ai_error );

        if ( ! $ai_data ) {
            $this->log_search_event( $search_query, $results_count, 0, $ai_error ? $ai_error : 'AI summary not available' );

            return rest_ensure_response(
                array(
                    'answer_html' => '',
                    'error' => $ai_error ? $ai_error : 'AI summary is not available right now.',
                )
            );
        }

        $this->log_search_event( $search_query, $results_count, 1, '' );

        $answer_html = isset( $ai_data['answer_html'] ) ? (string) $ai_data['answer_html'] : '';
        $sources = isset( $ai_data['results'] ) && is_array( $ai_data['results'] ) ? $ai_data['results'] : array();

        $allowed_tags = array(
            'p' => array(),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'h3' => array(),
            'h4' => array(),
            'a' => array(
                'href' => array(),
                'title' => array(),
                'target' => array(),
                'rel' => array(),
            ),
        );

        $answer_html = wp_kses( $answer_html, $allowed_tags );

        if ( ! empty( $sources ) ) {
            $answer_html .= $this->render_sources_html( $sources );
        }

        return rest_ensure_response(
            array(
                'answer_html' => $answer_html,
                'error' => '',
            )
        );
    }

    private function is_rate_limited_for_ai_calls() {
        $options = $this->get_options();
        $limit = isset( $options['max_calls_per_minute'] ) ? (int) $options['max_calls_per_minute'] : 0;

        if ( $limit <= 0 ) {
            return false;
        }

        $key = 'rt_ai_rate_' . gmdate( 'YmdHi' );
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

        $provider_id = isset( $options['provider'] ) ? $options['provider'] : 'openai';
        
        $normalized_query = strtolower( trim( $search_query ) );
        $namespace = $this->get_cache_namespace();
        
        $cache_key_data = implode( '|', array(
            $provider_id,
            $options['model'],
            $options['max_posts'],
            $normalized_query
        ) );
        
        $cache_key = $this->cache_prefix . 'ns' . $namespace . '_' . md5( $cache_key_data );
        $cached_raw = get_transient( $cache_key );

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

        $provider = RT_AI_Provider_Factory::create(
            $provider_id,
            $options['api_key'],
            $options['model']
        );

        if ( ! $provider ) {
            $ai_error = 'Invalid AI provider configuration.';
            return null;
        }

        $result = $provider->generate_summary( $search_query, $posts_for_ai );

        if ( isset( $result['error'] ) ) {
            $ai_error = $result['error'];
            return null;
        }

        if ( empty( $result['answer_html'] ) ) {
            $result['answer_html'] = '<p>AI summary did not return a valid answer.</p>';
        }

        if ( empty( $result['results'] ) || ! is_array( $result['results'] ) ) {
            $result['results'] = array();
        }

        $ttl_option = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 0;
        $ttl = $ttl_option > 0 ? $ttl_option : $this->cache_ttl;
        set_transient( $cache_key, wp_json_encode( $result ), $ttl );

        return $result;
    }

    private function render_sources_html( $sources ) {
        if ( empty( $sources ) || ! is_array( $sources ) ) {
            return '';
        }

        $sources = array_slice( $sources, 0, RT_AI_SEARCH_MAX_SOURCES_DISPLAY );
        $count = count( $sources );

        $show_label = 'Show sources (' . intval( $count ) . ')';
        $hide_label = 'Hide sources';

        $html = '<div class="rt-ai-sources">';
        $html .= '<button type="button" class="rt-ai-sources-toggle" data-label-show="' . esc_attr( $show_label ) . '" data-label-hide="' . esc_attr( $hide_label ) . '">';
        $html .= esc_html( $show_label );
        $html .= '</button>';
        $html .= '<ul class="rt-ai-sources-list" hidden>';

        foreach ( $sources as $src ) {
            $title = isset( $src['title'] ) ? $src['title'] : '';
            $url = isset( $src['url'] ) ? $src['url'] : '';
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