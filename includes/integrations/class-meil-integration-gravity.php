<?php
/**
 * Gravity Forms. A Name field offers its first and last parts (inputs .3 and
 * .6); a Checkbox field counts as ticked when any of its choices is, a
 * Consent field when its box (.1) is. gform_after_submission also runs for
 * entries marked as spam: those are left out.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Integration_Gravity extends Meil_Integration {

	public function key(): string {
		return 'gravity';
	}

	public function name(): string {
		return 'Gravity Forms';
	}

	public function active(): bool {
		return class_exists( 'GFAPI' );
	}

	public function forms(): array {
		$out = array();
		foreach ( (array) GFAPI::get_forms() as $form ) {
			$fields = array();
			foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
				$type  = (string) ( $field->type ?? '' );
				$id    = (string) ( $field->id ?? '' );
				$label = '' !== (string) ( $field->label ?? '' ) ? (string) $field->label : $id;
				if ( 'name' === $type ) {
					/* translators: %s: the field's label */
					$fields[] = array( 'id' => $id . '.3', 'label' => sprintf( __( '%s (first name)', 'meil' ), $label ), 'type' => 'text' );
					/* translators: %s: the field's label */
					$fields[] = array( 'id' => $id . '.6', 'label' => sprintf( __( '%s (last name)', 'meil' ), $label ), 'type' => 'text' );
					continue;
				}
				$fields[] = array(
					'id'    => $id,
					'label' => $label,
					'type'  => 'email' === $type ? 'email' : ( in_array( $type, array( 'checkbox', 'consent' ), true ) ? 'checkbox' : ( 'text' === $type ? 'text' : 'other' ) ),
					'text'  => 'consent' === $type ? wp_strip_all_tags( (string) ( $field->checkboxLabel ?? '' ) ) : implode( ', ', array_map( static function ( $choice ): string {
						return is_array( $choice ) ? (string) ( $choice['text'] ?? '' ) : '';
					}, (array) ( $field->choices ?? array() ) ) ),
				);
			}
			$out[] = array(
				'id'     => (string) ( $form['id'] ?? '' ),
				'title'  => (string) ( $form['title'] ?? '' ),
				'fields' => $fields,
			);
		}
		return $out;
	}

	public function hook(): string {
		return 'gform_after_submission';
	}

	public function hook_args(): int {
		return 2;
	}

	public function read( array $args ): ?array {
		list( $entry, $form ) = $args + array( array(), array() );
		if ( 'spam' === ( $entry['status'] ?? '' ) ) {
			return null;
		}
		$values = array();
		foreach ( (array) $entry as $key => $value ) {
			if ( preg_match( '/^\d+(\.\d+)?$/', (string) $key ) ) {
				$values[ (string) $key ] = $value;
			}
		}
		// A field with several boxes, under its own id: what was ticked.
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$id   = (string) ( $field->id ?? '' );
			$type = (string) ( $field->type ?? '' );
			if ( 'consent' === $type ) {
				$values[ $id ] = $values[ $id . '.1' ] ?? '';
			} elseif ( 'checkbox' === $type ) {
				$picked = array();
				foreach ( $values as $key => $value ) {
					if ( 0 === strpos( $key, $id . '.' ) && '' !== (string) $value ) {
						$picked[] = $value;
					}
				}
				$values[ $id ] = $picked;
			}
		}
		return array( (string) ( $form['id'] ?? '' ), $values );
	}
}
