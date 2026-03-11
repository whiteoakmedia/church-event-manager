<?php
/**
 * Registers the Church Event CPT, taxonomies, and event-meta columns.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Post_Types {

	public function register() {
		$this->register_event_cpt();
		$this->register_event_category();
		$this->register_event_tag();
		$this->register_ministry_taxonomy();
	}

	// ── Custom Post Type: cem_event ───────────────────────────────────────────

	private function register_event_cpt() {
		$labels = [
			'name'               => _x( 'Events',             'post type general name', 'church-event-manager' ),
			'singular_name'      => _x( 'Event',              'post type singular name', 'church-event-manager' ),
			'menu_name'          => _x( 'Events',             'admin menu',             'church-event-manager' ),
			'add_new'            => __( 'Add New',            'church-event-manager' ),
			'add_new_item'       => __( 'Add New Event',      'church-event-manager' ),
			'edit_item'          => __( 'Edit Event',         'church-event-manager' ),
			'view_item'          => __( 'View Event',         'church-event-manager' ),
			'all_items'          => __( 'All Events',         'church-event-manager' ),
			'search_items'       => __( 'Search Events',      'church-event-manager' ),
			'not_found'          => __( 'No events found.',   'church-event-manager' ),
			'not_found_in_trash' => __( 'No events in trash.','church-event-manager' ),
		];

		register_post_type( 'cem_event', [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false, // We'll use our own top-level menu
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'event', 'with_front' => false ],
			'capability_type'    => 'post',
			'has_archive'        => 'events',
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-calendar-alt',
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions' ],
		] );

		// Admin list columns
		add_filter( 'manage_cem_event_posts_columns',        [ $this, 'event_columns' ] );
		add_action( 'manage_cem_event_posts_custom_column',  [ $this, 'event_column_data' ], 10, 2 );
		add_filter( 'manage_edit-cem_event_sortable_columns',[ $this, 'event_sortable_columns' ] );
		add_action( 'pre_get_posts',                         [ $this, 'sort_events_by_date' ] );
	}

	// ── Taxonomy: Event Category ──────────────────────────────────────────────

	private function register_event_category() {
		$labels = [
			'name'              => _x( 'Event Categories', 'taxonomy general name', 'church-event-manager' ),
			'singular_name'     => _x( 'Event Category',  'taxonomy singular name', 'church-event-manager' ),
			'search_items'      => __( 'Search Categories','church-event-manager' ),
			'all_items'         => __( 'All Categories',   'church-event-manager' ),
			'parent_item'       => __( 'Parent Category',  'church-event-manager' ),
			'parent_item_colon' => __( 'Parent Category:', 'church-event-manager' ),
			'edit_item'         => __( 'Edit Category',    'church-event-manager' ),
			'update_item'       => __( 'Update Category',  'church-event-manager' ),
			'add_new_item'      => __( 'Add New Category', 'church-event-manager' ),
			'menu_name'         => __( 'Categories',       'church-event-manager' ),
		];

		register_taxonomy( 'cem_event_category', 'cem_event', [
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => 'event-category' ],
		] );

		// Seed default categories on first run
		if ( ! get_option( 'cem_default_categories_seeded' ) ) {
			$defaults = [
				'Service'          => 'Weekly worship services and special services',
				'Youth'            => 'Youth group events and activities',
				"Women's Ministry" => "Events for the women's ministry",
				"Men's Ministry"   => "Events for the men's ministry",
				'Family'           => 'Family-friendly events',
				'Community'        => 'Community outreach events',
				'Bible Study'      => 'Bible study groups and classes',
				'Fundraiser'       => 'Fundraising events and campaigns',
				'Special Event'    => 'Special events and conferences',
				'Volunteer'        => 'Volunteer and service opportunities',
			];
			foreach ( $defaults as $name => $description ) {
				if ( ! term_exists( $name, 'cem_event_category' ) ) {
					wp_insert_term( $name, 'cem_event_category', [ 'description' => $description ] );
				}
			}
			update_option( 'cem_default_categories_seeded', true );
		}
	}

	// ── Taxonomy: Event Tag ───────────────────────────────────────────────────

	private function register_event_tag() {
		register_taxonomy( 'cem_event_tag', 'cem_event', [
			'label'             => __( 'Event Tags', 'church-event-manager' ),
			'hierarchical'      => false,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => false,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => 'event-tag' ],
		] );
	}

	// ── Taxonomy: Ministry ────────────────────────────────────────────────────

	private function register_ministry_taxonomy() {
		register_taxonomy( 'cem_ministry', 'cem_event', [
			'label'             => __( 'Ministries', 'church-event-manager' ),
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'query_var'         => true,
			'rewrite'           => [ 'slug' => 'ministry' ],
		] );
	}

	// ── Admin List Columns ────────────────────────────────────────────────────

	public function event_columns( $columns ) {
		// Remove default date column and reorder
		unset( $columns['date'] );
		return array_merge( $columns, [
			'cem_start_date'   => __( 'Date & Time',   'church-event-manager' ),
			'cem_location'     => __( 'Location',      'church-event-manager' ),
			'cem_capacity'     => __( 'Capacity',      'church-event-manager' ),
			'cem_registrations'=> __( 'Registrations', 'church-event-manager' ),
			'cem_status'       => __( 'Status',        'church-event-manager' ),
		] );
	}

	public function event_column_data( $column, $post_id ) {
		switch ( $column ) {
			case 'cem_start_date':
				$start = get_post_meta( $post_id, '_cem_start_datetime', true );
				$end   = get_post_meta( $post_id, '_cem_end_datetime', true );
				if ( $start ) {
					echo '<strong>' . esc_html( CEM_Helpers::format_date( $start ) ) . '</strong><br>';
					echo esc_html( CEM_Helpers::format_time( $start ) );
					if ( $end ) echo ' – ' . esc_html( CEM_Helpers::format_time( $end ) );
				} else {
					echo '—';
				}
				break;

			case 'cem_location':
				$loc = get_post_meta( $post_id, '_cem_location', true );
				echo $loc ? esc_html( $loc ) : '—';
				break;

			case 'cem_capacity':
				$cap  = (int) get_post_meta( $post_id, '_cem_capacity', true );
				$taken = CEM_Helpers::get_registration_count( $post_id );
				if ( $cap > 0 ) {
					$pct = round( ( $taken / $cap ) * 100 );
					$cls = $pct >= 100 ? 'cem-cap-full' : ( $pct >= 80 ? 'cem-cap-warning' : 'cem-cap-ok' );
					echo "<span class='$cls'>" . esc_html( "$taken / $cap" ) . "</span>";
				} else {
					echo esc_html( $taken . ' / ∞' );
				}
				break;

			case 'cem_registrations':
				$count = CEM_Helpers::get_registration_count( $post_id, [ 'pending', 'confirmed', 'checked_in', 'waitlisted' ] );
				$url   = admin_url( 'admin.php?page=cem-registrations&event_id=' . $post_id );
				echo '<a href="' . esc_url( $url ) . '">' . esc_html( $count ) . ' ' . __( 'registrations', 'church-event-manager' ) . '</a>';
				break;

			case 'cem_status':
				$start  = get_post_meta( $post_id, '_cem_start_datetime', true );
				$status = get_post_meta( $post_id, '_cem_event_status', true );
				if ( $status === 'cancelled' ) {
					echo '<span class="cem-badge cem-badge--red">' . esc_html__( 'Cancelled', 'church-event-manager' ) . '</span>';
				} elseif ( $start && strtotime( $start ) < time() ) {
					echo '<span class="cem-badge cem-badge--grey">' . esc_html__( 'Past', 'church-event-manager' ) . '</span>';
				} else {
					echo '<span class="cem-badge cem-badge--green">' . esc_html__( 'Upcoming', 'church-event-manager' ) . '</span>';
				}
				break;
		}
	}

	public function event_sortable_columns( $columns ) {
		$columns['cem_start_date'] = 'cem_start_date';
		return $columns;
	}

	public function sort_events_by_date( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) return;
		if ( $query->get( 'post_type' ) !== 'cem_event' ) return;
		if ( $query->get( 'orderby' ) === 'cem_start_date' ) {
			$query->set( 'meta_key', '_cem_start_datetime' );
			$query->set( 'orderby', 'meta_value' );
		}
	}
}
