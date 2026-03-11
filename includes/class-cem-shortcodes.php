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
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_cem_start_datetime',
			'order'          => 'ASC',
			'tax_query'      => [],
			'meta_query'     => [],
		];

		if ( $atts['show_past'] !== 'yes' ) {
			$query_args['meta_query'][] = [
				'key'     => '_cem_start_datetime',
				'value'   => current_time( 'mysql' ),
				'compare' => '>=',
				'type'    => 'DATETIME',
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
						<option value=""><?php esc_html_e( 'All Categories', 'church-event-manager' ); ?></option>
						<?php
						$cats = get_terms( [ 'taxonomy' => 'cem_event_category', 'hide_empty' => true ] );
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
						<?php if ( $start ) : ?>
						<div class="cem-event-date-badge">
							<span class="cem-date-month"><?php echo esc_html( date_i18n( 'M', strtotime( $start ) ) ); ?></span>
							<span class="cem-date-day"><?php echo esc_html( date_i18n( 'j', strtotime( $start ) ) ); ?></span>
						</div>
						<?php endif; ?>

						<div class="cem-event-info">
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
								<?php if ( $full && get_option( 'cem_waitlist_enabled' ) ) : ?>
								<a href="<?php the_permalink(); ?>?register=1" class="cem-btn cem-btn-secondary">
									<?php esc_html_e( 'Join Waitlist', 'church-event-manager' ); ?>
								</a>
								<?php elseif ( $reg_status !== 'closed' ) : ?>
								<a href="<?php the_permalink(); ?>?register=1" class="cem-btn cem-btn-primary">
									<?php esc_html_e( 'Register Now', 'church-event-manager' ); ?>
								</a>
								<?php else : ?>
								<span class="cem-btn cem-btn-disabled"><?php esc_html_e( 'Registration Closed', 'church-event-manager' ); ?></span>
								<?php endif; ?>
								<a href="<?php the_permalink(); ?>" class="cem-btn cem-btn-ghost">
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
			'layout'   => 'grid',  // grid | list
			'per_page' => 12,
			'type'     => '',      // bible-study, prayer, mens, womens, etc.
			'status'   => 'open',  // open | closed | full | inactive | '' = all
		], $atts );

		$meta_query = [ 'relation' => 'AND' ];

		if ( $atts['type'] ) {
			$meta_query[] = [
				'key'     => '_cem_group_type',
				'value'   => sanitize_key( $atts['type'] ),
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

		$query = new WP_Query( [
			'post_type'      => 'cem_group',
			'post_status'    => 'publish',
			'posts_per_page' => (int) $atts['per_page'],
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => count( $meta_query ) > 1 ? $meta_query : [],
		] );

		if ( ! $query->have_posts() ) {
			return '<p class="cem-no-events">' . esc_html__( 'No groups found.', 'church-event-manager' ) . '</p>';
		}

		$group_types = CEM_Group::group_types();
		$layout      = in_array( $atts['layout'], [ 'grid', 'list' ], true ) ? $atts['layout'] : 'grid';
		$status_labels = [
			'open'     => __( 'Open',     'church-event-manager' ),
			'closed'   => __( 'Closed',   'church-event-manager' ),
			'full'     => __( 'Full',     'church-event-manager' ),
			'inactive' => __( 'Inactive', 'church-event-manager' ),
		];

		ob_start();
		echo '<div class="cem-events-grid cem-groups-' . esc_attr( $layout ) . '">';

		while ( $query->have_posts() ) {
			$query->the_post();
			$id        = get_the_ID();
			$type      = get_post_meta( $id, '_cem_group_type',      true );
			$day       = get_post_meta( $id, '_cem_group_day',       true );
			$time      = get_post_meta( $id, '_cem_group_time',      true );
			$freq      = get_post_meta( $id, '_cem_group_frequency', true );
			$location  = get_post_meta( $id, '_cem_group_location',  true );
			$leader    = get_post_meta( $id, '_cem_group_leader',    true );
			$status    = get_post_meta( $id, '_cem_group_status',    true ) ?: 'open';
			$capacity  = (int) get_post_meta( $id, '_cem_group_capacity', true );
			$members   = $capacity > 0 ? CEM_Group::get_signup_count( $id ) : 0;

			$type_label    = $group_types[ $type ] ?? '';
			$status_label  = $status_labels[ $status ] ?? ucfirst( $status );
			$fmt_time      = CEM_Group::format_time( $time );
			$schedule_parts = array_filter( [ $freq ? ucfirst( $freq ) : '', $day, $fmt_time ] );
			$schedule      = implode( ' · ', $schedule_parts );
			$thumb         = has_post_thumbnail() ? get_the_post_thumbnail( $id, 'medium', [ 'class' => 'cem-card-img' ] ) : '';
			?>
			<article class="cem-event-card cem-group-card">
				<?php if ( $thumb ) : ?>
				<a href="<?php the_permalink(); ?>" class="cem-card-img-wrap"><?php echo $thumb; ?></a>
				<?php endif; ?>
				<div class="cem-card-body">
					<div class="cem-card-meta">
						<?php if ( $type_label ) : ?>
						<span class="cem-badge cem-group-type-badge"><?php echo esc_html( $type_label ); ?></span>
						<?php endif; ?>
						<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
					</div>
					<h3 class="cem-card-title">
						<a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
					</h3>
					<?php if ( $schedule ) : ?>
					<p class="cem-card-date">🗓 <?php echo esc_html( $schedule ); ?></p>
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

		$reg_status = get_post_meta( $event_id, '_cem_registration_status', true );
		$deadline   = get_post_meta( $event_id, '_cem_registration_deadline', true );
		$full       = CEM_Helpers::is_at_capacity( $event_id );
		$waitlist   = get_option( 'cem_waitlist_enabled', '1' );
		$custom_fields = CEM_Custom_Fields::get_fields( $event_id );

		// ── Payment detection ────────────────────────────────────────────────────
		$event_price      = get_post_meta( $event_id, '_cem_price', true );
		$price_num        = ( $event_price !== '' ) ? (float) $event_price : 0.0;
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

					<?php
					$max_attendees = get_post_meta( $event_id, '_cem_max_attendees_per_reg', true );
					$spots = CEM_Helpers::get_spots_remaining( $event_id );
					if ( (int) $max_attendees !== 1 ) :
					?>
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

	public function my_registrations( $atts ) {
		ob_start();

		// Handle manage by code (from email link)
		$code = sanitize_text_field( $_GET['cem_code'] ?? '' );
		if ( $code ) {
			$this->render_manage_registration( $code );
			return ob_get_clean();
		}

		// Logged-in user: show their registrations
		if ( is_user_logged_in() ) {
			$regs = CEM_Registration::get_for_user( '', get_current_user_id() );
		} else {
			// Show search form
			?>
			<div class="cem-lookup-wrap">
				<h3><?php esc_html_e( 'Look Up Your Registration', 'church-event-manager' ); ?></h3>
				<p><?php esc_html_e( 'Enter your email address to view your registrations.', 'church-event-manager' ); ?></p>
				<form method="get" class="cem-form cem-lookup-form">
					<div class="cem-field">
						<label for="cem_lookup_email"><?php esc_html_e( 'Email Address', 'church-event-manager' ); ?></label>
						<input type="email" id="cem_lookup_email" name="cem_email"
							value="<?php echo esc_attr( $_GET['cem_email'] ?? '' ); ?>" required>
					</div>
					<button type="submit" class="cem-btn cem-btn-primary"><?php esc_html_e( 'Find My Registrations', 'church-event-manager' ); ?></button>
				</form>
			</div>
			<?php

			if ( ! empty( $_GET['cem_email'] ) ) {
				$regs = CEM_Registration::get_for_user( sanitize_email( $_GET['cem_email'] ) );
			} else {
				return ob_get_clean();
			}
		}

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
		$atts  = shortcode_atts( [ 'months' => 1 ], $atts );
		$month = isset( $_GET['cem_month'] ) ? (int) $_GET['cem_month'] : (int) date( 'n' );
		$year  = isset( $_GET['cem_year'] )  ? (int) $_GET['cem_year']  : (int) date( 'Y' );

		$first_day  = mktime( 0, 0, 0, $month, 1, $year );
		$days_in    = (int) date( 't', $first_day );
		$start_dow  = (int) date( 'w', $first_day ); // 0=Sun

		// Get events for the month
		$events_by_day = [];
		$events = get_posts( [
			'post_type'      => 'cem_event',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_cem_start_datetime',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [ [
				'key'     => '_cem_start_datetime',
				'value'   => [ date( 'Y-m-d', $first_day ), date( 'Y-m-d', mktime( 0,0,0,$month,$days_in,$year ) ) ],
				'compare' => 'BETWEEN',
				'type'    => 'DATE',
			] ],
		] );

		foreach ( $events as $e ) {
			$start = get_post_meta( $e->ID, '_cem_start_datetime', true );
			$day   = (int) date( 'j', strtotime( $start ) );
			$events_by_day[ $day ][] = $e;
		}

		$prev = $month === 1 ? [ 12, $year - 1 ] : [ $month - 1, $year ];
		$next = $month === 12 ? [ 1, $year + 1 ] : [ $month + 1, $year ];
		$cur_url = remove_query_arg( [ 'cem_month', 'cem_year' ] );

		ob_start();
		?>
		<div class="cem-calendar-wrap">
			<div class="cem-calendar-header">
				<a href="<?php echo esc_url( add_query_arg( [ 'cem_month' => $prev[0], 'cem_year' => $prev[1] ], $cur_url ) ); ?>" class="cem-cal-nav">← <?php esc_html_e( 'Previous', 'church-event-manager' ); ?></a>
				<h3><?php echo esc_html( date_i18n( 'F Y', $first_day ) ); ?></h3>
				<a href="<?php echo esc_url( add_query_arg( [ 'cem_month' => $next[0], 'cem_year' => $next[1] ], $cur_url ) ); ?>" class="cem-cal-nav"><?php esc_html_e( 'Next', 'church-event-manager' ); ?> →</a>
			</div>

			<table class="cem-calendar">
				<thead>
					<tr>
						<?php foreach ( [ 'Sun','Mon','Tue','Wed','Thu','Fri','Sat' ] as $d ) : ?>
						<th><?php echo esc_html( $d ); ?></th>
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
							$is_today = ( $day === (int) date('j') && $month === (int) date('n') && $year === (int) date('Y') );
							echo '<td class="cem-cal-day' . ( $is_today ? ' cem-cal-today' : '' ) . ( ! empty( $events_by_day[$day] ) ? ' cem-cal-has-events' : '' ) . '">';
							echo '<span class="cem-cal-date">' . $day . '</span>';
							if ( ! empty( $events_by_day[$day] ) ) {
								echo '<div class="cem-cal-events">';
								foreach ( $events_by_day[$day] as $ce ) {
									echo '<a class="cem-cal-event-dot" href="' . esc_url( get_permalink( $ce->ID ) ) . '" title="' . esc_attr( $ce->post_title ) . '">' . esc_html( wp_trim_words( $ce->post_title, 4 ) ) . '</a>';
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
		</div>
		<?php
		return ob_get_clean();
	}
}
