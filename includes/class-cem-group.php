<?php
/**
 * Event Series (Groups) — CPT, meta boxes, event linking, helpers.
 *
 * Registrations for groups reuse the existing cem_registrations table by
 * storing the group post ID in the event_id column.  All existing email,
 * cancellation, and admin logic therefore works for free.
 *
 * @package ChurchEventManager
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Group {

	public function init() {
		add_action( 'init', [ $this, 'register_cpt' ], 0 );

		// Admin columns
		add_filter( 'manage_cem_group_posts_columns',       [ $this, 'group_columns' ] );
		add_action( 'manage_cem_group_posts_custom_column', [ $this, 'group_column_data' ], 10, 2 );

		// Meta boxes
		add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_cem_group', [ $this, 'save_meta' ], 10, 2 );

		// Event → Group link (sidebar meta box on cem_event edit screen)
		add_action( 'add_meta_boxes_cem_event', [ $this, 'add_event_group_meta_box' ] );
		add_action( 'save_post_cem_event',       [ $this, 'save_event_group' ], 10, 2 );

		// Template override for single cem_group
		add_filter( 'template_include', [ $this, 'single_group_template' ], 99 );
	}

	// ── CPT Registration ─────────────────────────────────────────────────────

	public function register_cpt() {
		$labels = [
			'name'               => _x( 'Event Series',             'post type general name', 'church-event-manager' ),
			'singular_name'      => _x( 'Event Series',             'post type singular name', 'church-event-manager' ),
			'menu_name'          => _x( 'Event Series',             'admin menu',             'church-event-manager' ),
			'add_new'            => __( 'Add New',                  'church-event-manager' ),
			'add_new_item'       => __( 'Add New Event Series',     'church-event-manager' ),
			'edit_item'          => __( 'Edit Event Series',        'church-event-manager' ),
			'view_item'          => __( 'View Event Series',        'church-event-manager' ),
			'all_items'          => __( 'All Event Series',         'church-event-manager' ),
			'search_items'       => __( 'Search Event Series',      'church-event-manager' ),
			'not_found'          => __( 'No event series found.',   'church-event-manager' ),
			'not_found_in_trash' => __( 'No event series in trash.','church-event-manager' ),
		];

		register_post_type( 'cem_group', [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'event-series', 'with_front' => false ],
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions' ],
		] );
	}

	// ── Admin List Columns ───────────────────────────────────────────────────

	public function group_columns( $cols ) {
		return [
			'cb'        => $cols['cb'],
			'title'     => __( 'Series Title', 'church-event-manager' ),
			'dates'     => __( 'Dates',         'church-event-manager' ),
			'location'  => __( 'Location',      'church-event-manager' ),
			'events'    => __( 'Events',        'church-event-manager' ),
			'signups'   => __( 'Sign-ups',      'church-event-manager' ),
			'status'    => __( 'Status',        'church-event-manager' ),
		];
	}

	public function group_column_data( $col, $post_id ) {
		switch ( $col ) {
			case 'dates':
				$start = get_post_meta( $post_id, '_cem_group_start_date', true );
				$end   = get_post_meta( $post_id, '_cem_group_end_date',   true );
				if ( $start ) {
					echo esc_html( CEM_Helpers::format_date( $start ) );
					if ( $end ) {
						echo ' – ' . esc_html( CEM_Helpers::format_date( $end ) );
					}
				} else {
					echo '—';
				}
				break;

			case 'location':
				$loc = get_post_meta( $post_id, '_cem_group_location', true );
				echo $loc ? esc_html( $loc ) : '—';
				break;

			case 'events':
				$events = self::get_linked_events( $post_id );
				echo esc_html( count( $events ) );
				break;

			case 'signups':
				echo esc_html( self::get_signup_count( $post_id ) );
				break;

			case 'status':
				$status = get_post_meta( $post_id, '_cem_group_status', true ) ?: 'upcoming';
				$label  = ucfirst( $status );
				$colors = [ 'upcoming' => '#1d4ed8', 'ongoing' => '#166534', 'completed' => '#475569', 'cancelled' => '#991b1b' ];
				$color  = $colors[ $status ] ?? '#888888';
				echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $label ) . '</span>';
				break;
		}
	}

	// ── Group Meta Box ───────────────────────────────────────────────────────

	public function add_meta_boxes() {
		add_meta_box(
			'cem_group_details',
			__( 'Series Details', 'church-event-manager' ),
			[ $this, 'mb_group_details' ],
			'cem_group',
			'normal',
			'high'
		);
	}

	public function mb_group_details( $post ) {
		wp_nonce_field( 'cem_group_meta', 'cem_group_nonce' );
		$start      = get_post_meta( $post->ID, '_cem_group_start_date',          true );
		$end        = get_post_meta( $post->ID, '_cem_group_end_date',            true );
		$location   = get_post_meta( $post->ID, '_cem_group_location',            true );
		$address    = get_post_meta( $post->ID, '_cem_group_address',             true );
		$capacity   = get_post_meta( $post->ID, '_cem_group_capacity',            true );
		$status     = get_post_meta( $post->ID, '_cem_group_status',              true ) ?: 'upcoming';
		$reg_status = get_post_meta( $post->ID, '_cem_group_registration_status', true ) ?: 'open';
		?>
		<style>
		.cem-group-meta-table td, .cem-group-meta-table th { padding: 8px 10px; }
		.cem-group-meta-table input[type="date"] { max-width: 180px; }
		</style>
		<table class="form-table cem-group-meta-table">
			<tr>
				<th><label for="_cem_group_start_date"><?php esc_html_e( 'Start Date', 'church-event-manager' ); ?></label></th>
				<td><input type="date" id="_cem_group_start_date" name="_cem_group_start_date" value="<?php echo esc_attr( $start ); ?>"></td>
				<th><label for="_cem_group_end_date"><?php esc_html_e( 'End Date', 'church-event-manager' ); ?></label></th>
				<td><input type="date" id="_cem_group_end_date" name="_cem_group_end_date" value="<?php echo esc_attr( $end ); ?>"></td>
			</tr>
			<tr>
				<th><label for="_cem_group_location"><?php esc_html_e( 'Location Name', 'church-event-manager' ); ?></label></th>
				<td><input type="text" id="_cem_group_location" name="_cem_group_location" value="<?php echo esc_attr( $location ); ?>" class="large-text"></td>
				<th><label for="_cem_group_capacity"><?php esc_html_e( 'Capacity', 'church-event-manager' ); ?></label></th>
				<td>
					<input type="number" id="_cem_group_capacity" name="_cem_group_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="0" class="small-text">
					<p class="description"><?php esc_html_e( '0 = unlimited', 'church-event-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="_cem_group_address"><?php esc_html_e( 'Address', 'church-event-manager' ); ?></label></th>
				<td colspan="3"><textarea id="_cem_group_address" name="_cem_group_address" rows="2" class="large-text"><?php echo esc_textarea( $address ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="_cem_group_status"><?php esc_html_e( 'Series Status', 'church-event-manager' ); ?></label></th>
				<td>
					<select id="_cem_group_status" name="_cem_group_status">
						<option value="upcoming"  <?php selected( $status, 'upcoming' ); ?>><?php esc_html_e( 'Upcoming', 'church-event-manager' ); ?></option>
						<option value="ongoing"   <?php selected( $status, 'ongoing' ); ?>><?php esc_html_e( 'Ongoing',  'church-event-manager' ); ?></option>
						<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed','church-event-manager' ); ?></option>
						<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled','church-event-manager' ); ?></option>
					</select>
				</td>
				<th><label for="_cem_group_registration_status"><?php esc_html_e( 'Sign-ups', 'church-event-manager' ); ?></label></th>
				<td>
					<select id="_cem_group_registration_status" name="_cem_group_registration_status">
						<option value="open"  <?php selected( $reg_status, 'open' ); ?>><?php esc_html_e( 'Open',  'church-event-manager' ); ?></option>
						<option value="closed"<?php selected( $reg_status, 'closed' ); ?>><?php esc_html_e( 'Closed','church-event-manager' ); ?></option>
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['cem_group_nonce'] ) || ! wp_verify_nonce( $_POST['cem_group_nonce'], 'cem_group_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$text_fields = [ '_cem_group_start_date', '_cem_group_end_date', '_cem_group_location' ];
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) );
			}
		}
		if ( isset( $_POST['_cem_group_address'] ) ) {
			update_post_meta( $post_id, '_cem_group_address', sanitize_textarea_field( $_POST['_cem_group_address'] ) );
		}
		if ( isset( $_POST['_cem_group_capacity'] ) ) {
			update_post_meta( $post_id, '_cem_group_capacity', absint( $_POST['_cem_group_capacity'] ) );
		}
		$allowed_statuses   = [ 'upcoming', 'ongoing', 'completed', 'cancelled' ];
		$allowed_reg_status = [ 'open', 'closed' ];
		if ( isset( $_POST['_cem_group_status'] ) && in_array( $_POST['_cem_group_status'], $allowed_statuses, true ) ) {
			update_post_meta( $post_id, '_cem_group_status', $_POST['_cem_group_status'] );
		}
		if ( isset( $_POST['_cem_group_registration_status'] ) && in_array( $_POST['_cem_group_registration_status'], $allowed_reg_status, true ) ) {
			update_post_meta( $post_id, '_cem_group_registration_status', $_POST['_cem_group_registration_status'] );
		}
	}

	// ── Event → Group Link (sidebar meta box on cem_event) ───────────────────

	public function add_event_group_meta_box() {
		add_meta_box(
			'cem_event_group',
			__( 'Event Series', 'church-event-manager' ),
			[ $this, 'mb_event_group' ],
			'cem_event',
			'side',
			'default'
		);
	}

	public function mb_event_group( $post ) {
		wp_nonce_field( 'cem_event_group_meta', 'cem_event_group_nonce' );
		$current_group = (int) get_post_meta( $post->ID, '_cem_event_group_id', true );

		$groups = get_posts( [
			'post_type'      => 'cem_group',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		?>
		<p>
			<label for="_cem_event_group_id"><?php esc_html_e( 'Assign to Series:', 'church-event-manager' ); ?></label>
			<br>
			<select id="_cem_event_group_id" name="_cem_event_group_id" style="width:100%;margin-top:4px">
				<option value=""><?php esc_html_e( '— None —', 'church-event-manager' ); ?></option>
				<?php foreach ( $groups as $group ) : ?>
				<option value="<?php echo esc_attr( $group->ID ); ?>" <?php selected( $current_group, $group->ID ); ?>>
					<?php echo esc_html( $group->post_title ); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</p>
		<?php
	}

	public function save_event_group( $post_id, $post ) {
		if ( ! isset( $_POST['cem_event_group_nonce'] ) || ! wp_verify_nonce( $_POST['cem_event_group_nonce'], 'cem_event_group_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$group_id = isset( $_POST['_cem_event_group_id'] ) ? absint( $_POST['_cem_event_group_id'] ) : 0;
		if ( $group_id ) {
			update_post_meta( $post_id, '_cem_event_group_id', $group_id );
		} else {
			delete_post_meta( $post_id, '_cem_event_group_id' );
		}
	}

	// ── Template override ─────────────────────────────────────────────────────

	public function single_group_template( $template ) {
		if ( ! is_singular( 'cem_group' ) ) return $template;

		// Only override generic fallback templates (single.php, index.php, etc.)
		// If a theme builder (Elementor, CMSMasters, etc.) has a custom canvas, leave it alone.
		$theme_template = basename( $template );
		$generic        = [ 'single.php', 'index.php', 'singular.php' ];
		if ( ! in_array( $theme_template, $generic, true ) ) {
			return $template;
		}

		$plugin_template = CEM_PLUGIN_DIR . 'templates/single-group.php';
		if ( file_exists( $plugin_template ) ) {
			return $plugin_template;
		}
		return $template;
	}

	// ── Static Helpers ────────────────────────────────────────────────────────

	/**
	 * Get all published events linked to a group.
	 */
	public static function get_linked_events( $group_id ) {
		return get_posts( [
			'post_type'      => 'cem_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cem_start_datetime',
			'order'          => 'ASC',
			'meta_query'     => [ [
				'key'   => '_cem_event_group_id',
				'value' => $group_id,
			] ],
		] );
	}

	/**
	 * Count active sign-ups for a group (uses event_id = group_id convention).
	 */
	public static function get_signup_count( $group_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(num_attendees),0) FROM {$wpdb->prefix}cem_registrations
			 WHERE event_id = %d AND status NOT IN ('cancelled','waitlisted')",
			$group_id
		) );
	}

	public static function is_at_capacity( $group_id ) {
		$capacity = (int) get_post_meta( $group_id, '_cem_group_capacity', true );
		if ( $capacity <= 0 ) return false;
		return self::get_signup_count( $group_id ) >= $capacity;
	}
}
