<?php
/**
 * Blocks for the block editor: "meil.ro form" (one of the forms built under
 * meil.ro > Forms, picked in the block) and "meil.ro email preferences". They
 * draw exactly what [meil_form id="…"] and [meil_preferences] draw, which
 * keep working as before. No build step: assets/blocks.js uses the editor's
 * own scripts, and its texts come from here, translated like the rest.
 *
 * @package Meil_Mailer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meil_Blocks {

	/** @var Meil_Forms */
	private $forms;

	/** @var Meil_Preferences */
	private $preferences;

	public function __construct( Meil_Forms $forms, Meil_Preferences $preferences ) {
		$this->forms       = $forms;
		$this->preferences = $preferences;

		add_action( 'init', array( $this, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ) );
	}

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'meil-blocks',
			MEIL_MAILER_URL . 'assets/blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render' ),
			meil_asset_version( 'assets/blocks.js' ),
			true
		);
		// The form looks in the editor as it will on the page, unless the
		// plugin's styles are turned off.
		$styled = ! empty( meil_mailer_settings()['load_css'] );
		if ( $styled ) {
			wp_register_style( 'meil-forms-editor', MEIL_MAILER_URL . 'assets/forms.css', array(), meil_asset_version( 'assets/forms.css' ) );
		}

		register_block_type(
			'meil/form',
			array(
				'api_version'     => 2,
				'editor_script'   => 'meil-blocks',
				'editor_style'    => $styled ? 'meil-forms-editor' : null,
				'attributes'      => array(
					'formId' => array(
						'type'    => 'number',
						'default' => 0,
					),
				),
				'render_callback' => function ( array $attributes ): string {
					return $this->forms->render_shortcode( array( 'id' => (int) ( $attributes['formId'] ?? 0 ) ) );
				},
			)
		);
		register_block_type(
			'meil/preferences',
			array(
				'api_version'     => 2,
				'editor_script'   => 'meil-blocks',
				'render_callback' => function (): string {
					return $this->preferences->render_shortcode( array() );
				},
			)
		);
	}

	/** The forms to pick from, and the texts, for assets/blocks.js. */
	public function editor_data(): void {
		$forms = get_posts(
			array(
				'post_type'      => Meil_Forms::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		$data = array(
			'forms'     => array_map(
				static function ( WP_Post $post ): array {
					return array(
						'id'    => $post->ID,
						/* translators: %d: form id */
						'title' => '' !== $post->post_title ? $post->post_title : sprintf( __( 'Form %d', 'meil' ), $post->ID ),
					);
				},
				$forms
			),
			'canManage' => current_user_can( 'manage_options' ),
			'newUrl'    => admin_url( 'post-new.php?post_type=' . Meil_Forms::POST_TYPE ),
			'manageUrl' => admin_url( 'edit.php?post_type=' . Meil_Forms::POST_TYPE ),
			'strings'   => array(
				'formTitle'        => __( 'meil.ro form', 'meil' ),
				'formDescription'  => __( 'A subscription form built under meil.ro > Forms.', 'meil' ),
				'prefsTitle'       => __( 'meil.ro email preferences', 'meil' ),
				'prefsDescription' => __( 'Logged-in users choose which of your lists they get.', 'meil' ),
				'prefsPreview'     => __( 'Email preferences: on the page, logged-in users see your public lists here and tick the ones they want. Visitors who are not logged in see a short message.', 'meil' ),
				'form'             => __( 'Form', 'meil' ),
				'choose'           => __( 'Choose a form', 'meil' ),
				'pick'             => __( 'Choose which form to show.', 'meil' ),
				'noForms'          => __( 'There are no forms yet. Build one under meil.ro > Forms, then pick it here.', 'meil' ),
				'noFormsEditor'    => __( 'There are no forms yet. An administrator can build one under meil.ro > Forms.', 'meil' ),
				'create'           => __( 'Build a form', 'meil' ),
				'manage'           => __( 'Manage forms', 'meil' ),
				'keywords'         => array( __( 'newsletter', 'meil' ), __( 'subscribe', 'meil' ), 'email' ),
			),
		);
		wp_add_inline_script( 'meil-blocks', 'window.meilBlocks = ' . wp_json_encode( $data ) . ';', 'before' );
	}
}
