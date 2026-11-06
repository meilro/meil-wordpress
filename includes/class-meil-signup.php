<?php
/**
 * A "send me news by email" checkbox at WooCommerce checkout and on the
 * registration forms (WordPress's own and WooCommerce's My Account), set up on
 * the meil.ro settings screen: where it shows, its text, the audiences it
 * adds to, and whether people confirm by email first.
 *
 * The checkbox starts unticked: ticking it is the consent. Saved with the
 * contact are where it came from and the exact text the person saw; without
 * the confirmation email, also when and from which IP address.
 *
 * Checkout works with the classic checkout (shortcode) and with the Checkout
 * block, where the checkbox is a WooCommerce additional field (WooCommerce
 * 8.9 and later). The sign-up never holds up an order or a registration:
 * when meil.ro cannot be reached it waits and is tried again (Meil_Queue).
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Signup {

	/** The posted checkbox, on the classic checkout and the registration forms. */
	private const FIELD = 'meil_signup';

	/** The nonce shown with the checkbox on the classic checkout and the registration forms. */
	private const NONCE       = 'meil_signup';
	private const NONCE_FIELD = 'meil_signup_nonce';

	/** The Checkout block's field. WooCommerce saves its value as _wc_other/meil/newsletter. */
	private const BLOCK_FIELD = 'meil/newsletter';

	private const ORDER_META = '_meil_signup';

	/** @var Meil_Queue */
	private $queue;

	/** @var array<string,mixed> */
	private $settings;

	/** Addresses signed up in this request: a checkout that also creates an account asks once. @var array<string,bool> */
	private static $done = array();

	public function __construct( Meil_Client $client, array $settings, Meil_Queue $queue ) {
		$this->queue    = $queue;
		$this->settings = $settings;

		$places = (array) ( $settings['signup_places'] ?? array() );
		if ( ! $client->is_configured() || ! meil_key_can( 'manage' ) || array() === (array) ( $settings['signup_audiences'] ?? array() ) || array() === $places ) {
			return;
		}

		if ( in_array( 'checkout', $places, true ) ) {
			add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_checkout' ) );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'classic_order' ), 10, 3 );
			add_action( 'woocommerce_init', array( $this, 'register_block_field' ) );
			add_action( 'woocommerce_set_additional_field_value', array( $this, 'block_value' ), 10, 4 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'block_order' ) );
		}
		if ( in_array( 'register', $places, true ) ) {
			add_action( 'register_form', array( $this, 'render_register' ) );
			add_action( 'woocommerce_register_form', array( $this, 'render_woo_register' ) );
			add_action( 'user_register', array( $this, 'registered' ) );
		}
	}

	/**
	 * The text next to the checkbox: the one from the settings, or the default
	 * in the site's language. Read when it is shown, not when the plugin
	 * loads, so WPML and Polylang can hand back the translation for the
	 * visitor's language (wpml-config.xml registers it with both).
	 */
	public static function label(): string {
		$label = trim( (string) ( meil_mailer_settings()['signup_label'] ?? '' ) );
		return '' !== $label ? $label : self::default_label();
	}

	public static function default_label(): string {
		return __( 'Send me news and offers by email', 'meilro' );
	}

	/* ------------------------------------------------------------- checkout */

	public function render_checkout(): void {
		if ( ! function_exists( 'woocommerce_form_field' ) ) {
			return;
		}
		woocommerce_form_field(
			self::FIELD,
			array(
				'type'     => 'checkbox',
				'class'       => array( 'meil-signup' ),
				'input_class' => array_filter( explode( ' ', trim( meil_extra_classes( 'checkbox' ) ) ) ),
				'label'       => esc_html( self::label() ),
				'required'    => false,
			),
			0
		);
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
	}

	/** Was the checkbox ticked, on a form that carried its nonce? */
	private static function ticked(): bool {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) ), self::NONCE ) ) {
			return false;
		}
		return ! empty( $_POST[ self::FIELD ] );
	}

	/**
	 * Classic checkout, once WooCommerce has created the order.
	 *
	 * @param int      $order_id
	 * @param array    $posted_data
	 * @param WC_Order $order
	 */
	public function classic_order( $order_id, $posted_data, $order ): void {
		if ( ! $order instanceof WC_Order || ! self::ticked() ) {
			return;
		}
		$order->update_meta_data( self::ORDER_META, 'yes' );
		$order->save_meta_data();
		$this->subscribe( $order->get_billing_email(), $order->get_billing_first_name(), $order->get_billing_last_name(), 'woocommerce_checkout' );
	}

	/** Checkout block: the same checkbox as an additional field, under the order notes. */
	public function register_block_field(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, '8.9', '<' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field(
			array(
				'id'       => self::BLOCK_FIELD,
				'label'    => self::label(),
				'location' => 'order',
				'type'     => 'checkbox',
				'required' => false,
			)
		);
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param string $group
	 * @param mixed  $wc_object
	 */
	public function block_value( $key, $value, $group, $wc_object ): void {
		if ( self::BLOCK_FIELD === $key && ! empty( $value ) && $wc_object instanceof WC_Order ) {
			$wc_object->update_meta_data( self::ORDER_META, 'yes' );
		}
	}

	/** @param WC_Order $order */
	public function block_order( $order ): void {
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'yes' !== $order->get_meta( self::ORDER_META ) && '1' !== (string) $order->get_meta( '_wc_other/' . self::BLOCK_FIELD ) ) {
			return;
		}
		$this->subscribe( $order->get_billing_email(), $order->get_billing_first_name(), $order->get_billing_last_name(), 'woocommerce_checkout' );
	}

	/* --------------------------------------------------------- registration */

	/** wp-login.php?action=register */
	public function render_register(): void {
		printf(
			'<p class="meil-signup"><label><input type="checkbox" name="%1$s" value="1" class="%3$s" /> %2$s</label></p>',
			esc_attr( self::FIELD ),
			esc_html( self::label() ),
			esc_attr( trim( meil_extra_classes( 'checkbox' ) ) )
		);
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
	}

	/** WooCommerce My Account registration. */
	public function render_woo_register(): void {
		printf(
			'<p class="form-row meil-signup"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox"><input class="woocommerce-form__input woocommerce-form__input-checkbox%3$s" type="checkbox" name="%1$s" value="1" /> <span>%2$s</span></label></p>',
			esc_attr( self::FIELD ),
			esc_html( self::label() ),
			esc_attr( meil_extra_classes( 'checkbox' ) )
		);
		wp_nonce_field( self::NONCE, self::NONCE_FIELD, false );
	}

	/**
	 * A user was created. Only from a form that showed the checkbox: users
	 * added in the admin or by other plugins never carry it. An account made
	 * while checking out is left to the order, which knows the full name.
	 *
	 * @param int $user_id
	 */
	public function registered( $user_id ): void {
		if ( defined( 'WOOCOMMERCE_CHECKOUT' ) || ! self::ticked() ) {
			return;
		}
		$user = get_userdata( (int) $user_id );
		if ( $user ) {
			$this->subscribe( (string) $user->user_email, (string) $user->first_name, (string) $user->last_name, 'wordpress_register' );
		}
	}

	/* ---------------------------------------------------------------- shared */

	private function subscribe( string $email, string $first_name, string $last_name, string $source ): void {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) || isset( self::$done[ $email ] ) ) {
			return;
		}
		self::$done[ $email ] = true;

		$confirm    = ! empty( $this->settings['signup_confirm'] );
		$properties = array(
			'source'      => $source,
			'signup_text' => self::label(),
		);
		// With confirmation, meil.ro records when and from where they confirmed.
		if ( ! $confirm ) {
			$properties = array(
				'consent_timestamp' => gmdate( 'c' ),
				'consent_ip'        => meil_visitor_ip(),
			) + $properties;
		}
		$payload = array(
			'audience_ids' => array_values( array_map( 'strval', (array) $this->settings['signup_audiences'] ) ),
			'email'        => $email,
			'properties'   => $properties,
		);
		if ( '' !== trim( $first_name ) ) {
			$payload['first_name'] = trim( $first_name );
		}
		if ( '' !== trim( $last_name ) ) {
			$payload['last_name'] = trim( $last_name );
		}
		if ( $confirm ) {
			$payload['confirm']  = true;
			$payload['language'] = 0 === strpos( determine_locale(), 'ro' ) ? 'ro' : 'en';
		}
		// Refused for good: it is on the Unsent list, and the order or the
		// registration goes on as usual.
		$this->queue->deliver( 'contact', $payload, $email, '' );
	}
}
