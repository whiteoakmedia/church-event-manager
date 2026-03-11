<?php
/**
 * Single Small Group Template
 *
 * Loaded by CEM_Group::single_group_template() when no theme template or
 * builder template claims the cem_group post type.
 *
 * @package ChurchEventManager
 */
defined( 'ABSPATH' ) || exit;

$has_main_elements = class_exists( 'ChristianMissionSpace\TemplateFunctions\Main_Elements' );

get_header();

if ( $has_main_elements ) {
	echo ChristianMissionSpace\TemplateFunctions\Main_Elements::main_wrapper_start(); // phpcs:ignore
}

while ( have_posts() ) :
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

	// ── Computed Values ───────────────────────────────────────────────────────
	$members    = $capacity > 0 ? CEM_Group::get_signup_count( $group_id ) : 0;
	$is_full    = $capacity > 0 && $members >= $capacity;
	$spots_left = $capacity > 0 ? max( 0, $capacity - $members ) : null;
	$can_join   = ( $status === 'open' && ! $is_full );

	$fmt_time      = CEM_Group::format_time( $time );
	$schedule_parts = array_filter( [ $frequency ? ucwords( $frequency ) : '', $day, $fmt_time ] );
	$schedule      = implode( ' · ', $schedule_parts );

	$group_types   = CEM_Group::group_types();
	$type_label    = $group_types[ $type ] ?? '';

	$status_labels = [
		'open'     => __( 'Open',     'church-event-manager' ),
		'closed'   => __( 'Closed',   'church-event-manager' ),
		'full'     => __( 'Full',     'church-event-manager' ),
		'inactive' => __( 'Inactive', 'church-event-manager' ),
	];
	$status_label = $status_labels[ $status ] ?? ucfirst( $status );
	?>

<article id="post-<?php the_ID(); ?>" <?php post_class( 'cem-single-group' ); ?>>

	<?php if ( has_post_thumbnail() ) : ?>
	<div class="cem-group-hero">
		<?php the_post_thumbnail( 'large', [ 'class' => 'cem-group-hero-img' ] ); ?>
		<div class="cem-group-hero-overlay"></div>
		<div class="cem-group-hero-content">
			<?php if ( $type_label ) : ?>
			<span class="cem-group-type-badge"><?php echo esc_html( $type_label ); ?></span>
			<?php endif; ?>
			<h1 class="cem-group-title"><?php the_title(); ?></h1>
			<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status ); ?>">
				<?php echo esc_html( $status_label ); ?>
			</span>
		</div>
	</div>
	<?php else : ?>
	<header class="cem-group-header">
		<div class="cem-group-header-badges">
			<?php if ( $type_label ) : ?>
			<span class="cem-group-type-badge"><?php echo esc_html( $type_label ); ?></span>
			<?php endif; ?>
			<span class="cem-badge cem-group-status cem-group-status--<?php echo esc_attr( $status ); ?>">
				<?php echo esc_html( $status_label ); ?>
			</span>
		</div>
		<h1 class="cem-group-title"><?php the_title(); ?></h1>
	</header>
	<?php endif; ?>

	<div class="cem-group-layout">

		<!-- Main content column -->
		<div class="cem-group-main">

			<?php if ( get_the_content() ) : ?>
			<section class="cem-group-description">
				<?php the_content(); ?>
			</section>
			<?php endif; ?>

		</div><!-- .cem-group-main -->

		<!-- Sidebar -->
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
			</div><!-- .cem-group-details-card -->

			<!-- Join Form -->
			<?php if ( $can_join ) : ?>
			<div class="cem-group-signup-wrap">
				<h3 class="cem-card-heading"><?php esc_html_e( 'Join This Group', 'church-event-manager' ); ?></h3>
				<?php echo do_shortcode( '[cem_registration_form event_id="' . $group_id . '"]' ); ?>
			</div>
			<?php elseif ( $is_full || $status === 'full' ) : ?>
			<div class="cem-group-signup-wrap cem-group-full">
				<p><?php esc_html_e( 'This group is full.', 'church-event-manager' ); ?></p>
			</div>
			<?php elseif ( $status === 'closed' ) : ?>
			<div class="cem-group-signup-wrap cem-group-closed">
				<p><?php esc_html_e( 'This group is not currently accepting new members.', 'church-event-manager' ); ?></p>
			</div>
			<?php elseif ( $status === 'inactive' ) : ?>
			<div class="cem-group-signup-wrap cem-group-ended">
				<p><?php esc_html_e( 'This group is no longer active.', 'church-event-manager' ); ?></p>
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
