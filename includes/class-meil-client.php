<?php
/**
 * Thin REST client for the meil.ro API: only the calls this plugin makes.
 *
 * Bearer-token auth, JSON in, JSON out, errors shaped as
 * {"error":{"message":"...","name":"..."}}.
 *
 * @see https://meil.ro/docs
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Client {

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
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( string $method, string $path, ?array $body = null ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'meil_not_configured', __( 'meil is not configured: add the API key under Meil → Settings.', 'meil' ) );
		}

		$args = array(
			'method'     => $method,
			'timeout'    => 20,
			'user-agent' => 'meil-wordpress/' . MEIL_MAILER_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'    => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( MEIL_MAILER_API_BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
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
					__( 'meil API returned HTTP %d.', 'meil' ),
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

	/** @param array<string,mixed> $payload */
	public function send_email( array $payload ) {
		return $this->request( 'POST', '/emails', $payload );
	}

	public function list_audiences() {
		return $this->request( 'GET', '/audiences' );
	}

	/** @param array<string,mixed> $payload */
	public function create_contact( array $payload ) {
		return $this->request( 'POST', '/contacts', $payload );
	}
}
