<?php
/**
 * Emails and sign-ups that could not reach meil.ro right away.
 *
 * A call is tried at once, while the page waits, as before. When that does not
 * work for a reason that may pass (meil.ro cannot be reached, takes too long,
 * asks to slow down, or answers 5xx), the call is kept in a table of its own
 * and WP-Cron tries it again 1, 5, 15 and 60 minutes later, then after 3, 6
 * and 12 hours. Every email carries an Idempotency-Key, which meil.ro
 * remembers for 24 hours: when a try timed out after meil.ro had in fact sent
 * the email, the next try gets the first answer back instead of a second
 * email. That is why all the tries fit in the first 23 hours.
 *
 * A refusal that trying again cannot fix (an invalid address, a revoked key, a
 * From address on a domain meil.ro does not send for) is kept as failed at
 * once. Failed calls are listed under meil.ro > Unsent, where they can be sent
 * again or deleted, and are removed after 30 days. A call that went through is
 * removed straight away: meil.ro's own log has the sent emails.
 *
 * After a failure that looks like meil.ro is down, the calls of the next
 * minute are kept without trying, so a page that sends several emails waits
 * once, not once per email.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Queue {

	/** The WP-Cron event that tries the waiting calls. */
	public const HOOK = 'meil_queue_run';

	/** Bumped when the table changes; maybe_install() then updates it. */
	private const DB_VERSION = '1';
	private const DB_OPTION  = 'meil_queue_db';

	/** Set for a minute after meil.ro looked down. */
	private const DOWN = 'meil_api_down';

	/** Seconds before each next try: 1, 5, 15, 60 minutes, then 3, 6, 12 hours. 22 hours 21 minutes in all. */
	private const DELAYS = array( 60, 300, 900, 3600, 10800, 21600, 43200 );

	/** Failed calls are kept this many days. */
	private const KEEP_DAYS = 30;

	/** At most this many calls per run, so one run stays well inside a request. */
	private const BATCH = 20;

	/** @var Meil_Client */
	private $client;

	public function __construct( Meil_Client $client ) {
		$this->client = $client;
		add_action( self::HOOK, array( $this, 'run' ) );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'meil_queue';
	}

	/**
	 * Creates or updates the table. payload is the JSON body of the call;
	 * recipient and subject are only for the Unsent list. Times are UTC.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				kind varchar(16) NOT NULL DEFAULT 'email',
				status varchar(16) NOT NULL DEFAULT 'waiting',
				recipient varchar(255) NOT NULL DEFAULT '',
				subject varchar(255) NOT NULL DEFAULT '',
				payload longtext NOT NULL,
				idem_key varchar(64) NOT NULL DEFAULT '',
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				last_error text NULL,
				created_at datetime NOT NULL,
				next_try datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY status_next (status,next_try)
			) {$charset};"
		);
		update_option( self::DB_OPTION, self::DB_VERSION );
	}

	/** Activation does not run when a plugin is updated from a zip: this does, once. */
	public static function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			self::install();
		}
	}

	/* ------------------------------------------------------------ sending */

	/** Did meil.ro look down in the last minute? Then a new call is kept without trying. */
	public static function is_down(): bool {
		return (bool) get_transient( self::DOWN );
	}

	/**
	 * Could the same call work later? A network error or timeout (no status),
	 * 408, 429 (too fast, or a daily or monthly limit), 5xx, or another try
	 * with the same Idempotency-Key still running.
	 */
	public static function is_temporary( WP_Error $error ): bool {
		if ( 'meil_not_configured' === $error->get_error_code() ) {
			return false;
		}
		$status = Meil_Client::status( $error );
		return 0 === $status || 408 === $status || 429 === $status || $status >= 500
			|| 'concurrent_idempotent_requests' === $error->get_error_code();
	}

	/** Makes the API call a stored row stands for. */
	public function call( string $kind, array $payload, string $idem_key, int $timeout ) {
		if ( 'contact' === $kind ) {
			return $this->client->create_contact( $payload, $timeout );
		}
		return $this->client->send_email( $payload, $idem_key, $timeout );
	}

	/**
	 * Tries a call now, while the page waits, and keeps it when that does not
	 * work. true when it was sent or kept to be tried again; a WP_Error when
	 * meil.ro refused it for good (it is then on the Unsent list).
	 *
	 * @param array<string,mixed> $payload
	 * @return true|WP_Error
	 */
	public function deliver( string $kind, array $payload, string $recipient, string $subject, string $idem_key = '' ) {
		if ( self::is_down() ) {
			self::keep( $kind, $payload, $recipient, $subject, $idem_key, null, 0 );
			return true;
		}
		return $this->settle( $kind, $payload, $recipient, $subject, $idem_key, $this->call( $kind, $payload, $idem_key, Meil_Client::PAGE_TIMEOUT ) );
	}

	/**
	 * What happens after a first try: sent (true), kept for later (true), or
	 * refused for good and listed as failed (the WP_Error).
	 *
	 * @param array<string,mixed>          $payload
	 * @param array<string,mixed>|WP_Error $result
	 * @return true|WP_Error
	 */
	public function settle( string $kind, array $payload, string $recipient, string $subject, string $idem_key, $result ) {
		if ( ! is_wp_error( $result ) ) {
			return true;
		}
		if ( self::is_temporary( $result ) ) {
			self::note_down( $result );
			self::keep( $kind, $payload, $recipient, $subject, $idem_key, $result, 1 );
			return true;
		}
		self::store( $kind, 'failed', $payload, $recipient, $subject, $idem_key, $result->get_error_message(), 1, time() );
		return $result;
	}

	/**
	 * Keeps a call to be tried again. $attempts is how many tries it has had.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function keep( string $kind, array $payload, string $recipient, string $subject, string $idem_key, ?WP_Error $error, int $attempts ): void {
		self::store( $kind, 'waiting', $payload, $recipient, $subject, $idem_key, $error ? $error->get_error_message() : '', $attempts, time() + self::DELAYS[0] );
		/**
		 * Fires when a call is kept to be tried again later.
		 *
		 * @param string              $kind    email or contact
		 * @param array<string,mixed> $payload
		 */
		do_action( 'meil_queued', $kind, $payload );
		self::schedule();
	}

	/** @param array<string,mixed> $payload */
	private static function store( string $kind, string $status, array $payload, string $recipient, string $subject, string $idem_key, string $error, int $attempts, int $next_try ): void {
		global $wpdb;
		self::maybe_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
		$wpdb->insert(
			self::table(),
			array(
				'kind'       => $kind,
				'status'     => $status,
				'recipient'  => mb_substr( $recipient, 0, 255 ),
				'subject'    => mb_substr( $subject, 0, 255 ),
				'payload'    => (string) wp_json_encode( $payload ),
				'idem_key'   => $idem_key,
				'attempts'   => $attempts,
				'last_error' => $error,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'next_try'   => gmdate( 'Y-m-d H:i:s', $next_try ),
			)
		);
	}

	/** A network error or 5xx: meil.ro looks down, so the next minute's calls are kept without trying. */
	private static function note_down( WP_Error $error ): void {
		$status = Meil_Client::status( $error );
		if ( 0 === $status || $status >= 500 ) {
			set_transient( self::DOWN, 1, MINUTE_IN_SECONDS );
		}
	}

	/** Puts the cron event at the earliest waiting call, or removes it when nothing waits. */
	public static function schedule(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$next = $wpdb->get_var( "SELECT MIN(next_try) FROM {$wpdb->prefix}meil_queue WHERE status = 'waiting'" );
		$set  = wp_next_scheduled( self::HOOK );
		if ( ! $next ) {
			if ( $set ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
			return;
		}
		$when = max( time(), (int) strtotime( $next . ' UTC' ) );
		if ( $set && $set <= $when ) {
			return; // An earlier run finds nothing due yet and schedules again.
		}
		if ( $set ) {
			wp_unschedule_event( $set, self::HOOK );
		}
		wp_schedule_single_event( $when, self::HOOK );
	}

	/** The cron run: tries the calls that are due. */
	public function run(): void {
		global $wpdb;
		self::maybe_install();
		self::prune();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}meil_queue WHERE status = 'waiting' AND next_try <= %s ORDER BY next_try ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), self::BATCH ), ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$outcome = $this->attempt( $row );
			if ( is_wp_error( $outcome ) && self::is_down() ) {
				break; // Still down: the rest wait for the next run.
			}
		}
		self::schedule();
	}

	/**
	 * One more try of a stored call. true when it went through (the row is
	 * gone), otherwise the error (the row waits again, or is now failed).
	 *
	 * @param array<string,mixed> $row
	 * @return true|WP_Error
	 */
	private function attempt( array $row ) {
		global $wpdb;
		$payload  = json_decode( (string) $row['payload'], true );
		$payload  = is_array( $payload ) ? $payload : array();
		$attempts = (int) $row['attempts'] + 1;
		$result   = $this->call( (string) $row['kind'], $payload, (string) $row['idem_key'], 20 );

		if ( ! is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
			$wpdb->delete( self::table(), array( 'id' => (int) $row['id'] ) );
			if ( 'email' === $row['kind'] ) {
				/** This action is documented in includes/class-meil-mailer.php */
				do_action( 'meil_mailer_sent', $result, $payload );
			}
			return true;
		}

		$again = self::is_temporary( $result ) && isset( self::DELAYS[ $attempts - 1 ] );
		if ( $again ) {
			self::note_down( $result );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->update(
			self::table(),
			array(
				'status'     => $again ? 'waiting' : 'failed',
				'attempts'   => $attempts,
				'last_error' => $result->get_error_message(),
				'next_try'   => gmdate( 'Y-m-d H:i:s', time() + ( $again ? self::DELAYS[ $attempts - 1 ] : 0 ) ),
			),
			array( 'id' => (int) $row['id'] )
		);
		if ( ! $again && 'email' === $row['kind'] ) {
			/** This action is documented in includes/class-meil-mailer.php */
			do_action( 'meil_mailer_failed', $result, $payload );
		}
		return $result;
	}

	/* ------------------------------------------------------- the Unsent list */

	/**
	 * The calls waiting or failed, newest first.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function rows( int $limit = 200 ): array {
		global $wpdb;
		self::maybe_install();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, kind, status, recipient, subject, attempts, last_error, created_at, next_try FROM {$wpdb->prefix}meil_queue ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return array_values( (array) $rows );
	}

	/** How many failed calls there are, and the id of the newest one. */
	public static function failed_summary(): array {
		global $wpdb;
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			return array( 0, 0 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS n, MAX(id) AS newest FROM {$wpdb->prefix}meil_queue WHERE status = 'failed'", ARRAY_A );
		return array( (int) ( $row['n'] ?? 0 ), (int) ( $row['newest'] ?? 0 ) );
	}

	/**
	 * Tries one stored call again now, from the Unsent list, with a fresh
	 * count of tries. A new Idempotency-Key: the old one may have been
	 * forgotten by meil.ro by now, and this is the admin's own decision.
	 *
	 * @return true|WP_Error|null null when there is no such row.
	 */
	public function send_now( int $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}meil_queue WHERE id = %d", $id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['attempts'] = 0;
		if ( 'email' === $row['kind'] ) {
			$row['idem_key'] = wp_generate_uuid4();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
			$wpdb->update( self::table(), array( 'idem_key' => $row['idem_key'] ), array( 'id' => $id ) );
		}
		$outcome = $this->attempt( $row );
		self::schedule();
		return $outcome;
	}

	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete( self::table(), array( 'id' => $id ) );
		self::schedule();
	}

	/** Deletes every failed call. */
	public static function delete_failed(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table.
		$wpdb->delete( self::table(), array( 'status' => 'failed' ) );
	}

	/** Failed calls older than KEEP_DAYS go: they hold email contents. */
	public static function prune(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}meil_queue WHERE status = 'failed' AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
	}
}
