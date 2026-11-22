<?php
/**
 * Removes Dash Notes tables and options when the plugin is deleted.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Drop this site's tables and options. */
function blogsafe_dash_notes_uninstall_site() {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- removing the plugin's own tables.
	foreach ( array( 'screens', 'users', 'items', 'replies', 'revisions' ) as $suffix ) {
		$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'blogsafe_dash_notes_' . $suffix ) . '`' );
	}
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $wpdb->prefix . 'blogsafe_dash_notes' ) . '`' );
	// phpcs:enable
	delete_option( 'blogsafe_dash_notes_db_version' );
	delete_option( 'blogsafe_dash_notes_settings' );
	delete_option( 'blogsafe_dash_notes_network_sync' );
}

if ( is_multisite() ) {
	$blogsafe_dash_notes_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $blogsafe_dash_notes_sites as $blogsafe_dash_notes_site ) {
		switch_to_blog( $blogsafe_dash_notes_site );
		blogsafe_dash_notes_uninstall_site();
		restore_current_blog();
	}
} else {
	blogsafe_dash_notes_uninstall_site();
}

// Each user's remembered All Notes view (user meta is network-wide).
delete_metadata( 'user', 0, 'blogsafe_dash_notes_list_view', '', true );

// Network notes: the network-wide "last changed" marker.
if ( is_multisite() ) {
	delete_site_option( 'blogsafe_dash_notes_network_changed' );
}
