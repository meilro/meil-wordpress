<?php
/**
 * [meil_preferences]: the logged-in user ticks and unticks the lists they get.
 *
 * Meant for an account page. It shows every public audience of the API key's
 * domain, ticked when the user's address is on it, and saves the changes:
 * PATCH /contacts/{email} when meil.ro already knows the address, POST
 * /contacts when it does not and the user ticked something. A logged-in user
 * is not asked to confirm by email: WordPress already knows the address.
 *
 * The form is sent in the background (admin-ajax.php, see assets/forms.js) and
 * the answer updates the page in place. Without JavaScript it still works: the
 * form posts to the page, is saved before the page is drawn (template_redirect)
 * and ends in a redirect, so a refresh never sends it twice. The page is
 * personal, so it is marked as not cacheable.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Preferences {

	private const ACTION = 'meil_prefs_save';
	private const RESULT = 'meil_prefs';

	/** @var Meil_Client */
	private $client;

	public function __construct( Meil_Client $client ) {
		$this->client = $client;

		add_shortcode( 'meil_preferences', array( $this, 'render_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'template_redirect', array( $this, 'no_cache' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle_ajax' ) );
	}

	/** A page with the shortcode is one person's data: never cached. */
	public function no_cache(): void {
		$post = get_post();
		if ( is_singular() && $post && has_shortcode( $post->post_content, 'meil_preferences' ) ) {
			self::mark_not_cacheable();
		}
	}

	private static function mark_not_cacheable(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // WP Super Cache, W3 Total Cache, WP Rocket, LiteSpeed and others read it.
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
	}

	/** The message for the result of a save: [type, text]. */
	private static function notice( string $result ): array {
		switch ( $result ) {
			case 'saved':
				return array( 'success', __( 'Your choices are saved.', 'meil' ) );
			case 'blocked':
				return array( 'error', __( 'Some lists could not be turned on for this address, because earlier emails to it were not delivered or were marked as spam. Contact us if this is a mistake.', 'meil' ) );
			case 'login':
				return array( 'error', __( 'Your email preferences are available once you are logged in.', 'meil' ) );
		}
		return array( 'error', __( 'Sorry, your choices could not be saved right now. Please try again later.', 'meil' ) );
	}

	/**
	 * The account's public audiences, as the preferences page shows them.
	 *
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private function public_audiences() {
		$audiences = meil_cached_audiences( $this->client );
		if ( is_wp_error( $audiences ) ) {
			return $audiences;
		}
		return array_values(
			array_filter(
				$audiences,
				static function ( array $audience ): bool {
					return 'public' === ( $audience['visibility'] ?? '' );
				}
			)
		);
	}

	/**
	 * The user's lists in meil.ro, by audience id: null when meil.ro does not
	 * know the address yet.
	 *
	 * @return array<string,array<string,mixed>>|null|WP_Error
	 */
	private function memberships( string $email ) {
		$contact = $this->client->get_contact( $email );
		if ( is_wp_error( $contact ) ) {
			return 404 === Meil_Client::status( $contact ) ? null : $contact;
		}
		return self::by_audience( (array) ( $contact['audiences'] ?? array() ) );
	}

	/**
	 * @param array<int,mixed> $memberships As the API lists them.
	 * @return array<string,array<string,mixed>>
	 */
	private static function by_audience( array $memberships ): array {
		$out = array();
		foreach ( $memberships as $membership ) {
			if ( is_array( $membership ) && isset( $membership['audience_id'] ) ) {
				$out[ (string) $membership['audience_id'] ] = $membership;
			}
		}
		return $out;
	}

	/**
	 * The lists as the page shows them: each public audience with its state.
	 *
	 * @param list<array<string,mixed>>              $audiences
	 * @param array<string,array<string,mixed>>|null $memberships
	 * @return list<array<string,mixed>>
	 */
	private static function choices( array $audiences, ?array $memberships ): array {
		$choices = array();
		foreach ( $audiences as $audience ) {
			$id         = (string) $audience['id'];
			$membership = null !== $memberships ? ( $memberships[ $id ] ?? null ) : null;
			$pending    = null !== $membership && ! empty( $membership['pending_confirmation'] );
			$subscribed = null !== $membership && ! empty( $membership['subscribed'] );

			$choices[] = array(
				'id'          => $id,
				'label'       => (string) ( $audience['name'] ?? '' ),
				'description' => (string) ( $audience['description'] ?? '' ),
				'checked'     => $subscribed || $pending,
				'state'       => $pending ? 'pending' : ( $subscribed ? 'subscribed' : 'unsubscribed' ),
			);
		}

		/**
		 * The lists shown, in order: change a label, reorder or leave one out.
		 * Each has id, label, description, checked and state (subscribed,
		 * pending or unsubscribed).
		 */
		return array_values( (array) apply_filters( 'meil_preferences_choices', $choices ) );
	}

	/**
	 * Saves the lists ticked in the posted form for the logged-in user.
	 *
	 * @return array{result:string,choices:list<array<string,mixed>>}
	 */
	private function save(): array {
		$fail = array(
			'result'  => 'error',
			'choices' => array(),
		);
		if ( ! is_user_logged_in() ) {
			$fail['result'] = 'login';
			return $fail;
		}
		if ( ! isset( $_POST['meil_prefs_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['meil_prefs_nonce'] ) ), self::ACTION ) ) {
			return $fail;
		}

		$user      = wp_get_current_user();
		$email     = (string) $user->user_email;
		$audiences = $this->public_audiences();
		$current   = is_wp_error( $audiences ) ? $audiences : $this->memberships( $email );
		if ( is_wp_error( $audiences ) || is_wp_error( $current ) ) {
			return $fail;
		}

		$ids    = array_map( 'strval', array_column( $audiences, 'id' ) );
		$posted = isset( $_POST['meil_audiences'] ) && is_array( $_POST['meil_audiences'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['meil_audiences'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the map.
			: array();
		$wanted = array_values( array_intersect( $ids, $posted ) );

		if ( null === $current ) {
			// Not in meil.ro yet: only ticking something makes them a contact.
			if ( array() === $wanted ) {
				return array(
					'result'  => 'saved',
					'choices' => self::choices( $audiences, null ),
				);
			}
			$payload = array(
				'audience_ids' => $wanted,
				'email'        => $email,
				'properties'   => array(
					'consent_timestamp' => gmdate( 'c' ),
					'consent_ip'        => meil_visitor_ip(),
					'source'            => 'wordpress_account',
				),
			);
			if ( '' !== (string) $user->first_name ) {
				$payload['first_name'] = (string) $user->first_name;
			}
			if ( '' !== (string) $user->last_name ) {
				$payload['last_name'] = (string) $user->last_name;
			}
			$result = $this->client->create_contact( $payload );
		} else {
			$lists = array();
			foreach ( $ids as $id ) {
				$lists[ $id ] = in_array( $id, $wanted, true );
			}
			$result = $this->client->update_contact( $email, array( 'audiences' => $lists ) );
		}
		if ( is_wp_error( $result ) ) {
			return $fail;
		}

		$memberships = self::by_audience( (array) ( $result['audiences'] ?? array() ) );
		$saved       = array(
			'result'  => 'saved',
			'choices' => self::choices( $audiences, $memberships ),
		);
		// A list that stayed off although it was ticked: the address bounced or
		// reported spam, and meil.ro will not send to it again.
		foreach ( $wanted as $id ) {
			if ( empty( $memberships[ $id ]['subscribed'] ) ) {
				$saved['result'] = 'blocked';
			}
		}
		return $saved;
	}

	/** The form sent in the background: answers with the message and the lists as they are now. */
	public function handle_ajax(): void {
		$saved                  = $this->save();
		list( $type, $message ) = self::notice( $saved['result'] );
		wp_send_json(
			array(
				'type'    => $type,
				'message' => $message,
				'choices' => array_map(
					static function ( array $choice ): array {
						return array(
							'id'      => $choice['id'],
							'checked' => $choice['checked'],
							'state'   => $choice['state'],
						);
					},
					$saved['choices']
				),
			)
		);
	}

	/** Without JavaScript: saves, then sends the browser back to the page. */
	public function handle_post(): void {
		if ( wp_doing_ajax() || ! isset( $_POST['meil_action'] ) || self::ACTION !== $_POST['meil_action'] ) {
			return;
		}
		// Back to the same address the form was sent from (an account page may
		// carry its own query string), without the previous result.
		$here = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		wp_safe_redirect( add_query_arg( self::RESULT, $this->save()['result'], remove_query_arg( self::RESULT, $here ) ) );
		exit;
	}

	/** @param array<string,mixed>|string $atts */
	public function render_shortcode( $atts ): string {
		self::mark_not_cacheable();
		meil_enqueue_front_assets();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which notice to show, after a save without JavaScript.
		$result  = isset( $_GET[ self::RESULT ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::RESULT ] ) ) : '';
		$message = null;
		if ( in_array( $result, array( 'saved', 'blocked', 'error' ), true ) ) {
			list( $type, $text ) = self::notice( $result );
			$message             = array(
				'type' => $type,
				'text' => $text,
			);
		}

		$vars = array(
			'logged_in' => is_user_logged_in(),
			'message'   => $message,
			'email'     => '',
			'choices'   => array(),
			'error'     => '',
			'login_url' => wp_login_url( get_permalink() ? (string) get_permalink() : home_url( '/' ) ),
			'action'    => self::ACTION,
			'nonce'     => '',
			'ajax_url'  => admin_url( 'admin-ajax.php' ),
		);

		if ( $vars['logged_in'] ) {
			$email         = (string) wp_get_current_user()->user_email;
			$vars['email'] = $email;
			$vars['nonce'] = wp_create_nonce( self::ACTION );

			$audiences = $this->public_audiences();
			$current   = is_wp_error( $audiences ) ? $audiences : $this->memberships( $email );
			if ( is_wp_error( $audiences ) || is_wp_error( $current ) ) {
				$problem       = is_wp_error( $audiences ) ? $audiences : $current;
				$vars['error'] = current_user_can( 'manage_options' )
					? $problem->get_error_message()
					: __( 'Your email preferences cannot be shown right now. Please try again later.', 'meil' );
			} else {
				$vars['choices'] = self::choices( $audiences, $current );
			}
		}

		return meil_template( 'preferences', $vars );
	}
}
