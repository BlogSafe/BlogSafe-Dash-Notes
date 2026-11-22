<?php
/**
 * REST routes used by the dashboard board (namespace blogsafe-dash-notes/v1).
 *
 * Every route needs a logged-in user; the wp_rest nonce sent by the script
 * covers CSRF. Permission checks live in each callback so a note that exists
 * but is not yours answers the same 404 as one that doesn't exist.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Rest {

	const NS = 'blogsafe-dash-notes/v1';

	public static function boot() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		$logged_in = static function () {
			return is_user_logged_in();
		};
		$id_arg = array(
			'id' => array(
				'validate_callback' => static function ( $value ) {
					return is_numeric( $value ) && (int) $value > 0;
				},
			),
		);

		register_rest_route(
			self::NS,
			'/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'board' ),
					'permission_callback' => $logged_in,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => array( __CLASS__, 'can_create' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => $logged_in,
					'args'                => $id_arg,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete' ),
					'permission_callback' => $logged_in,
					'args'                => $id_arg,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/state',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'state' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/items/(?P<item>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'tick' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/replies',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reply' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/replies/(?P<reply>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_reply' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/revisions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'revisions' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/revisions/(?P<revision>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore' ),
				'permission_callback' => $logged_in,
				'args'                => $id_arg,
			)
		);
		register_rest_route(
			self::NS,
			'/templates',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'save_template' ),
				'permission_callback' => array( __CLASS__, 'can_create' ),
			)
		);
		register_rest_route(
			self::NS,
			'/users',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'users' ),
				'permission_callback' => array( __CLASS__, 'can_create' ),
			)
		);
	}

	public static function can_create() {
		return BlogSafe_Dash_Notes_Settings::can_create();
	}

	/**
	 * Transient key for a client's one-time token (32 hex characters), per
	 * user and kind; '' when there is no valid token.
	 */
	private static function token_key( array $params, $user_id, $kind ) {
		$token = isset( $params['token'] ) && is_string( $params['token'] ) ? strtolower( $params['token'] ) : '';
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/', $token ) ) {
			return '';
		}
		return 'bsdn_tok_' . md5( $kind . '|' . (int) $user_id . '|' . $token );
	}

	private static function no_roles() {
		return new WP_Error( 'bsdn_no_roles', __( 'Pick at least one role to send the note to.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
	}

	private static function not_found() {
		return new WP_Error( 'bsdn_not_found', __( 'That note does not exist or is not yours.', 'blogsafe-dash-notes' ), array( 'status' => 404 ) );
	}

	public static function board() {
		BlogSafe_Dash_Notes_Network::sync();
		BlogSafe_Dash_Notes_Store::run_recurring();
		$user_id = get_current_user_id();
		$out     = array();
		foreach ( BlogSafe_Dash_Notes_Store::board( $user_id ) as $row ) {
			$out[] = BlogSafe_Dash_Notes_Store::shape( $row, $user_id );
		}
		return rest_ensure_response( $out );
	}

	public static function create( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$params  = (array) $request->get_json_params();
		// A repeat of a create that already worked (retried after a lost
		// answer, or Save clicked again) returns that note instead of a copy.
		$token_key = self::token_key( $params, $user_id, 'note' );
		$seen      = $token_key ? (int) get_transient( $token_key ) : 0;
		if ( $seen && BlogSafe_Dash_Notes_Store::get( $seen ) ) {
			return rest_ensure_response( BlogSafe_Dash_Notes_Store::shape( BlogSafe_Dash_Notes_Store::get( $seen ), $user_id ) );
		}
		$fields  = BlogSafe_Dash_Notes_Store::clean_fields( $params );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$over = BlogSafe_Dash_Notes_Store::quota_error( $user_id, $fields['audience'] );
		if ( $over ) {
			return $over;
		}
		$recipients = 'users' === $fields['audience'] ? BlogSafe_Dash_Notes_Store::clean_recipients( $params['recipients'] ?? array(), $user_id ) : array();
		if ( 'users' === $fields['audience'] && ! $recipients ) {
			return new WP_Error( 'bsdn_no_recipients', __( 'Pick at least one user to send the note to.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
		}
		if ( 'roles' === $fields['audience'] && '' === $fields['roles'] ) {
			return self::no_roles();
		}
		$screens = BlogSafe_Dash_Notes_Store::clean_screens( $params['screens'] ?? array() );
		if ( is_wp_error( $screens ) ) {
			return $screens;
		}
		$state   = BlogSafe_Dash_Notes_Store::clean_state( (array) ( $params['state'] ?? array() ) );
		$note_id = BlogSafe_Dash_Notes_Store::create( $user_id, $fields, $recipients, $state, $screens );
		if ( ! $note_id ) {
			return new WP_Error( 'bsdn_save_failed', __( 'The note could not be saved.', 'blogsafe-dash-notes' ), array( 'status' => 500 ) );
		}
		if ( array_key_exists( 'items', $params ) ) {
			BlogSafe_Dash_Notes_Items::save( $note_id, BlogSafe_Dash_Notes_Items::clean( $params['items'] ) );
		}
		if ( $token_key ) {
			set_transient( $token_key, $note_id, 15 * MINUTE_IN_SECONDS );
		}
		$response = rest_ensure_response( BlogSafe_Dash_Notes_Store::shape( BlogSafe_Dash_Notes_Store::get( $note_id ), $user_id ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function update( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		if ( ! BlogSafe_Dash_Notes_Store::can_edit( $note, $user_id ) ) {
			return self::not_found();
		}
		$params = (array) $request->get_json_params();
		$fields = BlogSafe_Dash_Notes_Store::clean_fields( $params, true );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$after_title   = $fields['title'] ?? $note->title;
		$after_content = $fields['content'] ?? $note->content;
		if ( '' === $after_title && '' === $after_content ) {
			return new WP_Error( 'bsdn_empty', __( 'A note needs a title or some text.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
		}
		$audience   = $fields['audience'] ?? $note->audience;
		// Turning a private note into a shared one counts against the sharing limit.
		if ( 'private' === $note->audience && 'private' !== $audience && empty( $note->is_template ) && ! BlogSafe_Dash_Notes_Settings::is_manager( $user_id ) ) {
			$over = BlogSafe_Dash_Notes_Store::quota_error( (int) $note->author_id, $audience, (int) $note->id );
			if ( $over ) {
				return $over;
			}
		}
		$recipients = null;
		if ( 'users' === $audience && ( array_key_exists( 'recipients', $params ) || 'users' !== $note->audience ) ) {
			$recipients = BlogSafe_Dash_Notes_Store::clean_recipients( $params['recipients'] ?? array(), (int) $note->author_id );
			if ( ! $recipients ) {
				return new WP_Error( 'bsdn_no_recipients', __( 'Pick at least one user to send the note to.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
			}
		}
		$screens = null;
		if ( array_key_exists( 'screens', $params ) ) {
			$screens = BlogSafe_Dash_Notes_Store::clean_screens( $params['screens'] );
			if ( is_wp_error( $screens ) ) {
				return $screens;
			}
			if ( ! $screens ) {
				return new WP_Error( 'bsdn_no_screens', __( 'Pick at least one place for the note to show.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
			}
		}
		$roles_after = array_key_exists( 'roles', $fields ) ? $fields['roles'] : (string) $note->roles;
		if ( 'roles' === $audience && '' === $roles_after ) {
			return self::no_roles();
		}
		$items = array_key_exists( 'items', $params ) ? BlogSafe_Dash_Notes_Items::clean( $params['items'] ) : null;
		// History: keep the version being replaced.
		if ( BlogSafe_Dash_Notes_Revisions::changes( $note, $fields, $items ) ) {
			BlogSafe_Dash_Notes_Revisions::snapshot( $note, $user_id );
		}
		BlogSafe_Dash_Notes_Store::update( $note, $fields, $recipients, $screens );
		if ( null !== $items ) {
			BlogSafe_Dash_Notes_Items::save( $note->id, $items );
		}
		return rest_ensure_response( BlogSafe_Dash_Notes_Store::shape( BlogSafe_Dash_Notes_Store::get( $note->id ), $user_id ) );
	}

	public static function delete( WP_REST_Request $request ) {
		$note = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		if ( ! BlogSafe_Dash_Notes_Store::can_edit( $note, get_current_user_id() ) ) {
			return self::not_found();
		}
		BlogSafe_Dash_Notes_Store::delete( $note->id );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/** Position, size, stacking, minimized and "remove from my dashboard". */
	public static function state( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		if ( ! BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			return self::not_found();
		}
		$state = BlogSafe_Dash_Notes_Store::clean_state( (array) $request->get_json_params() );
		if ( ! empty( $state['dismissed'] ) && (int) $note->author_id === $user_id ) {
			// Authors delete their notes; they don't hide them from themselves.
			unset( $state['dismissed'] );
		}
		BlogSafe_Dash_Notes_Store::save_state( $note->id, $user_id, $state );
		return rest_ensure_response( array( 'saved' => true ) );
	}

	/** Tick or untick a checklist item: anyone who can see the note. */
	public static function tick( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		if ( ! BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			return self::not_found();
		}
		$done = rest_sanitize_boolean( $request->get_param( 'done' ) );
		if ( ! BlogSafe_Dash_Notes_Items::tick( $note->id, (int) $request['item'], $done, $user_id ) ) {
			return self::not_found();
		}
		return rest_ensure_response( BlogSafe_Dash_Notes_Items::for_note( $note->id ) );
	}

	/** Reply to a note: anyone who can see it. */
	public static function reply( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		if ( ! BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			return self::not_found();
		}
		$text      = BlogSafe_Dash_Notes_Replies::clean( $request->get_param( 'text' ) );
		$token_key = self::token_key( array( 'token' => $request->get_param( 'token' ) ), $user_id, 'reply' );
		if ( $token_key && get_transient( $token_key ) ) {
			// Already added (this is a retry): just return the thread.
			return rest_ensure_response( BlogSafe_Dash_Notes_Replies::for_note( $note->id, $user_id, BlogSafe_Dash_Notes_Store::can_edit( $note, $user_id ) ) );
		}
		if ( '' === $text ) {
			return new WP_Error( 'bsdn_empty_reply', __( 'Write something to reply.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
		}
		if ( ! BlogSafe_Dash_Notes_Replies::add( $note, $user_id, $text ) ) {
			return new WP_Error( 'bsdn_too_many_replies', __( 'This note has reached the maximum number of replies.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
		}
		if ( $token_key ) {
			set_transient( $token_key, 1, 15 * MINUTE_IN_SECONDS );
		}
		return rest_ensure_response( BlogSafe_Dash_Notes_Replies::for_note( $note->id, $user_id, BlogSafe_Dash_Notes_Store::can_edit( $note, $user_id ) ) );
	}

	/** Delete a reply: its writer, or whoever may edit the note. */
	public static function delete_reply( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		$reply   = BlogSafe_Dash_Notes_Replies::get( (int) $request['reply'] );
		if ( ! $note || ! $reply || (int) $reply->note_id !== (int) $note->id || ! BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			return self::not_found();
		}
		$can_edit = BlogSafe_Dash_Notes_Store::can_edit( $note, $user_id );
		if ( ! $can_edit && (int) $reply->user_id !== $user_id ) {
			return self::not_found();
		}
		BlogSafe_Dash_Notes_Replies::delete( $reply->id );
		return rest_ensure_response( BlogSafe_Dash_Notes_Replies::for_note( $note->id, $user_id, $can_edit ) );
	}

	/** Earlier versions of a note: whoever may edit it. */
	public static function revisions( WP_REST_Request $request ) {
		$note = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		if ( ! BlogSafe_Dash_Notes_Store::can_edit( $note, get_current_user_id() ) ) {
			return self::not_found();
		}
		return rest_ensure_response( BlogSafe_Dash_Notes_Revisions::for_note( $note->id ) );
	}

	/** Put an earlier version back (the current one is kept as history). */
	public static function restore( WP_REST_Request $request ) {
		$user_id  = get_current_user_id();
		$note     = BlogSafe_Dash_Notes_Store::get( (int) $request['id'] );
		$revision = BlogSafe_Dash_Notes_Revisions::get( (int) $request['revision'] );
		if ( ! BlogSafe_Dash_Notes_Store::can_edit( $note, $user_id ) || ! $revision || (int) $revision->note_id !== (int) $note->id ) {
			return self::not_found();
		}
		$done = BlogSafe_Dash_Notes_Revisions::restore( $note, $revision, $user_id );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return rest_ensure_response( BlogSafe_Dash_Notes_Store::shape( BlogSafe_Dash_Notes_Store::get( $note->id ), $user_id ) );
	}

	/** Save a note the user can see as one of their templates. */
	public static function save_template( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( absint( $request->get_param( 'note' ) ) );
		if ( ! $note || ! BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			return self::not_found();
		}
		$id = BlogSafe_Dash_Notes_Store::make_template( $note, $user_id );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$response = rest_ensure_response( BlogSafe_Dash_Notes_Store::template_shape( BlogSafe_Dash_Notes_Store::get( $id ) ) );
		$response->set_status( 201 );
		return $response;
	}

	/** Recipient picker search: id and display name only, never emails. */
	public static function users( WP_REST_Request $request ) {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$args   = array(
			'number'  => 20,
			'orderby' => 'display_name',
			'fields'  => array( 'ID', 'display_name' ),
			'exclude' => array( get_current_user_id() ),
		);
		if ( '' !== $search ) {
			$args['search']         = '*' . $search . '*';
			// Display names only. Not user_login, and not user_nicename either
			// (WordPress builds it from the login): matching on them would let
			// note writers confirm login names.
			$args['search_columns'] = array( 'display_name' );
		}
		if ( ! is_multisite() ) {
			$args['blog_id'] = 0;
		}
		$out = array();
		foreach ( get_users( $args ) as $user ) {
			$out[] = array(
				'id'   => (int) $user->ID,
				'name' => (string) $user->display_name,
			);
		}
		return rest_ensure_response( $out );
	}
}
