<?php
/**
 * Settings screen: Meil.
 *
 * Standard Settings API (options.php) for the connection fields, a
 * separate admin-post.php handler for the "send a test email" tool, links
 * into the meil.ro dashboard, and the audiences ready for [meil_signup].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Mailer_Admin {

	private const PAGE       = 'meil-mailer';
	private const GROUP      = 'meil_mailer_group';
	private const SECTION    = 'meil_mailer_section';
	private const TEST_NONCE = 'meil_mailer_test_email';

	/** @var Meil_Client */
	private $client;

	/** @var string */
	private $option_name;

	public function __construct( Meil_Client $client, string $option_name ) {
		$this->client      = $client;
		$this->option_name = $option_name;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_meil_mailer_send_test', array( $this, 'handle_test_email' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'meil.ro', 'meil' ),
			__( 'meil.ro', 'meil' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_settings_page' ),
			'dashicons-email-alt',
			80
		);
	}

	public function register_settings(): void {
		register_setting(
			self::GROUP,
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => array(),
			)
		);

		add_settings_section(
			self::SECTION,
			__( 'Connection', 'meil' ),
			static function (): void {
				echo '<p>' . esc_html__( 'Enter an API key created under API Keys in meil.ro. An "Email sending" key is enough for WordPress mail; the signup form needs a "Full access" key.', 'meil' ) . '</p>';
			},
			self::PAGE
		);

		$fields = array(
			'api_key'          => __( 'API key', 'meil' ),
			'from_email'       => __( 'Default from address', 'meil' ),
			'from_name'        => __( 'Default from name', 'meil' ),
			'override_wp_mail' => __( 'Send WordPress mail through meil', 'meil' ),
		);
		foreach ( $fields as $field => $label ) {
			add_settings_field(
				$field,
				$label,
				array( $this, 'render_field' ),
				self::PAGE,
				self::SECTION,
				array( 'field' => $field )
			);
		}
	}

	/** @param array<string,mixed> $input */
	public function sanitize_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();

		return array(
			'api_key'          => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
			'from_email'       => sanitize_email( (string) ( $input['from_email'] ?? '' ) ),
			'from_name'        => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
			'override_wp_mail' => ! empty( $input['override_wp_mail'] ),
		);
	}

	public function render_field( array $args ): void {
		$field    = $args['field'];
		$settings = meil_mailer_settings();
		$value    = $settings[ $field ] ?? '';
		$name     = $this->option_name . '[' . $field . ']';
		$id       = 'meil-mailer-' . $field;

		switch ( $field ) {
			case 'api_key':
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="off" placeholder="meil_..." />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
			case 'from_email':
				printf(
					'<input type="email" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				echo '<p class="description">' . esc_html__( 'Must be on a domain verified in meil.ro under Domains.', 'meil' ) . '</p>';
				break;
			case 'from_name':
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value )
				);
				break;
			case 'override_wp_mail':
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html__( 'Replace wp_mail() delivery with the meil API (recommended). Leave unchecked to use meil for the signup form only.', 'meil' )
				);
				break;
		}
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = meil_mailer_settings();
		?>
		<div class="wrap meil-mailer-wrap">
			<h1><?php esc_html_e( 'meil.ro', 'meil' ); ?></h1>

			<?php $this->render_dashboard_links(); ?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::PAGE );
				submit_button( __( 'Save Settings', 'meil' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Send a test email', 'meil' ); ?></h2>
			<?php if ( empty( $settings['override_wp_mail'] ) || ! $this->client->is_configured() ) : ?>
				<p><?php esc_html_e( 'Add an API key and turn on "Send WordPress mail through meil" to send a test.', 'meil' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Sends through wp_mail(), the same way your site sends password resets and order emails.', 'meil' ); ?></p>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="meil_mailer_send_test" />
					<?php wp_nonce_field( self::TEST_NONCE ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="meil-mailer-test-to"><?php esc_html_e( 'Send to', 'meil' ); ?></label></th>
							<td>
								<input type="email" id="meil-mailer-test-to" name="test_to" class="regular-text"
									value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>" required />
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Send Test Email', 'meil' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>

			<?php if ( $this->client->is_configured() ) : ?>
				<hr />
				<h2><?php esc_html_e( 'Signup form', 'meil' ); ?></h2>
				<?php $this->render_audiences(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** Buttons that open the matching meil.ro dashboard pages in a new tab. */
	private function render_dashboard_links(): void {
		$links = array(
			'emails'     => __( 'Sent emails', 'meil' ),
			'broadcasts' => __( 'Broadcasts', 'meil' ),
			'audiences'  => __( 'Audiences', 'meil' ),
			'templates'  => __( 'Templates', 'meil' ),
			'domains'    => __( 'Domains', 'meil' ),
			'api-keys'   => __( 'API keys', 'meil' ),
		);

		echo '<p class="meil-mailer-links">';
		foreach ( $links as $path => $label ) {
			printf(
				'<a class="button" href="%1$s" target="_blank" rel="noopener">%2$s</a>',
				esc_url( MEIL_MAILER_APP_URL . '/' . $path ),
				esc_html( $label )
			);
		}
		echo '</p>';
	}

	/** Lists the account's audiences with the shortcode for each one. */
	private function render_audiences(): void {
		$result = $this->client->list_audiences();

		if ( is_wp_error( $result ) ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );
			if ( 403 === $status ) {
				echo '<p>' . esc_html__( 'This API key can only send email. To add a signup form to your site, use a "Full access" key.', 'meil' ) . '</p>';
				return;
			}
			echo '<div class="notice notice-error inline"><p>' . esc_html(
				sprintf(
					/* translators: %s: error message from the meil API */
					__( 'Could not reach meil: %s', 'meil' ),
					$result->get_error_message()
				)
			) . '</p></div>';
			return;
		}

		$audiences = $result['data'] ?? array();
		if ( array() === $audiences ) {
			printf(
				'<p>%1$s <a href="%2$s" target="_blank" rel="noopener">%3$s</a></p>',
				esc_html__( 'You have no audiences yet.', 'meil' ),
				esc_url( MEIL_MAILER_APP_URL . '/audiences' ),
				esc_html__( 'Create one in meil.ro', 'meil' )
			);
			return;
		}

		echo '<p>' . esc_html__( 'Paste a shortcode into any page, post or widget area to add a signup form for that audience. Add show_name_fields="true" to also ask for a first name.', 'meil' ) . '</p>';
		echo '<table class="widefat striped meil-mailer-audiences"><thead><tr>';
		echo '<th>' . esc_html__( 'Audience', 'meil' ) . '</th>';
		echo '<th>' . esc_html__( 'Shortcode', 'meil' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $audiences as $audience ) {
			printf(
				'<tr><td>%1$s</td><td><code>[meil_signup audience="%2$s"]</code></td></tr>',
				esc_html( (string) ( $audience['name'] ?? '' ) ),
				esc_html( (string) ( $audience['id'] ?? '' ) )
			);
		}
		echo '</tbody></table>';
	}

	public function handle_test_email(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'meil' ) );
		}
		check_admin_referer( self::TEST_NONCE );

		$to       = sanitize_email( wp_unslash( (string) ( $_POST['test_to'] ?? '' ) ) );
		$redirect = admin_url( 'admin.php?page=' . self::PAGE );

		if ( '' === $to || ! is_email( $to ) ) {
			wp_safe_redirect( add_query_arg( 'meil_test', 'invalid_address', $redirect ) );
			exit;
		}

		// Goes through wp_mail() so it tests the same path real site mail takes.
		$error   = null;
		$capture = static function ( WP_Error $e ) use ( &$error ): void {
			$error = $e;
		};
		add_action( 'wp_mail_failed', $capture );
		$sent = wp_mail(
			$to,
			sprintf(
				/* translators: %s: site name */
				__( 'Test email from %s', 'meil' ),
				get_bloginfo( 'name' )
			),
			__( 'This is a test email sent by the meil.ro plugin. If you received this, WordPress mail is going through meil.', 'meil' )
		);
		remove_action( 'wp_mail_failed', $capture );

		if ( $sent ) {
			wp_safe_redirect( add_query_arg( 'meil_test', 'sent', $redirect ) );
		} else {
			$message = $error instanceof WP_Error ? $error->get_error_message() : __( 'wp_mail() returned false.', 'meil' );
			wp_safe_redirect( add_query_arg( 'meil_test_error', rawurlencode( $message ), $redirect ) );
		}
		exit;
	}

	/**
	 * Renders a one-time notice from the query args handle_test_email()
	 * redirects back with. Read-only display of our own flags, not a form
	 * submission, so there is nothing here for a nonce to protect.
	 */
	public function render_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['meil_test'] ) && 'sent' === $_GET['meil_test'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Test email sent.', 'meil' ) . '</p></div>';
		} elseif ( isset( $_GET['meil_test'] ) && 'invalid_address' === $_GET['meil_test'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Enter a valid email address.', 'meil' ) . '</p></div>';
		} elseif ( isset( $_GET['meil_test_error'] ) ) {
			$message = sanitize_text_field( wp_unslash( (string) $_GET['meil_test_error'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html(
				sprintf(
					/* translators: %s: error message from the meil API */
					__( 'Test email failed: %s', 'meil' ),
					$message
				)
			) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}
}
