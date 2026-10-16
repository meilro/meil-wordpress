<?php
/**
 * Fires only on Delete (not Deactivate) from the Plugins screen.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'meil_mailer_settings' );
