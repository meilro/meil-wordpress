<?php
/**
 * The popup: one of the forms shown on top of the pages, as a popup, a
 * slide-in or a bar, set under meil.ro > Settings > Popup. The form is drawn
 * at the bottom of the page, hidden, and assets/popup.js shows it when its
 * moment comes: after some seconds, after some scrolling, or when the visitor
 * is about to leave.
 *
 * Never on the cart, the checkout or the account pages, nor on a page that
 * already shows the same form or a confirmation box (Meil_Confirm). The browser remembers a close (for the days
 * set) and a signup through any meil.ro form (for good), so it does not come
 * back.
 *
 * The same HTML goes to every visitor, so page caches are fine with it. Only
 * administrators, whose pages caches leave alone, get the test mode's mark
 * (see meil_test_mode()).
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Popups {

	/** @var Meil_Forms */
	private $forms;

	public function __construct( Meil_Forms $forms ) {
		$this->forms = $forms;

		add_action( 'wp_footer', array( $this, 'render' ), 5 ); // Before the footer scripts are printed (20).
	}

	/** May a popup show on this page at all? */
	private static function page_allows(): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_customize_preview() || Meil_Confirm::is_confirming() || Meil_Confirm::drawn() ) {
			return false;
		}
		// Where people are paying or managing their account, nothing gets in the way.
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $check ) {
			if ( function_exists( $check ) && $check() ) {
				return false;
			}
		}
		return true;
	}

	private static function matches( int $form_id, string $where ): bool {
		if ( ( 'posts' === $where && ! is_singular( 'post' ) ) || ( 'pages' === $where && ! is_page() ) ) {
			return false;
		}
		// Not on top of a page that already shows the same form.
		$post = is_singular() ? get_post() : null;
		if ( $post ) {
			$content = (string) $post->post_content;
			if ( preg_match( '/\[meil_form\s+id=["\']?' . $form_id . '\b/', $content ) || preg_match( '/<!-- wp:meil\/form \{[^}]*"formId":' . $form_id . '\b/', $content ) ) {
				return false;
			}
		}
		return true;
	}

	public function render(): void {
		$settings = meil_mailer_settings();
		$form_id  = (int) ( $settings['popup_form'] ?? 0 );
		if ( $form_id <= 0 || 'publish' !== get_post_status( $form_id ) || ! meil_key_can( 'manage' ) || ! self::page_allows()
			|| ! self::matches( $form_id, (string) $settings['popup_where'] ) ) {
			return;
		}
		$html = $this->forms->render_shortcode( array( 'id' => $form_id ) );
		if ( false === strpos( $html, 'meil-form__form' ) ) {
			return; // Nothing a visitor could fill in.
		}
		wp_enqueue_script( 'meil-popup', MEIL_MAILER_URL . 'assets/popup.js', array(), meil_asset_version( 'assets/popup.js' ), true );
		wp_enqueue_style( 'meil-popup', MEIL_MAILER_URL . 'assets/popup.css', array(), meil_asset_version( 'assets/popup.css' ) );
		$popup = meil_template(
			'popup',
			array(
				'form_id'   => $form_id,
				'kind'      => (string) $settings['popup_kind'],
				'trigger'   => (string) $settings['popup_trigger'],
				'value'     => (int) $settings['popup_value'],
				'days'      => (int) $settings['popup_days'],
				'title'     => '' !== (string) $settings['popup_title'] ? (string) $settings['popup_title'] : Meil_Forms::default_text( 'popup_title' ),
				'text'      => (string) $settings['popup_text'],
				'form_html' => $html,
				'test'      => meil_test_mode(),
			)
		);
		echo $popup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the template escapes; the form's HTML comes from the form's own template.
	}
}
