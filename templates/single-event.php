<?php
/**
 * Single Event Template
 *
 * Integrates with the Christian Mission (CMSMasters/Elementor) WordPress theme.
 * Dynamically renders all Church Event Manager plugin data using the theme's
 * BEM CSS class patterns (cmsmasters-*) for visual consistency.
 *
 * WordPress template hierarchy: this file is loaded via CEM_Public::single_event_template()
 * for the `cem_event` post type when the active theme doesn't provide single-cem_event.php.
 *
 * @package ChurchEventManager
 */

defined( 'ABSPATH' ) || exit;

// ── Theme Compatibility ────────────────────────────────────────────────────────
// Gracefully support the CMSMasters Main_Elements wrapper if the theme provides it.
$has_main_elements = class_exists( 'ChristianMissionSpace\TemplateFunctions\Main_Elements' );

get_header();

if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_start(); // phpcs:ignore
}

// ── iCal Download Handler (handled before output) ─────────────────────────────
// Actual output is in CEM_Public::handle_ical_download() via template_redirect.

while ( have_posts() ) :
	the_post();

	$event_id = get_the_ID();

	// ── Pull All Plugin Meta ───────────────────────────────────────────────────
	$start_dt    = get_post_meta( $event_id, '_cem_start_datetime', true );
	$end_dt      = get_post_meta( $event_id, '_cem_end_datetime', true );
	$location    = get_post_meta( $event_id, '_cem_location', true );
	$address     = get_post_meta( $event_id, '_cem_location_address', true );
	$map_url     = get_post_meta( $event_id, '_cem_location_url', true );
	$organizer   = get_post_meta( $event_id, '_cem_organizer', true );
	$price       = get_post_meta( $event_id, '_cem_price', true );
	$status      = get_post_meta( $event_id, '_cem_event_status', true ) ?: 'open';
	$is_online   = get_post_meta( $event_id, '_cem_online_event', true );
	$stream_url  = get_post_meta( $event_id, '_cem_stream_url', true );
	$capacity    = (int) get_post_meta( $event_id, '_cem_capacity', true );
	$max_per_reg = (int) get_post_meta( $event_id, '_cem_max_attendees_per_reg', true );
	$deadline    = get_post_meta( $event_id, '_cem_registration_deadline', true );
	$reg_status  = get_post_meta( $event_id, '_cem_registration_status', true ) ?: 'open';

	// ── Format Dates & Times ──────────────────────────────────────────────────
	$start_date    = '';
	$start_time    = '';
	$end_date      = '';
	$end_time      = '';
	$event_day     = '';
	$event_day_num = '';
	$event_month   = '';

	if ( $start_dt ) {
		$ts            = strtotime( $start_dt );
		$start_date    = class_exists( 'CEM_Helpers' ) ? CEM_Helpers::format_date( $start_dt )  : date_i18n( get_option( 'date_format' ), $ts );
		$start_time    = class_exists( 'CEM_Helpers' ) ? CEM_Helpers::format_time( $start_dt )  : date_i18n( get_option( 'time_format' ), $ts );
		$event_day     = date_i18n( 'D', $ts );
		$event_day_num = date_i18n( 'j', $ts );
		$event_month   = date_i18n( 'M', $ts );
	}

	if ( $end_dt ) {
		$ts_end   = strtotime( $end_dt );
		$end_date = class_exists( 'CEM_Helpers' ) ? CEM_Helpers::format_date( $end_dt ) : date_i18n( get_option( 'date_format' ), $ts_end );
		$end_time = class_exists( 'CEM_Helpers' ) ? CEM_Helpers::format_time( $end_dt ) : date_i18n( get_option( 'time_format' ), $ts_end );
	}

	// ── Capacity & Registration State ─────────────────────────────────────────
	$at_capacity     = class_exists( 'CEM_Helpers' ) ? CEM_Helpers::is_at_capacity( $event_id )    : false;
	$spots_remaining = ( $capacity > 0 && class_exists( 'CEM_Helpers' ) ) ? CEM_Helpers::get_spots_remaining( $event_id ) : null;
	$reg_count       = class_exists( 'CEM_Helpers' ) ? CEM_Helpers::get_registration_count( $event_id ) : 0;
	$deadline_passed = $deadline && strtotime( $deadline ) < time();
	$can_register    = ( $reg_status === 'open' && $status === 'open' && ! $at_capacity && ! $deadline_passed );

	// ── Pre-calculate Capacity & My-Registrations Vars ────────────────────────
	// Done here (not inside the sidebar block) so the full-width registration
	// section — which sits outside the grid — can use them without re-querying.
	$pct         = 0;
	$bar_class   = '';
	$spots_label = '';
	if ( $capacity > 0 ) {
		$pct       = min( 100, (int) round( ( $reg_count / $capacity ) * 100 ) );
		$bar_class = $pct >= 90 ? 'cem-capacity-bar__fill--critical'
				   : ( $pct >= 70 ? 'cem-capacity-bar__fill--warning' : '' );
		$spots_label = ( $spots_remaining !== null )
			? sprintf( _n( '%d spot remaining', '%d spots remaining', $spots_remaining, 'church-event-manager' ), $spots_remaining )
			: sprintf( __( '%d registered', 'church-event-manager' ), $reg_count );
	}
	$my_regs_page_id = get_option( 'cem_registrations_page_id' );
	$my_regs_url     = $my_regs_page_id ? get_permalink( $my_regs_page_id ) : '';

	// ── Price Display ─────────────────────────────────────────────────────────
	$price_display = ( $price === '' || (float) $price === 0.0 )
		? __( 'Free', 'church-event-manager' )
		: '$' . number_format( (float) $price, 2 );

	// ── Taxonomies ────────────────────────────────────────────────────────────
	$tax_categories = get_the_term_list( $event_id, 'cem_event_category', '', ', ' );
	$tax_ministries = get_the_term_list( $event_id, 'cem_ministry', '', ', ' );
	$tax_tags       = get_the_term_list( $event_id, 'cem_event_tag', '', ', ' );

	// ── Church Info ───────────────────────────────────────────────────────────
	$church_name  = get_option( 'cem_church_name', get_bloginfo( 'name' ) );
	$church_email = get_option( 'admin_email' );

	// ── Add-to-Calendar URLs ──────────────────────────────────────────────────
	$google_cal_url = '';
	$ical_url       = '';

	if ( $start_dt ) {
		$cal_title  = rawurlencode( get_the_title() );
		$cal_start  = gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) );
		$cal_end    = $end_dt ? gmdate( 'Ymd\THis\Z', strtotime( $end_dt ) ) : gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) + 3600 );
		$cal_loc    = rawurlencode( $address ?: $location );
		$cal_desc   = rawurlencode( wp_strip_all_tags( get_the_excerpt() ) );
		$google_cal_url = "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$cal_title}&dates={$cal_start}/{$cal_end}&location={$cal_loc}&details={$cal_desc}";
		$ical_url       = add_query_arg( [ 'cem_ical' => 1, 'event_id' => $event_id ], home_url( '/' ) );
	}

	// Status label map
	$status_labels = [
		'open'      => __( 'Registration Open', 'church-event-manager' ),
		'closed'    => __( 'Registration Closed', 'church-event-manager' ),
		'cancelled' => __( 'Cancelled', 'church-event-manager' ),
		'past'      => __( 'Past Event', 'church-event-manager' ),
	];
	$status_label = $status_labels[ $status ] ?? ucfirst( $status );
	?>

	<article id="post-<?php the_ID(); ?>" <?php post_class( 'cmsmasters-single-post cem-single-event' ); ?>>

		<?php /* ═══════════════════════════════════════════════════════════════
		   HERO: Featured Image with overlay
		   ════════════════════════════════════════════════════════════════════ */ ?>

		<?php if ( has_post_thumbnail() ) : ?>
		<div class="cem-event-hero cmsmasters-section-container">
			<?php the_post_thumbnail( 'full', [ 'class' => 'cem-event-hero__img', 'alt' => esc_attr( get_the_title() ) ] ); ?>
			<div class="cem-event-hero__overlay">
				<div class="cem-event-hero__overlay-inner">
					<?php if ( $status !== 'open' ) : ?>
						<span class="cem-status-pill cem-status-pill--<?php echo esc_attr( $status ); ?>">
							<?php echo esc_html( $status_label ); ?>
						</span>
					<?php endif; ?>
					<?php if ( $tax_categories && ! is_wp_error( $tax_categories ) ) : ?>
						<div class="cem-event-hero__cats"><?php echo wp_kses_post( $tax_categories ); ?></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php endif; ?>


		<?php /* ═══════════════════════════════════════════════════════════════
		   TITLE & META HEADER
		   ════════════════════════════════════════════════════════════════════ */ ?>

		<header class="cmsmasters-single-post-title cmsmasters-section-container entry-header cem-event-header">
			<?php if ( $tax_ministries && ! is_wp_error( $tax_ministries ) ) : ?>
				<p class="cem-event-ministry-label"><?php echo wp_kses_post( $tax_ministries ); ?></p>
			<?php endif; ?>

			<h1 class="cmsmasters-single-post-title__tag entry-title cem-event-title">
				<?php the_title(); ?>
			</h1>

			<?php if ( $organizer ) : ?>
				<p class="cem-event-organizer-byline">
					<span class="cem-event-organizer-byline__label"><?php esc_html_e( 'Organized by', 'church-event-manager' ); ?></span>
					<strong class="cem-event-organizer-byline__name"><?php echo esc_html( $organizer ); ?></strong>
				</p>
			<?php endif; ?>
		</header>


		<?php /* ═══════════════════════════════════════════════════════════════
		   QUICK-INFO BAR  (date · time · location · price)
		   ════════════════════════════════════════════════════════════════════ */ ?>

		<?php if ( $start_dt || $location || $is_online || $price !== '' ) : ?>
		<div class="cem-info-bar cmsmasters-section-container">
			<div class="cem-info-bar__inner">

				<?php if ( $start_date ) : ?>
				<div class="cem-info-bar__item">
					<span class="cem-info-bar__icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
					</span>
					<span class="cem-info-bar__text">
						<span class="cem-info-bar__label"><?php esc_html_e( 'Date', 'church-event-manager' ); ?></span>
						<span class="cem-info-bar__value">
							<?php echo esc_html( $start_date ); ?>
							<?php if ( $end_date && $end_date !== $start_date ) : ?>
								&ndash; <?php echo esc_html( $end_date ); ?>
							<?php endif; ?>
						</span>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( $start_time ) : ?>
				<div class="cem-info-bar__item">
					<span class="cem-info-bar__icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
					</span>
					<span class="cem-info-bar__text">
						<span class="cem-info-bar__label"><?php esc_html_e( 'Time', 'church-event-manager' ); ?></span>
						<span class="cem-info-bar__value">
							<?php echo esc_html( $start_time ); ?>
							<?php if ( $end_time ) : ?>
								&ndash; <?php echo esc_html( $end_time ); ?>
							<?php endif; ?>
						</span>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( $location || $is_online ) : ?>
				<div class="cem-info-bar__item">
					<span class="cem-info-bar__icon" aria-hidden="true">
						<?php if ( $is_online ) : ?>
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
						<?php else : ?>
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
						<?php endif; ?>
					</span>
					<span class="cem-info-bar__text">
						<span class="cem-info-bar__label">
							<?php echo $is_online ? esc_html__( 'Online Event', 'church-event-manager' ) : esc_html__( 'Location', 'church-event-manager' ); ?>
						</span>
						<span class="cem-info-bar__value">
							<?php if ( $location ) echo esc_html( $location ); ?>
							<?php if ( $is_online && $stream_url ) : ?>
								<a href="<?php echo esc_url( $stream_url ); ?>" target="_blank" rel="noopener noreferrer" class="cem-info-bar__link">
									<?php esc_html_e( 'Join Online &rarr;', 'church-event-manager' ); ?>
								</a>
							<?php endif; ?>
						</span>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( $price !== '' ) : ?>
				<div class="cem-info-bar__item">
					<span class="cem-info-bar__icon" aria-hidden="true">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
					</span>
					<span class="cem-info-bar__text">
						<span class="cem-info-bar__label"><?php esc_html_e( 'Price', 'church-event-manager' ); ?></span>
						<span class="cem-info-bar__value <?php echo ( (float) $price === 0.0 || $price === '' ) ? 'cem-info-bar__value--free' : ''; ?>">
							<?php echo esc_html( $price_display ); ?>
						</span>
					</span>
				</div>
				<?php endif; ?>

			</div><!-- /.cem-info-bar__inner -->
		</div><!-- /.cem-info-bar -->
		<?php endif; ?>


		<?php /* ═══════════════════════════════════════════════════════════════
		   TWO-COLUMN BODY  (main content + registration sidebar)
		   ════════════════════════════════════════════════════════════════════ */ ?>

		<div class="cem-event-body cmsmasters-section-container">
			<div class="cem-event-body__grid">

				<!-- ─── MAIN COLUMN ──────────────────────────────────────── -->
				<div class="cem-event-body__main">

					<?php /* Event Description / WP Post Content */ ?>
					<div class="cmsmasters-single-post-content entry-content cem-event-description">
						<?php the_content(); ?>
					</div>


					<?php /* Online Event Join Link (large CTA, when no stream details in sidebar) */ ?>
					<?php if ( $is_online && $stream_url ) : ?>
					<div class="cem-online-cta">
						<h3 class="cem-section-heading">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="cem-section-heading__icon"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
							<?php esc_html_e( 'Join Online', 'church-event-manager' ); ?>
						</h3>
						<a href="<?php echo esc_url( $stream_url ); ?>" class="cem-btn cem-btn--primary" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Join Stream &rarr;', 'church-event-manager' ); ?>
						</a>
					</div>
					<?php endif; ?>

		<section class="cem-event-body__reg-section" id="cem-registration-anchor">
			<div class="cem-reg-full-card">

				<?php /* ── Card Header: title + status pill + capacity + deadline ── */ ?>
				<div class="cem-reg-full-card__header">

					<div class="cem-reg-full-card__title-col">
						<div class="cem-reg-full-card__title-row">
							<h3 class="cem-reg-full-card__title">
								<?php esc_html_e( 'Register for This Event', 'church-event-manager' ); ?>
							</h3>

							<?php if ( $status !== 'cancelled' && $status !== 'past' ) : ?>
								<span class="cem-status-pill cem-status-pill--<?php echo esc_attr( $can_register ? 'open' : 'closed' ); ?>">
									<?php echo $can_register
										? esc_html__( 'Registration Open', 'church-event-manager' )
										: esc_html__( 'Registration Closed', 'church-event-manager' ); ?>
								</span>
							<?php endif; ?>
						</div><!-- /.cem-reg-full-card__title-row -->

						<?php if ( $deadline && ! $deadline_passed ) : ?>
						<p class="cem-deadline-notice">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
							<?php printf(
								/* translators: %s: registration deadline date */
								esc_html__( 'Register by %s', 'church-event-manager' ),
								'<strong>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $deadline ) ) ) . '</strong>'
							); ?>
						</p>
						<?php endif; ?>

						<?php if ( $max_per_reg > 0 ) : ?>
						<p class="cem-max-notice" style="margin-bottom:0">
							<?php printf(
								/* translators: %d: max attendees per registration */
								esc_html__( 'Maximum %d attendees per registration.', 'church-event-manager' ),
								$max_per_reg
							); ?>
						</p>
						<?php endif; ?>
					</div><!-- /.cem-reg-full-card__title-col -->

					<?php if ( $capacity > 0 ) : ?>
					<div class="cem-capacity-bar cem-reg-full-card__capacity">
						<div class="cem-capacity-bar__header">
							<span class="cem-capacity-bar__label"><?php echo esc_html( $spots_label ); ?></span>
							<span class="cem-capacity-bar__pct"><?php echo esc_html( $pct ); ?>%</span>
						</div>
						<div class="cem-capacity-bar__track" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100">
							<div class="cem-capacity-bar__fill <?php echo esc_attr( $bar_class ); ?>" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
						</div>
					</div>
					<?php endif; ?>

				</div><!-- /.cem-reg-full-card__header -->

				<?php /* ── Status notices & form body ────────────────────── */ ?>

				<?php if ( $status === 'cancelled' ) : ?>
					<div class="cem-reg-notice cem-reg-notice--cancelled">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
						<strong><?php esc_html_e( 'This event has been cancelled.', 'church-event-manager' ); ?></strong>
					</div>

				<?php elseif ( $status === 'past' ) : ?>
					<div class="cem-reg-notice cem-reg-notice--past">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						<strong><?php esc_html_e( 'This event has already taken place.', 'church-event-manager' ); ?></strong>
					</div>

				<?php elseif ( $reg_status !== 'open' || $status === 'closed' ) : ?>
					<div class="cem-reg-notice cem-reg-notice--closed">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
						<div>
							<strong><?php esc_html_e( 'Registration is closed.', 'church-event-manager' ); ?></strong>
							<p><?php esc_html_e( 'Registration for this event is not currently available.', 'church-event-manager' ); ?></p>
						</div>
					</div>

				<?php elseif ( $deadline_passed ) : ?>
					<div class="cem-reg-notice cem-reg-notice--closed">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						<strong><?php esc_html_e( 'Registration deadline has passed.', 'church-event-manager' ); ?></strong>
					</div>

				<?php elseif ( $at_capacity ) : ?>
					<div class="cem-reg-notice cem-reg-notice--waitlist">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
						<div>
							<strong><?php esc_html_e( 'This event is full.', 'church-event-manager' ); ?></strong>
							<p><?php esc_html_e( 'Join the waitlist — we\'ll notify you if a spot opens up.', 'church-event-manager' ); ?></p>
						</div>
					</div>
					<?php echo do_shortcode( '[cem_registration_form event_id="' . $event_id . '"]' ); ?>

				<?php else : ?>
					<?php echo do_shortcode( '[cem_registration_form event_id="' . $event_id . '"]' ); ?>

				<?php endif; /* end registration status */ ?>

				<?php if ( $my_regs_url ) : ?>
				<div class="cem-reg-card__footer">
					<a href="<?php echo esc_url( $my_regs_url ); ?>" class="cem-subtle-link">
						<?php esc_html_e( '&larr; View my registrations', 'church-event-manager' ); ?>
					</a>
				</div>
				<?php endif; ?>

			</div><!-- /.cem-reg-full-card -->
		</section><!-- /.cem-event-body__reg-section -->

					<?php /* Tags */ ?>
					<?php if ( $tax_tags && ! is_wp_error( $tax_tags ) ) : ?>
					<div class="cem-event-tags">
						<span class="cem-event-tags__label"><?php esc_html_e( 'Tags:', 'church-event-manager' ); ?></span>
						<div class="cem-event-tags__list"><?php echo wp_kses_post( $tax_tags ); ?></div>
					</div>
					<?php endif; ?>

				</div><!-- /.cem-event-body__main -->


				<!-- ─── SIDEBAR (compact: date badge · calendar · organizer) ──── -->
				<aside class="cem-event-body__sidebar">

					<?php /* ── Venue Details Card ─────────────────── */ ?>
					<?php if ( $address || $map_url || $location ) : ?>
					<div class="cem-sidebar-card cem-venue-card">
						<h4 class="cem-sidebar-card__heading">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
							<?php esc_html_e( 'Venue Details', 'church-event-manager' ); ?>
						</h4>
						<?php if ( $location ) : ?>
							<p class="cem-venue-card__name"><?php echo esc_html( $location ); ?></p>
						<?php endif; ?>
						<?php if ( $address ) : ?>
							<address class="cem-venue-card__address"><?php echo nl2br( esc_html( $address ) ); ?></address>
						<?php endif; ?>
						<?php if ( $map_url ) : ?>
							<a href="<?php echo esc_url( $map_url ); ?>" class="cem-cal-link" target="_blank" rel="noopener noreferrer">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="3 11 22 2 13 21 11 13 3 11"/></svg>
								<?php esc_html_e( 'Get Directions', 'church-event-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<?php /* ── Add to Calendar Card ──────────────────── */ ?>
					<?php if ( $start_dt ) : ?>
					<div class="cem-sidebar-card cem-cal-card">
						<h4 class="cem-sidebar-card__heading">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
							<?php esc_html_e( 'Add to Calendar', 'church-event-manager' ); ?>
						</h4>
						<div class="cem-cal-links">
							<a href="<?php echo esc_url( $google_cal_url ); ?>" target="_blank" rel="noopener noreferrer" class="cem-cal-link">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
								<?php esc_html_e( 'Google Calendar', 'church-event-manager' ); ?>
							</a>
							<a href="<?php echo esc_url( $ical_url ); ?>" class="cem-cal-link">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
								<?php esc_html_e( 'Download .ics', 'church-event-manager' ); ?>
							</a>
						</div>
					</div>
					<?php endif; ?>


					<?php /* ── Organizer / Contact Card ─────────────── */ ?>
					<?php if ( $organizer || $church_email ) : ?>
					<div class="cem-sidebar-card cem-organizer-card">
						<h4 class="cem-sidebar-card__heading">
							<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
							<?php esc_html_e( 'Event Organizer', 'church-event-manager' ); ?>
						</h4>
						<p class="cem-organizer-name"><?php echo esc_html( $organizer ?: $church_name ); ?></p>
						<?php if ( $church_email ) : ?>
							<a href="mailto:<?php echo esc_attr( $church_email ); ?>" class="cem-subtle-link">
								<?php esc_html_e( 'Contact Organizer', 'church-event-manager' ); ?>
							</a>
						<?php endif; ?>
					</div>
					<?php endif; ?>

				</aside><!-- /.cem-event-body__sidebar -->

			</div><!-- /.cem-event-body__grid -->

		</div><!-- /.cem-event-body -->

	</article><!-- #post-<?php the_ID(); ?> -->

<?php endwhile; ?>

<?php
if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_end(); // phpcs:ignore
}

get_footer();
