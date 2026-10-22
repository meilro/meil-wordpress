<?php
/**
 * Fires only on Delete (not Deactivate) from the Plugins screen: removes the
 * settings and the forms. Nothing is changed in meil.ro.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'meil_mailer_settings' );

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
