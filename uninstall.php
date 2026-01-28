<?php
/**
 * Uninstall script for RivianTrackr AI Search
 *
 * This file runs when the plugin is deleted via the WordPress admin.
 * It removes all plugin data including options, transients, and database tables.
 *
 * @package RivianTrackr_AI_Search
 */

// Exit if not called by WordPress uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete plugin options
delete_option( 'rt_ai_search_options' );
delete_option( 'rt_ai_search_models_cache' );
delete_option( 'rt_ai_search_cache_namespace' );
delete_option( 'rt_ai_search_cache_keys' ); // Legacy option

// Delete all transients created by the plugin
// Transients are stored in options table with _transient_ prefix
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_rt_ai_%'
        OR option_name LIKE '_transient_timeout_rt_ai_%'"
);

// Drop the logs table
$table_name = $wpdb->prefix . 'rt_ai_search_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Clear any cached data in object cache (if persistent caching is used)
wp_cache_flush();
