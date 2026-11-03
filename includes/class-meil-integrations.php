<?php
/**
 * meil.ro > Form plugins: connects forms made with Contact Form 7, WPForms,
 * Fluent Forms, Gravity Forms or Elementor Pro to meil.ro audiences, so the
 * forms a site already has can sign people up.
 *
 * For each form: the audiences, which field holds the email address (and the
 * name, if any), and who joins: only those who tick a checkbox of the form
 * (the usual case: "send me news" on a contact form), or everyone who sends
 * it (only for a form made to sign up). Confirmation by email is on unless
 * turned off. The checkbox's own text is saved with the contact as what the
 * person agreed to.
 *
 * Sign-ups go through Meil_Queue: the form plugin's sending never waits on
 * meil.ro and never fails because of it. Whatever goes wrong in here is
 * written to the PHP error log and the form goes on as usual.
 *
 * Needs a "Full access" API key, like the plugin's own forms.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Integrations {

	public const PAGE = 'meil-form-plugins';

	/** The connections: "plugin:form id" => settings. */
	private const OPTION = 'meil_integrations';

	private const NONCE = 'meil_integration_save';

	/** @var Meil_Client */
	private $client;

	/** @var Meil_Queue */
	private $queue;

	/** @var array<string,Meil_Integration> */
	private $plugins = array();

	public function __construct( Meil_Client $client, Meil_Queue $queue ) {
		$this->client = $client;
		$this->queue  = $queue;
		foreach ( array( new Meil_Integration_CF7(), new Meil_Integration_WPForms(), new Meil_Integration_Fluent(), new Meil_Integration_Gravity(), new Meil_Integration_Elementor() ) as $plugin ) {
			$this->plugins[ $plugin->key() ] = $plugin;
		}

		// Listen only to plugins that have a connected form.
		if ( $client->is_configured() && meil_key_can( 'manage' ) ) {
			$used = array_unique( array_map( static function ( string $key ): string {
				return (string) strtok( $key, ':' );
			}, array_keys( self::connections() ) ) );
			foreach ( $used as $key ) {
				$plugin = $this->plugins[ $key ] ?? null;
				if ( $plugin && $plugin->active() ) {
					add_action(
						$plugin->hook(),
						function ( ...$args ) use ( $plugin ): void {
							try {
								$read = $plugin->read( $args );
								if ( null !== $read ) {
									$this->sent( $plugin->key(), $read[0], $read[1] );
								}
							} catch ( \Throwable $e ) {
								// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the visitor's form must go on; the admin finds why here.
								error_log( 'meil.ro form plugins (' . $plugin->name() . '): ' . $e->getMessage() );
							}
						},
						10,
						$plugin->hook_args()
					);
				}
			}
		}

		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_menu' ), 15 );
			add_action( 'admin_post_' . self::NONCE, array( $this, 'handle_save' ) );
		}
	}

	/** @return array<string,array<string,mixed>> */
	public static function connections(): array {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/* ------------------------------------------------------------ sending */

	/**
	 * A connected form was sent: sign the person up when the connection says
	 * so. Runs inside the try of the hook set in the constructor.
	 *
	 * @param array<string,mixed> $values
	 */
	private function sent( string $plugin, string $form_id, array $values ): void {
		$connection = self::connections()[ $plugin . ':' . $form_id ] ?? null;
		if ( ! is_array( $connection ) || array() === (array) ( $connection['audiences'] ?? array() ) || '' === (string) ( $connection['email'] ?? '' ) ) {
			return;
		}
		$email = strtolower( sanitize_email( self::text( $values[ $connection['email'] ] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			return;
		}
		if ( empty( $connection['everyone'] ) && ( '' === (string) $connection['consent'] || ! Meil_Integration::ticked( $values[ $connection['consent'] ] ?? '' ) ) ) {
			return; // They did not tick it.
		}
		if ( ! Meil_Forms::within_signup_limit() ) {
			return;
		}

		$confirm    = ! empty( $connection['confirm'] );
		$properties = array(
			'source'      => $plugin,
			'signup_text' => ! empty( $connection['everyone'] )
				/* translators: %s: the form's title */
				? sprintf( __( 'Sent the form "%s"', 'meilro' ), (string) $connection['title'] )
				: (string) $connection['consent_label'],
		);
		if ( ! $confirm ) {
			$properties = array(
				'consent_timestamp' => gmdate( 'c' ),
				'consent_ip'        => meil_visitor_ip(),
			) + $properties;
		}
		$payload = array(
			'audience_ids' => array_values( array_map( 'strval', (array) $connection['audiences'] ) ),
			'email'        => $email,
			'properties'   => $properties,
		);
		foreach ( array( 'first' => 'first_name', 'last' => 'last_name' ) as $field => $name ) {
			$text = '' !== (string) ( $connection[ $field ] ?? '' ) ? self::text( $values[ $connection[ $field ] ] ?? '' ) : '';
			if ( '' !== $text ) {
				$payload[ $name ] = mb_substr( $text, 0, 100 );
			}
		}
		if ( $confirm ) {
			$payload['confirm']  = true;
			$payload['language'] = 0 === strpos( determine_locale(), 'ro' ) ? 'ro' : 'en';
		}
		$this->queue->deliver( 'contact', $payload, $email, '' );
	}

	/**
	 * One text out of a field's value: the first one of a list.
	 *
	 * @param mixed $value
	 */
	private static function text( $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return is_scalar( $value ) ? trim( sanitize_text_field( (string) $value ) ) : '';
	}

	/* -------------------------------------------------------------- admin */

	public function register_menu(): void {
		add_submenu_page( 'meil-mailer', __( 'Form plugins', 'meilro' ), __( 'Form plugins', 'meilro' ), 'manage_options', self::PAGE, array( $this, 'render_page' ) );
	}

	/** The plugin's forms, or the error that kept them from being read. */
	private function forms_of( Meil_Integration $plugin ) {
		try {
			return $plugin->forms();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'meil_forms', $e->getMessage() );
		}
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$active = array_filter(
			$this->plugins,
			static function ( Meil_Integration $plugin ): bool {
				return $plugin->active();
			}
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which form to show, and the result of the last save.
		$edit   = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['edit'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$result = isset( $_GET['meil_saved'] ) ? sanitize_key( wp_unslash( (string) $_GET['meil_saved'] ) ) : '';
		?>
		<div class="wrap meil-app">
			<?php meil_admin_head( __( 'Form plugins', 'meilro' ), __( 'Sign people up from forms you already have. Pick the audiences, the field with the email address, and who joins: those who tick a checkbox of the form, or everyone who sends it.', 'meilro' ) ); ?>
			<?php
			$notices = array(
				'saved'        => array( 'success', __( 'Saved.', 'meilro' ) ),
				'disconnected' => array( 'success', __( 'This form no longer signs anyone up.', 'meilro' ) ),
				'incomplete'   => array( 'error', __( 'Pick at least one audience, the email field, and who joins.', 'meilro' ) ),
			);
			if ( isset( $notices[ $result ] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notices[ $result ][0] ), esc_html( $notices[ $result ][1] ) );
			}
			?>
			<?php if ( ! $this->client->is_configured() ) : ?>
				<p><?php esc_html_e( 'Add an API key under meil.ro > Settings first.', 'meilro' ); ?></p>
			<?php elseif ( ! meil_key_can( 'manage' ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Connecting forms needs a "Full access" API key. Change it under meil.ro > Settings.', 'meilro' ); ?></p></div>
			<?php elseif ( array() === $active ) : ?>
				<p>
					<?php
					/* translators: %s: list of form plugins */
					echo esc_html( sprintf( __( 'None of the form plugins this works with is active: %s.', 'meilro' ), implode( ', ', array_map( static function ( Meil_Integration $plugin ): string {
						return $plugin->name();
					}, $this->plugins ) ) ) );
					?>
				</p>
			<?php elseif ( '' !== $edit && isset( $active[ (string) strtok( $edit, ':' ) ] ) ) : ?>
				<?php $this->render_edit( $active[ (string) strtok( $edit, ':' ) ], $edit ); ?>
			<?php else : ?>
				<?php $this->render_list( $active ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,Meil_Integration> $active */
	private function render_list( array $active ): void {
		$connections = self::connections();
		$names       = $this->audience_names();
		foreach ( $active as $plugin ) {
			$forms = $this->forms_of( $plugin );
			echo '<div class="meil-panel"><div class="meil-panel__head"><h2 class="meil-panel__title">' . esc_html( $plugin->name() ) . '</h2></div>';
			if ( is_wp_error( $forms ) ) {
				/* translators: %s: error message */
				echo '<div class="meil-panel__body"><div class="notice notice-error inline"><p>' . esc_html( sprintf( __( 'Could not read its forms: %s', 'meilro' ), $forms->get_error_message() ) ) . '</p></div></div></div>';
				continue;
			}
			if ( array() === $forms ) {
				echo '<div class="meil-panel__body"><p class="meil-empty">' . esc_html__( 'It has no forms yet.', 'meilro' ) . '</p></div></div>';
				continue;
			}
			?>
			<div class="meil-panel__body meil-panel__body--tight">
			<table class="meil-table meil-connections">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'Form', 'meilro' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Signs up to', 'meilro' ); ?></th>
					<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'meilro' ); ?></span></th>
				</tr></thead>
				<tbody>
					<?php
					foreach ( $forms as $form ) :
						$key        = $plugin->key() . ':' . $form['id'];
						$connection = $connections[ $key ] ?? null;
						?>
						<tr>
							<td><?php echo esc_html( '' !== $form['title'] ? $form['title'] : $form['id'] ); ?></td>
							<td class="<?php echo is_array( $connection ) ? '' : 'meil-table__muted'; ?>">
								<?php
								echo esc_html(
									is_array( $connection )
										? implode( ', ', array_map( static function ( string $id ) use ( $names ): string {
											return $names[ $id ] ?? $id;
										}, (array) $connection['audiences'] ) )
										: __( 'Not connected', 'meilro' )
								);
								?>
							</td>
							<td class="meil-connections__actions">
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'edit' => $key ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( is_array( $connection ) ? __( 'Change', 'meilro' ) : __( 'Connect', 'meilro' ) ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div></div>
			<?php
		}
	}

	/** The account's audiences by id, for showing names. @return array<string,string> */
	private function audience_names(): array {
		$audiences = $this->client->list_audiences();
		return is_wp_error( $audiences ) ? array() : array_column( (array) ( $audiences['data'] ?? array() ), 'name', 'id' );
	}

	private function render_edit( Meil_Integration $plugin, string $key ): void {
		$forms = $this->forms_of( $plugin );
		$form  = null;
		foreach ( is_wp_error( $forms ) ? array() : $forms as $candidate ) {
			if ( $plugin->key() . ':' . $candidate['id'] === $key ) {
				$form = $candidate;
			}
		}
		$back = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		if ( null === $form ) {
			echo '<p>' . esc_html__( 'This form was not found. It may have been deleted.', 'meilro' ) . ' <a href="' . esc_url( $back ) . '">' . esc_html__( 'Back to the list', 'meilro' ) . '</a></p>';
			return;
		}
		$audiences  = $this->client->list_audiences();
		$saved      = self::connections()[ $key ] ?? array();
		$connection = wp_parse_args(
			$saved,
			array(
				'audiences' => array(),
				'email'     => '',
				'first'     => '',
				'last'      => '',
				'consent'   => '',
				'everyone'  => false,
				'confirm'   => true,
			)
		);
		// A first guess for a new connection: its first email field and first checkbox.
		foreach ( $form['fields'] as $field ) {
			if ( '' === $connection['email'] && 'email' === $field['type'] && array() === $saved ) {
				$connection['email'] = $field['id'];
			}
		}
		$choices = static function ( array $fields, array $types ): array {
			return array_values( array_filter( $fields, static function ( array $field ) use ( $types ): bool {
				return in_array( $field['type'], $types, true );
			} ) );
		};
		$select  = static function ( string $name, string $current, array $fields, string $none ): void {
			printf( '<select name="%s" id="meil-int-%s">', esc_attr( $name ), esc_attr( $name ) );
			if ( '' !== $none ) {
				printf( '<option value="">%s</option>', esc_html( $none ) );
			}
			foreach ( $fields as $field ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $field['id'] ), selected( $current, $field['id'], false ), esc_html( $field['label'] ) );
			}
			echo '</select>';
		};
		$checkboxes = $choices( $form['fields'], array( 'checkbox' ) );
		?>
		<p class="meil-back"><a href="<?php echo esc_url( $back ); ?>">&larr; <?php esc_html_e( 'Back to the list', 'meilro' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="meil-stack">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::NONCE ); ?>" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<?php wp_nonce_field( self::NONCE ); ?>
			<div class="meil-panel">
				<div class="meil-panel__head"><h2 class="meil-panel__title"><?php echo esc_html( $plugin->name() . ': ' . ( '' !== $form['title'] ? $form['title'] : $form['id'] ) ); ?></h2></div>
				<div class="meil-panel__body">
					<div class="meil-field">
						<span class="meil-field__label"><?php esc_html_e( 'Add to', 'meilro' ); ?></span>
						<div class="meil-field__control">
							<?php if ( is_wp_error( $audiences ) ) : ?>
								<?php /* translators: %s: error message from the meil API */ ?>
								<p class="description"><?php echo esc_html( sprintf( __( 'Could not reach meil: %s', 'meilro' ), $audiences->get_error_message() ) ); ?></p>
							<?php else : ?>
								<ul class="meil-signup-list">
									<?php foreach ( (array) ( $audiences['data'] ?? array() ) as $audience ) : ?>
										<li><label><input type="checkbox" name="audiences[]" value="<?php echo esc_attr( (string) $audience['id'] ); ?>" <?php checked( in_array( (string) $audience['id'], (array) $connection['audiences'], true ) ); ?> /> <?php echo esc_html( (string) $audience['name'] ); ?></label></li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</div>
					</div>
					<div class="meil-field">
						<label class="meil-field__label" for="meil-int-email"><?php esc_html_e( 'Email address', 'meilro' ); ?></label>
						<div class="meil-field__control"><?php $select( 'email', (string) $connection['email'], array_merge( $choices( $form['fields'], array( 'email' ) ), $choices( $form['fields'], array( 'text', 'other' ) ) ), __( 'Choose a field', 'meilro' ) ); ?></div>
					</div>
					<div class="meil-field-row">
						<div class="meil-field">
							<label class="meil-field__label" for="meil-int-first"><?php esc_html_e( 'First name', 'meilro' ); ?></label>
							<div class="meil-field__control">
								<?php $select( 'first', (string) $connection['first'], $choices( $form['fields'], array( 'text', 'other' ) ), __( 'None', 'meilro' ) ); ?>
								<p class="description"><?php esc_html_e( 'A field with the whole name works here too.', 'meilro' ); ?></p>
							</div>
						</div>
						<div class="meil-field">
							<label class="meil-field__label" for="meil-int-last"><?php esc_html_e( 'Last name', 'meilro' ); ?></label>
							<div class="meil-field__control"><?php $select( 'last', (string) $connection['last'], $choices( $form['fields'], array( 'text', 'other' ) ), __( 'None', 'meilro' ) ); ?></div>
						</div>
					</div>
					<div class="meil-field">
						<span class="meil-field__label"><?php esc_html_e( 'Who joins', 'meilro' ); ?></span>
						<div class="meil-field__control">
							<fieldset class="meil-choices">
								<label class="meil-choice"><input type="radio" name="who" value="ticked" <?php checked( empty( $connection['everyone'] ) ); ?> <?php disabled( array() === $checkboxes ); ?> /> <?php esc_html_e( 'Only those who tick this checkbox:', 'meilro' ); ?></label>
								<?php if ( array() !== $checkboxes ) : ?>
									<div class="meil-choice__more"><?php $select( 'consent', (string) $connection['consent'], $checkboxes, '' ); ?></div>
								<?php else : ?>
									<p class="description"><?php esc_html_e( 'This form has no checkbox. Add one in the form (for example "Send me news by email"), then come back.', 'meilro' ); ?></p>
								<?php endif; ?>
								<label class="meil-choice"><input type="radio" name="who" value="everyone" <?php checked( ! empty( $connection['everyone'] ) || array() === $checkboxes ); ?> /> <?php esc_html_e( 'Everyone who sends the form', 'meilro' ); ?></label>
								<p class="description"><?php esc_html_e( 'Only for a form made to sign up, where sending it is the request. A contact form needs the checkbox: people who only wrote to you have not asked for your emails.', 'meilro' ); ?></p>
							</fieldset>
						</div>
					</div>
					<div class="meil-field">
						<span class="meil-field__label"><?php esc_html_e( 'Confirmation', 'meilro' ); ?></span>
						<div class="meil-field__control"><label><input type="checkbox" name="confirm" value="1" <?php checked( ! empty( $connection['confirm'] ) ); ?> /> <?php esc_html_e( 'Ask them to confirm by email before they are subscribed (recommended)', 'meilro' ); ?></label></div>
					</div>
				</div>
			</div>
			<p class="meil-actions">
				<button type="submit" name="do" value="save" class="button button-primary"><?php esc_html_e( 'Save', 'meilro' ); ?></button>
				<?php if ( array() !== $saved ) : ?>
					<button type="submit" name="do" value="disconnect" class="button-link button-link-delete"><?php esc_html_e( 'Disconnect', 'meilro' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'meilro' ) );
		}
		check_admin_referer( self::NONCE );
		$key         = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';
		$connections = self::connections();
		$back        = add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) );
		$plugin      = $this->plugins[ (string) strtok( $key, ':' ) ] ?? null;

		if ( isset( $_POST['do'] ) && 'disconnect' === $_POST['do'] ) {
			unset( $connections[ $key ] );
			update_option( self::OPTION, $connections );
			wp_safe_redirect( add_query_arg( 'meil_saved', 'disconnected', $back ) );
			exit;
		}

		// The form as its plugin has it now: only its own fields can be picked.
		$form = null;
		foreach ( $plugin && $plugin->active() ? (array) $this->forms_of( $plugin ) : array() as $candidate ) {
			if ( is_array( $candidate ) && $plugin->key() . ':' . $candidate['id'] === $key ) {
				$form = $candidate;
			}
		}
		$fields   = null !== $form ? array_column( $form['fields'], null, 'id' ) : array();
		$pick     = static function ( string $name ) use ( $fields ): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$value = isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $name ] ) ) : '';
			return isset( $fields[ $value ] ) ? $value : '';
		};
		$everyone = isset( $_POST['who'] ) && 'everyone' === $_POST['who'];
		$consent  = $everyone ? '' : $pick( 'consent' );
		$audiences = isset( $_POST['audiences'] ) && is_array( $_POST['audiences'] ) ? array_slice( array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['audiences'] ) ) ) ), 0, 20 ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the map.
		$email     = $pick( 'email' );

		if ( null === $form || array() === $audiences || '' === $email || ( ! $everyone && '' === $consent ) ) {
			wp_safe_redirect( add_query_arg( array( 'edit' => $key, 'meil_saved' => 'incomplete' ), $back ) );
			exit;
		}
		$connections[ $key ] = array(
			'title'         => (string) $form['title'],
			'audiences'     => $audiences,
			'email'         => $email,
			'first'         => $pick( 'first' ),
			'last'          => $pick( 'last' ),
			'consent'       => $consent,
			// What the person ticked, kept with each contact as what they agreed to.
			'consent_label' => '' !== $consent ? ( '' !== (string) ( $fields[ $consent ]['text'] ?? '' ) ? (string) $fields[ $consent ]['text'] : (string) $fields[ $consent ]['label'] ) : '',
			'everyone'      => $everyone,
			'confirm'       => ! empty( $_POST['confirm'] ),
		);
		update_option( self::OPTION, $connections );
		wp_safe_redirect( add_query_arg( 'meil_saved', 'saved', $back ) );
		exit;
	}
}
