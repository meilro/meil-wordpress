<?php
/**
 * meil.ro > Unsent: the emails and sign-ups waiting to be tried again, and the
 * ones meil.ro refused, each with why. They can be sent again or deleted.
 *
 * A failed one shows as a count on the menu item, and as a notice on every
 * admin screen until the list is opened, so a problem does not go unseen.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Queue_Admin {

	public const PAGE = 'meil-unsent';

	private const NONCE = 'meil_unsent';

	/** The newest failed id the admins have seen on the list. */
	private const SEEN = 'meil_unsent_seen';

	/** @var Meil_Queue */
	private $queue;

	public function __construct( Meil_Queue $queue ) {
		$this->queue = $queue;

		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_post_meil_unsent', array( $this, 'handle_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
	}

	public function register_menu(): void {
		list( $failed ) = Meil_Queue::failed_summary();
		$title          = __( 'Unsent', 'meilro' );
		add_submenu_page(
			'meil-mailer',
			$title,
			$failed > 0 ? $title . ' <span class="awaiting-mod">' . number_format_i18n( $failed ) . '</span>' : $title,
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/** On every admin screen but the list itself, while something failed that the admins have not seen yet. */
	public function render_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( $screen && false !== strpos( $screen->id, self::PAGE ) ) {
			return;
		}
		list( $failed, $newest ) = Meil_Queue::failed_summary();
		if ( 0 === $failed || $newest <= (int) get_option( self::SEEN, 0 ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo esc_html(
			sprintf(
				/* translators: %s: how many */
				_n( 'meil.ro: %s email or sign-up could not be sent.', 'meil.ro: %s emails or sign-ups could not be sent.', $failed, 'meilro' ),
				number_format_i18n( $failed )
			)
		);
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'See why', 'meilro' ) . '</a>';
		echo '</p></div>';
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		Meil_Queue::prune();
		$rows = Meil_Queue::rows();
		list( , $newest ) = Meil_Queue::failed_summary();
		update_option( self::SEEN, $newest, false );

		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap meil-app">
			<?php meil_admin_head( __( 'Unsent', 'meilro' ), __( 'When meil.ro cannot be reached, emails and sign-ups wait here and are tried again on their own for up to 22 hours. The ones meil.ro refused stay here for 30 days, with the reason, so you can fix the cause and send them again.', 'meilro' ) ); ?>
			<?php $this->render_result(); ?>

			<?php if ( array() === $rows ) : ?>
				<div class="meil-panel"><div class="meil-panel__body"><p class="meil-empty"><?php esc_html_e( 'Nothing is waiting, and nothing failed.', 'meilro' ); ?></p></div></div>
			<?php else : ?>
				<div class="meil-panel"><div class="meil-panel__body meil-panel__body--tight">
				<table class="meil-table meil-unsent">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Date', 'meilro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'To', 'meilro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Subject', 'meilro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Status', 'meilro' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Reason', 'meilro' ); ?></th>
							<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'meilro' ); ?></span></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( wp_date( $format, (int) strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
								<td><?php echo esc_html( (string) $row['recipient'] ); ?></td>
								<td><?php echo esc_html( 'contact' === $row['kind'] ? __( 'Newsletter sign-up', 'meilro' ) : (string) $row['subject'] ); ?></td>
								<td>
									<?php
									if ( 'waiting' === $row['status'] ) {
										echo '<span class="meil-badge meil-badge--wait">' . esc_html(
											sprintf(
												/* translators: %s: date and time */
												__( 'Waiting, next try %s', 'meilro' ),
												wp_date( $format, (int) strtotime( $row['next_try'] . ' UTC' ) )
											)
										) . '</span>';
									} else {
										echo '<span class="meil-badge meil-badge--bad">' . esc_html__( 'Failed', 'meilro' ) . '</span>';
									}
									?>
								</td>
								<td class="meil-table__muted"><?php echo esc_html( (string) $row['last_error'] ); ?></td>
								<td class="meil-unsent__actions">
									<?php $this->action_button( 'send', (int) $row['id'], __( 'Send now', 'meilro' ), 'button button-small' ); ?>
									<?php $this->action_button( 'delete', (int) $row['id'], __( 'Delete', 'meilro' ), 'button-link button-link-delete' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div></div>
				<div class="meil-actions"><?php $this->action_button( 'delete_failed', 0, __( 'Delete all failed', 'meilro' ), 'button' ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function action_button( string $do, int $id, string $label, string $class ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="meil-unsent__form">
			<input type="hidden" name="action" value="meil_unsent" />
			<input type="hidden" name="do" value="<?php echo esc_attr( $do ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<button type="submit" class="<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'meilro' ) );
		}
		check_admin_referer( self::NONCE );
		$do     = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( (string) $_POST['do'] ) ) : '';
		$id     = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$result = 'deleted';

		if ( 'send' === $do ) {
			$outcome = $this->queue->send_now( $id );
			if ( true === $outcome ) {
				$result = 'sent';
			} elseif ( is_wp_error( $outcome ) ) {
				$result = Meil_Queue::is_temporary( $outcome ) ? 'waiting' : 'failed';
			}
		} elseif ( 'delete' === $do ) {
			Meil_Queue::delete( $id );
		} elseif ( 'delete_failed' === $do ) {
			Meil_Queue::delete_failed();
		}
		wp_safe_redirect( add_query_arg( 'meil_unsent', $result, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/** The notice for what the last action did. Only reads the plugin's own flag. */
	private function render_result(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only picks which notice to show.
		$result = isset( $_GET['meil_unsent'] ) ? sanitize_key( wp_unslash( (string) $_GET['meil_unsent'] ) ) : '';
		$texts  = array(
			'sent'    => array( 'success', __( 'Sent.', 'meilro' ) ),
			'waiting' => array( 'warning', __( 'meil.ro still cannot be reached. It will be tried again on its own.', 'meilro' ) ),
			'failed'  => array( 'error', __( 'meil.ro refused it again. The reason is in the list.', 'meilro' ) ),
			'deleted' => array( 'success', __( 'Deleted.', 'meilro' ) ),
		);
		if ( isset( $texts[ $result ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $texts[ $result ][0] ), esc_html( $texts[ $result ][1] ) );
		}
	}
}
