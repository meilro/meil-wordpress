<?php
/**
 * Plugin Name:       meil.ro – Email & Newsletter
 * Plugin URI:        https://github.com/meilro/meil-wordpress
 * Description:       Sends WordPress mail through meil.ro, with a test-send tool and a newsletter signup form.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            meil.ro
 * Author URI:        https://meil.ro
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       meil
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEIL_MAILER_VERSION', '1.0.0' );
define( 'MEIL_MAILER_FILE', __FILE__ );
define( 'MEIL_MAILER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEIL_MAILER_URL', plugin_dir_url( __FILE__ ) );
define( 'MEIL_MAILER_OPTION', 'meil_mailer_settings' );
define( 'MEIL_MAILER_API_BASE_URL', 'https://api.meil.ro' );
define( 'MEIL_MAILER_APP_URL', 'https://meil.ro' );

require_once MEIL_MAILER_DIR . 'includes/class-meil-client.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-mailer.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-mailer-admin.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-mailer-signup.php';

/**
 * Returns the plugin's saved settings, merged with defaults.
 *
 * @return array{api_key:string,from_email:string,from_name:string,override_wp_mail:bool}
 */
function meil_mailer_settings(): array {
	$defaults = array(
		'api_key'          => '',
		'from_email'       => get_bloginfo( 'admin_email' ),
		'from_name'        => get_bloginfo( 'name' ),
		'override_wp_mail' => true,
	);
	$saved    = get_option( MEIL_MAILER_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/** Builds a Meil_Client from the current settings. */
function meil_mailer_client(): Meil_Client {
	$settings = meil_mailer_settings();
	return new Meil_Client( $settings['api_key'] );
}

/**
 * Bootstraps the plugin once every other plugin has loaded, so filters
 * registered elsewhere (e.g. by mu-plugins tweaking mail) are already in place.
 */
function meil_mailer_bootstrap(): void {
	load_plugin_textdomain( 'meil', false, dirname( plugin_basename( MEIL_MAILER_FILE ) ) . '/languages' );

	$settings = meil_mailer_settings();
	$client   = meil_mailer_client();

	new Meil_Mailer( $client, $settings );

	if ( is_admin() ) {
		new Meil_Mailer_Admin( $client, MEIL_MAILER_OPTION );
	}

	new Meil_Mailer_Signup( $client );
}
add_action( 'plugins_loaded', 'meil_mailer_bootstrap' );

/** Loads the admin stylesheet, only on this plugin's own screen. */
function meil_mailer_enqueue_admin_styles( string $hook ): void {
	if ( false === strpos( $hook, 'meil-mailer' ) ) {
		return;
	}
	wp_enqueue_style( 'meil-mailer-admin', MEIL_MAILER_URL . 'assets/admin.css', array(), MEIL_MAILER_VERSION );
}
add_action( 'admin_enqueue_scripts', 'meil_mailer_enqueue_admin_styles' );

/** Adds a "Settings" link on the Plugins screen. */
function meil_mailer_action_links( array $links ): array {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=meil-mailer' ) ) . '">' . esc_html__( 'Settings', 'meil' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( MEIL_MAILER_FILE ), 'meil_mailer_action_links' );
