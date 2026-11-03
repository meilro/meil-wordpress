<?php
/**
 * What every form plugin's adapter does, so Meil_Integrations can treat them
 * the same: say whether the plugin is active, list its forms and their
 * fields, and report a sent form with its values.
 *
 * Field ids are the form plugin's own, as strings; a part of a field (the
 * first name of a Name field) gets an id of its own, such as "3.first".
 * Field types are simplified to email, text and checkbox (anything that can
 * be ticked), or other.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Meil_Integration {

	/** Short and stable: it is part of the saved connections ("cf7:123"). */
	abstract public function key(): string;

	/** The plugin's name, as people know it. */
	abstract public function name(): string;

	abstract public function active(): bool;

	/**
	 * Each field may also have 'text': for a checkbox, the words next to it,
	 * kept with the contact as what the person agreed to.
	 *
	 * @return list<array{id:string,title:string,fields:list<array{id:string,label:string,type:string,text?:string}>}>
	 */
	abstract public function forms(): array;

	/** The plugin's action that runs once a form was sent and accepted. */
	abstract public function hook(): string;

	/** How many arguments that action passes. */
	abstract public function hook_args(): int;

	/**
	 * Reads the action's arguments: the form's id and its values by field id
	 * (a string, or a list for fields with several choices), or null when this
	 * sending is not one to act on (spam, invalid). Meil_Integrations calls it
	 * inside a try, so whatever goes wrong here never breaks the form.
	 *
	 * @param array<int,mixed> $args
	 * @return array{0:string,1:array<string,mixed>}|null
	 */
	abstract public function read( array $args ): ?array;

	/**
	 * Is a checkbox's value a tick? Form plugins send the chosen labels, "1",
	 * "on" or "yes", and an empty value or "0", "off", "no" for none.
	 *
	 * @param mixed $value
	 */
	public static function ticked( $value ): bool {
		if ( is_array( $value ) ) {
			return array() !== array_filter( $value, array( __CLASS__, 'ticked' ) );
		}
		$value = strtolower( trim( (string) $value ) );
		return '' !== $value && ! in_array( $value, array( '0', 'off', 'no', 'false' ), true );
	}
}
