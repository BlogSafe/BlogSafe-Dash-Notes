<?php
/**
 * Checklist items. The author (or a manager) edits the list; everyone who
 * can see the note ticks items, and the ticks are shared.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Items {

	const MAX_ITEMS = 50;
	const MAX_TEXT  = 500;

	/** @var array<int,array> items per note, cached for this request */
	private static $cache = array();

	/**
	 * Clean a list sent by the browser: [{id?, text}, …]. Empty lines are
	 * dropped; at most 50 items.
	 *
	 * @return array<int,array{id:int,text:string}>
	 */
	public static function clean( $input ) {
		$out = array();
		foreach ( is_array( $input ) ? $input : array() as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$text = mb_substr( trim( sanitize_text_field( (string) ( $item['text'] ?? '' ) ) ), 0, self::MAX_TEXT );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'id'   => absint( $item['id'] ?? 0 ),
				'text' => $text,
			);
			if ( count( $out ) >= self::MAX_ITEMS ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Replace a note's list. Items that keep their id keep their tick.
	 */
	public static function save( $note_id, array $items ) {
		global $wpdb;
		$table   = BlogSafe_Dash_Notes_Schema::items_table();
		$note_id = (int) $note_id;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table.
		$existing = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE note_id = %d", $note_id ) ) );
		$keep     = array();
		foreach ( array_values( $items ) as $pos => $item ) {
			$item_id = (int) ( $item['id'] ?? 0 ); // lists from texts() have no ids
			if ( $item_id && in_array( $item_id, $existing, true ) ) {
				$wpdb->update( $table, array( 'text' => $item['text'], 'position' => $pos ), array( 'id' => $item_id, 'note_id' => $note_id ), array( '%s', '%d' ), array( '%d', '%d' ) );
				$keep[] = $item_id;
			} else {
				$wpdb->insert( $table, array( 'note_id' => $note_id, 'position' => $pos, 'text' => $item['text'] ), array( '%d', '%d', '%s' ) );
			}
		}
		$gone = array_diff( $existing, $keep );
		if ( $gone ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE note_id = %d AND id IN (" . implode( ',', array_fill( 0, (int) count( $gone ), '%d' ) ) . ')', array_merge( array( $note_id ), array_values( $gone ) ) ) );
		}
		// phpcs:enable
		unset( self::$cache[ $note_id ] );
	}

	/** Tick or untick one item. Returns false when the item is not on that note. */
	public static function tick( $note_id, $item_id, $done, $user_id ) {
		global $wpdb;
		$table = BlogSafe_Dash_Notes_Schema::items_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$n = $wpdb->update(
			$table,
			$done ? array(
				'done_by' => (int) $user_id,
				'done_at' => current_time( 'mysql', true ),
			) : array(
				'done_by' => 0,
				'done_at' => null,
			),
			array(
				'id'      => (int) $item_id,
				'note_id' => (int) $note_id,
			)
		);
		unset( self::$cache[ (int) $note_id ] );
		if ( false === $n ) {
			return false;
		}
		// 0 rows changed is fine when it was already in that state; check it exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d AND note_id = %d", $item_id, $note_id ) );
	}

	/** Untick every item (recurring notes start over). */
	public static function reset( $note_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . BlogSafe_Dash_Notes_Schema::items_table() . ' SET done_by = 0, done_at = NULL WHERE note_id = %d', $note_id ) );
		unset( self::$cache[ (int) $note_id ] );
	}

	/** Drop cached items (network sync reads ids from another site). */
	public static function forget( array $note_ids ) {
		foreach ( $note_ids as $id ) {
			unset( self::$cache[ (int) $id ] );
		}
	}

	/** Load the items of several notes in one query. */
	public static function prime( array $note_ids ) {
		global $wpdb;
		$ids = array_diff( array_unique( array_map( 'intval', $note_ids ) ), array_keys( self::$cache ), array( 0 ) );
		if ( ! $ids ) {
			return;
		}
		foreach ( $ids as $id ) {
			self::$cache[ $id ] = array();
		}
		$table = BlogSafe_Dash_Notes_Schema::items_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- custom table; ids are placeholders.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, note_id, text, done_by, done_at FROM {$table} WHERE note_id IN (" . implode( ',', array_fill( 0, (int) count( $ids ), '%d' ) ) . ') ORDER BY note_id, position, id', $ids ) );
		BlogSafe_Dash_Notes_Store::prime_names( wp_list_pluck( (array) $rows, 'done_by' ) );
		foreach ( (array) $rows as $r ) {
			self::$cache[ (int) $r->note_id ][] = array(
				'id'     => (int) $r->id,
				'text'   => (string) $r->text,
				'done'   => null !== $r->done_at,
				'doneBy' => $r->done_at ? BlogSafe_Dash_Notes_Store::name( $r->done_by ) : '',
			);
		}
	}

	/** @return array<int,array{id:int,text:string,done:bool,doneBy:string}> */
	public static function for_note( $note_id ) {
		self::prime( array( $note_id ) );
		return self::$cache[ (int) $note_id ] ?? array();
	}

	/** Plain list for copying a note (templates, export): [{text}, …]. */
	public static function texts( $note_id ) {
		return array_map(
			static function ( $i ) {
				return array( 'text' => $i['text'] );
			},
			self::for_note( $note_id )
		);
	}

	public static function delete_for_note( $note_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( BlogSafe_Dash_Notes_Schema::items_table(), array( 'note_id' => (int) $note_id ), array( '%d' ) );
		unset( self::$cache[ (int) $note_id ] );
	}
}
