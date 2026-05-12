<?php
/**
 * All public-facing shortcodes.
 *
 * [cem_events]                    – Event listing with filters
 * [cem_groups]                    – Event Series listing grid/list
 * [cem_registration_form]         – Registration form for a specific event
 * [cem_my_registrations]          – Registrant's own history / manage
 * [cem_event_calendar]            – Simple calendar grid (coming events)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Shortcodes {

	public function register() {
		add_shortcode( 'cem_events',            [ $this, 'events_list' ] );
		add_shortcode( 'cem_groups',            [ $this, 'groups_list' ] );
		add_shortcode( 'cem_registration_form', [ $this, 'registration_form' ] );
		add_shortcode( 'cem_my_registrations',  [ $this, 'my_registrations' ] );
		add_shortcode( 'cem_event_calendar',    [ $this, 'event_calendar' ] );
	}

	// ── [cem_events] ──────────────────────────────────────────────────────────

	public function events_list( $atts ) {
		$atts = shortcode_atts( [
			'category'   => '',
			'ministry'   => '',
			'per_page'   => get_option( 'cem_events_per_page', 10 ),
			'show_past'  => 'no',
			'show_filter'=> 'yes',
			'layout'     => 'grid', // grid | list
		], $atts );

		// Current filter from URL
		$cat_filter = sanitize_text_field( $_GET['cem_category'] ?? $atts['category'] );
		$min_filter = sanitize_text_field( $_GET['cem_ministry'] ?? $atts['ministry'] );
		$page       = max( 1, (int) ( $_GET['cem_pg'] ?? 1 ) );

		$query_args = [
			'post_type'      => 'cem_event',
			// Explicit array of ONLY 'publish'. Some third-party plugins
			// (SEO / security) inject filters that expand the status set;
			// passing an array makes intent explicit and easier to audit.
			'post_status'    => [ 'publish' ],
			'posts_per_page' => (int) $atts['per_page'],
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cem_start_datetime',
			'order'          => 'ASC',
			'tax_query'      => [],
			'meta_query'     => [],
		];

		if ( $atts['show_past'] !== 'yes' ) {
			// Keep events visible until they have fully ended, not just started.
			// Logic: show if (end_datetime is set AND >= now) OR (no end_datetime AND start_datetime >= now).
			// This prevents events from disappearing from the public list the moment they begin.
			//
			// IMPORTANT: events that never had an end time saved have no
			// `_cem_end_datetime` meta row at all (not just empty string), so
			// the "no end time" branch must also match `NOT EXISTS`.
			// Without it, those events get filtered out entirely — which is
			// what caused 2 of 8 upcoming events to silently disappear from
			// the public listing.
			$now = current_time( 'mysql' );
			$query_args['meta_query'][] = [
				'relation' => 'OR',
				// Has an end time and the event hasn't finished yet
				[
					'key'     => '_cem_end_datetime',
					'value'   => $now,
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
				// No end time stored — match "row missing" OR "row is empty"
				// — and only show if the start time is still in the future.
				[
					'relation' => 'AND',
					[
						'relation' => 'OR',
						[
							'key'     => '_cem_end_datetime',
							'compare' => 'NOT EXISTS',
						],
						[
							'key'     => '_cem_end_datetime',
							'value'   => '',
							'compare' => '=',
						],
					],
					[
						'key'     => '_cem_start_datetime',
						'value'   => $now,
						'compare' => '>=',
						'type'    => 'DATETIME',
					],
				],
			];
		}

		if ( $cat_filter ) {
			$query_args['tax_query'][] = [
				'taxonomy' => 'cem_event_category',
				'field'    => 'slug',
				'terms'    => $cat_filter,
			];
		}

		if ( $min_filter ) {
			$query_args['tax_query'][] = [
				'taxonomy' => 'cem_ministry',
				'field'    => 'slug',
				'terms'    => $min_filter,
			];
		}

		$query = new WP_Query( $query_args );

		// ── Defensive filter: only render truly-published, non-past events ─────
		// Belt-and-suspenders. The query above already restricts to published
		// + future, but third-party query filters, recurrence-instance
		// regenerators, or object-cache weirdness have surfaced trashed
		// events on live sites before. This loop walks every returned post
		// and drops anything that doesn't actually pass the rules at render
		// time. A second authoritative check, not a redundant one.
		if ( $query->have_posts() ) {
			$hide_past   = ( $atts['show_past'] !== 'yes' );
			$now_ts      = strtotime( current_time( 'mysql' ) );
			$keep        = [];
			foreach ( $query->posts as $p ) {
				if ( ! $p || empty( $p->ID ) ) continue;

				// Authoritative status check from the actual posts table.
				if ( get_post_status( $p->ID ) !== 'publish' ) continue;

				if ( $hide_past ) {
					$end_dt   = get_post_meta( $p->ID, '_cem_end_datetime',   true );
					$start_dt = get_post_meta( $p->ID, '_cem_start_datetime', true );
					$cutoff   = $end_dt ?: $start_dt;
					if ( $cutoff && strtotime( $cutoff ) < $now_ts ) continue;
				}

				$keep[] = $p;
			}
			$query->posts      = array_values( $keep );
			$query->post_count = count( $query->posts );
			$query->found_posts = count( $query->posts );
		}

		// ── Recurring: filter out "hidden" future duplicates ────────────────────
		// When a recurrence group has _cem_recurrence_hide_future = '1', only the
		// FIRST upcoming instance (sorted ASC by start date) should appear.
		// Since WP_Query already orders by start ASC, the first time we see a group
		// we keep it; subsequent ones are skipped.
		if ( $query->have_posts() ) {
			$seen_groups   = [];
			$filtered_ids  = [];
			foreach ( $query->posts as $p ) {
				$group_id = get_post_meta( $p->ID, '_cem_recurrence_group_id', true );
				if ( $group_id ) {
					// Resolve hide_future from parent for instances, or from self for parents.
					$parent_id   = (int) get_post_meta( $p->ID, '_cem_parent_event_id', true );
					$source_id   = $parent_id ?: $p->ID;
					$hide_future = get_post_meta( $source_id, '_cem_recurrence_hide_future', true ) === '1';

					if ( $hide_future ) {
						if ( isset( $seen_groups[ $group_id ] ) ) continue; // skip duplicate
						$seen_groups[ $group_id ] = true;
					}
				}
				$filtered_ids[] = $p->ID;
			}
			// Replace posts with filtered list.
			$query->posts      = array_values( array_filter(
				$query->posts,
				fn( $p ) => in_array( $p->ID, $filtered_ids, true )
			) );
			$query->post_count = count( $query->posts );
		}

		ob_start();
		?>
		<div class="cem-events-wrap">

			<?php if ( $atts['show_filter'] === 'yes' ) : ?>
			<form class="cem-filter-form" method="get" action="">
				<div class="cem-filter-row">
					<select name="cem_category" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Events', 'church-event-manager' ); ?></option>
						<?php
						// hide_empty=false: always show all categories so "All Events" is never
						// the only option and categories with future events aren't silently hidden.
						$cats = get_terms( [ 'taxonomy' => 'cem_event_category', 'hide_empty' => false ] );
						foreach ( $cats as $cat ) :
						?>
						<option value="<?php echo esc_attr( $cat->slug ); ?>"
							<?php selected( $cat_filter, $cat->slug ); ?>>
							<?php echo esc_html( $cat->name ); ?>
						</option>
						<?php endforeach; ?>
					</select>

					<select name="cem_ministry" onchange="this.form.submit()">
						<option value=""><?php esc_html_e( 'All Ministries', 'church-event-manager' ); ?></option>
						<?php
						$mins = get_terms( [ 'taxonomy' => 'cem_ministry', 'hide_empty' => true ] );
						foreach ( $mins as $min ) :
						?>
						<option value="<?php echo esc_attr( $min->slug ); ?>"
							<?php selected( $min_filter, $min->slug ); ?>>
							<?php echo esc_html( $min->name ); ?>
						</option>
						<?php endforeach; ?>
					</select>

					<?php if ( $cat_filter || $min_filter ) : ?>
					<a href="<?php echo esc_url( remove_query_arg( [ 'cem_category', 'cem_ministry', 'cem_pg' ] ) ); ?>" class="cem-clear-filter">
						<?php esc_html_e( '✕ Clear Filters', 'church-event-manager' ); ?>
					</a>
					<?php endif; ?>
				</div>
			</form>
			<?php endif; ?>

			<?php if ( ! $query->have_posts() ) : ?>
			<div class="cem-no-events">
				<div class="cem-no-events-icon">📅</div>
				<h3><?php esc_html_e( 'No upcoming events found.', 'church-event-manager' ); ?></h3>
				<p><?php esc_html_e( 'Check back soon for new events!', 'church-event-manager' ); ?></p>
			</div>
			<?php else : ?>

			<div class="cem-events-<?php echo esc_attr( $atts['layout'] ); ?>">
				<?php while ( $query->have_posts() ) : $query->the_post();
					$event_id    = get_the_ID();

					// Final, last-line-of-defense check. Even if a stale
					// recurrence instance, an aggressive cache layer, or
					// a third-party query filter managed to slip a non-
					// published or past event past the query AND the
					// post-query filter above, skip rendering its card.
					if ( get_post_status( $event_id ) !== 'publish' ) continue;
					if ( $atts['show_past'] !== 'yes' ) {
						$_end   = get_post_meta( $event_id, '_cem_end_datetime',   true );
						$_start = get_post_meta( $event_id, '_cem_start_datetime', true );
						$_cut   = $_end ?: $_start;
						if ( $_cut && strtotime( $_cut ) < current_time( 'timestamp' ) ) continue;
					}

					$start       = get_post_meta( $event_id, '_cem_start_datetime', true );
					$end         = get_post_meta( $event_id, '_cem_end_datetime', true );
					$location    = get_post_meta( $event_id, '_cem_location', true );
					$capacity    = (int) get_post_meta( $event_id, '_cem_capacity', true );
					$reg_status  = get_post_meta( $event_id, '_cem_registration_status', true );
					$taken       = CEM_Helpers::get_registration_count( $event_id );
					$full        = CEM_Helpers::is_at_capacity( $event_id );
					$categories  = get_the_terms( $event_id, 'cem_event_category' );
					$thumbnail   = get_the_post_thumbnail_url( $event_id, 'large' );
					$price       = get_post_meta( $event_id, '_cem_price', true );
					// Recurring badge: show on parent events AND generated instances.
					$is_recurring = get_post_meta( $event_id, '_cem_is_recurring', true ) === '1'
					             || get_post_meta( $event_id, '_cem_is_recurrence_instance', true ) === '1';
				?>
				<article class="cem-event-card <?php echo $full ? 'cem-event-full' : ''; ?>">
					<?php if ( $thumbnail ) : ?>
					<div class="cem-event-image">
						<a href="<?php the_permalink(); ?>">
							<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php the_title_attribute(); ?>">
						</a>
						<?php if ( $categories && ! is_wp_error( $categories ) ) : ?>
						<div class="cem-event-cats">
							<?php foreach ( $categories as $cat ) : ?>
							<span class="cem-event-cat"><?php echo esc_html( $cat->name ); ?></span>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<div class="cem-event-body">
						<?php
						// Multi-day detection — start and end land on different
						// calendar days. Multi-day events render a compact
						// horizontal chip above the title (inside .cem-event-info,
						// see below). Single-day events keep the existing big
						// vertical badge in the left badge column.
						$start_ts      = $start ? strtotime( $start ) : 0;
						$end_ts        = $end   ? strtotime( $end )   : 0;
						$is_multi_day  = ( $start_ts && $end_ts
							&& date_i18n( 'Y-m-d', $start_ts ) !== date_i18n( 'Y-m-d', $end_ts ) );

						// Build the range label here so we can render it inside
						// the info column further down. Year shown on the end
						// always; year on the start only when years differ.
						$range_label = '';
						if ( $is_multi_day ) {
							$same_year = date_i18n( 'Y', $start_ts ) === date_i18n( 'Y', $end_ts );
							$range_label = $same_year
								? date_i18n( 'M j', $start_ts ) . ' → ' . date_i18n( 'M j, Y', $end_ts )
								: date_i18n( 'M j, Y', $start_ts ) . ' → ' . date_i18n( 'M j, Y', $end_ts );
						}
						?>
						<?php if ( $start && ! $is_multi_day ) : ?>
							<div class="cem-event-date-wrap">
								<div class="cem-event-date-badge">
									<span class="cem-date-month"><?php echo esc_html( date_i18n( 'M', $start_ts ) ); ?></span>
									<span class="cem-date-day"><?php echo esc_html( date_i18n( 'j', $start_ts ) ); ?></span>
								</div>
							</div>
						<?php endif; ?>

						<div class="cem-event-info">
							<?php if ( $is_multi_day && $range_label ) : ?>
							<div class="cem-event-date-range"><?php echo esc_html( $range_label ); ?></div>
							<?php endif; ?>

							<h3 class="cem-event-title">
								<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
								<?php if ( $is_recurring ) : ?>
								<span class="cem-recurring-badge" title="<?php esc_attr_e( 'Recurring Event', 'church-event-manager' ); ?>">🔁 <?php esc_html_e( 'Recurring', 'church-event-manager' ); ?></span>
								<?php endif; ?>
							</h3>

							<div class="cem-event-meta">
								<?php if ( $start ) : ?>
								<span class="cem-meta-item cem-meta-time">
									🕐 <?php echo esc_html( CEM_Helpers::format_time( $start ) ); ?>
									<?php if ( $end ) echo '– ' . esc_html( CEM_Helpers::format_time( $end ) ); ?>
								</span>
								<?php endif; ?>

								<?php if ( $location ) : ?>
								<span class="cem-meta-item cem-meta-location">
									📍 <?php echo esc_html( $location ); ?>
								</span>
								<?php endif; ?>

								<?php if ( $price !== '' ) :
									$price_num        = (float) $price;
									$currency         = get_option( 'cem_currency_symbol', '$' );
									$price_card_label = ( $price_num === 0.0 )
										? __( 'Free', 'church-event-manager' )
										: $currency . number_format( $price_num, 2 );
								?>
								<span class="cem-meta-item cem-meta-price<?php echo ( $price_num === 0.0 ) ? ' cem-meta-price--free' : ''; ?>">
									💰 <?php echo esc_html( $price_card_label ); ?>
								</span>
								<?php endif; ?>

								<?php if ( $capacity > 0 ) : ?>
								<span class="cem-meta-item cem-meta-capacity <?php echo $full ? 'cem-capacity-full' : ''; ?>">
									👥 <?php echo $full
										? esc_html__( 'Event Full', 'church-event-manager' )
										: sprintf( esc_html__( '%d spots remaining', 'church-event-manager' ), max(0, $capacity - $taken) ); ?>
								</span>
								<?php endif; ?>
							</div>

							<?php if ( has_excerpt() ) : ?>
							<p class="cem-event-excerpt"><?php the_excerpt(); ?></p>
							<?php endif; ?>

							<div class="cem-event-actions">
								<a href="<?php the_permalink(); ?>" class="cem-btn cem-btn-primary">
									<?php esc_html_e( 'Learn More', 'church-event-manager' ); ?>
								</a>
							</div>
						</div>
					</div>
				</article>
				<?php endwhile; ?>
			</div>

			<?php
			// Pagination
			$total_pages = $query->max_num_pages;
			if ( $total_pages > 1 ) :
				$base_url = remove_query_arg( 'cem_pg' );
			?>
			<div class="cem-pagination">
				<?php if ( $page > 1 ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'cem_pg', $page - 1, $base_url ) ); ?>" class="cem-page-btn">← <?php esc_html_e( 'Previous', 'church-event-manager' ); ?></a>
				<?php endif; ?>
				<span class="cem-page-info">
					<?php printf( esc_html__( 'Page %d of %d', 'church-event-manager' ), $page, $total_pages ); ?>
				</span>
				<?php if ( $page < $total_pages ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'cem_pg', $page + 1, $base_url ) ); ?>" class="cem-page-btn"><?php esc_html_e( 'Next', 'church-event-manager' ); ?> →</a>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<?php endif; // have_posts ?>
		</div>
		<?php
		wp_reset_postdata();
		return ob_get_clean();
	}

	// ── [cem_groups] ─────────────────────────────────────────────────────────

	public function groups_list( $atts ) {
		$atts = shortcode_atts( [
			'layout'      => 'grid',  // grid | list
			'per_page'    => 12,
			'type'        => '',      // bible-study, prayer, mens, womens, etc.
			'status'      => 'open',  // open | closed | full | inactive | '' = all
			'category'    => '',      // group category slug
			'show_filter' => 'yes',   // Show filter dropdowns
		], $atts );

		// URL overrides for filters
		$type_filter = sanitize_key( $_GET['cem_group_type'] ?? $atts['type'] );
		$cat_filter  = sanitize_text_field( $_GET['cem_group_cat'] ?? $atts['category'] );
		$day_filter  = sanitize_text_field( $_GET['cem_group_day'] ?? '' );
		$search      = sanitize_text_field( $_GET['cem_group_search'] ?? '' );

		$meta_query = [ 'relation' => 'AND' ];

		if ( $type_filter ) {
			$meta_query[] = [
				'key'     => '_cem_group_type',
				'value'   => $type_filter,
				'compare' => '=',
			];
		}
		if ( $atts['status'] ) {
			$meta_query[] = [
				'key'     => '_cem_group_status',
				'value'   => sanitize_key( $atts['status'] ),
				'compare' => '=',
			];
		}
		if ( $day_filter ) {
			$meta_query[] = [
				'key'     => '_cem_group_day',
				'value'   => $day_filter,
				'compare' => '=',
			];
		}

		$query_args = [
			'post_type'      => 'cem_group',
			'post_status'    => [ 'publish' ],
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => count( $meta_query ) > 1 ? $meta_query : [],
		];

		if ( $cat_filter ) {
			$query_args['tax_query'] = [ [
				'taxonomy' => 'cem_group_category',
				'field'    => 'slug',
				'terms'    => $cat_filter,
			] ];
		}

		if ( $search ) {
			$query_args['s'] = $search;
		}

		$query = new WP_Query( $query_args );

		$group_types = CEM_Group::group_types();
		$layout      = in_array( $atts['layout'], [ 'grid', 'list' ], true ) ? $atts['layout'] : 'grid';
		$status_labels = [
			'open'     => __( 'Open',     'church-event-manager' ),
			'closed'   => __( 'Closed',   'church-event-manager' ),
			'full'     => __( 'Full',     'church-event-manager' ),
			'inactive' => __( 'Inactive', 'church-event-manager' ),
		];

		ob_start();
		?>
		<div class="cem-groups-wrap">

		<?php if ( $atts['show_filter'] === 'yes' ) : ?>
		<form class="cem-filter-form cem-groups-filter" method="get" action="">
			<div class="cem-filter-row">
				<select name="cem_group_type" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All Types', 'church-event-manager' ); ?></option>
					<?php foreach ( $group_types as $val => $label ) :
						if ( ! $val ) continue; ?>
					<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type_filter, $val ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
					<?php endforeach; ?>
				</select>

				<select name="cem_group_day" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'Any Day', 'church-event-manager' ); ?></option>
					<?php foreach ( [ 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ] as $d ) : ?>
					<option value="<?php echo esc_attr( $d ); ?>" <?php selected( $day_filter, $d ); ?>>
						<?php echo esc_html( $d ); ?>
					</option>
					<?php endforeach; ?>
				</select>

				<?php
				$cats = get_terms( [ 'taxonomy' => 'cem_group_category', 'hide_empty' => true ] );
				if ( ! empty( $cats ) && ! is_wp_error( $cats ) ) : ?>
				<select name="cem_group_cat" onchange="this.form.submit()">
					<option value=""><?php esc_html_e( 'All Categories', 'church-event-manager' ); ?></option>
					<?php foreach ( $cats as $cat ) : ?>
					<option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $cat_filter, $cat->slug ); ?>>
						<?php echo esc_html( $cat->name ); ?>
					</option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>

				<?php if ( $type_filter || $cat_filter || $day_filter ) : ?>
				<a href="<?php echo esc_url( remove_query_arg( [ 'cem_group_type', 'cem_group_cat', 'cem_group_day' ] ) ); ?>" class="cem-clear-filter">
					<?php esc_html_e( '✕ Clear', 'church-event-manager' ); ?>
				</a>
				<?php endif; ?>
			</div>
		</form>
		<?php endif; ?>

		<?php if ( ! $query->have_posts() ) : ?>
			<div class="cem-no-events">
				<div class="cem-no-events-icon">👥</div>
				<h3><?php esc_html_e( 'No groups found.', 'church-event-manager' ); ?></h3>
				<p><?php esc_html_e( 'Try adjusting your filters or check back soon for new groups!', 'church-event-manager' ); ?></p>
			</div>
		<?php else : ?>

		<div class="cem-events-grid cem-groups-<?php echo esc_attr( $layout ); ?>">
		<?php
		while ( $query->have_posts() ) {
			$query->the_post();
			$id         = get_the_ID();
			$type        = get_post_meta( $id, '_cem_group_type',        true );
			$location    = get_post_meta( $id, '_cem_group_location',    true );
			$leader      = get_post_meta( $id, '_cem_group_leader',      true );
			$status      = get_post_meta( $id, '_cem_group_status',      true ) ?: 'open';
			$capacity    = (int) get_post_meta( $id, '_cem_group_capacity', true );
			$description = get_post_meta( $id, '_cem_group_description', true );
			$members    = $capacity > 0 ? CEM_Group::get_signup_count( $id ) : 0;
			$childcare  = get_post_meta( $id, '_cem_group_childcare', true ) === '1';
			$online     = get_post_meta( $id, '_cem_group_online',    true ) === '1';

			$type_label    = $group_types[ $type ] ?? '';
			$status_label  = $status_labels[ $status ] ?? ucfirst( $status );

			// Multi-meeting support — render every meeting time the group has,
			// not just the first one. Walking groups and similar can list 3+
			// times. format_meeting_entry handles the legacy fallback.
			$meeting_times  = CEM_Group::get_meeting_times( $id );
			$schedule_lines = array_values( array_filter( array_map( function( $entry ) {
				return CEM_Group::format_meeting_entry( $entry );
			}, $meeting_times ) ) );
			$thumb         = has_post_thumbnail() ? get_the_post_thumbnail( $id, 'medium', [ 'class' => 'cem-card-img' ] ) : '';
			$type_to_ph    = [
				'bible-study'  => 'amber',  'prayer'       => 'indigo',
				'mens'         => 'navy',   'womens'       => 'rose',
				'couples'      => 'rose',   'young-adults' => 'teal',
				'youth'        => 'teal',   'seniors'      => 'slate',
				'outreach'     => 'forest', 'recovery'     => 'amber',
				'other'        => 'slate',  ''             => 'slate',
			];
			$ph_color      = $type_to_ph[ $type ] ?? 'slate';
			$initial       = mb_strtoupper( mb_substr( get_the_title(), 0, 1 ) );
			?>
			<article class="cem-event-card cem-group-card">
				<?php if ( $thumb ) : ?>
				<a href="<?php the_permalink(); ?>" class="cem-card-img-wrap"><?php echo $thumb; ?></a>
				<?php else : ?>
				<a href="<?php the_permalink(); ?>" class="cem-card-img-wrap cem-card-placeholder cem-ph--<?php echo esc_attr( $ph_color ); ?>"></a>
				<?php endif; ?>
				<div class="cem-card-body">
					<div class="cem-card-meta">
						<?php if ( $type_label ) : ?>
						<span class="cem-badge cem-group-type-badge"><?php echo esc_html( $type_label ); ?></span>
						<?php endif; ?>
						<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
						<?php if ( $childcare ) : ?>
						<span class="cem-badge cem-badge--childcare"><?php esc_html_e( 'Childcare', 'church-event-manager' ); ?></span>
						<?php endif; ?>
						<?php if ( $online ) : ?>
						<span class="cem-badge cem-badge--online"><?php esc_html_e( 'Online', 'church-event-manager' ); ?></span>
						<?php endif; ?>
					</div>
					<div class="cem-card-title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</div>
					<?php
					// Show the dedicated description field, falling back to excerpt
					$card_desc = $description ?: ( has_excerpt() ? get_the_excerpt() : '' );
					if ( $card_desc ) : ?>
					<p class="cem-card-excerpt"><?php echo esc_html( wp_trim_words( $card_desc, 20 ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $schedule_lines ) ) : ?>
					<p class="cem-card-date">🗓
						<?php
						// One row → inline. Multiple rows → list with separators.
						if ( count( $schedule_lines ) === 1 ) {
							echo esc_html( $schedule_lines[0] );
						} else {
							echo '<span class="cem-card-schedule-list">';
							foreach ( $schedule_lines as $line ) {
								echo '<span>' . esc_html( $line ) . '</span>';
							}
							echo '</span>';
						}
						?>
					</p>
					<?php endif; ?>
					<?php if ( $location ) : ?>
					<p class="cem-card-location">📍 <?php echo esc_html( $location ); ?></p>
					<?php endif; ?>
					<?php if ( $leader ) : ?>
					<p class="cem-card-leader">👤 <?php echo esc_html( $leader ); ?></p>
					<?php endif; ?>
					<?php if ( $capacity > 0 ) : ?>
					<p class="cem-card-capacity">
						<?php printf(
							esc_html__( '%1$d / %2$d spots filled', 'church-event-manager' ),
							$members, $capacity
						); ?>
					</p>
					<?php endif; ?>
					<div class="cem-card-actions">
						<a href="<?php the_permalink(); ?>" class="cem-btn <?php echo $status === 'open' ? 'cem-btn-primary' : 'cem-btn-secondary'; ?>">
							<?php echo $status === 'open'
								? esc_html__( 'Join Group', 'church-event-manager' )
								: esc_html__( 'Learn More', 'church-event-manager' ); ?>
						</a>
					</div>
				</div>
			</article>
			<?php
		}
		wp_reset_postdata();
		echo '</div>';
		endif; // have_posts
		echo '</div><!-- .cem-groups-wrap -->';
		return ob_get_clean();
	}

	// ── [cem_registration_form] ───────────────────────────────────────────────

	public function registration_form( $atts ) {
		$atts = shortcode_atts( [
			'event_id' => get_the_ID(),
		], $atts );

		$event_id = (int) $atts['event_id'];
		if ( ! $event_id ) return '';

		$event = get_post( $event_id );
		if ( ! $event || $event->post_type !== 'cem_event' ) return '';

		// Check if registration is enabled for this event
		$reg_enabled = get_post_meta( $event_id, '_cem_registration_enabled', true );
		if ( $reg_enabled === '0' ) return '';

		$reg_status = get_post_meta( $event_id, '_cem_registration_status', true );
		$deadline   = get_post_meta( $event_id, '_cem_registration_deadline', true );
		$full       = CEM_Helpers::is_at_capacity( $event_id );
		$waitlist   = get_option( 'cem_waitlist_enabled', '1' );
		$custom_fields = CEM_Custom_Fields::get_fields( $event_id );

		// ── Registration types / pricing tiers ───────────────────────────────────
		$reg_types_json = get_post_meta( $event_id, '_cem_registration_types', true );
		$reg_types      = $reg_types_json ? json_decode( $reg_types_json, true ) : [];
		$has_reg_types  = ! empty( $reg_types );

		// ── Payment detection ────────────────────────────────────────────────────
		$event_price      = get_post_meta( $event_id, '_cem_price', true );
		$price_num        = ( $event_price !== '' ) ? (float) $event_price : 0.0;

		// If registration types exist, determine max price for Stripe loading
		if ( $has_reg_types ) {
			$max_type_price = max( array_column( $reg_types, 'price' ) );
			$price_num = (float) $max_type_price;
		}
		$stripe_enabled   = get_option( 'cem_stripe_enabled', '0' ) === '1';
		$stripe_pub_key   = get_option( 'cem_stripe_publishable_key', '' );
		$allow_inperson   = get_post_meta( $event_id, '_cem_allow_inperson', true ) === '1';
		$currency_symbol  = get_option( 'cem_currency_symbol', '$' );
		$price_display    = $currency_symbol . number_format( $price_num, 2 );

		// Online payment: price > 0, Stripe configured, in-person bypass NOT set.
		$payment_required = ( $price_num > 0 ) && $stripe_enabled && ! empty( $stripe_pub_key ) && ! $allow_inperson && ! $full;

		// In-person payment: price > 0, in-person bypass IS set — no Stripe needed.
		$inperson_payment = ( $price_num > 0 ) && $allow_inperson && ! $full;

		// Ensure Stripe scripts are loaded even when this shortcode is used on a
		// non-singular-cem_event page (e.g. a regular Page or custom template).
		// CEM_Public::enqueue_stripe_for_event() deduplicates via wp_enqueue_script.
		if ( $payment_required ) {
			CEM_Public::enqueue_stripe_for_event( $event_id );
		}

		ob_start();

		if ( $reg_status === 'closed' || ( $deadline && strtotime( $deadline ) < time() ) ) {
			echo '<div class="cem-notice cem-notice-warning">' . esc_html__( 'Registration for this event is closed.', 'church-event-manager' ) . '</div>';
			return ob_get_clean();
		}

		if ( $full && $waitlist ) {
			$button_label = __( 'Join Waitlist', 'church-event-manager' );
		} elseif ( $payment_required ) {
			/* translators: %s: formatted price e.g. "$25.00" */
			$button_label = sprintf( __( 'Pay %s & Register', 'church-event-manager' ), $price_display );
		} elseif ( $inperson_payment ) {
			/* translators: %s: formatted price e.g. "$25.00" */
			$button_label = sprintf( __( 'Register (Pay %s at Door)', 'church-event-manager' ), $price_display );
		} else {
			$button_label = __( 'Register Now', 'church-event-manager' );
		}

		?>
		<div class="cem-registration-wrap" id="cem-registration-<?php echo $event_id; ?>">

			<?php if ( $full && $waitlist ) : ?>
			<div class="cem-notice cem-notice-info">
				<?php esc_html_e( 'This event is full, but you can join the waitlist. You will be notified if a spot becomes available.', 'church-event-manager' ); ?>
			</div>
			<?php elseif ( $full && ! $waitlist ) : ?>
			<div class="cem-notice cem-notice-warning">
				<?php esc_html_e( 'This event is full. Registration is no longer available.', 'church-event-manager' ); ?>
			</div>
			<?php return ob_get_clean(); ?>
			<?php endif; ?>

			<div id="cem-form-messages"></div>

			<form class="cem-form" id="cem-registration-form" novalidate
				data-needs-payment="<?php echo $payment_required ? '1' : '0'; ?>"
				data-event-id="<?php echo esc_attr( $event_id ); ?>">
				<?php wp_nonce_field( 'cem_register_nonce', 'cem_nonce' ); ?>
				<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>">
				<input type="hidden" name="payment_intent_id" id="cem-payment-intent-id" value="">

				<?php
				$max_attendees = get_post_meta( $event_id, '_cem_max_attendees_per_reg', true );
				$spots         = CEM_Helpers::get_spots_remaining( $event_id );
				$currency_sym  = get_option( 'cem_currency_symbol', '$' );
			?>

				<?php if ( $has_reg_types ) :
				// Batch-load sold counts per registration type for this event.
				global $wpdb;
				$type_sold_raw = $wpdb->get_results( $wpdb->prepare(
					"SELECT m.meta_value AS type_name, COUNT(*) AS sold
					 FROM {$wpdb->prefix}cem_registration_meta m
					 INNER JOIN {$wpdb->prefix}cem_registrations r ON r.id = m.registration_id
					 WHERE r.event_id = %d AND m.meta_key = '_registration_type'
					   AND r.status IN ('confirmed','pending','checked_in','waitlisted')
					 GROUP BY m.meta_value",
					$event_id
				) );
				$type_sold_counts = [];
				foreach ( $type_sold_raw as $row ) {
					$type_sold_counts[ $row->type_name ] = (int) $row->sold;
				}
			?>
				<!-- Registration Type / Pricing Tier Selection -->
				<div class="cem-form-section">
					<h3 class="cem-section-title"><?php esc_html_e( 'Select Registration Type', 'church-event-manager' ); ?></h3>
					<div class="cem-reg-type-options" id="cem-reg-type-options">
						<?php foreach ( $reg_types as $i => $rt ) :
							$rt_price     = (float) $rt['price'];
							$rt_cap       = (int) ( $rt['capacity'] ?? 0 );
							$rt_price_lbl = $rt_price > 0 ? $currency_sym . number_format( $rt_price, 2 ) : __( 'Free', 'church-event-manager' );
							$rt_sold      = $type_sold_counts[ $rt['name'] ] ?? 0;
							$rt_avail     = ( $rt_cap > 0 ) ? max( 0, $rt_cap - $rt_sold ) : null;
							$rt_disabled  = ( $rt_cap > 0 && $rt_avail <= 0 );
						?>
						<label class="cem-reg-type-option <?php echo $rt_disabled ? 'cem-reg-type-disabled' : ''; ?>"
							<?php echo $rt_disabled ? 'title="' . esc_attr__( 'Sold out', 'church-event-manager' ) . '"' : ''; ?>>
							<input type="radio" name="registration_type_index" value="<?php echo esc_attr( $i ); ?>"
								data-price="<?php echo esc_attr( $rt_price ); ?>"
								data-name="<?php echo esc_attr( $rt['name'] ); ?>"
								<?php echo $i === 0 && ! $rt_disabled ? 'checked' : ''; ?>
								<?php echo $rt_disabled ? 'disabled' : ''; ?>>
							<span class="cem-reg-type-info">
								<span class="cem-reg-type-name"><?php echo esc_html( $rt['name'] ); ?></span>
								<?php if ( ! empty( $rt['description'] ) ) : ?>
								<span class="cem-reg-type-desc"><?php echo esc_html( $rt['description'] ); ?></span>
								<?php endif; ?>
								<?php if ( $rt_cap > 0 && $rt_avail !== null ) : ?>
								<span class="cem-reg-type-avail">
									<?php echo $rt_disabled
										? esc_html__( 'Sold Out', 'church-event-manager' )
										: sprintf( esc_html__( '%d spots left', 'church-event-manager' ), $rt_avail ); ?>
								</span>
								<?php endif; ?>
							</span>
							<span class="cem-reg-type-price"><?php echo esc_html( $rt_price_lbl ); ?></span>
						</label>
						<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>

				<div class="cem-form-section">
					<h3 class="cem-section-title"><?php esc_html_e( 'Your Information', 'church-event-manager' ); ?></h3>

					<div class="cem-form-row cem-form-row-2">
						<div class="cem-field">
							<label for="cem_first_name"><?php esc_html_e( 'First Name', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
							<input type="text" id="cem_first_name" name="first_name" required autocomplete="given-name">
						</div>
						<div class="cem-field">
							<label for="cem_last_name"><?php esc_html_e( 'Last Name', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
							<input type="text" id="cem_last_name" name="last_name" required autocomplete="family-name">
						</div>
					</div>

					<div class="cem-form-row cem-form-row-2">
						<div class="cem-field">
							<label for="cem_email"><?php esc_html_e( 'Email Address', 'church-event-manager' ); ?> <span class="cem-required">*</span></label>
							<input type="email" id="cem_email" name="email" required autocomplete="email">
						</div>
						<div class="cem-field">
							<label for="cem_phone"><?php esc_html_e( 'Phone Number', 'church-event-manager' ); ?></label>
							<input type="tel" id="cem_phone" name="phone" autocomplete="tel">
						</div>
					</div>

					<?php if ( (int) $max_attendees !== 1 ) : ?>
					<div class="cem-form-row">
						<div class="cem-field">
							<label for="cem_num_attendees"><?php esc_html_e( 'Number of Attendees', 'church-event-manager' ); ?></label>
							<input type="number" id="cem_num_attendees" name="num_attendees" value="1" min="1"
								<?php if ( $max_attendees ) echo 'max="' . esc_attr( $max_attendees ) . '"'; ?>
								<?php if ( $spots !== null ) echo 'max="' . esc_attr( min( $max_attendees ?: 99, $spots ) ) . '"'; ?>>
						</div>
					</div>
					<?php endif; ?>
				</div>

				<?php if ( ! empty( $custom_fields ) ) : ?>
				<div class="cem-form-section">
					<h3 class="cem-section-title"><?php esc_html_e( 'Additional Information', 'church-event-manager' ); ?></h3>
					<?php foreach ( $custom_fields as $field ) : ?>
					<?php CEM_Custom_Fields::render_field_html( $field ); ?>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="cem-form-section">
					<div class="cem-field">
						<label for="cem_notes"><?php esc_html_e( 'Notes or Special Requests (optional)', 'church-event-manager' ); ?></label>
						<textarea id="cem_notes" name="notes" rows="3"></textarea>
					</div>
				</div>

				<?php if ( $payment_required ) : ?>
				<div class="cem-form-section cem-payment-section">
					<h3 class="cem-section-title">
						<?php esc_html_e( 'Payment', 'church-event-manager' ); ?>
						<span class="cem-payment-amount"><?php echo esc_html( $price_display ); ?></span>
					</h3>
					<p class="cem-payment-intro">
						<?php esc_html_e( 'Your card will be charged securely via Stripe. We never store your card details.', 'church-event-manager' ); ?>
					</p>
					<div id="cem-stripe-element" class="cem-stripe-element">
						<div class="cem-stripe-loading">
							<span class="cem-stripe-loading__spinner"></span>
							<?php esc_html_e( 'Loading payment form…', 'church-event-manager' ); ?>
						</div>
					</div>
					<div id="cem-stripe-errors" class="cem-stripe-errors" role="alert"></div>
				</div>
				<?php elseif ( $inperson_payment ) : ?>
				<div class="cem-form-section cem-inperson-section">
					<div class="cem-inperson-notice">
						<span class="cem-inperson-notice__icon">💵</span>
						<div>
							<strong><?php echo esc_html( sprintf(
							    /* translators: %%s: formatted price */
							    __( 'Cost: %s — Pay at the Door', 'church-event-manager' ),
							    $price_display
							) ); ?></strong>
							<p><?php esc_html_e( 'No online payment required. Please bring payment to the event.', 'church-event-manager' ); ?></p>
						</div>
					</div>
				</div>
				<?php endif; ?>

				<div class="cem-form-submit">
					<button type="submit" class="cem-btn cem-btn-primary cem-btn-large" id="cem-submit-btn">
						<?php echo esc_html( $button_label ); ?>
					</button>
					<span class="cem-spinner" id="cem-spinner" style="display:none">⏳</span>
				</div>
			</form>

			<div id="cem-success-message" style="display:none" class="cem-notice cem-notice-success"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ── [cem_my_registrations] ────────────────────────────────────────────────

	/**
	 * Build the "your registrations" email body and dispatch it via CEM_Email.
	 * Shared between the public lookup form and the AJAX "email me a copy" button.
	 */
	public static function send_registration_list_email( $email, $regs ) {
		$rows = '';
		foreach ( $regs as $reg ) {
			$event      = get_post( $reg->event_id );
			$start      = get_post_meta( $reg->event_id, '_cem_start_datetime', true );
			$manage_url = CEM_Helpers::get_manage_url( $reg->registration_code );
			$title      = $event ? esc_html( $event->post_title ) : esc_html__( '(removed)', 'church-event-manager' );
			$date       = $start ? esc_html( CEM_Helpers::format_date( $start ) ) : '—';
			$status     = esc_html( ucfirst( $reg->status ) );
			$code       = esc_html( $reg->registration_code );
			$link       = '<a href="' . esc_url( $manage_url ) . '">' . esc_html__( 'View / Manage', 'church-event-manager' ) . '</a>';

			$rows .= "<tr style='border-bottom:1px solid #eee'>"
				. "<td style='padding:10px 8px'>$title</td>"
				. "<td style='padding:10px 8px'>$date</td>"
				. "<td style='padding:10px 8px'>$status</td>"
				. "<td style='padding:10px 8px'><code>$code</code></td>"
				. "<td style='padding:10px 8px'>$link</td>"
				. '</tr>';
		}

		$header_style = 'padding:8px;text-align:left;background:#f5f5f5;border-bottom:2px solid #ddd';
		$message = '<h2 style="margin-top:0">' . esc_html__( 'Your Registrations', 'church-event-manager' ) . '</h2>'
			. '<table style="width:100%;border-collapse:collapse;font-size:14px">'
			. '<thead><tr>'
			. "<th style='$header_style'>" . esc_html__( 'Event',  'church-event-manager' ) . '</th>'
			. "<th style='$header_style'>" . esc_html__( 'Date',   'church-event-manager' ) . '</th>'
			. "<th style='$header_style'>" . esc_html__( 'Status', 'church-event-manager' ) . '</th>'
			. "<th style='$header_style'>" . esc_html__( 'Code',   'church-event-manager' ) . '</th>'
			. "<th style='$header_style'>" . esc_html__( 'Link',   'church-event-manager' ) . '</th>'
			. '</tr></thead>'
			. "<tbody>$rows</tbody></table>"
			. '<p style="margin-top:16px;color:#555">'
			. esc_html__( 'Click "View / Manage" to update your details or cancel a registration.', 'church-event-manager' )
			. '</p>';

		return CEM_Email::send( [
			'to_email' => $email,
			'subject'  => sprintf(
				/* translators: %s: site name */
				__( 'Your Registrations at %s', 'church-event-manager' ),
				get_bloginfo( 'name' )
			),
			'message'  => $message,
			'type'     => 'registration_lookup',
		] );
	}

	public function my_registrations( $atts ) {
		ob_start();

		// Handle manage by code (from email link)
		$code = sanitize_text_field( wp_unslash( $_GET['cem_code'] ?? '' ) );
		if ( $code ) {
			$this->render_manage_registration( $code );
			return ob_get_clean();
		}

		// Privacy model:
		// Anyone could previously type any email into the lookup form and see
		// what registrations existed for that email — exposing whether someone
		// signed up for a specific event. Now, when a public visitor submits
		// the form, we ALWAYS show the same generic confirmation message
		// regardless of whether the email matches anything, and dispatch the
		// list as an email instead of rendering it in the page. Logged-in
		// users still see their own list inline.
		if ( ! is_user_logged_in() ) {
			$submitted_email = isset( $_GET['cem_email'] )
				? sanitize_email( wp_unslash( $_GET['cem_email'] ) )
				: '';

			$send_status = '';
			if ( $submitted_email && is_email( $submitted_email ) ) {
				// CSRF: require a nonce on this lookup form.
				$nonce = isset( $_GET['cem_lookup_nonce'] )
					? sanitize_text_field( wp_unslash( $_GET['cem_lookup_nonce'] ) )
					: '';
				$nonce_ok = $nonce && wp_verify_nonce( $nonce, 'cem_lookup' );

				if ( $nonce_ok ) {
					$regs = CEM_Registration::get_for_user( $submitted_email );
					if ( ! empty( $regs ) ) {
						// Reuse the same email body the AJAX endpoint produces.
						self::send_registration_list_email( $submitted_email, $regs );
					}
					// IMPORTANT: identical message whether or not registrations
					// existed — never reveal which addresses are on file.
					$send_status = 'sent';
				} else {
					$send_status = 'invalid';
				}
			}
			?>
			<div class="cem-lookup-wrap">
				<h3><?php esc_html_e( 'Look Up Your Registration', 'church-event-manager' ); ?></h3>
				<?php if ( $send_status === 'sent' ) : ?>
					<div class="cem-notice cem-notice-success">
						<?php esc_html_e( "If we have any registrations for that email address, we've just sent them to you. Please check your inbox.", 'church-event-manager' ); ?>
					</div>
				<?php else : ?>
					<?php if ( $send_status === 'invalid' ) : ?>
						<div class="cem-notice cem-notice-error">
							<?php esc_html_e( 'Your form session expired. Please try again.', 'church-event-manager' ); ?>
						</div>
					<?php endif; ?>
					<p><?php esc_html_e( "Enter your email address and we'll email you a list of your registrations and links to manage them.", 'church-event-manager' ); ?></p>
					<form method="get" class="cem-form cem-lookup-form">
						<?php wp_nonce_field( 'cem_lookup', 'cem_lookup_nonce', false ); ?>
						<div class="cem-field">
							<label for="cem_lookup_email"><?php esc_html_e( 'Email Address', 'church-event-manager' ); ?></label>
							<input type="email" id="cem_lookup_email" name="cem_email" required>
						</div>
						<button type="submit" class="cem-btn cem-btn-primary"><?php esc_html_e( 'Email My Registrations', 'church-event-manager' ); ?></button>
					</form>
				<?php endif; ?>
			</div>
			<?php
			return ob_get_clean();
		}

		// Logged-in user: show their registrations inline.
		$regs          = CEM_Registration::get_for_user( '', get_current_user_id() );
		$summary_email = wp_get_current_user()->user_email;

		if ( empty( $regs ) ) {
			echo '<div class="cem-notice cem-notice-info">' . esc_html__( 'No registrations found.', 'church-event-manager' ) . '</div>';
			return ob_get_clean();
		}
		?>
		<div class="cem-my-registrations">
			<h3><?php esc_html_e( 'Your Registrations', 'church-event-manager' ); ?></h3>
			<div class="cem-reg-list">
				<?php foreach ( $regs as $reg ) :
					$event = get_post( $reg->event_id );
					$start = get_post_meta( $reg->event_id, '_cem_start_datetime', true );
					$past  = $start && strtotime( $start ) < time();
					$can_cancel = ! $past && $reg->status !== 'cancelled'
						&& get_option( 'cem_allow_cancellations', '1' );
					$deadline = CEM_Helpers::get_cancellation_deadline( $reg->event_id );
					if ( $deadline ) $can_cancel = $can_cancel && ( strtotime( $deadline ) > time() );
				?>
				<div class="cem-reg-card <?php echo 'cem-reg-status-' . esc_attr( $reg->status ); ?>">
					<div class="cem-reg-card-header">
						<div>
							<h4 class="cem-reg-event-title">
								<?php echo $event ? esc_html( $event->post_title ) : esc_html__( '(Event removed)', 'church-event-manager' ); ?>
							</h4>
							<?php if ( $start ) : ?>
							<p class="cem-reg-date"><?php echo esc_html( CEM_Helpers::format_datetime( $start ) ); ?></p>
							<?php endif; ?>
						</div>
						<?php echo CEM_Helpers::status_badge( $reg->status ); ?>
					</div>
					<div class="cem-reg-card-body">
						<p><strong><?php esc_html_e( 'Registration Code:', 'church-event-manager' ); ?></strong> <?php echo esc_html( $reg->registration_code ); ?></p>
						<p><strong><?php esc_html_e( 'Attendees:', 'church-event-manager' ); ?></strong> <?php echo esc_html( $reg->num_attendees ); ?></p>
						<p><strong><?php esc_html_e( 'Registered:', 'church-event-manager' ); ?></strong> <?php echo esc_html( CEM_Helpers::format_datetime( $reg->created_at ) ); ?></p>
					</div>
					<?php if ( $can_cancel ) : ?>
					<div class="cem-reg-card-actions">
						<a href="<?php echo esc_url( CEM_Helpers::get_manage_url( $reg->registration_code ) ); ?>"
							class="cem-btn cem-btn-danger-ghost cem-btn-small">
							<?php esc_html_e( 'Cancel Registration', 'church-event-manager' ); ?>
						</a>
					</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		</div>

		<?php if ( $summary_email ) : ?>
		<div class="cem-email-summary-wrap">
			<p class="cem-muted"><?php esc_html_e( 'Want a copy in your inbox?', 'church-event-manager' ); ?></p>
			<button type="button" class="cem-btn cem-btn-ghost cem-btn-small cem-email-summary-btn"
				data-email="<?php echo esc_attr( $summary_email ); ?>"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'cem_public_nonce' ) ); ?>">
				<?php esc_html_e( 'Email me my registrations', 'church-event-manager' ); ?>
			</button>
			<span class="cem-email-summary-msg" style="margin-left:10px"></span>
		</div>
		<?php endif; ?>

		<?php
		return ob_get_clean();
	}

	/**
	 * Render single registration manage page (via email link or My Registrations).
	 *
	 * Security model:
	 *  - Viewing / editing: authenticated by the registration_code alone (a
	 *    12-char cryptographically random token). WordPress nonces are NOT
	 *    embedded in the email link because they expire in 24-48 h and are
	 *    tied to the user's session — both of which break links for typical
	 *    church registrants who are not WP users.
	 *  - All mutating actions (update form POST, cancel GET) carry a *fresh*
	 *    nonce generated at page-render time, so CSRF is still fully covered.
	 */
	private function render_manage_registration( $code ) {
		$reg = CEM_Registration::get_by_code( $code );

		if ( ! $reg ) {
			echo '<div class="cem-notice cem-notice-error">' . esc_html__( 'Registration not found. Please check your link and try again.', 'church-event-manager' ) . '</div>';
			return;
		}

		// ── Handle POST: update registration details ──────────────────────────

		if ( $_SERVER['REQUEST_METHOD'] === 'POST'
			&& ( sanitize_key( $_POST['cem_action'] ?? '' ) === 'update_reg' )
		) {
			$post_nonce = sanitize_text_field( $_POST['cem_nonce'] ?? '' );
			$result = CEM_Registration::update_by_code( $code, $post_nonce, [
				'num_attendees' => isset( $_POST['num_attendees'] ) ? $_POST['num_attendees'] : null,
				'phone'         => isset( $_POST['phone'] )         ? $_POST['phone']         : null,
				'notes'         => isset( $_POST['notes'] )         ? $_POST['notes']         : null,
			] );
			if ( is_wp_error( $result ) ) {
				echo '<div class="cem-notice cem-notice-error">' . esc_html( $result->get_error_message() ) . '</div>';
			} else {
				echo '<div class="cem-notice cem-notice-success">' . esc_html__( 'Your registration has been updated.', 'church-event-manager' ) . '</div>';
				$reg = CEM_Registration::get_by_code( $code ); // reload fresh data
			}
		}

		// ── Handle GET: cancel registration ──────────────────────────────────

		if ( ! empty( $_GET['cem_action'] ) && $_GET['cem_action'] === 'cancel' ) {
			$cancel_nonce = sanitize_text_field( $_GET['cem_nonce'] ?? '' );
			$result = CEM_Registration::cancel_by_code( $code, $cancel_nonce );
			if ( is_wp_error( $result ) ) {
				echo '<div class="cem-notice cem-notice-error">' . esc_html( $result->get_error_message() ) . '</div>';
			} else {
				echo '<div class="cem-notice cem-notice-success">' . esc_html__( 'Your registration has been cancelled. You will receive a confirmation email shortly.', 'church-event-manager' ) . '</div>';
				return;
			}
		}

		// ── Compute state ─────────────────────────────────────────────────────

		$event      = get_post( $reg->event_id );
		$start      = get_post_meta( $reg->event_id, '_cem_start_datetime', true );
		$event_past = $start && strtotime( $start ) < time();

		// A single fresh nonce covers both the edit form (POST) and the cancel link (GET).
		$fresh_nonce = wp_create_nonce( 'cem_manage_' . $code );
		$manage_url  = CEM_Helpers::get_manage_url( $code );
		$cancel_url  = add_query_arg( [
			'cem_action' => 'cancel',
			'cem_nonce'  => $fresh_nonce,
		], $manage_url );

		$deadline        = CEM_Helpers::get_cancellation_deadline( $reg->event_id );
		$deadline_passed = $deadline && strtotime( $deadline ) < time();
		$cancellations_enabled = (bool) get_option( 'cem_allow_cancellations', '1' );

		$can_edit   = ( $reg->status !== 'cancelled' ) && ! $event_past;
		$can_cancel = $can_edit && $cancellations_enabled && ! $deadline_passed;

		$max_attendees = (int) get_post_meta( $reg->event_id, '_cem_max_attendees_per_reg', true );
		?>
		<div class="cem-manage-wrap">

			<h2><?php esc_html_e( 'Your Registration', 'church-event-manager' ); ?></h2>

			<!-- ── Registration summary ───────────────────────────────────── -->
			<div class="cem-reg-card">
				<p><strong><?php esc_html_e( 'Event:', 'church-event-manager' ); ?></strong>
					<?php echo $event ? esc_html( $event->post_title ) : '—'; ?></p>
				<p><strong><?php esc_html_e( 'Date:', 'church-event-manager' ); ?></strong>
					<?php echo $start ? esc_html( CEM_Helpers::format_datetime( $start ) ) : '—'; ?></p>
				<p><strong><?php esc_html_e( 'Name:', 'church-event-manager' ); ?></strong>
					<?php echo esc_html( trim( $reg->first_name . ' ' . $reg->last_name ) ); ?></p>
				<p><strong><?php esc_html_e( 'Status:', 'church-event-manager' ); ?></strong>
					<?php echo CEM_Helpers::status_badge( $reg->status ); ?></p>
				<p><strong><?php esc_html_e( 'Attendees:', 'church-event-manager' ); ?></strong>
					<?php echo esc_html( $reg->num_attendees ); ?></p>
				<p><strong><?php esc_html_e( 'Registration Code:', 'church-event-manager' ); ?></strong>
					<code><?php echo esc_html( $reg->registration_code ); ?></code></p>

				<?php
				// QR — same code as the email. People who lose the email
				// can pull this page up on their phone and present it at
				// check-in instead.
				$qr_url = class_exists( 'CEM_QR' )
					? CEM_QR::get_url( $reg->registration_code )
					: '';
				if ( $qr_url && $reg->status !== 'cancelled' ) :
				?>
				<div class="cem-manage-qr">
					<img src="<?php echo esc_url( $qr_url ); ?>"
						width="180" height="180"
						alt="<?php esc_attr_e( 'Check-in QR code', 'church-event-manager' ); ?>">
					<p class="cem-manage-qr-caption"><?php esc_html_e( 'Show this at check-in.', 'church-event-manager' ); ?></p>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( $reg->status === 'cancelled' ) : ?>
			<!-- Already cancelled -->
			<div class="cem-notice cem-notice-info">
				<?php esc_html_e( 'This registration has already been cancelled.', 'church-event-manager' ); ?>
			</div>

			<?php elseif ( $event_past ) : ?>
			<!-- Event has passed -->
			<div class="cem-notice cem-notice-info">
				<?php esc_html_e( 'This event has already taken place. No further changes can be made.', 'church-event-manager' ); ?>
			</div>

			<?php else : ?>

			<!-- ── Edit form ──────────────────────────────────────────────── -->
			<div class="cem-manage-section">
				<h3><?php esc_html_e( 'Update Your Details', 'church-event-manager' ); ?></h3>
				<form method="post" action="<?php echo esc_url( $manage_url ); ?>" class="cem-form">
					<input type="hidden" name="cem_action" value="update_reg">
					<input type="hidden" name="cem_nonce"  value="<?php echo esc_attr( $fresh_nonce ); ?>">

					<?php if ( $max_attendees !== 1 ) : // show when event allows > 1 per reg ?>
					<div class="cem-field">
						<label for="cem_manage_attendees">
							<?php esc_html_e( 'Number of Attendees', 'church-event-manager' ); ?>
						</label>
						<input type="number" id="cem_manage_attendees" name="num_attendees"
							value="<?php echo esc_attr( $reg->num_attendees ); ?>"
							min="1"
							<?php if ( $max_attendees > 0 ) echo 'max="' . esc_attr( $max_attendees ) . '"'; ?>>
					</div>
					<?php endif; ?>

					<div class="cem-field">
						<label for="cem_manage_phone">
							<?php esc_html_e( 'Phone Number', 'church-event-manager' ); ?>
						</label>
						<input type="tel" id="cem_manage_phone" name="phone"
							value="<?php echo esc_attr( $reg->phone ?? '' ); ?>"
							autocomplete="tel">
					</div>

					<div class="cem-field">
						<label for="cem_manage_notes">
							<?php esc_html_e( 'Notes or Special Requests', 'church-event-manager' ); ?>
						</label>
						<textarea id="cem_manage_notes" name="notes"
							rows="3"><?php echo esc_textarea( $reg->notes ?? '' ); ?></textarea>
					</div>

					<div class="cem-form-submit">
						<button type="submit" class="cem-btn cem-btn-primary">
							<?php esc_html_e( 'Save Changes', 'church-event-manager' ); ?>
						</button>
					</div>
				</form>
			</div>

			<!-- ── Cancel section ─────────────────────────────────────────── -->
			<div class="cem-manage-section cem-manage-cancel-section">
				<h3><?php esc_html_e( 'Cancel Registration', 'church-event-manager' ); ?></h3>
				<?php if ( $can_cancel ) : ?>
				<p class="cem-muted">
					<?php esc_html_e( 'Need to cancel? This action cannot be undone.', 'church-event-manager' ); ?>
				</p>
				<a href="<?php echo esc_url( $cancel_url ); ?>" class="cem-btn cem-btn-danger"
					onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to cancel your registration?', 'church-event-manager' ); ?>')">
					<?php esc_html_e( 'Cancel My Registration', 'church-event-manager' ); ?>
				</a>
				<?php elseif ( ! $cancellations_enabled ) : ?>
				<p class="cem-muted">
					<?php esc_html_e( 'Online cancellations are not available for this event. Please contact us directly if you need to cancel.', 'church-event-manager' ); ?>
				</p>
				<?php elseif ( $deadline_passed ) : ?>
				<p class="cem-muted">
					<?php esc_html_e( 'The cancellation window for this event has closed.', 'church-event-manager' ); ?>
				</p>
				<?php endif; ?>
			</div>

			<?php endif; // not cancelled, not past ?>

		</div>
		<?php
	}

	// ── [cem_event_calendar] ──────────────────────────────────────────────────

	public function event_calendar( $atts ) {
		$atts  = shortcode_atts( [
			'months'       => 1,
			'show_groups'  => 'no',       // Include cem_group posts on the calendar
			'category'     => '',
			'ministry'     => '',
		], $atts );

		$month = isset( $_GET['cem_month'] ) ? (int) $_GET['cem_month'] : (int) date( 'n' );
		$year  = isset( $_GET['cem_year'] )  ? (int) $_GET['cem_year']  : (int) date( 'Y' );

		$first_day  = mktime( 0, 0, 0, $month, 1, $year );
		$days_in    = (int) date( 't', $first_day );
		$start_dow  = (int) date( 'w', $first_day ); // 0=Sun

		// Get events for the month
		$events_by_day = [];
		$event_data    = []; // Keyed by post ID for tooltip data

		// IMPORTANT: events are stored as `Y-m-d H:i:s`. A BETWEEN on `Y-m-d`
		// strings excludes anything later than midnight on the upper-bound
		// day — i.e. an event on the 31st at 7pm gets dropped from the
		// calendar entirely. Use a half-open range with `<` against the
		// first day of the *next* month instead.
		$range_start = date( 'Y-m-d 00:00:00', $first_day );
		$range_end   = date( 'Y-m-d 00:00:00', mktime( 0, 0, 0, $month + 1, 1, $year ) );

		$query_args = [
			'post_type'      => 'cem_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_cem_start_datetime',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[
					'key'     => '_cem_start_datetime',
					'value'   => $range_start,
					'compare' => '>=',
					'type'    => 'DATETIME',
				],
				[
					'key'     => '_cem_start_datetime',
					'value'   => $range_end,
					'compare' => '<',
					'type'    => 'DATETIME',
				],
			],
		];

		// Category / ministry filters
		if ( $atts['category'] || $atts['ministry'] ) {
			$query_args['tax_query'] = [];
			if ( $atts['category'] ) {
				$query_args['tax_query'][] = [
					'taxonomy' => 'cem_event_category',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $atts['category'] ),
				];
			}
			if ( $atts['ministry'] ) {
				$query_args['tax_query'][] = [
					'taxonomy' => 'cem_ministry',
					'field'    => 'slug',
					'terms'    => sanitize_text_field( $atts['ministry'] ),
				];
			}
		}

		$events = get_posts( $query_args );

		foreach ( $events as $e ) {
			$start    = get_post_meta( $e->ID, '_cem_start_datetime', true );
			// Skip events with no start time — without this guard,
			// strtotime() returns false and date('j', false) returns '1',
			// dumping malformed events onto the 1st of every month.
			if ( ! $start ) continue;
			$start_ts = strtotime( $start );
			if ( ! $start_ts ) continue;
			$end      = get_post_meta( $e->ID, '_cem_end_datetime', true );
			$location = get_post_meta( $e->ID, '_cem_location', true );
			$day      = (int) date( 'j', $start_ts );

			$events_by_day[ $day ][] = $e;
			$event_data[ $e->ID ] = [
				'title'    => $e->post_title,
				'start'    => $start,
				'end'      => $end,
				'location' => $location,
				'url'      => get_permalink( $e->ID ),
				'type'     => 'event',
			];
		}

		// Optionally include group meetings on the calendar
		if ( $atts['show_groups'] === 'yes' ) {
			$groups = get_posts( [
				'post_type'      => 'cem_group',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => [ [
					'key'     => '_cem_group_status',
					'value'   => [ 'open', 'full' ],
					'compare' => 'IN',
				] ],
			] );
			$day_map = [ 'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6 ];
			foreach ( $groups as $g ) {
				$g_loc   = get_post_meta( $g->ID, '_cem_group_location', true );
				$g_start = get_post_meta( $g->ID, '_cem_group_start_date', true );

				// Anchor used for bi-weekly / monthly cadence. Prefer the
				// group's configured start date so the schedule is stable;
				// fall back to the post date so an unconfigured group still
				// has a consistent (if arbitrary) phase.
				$anchor_ts = $g_start ? strtotime( $g_start ) : strtotime( $g->post_date );
				if ( ! $anchor_ts ) $anchor_ts = mktime( 0, 0, 0, $month, 1, $year );
				$anchor_wom = (int) ceil( (int) date( 'j', $anchor_ts ) / 7 );

				// Multi-meeting support: walk every meeting time on the group
				// and plot each one. A walking group meeting Tue/Wed/Sat now
				// shows 3 entries per week instead of just one.
				$g_meetings = CEM_Group::get_meeting_times( $g->ID );

				foreach ( $g_meetings as $mt ) {
					$g_day  = $mt['day']       ?? '';
					$g_time = $mt['time']      ?? '';
					$g_freq = $mt['frequency'] ?? '';
					if ( ! isset( $day_map[ $g_day ] ) ) continue;
					$target_dow = $day_map[ $g_day ];

					// Stable per-event-data key so multiple meetings of the same
					// group don't overwrite each other in $event_data.
					$entry_key = $g->ID . '|' . $g_day . '|' . $g_time;

					for ( $d = 1; $d <= $days_in; $d++ ) {
						$date_ts = mktime( 0, 0, 0, $month, $d, $year );
						if ( (int) date( 'w', $date_ts ) !== $target_dow ) continue;

						if ( $g_freq === 'biweekly' ) {
							$days_since = ( $date_ts - $anchor_ts ) / DAY_IN_SECONDS;
							if ( $days_since < 0 ) continue;
							if ( ( (int) round( $days_since ) ) % 14 !== 0 ) continue;
						} elseif ( $g_freq === 'monthly' ) {
							$this_wom = (int) ceil( $d / 7 );
							if ( $this_wom !== $anchor_wom ) continue;
						}

						$events_by_day[ $d ][] = (object) [ 'ID' => $entry_key, 'post_title' => $g->post_title ];
						$event_data[ $entry_key ] = [
							'title'    => $g->post_title,
							'start'    => date( 'Y-m-d', $date_ts ) . ( $g_time ? ' ' . $g_time . ':00' : '' ),
							'end'      => '',
							'location' => $g_loc,
							'url'      => get_permalink( $g->ID ),
							'type'     => 'group',
						];
					}
				}
			}
		}

		$prev = $month === 1 ? [ 12, $year - 1 ] : [ $month - 1, $year ];
		$next = $month === 12 ? [ 1, $year + 1 ] : [ $month + 1, $year ];
		$today_url = remove_query_arg( [ 'cem_month', 'cem_year' ] );
		$cur_url   = $today_url;

		ob_start();
		?>
		<div class="cem-calendar-wrap">
			<div class="cem-calendar-header">
				<div class="cem-calendar-nav">
					<a href="<?php echo esc_url( add_query_arg( [ 'cem_month' => $prev[0], 'cem_year' => $prev[1] ], $cur_url ) ); ?>" class="cem-cal-nav cem-cal-nav--prev" aria-label="<?php esc_attr_e( 'Previous month', 'church-event-manager' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
					</a>
					<h3 class="cem-calendar-title"><?php echo esc_html( date_i18n( 'F Y', $first_day ) ); ?></h3>
					<a href="<?php echo esc_url( add_query_arg( [ 'cem_month' => $next[0], 'cem_year' => $next[1] ], $cur_url ) ); ?>" class="cem-cal-nav cem-cal-nav--next" aria-label="<?php esc_attr_e( 'Next month', 'church-event-manager' ); ?>">
						<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
					</a>
				</div>
				<a href="<?php echo esc_url( $today_url ); ?>" class="cem-btn cem-btn-ghost cem-btn-small cem-cal-today-btn"><?php esc_html_e( 'Today', 'church-event-manager' ); ?></a>
			</div>

			<table class="cem-calendar">
				<thead>
					<tr>
						<?php
						$day_labels = [
							__( 'Sun', 'church-event-manager' ),
							__( 'Mon', 'church-event-manager' ),
							__( 'Tue', 'church-event-manager' ),
							__( 'Wed', 'church-event-manager' ),
							__( 'Thu', 'church-event-manager' ),
							__( 'Fri', 'church-event-manager' ),
							__( 'Sat', 'church-event-manager' ),
						];
						$full_day_labels = [
							__( 'Sunday', 'church-event-manager' ),
							__( 'Monday', 'church-event-manager' ),
							__( 'Tuesday', 'church-event-manager' ),
							__( 'Wednesday', 'church-event-manager' ),
							__( 'Thursday', 'church-event-manager' ),
							__( 'Friday', 'church-event-manager' ),
							__( 'Saturday', 'church-event-manager' ),
						];
						foreach ( $day_labels as $i => $d ) : ?>
						<th>
							<span class="cem-cal-day-short"><?php echo esc_html( $d ); ?></span>
							<span class="cem-cal-day-full"><?php echo esc_html( $full_day_labels[ $i ] ); ?></span>
						</th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php
				$day = 1;
				$rows = ceil( ( $days_in + $start_dow ) / 7 );
				for ( $row = 0; $row < $rows; $row++ ) {
					echo '<tr>';
					for ( $col = 0; $col < 7; $col++ ) {
						$cell = $row * 7 + $col;
						if ( $cell < $start_dow || $day > $days_in ) {
							echo '<td class="cem-cal-empty"></td>';
						} else {
							$is_today    = ( $day === (int) date('j') && $month === (int) date('n') && $year === (int) date('Y') );
							$has_events  = ! empty( $events_by_day[ $day ] );
							$event_count = $has_events ? count( $events_by_day[ $day ] ) : 0;
							$td_class    = 'cem-cal-day' . ( $is_today ? ' cem-cal-today' : '' ) . ( $has_events ? ' cem-cal-has-events' : '' );

							echo '<td class="' . esc_attr( $td_class ) . '">';
							echo '<span class="cem-cal-date">' . $day . '</span>';

							if ( $has_events ) {
								echo '<div class="cem-cal-events">';
								$shown = 0;
								foreach ( $events_by_day[ $day ] as $ce ) {
									$ed = $event_data[ $ce->ID ] ?? [];
									$time_str = '';
									if ( ! empty( $ed['start'] ) ) {
										$ts = strtotime( $ed['start'] );
										$time_str = date_i18n( get_option( 'time_format', 'g:i a' ), $ts );
									}
									$type_class = ( $ed['type'] ?? 'event' ) === 'group' ? 'cem-cal-event--group' : 'cem-cal-event--event';
									// Prefer the URL stashed in event_data so synthetic
									// IDs (multi-meeting groups) still resolve correctly.
									$cal_url = ! empty( $ed['url'] ) ? $ed['url'] : get_permalink( $ce->ID );

									if ( $shown < 3 ) {
										echo '<a class="cem-cal-event-item ' . esc_attr( $type_class ) . '" href="' . esc_url( $cal_url ) . '"'
											. ' data-event-id="' . esc_attr( $ce->ID ) . '"'
											. ' data-event-title="' . esc_attr( $ce->post_title ) . '"'
											. ' data-event-time="' . esc_attr( $time_str ) . '"'
											. ' data-event-location="' . esc_attr( $ed['location'] ?? '' ) . '"'
											. ' data-event-type="' . esc_attr( $ed['type'] ?? 'event' ) . '"'
											. '>';
										if ( $time_str ) {
											echo '<span class="cem-cal-event-time">' . esc_html( $time_str ) . '</span> ';
										}
										echo '<span class="cem-cal-event-name">' . esc_html( wp_trim_words( $ce->post_title, 4 ) ) . '</span>';
										echo '</a>';
									}
									$shown++;
								}
								if ( $event_count > 3 ) {
									echo '<span class="cem-cal-more">+' . ( $event_count - 3 ) . ' ' . esc_html__( 'more', 'church-event-manager' ) . '</span>';
								}
								echo '</div>';
							}
							echo '</td>';
							$day++;
						}
					}
					echo '</tr>';
				}
				?>
				</tbody>
			</table>

			<!-- Event Tooltip (populated by JS on hover) -->
			<div class="cem-cal-tooltip" id="cem-cal-tooltip" style="display:none">
				<div class="cem-cal-tooltip__content">
					<span class="cem-cal-tooltip__badge" id="cem-tooltip-badge"></span>
					<strong class="cem-cal-tooltip__title" id="cem-tooltip-title"></strong>
					<span class="cem-cal-tooltip__time" id="cem-tooltip-time"></span>
					<span class="cem-cal-tooltip__location" id="cem-tooltip-location"></span>
					<span class="cem-cal-tooltip__action"><?php esc_html_e( 'Click for details →', 'church-event-manager' ); ?></span>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}
