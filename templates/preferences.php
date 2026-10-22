<?php
/**
 * The logged-in user's lists, [meil_preferences].
 *
 * To change the markup, copy this file to your theme as meil/preferences.php.
 * Every element has its own class, so most changes only need CSS:
 *
 *   .meil-prefs                     the whole block
 *   .meil-prefs__message            the result of a save (+ --success, --error)
 *   .meil-prefs__notice             shown to visitors who are not logged in
 *   .meil-prefs__choice             one list (+ --checked, and --subscribed, --pending or --unsubscribed)
 *   .meil-prefs__checkbox-input     the real checkbox (visually hidden by forms.css)
 *   .meil-prefs__checkbox           the empty box drawn next to it, to style freely
 *   .meil-prefs__choice-label, __choice-description, __choice-status
 *   .meil-prefs__button
 *   .meil-prefs__form--loading, .meil-prefs__button--loading   while it is being saved
 *
 * The form is sent in the background by assets/forms.js, which reads the
 * data-meil-* attributes below; keep them if you change the markup.
 *
 * To rename, reorder or hide lists without copying this file, use the
 * meil_preferences_choices filter.
 *
 * @package Meil_Mailer
 *
 * @var bool                                $logged_in
 * @var array{type:string,text:string}|null $message
 * @var string                              $email
 * @var list<array<string,mixed>>           $choices   id, label, description, checked, state
 * @var string                              $error     why the lists cannot be shown, or ''
 * @var string                              $login_url
 * @var string                              $action
 * @var string                              $nonce
 * @var string                              $ajax_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="meil-prefs" data-meil-block="meil-prefs">
	<?php if ( ! $logged_in ) : ?>
		<p class="meil-prefs__notice">
			<?php esc_html_e( 'Your email preferences are available once you are logged in.', 'meil' ); ?>
			<a class="meil-prefs__login" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'meil' ); ?></a>
		</p>
	<?php else : ?>
		<p class="meil-prefs__message<?php echo null !== $message ? ' meil-prefs__message--' . esc_attr( $message['type'] ) : ''; ?>" role="status" aria-live="polite" data-meil-message<?php echo null === $message ? ' hidden' : ''; ?>><?php echo null !== $message ? esc_html( $message['text'] ) : ''; ?></p>

		<?php if ( '' !== $error ) : ?>
			<p class="meil-prefs__message meil-prefs__message--error" role="alert"><?php echo esc_html( $error ); ?></p>
		<?php elseif ( array() === $choices ) : ?>
			<p class="meil-prefs__notice"><?php esc_html_e( 'There are no lists to choose from yet.', 'meil' ); ?></p>
		<?php else : ?>
			<form class="meil-prefs__form" method="post"
				data-meil-ajax="<?php echo esc_url( $ajax_url ); ?>" data-meil-action="<?php echo esc_attr( $action ); ?>"
				data-meil-error="<?php esc_attr_e( 'Sorry, your choices could not be saved right now. Please try again later.', 'meil' ); ?>">
				<input type="hidden" name="meil_action" value="<?php echo esc_attr( $action ); ?>" />
				<input type="hidden" name="meil_prefs_nonce" value="<?php echo esc_attr( $nonce ); ?>" />

				<fieldset class="meil-prefs__choices">
					<legend class="meil-prefs__legend">
						<?php
						printf(
							/* translators: %s: the user's email address */
							esc_html__( 'Emails we send to %s', 'meil' ),
							'<strong class="meil-prefs__email">' . esc_html( $email ) . '</strong>'
						);
						?>
					</legend>
					<?php foreach ( $choices as $choice ) : ?>
						<label class="meil-prefs__choice meil-prefs__choice--<?php echo esc_attr( (string) $choice['state'] ); ?><?php echo $choice['checked'] ? ' meil-prefs__choice--checked' : ''; ?>" data-meil-choice="<?php echo esc_attr( (string) $choice['id'] ); ?>">
							<input class="meil-prefs__checkbox-input" type="checkbox" name="meil_audiences[]" value="<?php echo esc_attr( (string) $choice['id'] ); ?>" <?php checked( (bool) $choice['checked'] ); ?> />
							<span class="meil-prefs__checkbox" aria-hidden="true"></span>
							<span class="meil-prefs__choice-text">
								<span class="meil-prefs__choice-label"><?php echo esc_html( (string) $choice['label'] ); ?></span>
								<?php if ( '' !== (string) $choice['description'] ) : ?>
									<span class="meil-prefs__choice-description"><?php echo esc_html( (string) $choice['description'] ); ?></span>
								<?php endif; ?>
								<?php if ( 'pending' === $choice['state'] ) : ?>
									<span class="meil-prefs__choice-status"><?php esc_html_e( 'Waiting for you to confirm from the email we sent. Saving with this ticked subscribes you now.', 'meil' ); ?></span>
								<?php endif; ?>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<p class="meil-prefs__actions">
					<button class="meil-prefs__button" type="submit"><?php esc_html_e( 'Save', 'meil' ); ?></button>
				</p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
</div>
