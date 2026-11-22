<?php
/**
 * Database tables.
 *
 * notes       one row per note (the author's content and settings).
 * note_users  one row per (note, user): whether the user is a recipient, when
 *             they read it, and where the note sits on *their* dashboard.
 *             Rows for "Everyone" notes are created the first time a user
 *             reads or moves the note.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Schema {

	const OPTION = 'blogsafe_dash_notes_db_version';

	public static function notes_table() {
		global $wpdb;
		return $wpdb->prefix . 'blogsafe_dash_notes';
	}

	public static function users_table() {
		global $wpdb;
		return $wpdb->prefix . 'blogsafe_dash_notes_users';
	}

	public static function screens_table() {
		global $wpdb;
		return $wpdb->prefix . 'blogsafe_dash_notes_screens';
	}

	public static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'blogsafe_dash_notes_items';
	}

	public static function replies_table() {
		global $wpdb;
		return $wpdb->prefix . 'blogsafe_dash_notes_replies';
	}

	public static function revisions_table() {
		global $wpdb;
		return $wpdb->prefix . 'blogsafe_dash_notes_revisions';
	}

	/** All of this site's tables. */
	public static function tables() {
		return array( self::notes_table(), self::users_table(), self::screens_table(), self::items_table(), self::replies_table(), self::revisions_table() );
	}

	/**
	 * Multisite: drop this site's tables when the site is deleted. WordPress
	 * passes the list of tables for the site being deleted; $wpdb->prefix is
	 * that site's prefix at this point.
	 */
	public static function drop_with_site( $tables ) {
		return array_merge( (array) $tables, self::tables() );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION ) !== BLOGSAFE_DASH_NOTES_DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$notes   = self::notes_table();
		$users   = self::users_table();

		dbDelta(
			"CREATE TABLE {$notes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  author_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL DEFAULT '',
  content mediumtext NOT NULL,
  color varchar(7) NOT NULL DEFAULT '#fff59d',
  priority tinyint(1) unsigned NOT NULL DEFAULT 0,
  deadline date DEFAULT NULL,
  audience varchar(10) NOT NULL DEFAULT 'private',
  roles varchar(500) NOT NULL DEFAULT '',
  is_template tinyint(1) unsigned NOT NULL DEFAULT 0,
  recur varchar(10) NOT NULL DEFAULT '',
  recur_next datetime DEFAULT NULL,
  anchor varchar(500) NOT NULL DEFAULT '',
  network_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY author_id (author_id),
  KEY audience (audience),
  KEY recur_next (recur_next),
  KEY network_id (network_id)
) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$users} (
  note_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  is_recipient tinyint(1) unsigned NOT NULL DEFAULT 0,
  read_at datetime DEFAULT NULL,
  dismissed tinyint(1) unsigned NOT NULL DEFAULT 0,
  pos_x int(11) DEFAULT NULL,
  pos_y int(11) DEFAULT NULL,
  width smallint(5) unsigned DEFAULT NULL,
  height smallint(5) unsigned DEFAULT NULL,
  z_order int(10) unsigned NOT NULL DEFAULT 0,
  minimized tinyint(1) unsigned NOT NULL DEFAULT 0,
  locked tinyint(1) unsigned NOT NULL DEFAULT 0,
  done_at datetime DEFAULT NULL,
  snoozed_until datetime DEFAULT NULL,
  PRIMARY KEY  (note_id,user_id),
  KEY user_id (user_id)
) {$charset};"
		);

		$screens = self::screens_table();
		dbDelta(
			"CREATE TABLE {$screens} (
  note_id bigint(20) unsigned NOT NULL,
  screen_key varchar(120) NOT NULL,
  label varchar(200) NOT NULL DEFAULT '',
  url varchar(500) NOT NULL DEFAULT '',
  PRIMARY KEY  (note_id,screen_key),
  KEY screen_key (screen_key)
) {$charset};"
		);

		// Checklist items: ticks are shared by everyone who sees the note.
		$items = self::items_table();
		dbDelta(
			"CREATE TABLE {$items} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  note_id bigint(20) unsigned NOT NULL,
  position smallint(5) unsigned NOT NULL DEFAULT 0,
  text varchar(500) NOT NULL DEFAULT '',
  done_by bigint(20) unsigned NOT NULL DEFAULT 0,
  done_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY note_id (note_id)
) {$charset};"
		);

		// Replies under a note (plain text).
		$replies = self::replies_table();
		dbDelta(
			"CREATE TABLE {$replies} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  note_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  content text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY note_id (note_id)
) {$charset};"
		);

		// Earlier versions of a note's text, newest kept, capped per note.
		$revisions = self::revisions_table();
		dbDelta(
			"CREATE TABLE {$revisions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  note_id bigint(20) unsigned NOT NULL,
  user_id bigint(20) unsigned NOT NULL,
  title varchar(200) NOT NULL DEFAULT '',
  content mediumtext NOT NULL,
  color varchar(7) NOT NULL DEFAULT '',
  priority tinyint(1) unsigned NOT NULL DEFAULT 0,
  deadline date DEFAULT NULL,
  items text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY note_id (note_id)
) {$charset};"
		);

		// Notes from before 0.3.0 lived on the Dashboard only.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables, no user input.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$screens} (note_id, screen_key, label, url)
				SELECT n.id, 'dashboard', %s, 'index.php' FROM {$notes} n
				LEFT JOIN {$screens} s ON s.note_id = n.id
				WHERE s.note_id IS NULL",
				'Dashboard' // Not translated here: install can run before init. The Dashboard label is translated where it is shown.
			)
		);
		// phpcs:enable

		update_option( self::OPTION, BLOGSAFE_DASH_NOTES_DB_VERSION, true );
	}
}
