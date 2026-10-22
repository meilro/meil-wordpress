<?php
/**
 * Settings screen: meil.ro.
 *
 * Standard Settings API (options.php) for the connection fields, a separate
 * admin-post.php handler for the "send a test email" tool, links into the
 * meil.ro dashboard, and pointers to the forms and the preferences page.
 *
 * Saving a key asks meil.ro which domain it belongs to (GET /key). A key for
 * another site, or a default from address off the key's domain, is refused
 * with an error and the old settings stay, so nothing breaks later in front
 * of visitors.
 *
 * @package Meil_Mailer
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
		// The settings come first under the menu, so the menu link opens them.
		// Without this item WordPress lists only Forms, and the link goes there.
		add_submenu_page(
			self::PAGE,
			__( 'Settings', 'meil' ),
			__( 'Settings', 'meil' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_settings_page' )
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
				echo '<p>' . esc_html__( "Enter an API key created under API Keys in meil.ro for this site's domain. A \"Transactional only\" key is enough for WordPress mail; forms and the preferences page need \"Full access\".", 'meil' ) . '</p>';
			},
			self::PAGE
		);

		$fields = array(
			'api_key'          => __( 'API key', 'meil' ),
			'from_email'       => __( 'Default from address', 'meil' ),
			'from_name'        => __( 'Default from name', 'meil' ),
			'override_wp_mail' => __( 'Send WordPress mail through meil', 'meil' ),
			'load_css'         => __( 'Form styles', 'meil' ),
			'delete_with_user' => __( 'Deleted users', 'meil' ),
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

	/**
	 * Checks a new set of settings with meil.ro before they are stored. On a
	 * problem it adds an error for the screen and returns the old settings.
	 *
	 * @param array<string,mixed> $input
	 */
	public function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$previous = get_option( $this->option_name, array() );
		$previous = is_array( $previous ) ? $previous : array();

		$out = array(
			'api_key'          => sanitize_text_field( (string) ( $input['api_key'] ?? '' ) ),
			'from_email'       => sanitize_email( (string) ( $input['from_email'] ?? '' ) ),
			'from_name'        => sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ),
			'override_wp_mail' => ! empty( $input['override_wp_mail'] ),
			'load_css'         => ! empty( $input['load_css'] ),
			'delete_with_user' => ! empty( $input['delete_with_user'] ),
			'key_domain'       => '',
			'key_permission'   => '',
		);
		if ( '' === $out['api_key'] ) {
			return $out;
		}

		$key = $this->check_key( $out['api_key'] );
		if ( is_string( $key ) ) {
			$this->refuse( $key );
			return $previous;
		}
		$out['key_domain']     = $key['domain'];
		$out['key_permission'] = $key['permission'];
		return $out;
	}

	/**
	 * What meil.ro says about a key: its domain and permission, or why it
	 * cannot be used. Asked once per request: WordPress may run the sanitize
	 * callback twice on the first save.
	 *
	 * @return array{domain:string,permission:string}|string
	 */
	private function check_key( string $api_key ) {
		static $seen = array();
		if ( isset( $seen[ $api_key ] ) ) {
			return $seen[ $api_key ];
		}

		$result = ( new Meil_Client( $api_key ) )->get_key();
		if ( is_wp_error( $result ) ) {
			$status             = Meil_Client::status( $result );
			$seen[ $api_key ] = 401 === $status
				? __( 'This API key is not valid. Copy it again from API Keys in meil.ro.', 'meil' )
				/* translators: %s: error message from the meil API */
				: sprintf( __( 'Could not check the API key with meil.ro: %s', 'meil' ), $result->get_error_message() );
			return $seen[ $api_key ];
		}

		$seen[ $api_key ] = array(
			'domain'     => strtolower( (string) ( $result['domain'] ?? '' ) ),
			'permission' => (string) ( $result['permission'] ?? '' ),
		);
		return $seen[ $api_key ];
	}

	/** Shows an error on the settings screen, once. */
	private function refuse( string $message ): void {
		static $shown = array();
		if ( ! isset( $shown[ $message ] ) ) {
			$shown[ $message ] = true;
			add_settings_error( $this->option_name, 'meil_settings', $message );
		}
	}

	/** The permission names as the meil.ro dashboard shows them. */
	private static function permission_label( string $permission ): string {
		$labels = array(
			'full_access'      => __( 'Full access', 'meil' ),
			'broadcast_access' => __( 'Emails and broadcasts', 'meil' ),
			'email_access'     => __( 'Transactional only', 'meil' ),
		);
		return $labels[ $permission ] ?? $permission;
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
				if ( '' !== $settings['key_permission'] ) {
					echo '<p class="description">' . esc_html(
						'' !== $settings['key_domain']
							? sprintf(
								/* translators: 1: the API key's domain, 2: its permission, e.g. "Full access" */
								__( 'Works with %1$s, %2$s.', 'meil' ),
								$settings['key_domain'],
								self::permission_label( $settings['key_permission'] )
							)
							: sprintf(
								/* translators: %s: the API key's permission, e.g. "Full access" */
								__( 'Works with all your domains, %s.', 'meil' ),
								self::permission_label( $settings['key_permission'] )
							)
					) . '</p>';
				}
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
			case 'load_css':
			case 'delete_with_user':
				$texts = array(
					'override_wp_mail' => __( 'Replace wp_mail() delivery with the meil API (recommended). Leave unchecked to use meil for forms only.', 'meil' ),
					'load_css'         => __( "Load the plugin's styles for forms and the preferences page. Turn off to style them entirely from your theme.", 'meil' ),
					'delete_with_user' => __( 'When a WordPress user is deleted, delete their meil.ro contact too, with its data.', 'meil' ),
				);
				printf(
					'<label><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( ! empty( $value ), true, false ),
					esc_html( $texts[ $field ] )
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

			<?php settings_errors( $this->option_name ); ?>
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
				<?php $this->render_forms_help( $settings ); ?>
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

	/**
	 * Where the forms are built, and how to place the preferences page.
	 *
	 * @param array<string,mixed> $settings
	 */
	private function render_forms_help( array $settings ): void {
		?>
		<h2><?php esc_html_e( 'Subscription forms', 'meil' ); ?></h2>
		<?php if ( 'full_access' !== $settings['key_permission'] ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Forms and the preferences page need a "Full access" API key.', 'meil' ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Build forms with the audiences, labels and confirmation you want, then place each one with its shortcode.', 'meil' ); ?></p>
		<p><a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . Meil_Forms::POST_TYPE ) ); ?>"><?php esc_html_e( 'Manage forms', 'meil' ); ?></a></p>

		<h2><?php esc_html_e( 'Email preferences', 'meil' ); ?></h2>
		<p>
			<?php
			if ( '' !== $settings['key_domain'] ) {
				printf(
					/* translators: 1: the shortcode, 2: the API key's domain */
					esc_html__( 'Put %1$s on an account page: logged-in users see the public audiences of %2$s and choose which ones they get. Visitors who are not logged in see a message instead.', 'meil' ),
					'<code>[meil_preferences]</code>',
					esc_html( $settings['key_domain'] )
				);
			} else {
				printf(
					/* translators: %s: the shortcode */
					esc_html__( 'Put %s on an account page: logged-in users see the public audiences of all your domains and choose which ones they get. Visitors who are not logged in see a message instead.', 'meil' ),
					'<code>[meil_preferences]</code>'
				);
			}
			?>
		</p>
		<?php
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
