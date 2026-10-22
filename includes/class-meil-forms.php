<?php
/**
 * Subscription forms: built under meil.ro > Forms, placed with [meil_form id="…"].
 *
 * Each form is a post of a private type (meil_form) whose settings live in one
 * meta field: the audiences it offers, each with its own label and whether it
 * starts ticked, whether to ask for a first name, whether to ask for a
 * confirmation by email, the page to land on after confirming, and its texts.
 *
 * The form is sent in the background (admin-ajax.php, see assets/forms.js) and
 * the answer replaces it in place; without JavaScript it posts to the page.
 *
 * There is no nonce on the public form on purpose: most hosts cache pages, so
 * a nonce baked into the HTML expires and signups fail silently. A hidden
 * honeypot field turns away the simple bots instead, and the confirmation
 * email (when on) keeps the rest off the list.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Forms {

	public const POST_TYPE = 'meil_form';

	private const AJAX_ACTION = 'meil_form_submit';

	/** At most this many extra checkboxes (terms, privacy, offers...) on one form. */
	private const MAX_CHECKBOXES = 5;

	/** Signups one visitor IP may send in SIGNUP_WINDOW seconds, across all forms. */
	private const SIGNUP_LIMIT  = 5;
	private const SIGNUP_WINDOW = 600;

	private const META     = '_meil_form';
	private const NONCE    = 'meil_form_save';
	private const HONEYPOT = 'meil_form_website';

	/** @var Meil_Client */
	private $client;

	public function __construct( Meil_Client $client ) {
		$this->client = $client;

		add_action( 'init', array( $this, 'register_post_type' ) );
		add_shortcode( 'meil_form', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_ajax' ) );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_boxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save' ), 10, 2 );
			add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
			add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
			add_filter( 'post_updated_messages', array( $this, 'updated_messages' ) );
		}
	}

	/** A form's settings, with every key present. */
	public static function settings( int $form_id ): array {
		$saved = get_post_meta( $form_id, self::META, true );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'audiences'     => array(),
				'ask_name'      => false,
				'ask_last_name' => false,
				'confirm'       => true,
				'thanks_page'   => 0,
				'checkboxes'    => array(),
				'button'        => '',
				'placeholder'   => '',
				'success'       => '',
				'pending'       => '',
			)
		);
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Forms', 'meil' ),
					'singular_name'      => __( 'Form', 'meil' ),
					'add_new'            => __( 'Add form', 'meil' ),
					'add_new_item'       => __( 'Add form', 'meil' ),
					'edit_item'          => __( 'Edit form', 'meil' ),
					'new_item'           => __( 'New form', 'meil' ),
					'search_items'       => __( 'Search forms', 'meil' ),
					'not_found'          => __( 'No forms yet.', 'meil' ),
					'not_found_in_trash' => __( 'No forms in the trash.', 'meil' ),
					'all_items'          => __( 'Forms', 'meil' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'meil-mailer',
				'show_in_rest'    => false,
				'supports'        => array( 'title' ),
				'map_meta_cap'    => false,
				'capabilities'    => array(
					'edit_post'          => 'manage_options',
					'read_post'          => 'manage_options',
					'delete_post'        => 'manage_options',
					'edit_posts'         => 'manage_options',
					'edit_others_posts'  => 'manage_options',
					'delete_posts'       => 'manage_options',
					'publish_posts'      => 'manage_options',
					'read_private_posts' => 'manage_options',
					'create_posts'       => 'manage_options',
				),
			)
		);
	}

	/* ------------------------------------------------------------------ admin */

	public function add_meta_boxes(): void {
		// The checkbox texts are edited with WordPress's own editor (bold,
		// italic, links), created by assets/admin-forms.js, also for new rows.
		wp_enqueue_editor();
		wp_enqueue_script( 'meil-admin-forms', MEIL_MAILER_URL . 'assets/admin-forms.js', array(), meil_asset_version( 'assets/admin-forms.js' ), true );

		add_meta_box( 'meil-form-settings', __( 'Form settings', 'meil' ), array( $this, 'render_settings_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'meil-form-shortcode', __( 'Shortcode', 'meil' ), array( $this, 'render_shortcode_box' ), self::POST_TYPE, 'side', 'high' );
	}

	public function render_shortcode_box( WP_Post $post ): void {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the form to get its shortcode.', 'meil' ) . '</p>';
			return;
		}
		echo '<p>' . esc_html__( 'Paste it into any page, post or widget area:', 'meil' ) . '</p>';
		printf( '<p><input type="text" class="widefat code" readonly value="%s" onfocus="this.select()" /></p>', esc_attr( self::shortcode( $post->ID ) ) );
	}

	/** The shortcode that places a form. */
	public static function shortcode( int $form_id ): string {
		return '[meil_form id="' . $form_id . '"]';
	}

	public function render_settings_box( WP_Post $post ): void {
		$form = self::settings( $post->ID );
		wp_nonce_field( self::NONCE, 'meil_form_nonce' );

		$plugin    = meil_mailer_settings();
		$audiences = $this->client->is_configured() ? $this->client->list_audiences() : null;
		$chosen    = array();
		foreach ( $form['audiences'] as $row ) {
			$chosen[ $row['id'] ] = $row;
		}
		?>
		<div class="meil-fa">

			<section class="meil-fa-section">
				<h3 class="meil-fa-section__title"><?php esc_html_e( 'Audiences', 'meil' ); ?></h3>
				<?php if ( null === $audiences ) : ?>
					<p><?php esc_html_e( 'Add an API key under meil.ro > Settings first.', 'meil' ); ?></p>
				<?php elseif ( is_wp_error( $audiences ) ) : ?>
					<div class="notice notice-error inline"><p>
						<?php
						echo esc_html(
							403 === Meil_Client::status( $audiences )
								? __( 'Forms need a "Full access" API key. Change it under meil.ro > Settings.', 'meil' )
								/* translators: %s: error message from the meil API */
								: sprintf( __( 'Could not reach meil: %s', 'meil' ), $audiences->get_error_message() )
						);
						?>
					</p></div>
				<?php elseif ( array() === ( $audiences['data'] ?? array() ) ) : ?>
					<p>
						<?php
						if ( '' !== $plugin['key_domain'] ) {
							printf(
								/* translators: %s: sending domain */
								esc_html__( 'There are no audiences on %s yet.', 'meil' ),
								esc_html( $plugin['key_domain'] )
							);
						} else {
							esc_html_e( 'There are no audiences yet.', 'meil' );
						}
						?>
						<a href="<?php echo esc_url( MEIL_MAILER_APP_URL . '/audiences' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Create one in meil.ro', 'meil' ); ?></a>
					</p>
				<?php else : ?>
					<p class="meil-fa-section__lead"><?php esc_html_e( 'Tick the audiences this form offers and write the label visitors see for each. With more than one, visitors choose with checkboxes; with one, the form only asks for the email address.', 'meil' ); ?></p>
					<ul class="meil-fa-audiences">
						<?php
						foreach ( $audiences['data'] as $audience ) :
							$id    = (string) ( $audience['id'] ?? '' );
							$name  = (string) ( $audience['name'] ?? '' );
							$row   = $chosen[ $id ] ?? null;
							$field = 'meil_audiences[' . $id . ']';
							$slug  = 'meil-aud-' . md5( $id );
							?>
							<li class="meil-fa-audience">
								<input type="hidden" name="<?php echo esc_attr( $field . '[name]' ); ?>" value="<?php echo esc_attr( $name ); ?>" />
								<label class="meil-fa-audience__offer">
									<input type="checkbox" class="meil-fa-audience__toggle" name="<?php echo esc_attr( $field . '[on]' ); ?>" value="1" <?php checked( null !== $row ); ?> />
									<span class="meil-fa-audience__name"><?php echo esc_html( $name ); ?></span>
									<?php if ( 'private' === ( $audience['visibility'] ?? '' ) ) : ?>
										<span class="meil-fa-tag"><?php esc_html_e( 'private', 'meil' ); ?></span>
									<?php endif; ?>
								</label>
								<span class="meil-fa-audience__details">
									<label class="screen-reader-text" for="<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Label on the form', 'meil' ); ?></label>
									<input type="text" id="<?php echo esc_attr( $slug ); ?>" class="meil-fa-audience__label" name="<?php echo esc_attr( $field . '[label]' ); ?>" value="<?php echo esc_attr( null !== $row ? $row['label'] : '' ); ?>" placeholder="<?php echo esc_attr( $name ); ?>" />
									<label class="meil-fa-inline">
										<input type="checkbox" name="<?php echo esc_attr( $field . '[checked]' ); ?>" value="1" <?php checked( null !== $row && ! empty( $row['checked'] ) ); ?> />
										<?php esc_html_e( 'Ticked at first', 'meil' ); ?>
									</label>
								</span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</section>

			<section class="meil-fa-section">
				<h3 class="meil-fa-section__title"><?php esc_html_e( 'Checkboxes', 'meil' ); ?></h3>
				<p class="meil-fa-section__lead"><?php esc_html_e( 'For your terms, your privacy policy, or anything visitors agree to. Use the link button to point to your pages. Checkboxes start unticked, and each answer is saved on the contact, with the text the visitor saw.', 'meil' ); ?></p>
				<div class="meil-fa-cards" data-meil-checkboxes data-next="<?php echo esc_attr( (string) count( $form['checkboxes'] ) ); ?>" data-max="<?php echo esc_attr( (string) self::MAX_CHECKBOXES ); ?>">
					<?php
					foreach ( array_values( $form['checkboxes'] ) as $i => $checkbox ) {
						self::render_checkbox_row( (string) $i, $i + 1, $checkbox );
					}
					?>
				</div>
				<p class="meil-fa-empty"><?php esc_html_e( 'No checkboxes yet.', 'meil' ); ?></p>
				<template data-meil-checkbox-template>
					<?php
					self::render_checkbox_row(
						'__i__',
						0,
						array(
							'text'     => '',
							'required' => false,
							'property' => '',
						)
					);
					?>
				</template>
				<p class="meil-fa-add">
					<button type="button" class="button" data-meil-add>
						<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
						<?php esc_html_e( 'Add checkbox', 'meil' ); ?>
					</button>
				</p>
			</section>

			<section class="meil-fa-section">
				<h3 class="meil-fa-section__title"><?php esc_html_e( 'Signing up', 'meil' ); ?></h3>
				<table class="form-table meil-fa-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Fields', 'meil' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="meil_ask_name" value="1" <?php checked( $form['ask_name'] ); ?> /> <?php esc_html_e( 'Also ask for a first name', 'meil' ); ?></label><br />
								<label><input type="checkbox" name="meil_ask_last_name" value="1" <?php checked( $form['ask_last_name'] ); ?> /> <?php esc_html_e( 'Also ask for a last name', 'meil' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Confirmation', 'meil' ); ?></th>
						<td>
							<label><input type="checkbox" id="meil-confirm" name="meil_confirm" value="1" <?php checked( $form['confirm'] ); ?> /> <?php esc_html_e( 'Ask visitors to confirm by email before they are subscribed (recommended)', 'meil' ); ?></label>
							<p class="description"><?php esc_html_e( 'They get one email and are subscribed only after pressing its button, which keeps typos and bots off your list and records when and from where they confirmed.', 'meil' ); ?></p>
						</td>
					</tr>
					<tr class="meil-fa-thanks">
						<th scope="row"><label for="meil-thanks-page"><?php esc_html_e( 'Page they land on after confirming', 'meil' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'meil_thanks_page',
									'id'                => 'meil-thanks-page',
									'selected'          => (int) $form['thanks_page'],
									'show_option_none'  => esc_html__( "meil.ro's own confirmation page", 'meil' ),
									'option_none_value' => '0',
								)
							);
							?>
						</td>
					</tr>
				</table>
			</section>

			<section class="meil-fa-section">
				<h3 class="meil-fa-section__title"><?php esc_html_e( 'Texts', 'meil' ); ?></h3>
				<p class="meil-fa-section__lead"><?php esc_html_e( 'Leave a field empty to use the default, which follows the site language.', 'meil' ); ?></p>
				<table class="form-table meil-fa-table" role="presentation">
					<?php
					$texts = array(
						'button'      => array( __( 'Button', 'meil' ), self::default_text( 'button' ) ),
						'placeholder' => array( __( 'Email field placeholder', 'meil' ), self::default_text( 'placeholder' ) ),
						'success'     => array( __( 'Message after subscribing', 'meil' ), self::default_text( 'success' ) ),
						'pending'     => array( __( 'Message while waiting for the confirmation', 'meil' ), self::default_text( 'pending' ) ),
					);
					foreach ( $texts as $key => list( $label, $default ) ) :
						?>
						<tr>
							<th scope="row"><label for="meil-text-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td><input type="text" class="large-text" id="meil-text-<?php echo esc_attr( $key ); ?>" name="meil_text[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $form[ $key ] ); ?>" placeholder="<?php echo esc_attr( $default ); ?>" /></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</section>
		</div>
		<?php
	}

	/** The built-in texts, in the site's language. */
	public static function default_text( string $key ): string {
		switch ( $key ) {
			case 'button':
				return __( 'Subscribe', 'meil' );
			case 'placeholder':
				return __( 'you@example.com', 'meil' );
			case 'success':
				return __( 'Thank you, you are subscribed.', 'meil' );
			case 'pending':
				return __( 'Almost done: check your inbox and press the button in our email to confirm.', 'meil' );
		}
		return '';
	}

	public function save( int $post_id, WP_Post $post ): void {
		if ( ! isset( $_POST['meil_form_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['meil_form_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$audiences = array();
		$posted    = isset( $_POST['meil_audiences'] ) && is_array( $_POST['meil_audiences'] ) ? wp_unslash( $_POST['meil_audiences'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		foreach ( $posted as $id => $row ) {
			if ( ! is_array( $row ) || empty( $row['on'] ) ) {
				continue;
			}
			$audiences[] = array(
				'id'      => sanitize_text_field( (string) $id ),
				'name'    => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
				'label'   => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
				'checked' => ! empty( $row['checked'] ),
			);
		}

		$texts = array();
		$posted_texts = isset( $_POST['meil_text'] ) && is_array( $_POST['meil_text'] ) ? wp_unslash( $_POST['meil_text'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is sanitized below.
		foreach ( array( 'button', 'placeholder', 'success', 'pending' ) as $key ) {
			$texts[ $key ] = sanitize_text_field( (string) ( $posted_texts[ $key ] ?? '' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned by clean_checkboxes().
		$checkboxes = self::clean_checkboxes( isset( $_POST['meil_checkboxes'] ) ? wp_unslash( $_POST['meil_checkboxes'] ) : array() );

		update_post_meta(
			$post_id,
			self::META,
			array(
				'audiences'     => array_slice( $audiences, 0, 20 ),
				'ask_name'      => ! empty( $_POST['meil_ask_name'] ),
				'ask_last_name' => ! empty( $_POST['meil_ask_last_name'] ),
				'confirm'       => ! empty( $_POST['meil_confirm'] ),
				'thanks_page'   => isset( $_POST['meil_thanks_page'] ) ? absint( $_POST['meil_thanks_page'] ) : 0,
				'checkboxes'    => $checkboxes,
			) + $texts
		);
	}

	/**
	 * One checkbox in the form editor. $key is its index, or __i__ in the
	 * template new rows are made from; $number is what it is called by default.
	 *
	 * @param array<string,mixed> $checkbox
	 */
	private static function render_checkbox_row( string $key, int $number, array $checkbox ): void {
		$name        = 'meil_checkboxes[' . $key . ']';
		$placeholder = 'checkbox_' . ( $number > 0 ? $number : '__n__' );
		?>
		<div class="meil-fa-card" data-meil-checkbox>
			<div class="meil-fa-card__head">
				<span class="meil-fa-card__title" data-label="<?php esc_attr_e( 'Checkbox', 'meil' ); ?>"></span>
				<button type="button" class="button-link meil-fa-card__remove" data-meil-remove>
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<?php esc_html_e( 'Remove', 'meil' ); ?>
				</button>
			</div>
			<label class="screen-reader-text" for="meil-checkbox-text-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Checkbox text', 'meil' ); ?></label>
			<textarea id="meil-checkbox-text-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $name . '[text]' ); ?>" rows="3" class="widefat" data-meil-editor><?php echo esc_textarea( (string) $checkbox['text'] ); ?></textarea>
			<div class="meil-fa-card__foot">
				<label class="meil-fa-inline">
					<input type="checkbox" name="<?php echo esc_attr( $name . '[required]' ); ?>" value="1" <?php checked( ! empty( $checkbox['required'] ) ); ?> />
					<?php esc_html_e( 'Required', 'meil' ); ?>
				</label>
				<label class="meil-fa-property">
					<span><?php esc_html_e( 'Saved on the contact as', 'meil' ); ?></span>
					<input type="text" class="code" name="<?php echo esc_attr( $name . '[property]' ); ?>" value="<?php echo esc_attr( (string) $checkbox['property'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" maxlength="58" />
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Counts one signup for the visitor's IP. Past the limit the form is
	 * refused: a bot posting to it nonstop would otherwise use up the account's
	 * requests per second, and the site's own emails (password resets,
	 * orders) would start failing.
	 */
	private static function within_signup_limit(): bool {
		$ip = meil_visitor_ip();
		if ( '' === $ip ) {
			return true;
		}
		$key  = 'meil_signups_' . md5( $ip );
		$hits = (int) get_transient( $key );
		if ( $hits >= self::SIGNUP_LIMIT ) {
			return false;
		}
		set_transient( $key, $hits + 1, self::SIGNUP_WINDOW );
		return true;
	}

	/** A checkbox text with only bold, italic and links left in it, as it is saved. */
	private static function checkbox_kses( string $text ): string {
		// Script and style blocks go whole, their contents too, not only the tags.
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = wp_kses(
			$text,
			array(
				'a'      => array(
					'href'  => true,
					'title' => true,
				),
				'strong' => array(),
				'b'      => array(),
				'em'     => array(),
				'i'      => array(),
				'br'     => array(),
			),
			array( 'http', 'https', 'mailto', 'tel' )
		);
		// A link whose address was not a web, email or phone one (kses leaves
		// "alert(1)" behind from "javascript:alert(1)") keeps only its words.
		return (string) preg_replace_callback(
			'@<a\\s[^>]*>(.*?)</a>@si',
			static function ( array $m ): string {
				return preg_match( '@\\shref="(https?://|mailto:|tel:|/|#)[^"]*"@i', $m[0] ) ? $m[0] : $m[1];
			},
			$text
		);
	}

	/**
	 * A checkbox text as the form shows it: links open in a new tab, so the
	 * half-filled form is not lost.
	 */
	public static function checkbox_html( string $text ): string {
		return (string) preg_replace( '/<a\s/i', '<a target="_blank" rel="noopener noreferrer" ', self::checkbox_kses( $text ) );
	}

	/**
	 * The posted checkboxes, cleaned: empty ones dropped, each with a property
	 * name that is safe and unique ("checkbox_1" when none was given).
	 *
	 * @param mixed $posted
	 * @return list<array{text:string,required:bool,property:string}>
	 */
	private static function clean_checkboxes( $posted ): array {
		$out  = array();
		$used = array();
		foreach ( is_array( $posted ) ? $posted : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$text = trim( self::checkbox_kses( (string) ( $row['text'] ?? '' ) ) );
			if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
				continue;
			}
			$property = trim( (string) preg_replace( '/[^a-z0-9_]+/', '_', strtolower( (string) ( $row['property'] ?? '' ) ) ), '_' );
			$property = substr( '' !== $property ? $property : 'checkbox_' . ( count( $out ) + 1 ), 0, 58 );
			while ( isset( $used[ $property ] ) ) {
				$property = substr( $property, 0, 54 ) . '_' . ( count( $out ) + 1 );
			}
			$used[ $property ] = true;
			$out[]             = array(
				'text'     => $text,
				'required' => ! empty( $row['required'] ),
				'property' => $property,
			);
			if ( count( $out ) >= self::MAX_CHECKBOXES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * What visitors see for one audience: the form's own label, or else the
	 * audience's name in meil.ro.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function label( array $row ): string {
		foreach ( array( 'label', 'name', 'id' ) as $key ) {
			if ( '' !== (string) ( $row[ $key ] ?? '' ) ) {
				return (string) $row[ $key ];
			}
		}
		return '';
	}

	/**
	 * Fills in the audience names a form saved before it kept them, from
	 * meil.ro's (cached) list. Only asked when a row has neither a label nor a name.
	 *
	 * @param list<array<string,mixed>> $rows
	 * @return list<array<string,mixed>>
	 */
	private function with_names( array $rows ): array {
		$missing = array_filter(
			$rows,
			static function ( array $row ): bool {
				return '' === (string) ( $row['label'] ?? '' ) && '' === (string) ( $row['name'] ?? '' );
			}
		);
		if ( array() === $missing ) {
			return $rows;
		}
		$audiences = meil_cached_audiences( $this->client );
		if ( is_wp_error( $audiences ) ) {
			return $rows;
		}
		$names = array_column( $audiences, 'name', 'id' );
		foreach ( $rows as $i => $row ) {
			if ( '' === (string) ( $row['name'] ?? '' ) && isset( $names[ $row['id'] ] ) ) {
				$rows[ $i ]['name'] = (string) $names[ $row['id'] ];
			}
		}
		return $rows;
	}

	/** @param array<string,string> $columns */
	public function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['meil_shortcode'] = __( 'Shortcode', 'meil' );
				$out['meil_audiences'] = __( 'Audiences', 'meil' );
			}
		}
		return $out;
	}

	public function column( string $column, int $post_id ): void {
		if ( 'meil_shortcode' === $column ) {
			echo '<code>' . esc_html( self::shortcode( $post_id ) ) . '</code>';
		} elseif ( 'meil_audiences' === $column ) {
			$labels = array_map(
				static function ( array $row ): string {
					return self::label( $row );
				},
				$this->with_names( self::settings( $post_id )['audiences'] )
			);
			echo esc_html( array() !== $labels ? implode( ', ', $labels ) : __( 'None yet', 'meil' ) );
		}
	}

	/** "Form saved." instead of WordPress's "Post published. View post", which leads nowhere. */
	public function updated_messages( array $messages ): array {
		$saved                        = __( 'Form saved.', 'meil' );
		$messages[ self::POST_TYPE ] = array_fill( 0, 11, $saved );
		$messages[ self::POST_TYPE ][0] = '';
		return $messages;
	}

	/* -------------------------------------------------------------- front end */

	/** A saved, published form's settings, or null. */
	private static function published( int $form_id ): ?array {
		$post = $form_id ? get_post( $form_id ) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return null;
		}
		return self::settings( $form_id );
	}

	/** A form's text: its own, or the default in the site's language. */
	private static function texts( array $form ): callable {
		return static function ( string $key ) use ( $form ): string {
			return '' !== (string) $form[ $key ] ? (string) $form[ $key ] : self::default_text( $key );
		};
	}

	/** The form sent in the background: answers with the message, and whether the form is done. */
	public function handle_ajax(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- public form, see the class comment.
		$form = self::published( isset( $_POST['meil_form_id'] ) ? absint( $_POST['meil_form_id'] ) : 0 );
		if ( null === $form || array() === $form['audiences'] ) {
			wp_send_json(
				array(
					'type'    => 'error',
					'message' => __( 'Sorry, we could not sign you up right now. Please try again later.', 'meil' ),
					'done'    => false,
				)
			);
		}
		list( $message, $done ) = $this->handle_submission( $form, self::texts( $form ) );
		wp_send_json(
			array(
				'type'    => $message['type'],
				'message' => $message['text'],
				'done'    => $done,
			)
		);
	}

	/** @param array<string,mixed>|string $atts */
	public function render_shortcode( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'meil_form' );
		$form_id = absint( $atts['id'] );
		$form    = self::published( $form_id );
		$admin   = current_user_can( 'manage_options' );

		if ( null === $form ) {
			return $admin ? '<p>' . esc_html__( 'meil_form: no saved form with this id.', 'meil' ) . '</p>' : '';
		}
		if ( array() === $form['audiences'] ) {
			return $admin ? '<p>' . esc_html__( 'meil_form: this form has no audiences yet. Pick them under meil.ro > Forms.', 'meil' ) . '</p>' : '';
		}

		meil_enqueue_front_assets();

		$text = self::texts( $form );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, see the class comment.
		$submitted = isset( $_POST['meil_form_id'] ) && absint( $_POST['meil_form_id'] ) === $form_id;
		$message   = null;
		$done      = false;
		$values    = array(
			'email'      => '',
			'first_name' => '',
			'last_name'  => '',
			'audiences'  => null,
			'checkboxes' => array(),
		);
		if ( $submitted ) {
			list( $message, $done, $values ) = $this->handle_submission( $form, $text );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$choices = array();
		foreach ( $this->with_names( $form['audiences'] ) as $row ) {
			$choices[] = array(
				'id'      => $row['id'],
				'label'   => self::label( $row ),
				'checked' => null === $values['audiences'] ? ! empty( $row['checked'] ) : in_array( $row['id'], $values['audiences'], true ),
			);
		}

		return meil_template(
			'form',
			array(
				'form_id'      => $form_id,
				'instance_id'  => 'meil-form-' . $form_id . '-' . wp_rand( 1000, 9999 ),
				'choices'      => $choices,
				'show_choices' => count( $choices ) > 1,
				'ask_name'      => (bool) $form['ask_name'],
				'ask_last_name' => (bool) $form['ask_last_name'],
				'button_text'  => $text( 'button' ),
				'placeholder'  => $text( 'placeholder' ),
				'message'      => $message,
				'done'         => $done,
				'values'       => $values,
				'honeypot'     => self::HONEYPOT,
				'checkboxes'   => array_map(
					static function ( array $checkbox, int $i ) use ( $values ): array {
						return array(
							'html'     => self::checkbox_html( (string) $checkbox['text'] ),
							'required' => ! empty( $checkbox['required'] ),
							'checked'  => in_array( $i, (array) $values['checkboxes'], true ),
						);
					},
					array_values( (array) $form['checkboxes'] ),
					array_keys( array_values( (array) $form['checkboxes'] ) )
				),
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'ajax_action'  => self::AJAX_ACTION,
			)
		);
	}

	/**
	 * Signs the visitor up. Only audiences the form offers are ever sent, whatever
	 * the posted values say.
	 *
	 * @param array<string,mixed> $form
	 * @return array{0: array{type:string,text:string}|null, 1: bool, 2: array<string,mixed>}
	 */
	private function handle_submission( array $form, callable $text ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, see the class comment.
		$email      = isset( $_POST['meil_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['meil_email'] ) ) : '';
		$first_name = isset( $_POST['meil_first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['meil_first_name'] ) ) : '';
		$last_name  = isset( $_POST['meil_last_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['meil_last_name'] ) ) : '';
		$posted     = isset( $_POST['meil_audiences'] ) && is_array( $_POST['meil_audiences'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['meil_audiences'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by the map.
			: array();
		$bot        = ! empty( $_POST[ self::HONEYPOT ] );
		$ticked     = array();
		foreach ( array_keys( array_values( (array) $form['checkboxes'] ) ) as $i ) {
			if ( ! empty( $_POST['meil_checkbox'][ $i ] ) ) {
				$ticked[] = $i;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$offered = array_column( $form['audiences'], 'id' );
		$chosen  = count( $offered ) > 1 ? array_values( array_intersect( $offered, $posted ) ) : $offered;
		$values  = array(
			'email'      => $email,
			'first_name' => $first_name,
			'last_name'  => $last_name,
			'audiences'  => $chosen,
			'checkboxes' => $ticked,
		);
		$error   = static function ( string $message ) use ( $values ): array {
			return array(
				array(
					'type' => 'error',
					'text' => $message,
				),
				false,
				$values,
			);
		};

		// A filled-in honeypot means a bot: look successful, add nobody.
		if ( $bot ) {
			return array(
				array(
					'type' => 'success',
					'text' => $text( 'success' ),
				),
				true,
				$values,
			);
		}
		if ( ! is_email( $email ) ) {
			return $error( __( 'Enter a valid email address.', 'meil' ) );
		}
		if ( array() === $chosen ) {
			return $error( __( 'Choose at least one list.', 'meil' ) );
		}
		foreach ( array_values( (array) $form['checkboxes'] ) as $i => $checkbox ) {
			if ( ! empty( $checkbox['required'] ) && ! in_array( $i, $ticked, true ) ) {
				return $error( __( 'Tick the required box to continue.', 'meil' ) );
			}
		}
		if ( ! self::within_signup_limit() ) {
			return $error( __( 'Too many tries from your connection. Please wait a few minutes and try again.', 'meil' ) );
		}

		// With confirmation, meil.ro records when and from where the person
		// confirmed (confirmed_at, confirmed_ip): that is the proof, so the form
		// keeps no IP of its own. Without it, the form's record is the only one.
		$properties = array( 'source' => 'wordpress' );
		if ( empty( $form['confirm'] ) ) {
			$properties = array(
				'consent_timestamp' => gmdate( 'c' ),
				'consent_ip'        => meil_visitor_ip(),
			) + $properties;
		}
		// Each checkbox: whether it was ticked, and the words the visitor saw,
		// so it can be shown later what exactly they agreed to.
		foreach ( array_values( (array) $form['checkboxes'] ) as $i => $checkbox ) {
			$words = trim( (string) preg_replace( '/\s+/', ' ', html_entity_decode( wp_strip_all_tags( (string) $checkbox['text'] ), ENT_QUOTES, 'UTF-8' ) ) );
			$properties[ $checkbox['property'] ]           = in_array( $i, $ticked, true );
			$properties[ $checkbox['property'] . '_text' ] = $words;
		}
		$payload = array(
			'audience_ids' => $chosen,
			'email'        => $email,
			'properties'   => $properties,
		);
		if ( '' !== $first_name ) {
			$payload['first_name'] = $first_name;
		}
		if ( '' !== $last_name ) {
			$payload['last_name'] = $last_name;
		}
		if ( ! empty( $form['confirm'] ) ) {
			$payload['confirm']  = true;
			$payload['language'] = 0 === strpos( determine_locale(), 'ro' ) ? 'ro' : 'en';
			$thanks              = (int) $form['thanks_page'] > 0 ? get_permalink( (int) $form['thanks_page'] ) : false;
			if ( is_string( $thanks ) && '' !== $thanks ) {
				$payload['confirmed_url'] = $thanks;
			}
		}

		$result = $this->client->create_contact( $payload );
		if ( is_wp_error( $result ) ) {
			// The API's message is for the site owner (it can mention API keys), not visitors.
			return $error(
				current_user_can( 'manage_options' )
					? $result->get_error_message()
					: __( 'Sorry, we could not sign you up right now. Please try again later.', 'meil' )
			);
		}

		$waiting = array_filter(
			(array) ( $result['audiences'] ?? array() ),
			static function ( $audience ): bool {
				return is_array( $audience ) && ! empty( $audience['pending_confirmation'] );
			}
		);
		return array(
			array(
				'type' => array() !== $waiting ? 'pending' : 'success',
				'text' => $text( array() !== $waiting ? 'pending' : 'success' ),
			),
			true,
			$values,
		);
	}
}
