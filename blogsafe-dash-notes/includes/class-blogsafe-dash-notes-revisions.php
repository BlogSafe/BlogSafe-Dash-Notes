<?php
/**
 * Note history: before a note's text, look or checklist changes, the
 * current version is saved here. The newest 20 per note are kept, and any
 * of them can be restored by whoever may edit the note.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Revisions {

	const KEEP = 20;

	/** Fields a revision holds (besides the checklist). */
	const FIELDS = array( 'title', 'content', 'color', 'priority', 'deadline' );

	/**
	 * Would these changes alter what a revision holds?
	 *
	 * @param array      $fields cleaned (partial) note fields about to be saved
	 * @param array|null $items  cleaned checklist about to be saved, or null
	 */
	public static function changes( $note, array $fields, $items ) {
		foreach ( self::FIELDS as $key ) {
			if ( array_key_exists( $key, $fields ) && (string) $fields[ $key ] !== (string) $note->$key ) {
				return true;
			}
		}
		if ( null !== $items ) {
			$now  = wp_list_pluck( BlogSafe_Dash_Notes_Items::texts( $note->id ), 'text' );
			$next = wp_list_pluck( $items, 'text' );
			return $now !== $next;
		}
		return false;
	}

	/** Save the note as it is now. */
	public static function snapshot( $note, $user_id ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::revisions_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->insert(
			$table,
			array(
				'note_id'    => (int) $note->id,
				'user_id'    => (int) $user_id,
				'title'      => (string) $note->title,
				'content'    => (string) $note->content,
				'color'      => (string) $note->color,
				'priority'   => (int) $note->priority,
				'deadline'   => $note->deadline ? (string) $note->deadline : null,
				'items'      => wp_json_encode( BlogSafe_Dash_Notes_Items::texts( $note->id ) ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
		// Keep only the newest KEEP.
		$old = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE note_id = %d ORDER BY id DESC LIMIT 1000 OFFSET %d", $note->id, self::KEEP ) );
		if ( $old ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN (" . implode( ',', array_fill( 0, (int) count( $old ), '%d' ) ) . ')', array_map( 'intval', $old ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ids are placeholders.
		}
		// phpcs:enable
	}

	/** Summaries for the History panel, newest first. Text is plain. */
	public static function for_note( $note_id ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::revisions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, title, content, items, created_at FROM {$table} WHERE note_id = %d ORDER BY id DESC LIMIT %d", $note_id, self::KEEP ) );
		BlogSafe_Dash_Notes_Store::prime_names( wp_list_pluck( (array) $rows, 'user_id' ) );
		$out = array();
		foreach ( (array) $rows as $r ) {
			$items = json_decode( (string) $r->items, true );
			$out[] = array(
				'id'      => (int) $r->id,
				'at'      => mysql_to_rfc3339( $r->created_at ),
				'by'      => BlogSafe_Dash_Notes_Store::name( $r->user_id ),
				'title'   => (string) $r->title,
				'excerpt' => wp_html_excerpt( (string) $r->content, 120, '…' ),
				'items'   => is_array( $items ) ? count( $items ) : 0,
			);
		}
		return $out;
	}

	public static function get( $revision_id ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::revisions_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $revision_id ) );
	}

	/**
	 * Put a revision back. The current version is saved first, so a restore
	 * can itself be undone. Checklist ticks are kept for items whose text is
	 * unchanged.
	 */
	public static function restore( $note, $revision, $user_id ) {
		self::snapshot( $note, $user_id );
		$fields = BlogSafe_Dash_Notes_Store::clean_fields(
			array(
				'title'    => $revision->title,
				'content'  => $revision->content,
				'color'    => $revision->color,
				'priority' => (int) $revision->priority,
				'deadline' => (string) $revision->deadline,
			),
			true
		);
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		BlogSafe_Dash_Notes_Store::update( $note, $fields, null );

		$by_text = array();
		foreach ( BlogSafe_Dash_Notes_Items::for_note( $note->id ) as $item ) {
			$by_text[ $item['text'] ] = $item['id'];
		}
		$items = array();
		foreach ( BlogSafe_Dash_Notes_Items::clean( json_decode( (string) $revision->items, true ) ) as $item ) {
			$item['id'] = $by_text[ $item['text'] ] ?? 0;
			$items[]    = $item;
		}
		BlogSafe_Dash_Notes_Items::save( $note->id, $items );
		return true;
	}
}
