<?php
/**
 * Thin REST client for the meil.ro API: only the calls this plugin makes.
 *
 * Bearer-token auth, JSON in, JSON out, errors shaped as
 * {"error":{"message":"...","name":"..."}} and returned as WP_Error, with the
 * HTTP status in the error data.
 *
 * @see https://meil.ro/docs
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Client {

	/** Seconds to wait for meil.ro while a visitor's page is loading. */
	public const PAGE_TIMEOUT = 10;

	/** @var string */
	private $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = trim( $api_key );
	}

	public function is_configured(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * @param array<string,mixed>|null $body
	 * @param array<string,string>     $headers Extra request headers.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null, int $timeout = 20, array $headers = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'meil_not_configured', __( 'meil is not configured: add the API key under meil.ro > Settings.', 'meilro' ) );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => $timeout,
			'user-agent' => 'meil-wordpress/' . MEIL_MAILER_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			) + $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( MEIL_MAILER_API_BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Over the account's requests per second (busy site, several calls in a
		// row): wait as long as meil.ro asks, at most 2 seconds, and try once more.
		if ( 429 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$wait = min( 2, max( 1, (int) wp_remote_retrieve_header( $response, 'retry-after' ) ) );
			sleep( $wait );
			$response = wp_remote_request( MEIL_MAILER_API_BASE_URL . $path, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 400 ) {
			$message = is_array( $data ) ? ( $data['error']['message'] ?? '' ) : '';
			$name    = is_array( $data ) ? ( $data['error']['name'] ?? 'api_error' ) : 'api_error';
			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %d: HTTP status code */
					__( 'meil API returned HTTP %d.', 'meilro' ),
					$code
				);
			}
			return new WP_Error(
				$name,
				$message,
				array(
					'status' => $code,
					'body'   => $data,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/** An email address as one segment of a URL path. */
	private static function segment( string $value ): string {
		return rawurlencode( $value );
	}

	/**
	 * Sends one email (POST /emails). With an $idempotency_key, meil.ro answers
	 * a repeat of the same call within 24 hours with the first result instead
	 * of sending again, so a call that timed out can be tried again safely.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function send_email( array $payload, string $idempotency_key = '', int $timeout = 20 ) {
		return $this->request( 'POST', '/emails', $payload, $timeout, '' !== $idempotency_key ? array( 'Idempotency-Key' => $idempotency_key ) : array() );
	}

	/**
	 * Emails sent through the API, newest first, without their contents
	 * (GET /emails). $after is the id of the last one already shown, for the
	 * older ones; $before the first, for the newer ones.
	 */
	public function list_emails( int $limit = 25, string $after = '', string $before = '' ) {
		$query = array( 'limit' => $limit );
		if ( '' !== $after ) {
			$query['after'] = $after;
		} elseif ( '' !== $before ) {
			$query['before'] = $before;
		}
		return $this->request( 'GET', '/emails?' . http_build_query( $query ), null, self::PAGE_TIMEOUT );
	}

	/** One email with its contents, while meil.ro still keeps them (GET /emails/{id}). */
	public function get_email( string $id ) {
		return $this->request( 'GET', '/emails/' . self::segment( $id ), null, self::PAGE_TIMEOUT );
	}

	/** The key itself: its domain and permission (GET /key). */
	public function get_key() {
		return $this->request( 'GET', '/key' );
	}

	public function list_audiences() {
		return $this->request( 'GET', '/audiences', null, self::PAGE_TIMEOUT );
	}

	/**
	 * Adds a contact to audiences (POST /contacts). The payload carries
	 * audience_ids, email and the optional name, properties and confirm fields.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function create_contact( array $payload, int $timeout = self::PAGE_TIMEOUT ) {
		return $this->request( 'POST', '/contacts', $payload, $timeout );
	}

	/**
	 * Where a sign-up waiting for confirmation stands (GET /contacts/confirm/{token}):
	 * status pending, confirmed or expired, the address and the audiences by
	 * name. Changes nothing. A WP_Error with status 404 for a token that is not valid.
	 */
	public function get_confirmation( string $token ) {
		return $this->request( 'GET', '/contacts/confirm/' . self::segment( $token ), null, self::PAGE_TIMEOUT );
	}

	/**
	 * Confirms a sign-up (POST /contacts/confirm), with the visitor's IP as
	 * proof. Only ever on the visitor's button press: mail filters open every
	 * link in an email. An expired link is not an error: status is expired.
	 */
	public function confirm_contact( string $token, string $ip ) {
		$payload = array( 'token' => $token );
		if ( '' !== $ip ) {
			$payload['ip'] = $ip;
		}
		return $this->request( 'POST', '/contacts/confirm', $payload, self::PAGE_TIMEOUT );
	}

	/** A contact with its audiences, or a WP_Error with status 404 when it does not exist. */
	public function get_contact( string $email ) {
		return $this->request( 'GET', '/contacts/' . self::segment( $email ), null, self::PAGE_TIMEOUT );
	}

	/**
	 * Changes a contact (PATCH /contacts/{email}): name, properties, or which
	 * audiences it is on ("audiences" => [id => true|false]).
	 *
	 * @param array<string,mixed> $payload
	 */
	public function update_contact( string $email, array $payload ) {
		return $this->request( 'PATCH', '/contacts/' . self::segment( $email ), $payload, self::PAGE_TIMEOUT );
	}

	/** Deletes a contact from every audience, with its data. */
	public function delete_contact( string $email ) {
		return $this->request( 'DELETE', '/contacts/' . self::segment( $email ) );
	}

	/** The HTTP status of an error this client returned, 0 for a network error. */
	public static function status( WP_Error $error ): int {
		$data = $error->get_error_data();
		return is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	}
}
