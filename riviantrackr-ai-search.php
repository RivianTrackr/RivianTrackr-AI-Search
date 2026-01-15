<?php
/**
 * Plugin Name: RivianTrackr AI Search
 * Plugin URI: https://github.com/RivianTrackr/RivianTrackr-AI-Search
 * Description: Add an OpenAI powered AI summary to WordPress search on RivianTrackr.com without delaying normal results, with analytics, cache control, and collapsible sources.
 * Version: 3.2.2
 * Author URI: https://riviantrackr.com
 * Author: RivianTrackr
 * License: GPL v2 or later
 */

define( 'RT_AI_SEARCH_VERSION', '3.2.2' );
define( 'RT_AI_SEARCH_MODELS_CACHE_TTL', 7 * DAY_IN_SECONDS );


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

    public function __construct() {
        
        $this->cache_prefix = 'rt_ai_search_v' . str_replace( '.', '_', RT_AI_SEARCH_VERSION ) . '_';
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_dashboard_setup', array( $this, 'register_dashboard_widget' ) );
        add_action( 'loop_start', array( $this, 'inject_ai_summary_placeholder' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

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
            ip varchar(45) NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function activate() {
        self::create_logs_table();
    }

    private function ensure_logs_table() {
        self::create_logs_table();
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

    private function anonymize_existing_ips() {
        if ( ! $this->logs_table_is_available() ) {
            return false;
        }

        global $wpdb;
        $table_name = self::get_logs_table_name();

        // Process in batches to avoid timeouts on large tables.
        $batch_size = 1000;
        $last_id    = 0;
        $did_work   = false;

        while ( true ) {
            // Only select IPs that look like IPv4 or IPv6 addresses to avoid re-hashing already anonymized values.
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, ip
                     FROM {$table_name}
                     WHERE id > %d
                       AND ip IS NOT NULL
                       AND ip <> ''
                     ORDER BY id ASC
                     LIMIT %d",
                    $last_id,
                    $batch_size
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $last_id = (int) $row->id;

                $ip = trim( (string) $row->ip );
                if ( $ip === '' ) {
                    continue;
                }

                // Skip values that do not look like IP addresses (likely already hashed).
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) === false ) {
                    continue;
                }

                $hashed = $this->hash_ip_for_analytics( $ip );

                $updated = $wpdb->update(
                    $table_name,
                    array( 'ip' => $hashed ),
                    array( 'id' => $last_id ),
                    array( '%s' ),
                    array( '%d' )
                );

                if ( $updated !== false ) {
                    $did_work = true;
                }
            }

            if ( count( $rows ) < $batch_size ) {
                break;
            }
        }

        // Return true if we successfully ran (even if there was nothing to anonymize).
        return ( $did_work || true );
    }

    private function hash_ip_for_analytics( $ip ) {
        $ip = trim( (string) $ip );
        if ( $ip === '' ) {
            return '';
        }

        // Use a salted one way hash that fits within the logs table varchar(45) column.
        $salt = wp_salt( 'auth' );
        return sha1( $salt . '|' . $ip );
    }

    private function get_ip_value_for_logging() {
        $raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $opts   = $this->get_options();
        $mode   = isset( $opts['ip_storage'] ) ? $opts['ip_storage'] : 'hashed';

        if ( $mode === 'off' ) {
            return '';
        }
        if ( $mode === 'raw' ) {
            return $raw_ip;
        }

        return $this->hash_ip_for_analytics( $raw_ip );
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

        $ip  = $this->get_ip_value_for_logging();
        $now = current_time( 'mysql' );

        $wpdb->insert(
            $table_name,
            array(
                'search_query'  => $search_query,
                'results_count' => (int) $results_count,
                'ai_success'    => $ai_success ? 1 : 0,
                'ai_error'      => $ai_error,
                'created_at'    => $now,
                'ip'            => $ip,
            ),
            array(
                '%s',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
            )
        );
    }

    /* ---------------------------------------------------------
     *  Options and settings
     * --------------------------------------------------------- */

    public function get_options() {
        $defaults = array(
            'api_key'              => '',
            'model'                => 'gpt-4.1-mini',
            'max_posts'            => 6,
            'enable'               => 0,
            'max_calls_per_minute' => 30,
            'cache_ttl'            => 3600,
            'ip_storage'           => 'hashed',
        );

        $opts = get_option( $this->option_name, array() );
        return wp_parse_args( $opts, $defaults );
    }

    public function sanitize_options( $input ) {
        $output = array();

        $output['api_key']   = isset( $input['api_key'] ) ? trim( $input['api_key'] ) : '';
        $output['model']     = isset( $input['model'] ) ? sanitize_text_field( $input['model'] ) : 'gpt-4.1-mini';
        $output['max_posts'] = isset( $input['max_posts'] ) ? max( 1, intval( $input['max_posts'] ) ) : 6;
        $output['enable']    = ! empty( $input['enable'] ) ? 1 : 0;

        $output['max_calls_per_minute'] = isset( $input['max_calls_per_minute'] )
            ? max( 0, intval( $input['max_calls_per_minute'] ) )
            : 30;

        if ( isset( $input['cache_ttl'] ) ) {
            $ttl = intval( $input['cache_ttl'] );
            if ( $ttl < 60 ) {
                $ttl = 60;
            } elseif ( $ttl > 86400 ) {
                $ttl = 86400;
            }
            $output['cache_ttl'] = $ttl;
        } else {
            $output['cache_ttl'] = 3600;
        }


        $allowed_ip_storage = array( 'off', 'hashed', 'raw' );
        $ip_storage         = isset( $input['ip_storage'] ) ? sanitize_text_field( $input['ip_storage'] ) : 'hashed';
        $output['ip_storage'] = in_array( $ip_storage, $allowed_ip_storage, true ) ? $ip_storage : 'hashed';

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

        add_settings_field(
            'ip_storage',
            'Analytics IP storage',
            array( $this, 'field_ip_storage' ),
            'rt-ai-search',
            'rt_ai_search_main'
        );
    }

    public function field_api_key() {
        $options = $this->get_options();
        ?>
        <input type="password" name="<?php echo esc_attr( $this->option_name ); ?>[api_key]"
               value="<?php echo esc_attr( $options['api_key'] ); ?>"
               style="width: 400px;" autocomplete="off" />
        <p class="description">Create an API key in the OpenAI dashboard and paste it here.</p>
        <?php
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
            Pick the OpenAI model to use for AI search. Use the button below to refresh the list from OpenAI.
        </p>
        <?php
    }

    public function field_max_posts() {
        $options = $this->get_options();
        ?>
        <input type="number" name="<?php echo esc_attr( $this->option_name ); ?>[max_posts]"
               value="<?php echo esc_attr( $options['max_posts'] ); ?>"
               min="1" max="30" />
        <p class="description">How many posts or pages to pass as context for each search.</p>
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

    public function field_cache_ttl() {
        $options = $this->get_options();
        $value   = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 3600;
        ?>
        <input type="number"
               name="<?php echo esc_attr( $this->option_name ); ?>[cache_ttl]"
               value="<?php echo esc_attr( $value ); ?>"
               min="60"
               max="86400"
               step="60"
               style="width: 100px;" />
        <p class="description">
            How long to cache each AI summary in seconds. Minimum 60 seconds, maximum 86400 seconds (24 hours).
        </p>
        <?php
    }

    public function field_ip_storage() {
        $options = $this->get_options();
        $value   = isset( $options['ip_storage'] ) ? $options['ip_storage'] : 'hashed';
        ?>
        <select name="<?php echo esc_attr( $this->option_name ); ?>[ip_storage]">
            <option value="off" <?php selected( $value, 'off' ); ?>>Off (do not store)</option>
            <option value="hashed" <?php selected( $value, 'hashed' ); ?>>Hashed (recommended)</option>
            <option value="raw" <?php selected( $value, 'raw' ); ?>>Raw IP</option>
        </select>
        <p class="description">
            Controls how IP addresses are stored in Analytics. Hashed stores a one way hash for approximate uniqueness.
        </p>
        <?php
    }

    /* ---------------------------------------------------------
     *  Model list helpers
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
        $default_models = array(
            'gpt-5.1',
            'gpt-5',
            'gpt-5-mini',
            'gpt-5-nano',
            'gpt-4.1',
            'gpt-4.1-mini',
            'gpt-4o',
            'gpt-4.1-mini',
            'gpt-3.5-turbo',
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

    /* ---------------------------------------------------------
     *  Settings page
     * --------------------------------------------------------- */

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
        ?>
        <div class="wrap">
            <h1>AI Search Settings</h1>

            <?php if ( $refreshed ) : ?>
                <div class="updated notice">
                    <p>Model list refreshed from OpenAI.</p>
                </div>
            <?php elseif ( ! empty( $error ) ) : ?>
                <div class="error notice">
                    <p><?php echo esc_html( $error ); ?></p>
                </div>
            <?php endif; ?>

            <?php if ( $cache_cleared && empty( $cache_clear_error ) ) : ?>
                <div class="updated notice">
                    <p>AI summary cache cleared. New searches will fetch fresh answers.</p>
                </div>
            <?php elseif ( ! empty( $cache_clear_error ) ) : ?>
                <div class="error notice">
                    <p><?php echo esc_html( $cache_clear_error ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'rt_ai_search_group' );
                do_settings_sections( 'rt-ai-search' );
                submit_button();
                ?>
            </form>

            <p style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                <a href="<?php echo esc_url( $refresh_url ); ?>" class="button">
                    Refresh model list from OpenAI
                </a>
                <a href="<?php echo esc_url( $clear_cache_url ); ?>" class="button">
                    Clear AI cache
                </a>
            </p>

            <?php if ( is_array( $cache ) && ! empty( $cache['updated_at'] ) ) : ?>
                <p class="description">
                    Model list last updated:
                    <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $cache['updated_at'] ) ) ); ?>
                </p>
            <?php endif; ?>

            <p class="description">
                For detailed AI search analytics, open AI Search in the sidebar and click Analytics.
            </p>
        </div>
        <?php
    }

    /* ---------------------------------------------------------
     *  Analytics page
     * --------------------------------------------------------- */

    public function render_analytics_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $logs_built      = false;
    $logs_error      = '';
    $anonymized      = false;
    $anonymize_error = '';

    // Handle explicit admin actions via query params.
    if (
        isset( $_GET['rt_ai_rebuild_logs'] ) &&
        $_GET['rt_ai_rebuild_logs'] === '1' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'rt_ai_rebuild_logs' )
    ) {
        $logs_built = $this->ensure_logs_table();
        if ( ! $logs_built ) {
            $logs_error = 'Could not create or verify the analytics table. Check database permissions.';
        }
    }

    if (
        isset( $_GET['rt_ai_anonymize_ips'] ) &&
        $_GET['rt_ai_anonymize_ips'] === '1' &&
        isset( $_GET['_wpnonce'] ) &&
        wp_verify_nonce( $_GET['_wpnonce'], 'rt_ai_anonymize_ips' )
    ) {
        $anonymized = $this->anonymize_existing_ips();
        if ( ! $anonymized ) {
            $anonymize_error = 'Could not anonymize existing IP addresses. Check database permissions.';
        }
    }

    $logs_url = wp_nonce_url(
        admin_url( 'admin.php?page=rt-ai-search-analytics&rt_ai_rebuild_logs=1' ),
        'rt_ai_rebuild_logs'
    );

    $anonymize_url = wp_nonce_url(
        admin_url( 'admin.php?page=rt-ai-search-analytics&rt_ai_anonymize_ips=1' ),
        'rt_ai_anonymize_ips'
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

        <?php if ( $anonymized && empty( $anonymize_error ) ) : ?>
            <div class="updated notice">
                <p>Existing IP addresses have been anonymized successfully.</p>
            </div>
        <?php elseif ( ! empty( $anonymize_error ) ) : ?>
            <div class="error notice">
                <p><?php echo esc_html( $anonymize_error ); ?></p>
            </div>
        <?php endif; ?>

        <p style="margin-bottom:1rem;">
            <a href="<?php echo esc_url( $logs_url ); ?>" class="button">
                Create or repair analytics table
            </a>
            <a href="<?php echo esc_url( $anonymize_url ); ?>" class="button button-secondary" style="margin-left: 8px;">
                Anonymize existing IPs
            </a>
        </p>

        <?php $this->render_analytics_section(); ?>
    </div>
    <?php
}

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
        $success_rate   = $total_searches > 0 ? round( ( $success_count / $total_searches ) * 100 ) : 0;

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
                        $day_total   = (int) $row->total;
                        $day_success = (int) $row->success_count;
                        $day_rate    = $day_total > 0 ? round( ( $day_success / $day_total ) * 100 ) : 0;
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
                        $total_q        = (int) $row->total;
                        $success_q      = (int) $row->success_count;
                        $success_q_rate = $total_q > 0 ? round( ( $success_q / $total_q ) * 100 ) : 0;
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
                        <th>IP</th>
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
                            <td><?php echo esc_html( $event->ip ); ?></td>
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
        $success_rate   = $total_searches > 0 ? round( ( $success_count / $total_searches ) * 100 ) : 0;

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
                            $total_q        = (int) $row->total;
                            $success_q      = (int) $row->success_count;
                            $success_q_rate = $total_q > 0 ? round( ( $success_q / $total_q ) * 100 ) : 0;
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

    /* ---------------------------------------------------------
     *  Frontend placeholder
     * --------------------------------------------------------- */

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
     *  REST route and AI logic
     * --------------------------------------------------------- */

    public function register_rest_routes() {
        register_rest_route(
            'rt-ai-search/v1',
            '/summary',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_summary' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'q' => array(
                        'required'          => true,
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            )
        );
    }

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
            $max_posts = 8;
        }

        $post_type = 'any';

        $posts_for_ai = array();
        $used_ids     = array();

        $recent_date = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

        $recent_args = array(
            's'              => $search_query,
            'post_type'      => $post_type,
            'posts_per_page' => $max_posts,
            'post_status'    => 'publish',
            'date_query'     => array(
                array(
                    'after'     => $recent_date,
                    'inclusive' => true,
                    'column'    => 'post_date',
                ),
            ),
        );

        $recent_query = new WP_Query( $recent_args );

        if ( $recent_query->have_posts() ) {
            foreach ( $recent_query->posts as $post ) {
                $used_ids[] = $post->ID;

                $content = wp_strip_all_tags( $post->post_content );
                $content = mb_substr( $content, 0, 400 );

                $posts_for_ai[] = array(
                    'id'      => $post->ID,
                    'title'   => get_the_title( $post ),
                    'url'     => get_permalink( $post ),
                    'excerpt' => mb_substr( $content, 0, 200 ),
                    'content' => $content,
                    'type'    => $post->post_type,
                    'date'    => get_the_date( 'Y-m-d', $post ),
                );
            }
        }

        $remaining = $max_posts - count( $posts_for_ai );
        if ( $remaining > 0 ) {
            $older_args = array(
                's'              => $search_query,
                'post_type'      => $post_type,
                'posts_per_page' => $remaining,
                'post_status'    => 'publish',
                'post__not_in'   => $used_ids,
                'date_query'     => array(
                    array(
                        'before'    => $recent_date,
                        'inclusive' => false,
                        'column'    => 'post_date',
                    ),
                ),
            );

            $older_query = new WP_Query( $older_args );

            if ( $older_query->have_posts() ) {
                foreach ( $older_query->posts as $post ) {
                    $content = wp_strip_all_tags( $post->post_content );
                    $content = mb_substr( $content, 0, 400 );

                    $posts_for_ai[] = array(
                        'id'      => $post->ID,
                        'title'   => get_the_title( $post ),
                        'url'     => get_permalink( $post ),
                        'excerpt' => mb_substr( $content, 0, 200 ),
                        'content' => $content,
                        'type'    => $post->post_type,
                        'date'    => get_the_date( 'Y-m-d', $post ),
                    );
                }
            }
        }

        if ( count( $posts_for_ai ) < $max_posts ) {
            $remaining_fallback = $max_posts - count( $posts_for_ai );

            $fallback_args = array(
                's'              => $search_query,
                'post_type'      => $post_type,
                'posts_per_page' => $remaining_fallback,
                'post_status'    => 'publish',
                'post__not_in'   => wp_list_pluck( $posts_for_ai, 'id' ),
            );

            $fallback_query = new WP_Query( $fallback_args );

            if ( $fallback_query->have_posts() ) {
                foreach ( $fallback_query->posts as $post ) {
                    $content = wp_strip_all_tags( $post->post_content );
                    $content = mb_substr( $content, 0, 400 );

                    $posts_for_ai[] = array(
                        'id'      => $post->ID,
                        'title'   => get_the_title( $post ),
                        'url'     => get_permalink( $post ),
                        'excerpt' => mb_substr( $content, 0, 200 ),
                        'content' => $content,
                        'type'    => $post->post_type,
                        'date'    => get_the_date( 'Y-m-d', $post ),
                    );
                }
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

    /* ---------------------------------------------------------
     *  API throttling helper
     * --------------------------------------------------------- */

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
        set_transient( $key, $count, 70 );

        return false;
    }

    /* ---------------------------------------------------------
     *  AI data + cache + JSON parsing
     * --------------------------------------------------------- */

    private function get_ai_data_for_search( $search_query, $posts_for_ai, &$ai_error = '' ) {
        $options = $this->get_options();
        if ( empty( $options['api_key'] ) || empty( $options['enable'] ) ) {
            $ai_error = 'AI search is not configured.';
            return null;
        }

        $normalized_query = strtolower( trim( $search_query ) );
        $namespace        = $this->get_cache_namespace();
        $cache_key        = $this->cache_prefix . 'ns' . $namespace . '_' . md5( $options['model'] . '|' . $normalized_query );
        $cached_raw       = get_transient( $cache_key );

        if ( $cached_raw ) {
            $ai_data = json_decode( $cached_raw, true );
            if ( json_last_error() === JSON_ERROR_NONE && is_array( $ai_data ) ) {
                return $ai_data;
            }
        }

        if ( $this->is_rate_limited_for_ai_calls() ) {
            $ai_error = 'AI summary is temporarily unavailable due to rate limits. Please try again in a moment.';
            return null;
        }

        $api_response = $this->call_openai_for_search(
            $options['api_key'],
            $options['model'],
            $search_query,
            $posts_for_ai
        );

        if ( isset( $api_response['error'] ) ) {
            $ai_error = $api_response['error'];
            return null;
        }

        if ( empty( $api_response['choices'][0]['message']['content'] ) ) {
            $ai_error = 'Empty response from OpenAI.';
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
            $ai_error = 'Failed to parse OpenAI JSON response.';
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

    private function call_openai_for_search( $api_key, $model, $user_query, $posts ) {
        if ( empty( $api_key ) ) {
            return array( 'error' => 'OpenAI API key is not configured.' );
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

            // Bumped timeout to reduce cURL error 28 on slower models and larger prompts
            'timeout' => 60,
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            error_log( '[RivianTrackr AI Search] API request error: ' . $response->get_error_message() );
            return array( 'error' => $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            error_log( '[RivianTrackr AI Search] API HTTP error ' . $code . ' body: ' . $body );
            $decoded_error = json_decode( $body, true );
            if ( isset( $decoded_error['error']['message'] ) ) {
                return array( 'error' => $decoded_error['error']['message'] );
            }
            return array( 'error' => 'HTTP ' . $code . ' from OpenAI.' );
        }

        $decoded = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( '[RivianTrackr AI Search] Failed to decode OpenAI response: ' . json_last_error_msg() );
            return array( 'error' => 'Failed to decode OpenAI response.' );
        }

        return $decoded;
    }

    /* ---------------------------------------------------------
     *  Sources render
     * --------------------------------------------------------- */

    private function render_sources_html( $sources ) {
        if ( empty( $sources ) || ! is_array( $sources ) ) {
            return '';
        }

        $sources = array_slice( $sources, 0, 5 );
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