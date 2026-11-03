<?php
/**
 * Fires only on Delete (not Deactivate) from the Plugins screen: removes the
 * settings, the forms and the table of unsent emails. Nothing is changed in meil.ro.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'meil_mailer_settings' );
delete_option( 'meil_confirm_page_problem' );
delete_option( 'meil_queue_db' );
delete_option( 'meil_unsent_seen' );
delete_option( 'meil_integrations' );
delete_transient( 'meil_key_checked' );
delete_transient( 'meil_api_down' );
delete_transient( 'meil_refused_from' );
wp_clear_scheduled_hook( 'meil_queue_run' );

// The emails and sign-ups still waiting, or failed, go with the plugin.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}meil_queue" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- the plugin's own table, on uninstall.

$meil_forms = get_posts(
	array(
		'post_type'      => 'meil_form',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $meil_forms as $meil_form_id ) {
	wp_delete_post( (int) $meil_form_id, true );
}
