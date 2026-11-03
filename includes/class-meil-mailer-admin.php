<?php
/**
 * Settings screen: meil.ro.
 *
 * Four tabs on one option: General (the connection fields, the "send a test
 * email" tool through a separate admin-post.php handler, and how to place the
 * preferences page), Sign-up checkbox, Popup (one form shown on top of the
 * pages, with a preview) and Forms look. Standard Settings API (options.php).
 *
 * Saving a key asks meil.ro which domain it belongs to (GET /key). A key for
 * another site, or a default from address off the key's domain, is refused
 * with an error and the old settings stay, so nothing breaks later in front
 * of visitors.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Mailer_Admin {

	private const PAGE       = 'meil-mailer';
	private const GROUP      = 'meil_mailer_group';
	private const SECTION    = 'meil_mailer_section';
	private const SIGNUP     = 'meil_mailer_signup';
	private const LOOK       = 'meil_mailer_look';

	/** The "Sign-up checkbox" and "Popup" tabs' own settings pages, so their sections draw apart from the others. */
	private const SIGNUP_TAB = 'meil-mailer-signup';
	private const POPUP      = 'meil_mailer_popup';
	private const POPUP_TAB  = 'meil-mailer-popup';
	private const LOOK_TAB   = 'meil-mailer-look';
	private const TEST_NONCE = 'meil_mailer_test_email';

	/** @var Meil_Client */
	private $client;

	/** @var string */
	private $option_name;

	/** @var Meil_Forms */
	private $forms;

	public function __construct( Meil_Client $client, string $option_name, Meil_Forms $forms ) {
		$this->client      = $client;
		$this->option_name = $option_name;
		$this->forms       = $forms;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_meil_mailer_send_test', array( $this, 'handle_test_email' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'current_screen', array( $this, 'refresh_key' ) );
	}

	/**
	 * A key's permission or domain can be changed in meil.ro after it was
	 * saved here. On the plugin's own screens, at most once an hour, ask again
	 * (GET /key), so the screens offer only what the key can do.
	 *
	 * @param WP_Screen $screen
	 */
	public function refresh_key( $screen ): void {
		$id = $screen instanceof WP_Screen ? (string) $screen->id : '';
		if ( ( false === strpos( $id, 'meil' ) ) || ! current_user_can( 'manage_options' ) || get_transient( 'meil_key_checked' ) ) {
			return;
		}
		set_transient( 'meil_key_checked', 1, HOUR_IN_SECONDS );
		$settings = get_option( $this->option_name, array() );
		if ( ! is_array( $settings ) || '' === (string) ( $settings['api_key'] ?? '' ) ) {
			return;
		}
		$key = $this->check_key( (string) $settings['api_key'] );
		if ( is_string( $key ) || ( $key['domain'] === ( $settings['key_domain'] ?? '' ) && $key['permission'] === ( $settings['key_permission'] ?? '' ) ) ) {
			return; // Not reachable now, or nothing changed.
		}
		$settings['key_domain']     = $key['domain'];
		$settings['key_permission'] = $key['permission'];
		update_option( $this->option_name, $settings ); // The save asks meil.ro again, from check_key()'s memory.
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'meil.ro', 'meilro' ),
			__( 'meil.ro', 'meilro' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_settings_page' ),
			'dashicons-email-alt',
			80
		);
		// The settings come first under the menu, so the menu link opens them.
		// Without this item WordPress lists only Forms, and the link goes there.
		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'meilro' ),
			__( 'Settings', 'meilro' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Connection', 'meilro' ),
			static function (): void {
				echo '<p>' . esc_html__( "Enter an API key created under API Keys in meil.ro for this site's domain. A \"Transactional only\" key is enough for WordPress mail. Forms and the preferences page need \"Full access\".", 'meilro' ) . '</p>';
			},
			self::PAGE
		);

		$fields = array(
			'api_key'          => __( 'API key', 'meilro' ),
			'from_email'       => __( 'Default from address', 'meilro' ),
			'from_name'        => __( 'Default from name', 'meilro' ),
			'override_wp_mail' => __( 'Send WordPress mail through meil', 'meilro' ),
			'delete_with_user' => __( 'Deleted users', 'meilro' ),
		);
		foreach ( $fields as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_field' ),
				self::PAGE,
				self::SECTION,
				array( 'field' => $field )
			);
		}

		// No title of its own: the tab says it.
		add_settings_section(
			self::LOOK,
			'',
			static function (): void {
				echo '<p>' . esc_html__( 'Your own CSS classes are added after the plugin\'s own, on the buttons, fields and checkboxes of its forms and popups, the preferences page, the confirmation page and the sign-up checkbox, so they take your theme\'s look. Separate several with spaces.', 'meilro' ) . '</p>';
			},
			self::LOOK_TAB
		);
		$look = array(
			'load_css'       => __( 'Form styles', 'meilro' ),
			'class_button'   => __( 'Button classes', 'meilro' ),
			'class_input'    => __( 'Field classes', 'meilro' ),
			'class_checkbox' => __( 'Checkbox classes', 'meilro' ),
			'test_mode'      => __( 'Test mode', 'meilro' ),
		);
		foreach ( $look as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_field' ),
				self::LOOK_TAB,
				self::LOOK,
				array( 'field' => $field )
			);
		}

		// No title of its own: the tab says it.
		add_settings_section(
			self::SIGNUP,
			'',
			static function (): void {
				echo '<p>' . esc_html__( 'Adds a "send me news by email" checkbox at checkout and on the registration forms. It starts unticked, and whoever ticks it is added to the audiences below.', 'meilro' ) . '</p>';
			},
			self::SIGNUP_TAB
		);
		$signup = array(
			'signup_places'    => __( 'Show it', 'meilro' ),
			'signup_audiences' => __( 'Add to', 'meilro' ),
			'signup_label'     => __( 'Text', 'meilro' ),
			'signup_confirm'   => __( 'Confirmation', 'meilro' ),
		);
		foreach ( $signup as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_field' ),
				self::SIGNUP_TAB,
				self::SIGNUP,
				array( 'field' => $field )
			);
		}

		add_settings_section(
			self::POPUP,
			'',
			static function (): void {
				echo '<p>' . esc_html__( 'Shows one of your forms on top of your pages, when the visitor has had time to read. It is not shown again to someone who closed it, for the days you choose, nor to someone who already signed up. On phones a popup in the middle shows at the bottom instead, so it never covers the page.', 'meilro' ) . '</p>';
			},
			self::POPUP_TAB
		);
		$popup = array(
			'popup_form'    => __( 'Form', 'meilro' ),
			'popup_kind'    => __( 'Show it as', 'meilro' ),
			'popup_trigger' => __( 'When', 'meilro' ),
			'popup_where'   => __( 'On', 'meilro' ),
			'popup_days'    => __( 'Once closed', 'meilro' ),
			'popup_title'   => __( 'Title', 'meilro' ),
			'popup_text'    => __( 'Text under the title', 'meilro' ),
			'popup_preview' => __( 'Preview', 'meilro' ),
		);
		foreach ( $popup as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_field' ),
				self::POPUP_TAB,
				self::POPUP,
				array( 'field' => $field )
			);
		}
	}

	/**
	 * Checks a new set of settings with meil.ro before they are stored. On a
	 * problem it adds an error for the screen and returns the old settings.
	 *
	 * Each tab posts only its own fields (and _tab); the other tab's settings
	 * are kept as they were. Without _tab (refresh_key()) all of them are given.
	 *
	 * @param array<string,mixed> $input
	 */
	public function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = get_option( $this->option_name, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$tab      = (string) ( $input['_tab'] ?? '' );
		// A tab's own fields come from the form; the other tabs' stay as saved.
		$from = static function ( string $group ) use ( $tab, $input, $previous ): array {
			return '' === $tab || $group === $tab ? $input : wp_parse_args( $previous, array( 'signup_confirm' => true, 'load_css' => true ) );
		};

		// CSS class names only, separated by spaces.
		$classes = static function ( $value ): string {
			return substr( implode( ' ', array_filter( array_map( 'sanitize_html_class', (array) preg_split( '/\s+/', (string) $value ) ) ) ), 0, 200 );
		};
		$f    = $from( 'look' );
		$look = array(
			'load_css'       => ! empty( $f['load_css'] ),
			'class_button'   => $classes( $f['class_button'] ?? '' ),
			'class_input'    => $classes( $f['class_input'] ?? '' ),
			'class_checkbox' => $classes( $f['class_checkbox'] ?? '' ),
			'test_mode'      => ! empty( $f['test_mode'] ),
		);

		$f      = $from( 'signup' );
		$signup = array(
			'signup_places'    => array_values( array_intersect( array( 'checkout', 'register' ), array_map( 'strval', (array) ( $f['signup_places'] ?? array() ) ) ) ),
			'signup_audiences' => array_slice( array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $f['signup_audiences'] ?? array() ) ) ) ) ), 0, 20 ),
			'signup_label'     => sanitize_text_field( (string) ( $f['signup_label'] ?? '' ) ),
			'signup_confirm'   => ! empty( $f['signup_confirm'] ),
		);
		$f     = $from( 'popup' );
		$pick  = static function ( $value, array $allowed ): string {
			return in_array( (string) $value, $allowed, true ) ? (string) $value : $allowed[0];
		};
		$popup = array(
			'popup_form'    => absint( $f['popup_form'] ?? 0 ),
			'popup_kind'    => $pick( $f['popup_kind'] ?? '', array( 'modal', 'slide', 'bar' ) ),
			'popup_trigger' => $pick( $f['popup_trigger'] ?? '', array( 'delay', 'scroll', 'exit' ) ),
			'popup_value'   => min( 600, absint( $f['popup_value'] ?? 10 ) ),
			'popup_where'   => $pick( $f['popup_where'] ?? '', array( 'all', 'posts', 'pages' ) ),
			'popup_days'    => max( 1, min( 365, absint( $f['popup_days'] ?? 14 ) ) ),
			'popup_title'   => sanitize_text_field( (string) ( $f['popup_title'] ?? '' ) ),
			'popup_text'    => sanitize_text_field( (string) ( $f['popup_text'] ?? '' ) ),
		);
		// These tabs change nothing about the connection: no need to ask meil.ro.
		if ( in_array( $tab, array( 'look', 'signup', 'popup' ), true ) && array() !== $previous ) {
			return array_merge( $previous, $look, $signup, $popup );
		}

		$out = array(
			'api_key'          => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
			'from_email'       => sanitize_email( (string) ( $input['from_email'] ?? '' ) ),
			'from_name'        => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
			'override_wp_mail' => ! empty( $input['override_wp_mail'] ),
			'delete_with_user' => ! empty( $input['delete_with_user'] ),
			'key_domain'       => '',
			'key_permission'   => '',
		) + $look + $signup + $popup;
		if ( '' === $out['api_key'] ) {
			return $out;
		}

		$key = $this->check_key( $out['api_key'] );
		if ( is_string( $key ) ) {
			$this->refuse( $key );
			return $previous;
		}
		$out['key_domain']     = $key['domain'];
		$out['key_permission'] = $key['permission'];
		return $out;
	}

	/**
	 * What meil.ro says about a key: its domain and permission, or why it
	 * cannot be used. Asked once per request: WordPress may run the sanitize
	 * callback twice on the first save.
	 *
	 * @return array{domain:string,permission:string}|string
	 */
	private function check_key( string $api_key ) {
		static $seen = array();
		if ( isset( $seen[ $api_key ] ) ) {
			return $seen[ $api_key ];
		}

		$result = ( new Meil_Client( $api_key ) )->get_key();
		if ( is_wp_error( $result ) ) {
			$status             = Meil_Client::status( $result );
			$seen[ $api_key ] = 401 === $status
				? __( 'This API key is not valid. Copy it again from API Keys in meil.ro.', 'meilro' )
				/* translators: %s: error message from the meil API */
				: sprintf( __( 'Could not check the API key with meil.ro: %s', 'meilro' ), $result->get_error_message() );
			return $seen[ $api_key ];
		}

		$seen[ $api_key ] = array(
			'domain'     => strtolower( (string) ( $result['domain'] ?? '' ) ),
			'permission' => (string) ( $result['permission'] ?? '' ),
		);
		return $seen[ $api_key ];
	}

	/** Shows an error on the settings screen, once. */
	private function refuse( string $message ): void {
		static $shown = array();
		if ( ! isset( $shown[ $message ] ) ) {
			$shown[ $message ] = true;
			add_settings_error( $this->option_name, 'meil_settings', $message );
		}
	}

	/** The permission names as the meil.ro dashboard shows them. */
	private static function permission_label( string $permission ): string {
		$labels = array(
			'full_access'      => __( 'Full access', 'meilro' ),
			'broadcast_access' => __( 'Emails and broadcasts', 'meilro' ),
			'email_access'     => __( 'Transactional only', 'meilro' ),
		);
		return $labels[ $permission ] ?? $permission;
	}

	public function render_field( array $args ): void {
		$field    = $args['field'];
		$settings = meil_mailer_settings();
		$value    = $settings[ $field ] ?? '';
		$name     = $this->option_name . '[' . $field . ']';
		$id       = 'meil-mailer-' . $field;

		switch ( $field ) {
			case 'api_key':
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off" placeholder="meil_..." />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				if ( '' !== $settings['key_permission'] ) {
					echo '<p class="description">' . esc_html(
						'' !== $settings['key_domain']
							? sprintf(
								/* translators: 1: the API key's domain, 2: its permission, e.g. "Full access" */
								__( 'Works with %1$s, %2$s.', 'meilro' ),
								$settings['key_domain'],
								self::permission_label( $settings['key_permission'] )
							)
							: sprintf(
								/* translators: %s: the API key's permission, e.g. "Full access" */
								__( 'Works with all your domains, %s.', 'meilro' ),
								self::permission_label( $settings['key_permission'] )
							)
					) . '</p>';
				}
				break;
			case 'from_email':
				printf(
					'<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				echo '<p class="description">' . esc_html__( 'Must be on a domain verified in meil.ro under Domains.', 'meilro' ) . '</p>';
				break;
			case 'from_name':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
			case 'signup_places':
				$places = array(
					'checkout' => class_exists( 'WooCommerce' )
						? __( 'At WooCommerce checkout', 'meilro' )
						: __( 'At WooCommerce checkout (WooCommerce is not active)', 'meilro' ),
					'register' => __( 'On the registration forms (WordPress and WooCommerce My Account)', 'meilro' ),
				);
				echo '<ul class="meil-signup-list">';
				foreach ( $places as $place => $text ) {
					printf(
						'<li><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label></li>',
						esc_attr( $name ),
						esc_attr( $place ),
						checked( in_array( $place, (array) $value, true ), true, false ),
						esc_html( $text )
					);
				}
				echo '</ul>';
				break;
			case 'signup_audiences':
				$this->render_signup_audiences( $name, (array) $value, $settings );
				break;
			case 'signup_label':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="large-text" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( Meil_Signup::default_label() )
				);
				echo '<p class="description">' . esc_html__( 'Leave it empty to use the default, which follows the site language. The exact text is saved with each contact.', 'meilro' ) . '</p>';
				break;
			case 'class_button':
			case 'class_input':
			case 'class_checkbox':
				$examples = array(
					'class_button'   => 'btn btn-primary',
					'class_input'    => 'form-control',
					'class_checkbox' => 'form-check-input',
				);
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text code" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					/* translators: %s: example CSS classes */
					esc_attr( sprintf( __( 'For example %s', 'meilro' ), $examples[ $field ] ) )
				);
				if ( 'class_checkbox' === $field ) {
					echo '<p class="description">' . esc_html__( 'With the form styles on, the box people see on the plugin\'s forms is drawn by the plugin. Turn them off to let your theme style the real checkbox.', 'meilro' ) . '</p>';
				}
				break;
			case 'popup_form':
				$this->render_popup_form_field( $id, $name, (int) $value );
				break;
			case 'popup_kind':
			case 'popup_where':
				$options = 'popup_kind' === $field
					? array(
						'modal' => __( 'A popup in the middle of the screen', 'meilro' ),
						'slide' => __( 'A slide-in, in the bottom corner', 'meilro' ),
						'bar'   => __( 'A bar at the bottom of the screen', 'meilro' ),
					)
					: array(
						'all'   => __( 'Every page', 'meilro' ),
						'posts' => __( 'Blog posts only', 'meilro' ),
						'pages' => __( 'Pages only', 'meilro' ),
					);
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $options as $option => $text ) {
					printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $option ), selected( (string) $value, $option, false ), esc_html( $text ) );
				}
				echo '</select>';
				if ( 'popup_where' === $field ) {
					echo '<p class="description">' . esc_html__( 'Never on the cart, the checkout, the account pages, or a page that already shows this form.', 'meilro' ) . '</p>';
				}
				break;
			case 'popup_trigger':
				$triggers = array(
					'delay'  => __( 'After this many seconds on the page', 'meilro' ),
					'scroll' => __( 'After scrolling this much of the page, in percent', 'meilro' ),
					'exit'   => __( 'When the visitor is about to leave (on phones: after scrolling half the page)', 'meilro' ),
				);
				echo '<fieldset class="meil-choices">';
				foreach ( $triggers as $option => $text ) {
					printf(
						'<label class="meil-choice"><input type="radio" name="%1$s" value="%2$s" %3$s /> %4$s</label>',
						esc_attr( $name ),
						esc_attr( $option ),
						checked( (string) $value, $option, false ),
						esc_html( $text )
					);
				}
				echo '</fieldset>';
				printf(
					'<label class="meil-inline-label">%1$s <input type="number" min="0" max="600" class="small-text" name="%2$s" value="%3$s" /></label>',
					esc_html__( 'Seconds or percent:', 'meilro' ),
					esc_attr( $this->option_name . '[popup_value]' ),
					esc_attr( (string) (int) $settings['popup_value'] )
				);
				break;
			case 'popup_days':
				printf(
					'<label class="meil-inline-label">%1$s <input type="number" id="%2$s" min="1" max="365" class="small-text" name="%3$s" value="%4$s" /> %5$s</label>',
					esc_html__( 'Show it again after', 'meilro' ),
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) (int) $value ),
					esc_html__( 'days', 'meilro' )
				);
				break;
			case 'popup_title':
			case 'popup_text':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="large-text" placeholder="%4$s" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( 'popup_title' === $field ? Meil_Forms::default_text( 'popup_title' ) : __( 'Optional, for example what you send and how often', 'meilro' ) )
				);
				break;
			case 'popup_preview':
				$this->render_popup_preview( $settings );
				break;
			case 'override_wp_mail':
			case 'load_css':
			case 'delete_with_user':
			case 'signup_confirm':
			case 'test_mode':
				$texts = array(
					'override_wp_mail' => __( 'Replace wp_mail() delivery with the meil API (recommended). Leave unchecked to use meil for forms only.', 'meilro' ),
					'load_css'         => __( "Load the plugin's styles for forms and the preferences page. Turn off to style them entirely from your theme.", 'meilro' ),
					'delete_with_user' => __( 'When a WordPress user is deleted, delete their meil.ro contact too, with its data.', 'meilro' ),
					'signup_confirm'   => __( 'Ask them to confirm by email before they are subscribed (recommended)', 'meilro' ),
					'test_mode'        => __( 'Administrators see the popup on every page right away, even after closing it or signing up, and the confirmation page shows examples of what visitors see there. This lets you design both with your theme. Visitors see the site as usual.', 'meilro' ),
				);
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( $texts[ $field ] )
				);
				break;
		}
	}

	/**
	 * The audiences the sign-up checkbox can add to, as checkboxes. Needs a
	 * "Full access" key, like the forms.
	 *
	 * @param list<string>        $chosen
	 * @param array<string,mixed> $settings
	 */
	private function render_signup_audiences( string $name, array $chosen, array $settings ): void {
		if ( ! $this->client->is_configured() ) {
			echo '<p class="description">' . esc_html__( 'Save an API key first.', 'meilro' ) . '</p>';
			return;
		}
		$audiences = $this->client->list_audiences();
		if ( is_wp_error( $audiences ) ) {
			echo '<p class="description">' . esc_html(
				403 === Meil_Client::status( $audiences )
					? __( 'The sign-up checkbox needs a "Full access" API key.', 'meilro' )
					/* translators: %s: error message from the meil API */
					: sprintf( __( 'Could not reach meil: %s', 'meilro' ), $audiences->get_error_message() )
			) . '</p>';
			// Keep what was chosen, so saving now does not empty it.
			foreach ( $chosen as $id ) {
				printf( '<input type="hidden" name="%1$s[]" value="%2$s" />', esc_attr( $name ), esc_attr( $id ) );
			}
			return;
		}
		$list = array_values( array_filter( (array) ( $audiences['data'] ?? array() ), 'is_array' ) );
		if ( array() === $list ) {
			echo '<p class="description">' . esc_html__( 'There are no audiences yet.', 'meilro' ) . ' <a href="' . esc_url( MEIL_MAILER_APP_URL . '/audiences' ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create one in meil.ro', 'meilro' ) . '</a></p>';
			return;
		}
		echo '<ul class="meil-signup-list">';
		foreach ( $list as $audience ) {
			$id = (string) ( $audience['id'] ?? '' );
			printf(
				'<li><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s /> %4$s</label></li>',
				esc_attr( $name ),
				esc_attr( $id ),
				checked( in_array( $id, $chosen, true ), true, false ),
				esc_html( (string) ( $audience['name'] ?? $id ) )
			);
		}
		echo '</ul>';
	}

	/** The published forms, by id, for the popup's form picker and preview. @return array<int,string> */
	private static function form_choices(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => Meil_Forms::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'title', 'order' => 'ASC' ) ) as $post ) {
			/* translators: %d: form id */
			$out[ (int) $post->ID ] = '' !== $post->post_title ? $post->post_title : sprintf( __( 'Form %d', 'meilro' ), $post->ID );
		}
		return $out;
	}

	private function render_popup_form_field( string $id, string $name, int $value ): void {
		$forms = self::form_choices();
		if ( array() === $forms ) {
			echo '<p class="description">' . esc_html__( 'There are no forms yet. Build one under meil.ro > Forms, then pick it here.', 'meilro' ) . '</p>';
			printf( '<input type="hidden" name="%s" value="0" />', esc_attr( $name ) );
			return;
		}
		printf( '<select id="%1$s" name="%2$s">', esc_attr( $id ), esc_attr( $name ) );
		printf( '<option value="0" %1$s>%2$s</option>', selected( $value, 0, false ), esc_html__( 'No popup', 'meilro' ) );
		foreach ( $forms as $form_id => $title ) {
			printf( '<option value="%1$d" %2$s>%3$s</option>', (int) $form_id, selected( $value, $form_id, false ), esc_html( $title ) );
		}
		echo '</select>';
	}

	/**
	 * The popup as visitors will see it, in a frame of its own so the admin's
	 * styles stay out: the plugin's form styles (when on) and popup styles,
	 * over a stand-in page. It follows the fields as they change; Computer and
	 * Phone show it at both widths. The site adds the theme's look.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_popup_preview( array $settings ): void {
		$forms = array();
		foreach ( self::form_choices() as $form_id => $title ) {
			$html = $this->forms->render_shortcode( array( 'id' => $form_id ) );
			$forms[ $form_id ] = false !== strpos( $html, 'meil-form__form' )
				? $html
				: '<p>' . esc_html__( 'This form cannot be shown yet: pick its audiences under meil.ro > Forms.', 'meilro' ) . '</p>';
		}
		// render_shortcode() asks for the front-end files; this page does not need them.
		wp_dequeue_script( 'meil-forms' );
		wp_dequeue_style( 'meil-forms' );

		wp_enqueue_script( 'meil-admin-popup', MEIL_MAILER_URL . 'assets/admin-popup.js', array(), meil_asset_version( 'assets/admin-popup.js' ), true );
		wp_add_inline_script(
			'meil-admin-popup',
			'window.meilPopupPreview = ' . wp_json_encode(
				array(
					'forms'        => $forms,
					'formsCss'     => ! empty( $settings['load_css'] ) ? MEIL_MAILER_URL . 'assets/forms.css?ver=' . meil_asset_version( 'assets/forms.css' ) : '',
					'popupCss'     => MEIL_MAILER_URL . 'assets/popup.css?ver=' . meil_asset_version( 'assets/popup.css' ),
					'defaultTitle' => Meil_Forms::default_text( 'popup_title' ),
					'noForm'       => __( 'Pick a form above to see the popup.', 'meilro' ),
				)
			) . ';',
			'before'
		);
		?>
		<div class="meil-popup-preview">
			<div class="meil-tabs meil-tabs--small" role="group" aria-label="<?php esc_attr_e( 'Preview', 'meilro' ); ?>">
				<button type="button" class="meil-tab meil-tab--active" data-meil-preview-width="desktop" aria-pressed="true"><?php esc_html_e( 'Computer', 'meilro' ); ?></button>
				<button type="button" class="meil-tab" data-meil-preview-width="phone" aria-pressed="false"><?php esc_html_e( 'Phone', 'meilro' ); ?></button>
			</div>
			<div class="meil-popup-preview__stage">
				<iframe class="meil-popup-preview__frame" title="<?php esc_attr_e( 'Popup preview', 'meilro' ); ?>" data-meil-popup-preview></iframe>
			</div>
		</div>
		<p class="description"><?php esc_html_e( 'As it opens on your site, where it also takes your theme\'s look. The form cannot be sent from here.', 'meilro' ); ?></p>
		<?php
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = meil_mailer_settings();
		$tabs     = array(
			'general' => __( 'General', 'meilro' ),
			'signup'  => __( 'Sign-up checkbox', 'meilro' ),
			'popup'   => __( 'Popup', 'meilro' ),
			'look'    => __( 'Forms look', 'meilro' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which tab to show.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'general';
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'general';
		$page = array(
			'general' => self::PAGE,
			'signup'  => self::SIGNUP_TAB,
			'popup'   => self::POPUP_TAB,
			'look'    => self::LOOK_TAB,
		);
		?>
		<div class="wrap meil-app">
			<?php meil_admin_head( __( 'Settings', 'meilro' ), __( 'How this site connects to meil.ro, and how its forms look.', 'meilro' ) ); ?>
			<?php settings_errors( $this->option_name ); ?>

			<nav class="meil-tabs" aria-label="<?php esc_attr_e( 'Settings', 'meilro' ); ?>">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'tab' => 'general' === $key ? false : $key ), admin_url( 'admin.php' ) ) ); ?>"
						class="meil-tab<?php echo $tab === $key ? ' meil-tab--active' : ''; ?>"<?php echo $tab === $key ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<form action="options.php" method="post" class="meil-stack">
				<?php
				settings_fields( self::GROUP );
				printf( '<input type="hidden" name="%s[_tab]" value="%s" />', esc_attr( $this->option_name ), esc_attr( $tab ) );
				self::render_panels( $page[ $tab ] );
				?>
				<p class="meil-actions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'meilro' ); ?></button></p>
			</form>
			<?php
			if ( 'general' !== $tab ) {
				echo '</div>';
				return;
			}
			?>

			<div class="meil-panel">
				<div class="meil-panel__head"><h2 class="meil-panel__title"><?php esc_html_e( 'Send a test email', 'meilro' ); ?></h2></div>
				<div class="meil-panel__body">
					<?php if ( empty( $settings['override_wp_mail'] ) || ! $this->client->is_configured() ) : ?>
						<p class="meil-panel__lead"><?php esc_html_e( 'Add an API key and turn on "Send WordPress mail through meil" to send a test.', 'meilro' ); ?></p>
					<?php else : ?>
						<p class="meil-panel__lead"><?php esc_html_e( 'Sends through wp_mail(), the same way your site sends password resets and order emails.', 'meilro' ); ?></p>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="meil-inline-form">
							<input type="hidden" name="action" value="meil_mailer_send_test" />
							<?php wp_nonce_field( self::TEST_NONCE ); ?>
							<div class="meil-field meil-field--grow">
								<label class="meil-field__label" for="meil-mailer-test-to"><?php esc_html_e( 'Send to', 'meilro' ); ?></label>
								<input type="email" id="meil-mailer-test-to" name="test_to" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required />
							</div>
							<button type="submit" class="button"><?php esc_html_e( 'Send Test Email', 'meilro' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( $this->client->is_configured() ) : ?>
				<?php $this->render_preferences_help( $settings ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Draws a settings page's sections as panels, the way meil.ro's own screens
	 * look: the section's title in the panel's head, then its fields one below
	 * the other, each label above its control. In place of
	 * do_settings_sections() and its tables.
	 */
	private static function render_panels( string $page ): void {
		global $wp_settings_sections, $wp_settings_fields;
		// Fields whose control is a list, not one element a label can point to.
		$groups = array( 'signup_places', 'signup_audiences', 'popup_trigger', 'popup_preview' );
		foreach ( (array) ( $wp_settings_sections[ $page ] ?? array() ) as $section ) {
			echo '<div class="meil-panel">';
			if ( '' !== (string) $section['title'] ) {
				echo '<div class="meil-panel__head"><h2 class="meil-panel__title">' . esc_html( $section['title'] ) . '</h2></div>';
			}
			echo '<div class="meil-panel__body">';
			if ( is_callable( $section['callback'] ) ) {
				echo '<div class="meil-panel__lead">';
				call_user_func( $section['callback'], $section );
				echo '</div>';
			}
			foreach ( (array) ( $wp_settings_fields[ $page ][ $section['id'] ] ?? array() ) as $field ) {
				$name = (string) ( $field['args']['field'] ?? '' );
				echo '<div class="meil-field">';
				if ( in_array( $name, $groups, true ) ) {
					echo '<span class="meil-field__label">' . esc_html( $field['title'] ) . '</span>';
				} else {
					echo '<label class="meil-field__label" for="' . esc_attr( 'meil-mailer-' . $name ) . '">' . esc_html( $field['title'] ) . '</label>';
				}
				echo '<div class="meil-field__control">';
				call_user_func( $field['callback'], $field['args'] );
				echo '</div></div>';
			}
			echo '</div></div>';
		}
	}

	/**
	 * How to place the preferences page. The forms have their own menu item.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_preferences_help( array $settings ): void {
		?>
		<div class="meil-panel">
			<div class="meil-panel__head"><h2 class="meil-panel__title"><?php esc_html_e( 'Email preferences', 'meilro' ); ?></h2></div>
			<div class="meil-panel__body">
				<?php if ( ! meil_key_can( 'manage' ) ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'The preferences page needs a "Full access" API key.', 'meilro' ); ?></p></div>
				<?php endif; ?>
				<p class="meil-panel__lead">
					<?php
					if ( '' !== $settings['key_domain'] ) {
						printf(
							/* translators: 1: the shortcode, 2: the API key's domain */
							esc_html__( 'Put the "meil.ro email preferences" block or %1$s on an account page: logged-in users see the public audiences of %2$s and choose which ones they get. Visitors who are not logged in see a message instead.', 'meilro' ),
							'<code>[meil_preferences]</code>',
							esc_html( $settings['key_domain'] )
						);
					} else {
						printf(
							/* translators: %s: the shortcode */
							esc_html__( 'Put the "meil.ro email preferences" block or %s on an account page: logged-in users see the public audiences of all your domains and choose which ones they get. Visitors who are not logged in see a message instead.', 'meilro' ),
							'<code>[meil_preferences]</code>'
						);
					}
					?>
				</p>
			</div>
		</div>
		<?php
	}

	public function handle_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'meilro' ) );
		}
		check_admin_referer( self::TEST_NONCE );

		$to       = sanitize_email( wp_unslash( (string) ( $_POST['test_to'] ?? '' ) ) );
		$redirect = admin_url( 'admin.php?page=' . self::PAGE );

		if ( '' === $to || ! is_email( $to ) ) {
			wp_safe_redirect( add_query_arg( 'meil_test', 'invalid_address', $redirect ) );
			exit;
		}

		// Goes through wp_mail() so it tests the same path real site mail takes.
		$error   = null;
		$capture = static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e;
		};
		$queued = false;
		$wait   = static function () use ( &$queued ): void {
			$queued = true;
		};
		add_action( 'wp_mail_failed', $capture );
		add_action( 'meil_queued', $wait );
		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( 'Test email from %s', 'meilro' ),
				get_bloginfo( 'name' )
			),
			__( 'This is a test email sent by the meil.ro plugin. If you received this, WordPress mail is going through meil.', 'meilro' )
		);
		remove_action( 'wp_mail_failed', $capture );
		remove_action( 'meil_queued', $wait );

		if ( $sent ) {
			wp_safe_redirect( add_query_arg( 'meil_test', $queued ? 'queued' : 'sent', $redirect ) );
		} else {
			$message = $error instanceof WP_Error ? $error->get_error_message() : __( 'wp_mail() returned false.', 'meilro' );
			wp_safe_redirect( add_query_arg( 'meil_test_error', rawurlencode( $message ), $redirect ) );
		}
		exit;
	}

	/**
	 * Renders a one-time notice from the query args handle_test_email()
	 * redirects back with. Read-only display of our own flags, not a form
	 * submission, so there is nothing here for a nonce to protect.
	 */
	public function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['meil_test'] ) && 'sent' === $_GET['meil_test'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent.', 'meilro' ) . '</p></div>';
		} elseif ( isset( $_GET['meil_test'] ) && 'queued' === $_GET['meil_test'] ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'meil.ro could not be reached, so the test email is waiting under meil.ro > Unsent and will be tried again on its own.', 'meilro' ) . '</p></div>';
		} elseif ( isset( $_GET['meil_test'] ) && 'invalid_address' === $_GET['meil_test'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Enter a valid email address.', 'meilro' ) . '</p></div>';
		} elseif ( isset( $_GET['meil_test_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( (string) $_GET['meil_test_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: error message from the meil API */
					__( 'Test email failed: %s', 'meilro' ),
					$message
				)
			) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
