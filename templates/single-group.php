<?php
/**
 * Single Small Group Template
 *
 * The hero image is intentionally rendered BEFORE main_wrapper_start() so it
 * can break out to full viewport width and slide behind the fixed navbar.
 *
 * @package ChurchEventManager
 */
defined( 'ABSPATH' ) || exit;

$has_main_elements = class_exists( 'ChristianMissionSpace\TemplateFunctions\Main_Elements' );

get_header();

// Prime the loop early so we can access post data before the wrapper opens.
the_post();

$group_id = get_the_ID();

// ── Group Meta ─────────────────────────────────────────────────────────────
$type         = get_post_meta( $group_id, '_cem_group_type',         true );
$day          = get_post_meta( $group_id, '_cem_group_day',          true );
$time         = get_post_meta( $group_id, '_cem_group_time',         true );
$frequency    = get_post_meta( $group_id, '_cem_group_frequency',    true );
$leader       = get_post_meta( $group_id, '_cem_group_leader',       true );
$location     = get_post_meta( $group_id, '_cem_group_location',     true );
$address      = get_post_meta( $group_id, '_cem_group_address',      true );
$capacity     = (int) get_post_meta( $group_id, '_cem_group_capacity', true );
$status       = get_post_meta( $group_id, '_cem_group_status',       true ) ?: 'open';
$start_date   = get_post_meta( $group_id, '_cem_group_start_date',   true );
$end_date     = get_post_meta( $group_id, '_cem_group_end_date',     true );
$childcare    = get_post_meta( $group_id, '_cem_group_childcare',    true ) === '1';
$online       = get_post_meta( $group_id, '_cem_group_online',       true ) === '1';
$meeting_url  = get_post_meta( $group_id, '_cem_group_meeting_url',  true );
$description  = get_post_meta( $group_id, '_cem_group_description',  true );

// ── Back-to-Groups URL ────────────────────────────────────────────────────
$groups_page_id = get_option( 'cem_groups_page_id' );
// Fall back to the cem_group post type archive if no page is set
$groups_url     = $groups_page_id
	? get_permalink( $groups_page_id )
	: get_post_type_archive_link( 'cem_group' );

// ── Computed Values ───────────────────────────────────────────────────────
$members    = $capacity > 0 ? CEM_Group::get_signup_count( $group_id ) : 0;
$is_full    = $capacity > 0 && $members >= $capacity;
$spots_left = $capacity > 0 ? max( 0, $capacity - $members ) : null;
$can_join   = ( $status === 'open' && ! $is_full );

$fmt_time       = CEM_Group::format_time( $time );
$schedule_parts = array_filter( [ $frequency ? ucwords( $frequency ) : '', $day, $fmt_time ] );
$schedule       = implode( ' · ', $schedule_parts );

$group_types = CEM_Group::group_types();
$type_label  = $group_types[ $type ] ?? '';

$status_labels = [
	'open'     => __( 'Open',     'church-event-manager' ),
	'closed'   => __( 'Closed',   'church-event-manager' ),
	'full'     => __( 'Full',     'church-event-manager' ),
	'inactive' => __( 'Inactive', 'church-event-manager' ),
];
$status_label = $status_labels[ $status ] ?? ucfirst( $status );

// ── Hero (always rendered before wrapper — image or gradient fallback) ──
// Map group type to a gradient colour class so no-image pages still look polished.
$type_to_ph = [
	'bible-study'  => 'amber',
	'prayer'       => 'indigo',
	'mens'         => 'navy',
	'womens'       => 'rose',
	'couples'      => 'rose',
	'young-adults' => 'teal',
	'youth'        => 'teal',
	'seniors'      => 'slate',
	'outreach'     => 'forest',
	'recovery'     => 'amber',
	'other'        => 'slate',
	''             => 'slate',
];
$ph_color      = $type_to_ph[ $type ] ?? 'slate';
$has_thumbnail = has_post_thumbnail();
$hero_classes  = 'cem-group-hero' . ( $has_thumbnail ? '' : ' cem-group-hero--gradient cem-ph--' . esc_attr( $ph_color ) );
?>
<div class="<?php echo esc_attr( $hero_classes ); ?>">
	<?php if ( $has_thumbnail ) : ?>
	<?php the_post_thumbnail( 'full', [ 'class' => 'cem-group-hero-img' ] ); ?>
	<div class="cem-group-hero-overlay"></div>
	<?php endif; ?>
	<div class="cem-group-hero-content">
		<?php if ( $groups_url ) : ?>
		<a href="<?php echo esc_url( $groups_url ); ?>" class="cem-back-link cem-back-link--hero">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
			<?php esc_html_e( 'All Groups', 'church-event-manager' ); ?>
		</a>
		<?php endif; ?>
		<div class="cem-group-hero-badges">
			<?php if ( $type_label ) : ?>
			<span class="cem-group-type-badge"><?php echo esc_html( $type_label ); ?></span>
			<?php endif; ?>
			<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status ); ?>">
				<?php echo esc_html( $status_label ); ?>
			</span>
		</div>
		<h1 class="cem-group-title"><?php the_title(); ?></h1>
	</div>
