<?php
/**
 * Admin side: the dashboard board, the Dash Notes list page and settings.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Admin {

	const MENU_SLUG     = 'blogsafe-dash-notes';
	const SETTINGS_SLUG = 'blogsafe-dash-notes-settings';
	const ACTION        = 'blogsafe_dash_notes_action';

	/**
	 * "Our other plugins" link in the footer of Dash Notes' own pages.
	 * Generic on purpose: nothing to change when a new plugin ships. The
	 * owner wants it to go to the blogsafe.org home page.
	 */
	const MORE_PLUGINS_URL = 'https://blogsafe.org/';

	/** The user guide on blogsafe.org, opened by every Help link. */
	const HELP_URL = 'https://blogsafe.org/dash-notes-help/';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_text' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_action' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BLOGSAFE_DASH_NOTES_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
	}

	/** "Help" beside "Visit plugin site" on the Plugins screen. */
	public static function row_meta( $links, $file ) {
		if ( plugin_basename( BLOGSAFE_DASH_NOTES_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( self::HELP_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Help', 'blogsafe-dash-notes' ) . '</a>';
		}
		return $links;
	}

	/**
	 * On Dash Notes' own pages only (All Notes, Settings), the admin footer
	 * points to BlogSafe's other plugins. Nowhere else: WordPress.org
	 * guideline 11 keeps promotion off the rest of the admin.
	 */
	/** This site's roles, for "send to a role": [{key, name}]. */
	public static function role_choices() {
		$out = array();
		foreach ( wp_roles()->get_names() as $key => $name ) {
			$out[] = array(
				'key'  => (string) $key,
				'name' => translate_user_role( $name ),
			);
		}
		return $out;
	}

	/** Is this one of Dash Notes' own admin pages? Defaults to the current screen. */
	public static function is_own_screen( $id = null ) {
		if ( null === $id ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$id     = $screen ? $screen->id : '';
		}
		return false !== strpos( (string) $id, self::MENU_SLUG );
	}

	/** Body class that scopes the console theme to Dash Notes' own pages. */
	public static function body_class( $classes ) {
		return self::is_own_screen() ? $classes . ' bsdn-admin' : $classes;
	}

	public static function footer_text( $text ) {
		if ( ! self::is_own_screen() ) {
			return $text;
		}
		return sprintf(
			/* translators: %s: link to blogsafe.org. */
			esc_html__( 'Thanks for using BlogSafe Dash Notes. See our other WordPress plugins at %s.', 'blogsafe-dash-notes' ),
			'<a href="' . esc_url( self::MORE_PLUGINS_URL ) . '" target="_blank" rel="noopener">BlogSafe.org</a>'
		);
	}

	public static function action_links( $links ) {
		if ( current_user_can( 'manage_options' ) ) {
			$url = admin_url( 'admin.php?page=' . self::SETTINGS_SLUG );
			array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'blogsafe-dash-notes' ) . '</a>' );
		}
		return $links;
	}

	public static function menu() {
		$count = BlogSafe_Dash_Notes_Store::unread_count( get_current_user_id() );
		$badge = $count ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%2$s</span></span>', $count, esc_html( number_format_i18n( $count ) ) ) : '';

		add_menu_page(
			__( 'Dash Notes', 'blogsafe-dash-notes' ),
			esc_html__( 'Dash Notes', 'blogsafe-dash-notes' ) . $badge,
			'read',
			self::MENU_SLUG,
			array( __CLASS__, 'render_list' ),
			'dashicons-sticky',
			3
		);
		add_submenu_page( self::MENU_SLUG, __( 'All Notes', 'blogsafe-dash-notes' ), __( 'All Notes', 'blogsafe-dash-notes' ), 'read', self::MENU_SLUG, array( __CLASS__, 'render_list' ) );
		add_submenu_page( self::MENU_SLUG, __( 'Dash Notes Settings', 'blogsafe-dash-notes' ), __( 'Settings', 'blogsafe-dash-notes' ), 'manage_options', self::SETTINGS_SLUG, array( __CLASS__, 'render_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard board                                                    */
	/* ------------------------------------------------------------------ */

	/** @var array|null where the current admin page is, set by enqueue() */
	private static $here = null;

	/** @var int notes on the current page, for the admin bar */
	private static $board_count = 0;

	/**
	 * Keys, labels and URLs for the current admin page: "here" is the screen
	 * (WP_Screen id), "post" is the single post being edited, if any.
	 *
	 * @return array{here: array, post: array|null}|null
	 */
	public static function current_screens() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! $screen->id ) {
			return null;
		}

		$here = array(
			'key'   => (string) $screen->id,
			'label' => 'dashboard' === $screen->id ? __( 'Dashboard', 'blogsafe-dash-notes' ) : wp_strip_all_tags( (string) get_admin_page_title() ),
			'url'   => self::current_admin_path(),
		);
		if ( '' === $here['label'] ) {
			$here['label'] = $here['key'];
		}

		$post = null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which post is open.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( 'post' === $screen->base && $post_id && current_user_can( 'edit_post', $post_id ) ) {
			$type  = get_post_type_object( (string) get_post_type( $post_id ) );
			$title = get_the_title( $post_id );
			$post  = array(
				'key'   => 'post:' . $post_id,
				/* translators: 1: post type name, e.g. "Page", 2: post title. */
				'label' => sprintf( __( '%1$s: %2$s', 'blogsafe-dash-notes' ), $type ? $type->labels->singular_name : __( 'Post', 'blogsafe-dash-notes' ), '' !== $title ? $title : '#' . $post_id ),
				'url'   => 'post.php?post=' . $post_id . '&action=edit',
			);
		}
		return array(
			'here' => $here,
			'post' => $post,
		);
	}

	/** This page's path relative to wp-admin, keeping only the arguments that pick the page. */
	private static function current_admin_path() {
		global $pagenow;
		$file = is_string( $pagenow ) && preg_match( '/^[a-z0-9_\-]+\.php$/', $pagenow ) ? $pagenow : 'index.php';
		$keep = array();
		foreach ( array( 'page', 'post_type', 'taxonomy', 'tab' ) as $arg ) {
			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- building a link back to this page.
			if ( isset( $_GET[ $arg ] ) && is_string( $_GET[ $arg ] ) ) {
				$keep[ $arg ] = sanitize_key( wp_unslash( $_GET[ $arg ] ) );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Recommended
		}
		return $keep ? $file . '?' . http_build_query( $keep ) : $file;
	}

	/**
	 * Asset version: plugin version plus file time, so browsers fetch the new
	 * file after an update (deploys are FTP copy-overs that keep the version).
	 */
	private static function asset_ver( $relative ) {
		$time = @filemtime( BLOGSAFE_DASH_NOTES_DIR . $relative ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just means no suffix.
		return BLOGSAFE_DASH_NOTES_VERSION . ( $time ? '.' . $time : '' );
	}

	public static function enqueue( $hook ) {
		// Dash Notes' own pages (All Notes, Settings): the Forensics-style
		// console theme. The settings hook name starts with the menu title,
		// which carries the unread badge, so match on the slug instead.
		if ( self::is_own_screen( $hook ) ) {
			wp_enqueue_style( 'blogsafe-dash-notes-list', BLOGSAFE_DASH_NOTES_URL . 'admin/css/dash-notes-list.css', array(), self::asset_ver( 'admin/css/dash-notes-list.css' ) );
		}
		// Not inside media and other admin iframes, and not in Network Admin
		// or the per-user admin: notes belong to a site, and Dash Notes has
		// no menu there.
		if ( ( defined( 'IFRAME_REQUEST' ) && IFRAME_REQUEST ) || is_network_admin() || is_user_admin() ) {
			return;
		}
		$where = self::current_screens();
		if ( ! $where ) {
			return;
		}
		self::$here = $where;
		$keys       = array( $where['here']['key'] );
		if ( $where['post'] ) {
			$keys[] = $where['post']['key'];
		}

		$user_id = get_current_user_id();
		$notes   = array();
		$unread  = array();
		// Network notes copied to this site, and repeating notes whose time
		// has come, are brought up to date before notes are shown.
		BlogSafe_Dash_Notes_Network::sync();
		BlogSafe_Dash_Notes_Store::run_recurring();
		foreach ( BlogSafe_Dash_Notes_Store::board( $user_id, $keys ) as $row ) {
			$note    = BlogSafe_Dash_Notes_Store::shape( $row, $user_id );
			$notes[] = $note;
			if ( $note['isNew'] ) {
				$unread[] = $note['id'];
			}
		}
		self::$board_count = count( $notes );
		// Showing a note counts as reading it. It still shows as "New" on
		// this page load.
		if ( $unread ) {
			BlogSafe_Dash_Notes_Store::mark_read( $unread, $user_id );
		}

		$can_create = BlogSafe_Dash_Notes_Settings::can_create( $user_id );
		$can_upload = $can_create && current_user_can( 'upload_files' );
		if ( $can_create ) {
			wp_enqueue_editor(); // TinyMCE + Quicktags for wp.editor.initialize().
		}
		if ( $can_upload ) {
			wp_enqueue_media();
		}
		// Players for [audio], [video] and [playlist] in notes.
		wp_enqueue_style( 'wp-mediaelement' );
		wp_enqueue_script( 'wp-mediaelement' );

		wp_enqueue_style( 'blogsafe-dash-notes', BLOGSAFE_DASH_NOTES_URL . 'admin/css/dash-notes.css', array( 'wp-mediaelement' ), self::asset_ver( 'admin/css/dash-notes.css' ) );
		wp_enqueue_script( 'blogsafe-dash-notes', BLOGSAFE_DASH_NOTES_URL . 'admin/js/dash-notes.js', array( 'utils', 'wp-mediaelement' ), self::asset_ver( 'admin/js/dash-notes.js' ), true );

		$config = array(
			'rest'      => esc_url_raw( rest_url( BlogSafe_Dash_Notes_Rest::NS ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'userId'    => $user_id,
			'canCreate' => $can_create,
			'canUpload' => $can_upload,
			'listUrl'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			'today'     => current_time( 'Y-m-d' ),
			'here'      => $where['here'],
			'herePost'  => $where['post'],
			'isDash'    => 'dashboard' === $where['here']['key'],
			'roles'     => self::role_choices(),
			'canNetwork' => BlogSafe_Dash_Notes_Network::can_write( $user_id ),
			'templates' => $can_create ? BlogSafe_Dash_Notes_Store::templates_for( $user_id ) : array(),
			'colors'    => array( '#fff59d', '#ffe0b2', '#f8bbd0', '#e1bee7', '#b3e5fc', '#c8e6c9', '#eeeeee' ),
			'notes'     => $notes,
			'i18n'      => array(
				'addNote'       => __( 'Add sticky note', 'blogsafe-dash-notes' ),
				'networkNote'   => __( 'Network note', 'blogsafe-dash-notes' ),
				'networkHint'   => __( 'Sent to every site in the network. It can only be changed on the main site.', 'blogsafe-dash-notes' ),
				'toNetwork'     => __( 'To every site', 'blogsafe-dash-notes' ),
				'pinElement'    => __( 'Pin to a part of this page', 'blogsafe-dash-notes' ),
				'unpinElement'  => __( 'Unpin from the page', 'blogsafe-dash-notes' ),
				'pickHint'      => __( 'Click the part of the page to pin this note to. Press Esc to cancel.', 'blogsafe-dash-notes' ),
				'pinned'        => __( 'Pinned to a part of this page. Hover the note to see which.', 'blogsafe-dash-notes' ),
				'pinMissing'    => __( 'The part of the page this note is pinned to is not on this page.', 'blogsafe-dash-notes' ),
				'history'       => __( 'History…', 'blogsafe-dash-notes' ),
				'historyTitle'  => __( 'Earlier versions', 'blogsafe-dash-notes' ),
				'noHistory'     => __( 'No earlier versions yet. One is saved each time the note is edited.', 'blogsafe-dash-notes' ),
				'restoreVersion' => __( 'Restore', 'blogsafe-dash-notes' ),
				'confirmRestore' => __( 'Put this version back? The current version is kept in the history.', 'blogsafe-dash-notes' ),
				/* translators: 1: date and time, 2: user name. */
				'versionBy'     => __( '%1$s by %2$s', 'blogsafe-dash-notes' ),
				/* translators: %d: number of checklist items. */
				'versionItems'  => __( '%d checklist items', 'blogsafe-dash-notes' ),
				'close'         => __( 'Close', 'blogsafe-dash-notes' ),
				'saveTemplate'  => __( 'Save as template', 'blogsafe-dash-notes' ),
				'templateSaved' => __( 'Saved as a template. Pick it under "Start from template" when you add a note.', 'blogsafe-dash-notes' ),
				'fromTemplate'  => __( 'Start from template…', 'blogsafe-dash-notes' ),
				'repeats'       => __( 'Repeats', 'blogsafe-dash-notes' ),
				'recurs'        => array(
					''        => __( 'Never', 'blogsafe-dash-notes' ),
					'daily'   => __( 'Every day', 'blogsafe-dash-notes' ),
					'weekly'  => __( 'Every week', 'blogsafe-dash-notes' ),
					'monthly' => __( 'Every month', 'blogsafe-dash-notes' ),
				),
				/* translators: %s: how often, e.g. "Every week". */
				'recurTitle'    => __( 'Repeats: %s. The checklist and Done start over each time.', 'blogsafe-dash-notes' ),
				'checklist'     => __( 'Checklist', 'blogsafe-dash-notes' ),
				'addItem'       => __( 'Add item', 'blogsafe-dash-notes' ),
				'removeItem'    => __( 'Remove item', 'blogsafe-dash-notes' ),
				'itemPlaceholder' => __( 'Checklist item (Enter adds another)', 'blogsafe-dash-notes' ),
				/* translators: %s: name of the user who ticked the item. */
				'tickedBy'      => __( 'Ticked by %s', 'blogsafe-dash-notes' ),
				/* translators: 1: items ticked, 2: number of items. */
				'progress'      => __( '%1$d of %2$d done', 'blogsafe-dash-notes' ),
				/* translators: 1: items ticked, 2: number of items. */
				'progressShort' => __( '%1$d of %2$d', 'blogsafe-dash-notes' ),
				'allDone'       => __( 'All done', 'blogsafe-dash-notes' ),
				'reply'         => __( 'Reply', 'blogsafe-dash-notes' ),
				'repliesTitle'  => __( 'Replies', 'blogsafe-dash-notes' ),
				'oneReply'      => __( '1 reply', 'blogsafe-dash-notes' ),
				'closeReplies'  => __( 'Close replies', 'blogsafe-dash-notes' ),
				/* translators: %d: number of replies. */
				'repliesCount'  => __( '%d replies', 'blogsafe-dash-notes' ),
				'writeReply'    => __( 'Write a reply… (Ctrl+Enter sends)', 'blogsafe-dash-notes' ),
				'send'          => __( 'Send', 'blogsafe-dash-notes' ),
				'deleteReply'   => __( 'Delete reply', 'blogsafe-dash-notes' ),
				'confirmDeleteReply' => __( 'Delete this reply?', 'blogsafe-dash-notes' ),
				'discardChanges' => __( 'Throw away the changes you made to this note?', 'blogsafe-dash-notes' ),
				'undo'          => __( 'Undo', 'blogsafe-dash-notes' ),
				'dismissedToast' => __( 'Removed from your screen. Find it again under Dash Notes > All Notes.', 'blogsafe-dash-notes' ),
				'lockedHint'    => __( 'This note is locked in place. Click its lock icon to move it again.', 'blogsafe-dash-notes' ),
				'pinnedHint'    => __( 'This note is pinned to a part of the page. Click its pin icon to move it freely.', 'blogsafe-dash-notes' ),
				'markDone'      => __( 'Mark done', 'blogsafe-dash-notes' ),
				'markNotDone'   => __( 'Mark not done', 'blogsafe-dash-notes' ),
				'doneLabel'     => __( 'Done', 'blogsafe-dash-notes' ),
				/* translators: 1: users who finished, 2: number of recipients. */
				'doneBy'        => __( 'Done by %1$d of %2$d', 'blogsafe-dash-notes' ),
				/* translators: %d: number of users who finished. */
				'doneByAll'     => __( 'Done by %d', 'blogsafe-dash-notes' ),
				'lock'          => __( 'Lock in place', 'blogsafe-dash-notes' ),
				'unlock'        => __( 'Unlock', 'blogsafe-dash-notes' ),
				'locked'        => __( 'Locked in place', 'blogsafe-dash-notes' ),
				'unlockHint'    => __( 'Locked in place. Click to unlock.', 'blogsafe-dash-notes' ),
				'snoozeTomorrow' => __( 'Snooze until tomorrow', 'blogsafe-dash-notes' ),
				'snoozeWeek'    => __( 'Snooze for a week', 'blogsafe-dash-notes' ),
				'snoozePick'    => __( 'Snooze until…', 'blogsafe-dash-notes' ),
				'snoozeGo'      => __( 'Snooze', 'blogsafe-dash-notes' ),
				/* translators: %s: date the note comes back. */
				'snoozedUntil'  => __( 'Snoozed until %s. It is listed under Dash Notes > Snoozed until then.', 'blogsafe-dash-notes' ),
				'dueToday'      => __( 'Due today', 'blogsafe-dash-notes' ),
				'dueTomorrow'   => __( 'Due tomorrow', 'blogsafe-dash-notes' ),
				'pickRoles'     => __( 'Roles', 'blogsafe-dash-notes' ),
				'needsRoles'    => __( 'Pick at least one role to send the note to.', 'blogsafe-dash-notes' ),
				/* translators: %s: list of role names. */
				'toRoles'       => __( 'To: %s', 'blogsafe-dash-notes' ),
				'where'         => __( 'Where it shows', 'blogsafe-dash-notes' ),
				'whereDash'     => __( 'Dashboard', 'blogsafe-dash-notes' ),
				/* translators: %s: admin page name, e.g. "Plugins". */
				'whereHere'     => __( 'This page (%s)', 'blogsafe-dash-notes' ),
				/* translators: %s: post type and title, e.g. "Page: About us". */
				'wherePost'     => __( 'Only this item (%s)', 'blogsafe-dash-notes' ),
				'whereAll'      => __( 'Every admin page', 'blogsafe-dash-notes' ),
				'needsWhere'    => __( 'Pick at least one place for the note to show.', 'blogsafe-dash-notes' ),
				/* translators: %s: list of admin page names. */
				'movedAway'     => __( 'Saved. This note now shows on: %s', 'blogsafe-dash-notes' ),
				'hideNotes'     => __( 'Hide notes', 'blogsafe-dash-notes' ),
				/* translators: %d: number of notes on this page. */
				'showNotes'     => __( 'Show notes (%d)', 'blogsafe-dash-notes' ),
				'title'         => __( 'Title', 'blogsafe-dash-notes' ),
				'text'          => __( 'Write your note…', 'blogsafe-dash-notes' ),
				'embedHint'     => __( 'Tip: paste a YouTube, Vimeo or other video link on its own line to show the video.', 'blogsafe-dash-notes' ),
				'tooLong'       => __( 'The note is too long.', 'blogsafe-dash-notes' ),
				'color'         => __( 'Colour', 'blogsafe-dash-notes' ),
				'customColor'   => __( 'Custom colour', 'blogsafe-dash-notes' ),
				'priority'      => __( 'Priority', 'blogsafe-dash-notes' ),
				'priorities'    => array(
					__( 'Normal', 'blogsafe-dash-notes' ),
					__( 'High', 'blogsafe-dash-notes' ),
					__( 'Urgent', 'blogsafe-dash-notes' ),
				),
				'deadline'      => __( 'Deadline', 'blogsafe-dash-notes' ),
				/* translators: %s: deadline date. */
				'due'           => __( 'Due %s', 'blogsafe-dash-notes' ),
				/* translators: %s: deadline date. */
				'overdue'       => __( 'Overdue: %s', 'blogsafe-dash-notes' ),
				'audience'      => __( 'Who sees it', 'blogsafe-dash-notes' ),
				'audiences'     => array(
					'private' => __( 'Only me', 'blogsafe-dash-notes' ),
					'users'   => __( 'Selected users', 'blogsafe-dash-notes' ),
					'roles'   => __( 'Users with a role', 'blogsafe-dash-notes' ),
					'all'     => __( 'Everyone', 'blogsafe-dash-notes' ),
					'network' => __( 'Everyone on every site', 'blogsafe-dash-notes' ),
				),
				'findUsers'     => __( 'Type a name to add a user', 'blogsafe-dash-notes' ),
				'noUsers'       => __( 'No matching users.', 'blogsafe-dash-notes' ),
				/* translators: %s: user name. */
				'removeUser'    => __( 'Remove %s', 'blogsafe-dash-notes' ),
				'save'          => __( 'Save', 'blogsafe-dash-notes' ),
				'cancel'        => __( 'Cancel', 'blogsafe-dash-notes' ),
				'edit'          => __( 'Edit', 'blogsafe-dash-notes' ),
				'delete'        => __( 'Delete', 'blogsafe-dash-notes' ),
				'confirmDelete' => __( 'Delete this note for everyone who can see it?', 'blogsafe-dash-notes' ),
				'dismiss'       => __( 'Remove from my dashboard', 'blogsafe-dash-notes' ),
				'dismissHint'   => __( 'You can put it back from Dash Notes > All Notes.', 'blogsafe-dash-notes' ),
				'minimize'      => __( 'Minimise', 'blogsafe-dash-notes' ),
				'restore'       => __( 'Expand', 'blogsafe-dash-notes' ),
				'moreActions'   => __( 'Note actions', 'blogsafe-dash-notes' ),
				'new'           => __( 'New', 'blogsafe-dash-notes' ),
				/* translators: %s: name of the user who wrote the note. */
				'from'          => __( 'From %s', 'blogsafe-dash-notes' ),
				/* translators: %s: list of user names. */
				'toUsers'       => __( 'To: %s', 'blogsafe-dash-notes' ),
				'toAll'         => __( 'To everyone', 'blogsafe-dash-notes' ),
				/* translators: 1: users who read the note, 2: number of recipients. */
				'readBy'        => __( 'Read by %1$d of %2$d', 'blogsafe-dash-notes' ),
				/* translators: %d: number of users who read the note. */
				'readByAll'     => __( 'Read by %d', 'blogsafe-dash-notes' ),
				'needsRecipient' => __( 'Pick at least one user to send the note to.', 'blogsafe-dash-notes' ),
				'needsText'     => __( 'A note needs a title or some text.', 'blogsafe-dash-notes' ),
				/* translators: %s: the reason the save failed. */
				'saveFailed'    => __( 'Could not save: %s', 'blogsafe-dash-notes' ),
				'networkError'  => __( 'the server could not be reached. Nothing was lost: check your connection and try again.', 'blogsafe-dash-notes' ),
				/* translators: %d: HTTP status code. */
				'blockedError'  => __( 'the server refused the request (HTTP %d) before WordPress saw it. A security plugin or firewall may be blocking it.', 'blogsafe-dash-notes' ),
				/* translators: %d: HTTP status code. */
				'serverError'   => __( 'the server answered with an error (HTTP %d). Try again in a moment.', 'blogsafe-dash-notes' ),
				'untitled'      => __( 'Note', 'blogsafe-dash-notes' ),
				'resize'        => __( 'Resize note', 'blogsafe-dash-notes' ),
				'resizeWidth'   => __( 'Drag to change the note width (or use the left and right arrow keys)', 'blogsafe-dash-notes' ),
			),
		);
		wp_add_inline_script( 'blogsafe-dash-notes', 'window.blogsafeDashNotes = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * "Notes" menu in the admin bar on admin pages where the board runs.
	 * The script wires up "Add note" and "Hide/Show notes".
	 */
	public static function admin_bar( $bar ) {
		if ( ! is_admin() || null === self::$here ) {
			return;
		}
		$count = self::$board_count;
		$bar->add_node(
			array(
				'id'    => 'bsdn',
				'title' => '<span class="ab-icon dashicons dashicons-sticky" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Notes', 'blogsafe-dash-notes' ) . ( $count ? ' <span class="bsdn-ab-count">' . esc_html( number_format_i18n( $count ) ) . '</span>' : '' ) . '</span>',
				'href'  => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			)
		);
		if ( BlogSafe_Dash_Notes_Settings::can_create() ) {
			$bar->add_node(
				array(
					'parent' => 'bsdn',
					'id'     => 'bsdn-add',
					'title'  => esc_html__( 'Add note to this page', 'blogsafe-dash-notes' ) . ' <span class="bsdn-ab-key">Alt+Shift+N</span>',
					'href'   => '#bsdn-add',
				)
			);
		}
		$bar->add_node(
			array(
				'parent' => 'bsdn',
				'id'     => 'bsdn-toggle',
				'title'  => esc_html__( 'Hide notes', 'blogsafe-dash-notes' ),
				'href'   => '#bsdn-toggle',
			)
		);
		$bar->add_node(
			array(
				'parent' => 'bsdn',
				'id'     => 'bsdn-help',
				'title'  => esc_html__( 'Help', 'blogsafe-dash-notes' ),
				'href'   => self::HELP_URL,
				'meta'   => array(
					'target' => '_blank',
					'rel'    => 'noopener',
				),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'bsdn',
				'id'     => 'bsdn-all',
				'title'  => esc_html__( 'All notes', 'blogsafe-dash-notes' ),
				'href'   => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* List page                                                          */
	/* ------------------------------------------------------------------ */

	public static function render_list() {
		require_once BLOGSAFE_DASH_NOTES_DIR . 'admin/class-blogsafe-dash-notes-list-table.php';
		$table = new BlogSafe_Dash_Notes_List_Table();
		BlogSafe_Dash_Notes_Network::sync();
		BlogSafe_Dash_Notes_Store::run_recurring();
		$table->prepare_items();

		$messages = array(
			'deleted'   => __( 'Note deleted.', 'blogsafe-dash-notes' ),
			'restored'  => __( 'Note put back on your dashboard.', 'blogsafe-dash-notes' ),
			'dismissed' => __( 'Note removed from your dashboard.', 'blogsafe-dash-notes' ),
			'unsnoozed' => __( 'Note is back on your screen.', 'blogsafe-dash-notes' ),
			'undone'    => __( 'Note marked not done.', 'blogsafe-dash-notes' ),
			'denied'    => __( 'That note does not exist or is not yours.', 'blogsafe-dash-notes' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only message key, checked against a whitelist.
		$done = isset( $_GET['bsdn_done'] ) ? sanitize_key( wp_unslash( $_GET['bsdn_done'] ) ) : '';
		?>
		<div class="wrap bsdn-console">
			<?php self::render_header(); ?>
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Dash Notes', 'blogsafe-dash-notes' ); ?></h1>
			<?php if ( BlogSafe_Dash_Notes_Settings::can_create() ) : ?>
				<a href="<?php echo esc_url( admin_url( 'index.php?bsdn_new=1' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add sticky note', 'blogsafe-dash-notes' ); ?></a>
			<?php endif; ?>
			<hr class="wp-header-end">
			<?php if ( isset( $messages[ $done ] ) ) : ?>
				<div class="notice notice-<?php echo 'denied' === $done ? 'error' : 'success'; ?> is-dismissible"><p><?php echo esc_html( $messages[ $done ] ); ?></p></div>
			<?php endif; ?>
			<?php self::render_import_notice(); ?>
			<p class="description"><?php esc_html_e( 'Sticky notes live on your Dashboard and on the admin pages you choose. This page lists every note sent to you or written by you, including notes you removed from your screen.', 'blogsafe-dash-notes' ); ?></p>
			<?php BlogSafe_Dash_Notes_Transfer::render_tools(); ?>
			<?php $table->views(); ?>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $table->view ); ?>">
				<?php $table->search_box( __( 'Search notes', 'blogsafe-dash-notes' ), 'bsdn-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** Result of an import, shown once after the redirect. */
	private static function render_import_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only keys and counts.
		if ( ! isset( $_GET['bsdn_import'] ) ) {
			return;
		}
		$code    = sanitize_key( wp_unslash( $_GET['bsdn_import'] ) );
		$added   = isset( $_GET['bsdn_added'] ) ? absint( $_GET['bsdn_added'] ) : 0;
		$skipped = isset( $_GET['bsdn_skipped'] ) ? absint( $_GET['bsdn_skipped'] ) : 0;
		// phpcs:enable
		$errors = array(
			'nofile'  => __( 'No file was uploaded. Choose a Dash Notes export file first.', 'blogsafe-dash-notes' ),
			'toobig'  => __( 'That file is larger than 2 MB, which is more than a Dash Notes export can be.', 'blogsafe-dash-notes' ),
			'badfile' => __( 'That is not a Dash Notes export file.', 'blogsafe-dash-notes' ),
		);
		if ( isset( $errors[ $code ] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $errors[ $code ] ) . '</p></div>';
			return;
		}
		/* translators: %d: number of notes imported. */
		$text = sprintf( _n( 'Imported %d note.', 'Imported %d notes.', $added, 'blogsafe-dash-notes' ), $added );
		if ( $skipped ) {
			/* translators: %d: number of notes that could not be imported. */
			$text .= ' ' . sprintf( _n( '%d could not be imported (empty or not valid).', '%d could not be imported (empty or not valid).', $skipped, 'blogsafe-dash-notes' ), $skipped );
		}
		echo '<div class="notice notice-' . ( $added ? 'success' : 'warning' ) . ' is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
	}

	/** URL that runs one row action (delete / restore / dismiss) with a nonce. */
	public static function action_url( $do, $note_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'do'     => $do,
					'note'   => (int) $note_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . $do . '_' . (int) $note_id
		);
	}

	public static function handle_action() {
		$do      = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
		$note_id = isset( $_GET['note'] ) ? absint( $_GET['note'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $do . '_' . $note_id );

		$user_id = get_current_user_id();
		$note    = BlogSafe_Dash_Notes_Store::get( $note_id );
		$result  = 'denied';

		if ( 'delete' === $do && BlogSafe_Dash_Notes_Store::can_edit( $note, $user_id ) ) {
			BlogSafe_Dash_Notes_Store::delete( $note_id );
			$result = 'deleted';
		} elseif ( 'unsnooze' === $do && BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			BlogSafe_Dash_Notes_Store::save_state( $note_id, $user_id, array( 'snoozed_until' => null ) );
			$result = 'unsnoozed';
		} elseif ( 'undone' === $do && BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) ) {
			BlogSafe_Dash_Notes_Store::save_state( $note_id, $user_id, array( 'done_at' => null ) );
			$result = 'undone';
		} elseif ( in_array( $do, array( 'restore', 'dismiss' ), true ) && BlogSafe_Dash_Notes_Store::is_audience( $note, $user_id ) && (int) $note->author_id !== $user_id ) {
			BlogSafe_Dash_Notes_Store::save_state( $note_id, $user_id, array( 'dismissed' => 'dismiss' === $do ? 1 : 0 ) );
			$result = 'restore' === $do ? 'restored' : 'dismissed';
		}

		$back = wp_get_referer();
		$back = $back ? $back : admin_url( 'admin.php?page=' . self::MENU_SLUG );
		wp_safe_redirect( add_query_arg( 'bsdn_done', $result, remove_query_arg( 'bsdn_done', $back ) ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                           */
	/* ------------------------------------------------------------------ */

	public static function register_settings() {
		register_setting(
			'blogsafe_dash_notes',
			BlogSafe_Dash_Notes_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'BlogSafe_Dash_Notes_Settings', 'sanitize' ),
				'default'           => BlogSafe_Dash_Notes_Settings::defaults(),
			)
		);
	}

	/**
	 * Logo header on Dash Notes' own pages, laid out like BlogSafe
	 * Forensics: the dark-background logo, then version and site link.
	 */
	private static function render_header() {
		?>
		<div class="bsdn-head">
			<img class="bsdn-logo" src="<?php echo esc_url( BLOGSAFE_DASH_NOTES_URL . 'admin/images/dash_notes_logo_dark.png' ); ?>" width="216" height="69" alt="<?php esc_attr_e( 'BlogSafe Dash Notes', 'blogsafe-dash-notes' ); ?>">
			<div class="bsdn-head-meta">
				<span class="bsdn-label">
					<?php
					/* translators: %s: plugin version number. */
					echo esc_html( sprintf( __( 'Version %s', 'blogsafe-dash-notes' ), BLOGSAFE_DASH_NOTES_VERSION ) );
					?>
				</span>
				<a href="<?php echo esc_url( self::MORE_PLUGINS_URL ); ?>" target="_blank" rel="noopener">blogsafe.org</a>
			</div>
			<a class="button bsdn-help-btn" href="<?php echo esc_url( self::HELP_URL ); ?>" target="_blank" rel="noopener">
				<span class="dashicons dashicons-editor-help" aria-hidden="true"></span>
				<?php esc_html_e( 'Help', 'blogsafe-dash-notes' ); ?>
				<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'blogsafe-dash-notes' ); ?></span>
			</a>
		</div>
		<?php
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = BlogSafe_Dash_Notes_Settings::get();
		$name     = BlogSafe_Dash_Notes_Settings::OPTION;
		?>
		<div class="wrap bsdn-console">
			<?php self::render_header(); ?>
			<h1><?php esc_html_e( 'Dash Notes Settings', 'blogsafe-dash-notes' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'blogsafe_dash_notes' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bsdn-create-cap"><?php esc_html_e( 'Who can write notes', 'blogsafe-dash-notes' ); ?></label></th>
						<td>
							<select id="bsdn-create-cap" name="<?php echo esc_attr( $name ); ?>[create_cap]">
								<?php foreach ( BlogSafe_Dash_Notes_Settings::create_cap_choices() as $cap => $label ) : ?>
									<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $settings['create_cap'], $cap ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Everyone who can reach the Dashboard sees the notes sent to them, can move and resize them, and can remove them from their own dashboard. Only these users can write, send, edit and delete notes. Administrators can edit and delete every note.', 'blogsafe-dash-notes' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
