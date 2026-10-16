<?php
/**
 * Routes wp_mail() through the meil API instead of PHPMailer/SMTP.
 *
 * Hooks the core `pre_wp_mail` filter (WP 5.5+): returning a bool from it
 * short-circuits wp_mail() entirely, so no local mail transport is touched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Mailer {

	/** @var Meil_Client */
	private $client;

	/** @var array<string,mixed> */
	private $settings;

	public function __construct( Meil_Client $client, array $settings ) {
		$this->client   = $client;
		$this->settings = $settings;

		add_filter( 'pre_wp_mail', array( $this, 'send' ), 10, 2 );
	}

	/**
	 * @param bool|null           $short_circuit
	 * @param array<string,mixed> $atts to, subject, message, headers, attachments
	 * @return bool|null
	 */
	public function send( $short_circuit, array $atts ) {
		if ( empty( $this->settings['override_wp_mail'] ) || ! $this->client->is_configured() ) {
			return null; // fall through to WordPress' default mail transport
		}

		$to          = $atts['to'] ?? '';
		$subject     = (string) ( $atts['subject'] ?? '' );
		$message     = (string) ( $atts['message'] ?? '' );
		$headers     = $atts['headers'] ?? '';
		$attachments = $atts['attachments'] ?? array();

		$parsed = $this->parse_headers( $headers );

		$to_field = $this->addr_field( $to );
		if ( '' === $to_field ) {
			return $this->fail( $atts, __( 'meil: no recipient address.', 'meil' ) );
		}

		// Same filters core wp_mail() applies, so plugins that switch to HTML or
		// set a sender name that way (instead of via headers) keep working.
		// wp_mail_from is left out on purpose: meil only sends from verified
		// domains, so the address from Settings (or a From header) wins.
		$content_type = (string) apply_filters( 'wp_mail_content_type', $parsed['content_type'] );
		$is_html      = 'text/html' === strtolower( trim( explode( ';', $content_type )[0] ) );

		$from_name  = '' !== $parsed['from_name'] ? $parsed['from_name'] : (string) $this->settings['from_name'];
		$from_name  = (string) apply_filters( 'wp_mail_from_name', $from_name );
		$from_email = '' !== $parsed['from_email'] ? $parsed['from_email'] : (string) $this->settings['from_email'];
		if ( '' === $from_email ) {
			$from_email = 'wordpress@' . $this->site_domain();
		}
		$from = '' !== $from_name ? sprintf( '%s <%s>', $from_name, $from_email ) : $from_email;

		$payload = array(
			'from'    => $from,
			'to'      => $to_field,
			'subject' => $subject,
		);

		if ( $is_html ) {
			$payload['html'] = $message;
		} else {
			$payload['text'] = $message;
		}

		if ( '' !== $parsed['cc'] ) {
			$payload['cc'] = $parsed['cc'];
		}
		if ( '' !== $parsed['bcc'] ) {
			$payload['bcc'] = $parsed['bcc'];
		}
		if ( '' !== $parsed['reply_to'] ) {
			$payload['reply_to'] = $parsed['reply_to'];
		}
		if ( array() !== $parsed['extra_headers'] ) {
			$payload['headers'] = $parsed['extra_headers'];
		}

		$encoded_attachments = $this->encode_attachments( $attachments );
		if ( array() !== $encoded_attachments ) {
			$payload['attachments'] = $encoded_attachments;
		}

		/**
		 * Filters the JSON payload sent to POST /emails just before it goes out.
		 *
		 * @param array<string,mixed> $payload
		 * @param array<string,mixed> $atts    original wp_mail() arguments
		 */
		$payload = apply_filters( 'meil_mailer_payload', $payload, $atts );

		$result = $this->client->send_email( $payload );

		if ( is_wp_error( $result ) ) {
			return $this->fail( $atts, $result->get_error_message() );
		}

		/**
		 * Fires after a successful send via the meil API.
		 *
		 * @param array<string,mixed> $result  decoded API response ({"id": "..."})
		 * @param array<string,mixed> $payload the payload that was sent
		 */
		do_action( 'meil_mailer_sent', $result, $payload );
		do_action( 'wp_mail_succeeded', $atts );

		return true;
	}

	/** Fires wp_mail_failed with a WP_Error, as core wp_mail() would. */
	private function fail( array $atts, string $message ): bool {
		$error = new WP_Error( 'meil_send_failed', $message, $atts );
		do_action( 'wp_mail_failed', $error );
		do_action( 'meil_mailer_failed', $error, $atts );
		return false;
	}

	/**
	 * Parses a wp_mail() headers string/array the same way core's pluggable.php
	 * does: Content-Type, From, Cc, Bcc, Reply-To, and anything else is passed
	 * through as a custom header.
	 *
	 * @param string|string[] $headers
	 * @return array{content_type:string,from_name:string,from_email:string,cc:string,bcc:string,reply_to:string,extra_headers:array<string,string>}
	 */
	private function parse_headers( $headers ): array {
		$content_type = 'text/plain';
		$from_name    = '';
		$from_email = '';
		$cc         = array();
		$bcc        = array();
		$reply_to   = array();
		$extra      = array();

		if ( ! empty( $headers ) ) {
			$lines = is_array( $headers )
				? $headers
				: explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );

			foreach ( $lines as $line ) {
				$line = trim( (string) $line );
				if ( '' === $line || strpos( $line, ':' ) === false ) {
					continue;
				}
				[$name, $content] = explode( ':', $line, 2 );
				$name             = trim( $name );
				$content          = trim( $content );
				if ( '' === $name ) {
					continue;
				}

				switch ( strtolower( $name ) ) {
					case 'content-type':
						$type = trim( explode( ';', $content, 2 )[0] );
						if ( '' !== $type ) {
							$content_type = $type;
						}
						break;
					case 'mime-version':
					case 'content-transfer-encoding':
						// meil builds the MIME message itself.
						break;
					case 'from':
						if ( preg_match( '/^(.*)<(.+)>$/', $content, $m ) ) {
							$from_name  = trim( $m[1], " \t\"'" );
							$from_email = trim( $m[2] );
						} else {
							$from_email = $content;
						}
						break;
					case 'cc':
						$cc[] = $content;
						break;
					case 'bcc':
						$bcc[] = $content;
						break;
					case 'reply-to':
						$reply_to[] = $content;
						break;
					default:
						// meil rejects routing/identity/MIME headers itself; pass the rest through.
						$extra[ $name ] = $content;
						break;
				}
			}
		}

		return array(
			'content_type'  => $content_type,
			'from_name'     => $from_name,
			'from_email'    => sanitize_email( $from_email ),
			'cc'            => implode( ', ', $cc ),
			'bcc'           => implode( ', ', $bcc ),
			'reply_to'      => implode( ', ', $reply_to ),
			'extra_headers' => $extra,
		);
	}

	/**
	 * @param mixed $value string, comma/newline-separated string, or array
	 */
	private function addr_field( $value ): string {
		$parts = is_array( $value ) ? $value : preg_split( '/[,\r\n]+/', (string) $value );
		$parts = array_values(
			array_filter(
				array_map( 'trim', (array) $parts ),
				static function ( $p ) {
					return '' !== $p;
				}
			)
		);
		return implode( ', ', $parts );
	}

	/**
	 * Reads wp_mail() attachments (file paths, optionally keyed by desired
	 * filename) into the base64 shape POST /emails expects, capped the same
	 * way the API itself caps a request (20 files).
	 *
	 * @param string|string[] $attachments
	 * @return array<int,array{filename:string,content:string}>
	 */
	private function encode_attachments( $attachments ): array {
		// Core wp_mail() also accepts one path per line in a string.
		if ( ! is_array( $attachments ) ) {
			$attachments = explode( "\n", str_replace( "\r\n", "\n", (string) $attachments ) );
		}

		$out = array();
		foreach ( $attachments as $key => $path ) {
			$path = is_string( $path ) ? trim( $path ) : $path;
			if ( count( $out ) >= 20 ) {
				break;
			}
			if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
				continue;
			}
			$data = file_get_contents( $path );
			if ( false === $data ) {
				continue;
			}
			$out[] = array(
				'filename' => is_string( $key ) && '' !== $key ? $key : wp_basename( $path ),
				'content'  => base64_encode( $data ),
			);
		}
		return $out;
	}

	private function site_domain(): string {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		return is_string( $host ) && '' !== $host ? $host : 'localhost';
	}
}
