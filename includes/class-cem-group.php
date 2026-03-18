<?php
/**
 * Small Groups — CPT, meta boxes, and helpers.
 *
 * Groups represent recurring small groups people can join (Bible study,
 * prayer groups, men's/women's groups, etc.).  Registrations reuse the
 * existing cem_registrations table by storing the group post ID in the
 * event_id column — no schema changes required.
 *
 * Meta keys:
 *   _cem_group_type          – group category (Bible Study, Prayer, etc.)
 *   _cem_group_day           – meeting day (Monday … Sunday / Various)
 *   _cem_group_time          – meeting time (e.g. 7:00 PM)
 *   _cem_group_frequency     – Weekly / Bi-weekly / Monthly / Custom
 *   _cem_group_leader        – leader display name
 *   _cem_group_leader_email  – leader email (admin-only)
 *   _cem_group_location      – location name
 *   _cem_group_address       – full address
 *   _cem_group_capacity      – max members (0 = unlimited)
 *   _cem_group_status        – open | closed | full | inactive
 *
 * @package ChurchEventManager
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Group {

	/** Allowed group types shown in the admin select. */
	public static function group_types() {
		return [
			''            => __( '— Select Type —', 'church-event-manager' ),
			'bible-study' => __( 'Bible Study',     'church-event-manager' ),
			'prayer'      => __( 'Prayer',          'church-event-manager' ),
			'mens'        => __( "Men's",           'church-event-manager' ),
			'womens'      => __( "Women's",         'church-event-manager' ),
			'couples'     => __( 'Couples',         'church-event-manager' ),
			'young-adults'=> __( 'Young Adults',    'church-event-manager' ),
			'youth'       => __( 'Youth',           'church-event-manager' ),
			'seniors'     => __( 'Seniors',         'church-event-manager' ),
			'outreach'    => __( 'Outreach',        'church-event-manager' ),
			'recovery'    => __( 'Recovery',        'church-event-manager' ),
			'other'       => __( 'Other',           'church-event-manager' ),
		];
	}

	public function init() {
		add_action( 'init', [ $this, 'register_cpt' ], 0 );
		add_action( 'init', [ $this, 'register_taxonomies' ], 0 );

		// Admin columns
		add_filter( 'manage_cem_group_posts_columns',       [ $this, 'group_columns' ] );
		add_action( 'manage_cem_group_posts_custom_column', [ $this, 'group_column_data' ], 10, 2 );

		// Meta boxes
		add_action( 'add_meta_boxes',      [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_cem_group', [ $this, 'save_meta' ], 10, 2 );

		// Template override for single cem_group
		add_filter( 'template_include', [ $this, 'single_group_template' ], 99 );
	}

	// ── Taxonomies ───────────────────────────────────────────────────────────

	public function register_taxonomies() {
		// Group Category
		register_taxonomy( 'cem_group_category', 'cem_group', [
			'labels'            => [
				'name'          => _x( 'Group Categories', 'taxonomy general name', 'church-event-manager' ),
				'singular_name' => _x( 'Group Category',  'taxonomy singular name', 'church-event-manager' ),
				'search_items'  => __( 'Search Categories', 'church-event-manager' ),
				'all_items'     => __( 'All Categories',    'church-event-manager' ),
				'edit_item'     => __( 'Edit Category',     'church-event-manager' ),
				'add_new_item'  => __( 'Add New Category',  'church-event-manager' ),
				'menu_name'     => __( 'Categories',        'church-event-manager' ),
			],
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'group-category' ],
		] );

		// Group Tag
		register_taxonomy( 'cem_group_tag', 'cem_group', [
			'labels'            => [
				'name'          => _x( 'Group Tags', 'taxonomy general name', 'church-event-manager' ),
				'singular_name' => _x( 'Group Tag',  'taxonomy singular name', 'church-event-manager' ),
				'menu_name'     => __( 'Tags', 'church-event-manager' ),
			],
			'hierarchical'      => false,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => false,
			'show_in_rest'      => true,
			'rewrite'           => [ 'slug' => 'group-tag' ],
		] );
	}

	// ── CPT Registration ─────────────────────────────────────────────────────

	public function register_cpt() {
		$labels = [
			'name'               => _x( 'Groups',          'post type general name', 'church-event-manager' ),
			'singular_name'      => _x( 'Group',           'post type singular name', 'church-event-manager' ),
			'menu_name'          => _x( 'Groups',          'admin menu',              'church-event-manager' ),
			'add_new'            => __( 'Add New',          'church-event-manager' ),
			'add_new_item'       => __( 'Add New Group',    'church-event-manager' ),
			'edit_item'          => __( 'Edit Group',       'church-event-manager' ),
			'view_item'          => __( 'View Group',       'church-event-manager' ),
			'all_items'          => __( 'All Groups',       'church-event-manager' ),
			'search_items'       => __( 'Search Groups',    'church-event-manager' ),
			'not_found'          => __( 'No groups found.', 'church-event-manager' ),
			'not_found_in_trash' => __( 'No groups in trash.', 'church-event-manager' ),
		];

		register_post_type( 'cem_group', [
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'show_ui'            => true,
			'show_in_menu'       => false,
			'show_in_rest'       => true,
			'query_var'          => true,
			'rewrite'            => [ 'slug' => 'groups', 'with_front' => false ],
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'revisions' ],
		] );
	}

	// ── Admin List Columns ───────────────────────────────────────────────────

	public function group_columns( $cols ) {
		return [
			'cb'       => $cols['cb'],
			'title'    => __( 'Group',    'church-event-manager' ),
			'type'     => __( 'Type',     'church-event-manager' ),
			'schedule' => __( 'Schedule', 'church-event-manager' ),
			'leader'   => __( 'Leader',   'church-event-manager' ),
			'members'  => __( 'Members',  'church-event-manager' ),
			'status'   => __( 'Status',   'church-event-manager' ),
		];
	}

	public function group_column_data( $col, $post_id ) {
		switch ( $col ) {
			case 'type':
				$type   = get_post_meta( $post_id, '_cem_group_type', true );
				$types  = self::group_types();
				echo esc_html( $types[ $type ] ?? ucwords( str_replace( '-', ' ', $type ) ) ?: '—' );
				break;

			case 'schedule':
				$day  = get_post_meta( $post_id, '_cem_group_day',  true );
				$time = get_post_meta( $post_id, '_cem_group_time', true );
				$freq = get_post_meta( $post_id, '_cem_group_frequency', true );
				$parts = array_filter( [ $freq, $day, $time ] );
				echo $parts ? esc_html( implode( ' · ', $parts ) ) : '—';
				break;

			case 'leader':
				$leader = get_post_meta( $post_id, '_cem_group_leader', true );
				echo $leader ? esc_html( $leader ) : '—';
				break;

			case 'members':
				$count    = self::get_signup_count( $post_id );
				$capacity = (int) get_post_meta( $post_id, '_cem_group_capacity', true );
				echo $capacity > 0
					? esc_html( "$count / $capacity" )
					: esc_html( $count );
				break;

			case 'status':
				$status = get_post_meta( $post_id, '_cem_group_status', true ) ?: 'open';
				$colors = [
					'open'     => '#166534',
					'closed'   => '#6b7280',
					'full'     => '#b45309',
					'inactive' => '#991b1b',
				];
				$labels = [
					'open'     => __( 'Open',     'church-event-manager' ),
					'closed'   => __( 'Closed',   'church-event-manager' ),
					'full'     => __( 'Full',     'church-event-manager' ),
					'inactive' => __( 'Inactive', 'church-event-manager' ),
				];
				$color = $colors[ $status ] ?? '#888888';
				$label = $labels[ $status ] ?? ucfirst( $status );
				echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( $label ) . '</span>';
				break;
		}
	}

	// ── Group Meta Box ───────────────────────────────────────────────────────

	public function add_meta_boxes() {
		add_meta_box(
			'cem_group_details',
			__( 'Group Details', 'church-event-manager' ),
			[ $this, 'mb_group_details' ],
			'cem_group',
			'normal',
			'high'
		);
		add_meta_box(
			'cem_group_events',
			__( 'Group Events', 'church-event-manager' ),
			[ $this, 'mb_linked_events' ],
			'cem_group',
			'normal',
			'default'
		);
		add_meta_box(
			'cem_group_members',
			__( 'Group Members', 'church-event-manager' ),
			[ $this, 'mb_group_members' ],
			'cem_group',
			'normal',
			'default'
		);
	}

	public function mb_group_details( $post ) {
		wp_nonce_field( 'cem_group_meta', 'cem_group_nonce' );

		$type         = get_post_meta( $post->ID, '_cem_group_type',         true );
		$day          = get_post_meta( $post->ID, '_cem_group_day',          true );
		$time         = get_post_meta( $post->ID, '_cem_group_time',         true );
		$frequency    = get_post_meta( $post->ID, '_cem_group_frequency',    true );
		$leader       = get_post_meta( $post->ID, '_cem_group_leader',       true );
		$leader_email = get_post_meta( $post->ID, '_cem_group_leader_email', true );
		$location     = get_post_meta( $post->ID, '_cem_group_location',     true );
		$address      = get_post_meta( $post->ID, '_cem_group_address',      true );
		$capacity     = get_post_meta( $post->ID, '_cem_group_capacity',     true );
		$status       = get_post_meta( $post->ID, '_cem_group_status',       true ) ?: 'open';
		$start_date   = get_post_meta( $post->ID, '_cem_group_start_date',   true );
		$end_date     = get_post_meta( $post->ID, '_cem_group_end_date',     true );
		$childcare    = get_post_meta( $post->ID, '_cem_group_childcare',    true );
		$online       = get_post_meta( $post->ID, '_cem_group_online',       true );
		$meeting_url  = get_post_meta( $post->ID, '_cem_group_meeting_url',  true );
		$contact_phone = get_post_meta( $post->ID, '_cem_group_contact_phone', true );

		$days = [ '', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'Various' ];
		$freqs = [ '' => '', 'weekly' => 'Weekly', 'biweekly' => 'Bi-weekly', 'monthly' => 'Monthly', 'custom' => 'Custom' ];
		?>
		<style>
		.cem-group-meta-table td, .cem-group-meta-table th { padding: 8px 10px; vertical-align: middle; }
		.cem-group-meta-table input[type="time"] { max-width: 140px; }
		</style>
		<table class="form-table cem-group-meta-table">
			<tr>
				<th><label for="_cem_group_type"><?php esc_html_e( 'Group Type', 'church-event-manager' ); ?></label></th>
				<td>
					<select id="_cem_group_type" name="_cem_group_type">
						<?php foreach ( self::group_types() as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<th><label for="_cem_group_status"><?php esc_html_e( 'Status', 'church-event-manager' ); ?></label></th>
				<td>
					<select id="_cem_group_status" name="_cem_group_status">
						<option value="open"     <?php selected( $status, 'open' ); ?>><?php esc_html_e( 'Open — accepting new members',  'church-event-manager' ); ?></option>
						<option value="closed"   <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Closed — not taking new members', 'church-event-manager' ); ?></option>
						<option value="full"     <?php selected( $status, 'full' ); ?>><?php esc_html_e( 'Full',     'church-event-manager' ); ?></option>
						<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'church-event-manager' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="_cem_group_frequency"><?php esc_html_e( 'Frequency', 'church-event-manager' ); ?></label></th>
				<td>
					<select id="_cem_group_frequency" name="_cem_group_frequency">
						<?php foreach ( $freqs as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $frequency, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
				<th><label for="_cem_group_day"><?php esc_html_e( 'Meeting Day', 'church-event-manager' ); ?></label></th>
				<td>
					<select id="_cem_group_day" name="_cem_group_day">
						<?php foreach ( $days as $d ) : ?>
						<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $day, $d ); ?>><?php echo $d ? esc_html( $d ) : esc_html__( '— Day —', 'church-event-manager' ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="_cem_group_time"><?php esc_html_e( 'Meeting Time', 'church-event-manager' ); ?></label></th>
				<td><input type="time" id="_cem_group_time" name="_cem_group_time" value="<?php echo esc_attr( $time ); ?>"></td>
				<th><label for="_cem_group_capacity"><?php esc_html_e( 'Capacity', 'church-event-manager' ); ?></label></th>
				<td>
					<input type="number" id="_cem_group_capacity" name="_cem_group_capacity" value="<?php echo esc_attr( $capacity ); ?>" min="0" class="small-text">
					<p class="description"><?php esc_html_e( '0 = unlimited', 'church-event-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="_cem_group_leader"><?php esc_html_e( 'Leader Name', 'church-event-manager' ); ?></label></th>
				<td><input type="text" id="_cem_group_leader" name="_cem_group_leader" value="<?php echo esc_attr( $leader ); ?>" class="regular-text"></td>
				<th><label for="_cem_group_leader_email"><?php esc_html_e( 'Leader Email', 'church-event-manager' ); ?></label></th>
				<td><input type="email" id="_cem_group_leader_email" name="_cem_group_leader_email" value="<?php echo esc_attr( $leader_email ); ?>" class="regular-text">
					<p class="description"><?php esc_html_e( 'Admin-only. Not shown publicly.', 'church-event-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="_cem_group_location"><?php esc_html_e( 'Location', 'church-event-manager' ); ?></label></th>
				<td><input type="text" id="_cem_group_location" name="_cem_group_location" value="<?php echo esc_attr( $location ); ?>" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Room 201, Pastor\'s Home, Coffee Shop', 'church-event-manager' ); ?>"></td>
				<th><label for="_cem_group_address"><?php esc_html_e( 'Address', 'church-event-manager' ); ?></label></th>
				<td><input type="text" id="_cem_group_address" name="_cem_group_address" value="<?php echo esc_attr( $address ); ?>" class="large-text"></td>
			</tr>
			<tr>
				<th><label for="_cem_group_start_date"><?php esc_html_e( 'Start Date', 'church-event-manager' ); ?></label></th>
				<td><input type="date" id="_cem_group_start_date" name="_cem_group_start_date" value="<?php echo esc_attr( $start_date ); ?>">
					<p class="description"><?php esc_html_e( 'When the group begins meeting (optional).', 'church-event-manager' ); ?></p>
				</td>
				<th><label for="_cem_group_end_date"><?php esc_html_e( 'End Date', 'church-event-manager' ); ?></label></th>
				<td><input type="date" id="_cem_group_end_date" name="_cem_group_end_date" value="<?php echo esc_attr( $end_date ); ?>">
					<p class="description"><?php esc_html_e( 'When the group season ends (optional).', 'church-event-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="_cem_group_contact_phone"><?php esc_html_e( 'Contact Phone', 'church-event-manager' ); ?></label></th>
				<td><input type="tel" id="_cem_group_contact_phone" name="_cem_group_contact_phone" value="<?php echo esc_attr( $contact_phone ); ?>" class="regular-text"></td>
				<th><?php esc_html_e( 'Options', 'church-event-manager' ); ?></th>
				<td>
					<label style="display:block;margin-bottom:6px">
						<input type="checkbox" name="_cem_group_childcare" value="1" <?php checked( $childcare, '1' ); ?>>
						<?php esc_html_e( 'Childcare Available', 'church-event-manager' ); ?>
					</label>
					<label style="display:block">
						<input type="checkbox" name="_cem_group_online" value="1" id="cem-group-online" <?php checked( $online, '1' ); ?>>
						<?php esc_html_e( 'Online / Virtual Option', 'church-event-manager' ); ?>
					</label>
				</td>
			</tr>
			<tr id="cem-group-meeting-url-row" <?php echo $online !== '1' ? 'style="display:none"' : ''; ?>>
				<th><label for="_cem_group_meeting_url"><?php esc_html_e( 'Meeting URL', 'church-event-manager' ); ?></label></th>
				<td colspan="3"><input type="url" id="_cem_group_meeting_url" name="_cem_group_meeting_url" value="<?php echo esc_attr( $meeting_url ); ?>" class="large-text" placeholder="https://zoom.us/j/..."></td>
			</tr>
		</table>
		<script>
		jQuery('#cem-group-online').on('change', function(){
			jQuery('#cem-group-meeting-url-row').toggle(this.checked);
		});
		</script>
		<?php
	}

	/**
	 * Group Members meta box — shows all active registrations (members) for this group.
	 */
	public function mb_group_members( $post ) {
		$members = CEM_Registration::get_for_event( $post->ID, [
			'status'   => [ 'confirmed', 'pending', 'checked_in' ],
			'per_page' => 0,
			'orderby'  => 'last_name',
			'order'    => 'ASC',
		] );

		$count = count( $members );
		?>
		<p class="description">
			<?php printf(
				/* translators: %d: member count */
				esc_html( _n( '%d active member', '%d active members', $count, 'church-event-manager' ) ),
				$count
			); ?>
			<?php if ( $count > 0 ) : ?>
			&nbsp;·&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-group-signups&group_id=' . $post->ID ) ); ?>">
				<?php esc_html_e( 'View Full List →', 'church-event-manager' ); ?>
			</a>
			<?php endif; ?>
		</p>

		<?php if ( ! empty( $members ) ) : ?>
		<table class="widefat striped" style="margin-top:8px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'church-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Email', 'church-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'church-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Joined', 'church-event-manager' ); ?></th>
					<th><?php esc_html_e( 'Status', 'church-event-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( array_slice( $members, 0, 20 ) as $m ) : ?>
				<tr>
					<td><?php echo esc_html( trim( $m->first_name . ' ' . $m->last_name ) ); ?></td>
					<td><a href="mailto:<?php echo esc_attr( $m->email ); ?>"><?php echo esc_html( $m->email ); ?></a></td>
					<td><?php echo esc_html( $m->phone ?: '—' ); ?></td>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $m->created_at ) ) ); ?></td>
					<td><?php echo esc_html( ucfirst( $m->status ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $count > 20 ) : ?>
		<p class="description" style="margin-top:8px">
			<?php printf(
				esc_html__( 'Showing 20 of %d members.', 'church-event-manager' ),
				$count
			); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-group-signups&group_id=' . $post->ID ) ); ?>">
				<?php esc_html_e( 'View All →', 'church-event-manager' ); ?>
			</a>
		</p>
		<?php endif; ?>
		<?php else : ?>
		<p style="color:#6b7280;font-style:italic;margin-top:8px"><?php esc_html_e( 'No members yet.', 'church-event-manager' ); ?></p>
		<?php endif;
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['cem_group_nonce'] ) || ! wp_verify_nonce( $_POST['cem_group_nonce'], 'cem_group_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$text_fields = [
			'_cem_group_leader', '_cem_group_location', '_cem_group_address',
			'_cem_group_day', '_cem_group_time', '_cem_group_contact_phone',
		];
		foreach ( $text_fields as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['_cem_group_leader_email'] ) ) {
			update_post_meta( $post_id, '_cem_group_leader_email', sanitize_email( $_POST['_cem_group_leader_email'] ) );
		}
		if ( isset( $_POST['_cem_group_capacity'] ) ) {
			update_post_meta( $post_id, '_cem_group_capacity', absint( $_POST['_cem_group_capacity'] ) );
		}

		// Date fields
		$date_fields = [ '_cem_group_start_date', '_cem_group_end_date' ];
		foreach ( $date_fields as $field ) {
			if ( isset( $_POST[ $field ] ) && $_POST[ $field ] !== '' ) {
				update_post_meta( $post_id, $field, sanitize_text_field( $_POST[ $field ] ) );
			} else {
				delete_post_meta( $post_id, $field );
			}
		}

		// URL fields
		if ( isset( $_POST['_cem_group_meeting_url'] ) ) {
			update_post_meta( $post_id, '_cem_group_meeting_url', esc_url_raw( $_POST['_cem_group_meeting_url'] ) );
		}

		// Checkbox fields
		$checkbox_fields = [ '_cem_group_childcare', '_cem_group_online' ];
		foreach ( $checkbox_fields as $field ) {
			update_post_meta( $post_id, $field, ! empty( $_POST[ $field ] ) ? '1' : '0' );
		}

		$allowed_types  = array_keys( self::group_types() );
		$allowed_statuses = [ 'open', 'closed', 'full', 'inactive' ];
		$allowed_freqs  = [ '', 'weekly', 'biweekly', 'monthly', 'custom' ];

		if ( isset( $_POST['_cem_group_type'] ) && in_array( $_POST['_cem_group_type'], $allowed_types, true ) ) {
			update_post_meta( $post_id, '_cem_group_type', $_POST['_cem_group_type'] );
		}
		if ( isset( $_POST['_cem_group_status'] ) && in_array( $_POST['_cem_group_status'], $allowed_statuses, true ) ) {
			update_post_meta( $post_id, '_cem_group_status', $_POST['_cem_group_status'] );
		}
		if ( isset( $_POST['_cem_group_frequency'] ) && in_array( $_POST['_cem_group_frequency'], $allowed_freqs, true ) ) {
			update_post_meta( $post_id, '_cem_group_frequency', $_POST['_cem_group_frequency'] );
		}
	}

	// ── Email Group Members ──────────────────────────────────────────────────

	/**
	 * Get all active member emails for a group.
	 *
	 * @param int $group_id
	 * @return array [ ['name' => '...', 'email' => '...', 'registration_id' => int], ... ]
	 */
	public static function get_member_emails( $group_id ) {
		$members = CEM_Registration::get_for_event( $group_id, [
			'status'   => [ 'confirmed', 'checked_in' ],
			'per_page' => 0,
		] );

		$result = [];
		foreach ( $members as $m ) {
			$result[] = [
				'name'            => trim( $m->first_name . ' ' . $m->last_name ),
				'email'           => $m->email,
				'registration_id' => $m->id,
			];
		}
		return $result;
	}

	// ── Template override ─────────────────────────────────────────────────────

	public function single_group_template( $template ) {
		if ( ! is_singular( 'cem_group' ) ) return $template;

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
	 * Count active members for a group (uses event_id = group_id convention).
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

	/**
	 * Format a 24h time string (HH:MM) as a human-readable time.
	 */
	public static function format_time( $time_24h ) {
		if ( ! $time_24h ) return '';
		$ts = strtotime( '2000-01-01 ' . $time_24h );
		return $ts ? wp_date( get_option( 'time_format', 'g:i a' ), $ts ) : $time_24h;
	}

	/**
	 * Get cem_event posts linked to this group via _cem_event_group_id.
	 *
	 * @param int $group_id
	 * @return WP_Post[]
	 */
	public static function get_linked_events( $group_id ) {
		$posts = get_posts( [
			'post_type'      => 'cem_event',
			'post_status'    => [ 'publish', 'draft', 'future' ],
			'posts_per_page' => -1,
			'meta_key'       => '_cem_start_datetime',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_cem_event_group_id',
					'value'   => (int) $group_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				],
			],
		] );
		return $posts ?: [];
	}

	public function mb_linked_events( $post ) {
		$events   = self::get_linked_events( $post->ID );
		$add_url  = add_query_arg( [
			'post_type'    => 'cem_event',
			'cem_group_id' => $post->ID,
		], admin_url( 'post-new.php' ) );
		?>
		<div style="margin-bottom:10px">
			<a href="<?php echo esc_url( $add_url ); ?>" class="button button-primary">
				<?php esc_html_e( '+ Add Event to Group', 'church-event-manager' ); ?>
			</a>
		</div>
		<?php if ( empty( $events ) ) : ?>
			<p style="color:#6b7280;font-style:italic"><?php esc_html_e( 'No events linked to this group yet.', 'church-event-manager' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="margin-top:4px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Event', 'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Date', 'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status', 'church-event-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $events as $event ) :
					$start  = get_post_meta( $event->ID, '_cem_start_datetime', true );
					$status = $event->post_status;
					?>
					<tr>
						<td><?php echo esc_html( $event->post_title ); ?></td>
						<td><?php echo $start ? esc_html( CEM_Helpers::format_date( $start ) ) : '—'; ?></td>
						<td><?php echo esc_html( ucfirst( $status ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $event->ID ) ); ?>"><?php esc_html_e( 'Edit', 'church-event-manager' ); ?></a>
							&nbsp;|&nbsp;
							<a href="<?php echo esc_url( get_permalink( $event->ID ) ); ?>" target="_blank"><?php esc_html_e( 'View', 'church-event-manager' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif;
	}
}
