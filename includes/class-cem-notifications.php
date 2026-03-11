<?php
/**
 * Wires up notification emails via action hooks and scheduled reminders.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Notifications {

	public function init() {
		add_action( 'cem_after_registration',    [ $this, 'on_registration' ],      10, 2 );
		add_action( 'cem_registration_confirmed',[ $this, 'on_confirmation' ],      10, 2 );
		add_action( 'cem_registration_cancelled',[ $this, 'on_cancellation' ],      10, 2 );
		add_action( 'cem_waitlist_promoted',     [ $this, 'on_waitlist_promotion' ],10, 2 );
	}

	// ── Hooks ─────────────────────────────────────────────────────────────────

	public function on_registration( $registration_id, $event_id ) {
		// Admin notification
		if ( get_option( 'cem_admin_notify_on_register', '1' ) ) {
			CEM_Email::send_admin_notification( $registration_id );
		}
	}

	public function on_confirmation( $registration_id, $event_id ) {
		CEM_Email::send_confirmation( $registration_id );
	}

	public function on_cancellation( $registration_id, $event_id ) {
		CEM_Email::send_cancellation( $registration_id );
	}

	public function on_waitlist_promotion( $registration_id, $event_id ) {
		CEM_Email::send_waitlist_promotion( $registration_id );
		CEM_Email::send_confirmation( $registration_id );
	}

	// ── Scheduled Reminders ───────────────────────────────────────────────────

	/**
	 * Fired by daily cron. Sends reminders for events happening in the next
	 * N days (configured via cem_reminder_days_before).
	 */
	public function send_event_reminders() {
		if ( ! get_option( 'cem_send_reminders', '1' ) ) return;

		$days_before = (int) get_option( 'cem_reminder_days_before', 1 );
		global $wpdb;

		// Find events starting in exactly $days_before days (within a 24h window)
		$target_start = date( 'Y-m-d H:i:s', strtotime( "+{$days_before} days 00:00:00" ) );
		$target_end   = date( 'Y-m-d H:i:s', strtotime( "+{$days_before} days 23:59:59" ) );

		$events = $wpdb->get_results( $wpdb->prepare(
			"SELECT DISTINCT p.ID
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cem_start_datetime'
			 WHERE p.post_type = 'cem_event' AND p.post_status = 'publish'
			 AND pm.meta_value BETWEEN %s AND %s",
			$target_start, $target_end
		) );

		$today = current_time( 'Y-m-d' );

		foreach ( $events as $event ) {
			// Track emails notified so we can avoid duplicates across event + group reminders
			$notified_emails = [];

			// 1. Send reminders to direct event registrants
			$regs = CEM_Registration::get_for_event( $event->ID, [
				'status'   => [ 'confirmed', 'pending' ],
				'per_page' => 0,
			] );

			foreach ( $regs as $reg ) {
				$already_sent = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cem_email_log
					 WHERE registration_id = %d AND type = 'reminder' AND DATE(sent_at) = %s",
					$reg->id, $today
				) );
				if ( $already_sent ) continue;

				CEM_Email::send_reminder( $reg->id );
				$notified_emails[ strtolower( $reg->email ) ] = true;
			}

			// 2. Also notify group members if this event is linked to a group
			$group_id = (int) get_post_meta( $event->ID, '_cem_event_group_id', true );
			if ( ! $group_id ) continue;

			$group_regs = CEM_Registration::get_for_event( $group_id, [
				'status'   => [ 'confirmed', 'pending' ],
				'per_page' => 0,
			] );

			foreach ( $group_regs as $greg ) {
				// Skip if this email already got a direct event reminder above
				if ( isset( $notified_emails[ strtolower( $greg->email ) ] ) ) continue;

				// Skip if a group event reminder was already sent today for this reg + event
				$already_sent = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$wpdb->prefix}cem_email_log
					 WHERE registration_id = %d AND event_id = %d AND type = 'group_event_reminder' AND DATE(sent_at) = %s",
					$greg->id, $event->ID, $today
				) );
				if ( $already_sent ) continue;

				CEM_Email::send_group_event_reminder( $greg->id, $event->ID );
				$notified_emails[ strtolower( $greg->email ) ] = true;
			}
		}
	}

	// ── Capacity Alert ────────────────────────────────────────────────────────

	public static function maybe_send_capacity_alert( $event_id ) {
		$capacity  = (int) CEM_Helpers::get_event_meta( $event_id, 'capacity', 0 );
		if ( $capacity <= 0 ) return;

		$taken = CEM_Helpers::get_registration_count( $event_id );
		$pct   = ( $taken / $capacity ) * 100;

		// Alert at 80% and 100%
		$threshold = get_option( 'cem_capacity_alert_pct', 80 );
		if ( $pct < $threshold ) return;

		$event = get_post( $event_id );
		$label = $pct >= 100 ? __( 'FULL', 'church-event-manager' ) : round( $pct ) . '%';

		CEM_Email::send( [
			'to_email' => get_option( 'cem_admin_notify_email', get_option( 'admin_email' ) ),
			'subject'  => sprintf(
				__( 'Capacity Alert: %s is now %s full', 'church-event-manager' ),
				$event ? $event->post_title : "Event #$event_id",
				$label
			),
			'message'  => sprintf(
				'<p>%s</p><p><a href="%s">View Registrations</a></p>',
				sprintf( __( '%s is %s full (%d/%d spots taken).', 'church-event-manager' ),
					esc_html( $event ? $event->post_title : '' ),
					$label, $taken, $capacity
				),
				admin_url( 'admin.php?page=cem-registrations&event_id=' . $event_id )
			),
			'event_id' => $event_id,
			'type'     => 'capacity_alert',
		] );
	}
}
