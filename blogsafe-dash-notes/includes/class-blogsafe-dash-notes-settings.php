<?php
/**
 * Plugin settings (one option) and the capability checks built on them.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Settings {

	const OPTION = 'blogsafe_dash_notes_settings';

	/** Capability that lets a user create notes. Admins pick from these. */
	public static function create_cap_choices() {
		return array(
			'read'              => __( 'Every user (Subscribers and up)', 'blogsafe-dash-notes' ),
			'edit_posts'        => __( 'Contributors and up', 'blogsafe-dash-notes' ),
			'publish_posts'     => __( 'Authors and up', 'blogsafe-dash-notes' ),
			'edit_others_posts' => __( 'Editors and up', 'blogsafe-dash-notes' ),
			'manage_options'    => __( 'Administrators only', 'blogsafe-dash-notes' ),
		);
	}

	public static function defaults() {
		return array(
			'create_cap' => 'edit_posts',
		);
	}

	public static function get() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		return array_merge( self::defaults(), array_intersect_key( $saved, self::defaults() ) );
	}

	public static function sanitize( $input ) {
		$out   = self::defaults();
		$input = is_array( $input ) ? $input : array();
		if ( isset( $input['create_cap'] ) && array_key_exists( (string) $input['create_cap'], self::create_cap_choices() ) ) {
			$out['create_cap'] = (string) $input['create_cap'];
		}
		return $out;
	}

	public static function create_cap() {
		$cap = self::get()['create_cap'];
		return array_key_exists( $cap, self::create_cap_choices() ) ? $cap : 'edit_posts';
	}

	/** Can this user write new notes? */
	public static function can_create( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return $user_id > 0 && user_can( $user_id, self::create_cap() );
	}

	/** Can this user see, edit and delete every note on the site? */
	public static function is_manager( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return $user_id > 0 && user_can( $user_id, 'manage_options' );
	}
}
