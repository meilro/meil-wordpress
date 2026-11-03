<?php
/**
 * Elementor Pro forms. They are not posts of their own but widgets inside
 * pages and templates, so they are found by reading Elementor's page data;
 * a form is "page id:widget id". Fields are known by their ID (custom_id),
 * which is also how Elementor reports a sent form.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Integration_Elementor extends Meil_Integration {

	public function key(): string {
		return 'elementor';
	}

	public function name(): string {
		return 'Elementor Pro';
	}

	public function active(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	public function forms(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- finding Elementor's form widgets, admin only.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
				 WHERE m.meta_key = '_elementor_data' AND m.meta_value LIKE %s AND p.post_status IN ('publish', 'private') ORDER BY p.ID DESC LIMIT 200",
				'%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%'
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $row ) {
			$found = array();
			self::walk( (array) json_decode( (string) $row['meta_value'], true ), $found );
			foreach ( $found as $widget ) {
				$settings = (array) ( $widget['settings'] ?? array() );
				$fields   = array();
				foreach ( (array) ( $settings['form_fields'] ?? array() ) as $field ) {
					$id   = (string) ( $field['custom_id'] ?? '' );
					$type = (string) ( $field['field_type'] ?? 'text' );
					if ( '' === $id ) {
						continue;
					}
					$fields[] = array(
						'id'    => $id,
						'label' => '' !== (string) ( $field['field_label'] ?? '' ) ? (string) $field['field_label'] : $id,
						'type'  => 'email' === $type ? 'email' : ( in_array( $type, array( 'acceptance', 'checkbox' ), true ) ? 'checkbox' : ( in_array( $type, array( 'text', 'tel' ), true ) ? 'text' : 'other' ) ),
						'text'  => wp_strip_all_tags( (string) ( 'acceptance' === $type ? ( $field['acceptance_text'] ?? '' ) : str_replace( "\n", ', ', (string) ( $field['field_options'] ?? '' ) ) ) ),
					);
				}
				$out[] = array(
					'id'     => $row['ID'] . ':' . $widget['id'],
					/* translators: 1: the form's name, 2: the page it is on */
					'title'  => sprintf( __( '%1$s, on %2$s', 'meilro' ), (string) ( $settings['form_name'] ?? __( 'Form', 'meilro' ) ), (string) $row['post_title'] ),
					'fields' => $fields,
				);
			}
		}
		return $out;
	}

	/**
	 * @param array<int|string,mixed>   $elements
	 * @param list<array<string,mixed>> $found
	 */
	private static function walk( array $elements, array &$found ): void {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			if ( 'form' === ( $element['widgetType'] ?? '' ) && isset( $element['id'] ) ) {
				$found[] = $element;
			}
			self::walk( (array) ( $element['elements'] ?? array() ), $found );
		}
	}

	public function hook(): string {
		return 'elementor_pro/forms/new_record';
	}

	public function hook_args(): int {
		return 1;
	}

	public function read( array $args ): ?array {
		$record = $args[0] ?? null;
		if ( ! is_object( $record ) ) {
			return null;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Elementor has checked the sending; this only reads which form it was.
		$widget = (string) $record->get_form_settings( 'id' );
		if ( '' === $widget && isset( $_POST['form_id'] ) ) {
			$widget = sanitize_text_field( wp_unslash( (string) $_POST['form_id'] ) );
		}
		$page = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$values = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$values[ (string) $id ] = $field['value'] ?? '';
		}
		return array( $page . ':' . $widget, $values );
	}
}
