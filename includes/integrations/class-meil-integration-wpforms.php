<?php
/**
 * WPForms (Lite and Pro). A Name field with first and last parts offers each
 * part on its own.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Integration_WPForms extends Meil_Integration {

	public function key(): string {
		return 'wpforms';
	}

	public function name(): string {
		return 'WPForms';
	}

	public function active(): bool {
		return function_exists( 'wpforms' );
	}

	public function forms(): array {
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'posts_per_page' => -1 ) ) as $post ) {
			$data   = json_decode( (string) $post->post_content, true );
			$fields = array();
			foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
				if ( ! is_array( $field ) || ! isset( $field['id'] ) ) {
					continue;
				}
				$id    = (string) $field['id'];
				$label = '' !== (string) ( $field['label'] ?? '' ) ? (string) $field['label'] : $id;
				$type  = (string) ( $field['type'] ?? '' );
				if ( 'name' === $type && 'simple' !== ( $field['format'] ?? 'first-last' ) ) {
					/* translators: %s: the field's label */
					$fields[] = array( 'id' => $id . '.first', 'label' => sprintf( __( '%s (first name)', 'meilro' ), $label ), 'type' => 'text' );
					/* translators: %s: the field's label */
					$fields[] = array( 'id' => $id . '.last', 'label' => sprintf( __( '%s (last name)', 'meilro' ), $label ), 'type' => 'text' );
					continue;
				}
				$fields[] = array(
					'id'    => $id,
					'label' => $label,
					'type'  => 'email' === $type ? 'email' : ( in_array( $type, array( 'checkbox', 'gdpr-checkbox' ), true ) ? 'checkbox' : ( in_array( $type, array( 'text', 'name' ), true ) ? 'text' : 'other' ) ),
					// A checkbox's own words are its choices ("Yes, send me news").
					'text'  => implode( ', ', array_filter( array_map( static function ( $choice ): string {
						return is_array( $choice ) ? wp_strip_all_tags( (string) ( $choice['label'] ?? '' ) ) : '';
					}, (array) ( $field['choices'] ?? array() ) ) ) ),
				);
			}
			$out[] = array(
				'id'     => (string) $post->ID,
				'title'  => (string) $post->post_title,
				'fields' => $fields,
			);
		}
		return $out;
	}

	public function hook(): string {
		return 'wpforms_process_complete';
	}

	public function hook_args(): int {
		return 3;
	}

	public function read( array $args ): ?array {
		list( $fields, , $form_data ) = $args + array( array(), array(), array() );
		$values = array();
		foreach ( (array) $fields as $id => $field ) {
			$values[ (string) $id ] = $field['value'] ?? '';
			if ( 'name' === ( $field['type'] ?? '' ) ) {
				$values[ $id . '.first' ] = $field['first'] ?? '';
				$values[ $id . '.last' ]  = $field['last'] ?? '';
			}
		}
		return array( (string) ( $form_data['id'] ?? '' ), $values );
	}
}
