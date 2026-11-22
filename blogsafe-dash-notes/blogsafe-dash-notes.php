<?php
/**
 * Plugin Name:       BlogSafe Dash Notes
 * Plugin URI:        https://blogsafe.org/
 * Description:       Sticky notes on the WordPress dashboard. Drag them, resize them, colour them, and send them to other users with a priority and a deadline.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            BlogSafe
 * Author URI:        https://blogsafe.org/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       blogsafe-dash-notes
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BLOGSAFE_DASH_NOTES_VERSION', '0.1.0' );
define( 'BLOGSAFE_DASH_NOTES_DB_VERSION', '0.4.0' );
define( 'BLOGSAFE_DASH_NOTES_FILE', __FILE__ );
define( 'BLOGSAFE_DASH_NOTES_DIR', plugin_dir_path( __FILE__ ) );
define( 'BLOGSAFE_DASH_NOTES_URL', plugin_dir_url( __FILE__ ) );

require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-schema.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-settings.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-store.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-items.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-replies.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-revisions.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-network.php';
require_once BLOGSAFE_DASH_NOTES_DIR . 'includes/class-blogsafe-dash-notes-rest.php';

register_activation_hook( __FILE__, array( 'BlogSafe_Dash_Notes_Schema', 'install' ) );

// Deploys are FTP copy-overs, which never run the activation hook, so the
// tables are also checked (one autoloaded option read) on every load. On
// init, not plugins_loaded: nothing may trigger translations before init
// (WordPress 6.7+ logs a "_load_textdomain_just_in_time" notice).
add_action( 'init', array( 'BlogSafe_Dash_Notes_Schema', 'maybe_upgrade' ), 1 );

// Multisite: a deleted site takes its Dash Notes tables with it.
add_filter( 'wpmu_drop_tables', array( 'BlogSafe_Dash_Notes_Schema', 'drop_with_site' ) );

BlogSafe_Dash_Notes_Store::boot();
BlogSafe_Dash_Notes_Rest::boot();

if ( is_admin() ) {
	require_once BLOGSAFE_DASH_NOTES_DIR . 'admin/class-blogsafe-dash-notes-admin.php';
	require_once BLOGSAFE_DASH_NOTES_DIR . 'admin/class-blogsafe-dash-notes-transfer.php';
	BlogSafe_Dash_Notes_Admin::boot();
	BlogSafe_Dash_Notes_Transfer::boot();
}
