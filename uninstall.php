<?php
/**
 * Uninstall script for AI Search Summary
 *
 * This file runs when the plugin is deleted via the WordPress admin.
 * It removes all plugin data including options, transients, and database tables.
 *
 * @package AI_Search_Summary
 */

// Exit if not called by WordPress uninstall
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Delete plugin options
delete_option( 'aiss_options' );
delete_option( 'aiss_models_cache' );
delete_option( 'aiss_cache_namespace' );
delete_option( 'aiss_cache_keys' ); // Legacy option
delete_option( 'aiss_db_version' );

// Delete all transients created by the plugin
// Transients are stored in options table with _transient_ prefix
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_aiss_%'
        OR option_name LIKE '_transient_timeout_aiss_%'"
);

// Drop the logs table
$table_name = $wpdb->prefix . 'aiss_logs';
$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

// Clear any cached data in object cache (if persistent caching is used)
wp_cache_flush();
