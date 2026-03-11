<?php
/**
 * All admin-facing functionality:
 * menus, dashboard, event meta boxes, registrations, email center,
 * reports, settings, and CPT columns.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Admin {

	public function init() {
		add_action( 'admin_menu',              [ $this, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts',   [ $this, 'enqueue_assets' ] );
		add_action( 'add_meta_boxes',          [ $this, 'add_meta_boxes' ] );
		add_action( 'save_post_cem_event',     [ $this, 'save_event_meta' ] );
		add_action( 'admin_notices',           [ $this, 'admin_notices' ] );
		add_filter( 'plugin_action_links_' . CEM_PLUGIN_BASENAME, [ $this, 'plugin_links' ] );
		add_filter( 'plugin_row_meta',         [ $this, 'plugin_row_meta' ], 10, 2 );
		add_filter( 'admin_footer_text',       [ $this, 'admin_footer_text' ] );
		// Custom role capability
		add_action( 'init', [ $this, 'add_custom_capabilities' ] );
	}

	// ── Capabilities ──────────────────────────────────────────────────────────

	public function add_custom_capabilities() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( 'cem_manage_events' );
		}
	}

	// ── Admin Menu ────────────────────────────────────────────────────────────

	public function add_admin_menu() {
		add_menu_page(
			__( 'Church Events', 'church-event-manager' ),
			__( 'Church Events', 'church-event-manager' ),
			'cem_manage_events',
			'cem-dashboard',
			[ $this, 'page_dashboard' ],
			'dashicons-calendar-alt',
			30
		);

		add_submenu_page( 'cem-dashboard', __( 'Dashboard',      'church-event-manager' ), __( 'Dashboard',      'church-event-manager' ), 'cem_manage_events', 'cem-dashboard',      [ $this, 'page_dashboard' ] );
		add_submenu_page( 'cem-dashboard', __( 'All Events',     'church-event-manager' ), __( 'All Events',     'church-event-manager' ), 'cem_manage_events', 'edit.php?post_type=cem_event' );
		add_submenu_page( 'cem-dashboard', __( 'Add New Event',  'church-event-manager' ), __( 'Add New Event',  'church-event-manager' ), 'cem_manage_events', 'post-new.php?post_type=cem_event' );
		add_submenu_page( 'cem-dashboard', __( 'Registrations',  'church-event-manager' ), __( 'Registrations',  'church-event-manager' ), 'cem_manage_events', 'cem-registrations',  [ $this, 'page_registrations' ] );
		add_submenu_page( 'cem-dashboard', __( 'Groups',         'church-event-manager' ), __( 'Groups',         'church-event-manager' ), 'cem_manage_events', 'edit.php?post_type=cem_group' );
		add_submenu_page( 'cem-dashboard', __( 'Add New Group',  'church-event-manager' ), __( 'Add New Group',  'church-event-manager' ), 'cem_manage_events', 'post-new.php?post_type=cem_group' );
		add_submenu_page( 'cem-dashboard', __( 'Group Sign-ups', 'church-event-manager' ), __( 'Group Sign-ups', 'church-event-manager' ), 'cem_manage_events', 'cem-group-signups',  [ $this, 'page_group_signups' ] );
		add_submenu_page( 'cem-dashboard', __( 'Email Center',   'church-event-manager' ), __( 'Email Center',   'church-event-manager' ), 'cem_manage_events', 'cem-emails',         [ $this, 'page_emails' ] );
		add_submenu_page( 'cem-dashboard', __( 'Reports',        'church-event-manager' ), __( 'Reports',        'church-event-manager' ), 'cem_manage_events', 'cem-reports',        [ $this, 'page_reports' ] );
		add_submenu_page( 'cem-dashboard', __( 'Settings',       'church-event-manager' ), __( 'Settings',       'church-event-manager' ), 'manage_options',    'cem-settings',       [ $this, 'page_settings' ] );
		add_submenu_page( 'cem-dashboard', __( 'Support',        'church-event-manager' ), __( '🎫 Support',      'church-event-manager' ), 'manage_options',    'cem-support',        [ $this, 'page_support'   ] );
	}

	// ── Assets ────────────────────────────────────────────────────────────────

	public function enqueue_assets( $hook ) {
		$cem_pages = [ 'toplevel_page_cem-dashboard', 'church-events_page_cem-registrations',
			'church-events_page_cem-emails', 'church-events_page_cem-reports',
			'church-events_page_cem-settings', 'church-events_page_cem-group-signups',
			'cem_event', 'post.php', 'post-new.php' ];

		$on_cem = in_array( $hook, $cem_pages )
			|| ( in_array( $hook, [ 'post.php', 'post-new.php' ] ) && in_array( get_post_type(), [ 'cem_event', 'cem_group' ], true ) );

		if ( ! $on_cem ) return;

		wp_enqueue_style(  'cem-admin', CEM_PLUGIN_URL . 'admin/css/cem-admin.css', [], CEM_VERSION );
		wp_enqueue_style(  'wp-color-picker' );
		wp_enqueue_script( 'cem-admin', CEM_PLUGIN_URL . 'admin/js/cem-admin.js',
			[ 'jquery', 'wp-color-picker', 'jquery-ui-sortable', 'jquery-ui-datepicker' ], CEM_VERSION, true );

		wp_localize_script( 'cem-admin', 'cemAdmin', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'cem_admin_nonce' ),
			'exportNonce'=> wp_create_nonce( 'cem_export_nonce' ),
			'settingsNonce' => wp_create_nonce( 'cem_settings_nonce' ),
			'confirmDelete' => __( 'Are you sure? This cannot be undone.', 'church-event-manager' ),
			'confirmBulkEmail' => __( 'Send this email to all selected registrants?', 'church-event-manager' ),
			'strings'    => [
				'saved'   => __( 'Saved!', 'church-event-manager' ),
				'error'   => __( 'An error occurred.', 'church-event-manager' ),
				'sending' => __( 'Sending…', 'church-event-manager' ),
				'loading' => __( 'Loading…', 'church-event-manager' ),
			],
		] );
	}

	// ── Admin Notices ─────────────────────────────────────────────────────────

	public function admin_notices() {
		// Show notice if SMTP isn't configured
		$from = get_option( 'cem_from_email', '' );
		$screen = get_current_screen();
		if ( $screen && strpos( $screen->id, 'cem' ) !== false && ! $from ) :
		?>
		<div class="notice notice-warning is-dismissible">
			<p><?php
				printf(
					__( '<strong>Church Event Manager:</strong> Please <a href="%s">configure your email settings</a> to enable confirmation emails.', 'church-event-manager' ),
					admin_url( 'admin.php?page=cem-settings#email' )
				);
			?></p>
		</div>
		<?php endif;
	}

	// ── Plugin links ──────────────────────────────────────────────────────────

	public function plugin_links( $links ) {
		array_unshift( $links,
			'<a href="' . admin_url( 'admin.php?page=cem-dashboard' ) . '">' . __( 'Dashboard', 'church-event-manager' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=cem-settings' ) . '">'  . __( 'Settings',  'church-event-manager' ) . '</a>',
			'<a href="' . admin_url( 'admin.php?page=cem-support' ) . '" style="color:#e17055;font-weight:600">'  . __( '🎫 Support',  'church-event-manager' ) . '</a>'
		);
		return $links;
	}

	public function plugin_row_meta( $links, $file ) {
		if ( $file !== CEM_PLUGIN_BASENAME ) {
			return $links;
		}
		$links[] = '<a href="https://whiteoakmedia.io" target="_blank" rel="noopener noreferrer">White Oak Media LLC</a>';
		$links[] = '<a href="' . admin_url( 'admin.php?page=cem-support' ) . '">' . __( 'Submit a Ticket', 'church-event-manager' ) . '</a>';
		return $links;
	}

	public function admin_footer_text( $text ) {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return $text;
		}
		// Show on all CEM admin pages (screen IDs contain 'cem-' or are the event CPT screens).
		$is_cem = strpos( $screen->id, 'cem-' ) !== false
			|| strpos( $screen->id, 'cem_event' ) !== false
			|| strpos( $screen->post_type ?? '', 'cem_event' ) !== false;

		if ( $is_cem ) {
			return sprintf(
				/* translators: 1: plugin name, 2: company link */
				__( 'Thank you for using %1$s &mdash; developed with ❤️ by %2$s', 'church-event-manager' ),
				'<strong>Church Event Manager</strong>',
				'<a href="https://whiteoakmedia.io" target="_blank" rel="noopener noreferrer"><strong>White Oak Media LLC</strong></a>'
			);
		}
		return $text;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// PAGES
	// ─────────────────────────────────────────────────────────────────────────

	// ── Dashboard ─────────────────────────────────────────────────────────────

	public function page_dashboard() {
		$stats      = CEM_Registration::get_dashboard_stats();
		$recent_regs = CEM_Registration::get_all( [ 'per_page' => 10, 'orderby' => 'created_at', 'order' => 'DESC' ] )['registrations'];

		// Upcoming events
		$upcoming = get_posts( [
			'post_type'      => 'cem_event',
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'meta_key'       => '_cem_start_datetime',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [ [ 'key' => '_cem_start_datetime', 'value' => current_time('mysql'), 'compare' => '>=', 'type' => 'DATETIME' ] ],
		] );

		// Near-capacity events (>= 80% full)
		$alerts = [];
		$all_events = get_posts( [ 'post_type' => 'cem_event', 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_cem_capacity', 'meta_compare' => '>', 'meta_value' => 0 ] );
		foreach ( $all_events as $ev ) {
			$cap   = (int) get_post_meta( $ev->ID, '_cem_capacity', true );
			$taken = CEM_Helpers::get_registration_count( $ev->ID );
			if ( $cap > 0 && ( $taken / $cap ) >= 0.80 ) {
				$alerts[] = [ 'event' => $ev, 'taken' => $taken, 'cap' => $cap, 'pct' => round(($taken/$cap)*100) ];
			}
		}
		?>
		<div class="wrap cem-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Church Event Manager', 'church-event-manager' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=cem_event' ) ); ?>" class="page-title-action">
				+ <?php esc_html_e( 'Add New Event', 'church-event-manager' ); ?>
			</a>
			<hr class="wp-header-end">

			<!-- Stat Cards -->
			<div class="cem-stat-cards">
				<?php $this->stat_card( __( 'Total Events',    'church-event-manager' ), $stats['total_events'],          'dashicons-calendar-alt', '#6c5ce7', admin_url('edit.php?post_type=cem_event') ); ?>
				<?php $this->stat_card( __( 'Registrations',   'church-event-manager' ), $stats['total_registrations'],   'dashicons-groups',       '#00b894' ); ?>
				<?php $this->stat_card( __( 'Registered Today','church-event-manager' ), $stats['registrations_today'],   'dashicons-star-filled',  '#fdcb6e' ); ?>
				<?php $this->stat_card( __( 'Upcoming Events', 'church-event-manager' ), $stats['upcoming_events'],       'dashicons-clock',        '#0984e3', admin_url('admin.php?page=cem-registrations') ); ?>
				<?php $this->stat_card( __( 'Pending Confirm', 'church-event-manager' ), $stats['pending_confirmations'], 'dashicons-yes-alt',      '#e17055', admin_url('admin.php?page=cem-registrations&status=pending') ); ?>
				<?php $this->stat_card( __( 'Waitlisted',      'church-event-manager' ), $stats['total_waitlisted'],      'dashicons-list-view',    '#a29bfe', admin_url('admin.php?page=cem-registrations&status=waitlisted') ); ?>
			</div>

			<div class="cem-dashboard-columns">
				<!-- Recent Registrations -->
				<div class="cem-dashboard-card">
					<h2><?php esc_html_e( 'Recent Registrations', 'church-event-manager' ); ?></h2>
					<?php if ( empty( $recent_regs ) ) : ?>
					<p class="cem-muted"><?php esc_html_e( 'No registrations yet.', 'church-event-manager' ); ?></p>
					<?php else : ?>
					<table class="cem-table">
						<thead><tr>
							<th><?php esc_html_e( 'Name', 'church-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Event', 'church-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'church-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Payment', 'church-event-manager' ); ?></th>
							<th><?php esc_html_e( 'Registered', 'church-event-manager' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $recent_regs as $reg ) :
							$event = get_post( $reg->event_id );
						?>
						<tr>
							<td><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></td>
							<td><?php echo $event ? esc_html( wp_trim_words( $event->post_title, 5 ) ) : '—'; ?></td>
							<td><?php echo CEM_Helpers::status_badge( $reg->status ); ?></td>
							<td><?php
								$pay_status = $reg->payment_status ?? 'free';
								if ( $pay_status === 'paid' ) {
									echo '<span class="cem-badge cem-badge--paid">&#10003; Paid</span>';
								} elseif ( $pay_status === 'in_person' ) {
									echo '<span class="cem-badge cem-badge--in-person">&#128197; At Door</span>';
								} elseif ( $pay_status === 'free' ) {
									echo '<span class="cem-badge cem-badge--free">Free</span>';
								} else {
									echo '<span class="cem-badge cem-badge--pending">' . esc_html( ucfirst( $pay_status ) ) . '</span>';
								}
							?></td>
							<td class="cem-muted"><?php echo esc_html( human_time_diff( strtotime( $reg->created_at ), time() ) . ' ago' ); ?></td>
						</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-registrations' ) ); ?>"><?php esc_html_e( 'View all registrations →', 'church-event-manager' ); ?></a></p>
					<?php endif; ?>
				</div>

				<!-- Right column -->
				<div class="cem-dashboard-right">

					<!-- Upcoming Events -->
					<div class="cem-dashboard-card">
						<h2><?php esc_html_e( 'Upcoming Events', 'church-event-manager' ); ?></h2>
						<?php if ( empty( $upcoming ) ) : ?>
						<p class="cem-muted"><?php esc_html_e( 'No upcoming events.', 'church-event-manager' ); ?></p>
						<?php else : ?>
						<ul class="cem-event-list">
						<?php foreach ( $upcoming as $ev ) :
							$start = get_post_meta( $ev->ID, '_cem_start_datetime', true );
							$cap   = (int) get_post_meta( $ev->ID, '_cem_capacity', true );
							$taken = CEM_Helpers::get_registration_count( $ev->ID );
						?>
						<li>
							<strong><?php echo esc_html( $ev->post_title ); ?></strong>
							<span class="cem-muted"><?php echo esc_html( CEM_Helpers::format_date( $start ) ); ?></span>
							<span class="cem-muted"><?php echo $cap > 0 ? esc_html( "$taken/$cap" ) : esc_html( $taken . ' registered' ); ?></span>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-registrations&event_id=' . $ev->ID ) ); ?>"><?php esc_html_e( 'View', 'church-event-manager' ); ?></a>
						</li>
						<?php endforeach; ?>
						</ul>
						<?php endif; ?>
					</div>

					<!-- Capacity Alerts -->
					<?php if ( ! empty( $alerts ) ) : ?>
					<div class="cem-dashboard-card cem-card-alert">
						<h2>⚠️ <?php esc_html_e( 'Capacity Alerts', 'church-event-manager' ); ?></h2>
						<ul class="cem-alert-list">
						<?php foreach ( $alerts as $alert ) : ?>
						<li>
							<strong><?php echo esc_html( $alert['event']->post_title ); ?></strong>
							<div class="cem-progress-bar-wrap">
								<div class="cem-progress-bar" style="width:<?php echo esc_attr( $alert['pct'] ); ?>%;background:<?php echo $alert['pct'] >= 100 ? '#e17055' : '#fdcb6e'; ?>"></div>
							</div>
							<span><?php echo esc_html( "{$alert['taken']}/{$alert['cap']} ({$alert['pct']}%)" ); ?></span>
						</li>
						<?php endforeach; ?>
						</ul>
					</div>
					<?php endif; ?>

					<!-- Quick Links -->
					<div class="cem-dashboard-card">
						<h2><?php esc_html_e( 'Quick Actions', 'church-event-manager' ); ?></h2>
						<div class="cem-quick-links">
							<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=cem_event' ) ); ?>" class="cem-quick-link">📅 <?php esc_html_e( 'New Event', 'church-event-manager' ); ?></a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-emails' ) ); ?>"           class="cem-quick-link">✉️ <?php esc_html_e( 'Send Email', 'church-event-manager' ); ?></a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-reports' ) ); ?>"           class="cem-quick-link">📊 <?php esc_html_e( 'Reports',   'church-event-manager' ); ?></a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-settings' ) ); ?>"          class="cem-quick-link">⚙️ <?php esc_html_e( 'Settings',  'church-event-manager' ); ?></a>
						</div>
					</div>
				</div>
			</div><!-- /.cem-dashboard-columns -->

			<!-- White Oak Media LLC credit -->
			<div class="cem-wom-footer">
				<?php printf(
					/* translators: 1: plugin name, 2: company link */
					__( '%1$s &mdash; developed by %2$s', 'church-event-manager' ),
					'<strong>Church Event Manager</strong>',
					'<a href="https://whiteoakmedia.io" target="_blank" rel="noopener noreferrer"><strong>White Oak Media LLC</strong></a>'
				); ?>
				<span class="cem-wom-footer__version">v<?php echo esc_html( CEM_VERSION ); ?></span>
				&bull;
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cem-support' ) ); ?>"><?php esc_html_e( 'Submit a Ticket', 'church-event-manager' ); ?></a>
			</div>

		</div><!-- /.cem-wrap -->
		<?php
	}

	private function stat_card( $label, $value, $icon, $color, $url = '' ) {
		echo '<div class="cem-stat-card" style="border-top-color:' . esc_attr($color) . '">';
		echo '<div class="cem-stat-icon dashicons ' . esc_attr($icon) . '" style="color:' . esc_attr($color) . '"></div>';
		$val_html = $url
			? '<a href="' . esc_url($url) . '" class="cem-stat-value">' . esc_html($value) . '</a>'
			: '<span class="cem-stat-value">' . esc_html($value) . '</span>';
		echo '<div class="cem-stat-info">' . $val_html . '<span class="cem-stat-label">' . esc_html($label) . '</span></div>';
		echo '</div>';
	}

	// ── Registrations Page ────────────────────────────────────────────────────

	public function page_registrations() {
		$event_id  = (int) ( $_GET['event_id'] ?? 0 );
		$status    = sanitize_key( $_GET['status'] ?? '' );
		$search    = sanitize_text_field( $_GET['s'] ?? '' );
		$page      = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page  = 25;

		$args = [
			'per_page' => $per_page,
			'page'     => $page,
			'search'   => $search,
			'status'   => $status ? [ $status ] : [],
			'event_id' => $event_id,
		];

		$result = CEM_Registration::get_all( $args );
		$regs   = $result['registrations'];
		$total  = $result['total'];
		$pages  = ceil( $total / $per_page );

		// Events dropdown for filter
		$events = get_posts( [ 'post_type' => 'cem_event', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );

		?>
		<div class="wrap cem-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Registrations', 'church-event-manager' ); ?></h1>
			<?php if ( $event_id ) :
				$ev = get_post( $event_id );
			?>
			<span class="cem-filter-label"><?php echo $ev ? esc_html( $ev->post_title ) : ''; ?></span>
			<a href="<?php echo esc_url( admin_url('admin.php?page=cem-registrations') ); ?>" class="page-title-action"><?php esc_html_e('Clear', 'church-event-manager'); ?></a>
			<?php endif; ?>

			<!-- Export -->
			<a href="<?php echo esc_url( add_query_arg( [
				'action'   => 'cem_export_registrations',
				'event_id' => $event_id,
				'status'   => $status,
				'nonce'    => wp_create_nonce('cem_export_nonce'),
			], admin_url('admin-ajax.php') ) ); ?>" class="page-title-action">
				⬇️ <?php esc_html_e( 'Export CSV', 'church-event-manager' ); ?>
			</a>
			<hr class="wp-header-end">

			<!-- Filters -->
			<div class="cem-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="cem-registrations">
					<select name="event_id" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Events', 'church-event-manager' ); ?></option>
						<?php foreach ( $events as $ev ) : ?>
						<option value="<?php echo esc_attr( $ev->ID ); ?>" <?php selected( $event_id, $ev->ID ); ?>>
							<?php echo esc_html( $ev->post_title ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<select name="status" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Statuses', 'church-event-manager' ); ?></option>
						<?php foreach ( [ 'pending','confirmed','cancelled','waitlisted','checked_in' ] as $s ) : ?>
						<option value="<?php echo esc_attr($s); ?>" <?php selected($status,$s); ?>><?php echo esc_html(ucwords(str_replace('_',' ',$s))); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" placeholder="<?php esc_attr_e('Search name, email…','church-event-manager'); ?>"
						value="<?php echo esc_attr($search); ?>">
					<button type="submit" class="button"><?php esc_html_e('Filter','church-event-manager'); ?></button>
				</form>

				<!-- Bulk actions -->
				<form id="cem-bulk-form">
					<select id="cem-bulk-action">
						<option value=""><?php esc_html_e('Bulk Actions','church-event-manager'); ?></option>
						<option value="confirmed"><?php esc_html_e('Mark Confirmed','church-event-manager'); ?></option>
						<option value="checked_in"><?php esc_html_e('Mark Checked In','church-event-manager'); ?></option>
						<option value="cancelled"><?php esc_html_e('Mark Cancelled','church-event-manager'); ?></option>
						<option value="reminder"><?php esc_html_e('Send Reminder Email','church-event-manager'); ?></option>
					</select>
					<button type="button" class="button" id="cem-apply-bulk"><?php esc_html_e('Apply','church-event-manager'); ?></button>
				</form>
			</div>

			<!-- Results count -->
			<p class="cem-results-count">
				<?php printf( esc_html__( '%d registrations found', 'church-event-manager' ), $total ); ?>
			</p>

			<?php if ( empty( $regs ) ) : ?>
			<div class="cem-empty-state">
				<p><?php esc_html_e( 'No registrations found matching your criteria.', 'church-event-manager' ); ?></p>
			</div>
			<?php else : ?>

			<table class="wp-list-table widefat fixed striped cem-reg-table">
				<thead>
					<tr>
						<th class="check-column"><input type="checkbox" id="cem-select-all"></th>
						<th><?php esc_html_e( 'Name',         'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Event',        'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Email / Phone','church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Attendees',    'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status',       'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Registered',   'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Actions',      'church-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $regs as $reg ) :
					$event = get_post( $reg->event_id );
				?>
				<tr data-id="<?php echo esc_attr( $reg->id ); ?>">
					<td class="check-column"><input type="checkbox" class="cem-reg-cb" value="<?php echo esc_attr( $reg->id ); ?>"></td>
					<td>
						<strong>
							<a href="#" class="cem-view-reg" data-id="<?php echo esc_attr( $reg->id ); ?>">
								<?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?>
							</a>
						</strong>
						<br><small class="cem-muted"><?php echo esc_html( $reg->registration_code ); ?></small>
					</td>
					<td>
						<?php if ( $event ) : ?>
						<a href="<?php echo esc_url( admin_url('admin.php?page=cem-registrations&event_id=' . $reg->event_id) ); ?>">
							<?php echo esc_html( wp_trim_words( $event->post_title, 5 ) ); ?>
						</a>
						<?php else : ?>—<?php endif; ?>
					</td>
					<td>
						<a href="mailto:<?php echo esc_attr( $reg->email ); ?>"><?php echo esc_html( $reg->email ); ?></a>
						<?php if ( $reg->phone ) : ?><br><small><?php echo esc_html( $reg->phone ); ?></small><?php endif; ?>
					</td>
					<td><?php echo esc_html( $reg->num_attendees ); ?></td>
					<td><?php echo CEM_Helpers::status_badge( $reg->status ); ?></td>
					<td class="cem-muted"><?php echo esc_html( CEM_Helpers::format_datetime( $reg->created_at ) ); ?></td>
					<td class="cem-actions">
						<?php if ( $reg->status !== 'checked_in' ) : ?>
						<button class="button button-small cem-check-in-btn" data-id="<?php echo esc_attr( $reg->id ); ?>" title="<?php esc_attr_e('Check In','church-event-manager'); ?>">✔</button>
						<?php endif; ?>
						<button class="button button-small cem-view-reg" data-id="<?php echo esc_attr( $reg->id ); ?>" title="<?php esc_attr_e('View Details','church-event-manager'); ?>">👁</button>
						<?php if ( $reg->status === 'waitlisted' ) : ?>
						<button class="button button-small cem-promote-btn" data-id="<?php echo esc_attr( $reg->id ); ?>" title="<?php esc_attr_e('Promote from Waitlist','church-event-manager'); ?>">⬆</button>
						<?php endif; ?>
						<button class="button button-small cem-delete-reg" data-id="<?php echo esc_attr( $reg->id ); ?>" title="<?php esc_attr_e('Delete','church-event-manager'); ?>">🗑</button>
					</td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
			<div class="cem-pagination tablenav">
				<div class="tablenav-pages">
					<?php for ( $p = 1; $p <= $pages; $p++ ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'paged', $p ) ); ?>"
						class="button <?php echo $p === $page ? 'button-primary' : ''; ?>">
						<?php echo esc_html( $p ); ?>
					</a>
					<?php endfor; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php endif; ?>
		</div>

		<!-- Registration Detail Modal -->
		<div id="cem-reg-modal" class="cem-modal" style="display:none">
			<div class="cem-modal-overlay"></div>
			<div class="cem-modal-content">
				<button class="cem-modal-close">✕</button>
				<div id="cem-reg-modal-body">
					<p class="cem-muted"><?php esc_html_e( 'Loading…', 'church-event-manager' ); ?></p>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Group Sign-ups Page ──────────────────────────────────────────────────

	public function page_group_signups() {
		global $wpdb;

		$group_id = (int) ( $_GET['group_id'] ?? 0 );
		$status   = sanitize_key( $_GET['status'] ?? '' );
		$search   = sanitize_text_field( $_GET['s'] ?? '' );
		$page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$per_page = 25;

		$args = [
			'per_page' => $per_page,
			'page'     => $page,
			'search'   => $search,
			'status'   => $status ? [ $status ] : [],
			'event_id' => $group_id,
		];

		// If no specific group, limit to cem_group post IDs only.
		if ( ! $group_id ) {
			$group_ids = get_posts( [
				'post_type'      => 'cem_group',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			] );
			if ( empty( $group_ids ) ) {
				$group_ids = [ 0 ]; // Force zero results.
			}
			$args['event_ids'] = $group_ids;
		}

		$result = CEM_Registration::get_all( $args );
		$regs   = $result['registrations'];
		$total  = $result['total'];
		$pages  = ceil( $total / $per_page );

		$groups = get_posts( [ 'post_type' => 'cem_group', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );
		?>
		<div class="wrap cem-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Group Sign-ups', 'church-event-manager' ); ?></h1>
			<hr class="wp-header-end">

			<div class="cem-filter-bar">
				<form method="get">
					<input type="hidden" name="page" value="cem-group-signups">
					<select name="group_id" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Groups', 'church-event-manager' ); ?></option>
						<?php foreach ( $groups as $g ) : ?>
						<option value="<?php echo esc_attr( $g->ID ); ?>" <?php selected( $group_id, $g->ID ); ?>>
							<?php echo esc_html( $g->post_title ); ?>
						</option>
						<?php endforeach; ?>
					</select>
					<select name="status" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Statuses', 'church-event-manager' ); ?></option>
						<?php foreach ( [ 'pending', 'confirmed', 'cancelled', 'waitlisted', 'checked_in' ] as $s ) : ?>
						<option value="<?php echo esc_attr($s); ?>" <?php selected($status,$s); ?>><?php echo esc_html(ucwords(str_replace('_',' ',$s))); ?></option>
						<?php endforeach; ?>
					</select>
					<input type="search" name="s" placeholder="<?php esc_attr_e('Search name, email…','church-event-manager'); ?>"
						value="<?php echo esc_attr($search); ?>">
					<button type="submit" class="button"><?php esc_html_e('Filter','church-event-manager'); ?></button>
				</form>
			</div>

			<p class="cem-results-count">
				<?php printf( esc_html__( '%d sign-ups found', 'church-event-manager' ), $total ); ?>
			</p>

			<?php if ( empty( $regs ) ) : ?>
			<div class="cem-empty-state">
				<p><?php esc_html_e( 'No sign-ups found.', 'church-event-manager' ); ?></p>
			</div>
			<?php else : ?>
			<table class="wp-list-table widefat fixed striped cem-reg-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name',         'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Series',       'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Email',        'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Attendees',    'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Status',       'church-event-manager' ); ?></th>
						<th><?php esc_html_e( 'Registered',   'church-event-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $regs as $reg ) :
					$grp       = get_post( $reg->event_id );
					$grp_title = $grp ? esc_html( $grp->post_title ) : '—';
					$badge_map = [
						'confirmed'  => 'cem-badge--green',
						'cancelled'  => 'cem-badge--red',
						'waitlisted' => 'cem-badge--yellow',
						'checked_in' => 'cem-badge--purple',
						'pending'    => '',
					];
					$badge_cls = $badge_map[ $reg->status ] ?? '';
				?>
				<tr>
					<td><strong><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></strong></td>
					<td><?php echo $grp ? '<a href="' . esc_url( get_edit_post_link( $reg->event_id ) ) . '">' . $grp_title . '</a>' : $grp_title; ?></td>
					<td><?php echo esc_html( $reg->email ); ?></td>
					<td><?php echo (int) $reg->num_attendees; ?></td>
					<td><span class="cem-badge <?php echo esc_attr($badge_cls); ?>"><?php echo esc_html(ucwords(str_replace('_',' ',$reg->status))); ?></span></td>
					<td><?php echo esc_html( wp_date( get_option('date_format'), strtotime( $reg->created_at ) ) ); ?></td>
				</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
			<div class="tablenav">
				<div class="tablenav-pages">
					<?php
					echo paginate_links( [ // phpcs:ignore WordPress.Security.EscapeOutput
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $page,
						'total'   => $pages,
					] );
					?>
				</div>
			</div>
			<?php endif; ?>
			<?php endif; ?>
		</div>

		<!-- Registration Detail Modal -->
		<div id="cem-reg-modal" class="cem-modal" style="display:none">
			<div class="cem-modal-overlay"></div>
			<div class="cem-modal-content">
				<button class="cem-modal-close">✕</button>
				<div id="cem-reg-modal-body"></div>
			</div>
		</div>
		<?php
	}

	// ── Email Center ──────────────────────────────────────────────────────────

	public function page_emails() {
		$tab = sanitize_key( $_GET['tab'] ?? 'compose' );
		$events = get_posts( [ 'post_type' => 'cem_event', 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ] );

		?>
		<div class="wrap cem-wrap">
			<h1><?php esc_html_e( 'Email Center', 'church-event-manager' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a href="?page=cem-emails&tab=compose" class="nav-tab <?php echo $tab==='compose' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Compose & Send','church-event-manager'); ?></a>
				<a href="?page=cem-emails&tab=log"     class="nav-tab <?php echo $tab==='log'     ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Email Log','church-event-manager'); ?></a>
			</nav>

			<?php if ( $tab === 'compose' ) : ?>
			<div class="cem-card">
				<h2><?php esc_html_e( 'Send Email to Registrants', 'church-event-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Compose an email to send to selected registrants. You can use template variables like {first_name}, {event_title}, {event_date}, {event_location}.', 'church-event-manager' ); ?></p>

				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e('Event','church-event-manager'); ?></label></th>
						<td>
							<select id="cem-email-event">
								<option value=""><?php esc_html_e('— All events —','church-event-manager'); ?></option>
								<?php foreach ( $events as $ev ) : ?>
								<option value="<?php echo esc_attr( $ev->ID ); ?>"><?php echo esc_html( $ev->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e('Send To','church-event-manager'); ?></label></th>
						<td>
							<label><input type="radio" name="cem_email_status" value="all" checked> <?php esc_html_e('All registrants','church-event-manager'); ?></label><br>
							<label><input type="radio" name="cem_email_status" value="confirmed">    <?php esc_html_e('Confirmed only','church-event-manager'); ?></label><br>
							<label><input type="radio" name="cem_email_status" value="pending">      <?php esc_html_e('Pending only','church-event-manager'); ?></label><br>
							<label><input type="radio" name="cem_email_status" value="waitlisted">   <?php esc_html_e('Waitlist only','church-event-manager'); ?></label>
						</td>
					</tr>
					<tr>
						<th><label for="cem-email-subject"><?php esc_html_e('Subject','church-event-manager'); ?></label></th>
						<td><input type="text" id="cem-email-subject" class="large-text" placeholder="<?php esc_attr_e('Email subject…','church-event-manager'); ?>"></td>
					</tr>
					<tr>
						<th><label for="cem-email-body"><?php esc_html_e('Message','church-event-manager'); ?></label></th>
						<td>
							<?php
							wp_editor( '', 'cem_email_body', [
								'textarea_name' => 'cem_email_body',
								'textarea_rows' => 12,
								'media_buttons' => false,
								'teeny'         => false,
							] );
							?>
							<p class="description"><?php esc_html_e('Available variables: {first_name} {last_name} {email} {event_title} {event_date} {event_time} {event_location} {registration_code} {manage_url}','church-event-manager'); ?></p>
						</td>
					</tr>
				</table>

				<p>
					<button type="button" class="button button-primary button-large" id="cem-preview-recipients">
						<?php esc_html_e( 'Preview Recipients', 'church-event-manager' ); ?>
					</button>
				</p>

				<div id="cem-email-preview-wrap" style="display:none">
					<h3><?php esc_html_e( 'Recipients', 'church-event-manager' ); ?> (<span id="cem-recipient-count">0</span>)</h3>
					<div id="cem-recipient-list" class="cem-recipient-list"></div>
					<p>
						<button type="button" class="button button-primary" id="cem-send-bulk-email">
							✉️ <?php esc_html_e( 'Send Email to All Recipients', 'church-event-manager' ); ?>
						</button>
					</p>
				</div>

				<div id="cem-email-result"></div>
			</div>

			<?php else : // log tab ?>
			<?php
			$log_page = max(1, (int) ($_GET['log_page'] ?? 1));
			$log = CEM_Email::get_log([ 'per_page' => 25, 'page' => $log_page ]);
			?>
			<div class="cem-card">
				<h2><?php esc_html_e( 'Email Log', 'church-event-manager' ); ?></h2>
				<?php if ( empty( $log['emails'] ) ) : ?>
				<p class="cem-muted"><?php esc_html_e('No emails logged yet.','church-event-manager'); ?></p>
				<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th><?php esc_html_e('To','church-event-manager'); ?></th>
						<th><?php esc_html_e('Subject','church-event-manager'); ?></th>
						<th><?php esc_html_e('Type','church-event-manager'); ?></th>
						<th><?php esc_html_e('Status','church-event-manager'); ?></th>
						<th><?php esc_html_e('Sent','church-event-manager'); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $log['emails'] as $email ) : ?>
					<tr>
						<td><?php echo esc_html( $email->to_email ); ?></td>
						<td><?php echo esc_html( $email->subject ); ?></td>
						<td><span class="cem-badge"><?php echo esc_html( $email->type ); ?></span></td>
						<td><?php echo $email->status === 'sent'
							? '<span class="cem-badge cem-badge--green">Sent</span>'
							: '<span class="cem-badge cem-badge--red">Failed</span>'; ?></td>
						<td class="cem-muted"><?php echo esc_html( CEM_Helpers::format_datetime( $email->sent_at ) ); ?></td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Reports Page ──────────────────────────────────────────────────────────

	public function page_reports() {
		global $wpdb;
		$table = "{$wpdb->prefix}cem_registrations";

		// Registrations per event
		$per_event = $wpdb->get_results(
			"SELECT r.event_id, p.post_title,
			        COUNT(*) as total,
			        SUM(CASE WHEN r.status='confirmed'  THEN 1 ELSE 0 END) as confirmed,
			        SUM(CASE WHEN r.status='checked_in' THEN 1 ELSE 0 END) as checked_in,
			        SUM(CASE WHEN r.status='cancelled'  THEN 1 ELSE 0 END) as cancelled,
			        SUM(CASE WHEN r.status='waitlisted' THEN 1 ELSE 0 END) as waitlisted
			 FROM $table r
			 LEFT JOIN {$wpdb->posts} p ON p.ID = r.event_id
			 GROUP BY r.event_id
			 ORDER BY total DESC
			 LIMIT 20"
		);

		// Registrations per day (last 30 days)
		$per_day = $wpdb->get_results(
			"SELECT DATE(created_at) as day, COUNT(*) as count
			 FROM $table
			 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY DATE(created_at)
			 ORDER BY day ASC"
		);
		?>
		<div class="wrap cem-wrap">
			<h1><?php esc_html_e( 'Reports', 'church-event-manager' ); ?></h1>

			<div class="cem-card">
				<div class="cem-report-header">
					<h2><?php esc_html_e( 'Registrations by Event', 'church-event-manager' ); ?></h2>
					<a href="<?php echo esc_url( add_query_arg([
						'action'=>'cem_export_registrations','nonce'=>wp_create_nonce('cem_export_nonce')
					], admin_url('admin-ajax.php') ) ); ?>" class="button">
						⬇️ <?php esc_html_e( 'Export All as CSV', 'church-event-manager' ); ?>
					</a>
				</div>
				<table class="wp-list-table widefat fixed striped">
					<thead><tr>
						<th><?php esc_html_e('Event','church-event-manager'); ?></th>
						<th><?php esc_html_e('Total','church-event-manager'); ?></th>
						<th><?php esc_html_e('Confirmed','church-event-manager'); ?></th>
						<th><?php esc_html_e('Checked In','church-event-manager'); ?></th>
						<th><?php esc_html_e('Cancelled','church-event-manager'); ?></th>
						<th><?php esc_html_e('Waitlisted','church-event-manager'); ?></th>
						<th><?php esc_html_e('Attendance %','church-event-manager'); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $per_event as $row ) :
						$attendance_pct = $row->confirmed > 0
							? round( ( $row->checked_in / $row->confirmed ) * 100 )
							: 0;
					?>
					<tr>
						<td><strong><?php echo esc_html( $row->post_title ?: "Event #{$row->event_id}" ); ?></strong></td>
						<td><?php echo esc_html( $row->total ); ?></td>
						<td><span class="cem-badge cem-badge--green"><?php echo esc_html($row->confirmed); ?></span></td>
						<td><span class="cem-badge cem-badge--purple"><?php echo esc_html($row->checked_in); ?></span></td>
						<td><span class="cem-badge cem-badge--red"><?php echo esc_html($row->cancelled); ?></span></td>
						<td><span class="cem-badge cem-badge--blue"><?php echo esc_html($row->waitlisted); ?></span></td>
						<td>
							<div class="cem-progress-bar-wrap">
								<div class="cem-progress-bar" style="width:<?php echo esc_attr($attendance_pct); ?>%"></div>
							</div>
							<?php echo esc_html($attendance_pct); ?>%
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url('admin.php?page=cem-registrations&event_id=' . $row->event_id) ); ?>" class="button button-small">
								<?php esc_html_e('View','church-event-manager'); ?>
							</a>
							<a href="<?php echo esc_url( add_query_arg([
								'action'=>'cem_export_registrations',
								'event_id'=>$row->event_id,
								'nonce'=>wp_create_nonce('cem_export_nonce')
							], admin_url('admin-ajax.php') ) ); ?>" class="button button-small">
								⬇️
							</a>
						</td>
					</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Chart data (simple bar) -->
			<div class="cem-card">
				<h2><?php esc_html_e( 'Registrations — Last 30 Days', 'church-event-manager' ); ?></h2>
				<div class="cem-chart-wrap">
					<?php if ( empty( $per_day ) ) : ?>
					<p class="cem-muted"><?php esc_html_e('No data yet.','church-event-manager'); ?></p>
					<?php else :
						$max_count = max( array_column( $per_day, 'count' ) );
					?>
					<div class="cem-bar-chart">
						<?php foreach ( $per_day as $day ) :
							$pct = $max_count > 0 ? round(($day->count/$max_count)*100) : 0;
						?>
						<div class="cem-bar-wrap" title="<?php echo esc_attr("{$day->day}: {$day->count} registrations"); ?>">
							<div class="cem-bar" style="height:<?php echo esc_attr($pct); ?>%"></div>
							<div class="cem-bar-label"><?php echo esc_html( date('j/m', strtotime($day->day)) ); ?></div>
						</div>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	// ── Settings Page ─────────────────────────────────────────────────────────

	public function page_settings() {
		$tab = sanitize_key( $_GET['tab'] ?? 'general' );
		?>
		<div class="wrap cem-wrap">
			<h1><?php esc_html_e( 'Settings', 'church-event-manager' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<?php foreach ( [
					'general'      => __( 'General',      'church-event-manager' ),
					'email'        => __( 'Email',        'church-event-manager' ),
					'registration' => __( 'Registration', 'church-event-manager' ),
					'payments'     => __( '💳 Payments',  'church-event-manager' ),
					'pages'        => __( 'Pages',        'church-event-manager' ),
				] as $t => $label ) : ?>
				<a href="?page=cem-settings&tab=<?php echo esc_attr($t); ?>" class="nav-tab <?php echo $tab===$t ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html($label); ?>
				</a>
				<?php endforeach; ?>
			</nav>

			<div id="cem-settings-messages"></div>
			<form id="cem-settings-form" class="cem-card">

			<?php if ( $tab === 'general' ) : ?>
				<h2><?php esc_html_e( 'General Settings', 'church-event-manager' ); ?></h2>
				<table class="form-table">
					<?php $this->settings_row( 'cem_from_name',       __( 'Church Name',          'church-event-manager' ), 'text' ); ?>
					<?php $this->settings_row( 'cem_church_phone',    __( 'Church Phone',         'church-event-manager' ), 'text' ); ?>
					<?php $this->settings_row( 'cem_events_per_page', __( 'Events Per Page',      'church-event-manager' ), 'number' ); ?>
					<?php $this->settings_row( 'cem_date_format',     __( 'Date Format',          'church-event-manager' ), 'text', 'F j, Y' ); ?>
					<?php $this->settings_row( 'cem_time_format',     __( 'Time Format',          'church-event-manager' ), 'text', 'g:i a' ); ?>
					<?php $this->settings_row( 'cem_currency_symbol', __( 'Currency Symbol',      'church-event-manager' ), 'text', '$' ); ?>
					<tr>
						<th><label><?php esc_html_e( 'Accent Color', 'church-event-manager' ); ?></label></th>
						<td><input type="text" name="cem_accent_color" value="<?php echo esc_attr( get_option('cem_accent_color','#3b5998') ); ?>" class="cem-color-picker"></td>
					</tr>
				</table>

			<?php elseif ( $tab === 'email' ) : ?>
				<h2><?php esc_html_e( 'Email Settings', 'church-event-manager' ); ?></h2>
				<table class="form-table">
					<?php $this->settings_row( 'cem_from_name',            __( 'From Name',              'church-event-manager' ), 'text' ); ?>
					<?php $this->settings_row( 'cem_from_email',           __( 'From Email',             'church-event-manager' ), 'email' ); ?>
					<?php $this->settings_row( 'cem_reply_to_email',       __( 'Reply-To Email',         'church-event-manager' ), 'email' ); ?>
					<?php $this->settings_row( 'cem_admin_notify_email',   __( 'Admin Notify Email',     'church-event-manager' ), 'email' ); ?>
					<?php $this->settings_checkbox( 'cem_admin_notify_on_register', __( 'Notify admin on new registration', 'church-event-manager' ) ); ?>
					<?php $this->settings_row( 'cem_confirmation_subject', __( 'Confirmation Subject',   'church-event-manager' ), 'text' ); ?>
					<?php $this->settings_row( 'cem_reminder_subject',     __( 'Reminder Subject',       'church-event-manager' ), 'text' ); ?>
					<?php $this->settings_row( 'cem_cancellation_subject', __( 'Cancellation Subject',   'church-event-manager' ), 'text' ); ?>
					<?php $this->settings_checkbox( 'cem_send_reminders',  __( 'Enable reminder emails', 'church-event-manager' ) ); ?>
					<?php $this->settings_row( 'cem_reminder_days_before', __( 'Send reminder N days before event', 'church-event-manager' ), 'number' ); ?>
					<tr>
						<td colspan="2">
							<p class="description"><?php esc_html_e( 'For SMTP, install WP Mail SMTP or similar plugin.', 'church-event-manager' ); ?></p>
						</td>
					</tr>
				</table>

			<?php elseif ( $tab === 'registration' ) : ?>
				<h2><?php esc_html_e( 'Registration Settings', 'church-event-manager' ); ?></h2>
				<table class="form-table">
					<?php $this->settings_checkbox( 'cem_registration_auto_confirm', __( 'Auto-confirm registrations', 'church-event-manager' ) ); ?>
					<?php $this->settings_checkbox( 'cem_waitlist_enabled',          __( 'Enable waitlist',            'church-event-manager' ) ); ?>
					<?php $this->settings_checkbox( 'cem_allow_cancellations',       __( 'Allow front-end cancellations', 'church-event-manager' ) ); ?>
					<?php $this->settings_row( 'cem_cancellation_days_before',       __( 'Cut-off: cancel up to N days before event', 'church-event-manager' ), 'number' ); ?>
					<?php $this->settings_row( 'cem_capacity_alert_pct',             __( 'Capacity alert threshold (%)', 'church-event-manager' ), 'number' ); ?>
				</table>

			<?php elseif ( $tab === 'payments' ) : ?>
				<h2><?php esc_html_e( 'Payment Settings', 'church-event-manager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Collect payments for paid events using Stripe. Enter your API keys from the Stripe Dashboard (Developers → API keys).', 'church-event-manager' ); ?>
					<a href="https://dashboard.stripe.com/apikeys" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open Stripe Dashboard →', 'church-event-manager' ); ?></a>
				</p>
				<table class="form-table">
					<?php $this->settings_checkbox( 'cem_stripe_enabled', __( 'Enable Stripe payments', 'church-event-manager' ) ); ?>
					<?php $this->settings_checkbox( 'cem_stripe_test_mode', __( 'Test mode (use test API keys)', 'church-event-manager' ) ); ?>
					<tr>
						<th><label for="cem_stripe_publishable_key"><?php esc_html_e( 'Publishable Key', 'church-event-manager' ); ?></label></th>
						<td>
							<input type="text" id="cem_stripe_publishable_key" name="cem_stripe_publishable_key"
								value="<?php echo esc_attr( get_option( 'cem_stripe_publishable_key', '' ) ); ?>"
								class="large-text" placeholder="pk_live_… or pk_test_…" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Safe to expose — used by Stripe.js in the browser.', 'church-event-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="cem_stripe_secret_key"><?php esc_html_e( 'Secret Key', 'church-event-manager' ); ?></label></th>
						<td>
							<input type="password" id="cem_stripe_secret_key" name="cem_stripe_secret_key"
								value="<?php echo esc_attr( get_option( 'cem_stripe_secret_key', '' ) ); ?>"
								class="large-text" placeholder="sk_live_… or sk_test_…" autocomplete="off">
							<p class="description"><?php esc_html_e( 'Keep this private — never share or expose it publicly.', 'church-event-manager' ); ?></p>
						</td>
					</tr>
					<?php $this->settings_row( 'cem_stripe_currency', __( 'Currency (ISO code)', 'church-event-manager' ), 'text', 'usd' ); ?>
				</table>

				<?php
				$stripe_on  = get_option( 'cem_stripe_enabled' ) === '1';
				$has_keys   = get_option( 'cem_stripe_publishable_key' ) && get_option( 'cem_stripe_secret_key' );
				if ( $stripe_on && $has_keys ) : ?>
				<div class="notice notice-success inline"><p>
					✅ <?php esc_html_e( 'Stripe is active. Paid events will show a payment form on registration.', 'church-event-manager' ); ?>
				</p></div>
				<?php elseif ( $stripe_on ) : ?>
				<div class="notice notice-warning inline"><p>
					⚠️ <?php esc_html_e( 'Stripe is enabled but API keys are missing. Enter both keys above.', 'church-event-manager' ); ?>
				</p></div>
				<?php endif; ?>

				<?php if ( $has_keys ) : ?>
				<p>
					<button type="button" class="button" id="cem-test-stripe">
						<?php esc_html_e( 'Test Stripe Connection', 'church-event-manager' ); ?>
					</button>
					<span id="cem-stripe-test-result" style="margin-left:10px;"></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'Test card: 4242 4242 4242 4242, any future date, any CVC/ZIP. Both keys must be test or both must be live.', 'church-event-manager' ); ?>
				</p>
				<?php endif; ?>

			<?php elseif ( $tab === 'pages' ) : ?>
				<h2><?php esc_html_e( 'Page Assignments', 'church-event-manager' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Assign WordPress pages to plugin shortcode pages.', 'church-event-manager' ); ?></p>
				<table class="form-table">
					<tr>
						<th><label><?php esc_html_e( 'Events Listing Page', 'church-event-manager' ); ?></label></th>
						<td>
							<?php wp_dropdown_pages( [ 'name' => 'cem_events_page_id', 'selected' => get_option('cem_events_page_id'), 'show_option_none' => __('— Select —','church-event-manager'), 'option_none_value' => '' ] ); ?>
						</td>
					</tr>
					<tr>
						<th><label><?php esc_html_e( 'My Registrations Page', 'church-event-manager' ); ?></label></th>
						<td>
							<?php wp_dropdown_pages( [ 'name' => 'cem_my_registrations_page_id', 'selected' => get_option('cem_my_registrations_page_id'), 'show_option_none' => __('— Select —','church-event-manager'), 'option_none_value' => '' ] ); ?>
						</td>
					</tr>
				</table>
			<?php endif; ?>

				<p>
					<button type="button" class="button button-primary" id="cem-save-settings">
						<?php esc_html_e( 'Save Settings', 'church-event-manager' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	// ── Settings helpers ─────────────────────────────────────────────────────

	private function settings_row( $option, $label, $type = 'text', $placeholder = '' ) {
		$value = get_option( $option, '' );
		?>
		<tr>
			<th><label for="<?php echo esc_attr($option); ?>"><?php echo esc_html($label); ?></label></th>
			<td>
				<input type="<?php echo esc_attr($type); ?>" id="<?php echo esc_attr($option); ?>"
					name="<?php echo esc_attr($option); ?>"
					value="<?php echo esc_attr($value); ?>"
					class="<?php echo $type === 'text' || $type === 'email' ? 'large-text' : 'small-text'; ?>"
					placeholder="<?php echo esc_attr($placeholder); ?>">
			</td>
		</tr>
		<?php
	}

	private function settings_checkbox( $option, $label ) {
		$value = get_option( $option, '0' );
		?>
		<tr>
			<th><label><?php echo esc_html($label); ?></label></th>
			<td><label><input type="checkbox" name="<?php echo esc_attr($option); ?>" value="1" <?php checked($value,'1'); ?>> <?php esc_html_e('Enabled','church-event-manager'); ?></label></td>
		</tr>
		<?php
	}

	// ─────────────────────────────────────────────────────────────────────────
	// EVENT META BOXES
	// ─────────────────────────────────────────────────────────────────────────

	public function add_meta_boxes() {
		add_meta_box( 'cem_event_details', __( 'Event Details',       'church-event-manager' ), [ $this, 'mb_event_details'    ], 'cem_event', 'normal',   'high' );
		add_meta_box( 'cem_event_reg',     __( 'Registration Settings','church-event-manager' ), [ $this, 'mb_event_reg'       ], 'cem_event', 'normal',   'default' );
		add_meta_box( 'cem_event_sidebar', __( 'Quick Stats',         'church-event-manager' ), [ $this, 'mb_event_sidebar'   ], 'cem_event', 'side',     'default' );
	}

	public function mb_event_details( $post ) {
		wp_nonce_field( 'cem_save_event_meta', 'cem_event_meta_nonce' );
		$fields = [
			'_cem_start_datetime'           => get_post_meta( $post->ID, '_cem_start_datetime', true ),
			'_cem_end_datetime'             => get_post_meta( $post->ID, '_cem_end_datetime',   true ),
			'_cem_location'                 => get_post_meta( $post->ID, '_cem_location',       true ),
			'_cem_location_address'         => get_post_meta( $post->ID, '_cem_location_address', true ),
			'_cem_location_url'             => get_post_meta( $post->ID, '_cem_location_url',   true ),
			'_cem_organizer'                => get_post_meta( $post->ID, '_cem_organizer',      true ),
			'_cem_organizer_email'          => get_post_meta( $post->ID, '_cem_organizer_email',true ),
			'_cem_price'                    => get_post_meta( $post->ID, '_cem_price',          true ),
			'_cem_event_status'             => get_post_meta( $post->ID, '_cem_event_status',   true ),
			'_cem_online_event'             => get_post_meta( $post->ID, '_cem_online_event',   true ),
			'_cem_stream_url'               => get_post_meta( $post->ID, '_cem_stream_url',     true ),
			'_cem_allow_inperson'           => get_post_meta( $post->ID, '_cem_allow_inperson', true ),
			// Recurring event fields
			'_cem_is_recurring'             => get_post_meta( $post->ID, '_cem_is_recurring',            true ),
			'_cem_recurrence_frequency'     => get_post_meta( $post->ID, '_cem_recurrence_frequency',    true ) ?: 'weekly',
			'_cem_recurrence_days'          => get_post_meta( $post->ID, '_cem_recurrence_days',         true ) ?: '[]',
			'_cem_recurrence_month_by'      => get_post_meta( $post->ID, '_cem_recurrence_month_by',     true ) ?: 'dayofmonth',
			'_cem_recurrence_month_date'    => get_post_meta( $post->ID, '_cem_recurrence_month_date',   true ) ?: '',
			'_cem_recurrence_month_week'    => get_post_meta( $post->ID, '_cem_recurrence_month_week',   true ) ?: '1',
			'_cem_recurrence_month_dow'     => get_post_meta( $post->ID, '_cem_recurrence_month_dow',    true ) ?: '0',
			'_cem_recurrence_end_date'      => get_post_meta( $post->ID, '_cem_recurrence_end_date',     true ),
			'_cem_recurrence_hide_future'   => get_post_meta( $post->ID, '_cem_recurrence_hide_future',  true ),
			'_cem_is_recurrence_instance'   => get_post_meta( $post->ID, '_cem_is_recurrence_instance',  true ),
			'_cem_parent_event_id'          => get_post_meta( $post->ID, '_cem_parent_event_id',         true ),
		];
		?>
		<div class="cem-meta-grid">
			<div class="cem-meta-row">
				<label><?php esc_html_e('Start Date & Time','church-event-manager'); ?></label>
				<input type="datetime-local" name="_cem_start_datetime"
					value="<?php echo esc_attr( $fields['_cem_start_datetime'] ? date('Y-m-d\TH:i', strtotime($fields['_cem_start_datetime'])) : '' ); ?>">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('End Date & Time','church-event-manager'); ?></label>
				<input type="datetime-local" name="_cem_end_datetime"
					value="<?php echo esc_attr( $fields['_cem_end_datetime'] ? date('Y-m-d\TH:i', strtotime($fields['_cem_end_datetime'])) : '' ); ?>">
			</div>
			<div class="cem-meta-row cem-meta-full">
				<label><?php esc_html_e('Venue / Location Name','church-event-manager'); ?></label>
				<input type="text" name="_cem_location" value="<?php echo esc_attr($fields['_cem_location']); ?>" placeholder="e.g. Fellowship Hall">
			</div>
			<div class="cem-meta-row cem-meta-full">
				<label><?php esc_html_e('Street Address','church-event-manager'); ?></label>
				<input type="text" name="_cem_location_address" value="<?php echo esc_attr($fields['_cem_location_address']); ?>" placeholder="123 Church Street, City, ST 12345">
			</div>
			<div class="cem-meta-row cem-meta-full">
				<label><?php esc_html_e('Map/Directions URL','church-event-manager'); ?></label>
				<input type="url" name="_cem_location_url" value="<?php echo esc_attr($fields['_cem_location_url']); ?>" placeholder="https://maps.google.com/…">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Organizer / Contact','church-event-manager'); ?></label>
				<input type="text" name="_cem_organizer" value="<?php echo esc_attr($fields['_cem_organizer']); ?>" placeholder="e.g. Pastor John">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Organizer Email','church-event-manager'); ?></label>
				<input type="email" name="_cem_organizer_email" value="<?php echo esc_attr($fields['_cem_organizer_email']); ?>">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Price / Cost','church-event-manager'); ?></label>
				<input type="number" name="_cem_price" value="<?php echo esc_attr($fields['_cem_price']); ?>"
					min="0" step="0.01" placeholder="0.00"
					style="max-width:140px">
				<span class="description"><?php esc_html_e('Enter a number (e.g. 25.00). Leave blank to hide price, or enter 0 to show "Free".', 'church-event-manager'); ?></span>
			</div>
			<div class="cem-meta-row" id="cem-allow-inperson-row">
				<label>
					<input type="checkbox" name="_cem_allow_inperson" value="1" <?php checked( $fields['_cem_allow_inperson'], '1' ); ?>>
					<?php esc_html_e( 'Allow in-person registration (attendees can register without paying online)', 'church-event-manager' ); ?>
				</label>
				<span class="description"><?php esc_html_e( 'When checked, the Stripe payment form is hidden. Attendees register normally and pay at the door.', 'church-event-manager' ); ?></span>
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Event Status','church-event-manager'); ?></label>
				<select name="_cem_event_status">
					<option value=""         <?php selected($fields['_cem_event_status'],''); ?>><?php esc_html_e('Active','church-event-manager'); ?></option>
					<option value="cancelled"<?php selected($fields['_cem_event_status'],'cancelled'); ?>><?php esc_html_e('Cancelled','church-event-manager'); ?></option>
					<option value="postponed"<?php selected($fields['_cem_event_status'],'postponed'); ?>><?php esc_html_e('Postponed','church-event-manager'); ?></option>
				</select>
			</div>
			<div class="cem-meta-row">
				<label><input type="checkbox" name="_cem_online_event" value="1" <?php checked($fields['_cem_online_event'],'1'); ?>>
					<?php esc_html_e('Online / Virtual Event','church-event-manager'); ?></label>
			</div>
			<div class="cem-meta-row cem-meta-full" id="cem-stream-url-row" <?php echo $fields['_cem_online_event'] ? '' : 'style="display:none"'; ?>>
				<label><?php esc_html_e('Stream / Meeting URL','church-event-manager'); ?></label>
				<input type="url" name="_cem_stream_url" value="<?php echo esc_attr($fields['_cem_stream_url']); ?>" placeholder="https://zoom.us/j/…">
			</div>

			<?php
			$is_instance = $fields['_cem_is_recurrence_instance'] === '1';
			$parent_id   = (int) $fields['_cem_parent_event_id'];
			if ( $is_instance && $parent_id ) :
			?>
			<!-- Recurrence instance notice -->
			<div class="cem-meta-row cem-meta-full">
				<div class="cem-recurrence-instance-notice">
					<span class="dashicons dashicons-update"></span>
					<?php printf(
						/* translators: %s: link to parent event */
						esc_html__( 'This is a generated occurrence of a recurring event. Edit the %s to change recurrence settings.', 'church-event-manager' ),
						'<a href="' . esc_url( get_edit_post_link( $parent_id ) ) . '">' . esc_html__( 'parent event', 'church-event-manager' ) . '</a>'
					); ?>
				</div>
			</div>
			<?php else : ?>
			<!-- Recurring event options (only on parent events) -->
			<div class="cem-meta-row cem-meta-full cem-recurrence-toggle-row">
				<label>
					<input type="checkbox" name="_cem_is_recurring" value="1" id="cem-is-recurring"
						<?php checked( $fields['_cem_is_recurring'], '1' ); ?>>
					<strong><?php esc_html_e( 'This is a recurring event', 'church-event-manager' ); ?></strong>
				</label>
				<span class="description"><?php esc_html_e( 'Enable to automatically generate future occurrences.', 'church-event-manager' ); ?></span>
			</div>

			<div id="cem-recurrence-options" class="cem-recurrence-options" <?php echo $fields['_cem_is_recurring'] === '1' ? '' : 'style="display:none"'; ?>>
				<div class="cem-recurrence-panel">

					<!-- Frequency -->
					<div class="cem-meta-row">
						<label><?php esc_html_e( 'Repeat Frequency', 'church-event-manager' ); ?></label>
						<select name="_cem_recurrence_frequency" id="cem-recurrence-frequency">
							<option value="daily"   <?php selected( $fields['_cem_recurrence_frequency'], 'daily' ); ?>><?php esc_html_e( 'Daily', 'church-event-manager' ); ?></option>
							<option value="weekly"  <?php selected( $fields['_cem_recurrence_frequency'], 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'church-event-manager' ); ?></option>
							<option value="monthly" <?php selected( $fields['_cem_recurrence_frequency'], 'monthly' ); ?>><?php esc_html_e( 'Monthly', 'church-event-manager' ); ?></option>
						</select>
					</div>

					<!-- Weekly: days of week checkboxes -->
					<?php
					$saved_days = json_decode( $fields['_cem_recurrence_days'], true ) ?: [];
					$day_labels = [ 0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat' ];
					?>
					<div id="cem-weekly-options" class="cem-recurrence-sub-options"
						<?php echo $fields['_cem_recurrence_frequency'] === 'weekly' ? '' : 'style="display:none"'; ?>>
						<label class="cem-recurrence-sub-label"><?php esc_html_e( 'Repeat on:', 'church-event-manager' ); ?></label>
						<div class="cem-day-checkboxes">
							<?php foreach ( $day_labels as $num => $label ) : ?>
							<label class="cem-day-cb-label">
								<input type="checkbox" name="_cem_recurrence_days[]" value="<?php echo $num; ?>"
									<?php checked( in_array( $num, $saved_days, true ), true ); ?>>
								<?php echo esc_html( $label ); ?>
							</label>
							<?php endforeach; ?>
						</div>
					</div>

					<!-- Monthly options -->
					<div id="cem-monthly-options" class="cem-recurrence-sub-options"
						<?php echo $fields['_cem_recurrence_frequency'] === 'monthly' ? '' : 'style="display:none"'; ?>>
						<div class="cem-meta-row">
							<label><?php esc_html_e( 'Repeat by:', 'church-event-manager' ); ?></label>
							<select name="_cem_recurrence_month_by" id="cem-recurrence-month-by">
								<option value="dayofmonth" <?php selected( $fields['_cem_recurrence_month_by'], 'dayofmonth' ); ?>>
									<?php esc_html_e( 'Date of Month (e.g., every 15th)', 'church-event-manager' ); ?>
								</option>
								<option value="dayofweek" <?php selected( $fields['_cem_recurrence_month_by'], 'dayofweek' ); ?>>
									<?php esc_html_e( 'Day of Week (e.g., 2nd Sunday)', 'church-event-manager' ); ?>
								</option>
							</select>
						</div>

						<!-- By date of month -->
						<div id="cem-monthly-date-opts" <?php echo $fields['_cem_recurrence_month_by'] === 'dayofmonth' ? '' : 'style="display:none"'; ?>>
							<div class="cem-meta-row">
								<label><?php esc_html_e( 'Day of Month:', 'church-event-manager' ); ?></label>
								<input type="number" name="_cem_recurrence_month_date"
									value="<?php echo esc_attr( $fields['_cem_recurrence_month_date'] ); ?>"
									min="1" max="31" placeholder="e.g. 15" style="max-width:80px">
							</div>
						</div>

						<!-- By day of week -->
						<div id="cem-monthly-dow-opts" <?php echo $fields['_cem_recurrence_month_by'] === 'dayofweek' ? '' : 'style="display:none"'; ?>>
							<div class="cem-meta-row cem-meta-row--inline">
								<label><?php esc_html_e( 'Which week:', 'church-event-manager' ); ?></label>
								<select name="_cem_recurrence_month_week">
									<option value="1"  <?php selected( $fields['_cem_recurrence_month_week'], '1' ); ?>><?php esc_html_e( '1st', 'church-event-manager' ); ?></option>
									<option value="2"  <?php selected( $fields['_cem_recurrence_month_week'], '2' ); ?>><?php esc_html_e( '2nd', 'church-event-manager' ); ?></option>
									<option value="3"  <?php selected( $fields['_cem_recurrence_month_week'], '3' ); ?>><?php esc_html_e( '3rd', 'church-event-manager' ); ?></option>
									<option value="4"  <?php selected( $fields['_cem_recurrence_month_week'], '4' ); ?>><?php esc_html_e( '4th', 'church-event-manager' ); ?></option>
									<option value="-1" <?php selected( $fields['_cem_recurrence_month_week'], '-1' ); ?>><?php esc_html_e( 'Last', 'church-event-manager' ); ?></option>
								</select>
								<select name="_cem_recurrence_month_dow">
									<?php foreach ( $day_labels as $num => $label ) : ?>
									<option value="<?php echo $num; ?>" <?php selected( (string) $fields['_cem_recurrence_month_dow'], (string) $num ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
									<?php endforeach; ?>
								</select>
								<span class="description"><?php esc_html_e( 'of every month', 'church-event-manager' ); ?></span>
							</div>
						</div>
					</div><!-- /#cem-monthly-options -->

					<!-- End Date -->
					<div class="cem-meta-row">
						<label><?php esc_html_e( 'End Date (optional):', 'church-event-manager' ); ?></label>
						<input type="date" name="_cem_recurrence_end_date"
							value="<?php echo esc_attr( $fields['_cem_recurrence_end_date'] ); ?>">
						<span class="description"><?php esc_html_e( 'Leave blank to generate 12 months of occurrences.', 'church-event-manager' ); ?></span>
					</div>

					<!-- Hide future -->
					<div class="cem-meta-row cem-meta-full">
						<label>
							<input type="checkbox" name="_cem_recurrence_hide_future" value="1"
								<?php checked( $fields['_cem_recurrence_hide_future'], '1' ); ?>>
							<?php esc_html_e( 'Only show the next upcoming occurrence in event listings (hide future duplicates)', 'church-event-manager' ); ?>
						</label>
						<span class="description"><?php esc_html_e( 'When enabled, visitors only see the nearest upcoming date — not the same event listed weeks or months ahead.', 'church-event-manager' ); ?></span>
					</div>

				</div><!-- /.cem-recurrence-panel -->
			</div><!-- /#cem-recurrence-options -->
			<?php endif; // not an instance ?>
		</div>
		<script>
		jQuery('[name="_cem_online_event"]').on('change', function(){
			jQuery('#cem-stream-url-row').toggle(this.checked);
		});
		jQuery('#cem-is-recurring').on('change', function(){
			jQuery('#cem-recurrence-options').toggle(this.checked);
		});
		jQuery('#cem-recurrence-frequency').on('change', function(){
			var val = this.value;
			jQuery('#cem-weekly-options').toggle(val === 'weekly');
			jQuery('#cem-monthly-options').toggle(val === 'monthly');
		});
		jQuery('#cem-recurrence-month-by').on('change', function(){
			var val = this.value;
			jQuery('#cem-monthly-date-opts').toggle(val === 'dayofmonth');
			jQuery('#cem-monthly-dow-opts').toggle(val === 'dayofweek');
		});
		</script>
		<?php
	}

	public function mb_event_reg( $post ) {
		$cap      = get_post_meta( $post->ID, '_cem_capacity', true );
		$deadline = get_post_meta( $post->ID, '_cem_registration_deadline', true );
		$max_pp   = get_post_meta( $post->ID, '_cem_max_attendees_per_reg', true );
		$reg_st   = get_post_meta( $post->ID, '_cem_registration_status', true );
		?>
		<div class="cem-meta-grid">
			<div class="cem-meta-row">
				<label><?php esc_html_e('Max Registrations (0 = unlimited)','church-event-manager'); ?></label>
				<input type="number" name="_cem_capacity" value="<?php echo esc_attr($cap); ?>" min="0" placeholder="0">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Max Attendees Per Registration','church-event-manager'); ?></label>
				<input type="number" name="_cem_max_attendees_per_reg" value="<?php echo esc_attr($max_pp ?: 1); ?>" min="1">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Registration Deadline','church-event-manager'); ?></label>
				<input type="datetime-local" name="_cem_registration_deadline"
					value="<?php echo esc_attr($deadline ? date('Y-m-d\TH:i', strtotime($deadline)) : ''); ?>">
			</div>
			<div class="cem-meta-row">
				<label><?php esc_html_e('Registration Status','church-event-manager'); ?></label>
				<select name="_cem_registration_status">
					<option value="open"   <?php selected($reg_st,'open'); ?>><?php esc_html_e('Open','church-event-manager'); ?></option>
					<option value="closed" <?php selected($reg_st,'closed'); ?>><?php esc_html_e('Closed','church-event-manager'); ?></option>
				</select>
			</div>
		</div>
		<?php
	}

	public function mb_event_sidebar( $post ) {
		if ( ! $post->ID ) return;
		$taken    = CEM_Helpers::get_registration_count( $post->ID );
		$capacity = (int) get_post_meta( $post->ID, '_cem_capacity', true );
		$waitlist = CEM_Registration::get_waitlist_count( $post->ID );
		?>
		<div class="cem-sidebar-stats">
			<div class="cem-ss-row"><span><?php esc_html_e('Registrations','church-event-manager'); ?></span><strong><?php echo esc_html($taken); ?></strong></div>
			<?php if ( $capacity > 0 ) : ?>
			<div class="cem-ss-row"><span><?php esc_html_e('Capacity','church-event-manager'); ?></span><strong><?php echo esc_html($capacity); ?></strong></div>
			<div class="cem-ss-row"><span><?php esc_html_e('Spots Left','church-event-manager'); ?></span><strong><?php echo esc_html( max(0,$capacity-$taken) ); ?></strong></div>
			<?php endif; ?>
			<div class="cem-ss-row"><span><?php esc_html_e('Waitlisted','church-event-manager'); ?></span><strong><?php echo esc_html($waitlist); ?></strong></div>
		</div>
		<p>
			<a href="<?php echo esc_url( admin_url('admin.php?page=cem-registrations&event_id=' . $post->ID) ); ?>" class="button button-small button-primary" style="width:100%;text-align:center;margin-top:8px">
				<?php esc_html_e('View Registrations','church-event-manager'); ?>
			</a>
		</p>
		<?php
	}

	// ── Save Event Meta ───────────────────────────────────────────────────────

	public function save_event_meta( $post_id ) {
		if ( ! isset( $_POST['cem_event_meta_nonce'] ) ) return;
		if ( ! wp_verify_nonce( $_POST['cem_event_meta_nonce'], 'cem_save_event_meta' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
		if ( ! current_user_can( 'edit_post', $post_id ) ) return;

		$datetime_fields = [ '_cem_start_datetime', '_cem_end_datetime', '_cem_registration_deadline' ];
		$text_fields     = [ '_cem_location', '_cem_location_address', '_cem_organizer', '_cem_organizer_email', '_cem_event_status', '_cem_registration_status' ];
		$url_fields      = [ '_cem_location_url', '_cem_stream_url' ];
		$number_fields   = [ '_cem_capacity', '_cem_max_attendees_per_reg' ];
		$checkbox_fields = [ '_cem_online_event', '_cem_allow_inperson' ];

		foreach ( $datetime_fields as $key ) {
			if ( isset( $_POST[$key] ) && $_POST[$key] !== '' ) {
				update_post_meta( $post_id, $key, date( 'Y-m-d H:i:s', strtotime( sanitize_text_field( $_POST[$key] ) ) ) );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
		foreach ( $text_fields   as $key ) update_post_meta( $post_id, $key, sanitize_text_field( $_POST[$key] ?? '' ) );
		foreach ( $url_fields    as $key ) update_post_meta( $post_id, $key, esc_url_raw( $_POST[$key] ?? '' ) );
		foreach ( $number_fields as $key ) update_post_meta( $post_id, $key, (int) ( $_POST[$key] ?? 0 ) );

		// Price is stored as a decimal string ("25.00") so (float) casts work correctly in templates.
		// Empty string means "no price shown"; "0.00" means "Free".
		$raw_price = trim( $_POST['_cem_price'] ?? '' );
		if ( $raw_price !== '' ) {
			update_post_meta( $post_id, '_cem_price', number_format( (float) $raw_price, 2, '.', '' ) );
		} else {
			update_post_meta( $post_id, '_cem_price', '' );
		}

		foreach ( $checkbox_fields as $key ) {
			update_post_meta( $post_id, $key, ! empty( $_POST[$key] ) ? '1' : '0' );
		}

		// ── Recurring Event ────────────────────────────────────────────────────
		// Only process recurrence on parent events (not on generated instances).
		$is_instance = get_post_meta( $post_id, '_cem_is_recurrence_instance', true ) === '1';
		if ( ! $is_instance ) {
			$is_recurring = ! empty( $_POST['_cem_is_recurring'] ) ? '1' : '0';
			update_post_meta( $post_id, '_cem_is_recurring', $is_recurring );

			if ( $is_recurring === '1' ) {
				$freq = sanitize_text_field( $_POST['_cem_recurrence_frequency'] ?? 'weekly' );
				if ( ! in_array( $freq, [ 'daily', 'weekly', 'monthly' ], true ) ) $freq = 'weekly';
				update_post_meta( $post_id, '_cem_recurrence_frequency', $freq );

				// Weekly: days array (0=Sun … 6=Sat)
				$days_raw = isset( $_POST['_cem_recurrence_days'] ) && is_array( $_POST['_cem_recurrence_days'] )
					? array_map( 'intval', $_POST['_cem_recurrence_days'] )
					: [];
				update_post_meta( $post_id, '_cem_recurrence_days', wp_json_encode( $days_raw ) );

				// Monthly: by type
				$month_by = sanitize_text_field( $_POST['_cem_recurrence_month_by'] ?? 'dayofmonth' );
				if ( ! in_array( $month_by, [ 'dayofmonth', 'dayofweek' ], true ) ) $month_by = 'dayofmonth';
				update_post_meta( $post_id, '_cem_recurrence_month_by', $month_by );
				update_post_meta( $post_id, '_cem_recurrence_month_date', (int) ( $_POST['_cem_recurrence_month_date'] ?? 1 ) );
				update_post_meta( $post_id, '_cem_recurrence_month_week', sanitize_text_field( $_POST['_cem_recurrence_month_week'] ?? '1' ) );
				update_post_meta( $post_id, '_cem_recurrence_month_dow', (int) ( $_POST['_cem_recurrence_month_dow'] ?? 0 ) );

				// End date
				$end_raw = sanitize_text_field( $_POST['_cem_recurrence_end_date'] ?? '' );
				update_post_meta( $post_id, '_cem_recurrence_end_date', $end_raw );

				// Hide future toggle
				update_post_meta( $post_id, '_cem_recurrence_hide_future', ! empty( $_POST['_cem_recurrence_hide_future'] ) ? '1' : '0' );

				// Generate instances in a shutdown callback to avoid timing issues
				// with the post not yet being fully saved.
				add_action( 'shutdown', function() use ( $post_id ) {
					$this->generate_recurrence_instances( $post_id );
				} );
			} else {
				// Recurring was turned off — delete the flag but leave existing instances.
				update_post_meta( $post_id, '_cem_is_recurring', '0' );
			}
		}
	}

	// ── Recurring event: generate child posts ──────────────────────────────────

	private function generate_recurrence_instances( $event_id ) {
		// Ensure a group ID exists to link all instances to this parent.
		$group_id = get_post_meta( $event_id, '_cem_recurrence_group_id', true );
		if ( ! $group_id ) {
			$group_id = 'cemgrp_' . substr( md5( $event_id . microtime() ), 0, 12 );
			update_post_meta( $event_id, '_cem_recurrence_group_id', $group_id );
		}

		// Delete all existing generated instances for this parent.
		$existing = get_posts( [
			'post_type'      => 'cem_event',
			'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'AND',
				[ 'key' => '_cem_parent_event_id',      'value' => $event_id, 'compare' => '='  ],
				[ 'key' => '_cem_is_recurrence_instance','value' => '1',       'compare' => '='  ],
			],
		] );
		foreach ( $existing as $inst_id ) {
			wp_delete_post( $inst_id, true ); // bypass trash
		}

		// Calculate occurrence dates.
		$occurrences = $this->calculate_recurrence_occurrences( $event_id );
		if ( empty( $occurrences ) ) return;

		$base_post    = get_post( $event_id );
		$base_start   = get_post_meta( $event_id, '_cem_start_datetime', true );
		$base_end     = get_post_meta( $event_id, '_cem_end_datetime',   true );
		$all_meta     = get_post_meta( $event_id );

		// Meta keys NOT to copy to instances (controlled per-instance or parent-only).
		$skip_meta = [
			'_cem_is_recurring', '_cem_is_recurrence_instance', '_cem_parent_event_id',
			'_cem_recurrence_group_id', '_cem_start_datetime', '_cem_end_datetime',
			'_cem_recurrence_frequency', '_cem_recurrence_days', '_cem_recurrence_month_by',
			'_cem_recurrence_month_date', '_cem_recurrence_month_week', '_cem_recurrence_month_dow',
			'_cem_recurrence_end_date', '_cem_recurrence_hide_future',
		];

		$taxonomies    = get_object_taxonomies( 'cem_event' );
		$thumbnail_id  = get_post_thumbnail_id( $event_id );

		$skip_first = true; // First occurrence = the parent event itself.
		foreach ( $occurrences as $occ ) {
			if ( $skip_first ) { $skip_first = false; continue; }

			$new_id = wp_insert_post( [
				'post_type'    => 'cem_event',
				'post_status'  => $base_post->post_status,
				'post_title'   => $base_post->post_title,
				'post_content' => $base_post->post_content,
				'post_excerpt' => $base_post->post_excerpt,
				'post_author'  => $base_post->post_author,
			], true );

			if ( is_wp_error( $new_id ) ) continue;

			// Copy parent meta.
			foreach ( $all_meta as $key => $values ) {
				if ( in_array( $key, $skip_meta, true ) ) continue;
				update_post_meta( $new_id, $key, maybe_unserialize( $values[0] ) );
			}

			// Set occurrence-specific meta.
			update_post_meta( $new_id, '_cem_start_datetime',        $occ['start'] );
			update_post_meta( $new_id, '_cem_end_datetime',          $occ['end'] );
			update_post_meta( $new_id, '_cem_is_recurrence_instance','1' );
			update_post_meta( $new_id, '_cem_parent_event_id',       $event_id );
			update_post_meta( $new_id, '_cem_recurrence_group_id',   $group_id );
			update_post_meta( $new_id, '_cem_is_recurring',          '0' );

			// Copy taxonomies and featured image.
			foreach ( $taxonomies as $tax ) {
				$terms = wp_get_object_terms( $event_id, $tax, [ 'fields' => 'ids' ] );
				if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
					wp_set_object_terms( $new_id, $terms, $tax );
				}
			}
			if ( $thumbnail_id ) {
				set_post_thumbnail( $new_id, $thumbnail_id );
			}
		}
	}

	// ── Recurring event: calculate occurrence dates ────────────────────────────

	private function calculate_recurrence_occurrences( $event_id ) {
		$freq       = get_post_meta( $event_id, '_cem_recurrence_frequency', true );
		$start_dt   = get_post_meta( $event_id, '_cem_start_datetime', true );
		$end_dt     = get_post_meta( $event_id, '_cem_end_datetime',   true );
		$end_date   = get_post_meta( $event_id, '_cem_recurrence_end_date', true );

		if ( ! $start_dt || ! $freq ) return [];

		$start_ts   = strtotime( $start_dt );
		$duration   = $end_dt ? ( strtotime( $end_dt ) - $start_ts ) : 3600; // fallback: 1 hour
		$time_str   = date( 'H:i:s', $start_ts );
		$max_end_ts = $end_date ? strtotime( $end_date . ' 23:59:59' ) : strtotime( '+12 months', $start_ts );
		$limit      = 300; // hard safety cap

		$occurrences = [];

		// ── Daily ─────────────────────────────────────────────────────────────
		if ( $freq === 'daily' ) {
			$cur = $start_ts;
			while ( $cur <= $max_end_ts && count( $occurrences ) < $limit ) {
				$occ_ts = strtotime( date( 'Y-m-d', $cur ) . ' ' . $time_str );
				$occurrences[] = [
					'start' => date( 'Y-m-d H:i:s', $occ_ts ),
					'end'   => $end_dt ? date( 'Y-m-d H:i:s', $occ_ts + $duration ) : '',
				];
				$cur = strtotime( '+1 day', $cur );
			}

		// ── Weekly ────────────────────────────────────────────────────────────
		} elseif ( $freq === 'weekly' ) {
			$days = json_decode( get_post_meta( $event_id, '_cem_recurrence_days', true ) ?: '[]', true );
			if ( empty( $days ) ) {
				$days = [ (int) date( 'w', $start_ts ) ]; // default: day of start date
			}
			sort( $days );

			$cur = strtotime( date( 'Y-m-d', $start_ts ) ); // midnight of start date
			while ( $cur <= $max_end_ts && count( $occurrences ) < $limit ) {
				$dow = (int) date( 'w', $cur );
				if ( in_array( $dow, $days, true ) ) {
					$occ_ts = strtotime( date( 'Y-m-d', $cur ) . ' ' . $time_str );
					if ( $occ_ts >= $start_ts ) { // skip anything before start datetime
						$occurrences[] = [
							'start' => date( 'Y-m-d H:i:s', $occ_ts ),
							'end'   => $end_dt ? date( 'Y-m-d H:i:s', $occ_ts + $duration ) : '',
						];
					}
				}
				$cur = strtotime( '+1 day', $cur );
			}

		// ── Monthly ───────────────────────────────────────────────────────────
		} elseif ( $freq === 'monthly' ) {
			$month_by   = get_post_meta( $event_id, '_cem_recurrence_month_by', true ) ?: 'dayofmonth';
			$cur_month  = (int) date( 'n', $start_ts );
			$cur_year   = (int) date( 'Y', $start_ts );
			$dow_names  = [ 'Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday' ];

			while ( count( $occurrences ) < $limit ) {
				$occ_ts = false;

				if ( $month_by === 'dayofmonth' ) {
					$target_day   = (int) ( get_post_meta( $event_id, '_cem_recurrence_month_date', true ) ?: date( 'j', $start_ts ) );
					$days_in_mon  = (int) date( 't', mktime( 0, 0, 0, $cur_month, 1, $cur_year ) );
					$actual_day   = min( $target_day, $days_in_mon );
					$occ_ts       = strtotime( sprintf( '%04d-%02d-%02d %s', $cur_year, $cur_month, $actual_day, $time_str ) );

				} else { // dayofweek
					$week  = (int) ( get_post_meta( $event_id, '_cem_recurrence_month_week', true ) ?: 1 );
					$dow   = (int) ( get_post_meta( $event_id, '_cem_recurrence_month_dow',  true ) ?: 0 );
					$dname = $dow_names[ $dow ];

					if ( $week === -1 ) {
						$occ_ts = strtotime( "last {$dname} of {$cur_year}-{$cur_month}" );
					} else {
						$ordinals = [ '', 'first', 'second', 'third', 'fourth' ];
						$ord      = $ordinals[ $week ] ?? 'first';
						$occ_ts   = strtotime( "{$ord} {$dname} of {$cur_year}-{$cur_month}" );
					}
					if ( $occ_ts ) {
						$occ_ts = strtotime( date( 'Y-m-d', $occ_ts ) . ' ' . $time_str );
					}
				}

				if ( ! $occ_ts || $occ_ts > $max_end_ts ) break;

				if ( $occ_ts >= $start_ts ) {
					$occurrences[] = [
						'start' => date( 'Y-m-d H:i:s', $occ_ts ),
						'end'   => $end_dt ? date( 'Y-m-d H:i:s', $occ_ts + $duration ) : '',
					];
				}

				$cur_month++;
				if ( $cur_month > 12 ) { $cur_month = 1; $cur_year++; }
			}
		}

		return $occurrences;
	}

	// ── Support Page ──────────────────────────────────────────────────────────

	public function page_support() {
		$current_user = wp_get_current_user();
		$site_url     = get_site_url();
		$wp_version   = get_bloginfo( 'wpversion' );
		$php_version  = phpversion();
		$theme        = wp_get_theme();
		?>
		<div class="wrap cem-wrap">
			<h1><?php esc_html_e( 'Support', 'church-event-manager' ); ?></h1>
			<hr class="wp-header-end">

			<div class="cem-support-layout">

				<!-- ── Ticket Form ─────────────────────────────────────── -->
				<div class="cem-support-form-wrap">
					<div class="cem-dashboard-card">
						<h2><?php esc_html_e( 'Submit a Support Ticket', 'church-event-manager' ); ?></h2>
						<p class="cem-muted">
							<?php esc_html_e( 'Describe your issue or question and our team at White Oak Media LLC will get back to you as soon as possible.', 'church-event-manager' ); ?>
						</p>

						<div id="cem-ticket-messages"></div>

						<form id="cem-ticket-form" class="cem-form" novalidate>
							<?php wp_nonce_field( 'cem_ticket_nonce', 'cem_ticket_nonce_field' ); ?>

							<div class="cem-form-row cem-form-row-2">
								<div class="cem-field">
									<label for="cem_ticket_name"><?php esc_html_e( 'Your Name', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
									<input type="text" id="cem_ticket_name" name="ticket_name" required
										value="<?php echo esc_attr( $current_user->display_name ); ?>">
								</div>
								<div class="cem-field">
									<label for="cem_ticket_email"><?php esc_html_e( 'Your Email', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
									<input type="email" id="cem_ticket_email" name="ticket_email" required
										value="<?php echo esc_attr( $current_user->user_email ); ?>">
								</div>
							</div>

							<div class="cem-form-row cem-form-row-2">
								<div class="cem-field">
									<label for="cem_ticket_type"><?php esc_html_e( 'Request Type', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
									<select id="cem_ticket_type" name="ticket_type" required>
										<option value=""><?php esc_html_e( '— Select —', 'church-event-manager' ); ?></option>
										<option value="Bug Report"><?php esc_html_e( '🐛 Bug Report', 'church-event-manager' ); ?></option>
										<option value="Feature Request"><?php esc_html_e( '💡 Feature Request', 'church-event-manager' ); ?></option>
										<option value="General Help"><?php esc_html_e( '🙋 General Help', 'church-event-manager' ); ?></option>
										<option value="Other"><?php esc_html_e( '📋 Other', 'church-event-manager' ); ?></option>
									</select>
								</div>
								<div class="cem-field">
									<label for="cem_ticket_subject"><?php esc_html_e( 'Subject', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
									<input type="text" id="cem_ticket_subject" name="ticket_subject" required
										placeholder="<?php esc_attr_e( 'Brief summary of your issue', 'church-event-manager' ); ?>">
								</div>
							</div>

							<div class="cem-field">
								<label for="cem_ticket_description"><?php esc_html_e( 'Description', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
								<textarea id="cem_ticket_description" name="ticket_description" rows="7" required
									placeholder="<?php esc_attr_e( 'Please describe the issue in detail. Include steps to reproduce if it is a bug.', 'church-event-manager' ); ?>"></textarea>
							</div>

							<div class="cem-field cem-field-checkbox">
								<label>
									<input type="checkbox" name="ticket_include_sysinfo" value="1" checked>
									<?php esc_html_e( 'Include system information (recommended — helps us diagnose issues faster)', 'church-event-manager' ); ?>
								</label>
							</div>

							<div class="cem-form-submit" style="margin-top:20px">
								<button type="submit" class="button button-primary button-large" id="cem-ticket-submit">
									📨 <?php esc_html_e( 'Send to Support Team', 'church-event-manager' ); ?>
								</button>
							</div>
						</form>
					</div>
				</div>

				<!-- ── Contact Info Sidebar ────────────────────────────── -->
				<div class="cem-support-sidebar">

					<!-- White Oak Media LLC Card -->
					<div class="cem-dashboard-card cem-wom-card">
						<div class="cem-wom-logo">🌳</div>
						<h2>White Oak Media LLC</h2>
						<p class="cem-muted"><?php esc_html_e( 'Church Event Manager is developed and maintained by White Oak Media LLC.', 'church-event-manager' ); ?></p>
						<ul class="cem-support-links">
							<li><a href="mailto:zach@whiteoakmedia.io">✉️ zach@whiteoakmedia.io</a></li>
							<li><a href="https://whiteoakmedia.io" target="_blank" rel="noopener noreferrer">🌐 whiteoakmedia.io</a></li>
						</ul>
					</div>

					<!-- System Info Card -->
					<div class="cem-dashboard-card">
						<h3><?php esc_html_e( 'System Information', 'church-event-manager' ); ?></h3>
						<table class="cem-sysinfo-table">
							<tr><th><?php esc_html_e( 'Plugin Version', 'church-event-manager' ); ?></th><td><?php echo esc_html( CEM_VERSION ); ?></td></tr>
							<tr><th><?php esc_html_e( 'WordPress', 'church-event-manager' ); ?></th><td><?php echo esc_html( $wp_version ); ?></td></tr>
							<tr><th><?php esc_html_e( 'PHP', 'church-event-manager' ); ?></th><td><?php echo esc_html( $php_version ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Active Theme', 'church-event-manager' ); ?></th><td><?php echo esc_html( $theme->get('Name') . ' ' . $theme->get('Version') ); ?></td></tr>
							<tr><th><?php esc_html_e( 'Site URL', 'church-event-manager' ); ?></th><td><?php echo esc_html( $site_url ); ?></td></tr>
						</table>
					</div>

				</div><!-- /.cem-support-sidebar -->
			</div><!-- /.cem-support-layout -->
		</div>

		<script>
		(function($){
			$('#cem-ticket-form').on('submit', function(e){
				e.preventDefault();
				var $btn  = $('#cem-ticket-submit');
				var $msgs = $('#cem-ticket-messages');
				$msgs.html('');
				$btn.prop('disabled', true).text('<?php echo esc_js( __( 'Sending…', 'church-event-manager' ) ); ?>');

				$.post(ajaxurl, {
					action:              'cem_submit_ticket',
					nonce:               $('#cem_ticket_nonce_field').val(),
					ticket_name:         $('#cem_ticket_name').val(),
					ticket_email:        $('#cem_ticket_email').val(),
					ticket_type:         $('#cem_ticket_type').val(),
					ticket_subject:      $('#cem_ticket_subject').val(),
					ticket_description:  $('#cem_ticket_description').val(),
					ticket_include_sysinfo: $('[name="ticket_include_sysinfo"]').is(':checked') ? '1' : '0',
				})
				.done(function(res){
					if ( res.success ) {
						$msgs.html('<div class="cem-notice cem-notice-success" style="margin-bottom:16px">' + res.data.message + '</div>');
						$('#cem-ticket-form')[0].reset();
						// Re-populate name/email after reset
						$('#cem_ticket_name').val('<?php echo esc_js( $current_user->display_name ); ?>');
						$('#cem_ticket_email').val('<?php echo esc_js( $current_user->user_email ); ?>');
					} else {
						$msgs.html('<div class="cem-notice cem-notice-error" style="margin-bottom:16px">' + (res.data.message || '<?php echo esc_js( __( 'An error occurred. Please try again.', 'church-event-manager' ) ); ?>') + '</div>');
					}
				})
				.fail(function(){
					$msgs.html('<div class="cem-notice cem-notice-error" style="margin-bottom:16px"><?php echo esc_js( __( 'Could not reach the server. Please check your connection and try again.', 'church-event-manager' ) ); ?></div>');
				})
				.always(function(){
					$btn.prop('disabled', false).html('📨 <?php echo esc_js( __( 'Send to Support Team', 'church-event-manager' ) ); ?>');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

}
