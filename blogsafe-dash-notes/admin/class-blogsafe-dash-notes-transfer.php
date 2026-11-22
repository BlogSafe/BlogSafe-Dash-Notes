<?php
/**
 * Export and import of notes as a JSON file (Dash Notes > All Notes).
 *
 * Export: the notes the user wrote (templates included); managers can
 * export every note on the site. Each note carries its text, look, audience
 * (recipients by login), places, repeat and checklist. Replies, history and
 * per-user state stay behind.
 *
 * Import: every value goes through the same cleaning as a normal save, so a
 * crafted file cannot add anything a user could not have typed.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BlogSafe_Dash_Notes_Transfer {

	const EXPORT_ACTION = 'blogsafe_dash_notes_export';
	const IMPORT_ACTION = 'blogsafe_dash_notes_import';
	const FORMAT        = 1;
	const MAX_BYTES     = 2097152; // 2 MB
	const MAX_NOTES     = 500;

	public static function boot() {
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( __CLASS__, 'export' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( __CLASS__, 'import' ) );
	}

	public static function export_url( $scope ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::EXPORT_ACTION,
					'scope'  => $scope,
				),
				admin_url( 'admin-post.php' )
			),
			self::EXPORT_ACTION
		);
	}

	/** Buttons and upload form shown above the notes table. */
	public static function render_tools() {
		if ( ! BlogSafe_Dash_Notes_Settings::can_create() ) {
			return;
		}
		?>
		<div class="bsdn-transfer">
			<a class="button" href="<?php echo esc_url( self::export_url( 'mine' ) ); ?>"><?php esc_html_e( 'Export my notes', 'blogsafe-dash-notes' ); ?></a>
			<?php if ( BlogSafe_Dash_Notes_Settings::is_manager() ) : ?>
				<a class="button" href="<?php echo esc_url( self::export_url( 'all' ) ); ?>"><?php esc_html_e( 'Export every note', 'blogsafe-dash-notes' ); ?></a>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" class="bsdn-import-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>">
				<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
				<label class="screen-reader-text" for="bsdn-import-file"><?php esc_html_e( 'Dash Notes export file', 'blogsafe-dash-notes' ); ?></label>
				<input type="file" id="bsdn-import-file" name="bsdn_import" accept=".json,application/json" required>
				<button type="submit" class="button"><?php esc_html_e( 'Import notes', 'blogsafe-dash-notes' ); ?></button>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                             */
	/* ------------------------------------------------------------------ */

	public static function export() {
		check_admin_referer( self::EXPORT_ACTION );
		$user_id = get_current_user_id();
		if ( ! BlogSafe_Dash_Notes_Settings::can_create( $user_id ) ) {
			wp_die( esc_html__( 'You are not allowed to export notes.', 'blogsafe-dash-notes' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above.
		$all  = isset( $_GET['scope'] ) && 'all' === $_GET['scope'] && BlogSafe_Dash_Notes_Settings::is_manager( $user_id );
		$data = self::build( $all ? 0 : $user_id, BlogSafe_Dash_Notes_Settings::is_manager( $user_id ) );

		$name = 'dash-notes-' . sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . gmdate( 'Y-m-d' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/**
	 * The export document. $author_id 0 = every note on the site.
	 *
	 * Login names are written only for administrators ($with_logins), who can
	 * see them anyway; they let a file move between sites. Everyone else gets
	 * recipients as user ids and no author, so an export never tells a note
	 * writer another user's login name.
	 */
	public static function build( $author_id, $with_logins = false ) {
		global $wpdb;
		$notes = BlogSafe_Dash_Notes_Schema::notes_table();
		$users = BlogSafe_Dash_Notes_Schema::users_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- custom tables.
		$rows = $author_id
			? $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$notes} WHERE author_id = %d AND network_id = 0 ORDER BY id LIMIT %d", $author_id, self::MAX_NOTES ) )
			: $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$notes} WHERE network_id = 0 ORDER BY id LIMIT %d", self::MAX_NOTES ) );
		$ids = wp_list_pluck( (array) $rows, 'id' );
		BlogSafe_Dash_Notes_Store::prime_screens( $ids );
		BlogSafe_Dash_Notes_Items::prime( $ids );

		$out = array();
		foreach ( (array) $rows as $n ) {
			$recipients = array();
			if ( 'users' === $n->audience ) {
				$rec_ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM {$users} WHERE note_id = %d AND is_recipient = 1 LIMIT %d", $n->id, BlogSafe_Dash_Notes_Store::MAX_RECIPIENTS ) );
				foreach ( $rec_ids as $rid ) {
					$u = get_userdata( (int) $rid );
					if ( $u ) {
						$recipients[] = $with_logins ? $u->user_login : (int) $u->ID;
					}
				}
			}
			$author = get_userdata( (int) $n->author_id );
			$out[]  = array(
				'title'       => (string) $n->title,
				'content'     => (string) $n->content,
				'color'       => (string) $n->color,
				'priority'    => (int) $n->priority,
				'deadline'    => $n->deadline ? (string) $n->deadline : '',
				'audience'    => (string) $n->audience,
				'roles'       => array_values( array_filter( explode( ',', (string) $n->roles ) ) ),
				'recipients'  => $recipients,
				'recur'       => (string) $n->recur,
				'is_template' => (bool) $n->is_template,
				'author'      => $author && $with_logins ? $author->user_login : '',
				'screens'     => BlogSafe_Dash_Notes_Store::screens( $n->id ),
				'items'       => BlogSafe_Dash_Notes_Items::texts( $n->id ),
			);
		}
		// phpcs:enable
		return array(
			'plugin'   => 'blogsafe-dash-notes',
			'format'   => self::FORMAT,
			'version'  => BLOGSAFE_DASH_NOTES_VERSION,
			'exported' => gmdate( 'c' ),
			'notes'    => $out,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                             */
	/* ------------------------------------------------------------------ */

	public static function import() {
		check_admin_referer( self::IMPORT_ACTION );
		$user_id = get_current_user_id();
		if ( ! current_user_can( BlogSafe_Dash_Notes_Settings::create_cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to import notes.', 'blogsafe-dash-notes' ), '', array( 'response' => 403 ) );
		}
		$result = array(
			'error' => '',
			'added' => 0,
			'skip'  => 0,
		);
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the upload's tmp path and size are checked, contents are parsed as JSON and cleaned field by field.
		$file = isset( $_FILES['bsdn_import'] ) && is_array( $_FILES['bsdn_import'] ) ? $_FILES['bsdn_import'] : null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
			$result['error'] = 'nofile';
		} elseif ( (int) $file['size'] > self::MAX_BYTES ) {
			$result['error'] = 'toobig';
		} else {
			$json   = self::read_upload( (string) $file['tmp_name'] );
			$result = '' === $json
				? array_merge( $result, array( 'error' => 'nofile' ) )
				: array_merge( $result, self::import_document( json_decode( $json, true ), $user_id ) );
		}
		$back = add_query_arg(
			array(
				'page'         => BlogSafe_Dash_Notes_Admin::MENU_SLUG,
				'bsdn_import'  => $result['error'] ? $result['error'] : 'ok',
				'bsdn_added'   => (int) $result['added'],
				'bsdn_skipped' => (int) $result['skip'],
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $back );
		exit;
	}

	/**
	 * Contents of PHP's temporary upload file, or ''. The path that is read
	 * is rebuilt from the folder PHP stores uploads in plus the file's own
	 * name, and used only when it is the very file PHP received, so no
	 * other file on the server can ever be read through an import.
	 */
	private static function read_upload( $tmp_name ) {
		$real = realpath( $tmp_name );
		if ( ! $real || ! is_uploaded_file( $tmp_name ) ) {
			return '';
		}
		// PHP uses upload_tmp_dir when it is set and writable, else the system temp folder.
		$dirs = array_filter( array( (string) ini_get( 'upload_tmp_dir' ), sys_get_temp_dir() ) );
		foreach ( $dirs as $dir ) {
			$path = trailingslashit( $dir ) . basename( $tmp_name );
			if ( realpath( $path ) === $real ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the uploaded temp file.
				return (string) file_get_contents( $path );
			}
		}
		return '';
	}

	/**
	 * Add the notes of an export document for $user_id.
	 *
	 * @return array{error:string,added:int,skip:int}
	 */
	public static function import_document( $doc, $user_id ) {
		if ( ! is_array( $doc ) || 'blogsafe-dash-notes' !== ( $doc['plugin'] ?? '' ) || ! isset( $doc['notes'] ) || ! is_array( $doc['notes'] ) ) {
			return array(
				'error' => 'badfile',
				'added' => 0,
				'skip'  => 0,
			);
		}
		$manager = BlogSafe_Dash_Notes_Settings::is_manager( $user_id );
		$added   = 0;
		$skip    = 0;
		foreach ( array_slice( $doc['notes'], 0, self::MAX_NOTES ) as $n ) {
			if ( ! is_array( $n ) ) {
				++$skip;
				continue;
			}
			// "Every site" only for a super admin on the main site; else "Everyone".
			if ( 'network' === ( $n['audience'] ?? '' ) && ! BlogSafe_Dash_Notes_Network::can_write( $user_id ) ) {
				$n['audience'] = 'all';
			}
			$fields = BlogSafe_Dash_Notes_Store::clean_fields(
				array(
					'title'    => $n['title'] ?? '',
					'content'  => $n['content'] ?? '',
					'color'    => $n['color'] ?? '',
					'priority' => $n['priority'] ?? 0,
					'deadline' => $n['deadline'] ?? '',
					'audience' => $n['audience'] ?? 'private',
					'roles'    => $n['roles'] ?? array(),
					'recur'    => $n['recur'] ?? '',
				)
			);
			$items = BlogSafe_Dash_Notes_Items::clean( $n['items'] ?? array() );
			if ( is_wp_error( $fields ) && ! $items ) {
				++$skip;
				continue;
			}
			if ( is_wp_error( $fields ) ) {
				// Only a checklist: give it a title so it can be saved.
				$fields = BlogSafe_Dash_Notes_Store::clean_fields(
					array(
						'title'    => __( 'Checklist', 'blogsafe-dash-notes' ),
						'audience' => $n['audience'] ?? 'private',
						'color'    => $n['color'] ?? '',
						'recur'    => $n['recur'] ?? '',
					)
				);
				if ( is_wp_error( $fields ) ) {
					++$skip;
					continue;
				}
			}

			// Author: managers keep the original author when that login is here.
			$author_id = $user_id;
			if ( $manager && ! empty( $n['author'] ) ) {
				$u = get_user_by( 'login', (string) $n['author'] );
				if ( $u && ( ! is_multisite() || is_user_member_of_blog( $u->ID ) ) && BlogSafe_Dash_Notes_Settings::can_create( $u->ID ) ) {
					$author_id = (int) $u->ID;
				}
			}

			// Recipients, users of this site only: by user id, or by login
			// name for administrators. Logins are never looked up for anyone
			// else, or an import could be used to test which logins exist.
			$recipients = array();
			if ( 'users' === $fields['audience'] ) {
				$ids = array();
				foreach ( array_slice( (array) ( $n['recipients'] ?? array() ), 0, BlogSafe_Dash_Notes_Store::MAX_RECIPIENTS ) as $who ) {
					if ( is_int( $who ) ) {
						$ids[] = $who;
					} elseif ( $manager && is_string( $who ) ) {
						$u = get_user_by( 'login', $who );
						if ( $u ) {
							$ids[] = (int) $u->ID;
						}
					}
				}
				$recipients = BlogSafe_Dash_Notes_Store::clean_recipients( $ids, $author_id );
				if ( ! $recipients ) {
					$fields['audience'] = 'private'; // nobody to send it to here
				}
			}
			if ( 'roles' === $fields['audience'] && '' === $fields['roles'] ) {
				$fields['audience'] = 'private';
			}

			$screens = BlogSafe_Dash_Notes_Store::clean_screens( $n['screens'] ?? array() );
			if ( is_wp_error( $screens ) ) {
				$screens = array();
			}
			// The same limits as writing notes by hand (templates don't count).
			if ( empty( $n['is_template'] ) && BlogSafe_Dash_Notes_Store::quota_error( $author_id, $fields['audience'] ) ) {
				++$skip;
				continue;
			}
			$note_id = BlogSafe_Dash_Notes_Store::create( $author_id, $fields, $recipients, array(), $screens );
			if ( ! $note_id ) {
				++$skip;
				continue;
			}
			if ( $items ) {
				BlogSafe_Dash_Notes_Items::save( $note_id, $items );
			}
			if ( ! empty( $n['is_template'] ) ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
				$wpdb->update( BlogSafe_Dash_Notes_Schema::notes_table(), array( 'is_template' => 1, 'audience' => 'private', 'recur_next' => null ), array( 'id' => $note_id ) );
			}
			++$added;
		}
		if ( count( $doc['notes'] ) > self::MAX_NOTES ) {
			$skip += count( $doc['notes'] ) - self::MAX_NOTES;
		}
		return array(
			'error' => '',
			'added' => $added,
			'skip'  => $skip,
		);
	}
}
