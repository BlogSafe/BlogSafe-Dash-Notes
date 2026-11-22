<?php
/**
 * Dash Notes > All Notes list.
 *
 * @package BlogSafe_Dash_Notes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class BlogSafe_Dash_Notes_List_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/** User meta: the list view the user last picked (inbox | sent | all). */
	const VIEW_META = 'blogsafe_dash_notes_list_view';

	/** @var string inbox | sent | all */
	public $view = 'inbox';

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'note',
				'plural'   => 'notes',
				'ajax'     => false,
			)
		);
		$allowed = array_keys( self::view_labels() );
		$user_id = get_current_user_id();
		$saved   = (string) get_user_meta( $user_id, self::VIEW_META, true );

		// The view the user last picked becomes their default. Only a
		// display preference, so a plain link may set it.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view preference only.
		$asked = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
		$pos   = array_search( $asked, $allowed, true );
		if ( false !== $pos ) {
			// The allowlist's own copy, never the request's text.
			$this->view = $allowed[ $pos ];
			if ( $this->view !== $saved && current_user_can( 'read' ) ) {
				update_user_meta( $user_id, self::VIEW_META, $this->view );
			}
		} else {
			$this->view = in_array( $saved, $allowed, true ) ? $saved : 'inbox';
		}
	}

	/** Views this user may pick, in display order. */
	public static function view_labels() {
		$views = array(
			'inbox'   => __( 'Sent to me', 'blogsafe-dash-notes' ),
			'sent'    => __( 'Written by me', 'blogsafe-dash-notes' ),
			'urgent'  => __( 'Urgent', 'blogsafe-dash-notes' ),
			'overdue' => __( 'Overdue', 'blogsafe-dash-notes' ),
			'done'    => __( 'Done', 'blogsafe-dash-notes' ),
			'snoozed' => __( 'Snoozed', 'blogsafe-dash-notes' ),
		);
		if ( BlogSafe_Dash_Notes_Settings::can_create() ) {
			$views['templates'] = __( 'Templates', 'blogsafe-dash-notes' );
		}
		if ( BlogSafe_Dash_Notes_Settings::is_manager() ) {
			$views['all'] = __( 'Every note on this site', 'blogsafe-dash-notes' );
		}
		return $views;
	}

	protected function get_views() {
		$base  = admin_url( 'admin.php?page=' . BlogSafe_Dash_Notes_Admin::MENU_SLUG );
		$views = self::view_labels();
		$out   = array();
		foreach ( $views as $key => $label ) {
			$out[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( add_query_arg( 'view', $key, $base ) ),
				$this->view === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		return $out;
	}

	public function get_columns() {
		return array(
			'title'      => __( 'Note', 'blogsafe-dash-notes' ),
			'author'     => __( 'From', 'blogsafe-dash-notes' ),
			'audience'   => __( 'To', 'blogsafe-dash-notes' ),
			'screens'    => __( 'Shows on', 'blogsafe-dash-notes' ),
			'priority'   => __( 'Priority', 'blogsafe-dash-notes' ),
			'deadline'   => __( 'Deadline', 'blogsafe-dash-notes' ),
			'status'     => __( 'Status', 'blogsafe-dash-notes' ),
			'updated_at' => __( 'Updated', 'blogsafe-dash-notes' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'      => array( 'title', false ),
			'priority'   => array( 'priority', true ),
			'deadline'   => array( 'deadline', false ),
			'updated_at' => array( 'updated_at', true ),
		);
	}

	protected function get_primary_column_name() {
		return 'title';
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'updated_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc';
		// phpcs:enable
		list( $rows, $total ) = BlogSafe_Dash_Notes_Store::list_notes(
			get_current_user_id(),
			$this->view,
			$search,
			$orderby,
			$order,
			self::PER_PAGE,
			$this->get_pagenum()
		);
		BlogSafe_Dash_Notes_Store::prime_names( wp_list_pluck( $rows, 'author_id' ) );
		BlogSafe_Dash_Notes_Store::prime_screens( wp_list_pluck( $rows, 'id' ) );
		$this->items           = $rows;
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'title' );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	public function no_items() {
		if ( 'sent' === $this->view ) {
			esc_html_e( 'You have not written any notes yet. Add one from your Dashboard.', 'blogsafe-dash-notes' );
		} else {
			esc_html_e( 'No notes found.', 'blogsafe-dash-notes' );
		}
	}

	protected function column_title( $item ) {
		$user_id = get_current_user_id();
		$title   = '' !== $item->title ? $item->title : __( '(no title)', 'blogsafe-dash-notes' );
		$excerpt = wp_html_excerpt( $item->content, 160, '…' );

		$actions   = array();
		$is_author = (int) $item->author_id === $user_id;
		$on_board  = empty( $item->is_template ) && BlogSafe_Dash_Notes_Store::is_audience( $item, $user_id ); // templates are never on a board
		if ( $on_board && ! empty( $item->snoozed_until ) && $item->snoozed_until > current_time( 'mysql', true ) ) {
			$actions['unsnooze'] = sprintf( '<a href="%s">%s</a>', esc_url( BlogSafe_Dash_Notes_Admin::action_url( 'unsnooze', $item->id ) ), esc_html__( 'Stop snoozing', 'blogsafe-dash-notes' ) );
		}
		if ( $on_board && ! empty( $item->done_at ) ) {
			$actions['undone'] = sprintf( '<a href="%s">%s</a>', esc_url( BlogSafe_Dash_Notes_Admin::action_url( 'undone', $item->id ) ), esc_html__( 'Mark not done', 'blogsafe-dash-notes' ) );
		}
		if ( $on_board && ! $is_author ) {
			if ( ! empty( $item->dismissed ) ) {
				$actions['restore'] = sprintf( '<a href="%s">%s</a>', esc_url( BlogSafe_Dash_Notes_Admin::action_url( 'restore', $item->id ) ), esc_html__( 'Put back on my dashboard', 'blogsafe-dash-notes' ) );
			} else {
				$actions['dismiss'] = sprintf( '<a href="%s">%s</a>', esc_url( BlogSafe_Dash_Notes_Admin::action_url( 'dismiss', $item->id ) ), esc_html__( 'Remove from my dashboard', 'blogsafe-dash-notes' ) );
			}
		}
		if ( $on_board ) {
			$actions['view'] = sprintf( '<a href="%s">%s</a>', esc_url( BlogSafe_Dash_Notes_Store::open_url( $item->id ) ), esc_html__( 'Open', 'blogsafe-dash-notes' ) );
		}
		if ( BlogSafe_Dash_Notes_Store::can_edit( $item, $user_id ) ) {
			$actions['delete'] = sprintf(
				'<a href="%1$s" class="submitdelete" onclick="return window.confirm(%2$s);">%3$s</a>',
				esc_url( BlogSafe_Dash_Notes_Admin::action_url( 'delete', $item->id ) ),
				esc_attr( wp_json_encode( __( 'Delete this note for everyone who can see it?', 'blogsafe-dash-notes' ) ) ),
				esc_html__( 'Delete', 'blogsafe-dash-notes' )
			);
		}

		return sprintf(
			'<span class="bsdn-swatch" style="background:%1$s"></span><strong>%2$s</strong><p class="bsdn-excerpt">%3$s</p>%4$s',
			esc_attr( BlogSafe_Dash_Notes_Store::clean_color( $item->color ) ),
			esc_html( $title ),
			esc_html( $excerpt ),
			$this->row_actions( $actions )
		);
	}

	protected function column_author( $item ) {
		return esc_html( BlogSafe_Dash_Notes_Store::name( $item->author_id ) );
	}

	protected function column_audience( $item ) {
		if ( 'all' === $item->audience && ! empty( $item->network_id ) ) {
			return esc_html__( 'Every site (network note)', 'blogsafe-dash-notes' );
		}
		if ( 'all' === $item->audience ) {
			return esc_html__( 'Everyone', 'blogsafe-dash-notes' );
		}
		if ( 'network' === $item->audience ) {
			return esc_html__( 'Every site in the network', 'blogsafe-dash-notes' );
		}
		if ( 'private' === $item->audience ) {
			return esc_html__( 'Only the author', 'blogsafe-dash-notes' );
		}
		if ( 'roles' === $item->audience ) {
			$names = array();
			foreach ( array_filter( explode( ',', (string) $item->roles ) ) as $role ) {
				$all     = wp_roles()->get_names();
				$names[] = isset( $all[ $role ] ) ? translate_user_role( $all[ $role ] ) : $role;
			}
			/* translators: %s: role names, e.g. "Editor, Author". */
			return esc_html( sprintf( __( 'Roles: %s', 'blogsafe-dash-notes' ), implode( ', ', $names ) ) );
		}
		$names = wp_list_pluck( BlogSafe_Dash_Notes_Store::audience_status( $item ), 'name' );
		$shown = array_slice( $names, 0, 5 );
		$more  = count( $names ) - count( $shown );
		$text  = implode( ', ', $shown );
		if ( $more > 0 ) {
			/* translators: %d: number of further recipients not listed. */
			$text .= ' ' . sprintf( __( 'and %d more', 'blogsafe-dash-notes' ), $more );
		}
		return esc_html( $text );
	}

	protected function column_screens( $item ) {
		$out = array();
		foreach ( BlogSafe_Dash_Notes_Store::screens( $item->id ) as $s ) {
			if ( 'dashboard' === $s['key'] ) {
				$s['label'] = __( 'Dashboard', 'blogsafe-dash-notes' );
			}
			if ( BlogSafe_Dash_Notes_Store::ALL_SCREENS === $s['key'] ) {
				$out[] = esc_html__( 'Every admin page', 'blogsafe-dash-notes' );
			} elseif ( '' !== $s['url'] ) {
				$out[] = sprintf( '<a href="%1$s">%2$s</a>', esc_url( admin_url( $s['url'] ) . '#bsdn-note-' . (int) $item->id ), esc_html( $s['label'] ) );
			} else {
				$out[] = esc_html( $s['label'] );
			}
		}
		return $out ? implode( ', ', $out ) : '&mdash;';
	}

	protected function column_priority( $item ) {
		$labels = array(
			__( 'Normal', 'blogsafe-dash-notes' ),
			__( 'High', 'blogsafe-dash-notes' ),
			__( 'Urgent', 'blogsafe-dash-notes' ),
		);
		$level = min( 2, max( 0, (int) $item->priority ) );
		return sprintf( '<span class="bsdn-pri bsdn-pri-%1$d">%2$s</span>', $level, esc_html( $labels[ $level ] ) );
	}

	protected function column_deadline( $item ) {
		if ( ! $item->deadline ) {
			return '&mdash;';
		}
		// Short date: the column is narrow. Title shows the site's full format.
		$full    = date_i18n( get_option( 'date_format' ), strtotime( $item->deadline ) );
		$text    = '<span title="' . esc_attr( $full ) . '">' . esc_html( date_i18n( _x( 'M j, Y', 'short deadline date', 'blogsafe-dash-notes' ), strtotime( $item->deadline ) ) ) . '</span>';
		$overdue = $item->deadline < current_time( 'Y-m-d' );
		return $overdue ? '<span class="bsdn-overdue">' . $text . '</span>' : $text;
	}

	protected function column_status( $item ) {
		$user_id = get_current_user_id();
		$own     = $this->own_state( $item );
		$text    = $this->audience_summary( $item, $user_id );
		if ( null !== $text ) {
			return implode( ', ', array_merge( array( esc_html( $text ) ), $own ) );
		}
		$parts = array();
		$parts[] = null === $item->read_at ? '<strong>' . esc_html__( 'New', 'blogsafe-dash-notes' ) . '</strong>' : esc_html__( 'Read', 'blogsafe-dash-notes' );
		$parts   = array_merge( $parts, $own );
		if ( ! empty( $item->dismissed ) ) {
			$parts[] = esc_html__( 'removed from dashboard', 'blogsafe-dash-notes' );
		}
		return implode( ', ', $parts );
	}

	/** This user's own Done / snoozed state for a row, as escaped HTML parts. */
	private function own_state( $item ) {
		$parts = array();
		if ( ! empty( $item->done_at ) ) {
			$parts[] = '<strong class="bsdn-done-label">' . esc_html__( 'Done', 'blogsafe-dash-notes' ) . '</strong>';
		}
		if ( ! empty( $item->snoozed_until ) && $item->snoozed_until > current_time( 'mysql', true ) ) {
			/* translators: %s: date the snooze ends. */
			$parts[] = esc_html( sprintf( __( 'snoozed until %s', 'blogsafe-dash-notes' ), date_i18n( _x( 'M j', 'short snooze date', 'blogsafe-dash-notes' ), strtotime( $item->snoozed_until . ' UTC' ) + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ) );
		}
		return $parts;
	}

	/**
	 * For the author (or an admin looking at someone else's note): who has
	 * read / finished it. Null when the row should show the viewer's own
	 * read state instead.
	 */
	private function audience_summary( $item, $user_id ) {
		if ( (int) $item->author_id === $user_id || ( 'all' === $this->view && ! BlogSafe_Dash_Notes_Store::is_audience( $item, $user_id ) ) ) {
			$status = BlogSafe_Dash_Notes_Store::audience_status( $item );
			if ( 'private' === $item->audience ) {
				return __( 'Private', 'blogsafe-dash-notes' );
			}
			$read = count( array_filter( wp_list_pluck( $status, 'read' ) ) );
			$done = count( array_filter( wp_list_pluck( $status, 'done' ) ) );
			if ( 'all' === $item->audience || 'roles' === $item->audience ) {
				/* translators: %d: number of users who have read the note. */
				$text = sprintf( _n( 'Read by %d user', 'Read by %d users', $read, 'blogsafe-dash-notes' ), $read );
				if ( $done ) {
					/* translators: %d: number of users who marked the note done. */
					$text .= ', ' . sprintf( __( 'done by %d', 'blogsafe-dash-notes' ), $done );
				}
				return $text;
			}
			/* translators: 1: users who read the note, 2: number of recipients. */
			$text = sprintf( __( 'Read by %1$d of %2$d', 'blogsafe-dash-notes' ), $read, count( $status ) );
			if ( $done ) {
				/* translators: 1: users who marked it done, 2: number of recipients. */
				$text .= ', ' . sprintf( __( 'done by %1$d of %2$d', 'blogsafe-dash-notes' ), $done, count( $status ) );
			}
			return $text;
		}
		return null;
	}

	protected function column_updated_at( $item ) {
		$time = strtotime( $item->updated_at . ' UTC' );
		/* translators: %s: human-readable time difference, e.g. "2 hours". */
		return esc_html( sprintf( __( '%s ago', 'blogsafe-dash-notes' ), human_time_diff( $time ) ) );
	}

	protected function column_default( $item, $column_name ) {
		return '';
	}
}
