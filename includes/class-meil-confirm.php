<?php
/**
 * Confirming a sign-up on this site instead of on meil.ro.
 *
 * A form whose "Where visitors confirm" is a page of this site asks meil.ro to
 * point the confirmation email's button at that page, with the form's id in
 * the address (?meil_form=12), to which meil.ro adds the token (&token=...).
 * The page's own button and retries carry it as meil_token. The page then shows
 * who is confirming which lists, with a button. Only pressing it confirms:
 * mail filters open every link in an email to scan it, so a page that
 * confirmed as it opened would subscribe everyone.
 *
 * The box is [meil_confirm]. A page chosen as the confirmation page without
 * the shortcode still gets the box, above its content, whenever someone
 * arrives with a token, so a forgotten shortcode never loses a sign-up.
 *
 * Administrators who open the page without a token see a note saying what
 * the page is for, and with test mode on, examples of what visitors see, so
 * the box can be designed with the theme.
 *
 * The press posts back to the page, is handled before the page is drawn
 * (template_redirect) and ends in a redirect: to the form's "after confirming"
 * page when it has one, else back here with the result, so a refresh never
 * sends it twice. These pages are personal, so they are never cached.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Confirm {

	private const ACTION = 'meil_confirm';
	private const RESULT = 'meil_confirm_result';

	/** Presses one visitor IP may make in TRY_WINDOW seconds, so nobody can guess tokens through this page. */
	private const TRY_LIMIT  = 20;
	private const TRY_WINDOW = 600;

	/** The results a page can be sent back with. */
	private const RESULTS = array( 'confirmed', 'already', 'expired', 'invalid', 'error' );

	/** @var Meil_Client */
	private $client;

	/** @var bool Has this page drawn the box? Then no popup goes on top of it. */
	private static $drawn = false;

	public function __construct( Meil_Client $client ) {
		$this->client = $client;

		add_shortcode( 'meil_confirm', array( $this, 'render_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'template_redirect', array( $this, 'no_cache' ) );
		add_filter( 'the_content', array( $this, 'add_to_page' ) );
	}

	/**
	 * The token in the request: from this page's button or a retry
	 * (meil_token), or from the confirmation email, where meil.ro adds it as
	 * "token" next to the form's id. A bare "token" without meil_form belongs
	 * to something else on the site. meil.ro's tokens are letters, digits, "-",
	 * "_" and ".", so anything else is taken out rather than passed on.
	 */
	private static function token(): string {
		// The token is the secret here (see the class comment), and it is reduced to safe characters below.
		// One disable and one enable for both: separate ones keep PHPCS from honouring the ignores further down.
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw = '';
		if ( isset( $_POST['meil_token'] ) ) {
			$raw = wp_unslash( (string) $_POST['meil_token'] );
		} elseif ( isset( $_GET['meil_token'] ) ) {
			$raw = wp_unslash( (string) $_GET['meil_token'] );
		} elseif ( isset( $_GET['token'], $_GET['meil_form'] ) ) {
			$raw = wp_unslash( (string) $_GET['token'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return substr( (string) preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $raw ), 0, 4096 );
	}

	/** The form the sign-up came from, for its "after confirming" page. */
	private static function form_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification -- only picks which page to go to next.
		return isset( $_POST['meil_form'] ) ? absint( $_POST['meil_form'] ) : ( isset( $_GET['meil_form'] ) ? absint( $_GET['meil_form'] ) : 0 );
	}

	/** The result the page was sent back with, or ''. */
	private static function result(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which message to show.
		$result = isset( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::RESULT ] ) ) : '';
		return in_array( $result, self::RESULTS, true ) ? $result : '';
	}

	/** Has the box been drawn on this page, for anyone? Asked by the popup, in the footer. */
	public static function drawn(): bool {
		return self::$drawn;
	}

	/** Is this request someone confirming, or coming back from it? */
	public static function is_confirming(): bool {
		return '' !== self::token() || '' !== self::result();
	}

	/** One person's sign-up: never cached, never indexed. */
	public function no_cache(): void {
		if ( ! self::is_confirming() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			// WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed and others read it.
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page caches' own constant.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		if ( function_exists( 'wp_robots_no_robots' ) ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
	}

	/** The texts, in the site's language; the 'meil_confirm_text' filter can change any of them. */
	private static function text( string $key ): string {
		switch ( $key ) {
			case 'confirmed':
				$text = __( 'Thank you, your subscription is confirmed.', 'meilro' );
				break;
			case 'already':
				$text = __( 'Your subscription was already confirmed. Thank you!', 'meilro' );
				break;
			case 'expired':
				$text = __( 'This confirmation link has expired. Please sign up again to get a new one.', 'meilro' );
				break;
			case 'invalid':
				$text = __( 'This confirmation link is not valid. Please use the button in the email we sent you, or sign up again.', 'meilro' );
				break;
			case 'error':
				$text = __( 'Sorry, we could not confirm your subscription right now. Please try again in a few minutes.', 'meilro' );
				break;
			case 'ask':
				/* translators: %s: the visitor's email address */
				$text = __( 'Press the button to confirm that you want to receive our emails at %s.', 'meilro' );
				break;
			case 'ask_plain':
				$text = __( 'Press the button to confirm your subscription.', 'meilro' );
				break;
			case 'lists':
				$text = __( 'You are subscribing to:', 'meilro' );
				break;
			case 'button':
				$text = __( 'Confirm my subscription', 'meilro' );
				break;
			default:
				$text = '';
		}
		return (string) apply_filters( 'meil_confirm_text', $text, $key );
	}

	/**
	 * Within the press limit for this visitor's IP? Counts this press. An
	 * address it cannot count (see meil_ip_countable()) is let through.
	 */
	private static function within_limit(): bool {
		$ip = meil_visitor_ip();
		if ( ! meil_ip_countable( $ip ) ) {
			return true;
		}
		$key   = 'meil_confirm_' . md5( $ip );
		$tries = (int) get_transient( $key );
		if ( $tries >= self::TRY_LIMIT ) {
			return false;
		}
		set_transient( $key, $tries + 1, self::TRY_WINDOW );
		return true;
	}

	/**
	 * The address of this page without the confirmation's own query values:
	 * where the press posts to and comes back to.
	 */
	private static function here(): string {
		$url = is_singular() ? get_permalink() : false;
		if ( ! is_string( $url ) || '' === $url ) {
			$url = isset( $_SERVER['REQUEST_URI'] ) ? home_url( esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) ) : home_url( '/' );
		}
		return remove_query_arg( array( 'meil_token', 'token', 'meil_form', self::RESULT ), $url );
	}

	/** The button was pressed: confirm with meil.ro, then redirect. */
	public function handle_post(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the token is the secret here, see the class comment.
		if ( wp_doing_ajax() || ! isset( $_POST['meil_action'] ) || self::ACTION !== $_POST['meil_action'] ) {
			return;
		}
		$token   = self::token();
		$form_id = self::form_id();
		$back    = static function ( string $result, string $keep_token = '' ) use ( $form_id ): void {
			$args = array( self::RESULT => $result );
			if ( '' !== $keep_token ) {
				// A failure the visitor can retry keeps the token, so the button shows again.
				$args['meil_token'] = $keep_token;
				if ( $form_id > 0 ) {
					$args['meil_form'] = $form_id;
				}
			}
			wp_safe_redirect( add_query_arg( $args, self::here() ) );
			exit;
		};

		if ( '' === $token ) {
			$back( 'invalid' );
		}
		if ( ! self::within_limit() ) {
			$back( 'error', $token );
		}

		$answer = $this->client->confirm_contact( $token, meil_visitor_ip() );
		if ( is_wp_error( $answer ) ) {
			if ( 404 === Meil_Client::status( $answer ) ) {
				$back( 'invalid' );
			}
			$back( 'error', $token );
		}

		$status = (string) ( $answer['status'] ?? '' );
		if ( 'expired' === $status ) {
			$back( 'expired' );
		}
		if ( 'confirmed' !== $status ) {
			$back( 'error', $token );
		}

		// Confirmed: on to the form's own "after confirming" page, if it has one.
		$thanks = $form_id > 0 ? Meil_Forms::in_language( (int) Meil_Forms::settings( $form_id )['thanks_page'] ) : 0;
		$url    = $thanks > 0 && 'publish' === get_post_status( $thanks ) ? get_permalink( $thanks ) : false;
		if ( is_string( $url ) && '' !== $url ) {
			wp_safe_redirect( $url );
			exit;
		}
		$back( ! empty( $answer['already_confirmed'] ) ? 'already' : 'confirmed' );
	}

	/**
	 * A page with a token and no [meil_confirm] still shows the box, above
	 * its own content: the main content of a single page only, once. So does
	 * a form's confirmation page for administrators in test mode, with the examples.
	 */
	public function add_to_page( string $content ): string {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() || has_shortcode( $content, 'meil_confirm' ) ) {
			return $content;
		}
		if ( ! self::is_confirming() && ! ( meil_test_mode() && self::page_form() > 0 ) ) {
			return $content;
		}
		return $this->render() . $content;
	}

	/**
	 * The published form whose confirmation page this is (or its translation),
	 * or 0. Asked only for administrators, so a loop over the forms is fine.
	 */
	private static function page_form(): int {
		static $found = array();
		$page_id = (int) get_queried_object_id();
		if ( $page_id <= 0 ) {
			return 0;
		}
		if ( ! isset( $found[ $page_id ] ) ) {
			$found[ $page_id ] = 0;
			$forms             = get_posts(
				array(
					'post_type'   => Meil_Forms::POST_TYPE,
					'post_status' => 'publish',
					'numberposts' => 100,
					'fields'      => 'ids',
				)
			);
			foreach ( $forms as $form_id ) {
				$confirm_page = (int) Meil_Forms::settings( (int) $form_id )['confirm_page'];
				if ( $confirm_page > 0 && ( $confirm_page === $page_id || Meil_Forms::in_language( $confirm_page ) === $page_id ) ) {
					$found[ $page_id ] = (int) $form_id;
					break;
				}
			}
		}
		return $found[ $page_id ];
	}

	/**
	 * Test mode: the box as visitors see it, with the button and the
	 * administrator's own address, then the message after confirming and the
	 * one for an expired link. The button does nothing here.
	 *
	 * @param array<string,mixed> $vars the box's values, without a token
	 */
	private function examples( array $vars ): string {
		$form_id = self::page_form();
		$lists   = array();
		if ( $form_id > 0 ) {
			foreach ( (array) Meil_Forms::settings( $form_id )['audiences'] as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) ) {
					$lists[] = Meil_Forms::list_label( $form_id, $row );
				}
			}
		}
		$email = (string) wp_get_current_user()->user_email;

		$ask = array_merge(
			$vars,
			array(
				'admin_note'  => __( 'Test mode: below is what visitors see here. First the button they press to confirm, then the message once they have confirmed, then the one for an expired link. Only you see these examples.', 'meilro' ),
				'show_button' => true,
				'example'     => true,
				'form_id'     => $form_id,
				'intro'       => '' !== $email ? sprintf( self::text( 'ask' ), $email ) : self::text( 'ask_plain' ),
				'lists'       => array() !== $lists ? $lists : array( __( 'Newsletter', 'meilro' ) ),
			)
		);
		$html = meil_template( 'confirm', $ask );
		foreach ( array( 'confirmed', 'expired' ) as $result ) {
			$html .= meil_template(
				'confirm',
				array_merge(
					$vars,
					array(
						'message' => array(
							'type' => 'confirmed' === $result ? 'success' : 'error',
							'text' => self::text( $result ),
						),
					)
				)
			);
		}
		return $html;
	}

	/** @param array<string,mixed>|string $atts */
	public function render_shortcode( $atts ): string {
		return $this->render();
	}

	/** The box: a message, the button, or both. Test mode's examples count as drawn too. */
	private function render(): string {
		$html        = $this->box();
		self::$drawn = self::$drawn || '' !== $html;
		return $html;
	}

	private function box(): string {
		$settings = meil_mailer_settings();
		if ( ! empty( $settings['load_css'] ) ) {
			wp_enqueue_style( 'meil-forms', MEIL_MAILER_URL . 'assets/forms.css', array(), meil_asset_version( 'assets/forms.css' ) );
		}

		$token  = self::token();
		$result = self::result();
		$vars   = array(
			'message'     => null,
			'show_button' => false,
			'intro'       => '',
			'lists'       => array(),
			'lists_label' => self::text( 'lists' ),
			'button_text' => self::text( 'button' ),
			'token'       => $token,
			'form_id'     => self::form_id(),
			'action'      => self::ACTION,
			'action_url'  => self::here(),
			'admin_note'  => '',
			'example'     => false,
		);

		if ( '' !== $result ) {
			$vars['message'] = array(
				'type' => in_array( $result, array( 'confirmed', 'already' ), true ) ? 'success' : 'error',
				'text' => self::text( $result ),
			);
			// After a failure that can be retried, the button again.
			if ( 'error' === $result && '' !== $token ) {
				$vars['show_button'] = true;
				$vars['intro']       = self::text( 'ask_plain' );
			}
			return meil_template( 'confirm', $vars );
		}

		if ( '' === $token ) {
			if ( meil_test_mode() ) {
				return $this->examples( $vars );
			}
			if ( current_user_can( 'manage_options' ) ) {
				$vars['admin_note'] = __( 'This is where visitors confirm their subscription: they arrive here from the button in the confirmation email. To see what they see, turn on test mode in meil.ro > Settings > Forms look. Only you see this note.', 'meilro' );
			}
			return '' !== $vars['admin_note'] ? meil_template( 'confirm', $vars ) : '';
		}

		// Look first, change nothing: what the page shows depends on where the sign-up stands.
		$check = $this->client->get_confirmation( $token );
		if ( is_wp_error( $check ) ) {
			if ( 404 === Meil_Client::status( $check ) ) {
				$vars['message'] = array(
					'type' => 'error',
					'text' => self::text( 'invalid' ),
				);
				return meil_template( 'confirm', $vars );
			}
			// meil.ro could not be asked just now: offer the button anyway, the press may well work.
			$vars['show_button'] = true;
			$vars['intro']       = self::text( 'ask_plain' );
			return meil_template( 'confirm', $vars );
		}

		$status = (string) ( $check['status'] ?? '' );
		if ( 'confirmed' === $status || 'expired' === $status ) {
			$vars['message'] = array(
				'type' => 'confirmed' === $status ? 'success' : 'error',
				'text' => self::text( 'confirmed' === $status ? 'already' : 'expired' ),
			);
			return meil_template( 'confirm', $vars );
		}

		$vars['show_button'] = true;
		$email               = (string) ( $check['email'] ?? '' );
		$vars['intro']       = '' !== $email ? sprintf( self::text( 'ask' ), $email ) : self::text( 'ask_plain' );
		// Each list as the form showed it (its own label), else by its name in meil.ro.
		$labels = array();
		if ( $vars['form_id'] > 0 ) {
			foreach ( (array) Meil_Forms::settings( $vars['form_id'] )['audiences'] as $row ) {
				if ( is_array( $row ) && isset( $row['id'] ) ) {
					$labels[ (string) $row['id'] ] = Meil_Forms::list_label( $vars['form_id'], $row );
				}
			}
		}
		foreach ( (array) ( $check['audiences'] ?? array() ) as $audience ) {
			if ( ! is_array( $audience ) ) {
				continue;
			}
			$label = $labels[ (string) ( $audience['id'] ?? '' ) ] ?? (string) ( $audience['name'] ?? '' );
			if ( '' !== $label ) {
				$vars['lists'][] = $label;
			}
		}
		return meil_template( 'confirm', $vars );
	}
}
