<?php
/**
 * Confirming a sign-up on this site, [meil_confirm].
 *
 * To change the markup, copy this file to your theme as meil/confirm.php.
 * Every element has its own class, so most changes only need CSS:
 *
 *   .meil-confirm                   the whole block
 *   .meil-confirm__message          the result (+ --success, --error)
 *   .meil-confirm__intro            what pressing the button does, with the address
 *   .meil-confirm__lists-label, __lists, __list   the lists being confirmed
 *   .meil-confirm__form, __button
 *   .meil-confirm__note             a note only the site's admins see
 *
 * The button must post the token back: confirming happens on the press,
 * never when the page opens.
 *
 * @package Meil_Mailer
 *
 * @var array{type:string,text:string}|null $message
 * @var bool                                $show_button
 * @var string                              $intro
 * @var list<string>                        $lists        the lists being confirmed, by their label
 * @var string                              $lists_label
 * @var string                              $button_text
 * @var string                              $token
 * @var int                                 $form_id
 * @var string                              $action
 * @var string                              $action_url
 * @var string                              $admin_note
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="meil-confirm" data-meil-block="meil-confirm">
	<?php if ( '' !== $admin_note ) : ?>
		<p class="meil-confirm__note"><?php echo esc_html( $admin_note ); ?></p>
	<?php endif; ?>

	<?php if ( null !== $message ) : ?>
		<p class="meil-confirm__message meil-confirm__message--<?php echo esc_attr( $message['type'] ); ?>" role="status"><?php echo esc_html( $message['text'] ); ?></p>
	<?php endif; ?>

	<?php if ( $show_button ) : ?>
		<form class="meil-confirm__form" method="post" action="<?php echo esc_url( $action_url ); ?>">
			<input type="hidden" name="meil_action" value="<?php echo esc_attr( $action ); ?>" />
			<input type="hidden" name="meil_token" value="<?php echo esc_attr( $token ); ?>" />
			<?php if ( $form_id > 0 ) : ?>
				<input type="hidden" name="meil_form" value="<?php echo esc_attr( (string) $form_id ); ?>" />
			<?php endif; ?>

			<?php if ( '' !== $intro ) : ?>
				<p class="meil-confirm__intro"><?php echo esc_html( $intro ); ?></p>
			<?php endif; ?>

			<?php if ( array() !== $lists ) : ?>
				<p class="meil-confirm__lists-label"><?php echo esc_html( $lists_label ); ?></p>
				<ul class="meil-confirm__lists">
					<?php foreach ( $lists as $list ) : ?>
						<li class="meil-confirm__list"><?php echo esc_html( $list ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<p class="meil-confirm__actions">
				<button class="meil-confirm__button" type="submit"><?php echo esc_html( $button_text ); ?></button>
			</p>
		</form>
	<?php endif; ?>
</div>
