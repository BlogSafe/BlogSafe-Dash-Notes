<?php
/**
 * All database access for notes and per-user note state.
 *
 * Visibility rule (used everywhere): a user sees a note when they wrote it,
 * when its audience is "all", or when its audience is "users" and they are a
 * recipient. Managers (manage_options) can also open any note from the list
 * page, but only notes they can see appear on their own dashboard.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Store {

	const AUDIENCES      = array( 'private', 'users', 'roles', 'all', 'network' );
	const RECUR          = array( '', 'daily', 'weekly', 'monthly' );
	const MAX_TEMPLATES  = 50;
	const MAX_TITLE      = 200;
	const MAX_CONTENT    = 100000; // bytes of filtered HTML

	/** Core media shortcodes that may run inside notes. No others ever run. */
	const MEDIA_SHORTCODES = array( 'audio', 'video', 'playlist', 'gallery', 'caption', 'wp_caption', 'embed' );
	const MAX_RECIPIENTS = 100;

	/**
	 * CSS a note may not use: it would let the note's content leave the note
	 * and sit on top of the admin page (a full-screen layer over wp-admin).
	 */
	const BLOCKED_CSS = array( 'position', 'top', 'right', 'bottom', 'left', 'inset', 'z-index' );

	/** Players (YouTube, Vimeo, …) shown per note; further links stay plain links. */
	const MAX_EMBEDS = 5;

	/** Players looked up per page load, over all notes on the page. */
	const MAX_EMBEDS_PAGE = 20;

	/** Notes one user may have (templates aside). Administrators have no limit. */
	const MAX_NOTES_PER_AUTHOR = 300;

	/** Of those, notes shared with other users at one time. */
	const MAX_SHARED_PER_AUTHOR = 50;
	const BOARD_LIMIT    = 200;
	const MAX_SCREENS    = 20;
	const ALL_SCREENS    = '*';
	const DEFAULT_COLOR  = '#fff59d';

	/** @var array<int,string> display names cached for this request */
	private static $names = array();

	public static function boot() {
		add_action( 'deleted_user', array( __CLASS__, 'forget_user' ) );
		add_action( 'remove_user_from_blog', array( __CLASS__, 'forget_user' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Validation                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Clean note fields sent by a browser. With $partial, only the keys that
	 * were sent come back; otherwise missing keys get defaults.
	 *
	 * @return array|WP_Error
	 */
	public static function clean_fields( array $input, $partial = false ) {
		$out = array();

		if ( array_key_exists( 'title', $input ) || ! $partial ) {
			$title        = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
			$out['title'] = mb_substr( $title, 0, self::MAX_TITLE );
		}
		if ( array_key_exists( 'content', $input ) || ! $partial ) {
			// Same allowlist WordPress applies to posts by users without
			// unfiltered_html — for everyone, admins included, because notes
			// are shown on other users' dashboards.
			$content = trim( self::filter_content( $input['content'] ?? '' ) );
			if ( strlen( $content ) > self::MAX_CONTENT ) {
				return new WP_Error( 'bsdn_too_long', __( 'The note is too long.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
			}
			$out['content'] = $content;
		}
		if ( array_key_exists( 'color', $input ) || ! $partial ) {
			$out['color'] = self::clean_color( $input['color'] ?? '' );
		}
		if ( array_key_exists( 'priority', $input ) || ! $partial ) {
			$priority        = (int) ( $input['priority'] ?? 0 );
			$out['priority'] = min( 2, max( 0, $priority ) );
		}
		if ( array_key_exists( 'deadline', $input ) || ! $partial ) {
			$deadline = trim( (string) ( $input['deadline'] ?? '' ) );
			if ( '' === $deadline ) {
				$out['deadline'] = null;
			} elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $deadline, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
				$out['deadline'] = $deadline;
			} else {
				return new WP_Error( 'bsdn_bad_deadline', __( 'The deadline is not a valid date.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
			}
		}
		if ( array_key_exists( 'audience', $input ) || ! $partial ) {
			$audience = (string) ( $input['audience'] ?? 'private' );
			if ( ! in_array( $audience, self::AUDIENCES, true ) || ( 'network' === $audience && ! BlogSafe_Dash_Notes_Network::can_write() ) ) {
				return new WP_Error( 'bsdn_bad_audience', __( 'Unknown audience.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
			}
			$out['audience'] = $audience;
		}
		if ( array_key_exists( 'roles', $input ) || ! $partial ) {
			$out['roles'] = self::clean_roles( $input['roles'] ?? array() );
		}
		if ( array_key_exists( 'recur', $input ) || ! $partial ) {
			$recur        = (string) ( $input['recur'] ?? '' );
			$out['recur'] = in_array( $recur, self::RECUR, true ) ? $recur : '';
		}
		if ( array_key_exists( 'anchor', $input ) ) {
			$out['anchor'] = self::clean_anchor( $input['anchor'] );
		}

		if ( ! $partial && '' === $out['title'] && '' === $out['content'] ) {
			return new WP_Error( 'bsdn_empty', __( 'A note needs a title or some text.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * Note HTML through the post allowlist, minus the CSS that positions an
	 * element outside its place (see BLOCKED_CSS). Used on save and again on
	 * display, so notes saved before this rule are covered too.
	 */
	public static function filter_content( $html ) {
		add_filter( 'safe_style_css', array( __CLASS__, 'note_style_css' ), 999 );
		try {
			$html = wp_kses_post( (string) $html );
		} finally {
			remove_filter( 'safe_style_css', array( __CLASS__, 'note_style_css' ), 999 );
		}
		return $html;
	}

	/** The CSS properties WordPress allows in posts, without BLOCKED_CSS. */
	public static function note_style_css( $properties ) {
		return array_values( array_diff( (array) $properties, self::BLOCKED_CSS ) );
	}

	/**
	 * Is this author at a limit? Administrators have none. Others may hold
	 * MAX_NOTES_PER_AUTHOR notes, MAX_SHARED_PER_AUTHOR of them shared.
	 *
	 * @param string $audience the audience the note is about to have
	 * @param int    $note_id  the note being changed (0 for a new note)
	 * @return WP_Error|null
	 */
	public static function quota_error( $author_id, $audience, $note_id = 0 ) {
		global $wpdb;
		if ( BlogSafe_Dash_Notes_Settings::is_manager( $author_id ) ) {
			return null;
		}
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, COALESCE( SUM( audience <> 'private' ), 0 ) AS shared FROM {$notes} WHERE author_id = %d AND is_template = 0 AND network_id = 0 AND id <> %d", $author_id, $note_id ) );
		if ( ! $note_id && $row && (int) $row->total >= self::MAX_NOTES_PER_AUTHOR ) {
			/* translators: %d: the most notes one user may have. */
			return new WP_Error( 'bsdn_too_many_notes', sprintf( __( 'You have reached the limit of %d notes. Delete some you no longer need under Dash Notes > All Notes.', 'blogsafe-dash-notes' ), self::MAX_NOTES_PER_AUTHOR ), array( 'status' => 400 ) );
		}
		if ( 'private' !== $audience && $row && (int) $row->shared >= self::MAX_SHARED_PER_AUTHOR ) {
			/* translators: %d: the most notes one user may share at a time. */
			return new WP_Error( 'bsdn_too_many_shared', sprintf( __( 'You can share at most %d notes with other users at a time. Delete one, or make one "Only me", first.', 'blogsafe-dash-notes' ), self::MAX_SHARED_PER_AUTHOR ), array( 'status' => 400 ) );
		}
		return null;
	}

	public static function clean_color( $color ) {
		$color = sanitize_hex_color( (string) $color );
		if ( ! $color ) {
			return self::DEFAULT_COLOR;
		}
		if ( 4 === strlen( $color ) ) {
			$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
		}
		return strtolower( $color );
	}

	/**
	 * Keep only real users of this site, never the author, at most 100.
	 *
	 * @return int[]
	 */
	public static function clean_recipients( $ids, $author_id ) {
		$ids = is_array( $ids ) ? $ids : array();
		$ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );
		$ids = array_diff( $ids, array( (int) $author_id ) );
		$ids = array_slice( array_values( $ids ), 0, self::MAX_RECIPIENTS );
		if ( ! $ids ) {
			return array();
		}
		$args = array(
			'include' => $ids,
			'fields'  => 'ID',
			'number'  => self::MAX_RECIPIENTS,
		);
		if ( ! is_multisite() ) {
			$args['blog_id'] = 0;
		}
		return array_map( 'intval', get_users( $args ) );
	}

	/**
	 * A CSS selector for "pin to a page element". It is only ever handed to
	 * querySelector() in the browser (never put into HTML), so it just has
	 * to be a short, plain string: no tags, no control characters, at most
	 * 500 characters. Anything else clears the pin.
	 */
	public static function clean_anchor( $selector ) {
		$selector = is_string( $selector ) ? trim( wp_check_invalid_utf8( $selector ) ) : '';
		if ( '' === $selector || strlen( $selector ) > 500 || preg_match( '/[<\x00-\x1F\x7F]/', $selector ) ) {
			return '';
		}
		return $selector;
	}

	/** Role keys of this site only, as a comma list for the roles column. */
	public static function clean_roles( $roles ) {
		$roles = is_array( $roles ) ? array_map( 'strval', $roles ) : array();
		$known = array_keys( wp_roles()->get_names() );
		return implode( ',', array_values( array_intersect( $known, $roles ) ) );
	}

	/** Roles the user has on the current site. */
	public static function user_roles( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return $user ? array_values( array_map( 'strval', (array) $user->roles ) ) : array();
	}

	/**
	 * When a snooze ends, in UTC: "tomorrow", "week" (7 days) or a future
	 * Y-m-d date, each at the start of that day in the site's time zone.
	 * Anything else ends the snooze (null).
	 */
	public static function snooze_until( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		if ( 'tomorrow' === $value ) {
			$when = $today->modify( '+1 day' );
		} elseif ( 'week' === $value ) {
			$when = $today->modify( '+7 days' );
		} elseif ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			$when = new DateTimeImmutable( $value . ' 00:00:00', $tz );
			if ( $when <= $today ) {
				return null;
			}
		} else {
			return null;
		}
		return $when->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The visibility rule as SQL (aliases n = notes, u = this user's state
	 * row): the user wrote it, or it is for everyone, or they are a chosen
	 * recipient, or it is for one of their roles. Templates are never on
	 * anyone's board or inbox.
	 *
	 * @return array{0: string, 1: array} SQL with placeholders, and values
	 */
	public static function visibility_sql( $user_id ) {
		$sql    = "n.is_template = 0 AND ( n.author_id = %d OR n.audience IN ( 'all', 'network' ) OR ( n.audience = 'users' AND u.is_recipient = 1 )";
		$params = array( (int) $user_id );
		foreach ( self::user_roles( $user_id ) as $role ) {
			$sql     .= " OR ( n.audience = 'roles' AND FIND_IN_SET( %s, n.roles ) )";
			$params[] = $role;
		}
		return array( $sql . ' )', $params );
	}

	/** Viewer-state values (position, size, order, flags), clamped. */
	public static function clean_state( array $input ) {
		$out    = array();
		$limits = array(
			'pos_x'   => array( 'x', 0, 20000 ),
			'pos_y'   => array( 'y', 0, 50000 ),
			'width'   => array( 'w', 160, 900 ),
			'height'  => array( 'h', 80, 1200 ),
			'z_order' => array( 'z', 0, 1000000 ),
		);
		foreach ( $limits as $column => $spec ) {
			list( $key, $min, $max ) = $spec;
			if ( isset( $input[ $key ] ) && is_numeric( $input[ $key ] ) ) {
				$out[ $column ] = min( $max, max( $min, (int) round( (float) $input[ $key ] ) ) );
			}
		}
		foreach ( array( 'minimized', 'dismissed', 'locked' ) as $flag ) {
			if ( array_key_exists( $flag, $input ) ) {
				$out[ $flag ] = rest_sanitize_boolean( $input[ $flag ] ) ? 1 : 0;
			}
		}
		if ( array_key_exists( 'done', $input ) ) {
			$out['done_at'] = rest_sanitize_boolean( $input['done'] ) ? current_time( 'mysql', true ) : null;
		}
		if ( array_key_exists( 'snooze', $input ) ) {
			$out['snoozed_until'] = self::snooze_until( $input['snooze'] );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                            */
	/* ------------------------------------------------------------------ */

	public static function get( $note_id ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$notes} WHERE id = %d", $note_id ) );
	}

	public static function user_row( $note_id, $user_id ) {
		global $wpdb;
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$users} WHERE note_id = %d AND user_id = %d", $note_id, $user_id ) );
	}

	/** Can $user_id see this note on their own dashboard / inbox? */
	public static function is_audience( $note, $user_id ) {
		if ( ! $note || ! $user_id ) {
			return false;
		}
		if ( (int) $note->author_id === (int) $user_id ) {
			return true;
		}
		if ( ! empty( $note->is_template ) ) {
			return false; // Templates belong to their author only.
		}
		if ( 'all' === $note->audience || 'network' === $note->audience ) {
			return true;
		}
		if ( 'users' === $note->audience ) {
			$row = self::user_row( $note->id, $user_id );
			return $row && (int) $row->is_recipient;
		}
		if ( 'roles' === $note->audience ) {
			$note_roles = array_filter( explode( ',', (string) $note->roles ) );
			return (bool) array_intersect( $note_roles, self::user_roles( $user_id ) );
		}
		return false;
	}

	public static function can_view( $note, $user_id ) {
		return self::is_audience( $note, $user_id ) || ( $note && BlogSafe_Dash_Notes_Settings::is_manager( $user_id ) );
	}

	/** Only the author (who must still be allowed to write notes) or a manager may change a note. */
	public static function can_edit( $note, $user_id ) {
		if ( ! $note || ! $user_id ) {
			return false;
		}
		if ( ! empty( $note->network_id ) ) {
			return false; // A copy of a network note: edit it on the main site.
		}
		if ( 'network' === $note->audience && is_multisite() && ! BlogSafe_Dash_Notes_Network::can_write( $user_id ) ) {
			return false; // Shown on every site: only a super admin changes it.
		}
		if ( BlogSafe_Dash_Notes_Settings::is_manager( $user_id ) ) {
			return true;
		}
		return (int) $note->author_id === (int) $user_id && BlogSafe_Dash_Notes_Settings::can_create( $user_id );
	}

	/**
	 * Notes this user sees on the current admin screen, oldest at the bottom
	 * of the stack.
	 *
	 * @param string[]|null $screen_keys keys of the current screen (see
	 *                                   Admin::current_screens()); "*" notes
	 *                                   always match. Null = every screen.
	 */
	public static function board( $user_id, $screen_keys = null ) {
		global $wpdb;
		$notes   = BlogSafe_Dash_Notes_Schema::notes_table();
		$users   = BlogSafe_Dash_Notes_Schema::users_table();
		$screens = BlogSafe_Dash_Notes_Schema::screens_table();

		list( $visible, $vparams ) = self::visibility_sql( $user_id );
		$params       = array_merge( array( $user_id ), $vparams, array( current_time( 'mysql', true ) ) );
		$screen_where = '';
		if ( null !== $screen_keys ) {
			$keys         = array_values( array_unique( array_merge( (array) $screen_keys, array( self::ALL_SCREENS ) ) ) );
			$screen_where = 'AND n.id IN ( SELECT s.note_id FROM ' . $screens . ' s WHERE s.screen_key IN (' . implode( ',', array_fill( 0, (int) count( $keys ), '%s' ) ) . ') )';
			$params       = array_merge( $params, $keys );
		}
		$params[] = self::BOARD_LIMIT;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables; every value is a placeholder.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.is_recipient, u.read_at, u.dismissed, u.pos_x, u.pos_y, u.width, u.height, u.z_order, u.minimized, u.locked, u.done_at, u.snoozed_until
				FROM {$notes} n
				LEFT JOIN {$users} u ON u.note_id = n.id AND u.user_id = %d
				WHERE {$visible}
				AND ( u.dismissed IS NULL OR u.dismissed = 0 )
				AND ( u.snoozed_until IS NULL OR u.snoozed_until <= %s )
				{$screen_where}
				ORDER BY COALESCE( u.z_order, 0 ) ASC, n.id ASC
				LIMIT %d",
				$params
			)
		);
		// phpcs:enable
		$rows = $rows ? $rows : array();
		$ids = wp_list_pluck( $rows, 'id' );
		self::prime_screens( $ids );
		BlogSafe_Dash_Notes_Items::prime( $ids );
		BlogSafe_Dash_Notes_Replies::prime( $ids );
		return $rows;
	}

	/* ------------------------------------------------------------------ */
	/* Where notes show                                                   */
	/* ------------------------------------------------------------------ */

	/** @var array<int,array> screens per note, cached for this request */
	private static $screen_cache = array();

	/**
	 * Clean a list of places sent by the browser: [{key, label, url}, …].
	 * key: a WordPress screen id (lower-case, as WP_Screen makes them),
	 * "post:ID" for one post, or "*" for every admin page.
	 * url: path relative to wp-admin, used for "open" links.
	 *
	 * @return array<string,array>|WP_Error keyed by screen key
	 */
	public static function clean_screens( $input ) {
		$out = array();
		foreach ( is_array( $input ) ? $input : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$key = (string) ( $item['key'] ?? '' );
			if ( self::ALL_SCREENS !== $key && ! preg_match( '/^[a-z0-9_\-]{1,100}(:\d{1,19})?$/', $key ) ) {
				return new WP_Error( 'bsdn_bad_screen', __( 'Unknown admin page.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
			}
			$url = (string) ( $item['url'] ?? '' );
			if ( '' !== $url && ! preg_match( '/^[a-z0-9_\-]+\.php(\?[A-Za-z0-9_\-=&%.:\[\]+,]*)?$/', $url ) ) {
				$url = '';
			}
			$label = mb_substr( sanitize_text_field( (string) ( $item['label'] ?? '' ) ), 0, 200 );
			if ( self::ALL_SCREENS === $key ) {
				$url   = '';
				$label = '';
			}
			$out[ $key ] = array(
				'key'   => $key,
				'label' => '' !== $label ? $label : $key,
				'url'   => $url,
			);
			if ( count( $out ) >= self::MAX_SCREENS ) {
				break;
			}
		}
		return $out;
	}

	/** The Dashboard, as a screens list. */
	public static function dashboard_screen() {
		return array(
			'dashboard' => array(
				'key'   => 'dashboard',
				'label' => __( 'Dashboard', 'blogsafe-dash-notes' ),
				'url'   => 'index.php',
			),
		);
	}

	private static function set_screens( $note_id, array $screens ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::screens_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( $table, array( 'note_id' => (int) $note_id ), array( '%d' ) );
		foreach ( $screens as $s ) {
			$wpdb->insert(
				$table,
				array(
					'note_id'    => (int) $note_id,
					'screen_key' => $s['key'],
					'label'      => $s['label'],
					'url'        => $s['url'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}
		// phpcs:enable
		unset( self::$screen_cache[ (int) $note_id ] );
	}

	/** Drop cached screens (network sync reads ids from another site). */
	public static function forget_screens( array $note_ids ) {
		foreach ( $note_ids as $id ) {
			unset( self::$screen_cache[ (int) $id ] );
		}
	}

	/** Replace a note's places with an already-cleaned list. */
	public static function replace_screens( $note_id, array $screens ) {
		$keyed = array();
		foreach ( $screens as $s ) {
			$keyed[ $s['key'] ] = $s;
		}
		self::set_screens( $note_id, $keyed ? $keyed : self::dashboard_screen() );
	}

	/** Load the screens of several notes in one query. */
	public static function prime_screens( array $note_ids ) {
		global $wpdb;
		$ids = array_diff( array_unique( array_map( 'intval', $note_ids ) ), array_keys( self::$screen_cache ), array( 0 ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$screen_cache[ $id ] = array();
		}
		$table = BlogSafe_Dash_Notes_Schema::screens_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table; ids are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT note_id, screen_key, label, url FROM {$table} WHERE note_id IN (" . implode( ',', array_fill( 0, (int) count( $ids ), '%d' ) ) . ') ORDER BY screen_key', $ids ) );
		foreach ( (array) $rows as $r ) {
			self::$screen_cache[ (int) $r->note_id ][] = array(
				'key'   => (string) $r->screen_key,
				'label' => (string) $r->label,
				'url'   => (string) $r->url,
			);
		}
	}

	/** @return array<int,array{key:string,label:string,url:string}> */
	public static function screens( $note_id ) {
		self::prime_screens( array( $note_id ) );
		return self::$screen_cache[ (int) $note_id ] ?? array();
	}

	/** Admin URL that opens a note where it lives, focused. */
	public static function open_url( $note_id ) {
		$path = 'index.php';
		foreach ( self::screens( $note_id ) as $s ) {
			if ( 'dashboard' === $s['key'] || self::ALL_SCREENS === $s['key'] ) {
				$path = 'index.php';
				break;
			}
			if ( '' !== $s['url'] ) {
				$path = $s['url'];
			}
		}
		return admin_url( $path ) . '#bsdn-note-' . (int) $note_id;
	}

	/** Recipients of a "users" note, or readers of an "all"/"roles" note, with read and done. */
	public static function audience_status( $note ) {
		global $wpdb;
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		if ( 'users' === $note->audience ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, read_at, done_at FROM {$users} WHERE note_id = %d AND is_recipient = 1 ORDER BY user_id LIMIT %d", $note->id, self::MAX_RECIPIENTS ) );
		} elseif ( in_array( $note->audience, array( 'all', 'roles', 'network' ), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, read_at, done_at FROM {$users} WHERE note_id = %d AND user_id <> %d AND ( read_at IS NOT NULL OR done_at IS NOT NULL ) ORDER BY read_at LIMIT 500", $note->id, $note->author_id ) );
		} else {
			$rows = array();
		}
		$ids = wp_list_pluck( (array) $rows, 'user_id' );
		self::prime_names( $ids );
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'id'   => (int) $row->user_id,
				'name' => self::name( $row->user_id ),
				'read' => null !== $row->read_at,
				'done' => null !== $row->done_at,
			);
		}
		return $out;
	}

	/**
	 * Notes for the list page.
	 *
	 * @param string $view inbox | sent | all | urgent | overdue | done | snoozed | templates
	 * @return array{0: array, 1: int} rows and total count
	 */
	public static function list_notes( $user_id, $view, $search, $orderby, $order, $per_page, $paged ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		$users = BlogSafe_Dash_Notes_Schema::users_table();

		$columns = array(
			'title'      => 'n.title',
			'priority'   => 'n.priority',
			'deadline'   => 'n.deadline',
			'created_at' => 'n.created_at',
			'updated_at' => 'n.updated_at',
		);
		$order_sql = ( $columns[ $orderby ] ?? 'n.updated_at' ) . ( 'asc' === strtolower( (string) $order ) ? ' ASC' : ' DESC' );

		$where  = array();
		$params = array( $user_id );
		list( $visible, $vparams ) = self::visibility_sql( $user_id );
		$now   = current_time( 'mysql', true );
		$today = current_time( 'Y-m-d' );
		switch ( $view ) {
			case 'sent':
				$where[]  = 'n.is_template = 0 AND n.author_id = %d';
				$params[] = $user_id;
				break;
			case 'all':
				$where[] = BlogSafe_Dash_Notes_Settings::is_manager( $user_id ) ? 'n.is_template = 0' : '1 = 0';
				break;
			case 'templates':
				$where[]  = 'n.is_template = 1 AND n.author_id = %d';
				$params[] = $user_id;
				break;
			case 'urgent':
				$where[] = $visible . ' AND n.priority = 2 AND u.done_at IS NULL';
				$params  = array_merge( $params, $vparams );
				break;
			case 'overdue':
				$where[]  = $visible . ' AND n.deadline IS NOT NULL AND n.deadline < %s AND u.done_at IS NULL';
				$params   = array_merge( $params, $vparams );
				$params[] = $today;
				break;
			case 'done':
				$where[] = $visible . ' AND u.done_at IS NOT NULL';
				$params  = array_merge( $params, $vparams );
				break;
			case 'snoozed':
				$where[]  = $visible . ' AND u.snoozed_until > %s';
				$params   = array_merge( $params, $vparams );
				$params[] = $now;
				break;
			default: // inbox: sent to me by others.
				$where[]  = 'n.author_id <> %d AND ' . $visible;
				$params[] = $user_id;
				$params   = array_merge( $params, $vparams );
		}
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '( n.title LIKE %s OR n.content LIKE %s )';
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$from      = "FROM {$notes} n LEFT JOIN {$users} u ON u.note_id = n.id AND u.user_id = %d WHERE {$where_sql}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables; every value is a placeholder, $order_sql is from a whitelist.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", $params ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.is_recipient, u.read_at, u.dismissed, u.done_at, u.snoozed_until {$from} ORDER BY {$order_sql}, n.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, max( 0, ( $paged - 1 ) * $per_page ) ) )
			)
		);
		// phpcs:enable
		return array( $rows ? $rows : array(), $total );
	}

	/** Unread notes waiting for this user (for the menu badge). */
	public static function unread_count( $user_id ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		list( $visible, $vparams ) = self::visibility_sql( $user_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom tables; values are placeholders.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$notes} n
				LEFT JOIN {$users} u ON u.note_id = n.id AND u.user_id = %d
				WHERE (
					( n.author_id <> %d AND {$visible} AND u.read_at IS NULL )
					OR ( n.author_id = %d AND n.is_template = 0 AND u.user_id IS NOT NULL AND u.read_at IS NULL )
				)
				AND ( u.dismissed IS NULL OR u.dismissed = 0 )",
				array_merge( array( $user_id, $user_id ), $vparams, array( $user_id ) )
			)
		);
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ */
	/* Writing                                                            */
	/* ------------------------------------------------------------------ */

	/** @return int new note id, 0 on failure */
	public static function create( $author_id, array $fields, array $recipients, array $state, array $screens = array() ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- custom table.
		$ok = $wpdb->insert(
			BlogSafe_Dash_Notes_Schema::notes_table(),
			array(
				'author_id'  => (int) $author_id,
				'title'      => $fields['title'],
				'content'    => $fields['content'],
				'color'      => $fields['color'],
				'priority'   => $fields['priority'],
				'deadline'   => $fields['deadline'],
				'audience'   => $fields['audience'],
				'roles'      => $fields['roles'] ?? '',
				'recur'      => $fields['recur'] ?? '',
				'recur_next' => self::next_reset( $fields['recur'] ?? '' ),
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return 0;
		}
		$note_id = (int) $wpdb->insert_id;
		self::set_recipients( $note_id, $recipients );
		self::set_screens( $note_id, $screens ? $screens : self::dashboard_screen() );
		if ( 'network' === $fields['audience'] ) {
			BlogSafe_Dash_Notes_Network::bump();
		}
		$state['read_at'] = $now;
		self::save_state( $note_id, $author_id, $state );
		return $note_id;
	}

	/**
	 * @param array      $fields     cleaned (partial) fields
	 * @param int[]|null $recipients new recipient list, or null to leave it
	 */
	public static function update( $note, array $fields, $recipients, $screens = null ) {
		global $wpdb;
		$changed_text = false;
		foreach ( array( 'title', 'content', 'priority', 'deadline' ) as $key ) {
			if ( array_key_exists( $key, $fields ) && (string) $fields[ $key ] !== (string) $note->$key ) {
				$changed_text = true;
			}
		}
		if ( array_key_exists( 'recur', $fields ) && $fields['recur'] !== (string) $note->recur ) {
			$fields['recur_next'] = self::next_reset( $fields['recur'] );
		}
		if ( $fields ) {
			$fields['updated_at'] = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update( BlogSafe_Dash_Notes_Schema::notes_table(), $fields, array( 'id' => (int) $note->id ) );
		}
		if ( null !== $recipients ) {
			self::set_recipients( (int) $note->id, $recipients );
		}
		if ( null !== $screens ) {
			self::set_screens( (int) $note->id, $screens );
		}
		if ( 'network' === $note->audience || 'network' === ( $fields['audience'] ?? '' ) ) {
			BlogSafe_Dash_Notes_Network::bump();
		}
		if ( $changed_text ) {
			// Everyone but the author sees the note as new again.
			$users = BlogSafe_Dash_Notes_Schema::users_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
			$wpdb->query( $wpdb->prepare( "UPDATE {$users} SET read_at = NULL WHERE note_id = %d AND user_id <> %d", $note->id, $note->author_id ) );
		}
	}

	public static function delete( $note_id ) {
		global $wpdb;
		$was = self::get( $note_id );
		if ( $was && 'network' === $was->audience ) {
			BlogSafe_Dash_Notes_Network::bump();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::users_table(), array( 'note_id' => (int) $note_id ), array( '%d' ) );
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::screens_table(), array( 'note_id' => (int) $note_id ), array( '%d' ) );
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::revisions_table(), array( 'note_id' => (int) $note_id ), array( '%d' ) );
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::notes_table(), array( 'id' => (int) $note_id ), array( '%d' ) );
		// phpcs:enable
		BlogSafe_Dash_Notes_Items::delete_for_note( $note_id );
		BlogSafe_Dash_Notes_Replies::delete_for_note( $note_id );
	}

	/** Replace the recipient list; other users' positions are kept. */
	private static function set_recipients( $note_id, array $recipients ) {
		global $wpdb;
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$users} SET is_recipient = 0 WHERE note_id = %d", $note_id ) );
		foreach ( $recipients as $user_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$users} (note_id, user_id, is_recipient) VALUES (%d, %d, 1) ON DUPLICATE KEY UPDATE is_recipient = 1", $note_id, $user_id ) );
		}
	}

	/**
	 * Insert or update one user's state for a note. Keys: pos_x, pos_y, width,
	 * height, z_order, minimized, dismissed, read_at.
	 */
	public static function save_state( $note_id, $user_id, array $state ) {
		global $wpdb;
		$allowed = array(
			'pos_x'     => '%d',
			'pos_y'     => '%d',
			'width'     => '%d',
			'height'    => '%d',
			'z_order'   => '%d',
			'minimized' => '%d',
			'dismissed'     => '%d',
			'locked'        => '%d',
			'read_at'       => '%s',
			'done_at'       => '%s',
			'snoozed_until' => '%s',
		);
		$columns = array( 'note_id', 'user_id' );
		$holders = array( '%d', '%d' );
		$values  = array( (int) $note_id, (int) $user_id );
		$updates = array();
		// Column names and placeholders come from $allowed (this code), never
		// from the keys of $state; values only ever go in as placeholders.
		foreach ( $allowed as $column => $holder ) {
			if ( ! array_key_exists( $column, $state ) ) {
				continue;
			}
			$value     = $state[ $column ];
			$columns[] = $column;
			if ( null === $value ) {
				$holders[] = 'NULL'; // prepare() would turn null into ''.
			} else {
				$holders[] = $holder;
				$values[]  = '%d' === $holder ? (int) $value : (string) $value;
			}
			$updates[] = "{$column} = VALUES({$column})";
		}
		if ( 2 === count( $columns ) ) {
			return; // nothing to save
		}
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		$sql   = "INSERT INTO {$users} (" . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $holders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- custom table; column names come from the whitelist above.
		$wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/** Mark notes read for this user (only those that are still unread). */
	public static function mark_read( array $note_ids, $user_id ) {
		$now = current_time( 'mysql', true );
		foreach ( array_unique( array_map( 'intval', $note_ids ) ) as $note_id ) {
			self::save_state( $note_id, $user_id, array( 'read_at' => $now ) );
		}
	}

	/** A user was deleted or removed from this site: drop their notes and state. */
	public static function forget_user( $user_id ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		// The tables may not exist yet on a site where the plugin never loaded.
		if ( get_option( BlogSafe_Dash_Notes_Schema::OPTION ) === false ) {
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		$wpdb->query( $wpdb->prepare( "DELETE u FROM {$users} u INNER JOIN {$notes} n ON n.id = u.note_id WHERE n.author_id = %d", $user_id ) );
		$screens = BlogSafe_Dash_Notes_Schema::screens_table();
		$wpdb->query( $wpdb->prepare( "DELETE s FROM {$screens} s INNER JOIN {$notes} n ON n.id = s.note_id WHERE n.author_id = %d", $user_id ) );
		foreach ( array( BlogSafe_Dash_Notes_Schema::items_table(), BlogSafe_Dash_Notes_Schema::replies_table(), BlogSafe_Dash_Notes_Schema::revisions_table() ) as $child ) {
			$wpdb->query( $wpdb->prepare( "DELETE c FROM {$child} c INNER JOIN {$notes} n ON n.id = c.note_id WHERE n.author_id = %d", $user_id ) );
		}
		// Their replies on other people's notes go too; their ticks stay, unattributed.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . BlogSafe_Dash_Notes_Schema::replies_table() . ' WHERE user_id = %d', $user_id ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . BlogSafe_Dash_Notes_Schema::items_table() . ' SET done_by = 0 WHERE done_by = %d', $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$notes} WHERE author_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$users} WHERE user_id = %d", $user_id ) );
		// phpcs:enable
		BlogSafe_Dash_Notes_Network::bump(); // they may have written network notes
	}

	/* ------------------------------------------------------------------ */
	/* Recurring notes                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * When a repeating note next starts over (UTC), counted from $from (a
	 * site-time DateTimeImmutable, default now): the start of the next day,
	 * of the same weekday next week, or of the same day next month. Null
	 * for notes that don't repeat.
	 */
	public static function next_reset( $recur, $from = null ) {
		if ( ! in_array( $recur, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			return null;
		}
		$tz   = wp_timezone();
		$from = $from instanceof DateTimeImmutable ? $from->setTimezone( $tz ) : new DateTimeImmutable( 'now', $tz );
		$day  = $from->setTime( 0, 0 );
		$step = array(
			'daily'   => '+1 day',
			'weekly'  => '+1 week',
			'monthly' => '+1 month',
		);
		return $day->modify( $step[ $recur ] )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Start over every repeating note whose time has come: checklist ticks
	 * and everyone's Done are cleared, everyone but the author sees it as
	 * new, and a deadline moves on by the same period. Runs on admin page
	 * loads (cheap: one indexed query when nothing is due), at most 50 notes
	 * per call.
	 *
	 * @return int notes reset
	 */
	public static function run_recurring() {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		$now   = current_time( 'mysql', true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		$due = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$notes} WHERE recur_next IS NOT NULL AND recur_next <= %s AND recur <> '' AND is_template = 0 ORDER BY recur_next LIMIT 50", $now ) );
		$utc = new DateTimeZone( 'UTC' );
		foreach ( (array) $due as $note ) {
			// Catch up if several periods passed while nobody looked.
			$next    = $note->recur_next;
			$periods = 0;
			while ( $next && $next <= $now && $periods < 400 ) {
				$next = self::next_reset( $note->recur, new DateTimeImmutable( $next, $utc ) );
				++$periods;
			}
			$update = array( 'recur_next' => $next );
			if ( $note->deadline ) {
				$step               = array(
					'daily'   => 'day',
					'weekly'  => 'week',
					'monthly' => 'month',
				);
				$deadline           = new DateTimeImmutable( $note->deadline . ' 00:00:00', wp_timezone() );
				$update['deadline'] = $deadline->modify( '+' . $periods . ' ' . $step[ $note->recur ] )->format( 'Y-m-d' );
			}
			$wpdb->update( $notes, $update, array( 'id' => (int) $note->id ) );
			BlogSafe_Dash_Notes_Items::reset( $note->id );
			$wpdb->query( $wpdb->prepare( "UPDATE {$users} SET done_at = NULL WHERE note_id = %d", $note->id ) );
			$wpdb->query( $wpdb->prepare( "UPDATE {$users} SET read_at = NULL WHERE note_id = %d AND user_id <> %d", $note->id, $note->author_id ) );
		}
		// phpcs:enable
		return count( (array) $due );
	}

	/* ------------------------------------------------------------------ */
	/* Templates                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Save a copy of a note as one of $user_id's templates: title, text,
	 * colour, priority, repeat and checklist. Never on anyone's board.
	 *
	 * @return int|WP_Error template id
	 */
	public static function make_template( $note, $user_id ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notes} WHERE is_template = 1 AND author_id = %d", $user_id ) );
		if ( $count >= self::MAX_TEMPLATES ) {
			return new WP_Error( 'bsdn_too_many_templates', __( 'You have reached the maximum number of templates. Delete one under Dash Notes > Templates first.', 'blogsafe-dash-notes' ), array( 'status' => 400 ) );
		}
		$fields = self::clean_fields(
			array(
				'title'    => $note->title,
				'content'  => $note->content,
				'color'    => $note->color,
				'priority' => (int) $note->priority,
				'deadline' => '',
				'audience' => 'private',
				'recur'    => (string) $note->recur,
			)
		);
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$id = self::create( $user_id, $fields, array(), array() );
		if ( ! $id ) {
			return new WP_Error( 'bsdn_save_failed', __( 'The note could not be saved.', 'blogsafe-dash-notes' ), array( 'status' => 500 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update( $notes, array( 'is_template' => 1, 'recur_next' => null ), array( 'id' => $id ) );
		BlogSafe_Dash_Notes_Items::save( $id, BlogSafe_Dash_Notes_Items::texts( $note->id ) );
		return $id;
	}

	/** $user_id's templates, as the new-note picker needs them. */
	public static function templates_for( $user_id ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, content, color, priority, recur FROM {$notes} WHERE is_template = 1 AND author_id = %d ORDER BY title, id LIMIT %d", $user_id, self::MAX_TEMPLATES ) );
		BlogSafe_Dash_Notes_Items::prime( wp_list_pluck( (array) $rows, 'id' ) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = self::template_shape( $r );
		}
		return $out;
	}

	public static function template_shape( $r ) {
		return array(
			'id'       => (int) $r->id,
			'title'    => sanitize_text_field( (string) $r->title ),
			'content'  => self::filter_content( $r->content ),
			'color'    => self::clean_color( $r->color ),
			'priority' => (int) $r->priority,
			'recur'    => self::pick( $r->recur, self::RECUR, '' ),
			'items'    => BlogSafe_Dash_Notes_Items::texts( $r->id ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Output shape                                                       */
	/* ------------------------------------------------------------------ */

	public static function prime_names( array $ids ) {
		$ids = array_diff( array_unique( array_map( 'intval', $ids ) ), array_keys( self::$names ), array( 0 ) );
		if ( ! $ids ) {
			return;
		}
		$args = array(
			'include' => $ids,
			'fields'  => array( 'ID', 'display_name' ),
			'blog_id' => 0,
		);
		foreach ( get_users( $args ) as $user ) {
			self::$names[ (int) $user->ID ] = (string) $user->display_name;
		}
		foreach ( $ids as $id ) {
			if ( ! isset( self::$names[ $id ] ) ) {
				self::$names[ $id ] = __( '(deleted user)', 'blogsafe-dash-notes' );
			}
		}
	}

	public static function name( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! isset( self::$names[ $user_id ] ) ) {
			self::prime_names( array( $user_id ) );
		}
		return self::$names[ $user_id ];
	}

	/**
	 * Note body as display HTML, built the way the_content builds a post:
	 * filter, embeds, paragraphs, links, then core media shortcodes only.
	 *
	 * The stored content was filtered on save; it is filtered again here so a
	 * row changed outside the plugin still can't carry script.
	 */
	public static function render_content( $content ) {
		global $wp_embed, $shortcode_tags;

		$html = self::filter_content( $content );
		if ( $wp_embed instanceof WP_Embed ) {
			$html = self::limit_embeds( $html );
			// Known providers only (YouTube, Vimeo, …): without "discovery"
			// WordPress never fetches an address just because a note names it.
			add_filter( 'embed_oembed_discover', '__return_false', 999 );
			try {
				$html = $wp_embed->run_shortcode( $html ); // [embed]URL[/embed]
				$html = $wp_embed->autoembed( $html );     // a bare URL on its own line
			} finally {
				remove_filter( 'embed_oembed_discover', '__return_false', 999 );
			}
		}
		$html = wpautop( $html );
		$html = shortcode_unautop( $html );
		$html = make_clickable( $html );

		$all            = is_array( $shortcode_tags ) ? $shortcode_tags : array();
		$shortcode_tags = array_intersect_key( $all, array_flip( self::MEDIA_SHORTCODES ) );
		try {
			$html = do_shortcode( $html );
		} finally {
			$shortcode_tags = $all;
		}

		if ( function_exists( 'wp_filter_content_tags' ) ) {
			$html = wp_filter_content_tags( $html, 'blogsafe_dash_notes' );
		}
		return $html;
	}

	/** @var int players looked up so far in this request */
	private static $embeds_used = 0;

	/**
	 * Keep the first MAX_EMBEDS players of a note (and MAX_EMBEDS_PAGE per
	 * page load); every further [embed] or bare link becomes a plain link
	 * before WordPress looks any of them up. Without this, a note holding
	 * hundreds of links would cost every viewer hundreds of lookups.
	 */
	private static function limit_embeds( $html ) {
		$in_note = 0;
		$take    = static function () use ( &$in_note ) {
			if ( $in_note >= self::MAX_EMBEDS || self::$embeds_used >= self::MAX_EMBEDS_PAGE ) {
				return false;
			}
			++$in_note;
			++self::$embeds_used;
			return true;
		};
		$link    = static function ( $url ) {
			$url = esc_url( $url );
			return '' === $url ? '' : '<a href="' . $url . '">' . esc_html( $url ) . '</a>';
		};
		$steps   = array(
			// [embed]URL[/embed]
			'/\[embed[^\]]*\](.*?)\[\/embed\]/is'                    => static function ( $m ) use ( $take, $link ) {
				return $take() ? $m[0] : $link( trim( wp_strip_all_tags( $m[1] ) ) );
			},
			// A link alone on its line, or alone in a paragraph: what autoembed() looks for.
			'|^(\s*)(https?://[^\s<>"]+)(\s*)$|im'                   => static function ( $m ) use ( $take, $link ) {
				return $take() ? $m[0] : $m[1] . $link( $m[2] ) . $m[3];
			},
			'|(<p(?: [^>]*)?>\s*)(https?://[^\s<>"]+)(\s*<\/p>)|i' => static function ( $m ) use ( $take, $link ) {
				return $take() ? $m[0] : $m[1] . $link( $m[2] ) . $m[3];
			},
		);
		foreach ( $steps as $pattern => $callback ) {
			$next = preg_replace_callback( $pattern, $callback, $html );
			if ( null !== $next ) {
				$html = $next;
			}
		}
		return $html;
	}

	/**
	 * The allowlist's own copy of $value, or $default. Output built this way
	 * never carries stored text, only strings written in this file.
	 */
	public static function pick( $value, array $allowed, $default ) {
		$pos = array_search( (string) $value, $allowed, true );
		return false === $pos ? $default : $allowed[ $pos ];
	}

	/** A stored Y-m-d date rebuilt from its numbers, or ''. */
	private static function out_date( $date ) {
		if ( ! is_string( $date ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) ) {
			return '';
		}
		return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
	}

	/** Note as sent to the dashboard script. `html` is filtered server-side; all other text is plain and the script escapes it. */
	public static function shape( $row, $user_id ) {
		$is_author = (int) $row->author_id === (int) $user_id;
		$can_edit  = self::can_edit( $row, $user_id );
		// Board rows already carry this user's state; single-note rows don't.
		$state   = property_exists( $row, 'z_order' ) ? ( null === $row->z_order ? null : $row ) : self::user_row( $row->id, $user_id );
		$read_at = $state ? $state->read_at : null;
		// Yes/no values and numbers are rebuilt inline with casts and empty(),
		// so nothing read from the database reaches the response as-is (and
		// taint scanners, which don't look inside helper functions, agree).
		$has_state = ! empty( $state );
		// This site's own role keys, in its order, for the roles the note names.
		$stored_roles = explode( ',', (string) $row->roles );
		$roles        = array();
		foreach ( array_keys( wp_roles()->get_names() ) as $role_key ) {
			if ( in_array( $role_key, $stored_roles, true ) ) {
				$roles[] = (string) $role_key;
			}
		}
		$note      = array(
			'id'        => (int) $row->id,
			'title'     => sanitize_text_field( (string) $row->title ),
			// Filtered again on the way out (it goes into the editor as HTML),
			// so a row changed outside the plugin still can't carry script.
			'content'   => self::filter_content( $row->content ),
			'html'      => self::render_content( $row->content ),
			'color'     => self::clean_color( $row->color ),
			'priority'  => (int) $row->priority,
			'deadline'  => self::out_date( $row->deadline ),
			'audience'  => self::pick( $row->audience, self::AUDIENCES, 'private' ),
			'author'    => array(
				'id'   => (int) $row->author_id,
				'name' => self::name( $row->author_id ),
			),
			'isAuthor'  => $is_author,
			'canEdit'   => $can_edit,
			'isNew'     => empty( $read_at ) && ( ! $is_author || $has_state ),
			// Same format as mysql_to_rfc3339(), rebuilt from a number.
			'updatedAt' => gmdate( 'Y-m-d\TH:i:s', (int) strtotime( $row->updated_at . ' UTC' ) ),
			'state'     => array(
				'x'         => $has_state && isset( $state->pos_x ) ? (int) $state->pos_x : null,
				'y'         => $has_state && isset( $state->pos_y ) ? (int) $state->pos_y : null,
				'w'         => $has_state && isset( $state->width ) ? (int) $state->width : null,
				'h'         => $has_state && isset( $state->height ) ? (int) $state->height : null,
				'z'         => $has_state ? (int) $state->z_order : 0,
				'minimized' => $has_state && ! empty( $state->minimized ),
				'locked'    => $has_state && ! empty( $state->locked ),
			),
			'done'           => $has_state && ! empty( $state->done_at ),
			'recur'          => self::pick( $row->recur, self::RECUR, '' ),
			// clean_anchor() has already refused any "<", so stripping tags
			// changes nothing in a real selector; it is there for scanners.
			'anchor'         => wp_strip_all_tags( self::clean_anchor( $row->anchor ) ),
			'networkCopy'    => 0 !== (int) $row->network_id,
			'roles'          => $roles,
			'audienceStatus' => array(),
			'screens'        => self::screens( $row->id ),
			'items'          => BlogSafe_Dash_Notes_Items::for_note( $row->id ),
			'replies'        => BlogSafe_Dash_Notes_Replies::for_note( $row->id, $user_id, $can_edit ),
		);
		if ( $can_edit || $is_author ) {
			$note['audienceStatus'] = self::audience_status( $row );
		}
		return $note;
	}
}
