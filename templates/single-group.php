<?php
/**
 * Single Event Series (Group) Template
 *
 * Loaded by CEM_Group::single_group_template() when no theme template or
 * builder template claims the cem_group post type.
 *
 * @package ChurchEventManager
 */
defined( 'ABSPATH' ) || exit;

// Theme compatibility (same pattern as single-event.php).
$has_main_elements = class_exists( 'ChristianMissionSpace\TemplateFunctions\Main_Elements' );

get_header();

if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_start(); // phpcs:ignore
}

while ( have_posts() ) :
	the_post();

	$group_id   = get_the_ID();

	// ── Group Meta ─────────────────────────────────────────────────────────────
	$start_date = get_post_meta( $group_id, '_cem_group_start_date',          true );
	$end_date   = get_post_meta( $group_id, '_cem_group_end_date',            true );
	$location   = get_post_meta( $group_id, '_cem_group_location',            true );
	$address    = get_post_meta( $group_id, '_cem_group_address',             true );
	$capacity   = (int) get_post_meta( $group_id, '_cem_group_capacity',      true );
	$status_val = get_post_meta( $group_id, '_cem_group_status',              true ) ?: 'upcoming';
	$reg_status = get_post_meta( $group_id, '_cem_group_registration_status', true ) ?: 'open';

	// ── Format Dates ──────────────────────────────────────────────────────────
	$date_format = get_option( 'date_format' );
	$fmt_start   = $start_date ? wp_date( $date_format, strtotime( $start_date ) ) : '';
	$fmt_end     = $end_date   ? wp_date( $date_format, strtotime( $end_date ) )   : '';

	// ── Signups / Capacity ────────────────────────────────────────────────────
	$signup_count = $capacity > 0 ? CEM_Group::get_signup_count( $group_id ) : 0;
	$is_full      = $capacity > 0 && $signup_count >= $capacity;
	$spots_left   = $capacity > 0 ? max( 0, $capacity - $signup_count ) : null;

	// ── Linked Events ─────────────────────────────────────────────────────────
	$linked_events = CEM_Group::get_linked_events( $group_id );

	// ── Status Labels ─────────────────────────────────────────────────────────
	$status_labels = [
		'upcoming'  => __( 'Upcoming',  'church-event-manager' ),
		'ongoing'   => __( 'Ongoing',   'church-event-manager' ),
		'completed' => __( 'Completed', 'church-event-manager' ),
		'cancelled' => __( 'Cancelled', 'church-event-manager' ),
	];
	$status_label = $status_labels[ $status_val ] ?? ucfirst( $status_val );

	$reg_open = ( $reg_status === 'open' && ! $is_full && $status_val !== 'completed' && $status_val !== 'cancelled' );
	?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'cem-single-group' ); ?>>

	<?php if ( has_post_thumbnail() ) : ?>
	<div class="cem-group-hero">
		<?php the_post_thumbnail( 'large', [ 'class' => 'cem-group-hero-img' ] ); ?>
		<div class="cem-group-hero-overlay"></div>
		<div class="cem-group-hero-content">
			<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status_val ); ?>">
				<?php echo esc_html( $status_label ); ?>
			</span>
			<h1 class="cem-group-title"><?php the_title(); ?></h1>
		</div>
	</div>
	<?php else : ?>
	<header class="cem-group-header">
		<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status_val ); ?>">
			<?php echo esc_html( $status_label ); ?>
		</span>
		<h1 class="cem-group-title"><?php the_title(); ?></h1>
	</header>
	<?php endif; ?>

	<div class="cem-group-layout">

		<!-- Main content column -->
		<div class="cem-group-main">

			<!-- Description -->
			<?php if ( get_the_content() ) : ?>
			<section class="cem-group-description">
				<?php the_content(); ?>
			</section>
			<?php endif; ?>

			<!-- Linked Events Schedule -->
			<?php if ( ! empty( $linked_events ) ) : ?>
			<section class="cem-group-schedule">
				<h2 class="cem-section-heading"><?php esc_html_e( 'Schedule', 'church-event-manager' ); ?></h2>
				<ul class="cem-schedule-list">
				<?php foreach ( $linked_events as $ev ) :
					$ev_start = get_post_meta( $ev->ID, '_cem_start_datetime', true );
					$ev_end   = get_post_meta( $ev->ID, '_cem_end_datetime',   true );
					$ev_loc   = get_post_meta( $ev->ID, '_cem_location',       true );

					$ev_date = $ev_start ? wp_date( $date_format, strtotime( $ev_start ) ) : '';
					$ev_time = $ev_start ? wp_date( get_option( 'time_format' ), strtotime( $ev_start ) ) : '';
					if ( $ev_end ) {
						$ev_time .= ' &ndash; ' . wp_date( get_option( 'time_format' ), strtotime( $ev_end ) );
					}
				?>
				<li class="cem-schedule-item">
					<div class="cem-schedule-date">
						<?php if ( $ev_start ) : ?>
						<span class="cem-schedule-day"><?php echo esc_html( wp_date( 'j', strtotime( $ev_start ) ) ); ?></span>
						<span class="cem-schedule-month"><?php echo esc_html( wp_date( 'M', strtotime( $ev_start ) ) ); ?></span>
						<?php endif; ?>
					</div>
					<div class="cem-schedule-info">
						<a class="cem-schedule-title" href="<?php echo esc_url( get_permalink( $ev->ID ) ); ?>">
							<?php echo esc_html( $ev->post_title ); ?>
						</a>
						<?php if ( $ev_time ) : ?>
						<span class="cem-schedule-time"><?php echo $ev_time; // phpcs:ignore — formatted date string ?></span>
						<?php endif; ?>
						<?php if ( $ev_loc ) : ?>
						<span class="cem-schedule-loc">📍 <?php echo esc_html( $ev_loc ); ?></span>
						<?php endif; ?>
					</div>
				</li>
				<?php endforeach; ?>
				</ul>
			</section>
			<?php endif; ?>

		</div><!-- .cem-group-main -->

		<!-- Sidebar -->
		<aside class="cem-group-sidebar">

			<!-- Details Card -->
			<div class="cem-group-details-card">
				<h3 class="cem-card-heading"><?php esc_html_e( 'Series Details', 'church-event-manager' ); ?></h3>

				<?php if ( $fmt_start ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">📅</span>
					<span class="cem-detail-text">
						<?php if ( $fmt_end && $fmt_end !== $fmt_start ) : ?>
							<?php echo esc_html( $fmt_start . ' – ' . $fmt_end ); ?>
						<?php else : ?>
							<?php echo esc_html( $fmt_start ); ?>
						<?php endif; ?>
					</span>
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

				<?php if ( $capacity > 0 ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">👥</span>
					<span class="cem-detail-text">
						<?php printf(
							/* translators: 1: spots filled, 2: total capacity */
							esc_html__( '%1$d of %2$d spots filled', 'church-event-manager' ),
							$signup_count,
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

				<?php if ( ! empty( $linked_events ) ) : ?>
				<div class="cem-detail-row">
					<span class="cem-detail-icon">🗓</span>
					<span class="cem-detail-text">
						<?php printf(
							esc_html( _n( '%d session', '%d sessions', count( $linked_events ), 'church-event-manager' ) ),
							count( $linked_events )
						); ?>
					</span>
				</div>
				<?php endif; ?>
			</div><!-- .cem-group-details-card -->

			<!-- Sign-up Form -->
			<?php if ( $reg_open ) : ?>
			<div class="cem-group-signup-wrap">
				<h3 class="cem-card-heading"><?php esc_html_e( 'Sign Up', 'church-event-manager' ); ?></h3>
				<?php echo do_shortcode( '[cem_registration_form event_id="' . $group_id . '"]' ); ?>
			</div>
			<?php elseif ( $is_full ) : ?>
			<div class="cem-group-signup-wrap cem-group-full">
				<p><?php esc_html_e( 'This series is full.', 'church-event-manager' ); ?></p>
			</div>
			<?php elseif ( $reg_status === 'closed' ) : ?>
			<div class="cem-group-signup-wrap cem-group-closed">
				<p><?php esc_html_e( 'Registration is closed.', 'church-event-manager' ); ?></p>
			</div>
			<?php elseif ( in_array( $status_val, [ 'completed', 'cancelled' ], true ) ) : ?>
			<div class="cem-group-signup-wrap cem-group-ended">
				<p><?php echo esc_html( $status_val === 'cancelled'
					? __( 'This series has been cancelled.', 'church-event-manager' )
					: __( 'This series has ended.', 'church-event-manager' )
				); ?></p>
			</div>
			<?php endif; ?>

		</aside><!-- .cem-group-sidebar -->

	</div><!-- .cem-group-layout -->

</article>

<?php endwhile; ?>

<?php
if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_end(); // phpcs:ignore
}

get_footer();
