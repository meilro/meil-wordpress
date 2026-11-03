<?php
/**
 * A form shown on top of the page: a popup, a slide-in or a bar.
 *
 * To change the markup, copy this file to your theme as meil/popup.php. The
 * form inside is templates/form.php, overridable the same way. Classes:
 *
 *   .meil-popup                 the whole thing (+ --modal, --slide or --bar, and --open while shown)
 *   .meil-popup__backdrop       the dimmed page behind a popup
 *   .meil-popup__box            the box with the form
 *   .meil-popup__close          the close button
 *   .meil-popup__title, __text  above the form
 *
 * assets/popup.js reads the data-meil-* attributes; keep them if you change the markup.
 *
 * @package Meil_Mailer
 *
 * @var int    $form_id
 * @var string $kind      modal, slide or bar
 * @var string $trigger   delay, scroll or exit
 * @var int    $value     seconds or percent
 * @var int    $days      days a closed popup stays away
 * @var string $title
 * @var string $text
 * @var string $form_html the form, from templates/form.php
 * @var bool   $test      test mode: shown at once, every time (administrators only)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$meil_title_id = 'meil-popup-' . $form_id . '-title';
?>
<div class="meil-popup meil-popup--<?php echo esc_attr( $kind ); ?>" hidden
	data-meil-popup="<?php echo esc_attr( (string) $form_id ); ?>" data-meil-kind="<?php echo esc_attr( $kind ); ?>"
	data-meil-trigger="<?php echo esc_attr( $trigger ); ?>" data-meil-value="<?php echo esc_attr( (string) $value ); ?>" data-meil-days="<?php echo esc_attr( (string) $days ); ?>"<?php echo ! empty( $test ) ? ' data-meil-test="1"' : ''; ?>>
	<?php if ( 'modal' === $kind ) : ?>
		<div class="meil-popup__backdrop" data-meil-popup-close></div>
	<?php endif; ?>
	<div class="meil-popup__box" role="dialog" aria-labelledby="<?php echo esc_attr( $meil_title_id ); ?>"<?php echo 'modal' === $kind ? ' aria-modal="true"' : ''; ?>>
		<button type="button" class="meil-popup__close" data-meil-popup-close aria-label="<?php esc_attr_e( 'Close', 'meil' ); ?>"><span aria-hidden="true">&times;</span></button>
		<p class="meil-popup__title" id="<?php echo esc_attr( $meil_title_id ); ?>"><?php echo esc_html( $title ); ?></p>
		<?php if ( '' !== $text ) : ?>
			<p class="meil-popup__text"><?php echo esc_html( $text ); ?></p>
		<?php endif; ?>
		<?php
		// Escaped by templates/form.php.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $form_html;
		?>
	</div>
</div>
