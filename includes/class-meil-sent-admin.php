<?php
/**
 * meil.ro > Sent: the emails sent through meil.ro, newest first, as meil.ro
 * reports them (GET /emails), with what became of each (delivered, bounced,
 * marked as spam...). Opening one shows its addresses and, while meil.ro
 * still keeps it (30 days), its contents.
 *
 * Nothing is stored on the site: each page asks meil.ro. Every API key can
 * read emails; a key limited to a domain sees only the emails from it.
 * Broadcasts are not listed, as in meil.ro's own Emails page.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Sent_Admin {

	public const PAGE = 'meil-sent';

	private const PER_PAGE = 25;

	/** @var Meil_Client */
	private $client;

	public function __construct( Meil_Client $client ) {
		$this->client = $client;
		add_action( 'admin_menu', array( $this, 'register_menu' ), 18 );
	}

	public function register_menu(): void {
		add_submenu_page( 'meil-mailer', __( 'Sent emails', 'meilro' ), __( 'Sent emails', 'meilro' ), 'manage_options', self::PAGE, array( $this, 'render_page' ) );
	}

	/** What became of an email, as meil.ro's dashboard says it, with a tone for its badge. */
	private static function status( string $event ): array {
		$labels = array(
			'delivered'    => array( __( 'Delivered', 'meilro' ), 'good' ),
			'sent'         => array( __( 'Sent', 'meilro' ), 'wait' ),
			'queued'       => array( __( 'Sending', 'meilro' ), 'wait' ),
			'sending'      => array( __( 'Sending', 'meilro' ), 'wait' ),
			'delayed'      => array( __( 'Delayed', 'meilro' ), 'wait' ),
			'bounced'      => array( __( 'Bounced', 'meilro' ), 'bad' ),
			'soft_bounced' => array( __( 'Soft bounce', 'meilro' ), 'bad' ),
			'complained'   => array( __( 'Spam complaint', 'meilro' ), 'bad' ),
			'rejected'     => array( __( 'Rejected', 'meilro' ), 'bad' ),
			'failed'       => array( __( 'Failed', 'meilro' ), 'bad' ),
		);
		return $labels[ $event ] ?? array( $event, 'wait' );
	}

	private static function badge( string $event ): string {
		list( $label, $tone ) = self::status( $event );
		return '<span class="meil-badge meil-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	private static function when( string $utc ): string {
		$time = strtotime( $utc . ' UTC' );
		return false === $time ? $utc : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	/** @param mixed $list */
	private static function addresses( $list ): string {
		return implode( ', ', array_map( 'strval', (array) $list ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation: which page or email to show.
		$id     = isset( $_GET['email'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['email'] ) ) : '';
		$after  = isset( $_GET['after'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['after'] ) ) : '';
		$before = isset( $_GET['before'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['before'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap meil-app">
			<?php meil_admin_head( __( 'Sent emails', 'meilro' ), '' !== $id ? '' : __( 'The emails your site and your other apps sent through meil.ro, newest first, and what became of each. Campaigns are in the meil.ro dashboard.', 'meilro' ) ); ?>
			<?php if ( ! $this->client->is_configured() ) : ?>
				<p><?php esc_html_e( 'Add an API key under meil.ro > Settings first.', 'meilro' ); ?></p>
			<?php elseif ( ! meil_key_can( 'email:read' ) ) : ?>
				<p><?php esc_html_e( 'This API key cannot read sent emails.', 'meilro' ); ?></p>
			<?php elseif ( '' !== $id ) : ?>
				<?php $this->render_email( $id ); ?>
			<?php else : ?>
				<?php $this->render_list( $after, $before ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_list( string $after, string $before ): void {
		$result = $this->client->list_emails( self::PER_PAGE, $after, $before );
		if ( is_wp_error( $result ) ) {
			/* translators: %s: error message from the meil API */
			echo '<div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Could not reach meil: %s', 'meilro' ), $result->get_error_message() ) ) . '</p></div>';
			return;
		}
		$emails = array_values( array_filter( (array) ( $result['data'] ?? array() ), 'is_array' ) );
		$more   = ! empty( $result['has_more'] );
		$base   = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		?>
		<?php if ( array() === $emails ) : ?>
			<div class="meil-panel"><div class="meil-panel__body"><p class="meil-empty"><?php esc_html_e( 'No emails yet.', 'meilro' ); ?></p></div></div>
			<?php
			return;
		endif;
		?>
		<div class="meil-panel"><div class="meil-panel__body meil-panel__body--tight">
		<table class="meil-table meil-sent">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'Date', 'meilro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'To', 'meilro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Subject', 'meilro' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'meilro' ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $emails as $email ) : ?>
					<tr>
						<td><?php echo esc_html( self::when( (string) ( $email['created_at'] ?? '' ) ) ); ?></td>
						<td><?php echo esc_html( self::addresses( $email['to'] ?? array() ) ); ?></td>
						<td><a class="meil-table__link" href="<?php echo esc_url( add_query_arg( 'email', (string) $email['id'], $base ) ); ?>"><?php echo esc_html( '' !== (string) ( $email['subject'] ?? '' ) ? (string) $email['subject'] : __( '(no subject)', 'meilro' ) ); ?></a></td>
						<td><?php echo wp_kses_post( self::badge( (string) ( $email['last_event'] ?? '' ) ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div></div>
		<p class="meil-actions meil-sent__pages">
			<?php
			// Newer: there are some when this page was reached by going back, or forward with more to come.
			$first = (string) $emails[0]['id'];
			$last  = (string) $emails[ count( $emails ) - 1 ]['id'];
			if ( '' !== $after || ( '' !== $before && $more ) ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'before', $first, $base ) ) . '">&larr; ' . esc_html__( 'Newer', 'meilro' ) . '</a> ';
			}
			if ( '' !== $before || $more ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( 'after', $last, $base ) ) . '">' . esc_html__( 'Older', 'meilro' ) . ' &rarr;</a>';
			}
			?>
		</p>
		<?php
	}

	private function render_email( string $id ): void {
		$back  = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		$email = $this->client->get_email( $id );
		echo '<p class="meil-back"><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'All sent emails', 'meilro' ) . '</a></p>';
		if ( is_wp_error( $email ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				404 === Meil_Client::status( $email )
					? __( 'This email was not found.', 'meilro' )
					/* translators: %s: error message from the meil API */
					: sprintf( __( 'Could not reach meil: %s', 'meilro' ), $email->get_error_message() )
			) . '</p></div>';
			return;
		}
		$rows = array(
			__( 'Date', 'meilro' )     => self::when( (string) ( $email['created_at'] ?? '' ) ),
			__( 'From', 'meilro' )     => (string) ( $email['from'] ?? '' ),
			__( 'To', 'meilro' )       => self::addresses( $email['to'] ?? array() ),
			__( 'Cc', 'meilro' )       => self::addresses( $email['cc'] ?? array() ),
			__( 'Bcc', 'meilro' )      => self::addresses( $email['bcc'] ?? array() ),
			__( 'Reply-To', 'meilro' ) => (string) ( $email['reply_to'] ?? '' ),
			__( 'Subject', 'meilro' )  => (string) ( $email['subject'] ?? '' ),
		);
		$files = array_filter(
			array_map(
				static function ( $file ): string {
					return is_array( $file ) ? (string) ( $file['filename'] ?? '' ) : '';
				},
				(array) ( $email['attachments'] ?? array() )
			)
		);
		if ( array() !== $files ) {
			$rows[ __( 'Attachments', 'meilro' ) ] = implode( ', ', $files );
		}
		?>
		<div class="meil-panel">
			<div class="meil-panel__head">
				<h2 class="meil-panel__title"><?php echo esc_html( (string) ( $email['subject'] ?? '' ) ); ?></h2>
				<?php echo wp_kses_post( self::badge( (string) ( $email['last_event'] ?? '' ) ) ); ?>
			</div>
			<div class="meil-panel__body">
				<dl class="meil-details">
					<?php foreach ( $rows as $label => $value ) : ?>
						<?php if ( '' !== $value ) : ?>
							<dt><?php echo esc_html( $label ); ?></dt><dd><?php echo esc_html( $value ); ?></dd>
						<?php endif; ?>
					<?php endforeach; ?>
				</dl>
				<p class="meil-actions"><a class="button" href="<?php echo esc_url( MEIL_MAILER_APP_URL . '/emails/' . rawurlencode( $id ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in meil.ro, with its delivery events', 'meilro' ); ?></a></p>
			</div>
		</div>

		<div class="meil-panel">
			<div class="meil-panel__head"><h2 class="meil-panel__title"><?php esc_html_e( 'Content', 'meilro' ); ?></h2></div>
			<div class="meil-panel__body">
		<?php
		$html = (string) ( $email['html'] ?? '' );
		$text = (string) ( $email['text'] ?? '' );
		if ( '' === $html && '' === $text ) {
			echo '<p class="meil-panel__lead">' . esc_html__( 'meil.ro keeps the content of an email for 30 days. This one is older, or was sent without it being kept.', 'meilro' ) . '</p>';
		} elseif ( '' !== $html ) {
			// A sandbox with nothing allowed: no scripts, no forms, links go nowhere.
			echo '<iframe class="meil-sent__preview" sandbox="" title="' . esc_attr__( 'Email preview', 'meilro' ) . '" srcdoc="' . esc_attr( $html ) . '"></iframe>';
		} else {
			echo '<pre class="meil-sent__text">' . esc_html( $text ) . '</pre>';
		}
		echo '</div></div>';
	}
}
