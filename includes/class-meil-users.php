<?php
/**
 * Keeps meil.ro in step with WordPress users.
 *
 * - A user's email address changes: their lists move to the new address. The
 *   API cannot rename a contact, so the new address is added to the lists the
 *   old one was subscribed to, with the same name and properties, and the old
 *   contact is then deleted. meil.ro's suppression list keeps the old address,
 *   as it should.
 * - A user is deleted, and "Delete the meil.ro contact" is on in the settings:
 *   their contact is deleted too, with its data.
 *
 * Addresses meil.ro does not know are left alone. A failed call never blocks
 * the WordPress change itself.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Users {

	/** @var Meil_Client */
	private $client;

	/** @var array<string,mixed> */
	private $settings;

	public function __construct( Meil_Client $client, array $settings ) {
		$this->client   = $client;
		$this->settings = $settings;

		if ( ! $client->is_configured() ) {
			return;
		}
		add_action( 'profile_update', array( $this, 'email_changed' ), 10, 2 );
		if ( ! empty( $settings['delete_with_user'] ) ) {
			add_action( 'delete_user', array( $this, 'user_deleted' ) );
			add_action( 'wpmu_delete_user', array( $this, 'user_deleted' ) );
		}
	}

	/**
	 * @param int           $user_id
	 * @param WP_User|mixed $old_user The user as it was before the change.
	 */
	public function email_changed( $user_id, $old_user ): void {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old_user instanceof WP_User ) {
			return;
		}
		$old = strtolower( (string) $old_user->user_email );
		$new = strtolower( (string) $user->user_email );
		if ( '' === $old || '' === $new || $old === $new ) {
			return;
		}

		$contact = $this->client->get_contact( $old );
		if ( is_wp_error( $contact ) ) {
			return; // Not a contact (404), or meil.ro cannot be reached: nothing to move.
		}

		$subscribed = array();
		foreach ( (array) ( $contact['audiences'] ?? array() ) as $membership ) {
			if ( is_array( $membership ) && ! empty( $membership['subscribed'] ) ) {
				$subscribed[] = (string) $membership['audience_id'];
			}
		}

		if ( array() !== $subscribed ) {
			$payload = array(
				'audience_ids' => $subscribed,
				'email'        => $new,
				'first_name'   => (string) ( $contact['first_name'] ?? '' ),
				'last_name'    => (string) ( $contact['last_name'] ?? '' ),
			);
			$properties = (array) ( $contact['properties'] ?? array() );
			if ( array() !== $properties ) {
				$payload['properties'] = $properties;
			}
			if ( is_wp_error( $this->client->create_contact( $payload ) ) ) {
				return; // Keep the old contact rather than lose the person.
			}
		}
		$this->client->delete_contact( $old );
	}

	/** @param int $user_id */
	public function user_deleted( $user_id ): void {
		$user = get_userdata( (int) $user_id );
		if ( $user && '' !== (string) $user->user_email ) {
			$this->client->delete_contact( (string) $user->user_email );
		}
	}
}
