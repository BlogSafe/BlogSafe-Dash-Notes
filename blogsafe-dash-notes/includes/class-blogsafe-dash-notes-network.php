<?php
/**
 * Network notes (multisite): a super admin writes a note on the main site
 * with the audience "network" ("Everyone on every site"). The main site's
 * note is the original; every other site keeps a read-only copy
 * (`network_id` = the original's id, audience "all") that is refreshed on
 * that site's next admin page load after a network note changed.
 *
 * Done, snooze, checklist ticks and replies on a copy belong to that site.
 * Editing and deleting happen on the main site only.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Network {

	/** Network-wide: when a network note last changed. */
	const CHANGED = 'blogsafe_dash_notes_network_changed';

	/** Per site: the CHANGED value this site last copied. */
	const SYNCED = 'blogsafe_dash_notes_network_sync';

	/** Is "Everyone on every site" available to this user here? */
	public static function can_write( $user_id = 0 ) {
		$user_id = $user_id ? (int) $user_id : get_current_user_id();
		return is_multisite() && is_main_site() && $user_id && is_super_admin( $user_id );
	}

	/** Tell every site that network notes changed. */
	public static function bump() {
		if ( is_multisite() ) {
			update_site_option( self::CHANGED, (string) microtime( true ) );
		}
	}

	/**
	 * On a site other than the main one: bring the copies of network notes
	 * up to date if anything changed since this site last looked. Cheap when
	 * nothing changed (two option reads).
	 */
	public static function sync() {
		if ( ! is_multisite() || is_main_site() ) {
			return;
		}
		$changed = (string) get_site_option( self::CHANGED, '' );
		if ( '' === $changed || get_option( self::SYNCED ) === $changed ) {
			return;
		}

		// Read the originals from the main site.
		$sources = array();
		switch_to_blog( get_main_site_id() );
		if ( false !== get_option( BlogSafe_Dash_Notes_Schema::OPTION ) ) {
			global $wpdb;
			$notes = BlogSafe_Dash_Notes_Schema::notes_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
			$rows = $wpdb->get_results( "SELECT * FROM {$notes} WHERE audience = 'network' AND is_template = 0 ORDER BY id LIMIT 200" );
			$ids  = wp_list_pluck( (array) $rows, 'id' );
			BlogSafe_Dash_Notes_Store::prime_screens( $ids );
			foreach ( (array) $rows as $r ) {
				$sources[ (int) $r->id ] = array(
					'row'     => $r,
					'screens' => BlogSafe_Dash_Notes_Store::screens( $r->id ),
					'items'   => BlogSafe_Dash_Notes_Items::texts( $r->id ),
				);
			}
		}
		restore_current_blog();
		// The screen cache is keyed by note id only; ids from the main site
		// must not leak into this site's lookups.
		BlogSafe_Dash_Notes_Store::forget_screens( array_keys( $sources ) );
		BlogSafe_Dash_Notes_Items::forget( array_keys( $sources ) );

		self::apply( $sources );
		update_option( self::SYNCED, $changed, false );
	}

	/** Make this site's copies match $sources (keyed by original id). */
	private static function apply( array $sources ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom table.
		$local = array();
		foreach ( (array) $wpdb->get_results( "SELECT id, network_id, updated_at FROM {$notes} WHERE network_id > 0" ) as $l ) {
			$local[ (int) $l->network_id ] = $l;
		}
		foreach ( $sources as $source_id => $src ) {
			$r      = $src['row'];
			$fields = array(
				'title'    => (string) $r->title,
				'content'  => (string) $r->content,
				'color'    => (string) $r->color,
				'priority' => (int) $r->priority,
				'deadline' => $r->deadline ? (string) $r->deadline : null,
				'audience' => 'all',
				'roles'    => '',
				'recur'    => '',
				'anchor'   => (string) $r->anchor,
			);
			if ( isset( $local[ $source_id ] ) ) {
				$copy = $local[ $source_id ];
				if ( (string) $copy->updated_at === (string) $r->updated_at ) {
					continue; // unchanged
				}
				$changed_text = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notes} WHERE id = %d AND ( title <> %s OR content <> %s )", $copy->id, $fields['title'], $fields['content'] ) );
				$fields['updated_at'] = $r->updated_at;
				$wpdb->update( $notes, $fields, array( 'id' => (int) $copy->id ) );
				$note_id = (int) $copy->id;
				if ( $changed_text ) {
					// Everyone on this site sees the changed note as new.
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . BlogSafe_Dash_Notes_Schema::users_table() . ' SET read_at = NULL WHERE note_id = %d', $note_id ) );
				}
			} else {
				$wpdb->insert(
					$notes,
					array_merge(
						$fields,
						array(
							'author_id'   => (int) $r->author_id,
							'network_id'  => (int) $source_id,
							'is_template' => 0,
							'created_at'  => $r->created_at,
							'updated_at'  => $r->updated_at,
						)
					)
				);
				$note_id = (int) $wpdb->insert_id;
			}
			BlogSafe_Dash_Notes_Store::replace_screens( $note_id, $src['screens'] );
			$keep = array();
			foreach ( BlogSafe_Dash_Notes_Items::for_note( $note_id ) as $item ) {
				$keep[ $item['text'] ] = $item['id'];
			}
			$items = array();
			foreach ( $src['items'] as $item ) {
				$items[] = array(
					'id'   => $keep[ $item['text'] ] ?? 0,
					'text' => $item['text'],
				);
			}
			BlogSafe_Dash_Notes_Items::save( $note_id, $items );
		}
		// Originals that are gone: remove their copies.
		foreach ( $local as $source_id => $copy ) {
			if ( ! isset( $sources[ $source_id ] ) ) {
				BlogSafe_Dash_Notes_Store::delete( (int) $copy->id );
			}
		}
		// phpcs:enable
	}
}
