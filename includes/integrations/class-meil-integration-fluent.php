<?php
/**
 * Fluent Forms. Fields can sit inside columns, so the form is walked through;
 * a Name field offers its first and last parts on their own.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Integration_Fluent extends Meil_Integration {

	public function key(): string {
		return 'fluent';
	}

	public function name(): string {
		return 'Fluent Forms';
	}

	public function active(): bool {
		return defined( 'FLUENTFORM' ) || function_exists( 'wpFluentForm' );
	}

	public function forms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fluent Forms' own table, read only.
		$rows = (array) $wpdb->get_results( "SELECT id, title, form_fields FROM {$wpdb->prefix}fluentform_forms ORDER BY id DESC", ARRAY_A );
		$out  = array();
		foreach ( $rows as $row ) {
			$data   = json_decode( (string) $row['form_fields'], true );
			$fields = array();
			self::walk( (array) ( $data['fields'] ?? array() ), $fields );
			$out[] = array(
				'id'     => (string) $row['id'],
				'title'  => (string) $row['title'],
				'fields' => $fields,
			);
		}
		return $out;
	}

	/**
	 * @param array<int,mixed>                                  $elements
	 * @param list<array{id:string,label:string,type:string}> $fields
	 */
	private static function walk( array $elements, array &$fields ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			foreach ( (array) ( $element['columns'] ?? array() ) as $column ) {
				self::walk( (array) ( $column['fields'] ?? array() ), $fields );
			}
			$kind  = (string) ( $element['element'] ?? '' );
			$name  = (string) ( $element['attributes']['name'] ?? '' );
			$label = wp_strip_all_tags( (string) ( $element['settings']['label'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			if ( 'input_name' === $kind ) {
				foreach ( (array) ( $element['fields'] ?? array() ) as $part => $sub ) {
					if ( in_array( $part, array( 'first_name', 'last_name' ), true ) && ! empty( $sub['settings']['visible'] ) ) {
						$fields[] = array(
							'id'    => $name . '.' . $part,
							'label' => wp_strip_all_tags( (string) ( $sub['settings']['label'] ?? $part ) ),
							'type'  => 'text',
						);
					}
				}
				continue;
			}
			if ( 'terms_and_condition' === $kind || 'gdpr_agreement' === $kind ) {
				$label = wp_trim_words( wp_strip_all_tags( (string) ( $element['settings']['tnc_html'] ?? $label ) ), 10 );
			}
			$fields[] = array(
				'id'    => $name,
				'label' => '' !== $label ? $label : $name,
				'type'  => 'input_email' === $kind ? 'email' : ( in_array( $kind, array( 'input_checkbox', 'terms_and_condition', 'gdpr_agreement' ), true ) ? 'checkbox' : ( 'input_text' === $kind ? 'text' : 'other' ) ),
			);
		}
	}

	public function hook(): string {
		return 'fluentform/submission_inserted';
	}

	public function hook_args(): int {
		return 3;
	}

	public function read( array $args ): ?array {
		list( , $form_data, $form ) = $args + array( 0, array(), null );
		$values = array();
		foreach ( (array) $form_data as $name => $value ) {
			$values[ (string) $name ] = $value;
			// A Name field comes as an array of its parts.
			if ( is_array( $value ) && ( isset( $value['first_name'] ) || isset( $value['last_name'] ) ) ) {
				foreach ( $value as $part => $text ) {
					$values[ $name . '.' . $part ] = $text;
				}
			}
		}
		return array( (string) ( is_object( $form ) ? $form->id : ( $form['id'] ?? '' ) ), $values );
	}
}
