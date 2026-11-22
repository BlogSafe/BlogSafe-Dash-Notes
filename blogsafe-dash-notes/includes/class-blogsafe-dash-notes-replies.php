<?php
/**
 * Replies under a note. Plain text only; everyone who can see the note can
 * reply. A reply makes the note "new" again for everyone else.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Replies {

	const MAX_TEXT    = 2000;
	const MAX_PER_NOTE = 200;

	/** @var array<int,array> replies per note, cached for this request */
	private static $cache = array();

	/** @return string cleaned text, '' when empty */
	public static function clean( $text ) {
		return mb_substr( trim( sanitize_textarea_field( (string) $text ) ), 0, self::MAX_TEXT );
	}

	/** @return int new reply id, 0 when the note already has the maximum */
	public static function add( $note, $user_id, $text ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::replies_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE note_id = %d", $note->id ) );
		if ( $count >= self::MAX_PER_NOTE ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared -- custom table.
		$wpdb->insert(
			$table,
			array(
				'note_id'    => (int) $note->id,
				'user_id'    => (int) $user_id,
				'content'    => $text,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		// Everyone else sees the note as new again; the replier has read it.
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query( $wpdb->prepare( "UPDATE {$users} SET read_at = NULL WHERE note_id = %d AND user_id <> %d", $note->id, $user_id ) );
		BlogSafe_Dash_Notes_Store::save_state( $note->id, $user_id, array( 'read_at' => current_time( 'mysql', true ) ) );
		unset( self::$cache[ (int) $note->id ] );
		return $id;
	}

	public static function get( $reply_id ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::replies_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $reply_id ) );
	}

	public static function delete( $reply_id ) {
		global $wpdb;
		$reply = self::get( $reply_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::replies_table(), array( 'id' => (int) $reply_id ), array( '%d' ) );
		if ( $reply ) {
			unset( self::$cache[ (int) $reply->note_id ] );
		}
	}

	/** Load the replies of several notes in one query. */
	public static function prime( array $note_ids ) {
		global $wpdb;
		$ids = array_diff( array_unique( array_map( 'intval', $note_ids ) ), array_keys( self::$cache ), array( 0 ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$cache[ $id ] = array();
		}
		$table = BlogSafe_Dash_Notes_Schema::replies_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table; ids are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, note_id, user_id, content, created_at FROM {$table} WHERE note_id IN (" . implode( ',', array_fill( 0, (int) count( $ids ), '%d' ) ) . ') ORDER BY note_id, id', $ids ) );
		BlogSafe_Dash_Notes_Store::prime_names( wp_list_pluck( (array) $rows, 'user_id' ) );
		foreach ( (array) $rows as $r ) {
			self::$cache[ (int) $r->note_id ][] = array(
				'id'     => (int) $r->id,
				'userId' => (int) $r->user_id,
				'name'   => BlogSafe_Dash_Notes_Store::name( $r->user_id ),
				'text'   => sanitize_textarea_field( (string) $r->content ), // plain text, as when it was saved
				// Same format as mysql_to_rfc3339(), rebuilt from a number.
				'at'     => gmdate( 'Y-m-d\TH:i:s', (int) strtotime( $r->created_at . ' UTC' ) ),
			);
		}
	}

	/** Replies as sent to the script. Text is plain; the script escapes it. */
	public static function for_note( $note_id, $viewer_id, $viewer_can_edit_note ) {
		self::prime( array( $note_id ) );
		$out = array();
		foreach ( self::$cache[ (int) $note_id ] ?? array() as $r ) {
			$r['canDelete'] = $viewer_can_edit_note || $r['userId'] === (int) $viewer_id;
			$out[]          = $r;
		}
		return $out;
	}

	public static function delete_for_note( $note_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::replies_table(), array( 'note_id' => (int) $note_id ), array( '%d' ) );
		unset( self::$cache[ (int) $note_id ] );
	}
}
