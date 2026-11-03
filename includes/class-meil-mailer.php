<?php
/**
 * Routes wp_mail() through the meil API instead of PHPMailer/SMTP.
 *
 * Hooks the core `pre_wp_mail` filter (WP 5.5+): returning a bool from it
 * short-circuits wp_mail() entirely, so no local mail transport is touched.
 *
 * The From address: meil.ro only sends from domains verified in the account,
 * and form plugins often put another address there (the admin's Gmail
 * address, or the visitor's own). Such an email is sent from the default from
 * address instead, under the same name, with the address it had in Reply-To,
 * so a reply still reaches it. Otherwise meil.ro would refuse it.
 *
 * When meil.ro cannot take the email right now, it is kept and sent later
 * (see Meil_Queue), and wp_mail() returns true: the email is on its way.
 * Only a refusal that trying again cannot fix returns false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Mailer {

	/** Domains meil.ro refused as a From address lately, so the next emails skip the refused call. */
	private const REFUSED = 'meil_refused_from';

	/** @var Meil_Client */
	private $client;

	/** @var array<string,mixed> */
	private $settings;

	/** @var Meil_Queue */
	private $queue;

	public function __construct( Meil_Client $client, array $settings, Meil_Queue $queue ) {
		$this->client   = $client;
		$this->settings = $settings;
		$this->queue    = $queue;

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
			return $this->fail( $atts, __( 'meil: no recipient address.', 'meilro' ) );
		}

		// The same filters core wp_mail() applies, so plugins that switch to
		// HTML or set the sender that way (WooCommerce does) keep working.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own wp_mail hook, as core wp_mail() runs it.
		$content_type = (string) apply_filters( 'wp_mail_content_type', $parsed['content_type'] );
		$is_html      = 'text/html' === strtolower( trim( explode( ';', $content_type )[0] ) );

		$default    = $this->default_from();
		$from_name  = '' !== $parsed['from_name'] ? $parsed['from_name'] : (string) $this->settings['from_name'];
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own wp_mail hook, as core wp_mail() runs it.
		$from_name  = (string) apply_filters( 'wp_mail_from_name', $from_name );
		$from_email = '' !== $parsed['from_email'] ? $parsed['from_email'] : $default;
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own wp_mail hook, as core wp_mail() runs it.
		$from_email = sanitize_email( (string) apply_filters( 'wp_mail_from', $from_email ) );

		$payload = array(
			'from'    => self::address( $from_name, '' !== $from_email ? $from_email : $default ),
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

		// A From address meil.ro is known to refuse is swapped before sending.
		if ( '' !== $from_email && ! $this->may_send_from( $from_email, $default ) ) {
			$payload = self::with_from( $payload, $from_name, $default, $from_email );
		}

		/**
		 * Filters the JSON payload sent to POST /emails just before it goes out.
		 *
		 * @param array<string,mixed> $payload
		 * @param array<string,mixed> $atts    original wp_mail() arguments
		 */
		$payload = apply_filters( 'meil_mailer_payload', $payload, $atts );

		// Each email gets its own key, kept with it if it has to wait, so no
		// try ever sends it twice.
		$idem_key = wp_generate_uuid4();

		if ( Meil_Queue::is_down() ) {
			Meil_Queue::keep( 'email', $payload, $to_field, $subject, $idem_key, null, 0 );
			return true;
		}

		$result = $this->queue->call( 'email', $payload, $idem_key, Meil_Client::PAGE_TIMEOUT );

		// Refused for its From address, on a domain this plugin could not
		// know about: once more from the default address, remembered for a day.
		$sent_from = self::bare( (string) ( $payload['from'] ?? '' ) );
		if ( is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'domain_not_verified', 'insufficient_permission' ), true )
			&& '' !== $sent_from && strtolower( $sent_from ) !== strtolower( $default ) ) {
			$this->remember_refused( $sent_from );
			$payload  = self::with_from( $payload, $from_name, $default, $sent_from );
			$idem_key = wp_generate_uuid4();
			$result   = $this->queue->call( 'email', $payload, $idem_key, Meil_Client::PAGE_TIMEOUT );
		}

		$outcome = $this->queue->settle( 'email', $payload, $to_field, $subject, $idem_key, $result );
		if ( is_wp_error( $outcome ) ) {
			return $this->fail( $atts, $outcome->get_error_message() );
		}

		if ( ! is_wp_error( $result ) ) {
			/**
			 * Fires after a successful send via the meil API.
			 *
			 * @param array<string,mixed> $result  decoded API response ({"id": "..."})
			 * @param array<string,mixed> $payload the payload that was sent
			 */
			do_action( 'meil_mailer_sent', $result, $payload );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own wp_mail hook, as core wp_mail() runs it.
		do_action( 'wp_mail_succeeded', $atts );

		return true;
	}

	/** The address from Settings, or wordpress@ the site's domain. */
	private function default_from(): string {
		$email = sanitize_email( (string) $this->settings['from_email'] );
		return '' !== $email ? $email : 'wordpress@' . $this->site_domain();
	}

	/**
	 * Can meil.ro be expected to send from this address? Yes on the default
	 * address's domain. On another one: no when the key is limited to a domain
	 * that does not cover it, or meil.ro refused it lately; else it is tried.
	 */
	private function may_send_from( string $email, string $default ): bool {
		$domain = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		if ( '' === $domain || ! is_email( $email ) ) {
			return false;
		}
		if ( strtolower( (string) substr( strrchr( $default, '@' ), 1 ) ) === $domain ) {
			return true;
		}
		$key_domain = strtolower( (string) ( $this->settings['key_domain'] ?? '' ) );
		if ( '' !== $key_domain && $domain !== $key_domain && substr( $domain, -strlen( '.' . $key_domain ) ) !== '.' . $key_domain ) {
			return false;
		}
		return ! in_array( $domain, (array) get_transient( self::REFUSED ), true );
	}

	private function remember_refused( string $email ): void {
		$domain  = strtolower( (string) substr( strrchr( $email, '@' ), 1 ) );
		$refused = array_slice( array_unique( array_merge( (array) get_transient( self::REFUSED ), array( $domain ) ) ), -20 );
		set_transient( self::REFUSED, array_values( array_filter( $refused ) ), DAY_IN_SECONDS );
	}

	/**
	 * The payload sent from $from_email under $name, with $original in
	 * Reply-To unless the email already names one.
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private static function with_from( array $payload, string $name, string $from_email, string $original ): array {
		$payload['from'] = self::address( $name, $from_email );
		if ( empty( $payload['reply_to'] ) && is_email( $original ) ) {
			$payload['reply_to'] = $original;
		}
		return $payload;
	}

	private static function address( string $name, string $email ): string {
		return '' !== $name ? sprintf( '%s <%s>', $name, $email ) : $email;
	}

	/** The bare address of "Name <address>" or of a bare address. */
	private static function bare( string $address ): string {
		return preg_match( '/<([^>]+)>\s*$/', $address, $m ) ? trim( $m[1] ) : trim( $address );
	}

	/** Fires wp_mail_failed with a WP_Error, as core wp_mail() would. */
	private function fail( array $atts, string $message ): bool {
		$error = new WP_Error( 'meil_send_failed', $message, $atts );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own wp_mail hook, as core wp_mail() runs it.
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
