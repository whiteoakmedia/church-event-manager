<?php
/**
 * Email composition, sending, bulk mailer, and log.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Email {

	// ── Single Send ───────────────────────────────────────────────────────────

	/**
	 * Send an email and log it.
	 *
	 * @param array $args {
	 *   to_email, to_name, subject, message (HTML),
	 *   event_id (optional), registration_id (optional), type (optional)
	 * }
	 * @return bool
	 */
	public static function send( array $args ) {
		$defaults = [
			'to_email'        => '',
			'to_name'         => '',
			'subject'         => '',
			'message'         => '',
			'event_id'        => null,
			'registration_id' => null,
			'type'            => 'general',
		];
		$args = wp_parse_args( $args, $defaults );

		if ( empty( $args['to_email'] ) || empty( $args['subject'] ) ) {
			return false;
		}

		$from_name  = get_option( 'cem_from_name', get_bloginfo( 'name' ) );
		$from_email = get_option( 'cem_from_email', get_option( 'admin_email' ) );
		$reply_to   = get_option( 'cem_reply_to_email', $from_email );

		$headers = [
			"From: $from_name <$from_email>",
			"Reply-To: $reply_to",
			'Content-Type: text/html; charset=UTF-8',
		];

		$to      = $args['to_name'] ? "{$args['to_name']} <{$args['to_email']}>" : $args['to_email'];
		$message = self::wrap_in_layout( $args['message'], $args['subject'] );

		$sent = wp_mail( $to, $args['subject'], $message, $headers );

		// Log
		self::log( [
			'event_id'        => $args['event_id'],
			'registration_id' => $args['registration_id'],
			'to_email'        => $args['to_email'],
			'to_name'         => $args['to_name'],
			'subject'         => $args['subject'],
			'message'         => $message,
			'type'            => $args['type'],
			'status'          => $sent ? 'sent' : 'failed',
		] );

		return $sent;
	}

	// ── Bulk Send ─────────────────────────────────────────────────────────────

	/**
	 * Send bulk email to a list of registrations.
	 *
	 * @param array  $registration_ids  Array of registration IDs.
	 * @param string $subject
	 * @param string $message  HTML (may contain {placeholders})
	 * @param int    $event_id
	 * @return array [ 'sent' => int, 'failed' => int ]
	 */
	public static function send_bulk( array $registration_ids, $subject, $message, $event_id = 0 ) {
		$sent   = 0;
		$failed = 0;

		foreach ( $registration_ids as $reg_id ) {
			$reg = CEM_Registration::get( (int) $reg_id );
			if ( ! $reg ) { $failed++; continue; }

			$event = get_post( $reg->event_id );
			$vars  = self::get_template_vars( $reg, $event );

			$result = self::send( [
				'to_email'        => $reg->email,
				'to_name'         => trim( $reg->first_name . ' ' . $reg->last_name ),
				'subject'         => CEM_Helpers::parse_template( $subject, $vars ),
				'message'         => CEM_Helpers::parse_template( $message, $vars ),
				'event_id'        => $reg->event_id,
				'registration_id' => $reg->id,
				'type'            => 'bulk',
			] );

			$result ? $sent++ : $failed++;

			// Throttle: avoid hitting email rate limits
			if ( $sent % 10 === 0 ) {
				usleep( 100000 ); // 0.1 second pause
			}
		}

		return compact( 'sent', 'failed' );
	}

	// ── Confirmation Email ────────────────────────────────────────────────────

	public static function send_confirmation( $registration_id ) {
		$reg   = CEM_Registration::get( $registration_id );
		if ( ! $reg ) return false;

		$event = get_post( $reg->event_id );
		$vars  = self::get_template_vars( $reg, $event );

		$subject = CEM_Helpers::parse_template(
			get_option( 'cem_confirmation_subject', 'Registration Confirmed – {event_title}' ),
			$vars
		);
		$message = self::load_template( 'confirmation', $vars );

		return self::send( [
			'to_email'        => $reg->email,
			'to_name'         => trim( $reg->first_name . ' ' . $reg->last_name ),
			'subject'         => $subject,
			'message'         => $message,
			'event_id'        => $reg->event_id,
			'registration_id' => $registration_id,
			'type'            => 'confirmation',
		] );
	}

	// ── Reminder Email ────────────────────────────────────────────────────────

	public static function send_reminder( $registration_id ) {
		$reg   = CEM_Registration::get( $registration_id );
		if ( ! $reg ) return false;

		$event = get_post( $reg->event_id );
		$vars  = self::get_template_vars( $reg, $event );

		$subject = CEM_Helpers::parse_template(
			get_option( 'cem_reminder_subject', 'Reminder: {event_title} is Tomorrow!' ),
			$vars
		);
		$message = self::load_template( 'reminder', $vars );

		return self::send( [
			'to_email'        => $reg->email,
			'to_name'         => trim( $reg->first_name . ' ' . $reg->last_name ),
			'subject'         => $subject,
			'message'         => $message,
			'event_id'        => $reg->event_id,
			'registration_id' => $registration_id,
			'type'            => 'reminder',
		] );
	}

	// ── Cancellation Email ────────────────────────────────────────────────────

	public static function send_cancellation( $registration_id ) {
		$reg   = CEM_Registration::get( $registration_id );
		if ( ! $reg ) return false;

		$event = get_post( $reg->event_id );
		$vars  = self::get_template_vars( $reg, $event );

		$subject = CEM_Helpers::parse_template(
			get_option( 'cem_cancellation_subject', 'Your Registration Has Been Cancelled – {event_title}' ),
			$vars
		);
		$message = self::load_template( 'cancellation', $vars );

		return self::send( [
			'to_email'        => $reg->email,
			'to_name'         => trim( $reg->first_name . ' ' . $reg->last_name ),
			'subject'         => $subject,
			'message'         => $message,
			'event_id'        => $reg->event_id,
			'registration_id' => $registration_id,
			'type'            => 'cancellation',
		] );
	}

	// ── Admin Notification ────────────────────────────────────────────────────

	public static function send_admin_notification( $registration_id ) {
		$reg   = CEM_Registration::get( $registration_id );
		if ( ! $reg ) return false;

		$event     = get_post( $reg->event_id );
		$admin_url = admin_url( 'admin.php?page=cem-registrations&event_id=' . $reg->event_id );
		$vars      = array_merge( self::get_template_vars( $reg, $event ), [
			'admin_url' => $admin_url,
		] );

		$message = self::load_template( 'admin-notification', $vars );
		$subject = sprintf(
			__( 'New Registration: %s – %s %s', 'church-event-manager' ),
			$event ? $event->post_title : 'Event #' . $reg->event_id,
			$reg->first_name, $reg->last_name
		);

		return self::send( [
			'to_email'        => get_option( 'cem_admin_notify_email', get_option( 'admin_email' ) ),
			'subject'         => $subject,
			'message'         => $message,
			'event_id'        => $reg->event_id,
			'registration_id' => $registration_id,
			'type'            => 'admin_notification',
		] );
	}

	// ── Waitlist Promotion Email ──────────────────────────────────────────────

	public static function send_waitlist_promotion( $registration_id ) {
		$reg   = CEM_Registration::get( $registration_id );
		if ( ! $reg ) return false;

		$event = get_post( $reg->event_id );
		$vars  = self::get_template_vars( $reg, $event );

		$message = self::load_template( 'waitlist-promotion', $vars );
		$subject = sprintf( __( 'Great news! A spot opened up – %s', 'church-event-manager' ), $event ? $event->post_title : '' );

		return self::send( [
			'to_email'        => $reg->email,
			'to_name'         => trim( $reg->first_name . ' ' . $reg->last_name ),
			'subject'         => $subject,
			'message'         => $message,
			'event_id'        => $reg->event_id,
			'registration_id' => $registration_id,
			'type'            => 'waitlist_promotion',
		] );
	}

	// ── Template Loader ───────────────────────────────────────────────────────

	/**
	 * Load an HTML email template and replace {vars}.
	 * Looks first in theme/church-event-manager/emails/, then plugin templates/emails/.
	 */
	public static function load_template( $template_name, array $vars = [] ) {
		$theme_path  = get_stylesheet_directory() . "/church-event-manager/emails/{$template_name}.php";
		$plugin_path = CEM_PLUGIN_DIR . "templates/emails/{$template_name}.php";

		$file = file_exists( $theme_path ) ? $theme_path : ( file_exists( $plugin_path ) ? $plugin_path : null );

		if ( ! $file ) {
			// Fallback: plain text
			return wpautop( implode( "\n", array_map(
				fn( $k, $v ) => "$k: $v",
				array_keys( $vars ), $vars
			) ) );
		}

		ob_start();
		extract( $vars ); // phpcs:ignore WordPress.PHP.DontExtract
		include $file;
		$html = ob_get_clean();

		return CEM_Helpers::parse_template( $html, $vars );
	}

	// ── HTML Layout Wrapper ───────────────────────────────────────────────────

	public static function wrap_in_layout( $content, $subject = '' ) {
		$accent      = get_option( 'cem_accent_color', '#3b5998' );
		$church_name = get_bloginfo( 'name' );
		$church_url  = home_url();

		return "<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width, initial-scale=1.0'>
<title>" . esc_html( $subject ) . "</title>
<style>
  body { margin:0; padding:0; background:#f4f4f4; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; color:#333; }
  .wrapper { max-width:600px; margin:0 auto; padding:20px; }
  .header { background:{$accent}; padding:24px 32px; border-radius:6px 6px 0 0; text-align:center; }
  .header h1 { margin:0; color:#fff; font-size:22px; font-weight:600; }
  .header p  { margin:4px 0 0; color:rgba(255,255,255,.8); font-size:13px; }
  .body  { background:#fff; padding:32px; border:1px solid #e8e8e8; }
  .footer { background:#f9f9f9; padding:16px 32px; border:1px solid #e8e8e8; border-top:none; border-radius:0 0 6px 6px; text-align:center; font-size:12px; color:#888; }
  .footer a { color:{$accent}; text-decoration:none; }
  .btn { display:inline-block; background:{$accent}; color:#fff; text-decoration:none; padding:12px 28px; border-radius:5px; font-weight:600; font-size:14px; margin:8px 0; }
  .info-table { width:100%; border-collapse:collapse; margin:16px 0; }
  .info-table th { background:#f7f7f7; padding:8px 12px; text-align:left; font-size:12px; text-transform:uppercase; color:#666; letter-spacing:.5px; border-bottom:1px solid #e8e8e8; }
  .info-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; font-size:14px; }
  .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:12px; font-weight:600; background:#e8f5e9; color:#2e7d32; }
</style>
</head>
<body>
<div class='wrapper'>
  <div class='header'>
    <h1>" . esc_html( $church_name ) . "</h1>
    <p>" . esc_html( $subject ) . "</p>
  </div>
  <div class='body'>$content</div>
  <div class='footer'>
    <p>&copy; " . date( 'Y' ) . " <a href='" . esc_url( $church_url ) . "'>" . esc_html( $church_name ) . "</a>. All rights reserved.</p>
    <p>You received this because you registered for a church event.</p>
  </div>
</div>
</body>
</html>";
	}

	// ── Template Variables ────────────────────────────────────────────────────

	public static function get_template_vars( $reg, $event ) {
		$start     = get_post_meta( $reg->event_id, '_cem_start_datetime', true );
		$end       = get_post_meta( $reg->event_id, '_cem_end_datetime', true );
		$location  = get_post_meta( $reg->event_id, '_cem_location', true );
		$manage_url= CEM_Helpers::get_manage_url( $reg->registration_code );

		return [
			'first_name'        => $reg->first_name,
			'last_name'         => $reg->last_name,
			'full_name'         => trim( $reg->first_name . ' ' . $reg->last_name ),
			'email'             => $reg->email,
			'phone'             => $reg->phone,
			'num_attendees'     => $reg->num_attendees,
			'registration_code' => $reg->registration_code,
			'registration_status' => $reg->status,
			'event_title'       => $event ? $event->post_title : '',
			'event_description' => $event ? wp_trim_words( $event->post_content, 30 ) : '',
			'event_url'         => $event ? get_permalink( $event->ID ) : '',
			'event_date'        => $start ? CEM_Helpers::format_date( $start ) : '',
			'event_time'        => $start ? CEM_Helpers::format_time( $start ) : '',
			'event_end_time'    => $end   ? CEM_Helpers::format_time( $end )   : '',
			'event_location'    => $location ?: '',
			'manage_url'        => $manage_url,
			'church_name'       => get_bloginfo( 'name' ),
			'church_url'        => home_url(),
			'church_phone'      => get_option( 'cem_church_phone', '' ),
		];
	}

	// ── Log ───────────────────────────────────────────────────────────────────

	private static function log( array $data ) {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}cem_email_log",
			array_merge( $data, [ 'created_at' => current_time( 'mysql' ), 'sent_at' => current_time( 'mysql' ) ] ),
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	/** Get email log for admin display. */
	public static function get_log( $args = [] ) {
		global $wpdb;
		$defaults = [ 'per_page' => 25, 'page' => 1, 'event_id' => 0, 'type' => '' ];
		$args     = wp_parse_args( $args, $defaults );

		$where  = [ '1=1' ];
		$values = [];
		if ( $args['event_id'] ) {
			$where[] = $wpdb->prepare( 'event_id = %d', $args['event_id'] );
		}
		if ( $args['type'] ) {
			$where[] = $wpdb->prepare( 'type = %s', $args['type'] );
		}

		$where_sql = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cem_email_log WHERE $where_sql" );
		$offset = ( $args['page'] - 1 ) * $args['per_page'];
		$rows   = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_email_log WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$args['per_page'], $offset
		) );

		return [ 'emails' => $rows, 'total' => $total ];
	}
}
