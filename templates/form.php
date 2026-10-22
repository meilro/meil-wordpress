<?php
/**
 * A subscription form, [meil_form id="…"].
 *
 * To change the markup, copy this file to your theme as meil/form.php. Every
 * element has its own class, so most changes only need CSS:
 *
 *   .meil-form                      the whole block (+ --done after a signup)
 *   .meil-form__message             the result (+ --success, --pending, --error)
 *   .meil-form__choices             the list checkboxes, when the form offers several
 *   .meil-form__choice              one list (+ --checked)
 *   .meil-form__checkbox-input      the real checkbox (visually hidden by forms.css)
 *   .meil-form__checkbox            the empty box drawn next to it, to style freely
 *   .meil-form__consents            the form's own checkboxes (terms, privacy...)
 *   .meil-form__choice--consent     one of them (+ --required); its text may hold links
 *   .meil-form__field, __label, __input, __button
 *   .meil-form__form--loading, .meil-form__button--loading   while it is being sent
 *
 * The form is sent in the background by assets/forms.js, which reads the
 * data-meil-* attributes below; keep them if you change the markup.
 *
 * @package Meil_Mailer
 *
 * @var int                                $form_id
 * @var string                             $instance_id
 * @var list<array<string,mixed>>          $choices      id, label, checked
 * @var bool                               $show_choices
 * @var bool                               $ask_name
 * @var bool                               $ask_last_name
 * @var string                             $button_text
 * @var string                             $placeholder
 * @var array{type:string,text:string}|null $message
 * @var bool                               $done         true after a signup: the form is not shown again
 * @var array<string,mixed>                $values       what the visitor typed, to fill the form again after an error
 * @var string                             $honeypot     name of the field bots fill in
 * @var list<array<string,mixed>>          $checkboxes   html (already safe: bold, italic, links), required, checked
 * @var string                             $ajax_url
 * @var string                             $ajax_action
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="meil-form meil-form--<?php echo esc_attr( (string) $form_id ); ?><?php echo $done ? ' meil-form--done' : ''; ?>" id="<?php echo esc_attr( $instance_id ); ?>" data-meil-block="meil-form">
	<p class="meil-form__message<?php echo null !== $message ? ' meil-form__message--' . esc_attr( $message['type'] ) : ''; ?>" role="status" aria-live="polite" data-meil-message<?php echo null === $message ? ' hidden' : ''; ?>><?php echo null !== $message ? esc_html( $message['text'] ) : ''; ?></p>

	<?php if ( ! $done ) : ?>
		<form class="meil-form__form" method="post" action="#<?php echo esc_attr( $instance_id ); ?>"
			data-meil-ajax="<?php echo esc_url( $ajax_url ); ?>" data-meil-action="<?php echo esc_attr( $ajax_action ); ?>"
			data-meil-error="<?php esc_attr_e( 'Sorry, we could not sign you up right now. Please try again later.', 'meil' ); ?>">
			<input type="hidden" name="meil_form_id" value="<?php echo esc_attr( (string) $form_id ); ?>" />

			<?php if ( $show_choices ) : ?>
				<fieldset class="meil-form__choices">
					<legend class="meil-form__legend"><?php esc_html_e( 'Choose what you want to receive', 'meil' ); ?></legend>
					<?php foreach ( $choices as $choice ) : ?>
						<label class="meil-form__choice<?php echo $choice['checked'] ? ' meil-form__choice--checked' : ''; ?>">
							<input class="meil-form__checkbox-input" type="checkbox" name="meil_audiences[]" value="<?php echo esc_attr( $choice['id'] ); ?>" <?php checked( $choice['checked'] ); ?> />
							<span class="meil-form__checkbox" aria-hidden="true"></span>
							<span class="meil-form__choice-label"><?php echo esc_html( $choice['label'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( $ask_name ) : ?>
				<p class="meil-form__field meil-form__field--first-name">
					<label class="meil-form__label" for="<?php echo esc_attr( $instance_id ); ?>-first-name"><?php esc_html_e( 'First name', 'meil' ); ?></label>
					<input class="meil-form__input" type="text" id="<?php echo esc_attr( $instance_id ); ?>-first-name" name="meil_first_name" value="<?php echo esc_attr( (string) $values['first_name'] ); ?>" autocomplete="given-name" />
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $ask_last_name ) ) : ?>
				<p class="meil-form__field meil-form__field--last-name">
					<label class="meil-form__label" for="<?php echo esc_attr( $instance_id ); ?>-last-name"><?php esc_html_e( 'Last name', 'meil' ); ?></label>
					<input class="meil-form__input" type="text" id="<?php echo esc_attr( $instance_id ); ?>-last-name" name="meil_last_name" value="<?php echo esc_attr( (string) ( $values['last_name'] ?? '' ) ); ?>" autocomplete="family-name" />
				</p>
			<?php endif; ?>

			<p class="meil-form__field meil-form__field--email">
				<label class="meil-form__label" for="<?php echo esc_attr( $instance_id ); ?>-email"><?php esc_html_e( 'Email address', 'meil' ); ?></label>
				<input class="meil-form__input" type="email" id="<?php echo esc_attr( $instance_id ); ?>-email" name="meil_email" value="<?php echo esc_attr( (string) $values['email'] ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" autocomplete="email" required />
			</p>

			<?php if ( array() !== $checkboxes ) : ?>
				<div class="meil-form__consents">
					<?php foreach ( $checkboxes as $i => $checkbox ) : ?>
						<label class="meil-form__choice meil-form__choice--consent<?php echo $checkbox['required'] ? ' meil-form__choice--required' : ''; ?>">
							<input class="meil-form__checkbox-input" type="checkbox" name="meil_checkbox[<?php echo esc_attr( (string) $i ); ?>]" value="1" <?php checked( $checkbox['checked'] ); ?><?php echo $checkbox['required'] ? ' required' : ''; ?> />
							<span class="meil-form__checkbox" aria-hidden="true"></span>
							<span class="meil-form__choice-label"><?php echo wp_kses_post( $checkbox['html'] ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<p class="meil-form__hp" aria-hidden="true">
				<input type="text" name="<?php echo esc_attr( $honeypot ); ?>" value="" tabindex="-1" autocomplete="off" />
			</p>

			<p class="meil-form__actions">
				<button class="meil-form__button" type="submit"><?php echo esc_html( $button_text ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
