<?php
/**
 * Plugin Name:       meil.ro – Email & Newsletter
 * Plugin URI:        https://github.com/meilro/meil-wordpress
 * Description:       Sends WordPress mail through meil.ro, with subscription forms and a preferences page for logged-in users.
 * Version:           1.3.0
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

define( 'MEIL_MAILER_VERSION', '1.3.0' );
define( 'MEIL_MAILER_FILE', __FILE__ );
define( 'MEIL_MAILER_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEIL_MAILER_URL', plugin_dir_url( __FILE__ ) );
define( 'MEIL_MAILER_OPTION', 'meil_mailer_settings' );
// Both can be set in wp-config.php first, to point the plugin at a test server.
if ( ! defined( 'MEIL_MAILER_API_BASE_URL' ) ) {
	define( 'MEIL_MAILER_API_BASE_URL', 'https://api.meil.ro' );
}
if ( ! defined( 'MEIL_MAILER_APP_URL' ) ) {
	define( 'MEIL_MAILER_APP_URL', 'https://meil.ro' );
}

require_once MEIL_MAILER_DIR . 'includes/class-meil-client.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-queue.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-queue-admin.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-mailer.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-mailer-admin.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-forms.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-preferences.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-confirm.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-users.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-signup.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-blocks.php';
require_once MEIL_MAILER_DIR . 'includes/integrations/class-meil-integration.php';
require_once MEIL_MAILER_DIR . 'includes/integrations/class-meil-integration-cf7.php';
require_once MEIL_MAILER_DIR . 'includes/integrations/class-meil-integration-wpforms.php';
require_once MEIL_MAILER_DIR . 'includes/integrations/class-meil-integration-fluent.php';
require_once MEIL_MAILER_DIR . 'includes/integrations/class-meil-integration-gravity.php';
require_once MEIL_MAILER_DIR . 'includes/integrations/class-meil-integration-elementor.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-integrations.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-popups.php';
require_once MEIL_MAILER_DIR . 'includes/class-meil-sent-admin.php';

/**
 * Returns the plugin's saved settings, merged with defaults.
 *
 * key_domain and key_permission are what meil.ro said about the API key when
 * it was saved (GET /key), so pages never have to ask again.
 *
 * @return array{api_key:string,from_email:string,from_name:string,override_wp_mail:bool,load_css:bool,delete_with_user:bool,class_button:string,class_input:string,class_checkbox:string,signup_places:list<string>,signup_audiences:list<string>,signup_label:string,signup_confirm:bool,popup_form:int,popup_kind:string,popup_trigger:string,popup_value:int,popup_where:string,popup_days:int,popup_title:string,popup_text:string,key_domain:string,key_permission:string}
 */
