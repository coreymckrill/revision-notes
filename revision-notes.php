<?php
/**
 * Plugin Name: Revision Notes
 * Plugin URI: http://wordpress.org/plugins/revision-notes/
 * Description: Add a note explaining the changes you're about to save. It's like commit messages, except for your WordPress content.
 * Version: 1.1
 * Author: Helen Hou-Sandí
 * Author URI: https://helen.blog/
 * Text Domain: revision-notes
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'WPINC' ) or die;

// Cheapo namespace; this isn't a real object or very testable (right now).
class HHS_Revision_Notes {

	public function __construct() {
		add_action( 'init', array( $this, 'init' ), 99 );
	}

	public function init() {
		add_action( 'post_submitbox_misc_actions', array( $this, 'edit_field' ) );
		add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );

		add_filter( 'wp_prepare_revision_for_js', array( $this, 'wp_prepare_revision_for_js' ), 10, 2 );
		add_filter( 'wp_post_revision_title_expanded', array( $this, 'wp_post_revision_title_expanded' ), 10, 2 );

		// Use post_type_supports() to make showing/hiding of the field easy for devs.
		// By default we'll show it for any post type that has an edit UI.
		$post_types = get_post_types( array( 'show_ui' => true ) );

		if ( ! empty( $post_types ) ) {
			foreach ( $post_types as $post_type ) {
				add_post_type_support( $post_type, 'revision-notes' );

				add_action( "manage_edit-{$post_type}_columns", array( $this, 'add_column' ) );
				add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'show_column' ), 10, 2 );
			}
		}

		add_action( 'enqueue_block_editor_assets', array( $this, 'block_editor_assets' ) );
	}

	public function edit_field() {
		$post = get_post();
		if ( ! $post ) {
			return;
		}

		if ( ! post_type_supports( $post->post_type, 'revision-notes' ) ) {
			return;
		}

		wp_nonce_field( 'hhs-revision-notes-save', 'hhs_revision_notes_nonce' );
?>
<div class="misc-pub-section revision-note">
<label><?php _e( 'Revision note (optional)', 'revision-notes' ); ?>
<input name="hhs_revision_note" type="text" class="widefat" maxlength="100" />
</label>
<p class="description"><?php _e( 'Enter a brief note about this change', 'revision-notes' ); ?></p>
</div>
<?php
	}

	public function save_post( $post_id, $post ) {
		// verify nonce
		if ( ! isset( $_POST['hhs_revision_notes_nonce'] ) ||
			! wp_verify_nonce( $_POST['hhs_revision_notes_nonce'], 'hhs-revision-notes-save' )
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// We don't need to bother with empties or deleting existing notes.
		if ( ! isset( $_POST['hhs_revision_note'] ) || empty( $_POST['hhs_revision_note'] ) ) {
			return;
		}

		$note = wp_strip_all_tags( $_POST['hhs_revision_note'] );

		// Save the note as meta on the revision itself.
		// save_post actually runs a second time on the parent post,
		// so it will also be stored as the latest note in the parent post's meta.
		update_metadata( 'post', $post_id, 'revision_note', $note );
	}

	public function wp_prepare_revision_for_js( $data, $revision ) {

		$note = esc_html( get_metadata( 'post', $revision->ID, 'revision_note', true ) );

		if ( ! empty( $note ) ) {
			/* Translators: 1: revision note; 2: time ago; */
			$data['timeAgo'] = sprintf( __( 'Note: %1$s - %2$s', 'revision-notes' ), $note, $data['timeAgo'] );
		}

		return $data;
	}

	public function wp_post_revision_title_expanded( $text, $revision ) {
		// Some safeguards in case this is being called by somebody else for something else.
		// We may want to do these checks elsewhere so that this function can still be used
		// in other contexts should a developer want to do so.
		if ( ! is_admin() ) {
			return $text;
		}

		$screen = get_current_screen();

		if ( 'post' !== $screen->base ) {
			return $text;
		}

		$note = get_metadata( 'post', $revision->ID, 'revision_note', true );

		if ( ! empty( $note ) ) {
			$text .= ' &mdash; <em>' . esc_html( $note ) . '</em>';
		}

		return $text;
	}

	public function add_column( $columns ) {
		$columns['revision_note'] = __( 'Latest Revision Note', 'revision-notes' );

		return $columns;
	}

	public function show_column( $column, $post_id ) {
		if ( 'revision_note' !== $column ) {
			return;
		}

		$note = get_post_meta( $post_id, 'revision_note', true );

		if ( empty( $note ) ) {
			return;
		}

		echo esc_html( $note );
	}

	public function block_editor_assets() {
		$script_asset = require 'build/index.asset.php';

		wp_enqueue_script(
			'revision-notes-block-editor',
			plugin_dir_url( __FILE__ ) . 'build/index.js',
			$script_asset['dependencies'],
			$script_asset['version']
		);
	}
}

// This is a global so it can be accessed by others for things like hooks.
// It could be a singleton too, I guess.
$hhs_revision_notes = new HHS_Revision_Notes();
