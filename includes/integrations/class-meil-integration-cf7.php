<?php
/**
 * Contact Form 7. Its fields have no labels, only names ("your-email"), so the
 * names are shown; an acceptance field also shows its text.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Integration_CF7 extends Meil_Integration {

	public function key(): string {
		return 'cf7';
	}

	public function name(): string {
		return 'Contact Form 7';
	}

	public function active(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	public function forms(): array {
		$out = array();
		foreach ( (array) WPCF7_ContactForm::find( array( 'posts_per_page' => -1 ) ) as $form ) {
			$fields = array();
			foreach ( (array) $form->scan_form_tags() as $tag ) {
				if ( '' === (string) $tag->name ) {
					continue; // The submit button and the like.
				}
				$text     = trim( wp_strip_all_tags( (string) $tag->content ) );
				$fields[] = array(
					'id'    => (string) $tag->name,
					'label' => '' !== $text ? $tag->name . ': ' . wp_trim_words( $text, 8 ) : (string) $tag->name,
					'type'  => self::type( (string) $tag->basetype ),
					'text'  => '' !== $text ? $text : implode( ', ', array_map( 'strval', (array) $tag->labels ) ),
				);
			}
			$out[] = array(
				'id'     => (string) $form->id(),
				'title'  => (string) $form->title(),
				'fields' => $fields,
			);
		}
		return $out;
	}

	private static function type( string $basetype ): string {
		if ( 'email' === $basetype ) {
			return 'email';
		}
		if ( in_array( $basetype, array( 'checkbox', 'acceptance' ), true ) ) {
			return 'checkbox';
		}
		return in_array( $basetype, array( 'text', 'tel' ), true ) ? 'text' : 'other';
	}

	public function hook(): string {
		return 'wpcf7_submit';
	}

	public function hook_args(): int {
		return 2;
	}

	public function read( array $args ): ?array {
		list( $form, $result ) = $args + array( null, array() );
		// Sent, even if its own email then failed; not spam, not invalid.
		if ( ! in_array( (string) ( $result['status'] ?? '' ), array( 'mail_sent', 'mail_failed' ), true ) || ! class_exists( 'WPCF7_Submission' ) ) {
			return null;
		}
		$submission = WPCF7_Submission::get_instance();
		return $submission ? array( (string) $form->id(), (array) $submission->get_posted_data() ) : null;
	}
}