function meil_mailer_settings(): array {
	$defaults = array(
		'api_key'          => '',
		'from_email'       => get_bloginfo( 'admin_email' ),
		'from_name'        => get_bloginfo( 'name' ),
		'override_wp_mail' => true,
		'load_css'         => true,
		'delete_with_user' => false,
		'class_button'     => '',
		'class_input'      => '',
		'class_checkbox'   => '',
		'signup_places'    => array(),
		'signup_audiences' => array(),
		'signup_label'     => '',
		'signup_confirm'   => true,
		'popup_form'       => 0,
		'popup_kind'       => 'modal',
		'popup_trigger'    => 'delay',
		'popup_value'      => 10,
		'popup_where'      => 'all',
		'popup_days'       => 14,
		'popup_title'      => '',
		'popup_text'       => '',
		'key_domain'       => '',
		'key_permission'   => '',
	);
	$saved    = get_option( MEIL_MAILER_OPTION, array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
}

/**
 * Can the API key do this? $capability is one of meil.ro's: manage
 * (audiences and contacts: forms, preferences, sign-ups, popups, form
 * plugins), broadcasts, email:send and email:read. "Full access" can do all
 * of them, "Emails and broadcasts" all but manage, "Transactional only" the
 * two email ones. A key whose permission is not known yet (saved before 1.1,
 * until the settings screen is opened) is let through: meil.ro still says no
 * if it must.
 */
function meil_key_can( string $capability ): bool {
	$grants   = array(
		'full_access'      => array( 'manage', 'broadcasts', 'email:send', 'email:read' ),
		'broadcast_access' => array( 'broadcasts', 'email:send', 'email:read' ),
		'email_access'     => array( 'email:send', 'email:read' ),
	);
	$settings = meil_mailer_settings();
	if ( '' === trim( (string) $settings['api_key'] ) ) {
		return false;
	}
	$permission = (string) $settings['key_permission'];
	if ( '' === $permission ) {
		return true;
	}
	return in_array( $capability, $grants[ $permission ] ?? $grants['email_access'], true );
}

/**
 * The classes set on the meil.ro screen for one kind of element (button,
 * input or checkbox), to add after the plugin's own, so the forms can take a
 * theme's styles ("btn btn-primary", "form-control"): '' or ' and the classes'.
 */
function meil_extra_classes( string $kind ): string {
	$classes = trim( (string) ( meil_mailer_settings()[ 'class_' . $kind ] ?? '' ) );
	return '' !== $classes ? ' ' . $classes : '';
}

/** Builds a Meil_Client from the current settings. */
function meil_mailer_client(): Meil_Client {
	$settings = meil_mailer_settings();
	return new Meil_Client( $settings['api_key'] );
}

/**
 * The visitor's IP address, for the consent record. Behind Cloudflare,
 * REMOTE_ADDR is Cloudflare's server and the visitor is in CF-Connecting-IP;
 * that header is only believed when the request really comes from one of
 * Cloudflare's addresses, so nobody can write their own. Sites behind another
 * proxy can supply the address with the 'meil_visitor_ip' filter.
 */
function meil_visitor_ip(): string {
	$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
	$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '';
	$cf     = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : '';
	$ip     = ( '' !== $remote && filter_var( $cf, FILTER_VALIDATE_IP ) && meil_is_cloudflare( $remote ) ) ? $cf : $remote;
	return (string) apply_filters( 'meil_visitor_ip', $ip, $remote );
}

/** Is this address one of Cloudflare's (https://www.cloudflare.com/ips/)? */
function meil_is_cloudflare( string $ip ): bool {
	$ranges = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);
	$address = inet_pton( $ip );
	if ( false === $address ) {
		return false;
	}
	foreach ( $ranges as $range ) {
		list( $subnet, $bits ) = explode( '/', $range );
		$net = inet_pton( $subnet );
		if ( false === $net || strlen( $net ) !== strlen( $address ) ) {
			continue; // IPv4 against IPv6, or the other way round.
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $address, 0, $bytes ) !== substr( $net, 0, $bytes ) ) {
			continue;
		}
		$rest = $bits % 8;
		if ( 0 === $rest || ( ( ord( $address[ $bytes ] ) ^ ord( $net[ $bytes ] ) ) & ( 0xFF << ( 8 - $rest ) ) & 0xFF ) === 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Renders a front-end template. A theme can replace any of them by copying the
 * file to its own folder as meil/<name>.php (child themes first), and the
 * 'meil_template_path' filter can point elsewhere.
 *
 * @param array<string,mixed> $vars Made available to the template as variables.
 */
function meil_template( string $name, array $vars ): string {
	$path = locate_template( array( 'meil/' . $name . '.php' ) );
	if ( '' === $path ) {
		$path = MEIL_MAILER_DIR . 'templates/' . $name . '.php';
	}
	$path = (string) apply_filters( 'meil_template_path', $path, $name, $vars );

	ob_start();
	// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template variables, set by the plugin.
	extract( $vars, EXTR_SKIP );
	include $path;
	return (string) ob_get_clean();
}

/**
 * The API key's audiences, kept for 5 minutes: they rarely change, and every
 * call counts toward the account's requests per second. Used by the front end
 * (forms and the preferences page); the admin screens always ask fresh.
 *
 * @return list<array<string,mixed>>|WP_Error
 */
function meil_cached_audiences( Meil_Client $client ) {
	$cache  = 'meil_audiences_' . md5( (string) meil_mailer_settings()['api_key'] );
	$result = get_transient( $cache );
	if ( ! is_array( $result ) ) {
		$result = $client->list_audiences();
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		set_transient( $cache, $result, 5 * MINUTE_IN_SECONDS );
	}
	return array_values( array_filter( (array) ( $result['data'] ?? array() ), 'is_array' ) );
}

/**
 * The address the forms are sent to in the background (admin-ajax.php). On a
 * site in several languages (WPML, Polylang) it carries the page's language,
 * which both read for a background request, so the answer comes in it.
 */
function meil_ajax_url(): string {
	$url = admin_url( 'admin-ajax.php' );
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API, which Polylang answers too.
	$language = apply_filters( 'wpml_current_language', null );
	return is_string( $language ) && '' !== $language ? add_query_arg( 'lang', $language, $url ) : $url;
}

/**
 * In a background request from a page in another language: the plugin's
 * own texts in that language too. WPML and Polylang have taken the language
 * from the request (meil_ajax_url()); WordPress's translations follow here
 * when they have not already.
 */
function meil_ajax_language(): void {
	$locale = '';
	if ( function_exists( 'pll_current_language' ) ) {
		$locale = (string) pll_current_language( 'locale' );
	} else {
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API.
		$code      = apply_filters( 'wpml_current_language', null );
		$languages = apply_filters( 'wpml_active_languages', null );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( is_string( $code ) && is_array( $languages ) && isset( $languages[ $code ]['default_locale'] ) ) {
			$locale = (string) $languages[ $code ]['default_locale'];
		}
	}
	if ( '' !== $locale && determine_locale() !== $locale ) {
		switch_to_locale( $locale );
	}
}

/**
 * The version a plugin file is loaded with: the plugin's, plus when the file
 * last changed, so a browser never keeps an old copy after an update.
 */
function meil_asset_version( string $file ): string {
	$changed = @filemtime( MEIL_MAILER_DIR . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just keeps the plain version.
	return MEIL_MAILER_VERSION . ( $changed ? '.' . $changed : '' );
}

/**
 * Loads what the forms need on the page: the script that sends them in the
 * background, and the stylesheet unless it was turned off in the settings.
 */
function meil_enqueue_front_assets(): void {
	wp_enqueue_script( 'meil-forms', MEIL_MAILER_URL . 'assets/forms.js', array(), meil_asset_version( 'assets/forms.js' ), true );
	$settings = meil_mailer_settings();
	if ( ! empty( $settings['load_css'] ) ) {
		wp_enqueue_style( 'meil-forms', MEIL_MAILER_URL . 'assets/forms.css', array(), meil_asset_version( 'assets/forms.css' ) );
	}
}

/**
 * Bootstraps the plugin once every other plugin has loaded, so filters
 * registered elsewhere (e.g. by mu-plugins tweaking mail) are already in place.
 */
function meil_mailer_bootstrap(): void {
	load_plugin_textdomain( 'meil', false, dirname( plugin_basename( MEIL_MAILER_FILE ) ) . '/languages' );

	$settings = meil_mailer_settings();
	$client   = meil_mailer_client();
	$queue    = new Meil_Queue( $client );

	new Meil_Mailer( $client, $settings, $queue );
	$forms = new Meil_Forms( $client );

	if ( is_admin() ) {
		Meil_Queue::maybe_install();
		new Meil_Mailer_Admin( $client, MEIL_MAILER_OPTION, $forms );
		new Meil_Queue_Admin( $queue );
		new Meil_Sent_Admin( $client );
	}

	$preferences = new Meil_Preferences( $client );
	new Meil_Blocks( $forms, $preferences );
	new Meil_Popups( $forms );
	new Meil_Confirm( $client );
	new Meil_Users( $client, $settings );
	new Meil_Signup( $client, $settings, $queue );
	new Meil_Integrations( $client, $queue );
}
add_action( 'plugins_loaded', 'meil_mailer_bootstrap' );

/** The table for emails and sign-ups waiting to be tried again (see Meil_Queue). */
function meil_mailer_activate(): void {
	Meil_Queue::install();
	Meil_Queue::schedule();
}
register_activation_hook( __FILE__, 'meil_mailer_activate' );

/** Waiting calls stay in the table and are tried again once the plugin is back on. */
function meil_mailer_deactivate(): void {
	wp_clear_scheduled_hook( Meil_Queue::HOOK );
}
register_deactivation_hook( __FILE__, 'meil_mailer_deactivate' );

/**
 * The top of a plugin screen, the way meil.ro's own pages start: the title,
 * a line under it, then the line WordPress puts its notices under.
 */
function meil_admin_head( string $title, string $subtitle = '' ): void {
	echo '<div class="meil-head"><div class="meil-head__text"><h1 class="meil-head__title">' . esc_html( $title ) . '</h1>';
	if ( '' !== $subtitle ) {
		echo '<p class="meil-head__subtitle">' . esc_html( $subtitle ) . '</p>';
	}
	echo '</div></div><hr class="wp-header-end" />';
}

/** Is this one of the plugin's admin screens? Then it wears meil.ro's look. */
function meil_is_admin_screen(): bool {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	return $screen && ( false !== strpos( (string) $screen->id, 'meil' ) || Meil_Forms::POST_TYPE === $screen->post_type );
}

/** @param string $classes */
function meil_admin_body_class( $classes ): string {
	return meil_is_admin_screen() ? $classes . ' meil-screen' : (string) $classes;
}
add_filter( 'admin_body_class', 'meil_admin_body_class' );

/**
 * Loads the admin stylesheet on this plugin's own screens, and the script
 * that shows a button is working once its form is sent.
 */
function meil_mailer_enqueue_admin_assets( string $hook ): void {
	$screen = get_current_screen();
	if ( false === strpos( $hook, 'meil' ) && ( ! $screen || Meil_Forms::POST_TYPE !== $screen->post_type ) ) {
		return;
	}
	wp_enqueue_style( 'meil-mailer-admin', MEIL_MAILER_URL . 'assets/admin.css', array(), meil_asset_version( 'assets/admin.css' ) );
	wp_enqueue_script( 'meil-mailer-admin', MEIL_MAILER_URL . 'assets/admin.js', array(), meil_asset_version( 'assets/admin.js' ), true );
}
add_action( 'admin_enqueue_scripts', 'meil_mailer_enqueue_admin_assets' );

/** Adds a "Settings" link on the Plugins screen. */
function meil_mailer_action_links( array $links ): array {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=meil-mailer' ) ) . '">' . esc_html__( 'Settings', 'meil' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( MEIL_MAILER_FILE ), 'meil_mailer_action_links' );