</div>

<?php
// ── Open the theme's main content wrapper ────────────────────────────────
if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_start(); // phpcs:ignore
}
?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'cem-single-group' ); ?>>

	<?php if ( $groups_url ) : ?>
	<div class="cem-back-to-events">
		<a href="<?php echo esc_url( $groups_url ); ?>" class="cem-back-link">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
			<?php esc_html_e( 'All Groups', 'church-event-manager' ); ?>
		</a>
	</div>
	<?php endif; ?>


	<div class="cem-group-layout">

		<!-- Main content column -->
		<div class="cem-group-main">

			<?php
			// Dedicated description meta first, fall back to post content
			$display_desc = $description ?: get_the_content();
			if ( $display_desc ) : ?>
			<section class="cem-group-description">
				<?php if ( $description ) : ?>
					<?php echo wp_kses_post( wpautop( $description ) ); ?>
				<?php else : ?>
					<?php the_content(); ?>
				<?php endif; ?>
			</section>
			<?php endif; ?>

			<?php
			$linked_events   = CEM_Group::get_linked_events( $group_id );
			$now             = current_time( 'timestamp' );
			$upcoming_events = array_filter( $linked_events, function( $ev ) use ( $now ) {
				$start = get_post_meta( $ev->ID, '_cem_start_datetime', true );
				return $start && strtotime( $start ) >= $now;
			} );
			if ( ! empty( $upcoming_events ) ) : ?>
			<section class="cem-group-events">
				<h2 class="cem-group-events-heading"><?php esc_html_e( 'Upcoming Events', 'church-event-manager' ); ?></h2>
				<ul class="cem-group-events-list">
					<?php foreach ( $upcoming_events as $ev ) :
						$ev_start    = get_post_meta( $ev->ID, '_cem_start_datetime', true );
						$ev_location = get_post_meta( $ev->ID, '_cem_location', true ) ?: $location;
						?>
					<li class="cem-group-event-item">
						<div class="cem-group-event-date">
							<span class="cem-group-event-day"><?php echo esc_html( wp_date( 'j', strtotime( $ev_start ) ) ); ?></span>
							<span class="cem-group-event-month"><?php echo esc_html( wp_date( 'M', strtotime( $ev_start ) ) ); ?></span>
						</div>
						<div class="cem-group-event-info">
							<a class="cem-group-event-title" href="<?php echo esc_url( get_permalink( $ev->ID ) ); ?>"><?php echo esc_html( $ev->post_title ); ?></a>
							<span class="cem-group-event-meta">
								<?php echo esc_html( CEM_Helpers::format_time( $ev_start ) ); ?>
								<?php if ( $ev_location ) : ?>
									&nbsp;·&nbsp; <?php echo esc_html( $ev_location ); ?>
								<?php endif; ?>
							</span>
						</div>
					</li>
					<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>

			<!-- Signup form — lives in the main column so it fills the space -->
			<?php if ( $can_join ) : ?>
			<section class="cem-group-join-section">
				<h2 class="cem-group-join-heading"><?php esc_html_e( 'Join This Group', 'church-event-manager' ); ?></h2>
				<?php echo CEM_Group::render_signup_form( $group_id ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</section>
			<?php elseif ( $is_full || $status === 'full' ) : ?>
			<div class="cem-group-status-notice cem-group-full">
				<p><?php esc_html_e( 'This group is currently full. Check back for openings!', 'church-event-manager' ); ?></p>
			</div>
			<?php elseif ( $status === 'closed' ) : ?>
			<div class="cem-group-status-notice cem-group-closed">
				<p><?php esc_html_e( 'This group is not currently accepting new members.', 'church-event-manager' ); ?></p>
			</div>
			<?php elseif ( $status === 'inactive' ) : ?>
			<div class="cem-group-status-notice cem-group-ended">
				<p><?php esc_html_e( 'This group is no longer active.', 'church-event-manager' ); ?></p>
			</div>
			<?php endif; ?>

		</div><!-- .cem-group-main -->

		<!-- Sidebar: details + leave group only -->
		<aside class="cem-group-sidebar">

			<!-- Details Card -->
			<div class="cem-group-details-card">
				<h3 class="cem-card-heading"><?php esc_html_e( 'Group Details', 'church-event-manager' ); ?></h3>

				<?php if ( $schedule ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">🗓</span>
					<span class="cem-detail-text"><?php echo esc_html( $schedule ); ?></span>
				</div>
				<?php endif; ?>

				<?php if ( $location ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">📍</span>
					<span class="cem-detail-text">
						<?php echo esc_html( $location ); ?>
						<?php if ( $address ) : ?>
						<small><?php echo esc_html( $address ); ?></small>
						<?php endif; ?>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( $leader ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">👤</span>
					<span class="cem-detail-text"><?php echo esc_html( $leader ); ?></span>
				</div>
				<?php endif; ?>

				<?php if ( $capacity > 0 ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">👥</span>
					<span class="cem-detail-text">
						<?php printf(
							esc_html__( '%1$d of %2$d spots filled', 'church-event-manager' ),
							$members,
							$capacity
						); ?>
						<?php if ( $spots_left !== null && $spots_left <= 5 && $spots_left > 0 ) : ?>
						<small class="cem-spots-warning">
							<?php printf( esc_html__( 'Only %d left!', 'church-event-manager' ), $spots_left ); ?>
						</small>
						<?php endif; ?>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( $start_date || $end_date ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">📅</span>
					<span class="cem-detail-text">
						<?php if ( $start_date && $end_date ) : ?>
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $start_date ) ) ); ?>
							–
							<?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $end_date ) ) ); ?>
						<?php elseif ( $start_date ) : ?>
							<?php printf( esc_html__( 'Starts %s', 'church-event-manager' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $start_date ) ) ) ); ?>
						<?php elseif ( $end_date ) : ?>
							<?php printf( esc_html__( 'Ends %s', 'church-event-manager' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $end_date ) ) ) ); ?>
						<?php endif; ?>
					</span>
				</div>
				<?php endif; ?>

				<?php if ( $childcare ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">👶</span>
					<span class="cem-detail-text"><?php esc_html_e( 'Childcare Available', 'church-event-manager' ); ?></span>
				</div>
				<?php endif; ?>

				<?php if ( $online && $meeting_url ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">💻</span>
					<span class="cem-detail-text">
						<a href="<?php echo esc_url( $meeting_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Join Online →', 'church-event-manager' ); ?>
						</a>
					</span>
				</div>
				<?php elseif ( $online ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">💻</span>
					<span class="cem-detail-text"><?php esc_html_e( 'Online Option Available', 'church-event-manager' ); ?></span>
				</div>
				<?php endif; ?>
			</div><!-- .cem-group-details-card -->

			<!-- Leave Group -->
			<div class="cem-leave-group-wrap">
				<h3 class="cem-card-heading"><?php esc_html_e( 'Already a Member?', 'church-event-manager' ); ?></h3>
				<p class="cem-leave-group-desc"><?php esc_html_e( 'Enter your email address to remove yourself from this group.', 'church-event-manager' ); ?></p>
				<form class="cem-leave-group-form" data-group-id="<?php echo esc_attr( $group_id ); ?>">
					<input type="email" name="leave_email" placeholder="<?php esc_attr_e( 'your@email.com', 'church-event-manager' ); ?>" required class="cem-leave-email">
					<button type="submit" class="cem-leave-group-btn"><?php esc_html_e( 'Leave Group', 'church-event-manager' ); ?></button>
					<div class="cem-leave-group-msg" style="display:none"></div>
				</form>
			</div>

		</aside><!-- .cem-group-sidebar -->

	</div><!-- .cem-group-layout -->

</article>

<?php
if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_end(); // phpcs:ignore
}

get_footer();
