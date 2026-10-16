<?php
/**
 * Front-end audience signup form: [meil_signup audience="<audience_id>"].
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and processes the [meil_signup] shortcode.
 *
 * There is no nonce on purpose: the form is public, and most hosts cache
 * pages, so a nonce baked into the HTML expires and signups fail silently.
 * A hidden honeypot field turns away the simple bots instead.
 */
class Meil_Mailer_Signup {

	private const HONEYPOT = 'meil_signup_website';

	/** @var Meil_Client */
	private $client;

	public function __construct( Meil_Client $client ) {
		$this->client = $client;

		add_shortcode( 'meil_signup', array( $this, 'render_shortcode' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'audience'         => '',
				'button_text'      => __( 'Subscribe', 'meil' ),
				'placeholder'      => __( 'you@example.com', 'meil' ),
				'success_message'  => __( 'Thanks — you are on the list!', 'meil' ),
				'show_name_fields' => 'false',
			),
			$atts,
			'meil_signup'
		);

		return $this->render( $atts );
	}

	/**
	 * @param array<string,mixed> $atts
	 */
	public function render( array $atts ): string {
		$audience_id = sanitize_text_field( (string) $atts['audience'] );
		if ( '' === $audience_id ) {
			return current_user_can( 'manage_options' )
				? '<p>' . esc_html__( 'meil_signup: the "audience" attribute is required.', 'meil' ) . '</p>'
				: '';
		}

		wp_enqueue_style( 'meil-mailer-signup', MEIL_MAILER_URL . 'assets/signup.css', array(), MEIL_MAILER_VERSION );

		$instance_id = 'meil-signup-' . substr( md5( $audience_id . wp_rand() ), 0, 8 );
		$show_names  = filter_var( $atts['show_name_fields'], FILTER_VALIDATE_BOOLEAN );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, see the class comment.
		$submitted_audience = isset( $_POST['meil_signup_audience'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['meil_signup_audience'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$feedback = ( $submitted_audience === $audience_id )
			? $this->handle_submission( $audience_id, $atts )
			: '';

		ob_start();
		?>
		<div class="meil-signup" id="<?php echo esc_attr( $instance_id ); ?>">
			<?php echo wp_kses_post( $feedback ); ?>
			<form class="meil-signup__form" method="post" action="#<?php echo esc_attr( $instance_id ); ?>">
				<?php if ( $show_names ) : ?>
					<p class="meil-signup__field">
						<label for="<?php echo esc_attr( $instance_id ); ?>-first"><?php esc_html_e( 'First name', 'meil' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $instance_id ); ?>-first" name="meil_signup_first_name" />
					</p>
				<?php endif; ?>
				<p class="meil-signup__field">
					<label for="<?php echo esc_attr( $instance_id ); ?>-email" class="screen-reader-text"><?php esc_html_e( 'Email address', 'meil' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $instance_id ); ?>-email" name="meil_signup_email"
						placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" required />
				</p>
				<p class="meil-signup__hp" aria-hidden="true">
					<input type="text" name="<?php echo esc_attr( self::HONEYPOT ); ?>" value="" tabindex="-1" autocomplete="off" />
				</p>
				<input type="hidden" name="meil_signup_audience" value="<?php echo esc_attr( $audience_id ); ?>" />
				<p class="meil-signup__field">
					<button type="submit"><?php echo esc_html( $atts['button_text'] ); ?></button>
				</p>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Adds the submitted address to the audience.
	 *
	 * @param array<string,mixed> $atts
	 */
	private function handle_submission( string $audience_id, array $atts ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form, see the class comment.
		$success = '<p class="meil-signup__success">' . esc_html( $atts['success_message'] ) . '</p>';

		// A filled-in honeypot means a bot: look successful, add nobody.
		if ( ! empty( $_POST[ self::HONEYPOT ] ) ) {
			return $success;
		}

		$email = isset( $_POST['meil_signup_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['meil_signup_email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			return '<p class="meil-signup__error">' . esc_html__( 'Enter a valid email address.', 'meil' ) . '</p>';
		}

		$payload    = array(
			'audience_id' => $audience_id,
			'email'       => $email,
			'properties'  => array(
				'consent_timestamp' => gmdate( 'c' ),
				'consent_ip'        => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '',
				'source'            => 'wordpress',
			),
		);
		$first_name = isset( $_POST['meil_signup_first_name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['meil_signup_first_name'] ) ) : '';
		if ( '' !== $first_name ) {
			$payload['first_name'] = $first_name;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->client->create_contact( $payload );

		if ( is_wp_error( $result ) ) {
			// The API's message is for the site owner (it can mention API keys), not visitors.
			$message = current_user_can( 'manage_options' )
				? $result->get_error_message()
				: __( 'Sorry, we could not sign you up right now. Please try again later.', 'meil' );
			return '<p class="meil-signup__error">' . esc_html( $message ) . '</p>';
		}

		return $success;
	}
}
