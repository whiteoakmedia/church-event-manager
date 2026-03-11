<?php
/**
 * All wp_ajax_ handlers.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Ajax {

	public function init() {
		// Public (logged-in + logged-out)
		add_action( 'wp_ajax_cem_register',                     [ $this, 'handle_registration' ] );
		add_action( 'wp_ajax_nopriv_cem_register',              [ $this, 'handle_registration' ] );
		add_action( 'wp_ajax_cem_cancel_registration',          [ $this, 'cancel_registration' ] );
		add_action( 'wp_ajax_nopriv_cem_cancel_registration',   [ $this, 'cancel_registration' ] );
		add_action( 'wp_ajax_cem_create_payment_intent',        [ $this, 'create_payment_intent' ] );
		add_action( 'wp_ajax_nopriv_cem_create_payment_intent', [ $this, 'create_payment_intent' ] );

		// Admin only
		add_action( 'wp_ajax_cem_check_in',               [ $this, 'check_in' ] );
		add_action( 'wp_ajax_cem_bulk_email',             [ $this, 'bulk_email' ] );
		add_action( 'wp_ajax_cem_export_registrations',   [ $this, 'export_registrations' ] );
		add_action( 'wp_ajax_cem_update_reg_status',      [ $this, 'update_reg_status' ] );
		add_action( 'wp_ajax_cem_send_reminder',          [ $this, 'send_reminder' ] );
		add_action( 'wp_ajax_cem_waitlist_promote',       [ $this, 'waitlist_promote' ] );
		add_action( 'wp_ajax_cem_delete_registration',    [ $this, 'delete_registration' ] );
		add_action( 'wp_ajax_cem_get_reg_details',        [ $this, 'get_reg_details' ] );
		add_action( 'wp_ajax_cem_save_settings',          [ $this, 'save_settings' ] );
		add_action( 'wp_ajax_cem_dashboard_stats',        [ $this, 'dashboard_stats' ] );
		add_action( 'wp_ajax_cem_get_recipients_preview', [ $this, 'get_recipients_preview' ] );
		add_action( 'wp_ajax_cem_submit_ticket',          [ $this, 'submit_ticket' ] );
	}

	// ── Public handlers ───────────────────────────────────────────────────────

	public function handle_registration() {
		// Verify nonce
		if ( ! isset( $_POST['cem_nonce'] ) || ! wp_verify_nonce( $_POST['cem_nonce'], 'cem_register_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'church-event-manager' ) ] );
		}

		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! $event_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid event.', 'church-event-manager' ) ] );
		}

		// Collect custom fields
		$custom_fields = [];
		$field_defs    = CEM_Custom_Fields::get_fields( $event_id );
		foreach ( $field_defs as $field ) {
			$key = 'cem_custom_' . $field->field_name;
			if ( isset( $_POST[ $key ] ) ) {
				$custom_fields[ $field->field_name ] = is_array( $_POST[ $key ] )
					? array_map( 'sanitize_text_field', $_POST[ $key ] )
					: sanitize_text_field( $_POST[ $key ] );
			}
		}

		// Validate required custom fields
		$field_errors = CEM_Custom_Fields::validate_posted_fields( $event_id, $_POST );
		if ( ! empty( $field_errors ) ) {
			wp_send_json_error( [ 'message' => implode( '<br>', $field_errors ) ] );
		}

		$data = [
			'event_id'      => $event_id,
			'first_name'    => sanitize_text_field( $_POST['first_name'] ?? '' ),
			'last_name'     => sanitize_text_field( $_POST['last_name'] ?? '' ),
			'email'         => sanitize_email( $_POST['email'] ?? '' ),
			'phone'         => sanitize_text_field( $_POST['phone'] ?? '' ),
			'num_attendees' => (int) ( $_POST['num_attendees'] ?? 1 ),
			'notes'         => sanitize_textarea_field( $_POST['notes'] ?? '' ),
			'custom_fields' => $custom_fields,
			'user_id'       => is_user_logged_in() ? get_current_user_id() : null,
		];

		if ( empty( $data['first_name'] ) || empty( $data['last_name'] ) ) {
			wp_send_json_error( [ 'message' => __( 'First and last name are required.', 'church-event-manager' ) ] );
		}
		if ( ! is_email( $data['email'] ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'church-event-manager' ) ] );
		}

		// ── Payment verification ───────────────────────────────────────────────
		$event_price    = get_post_meta( $event_id, '_cem_price', true );
		$price_num      = ( $event_price !== '' ) ? (float) $event_price : 0.0;
		$allow_inperson = get_post_meta( $event_id, '_cem_allow_inperson', true ) === '1';

		if ( $price_num > 0 && ! $allow_inperson && get_option( 'cem_stripe_enabled' ) === '1' ) {
			// ── Online payment required — verify with Stripe ──────────────────
			$pi_id = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );

			if ( empty( $pi_id ) ) {
				wp_send_json_error( [ 'message' => __( 'Payment is required for this event.', 'church-event-manager' ) ] );
			}

			// Verify the PaymentIntent server-side — prevents amount tampering.
			$verification = $this->verify_stripe_payment( $pi_id, $event_id, $price_num );
			if ( is_wp_error( $verification ) ) {
				wp_send_json_error( [ 'message' => $verification->get_error_message() ] );
			}

			$data['payment_intent_id'] = $pi_id;
			$data['payment_status']    = 'paid';

		} elseif ( $price_num > 0 && $allow_inperson ) {
			// ── In-person payment — no Stripe required ────────────────────────
			// Attendee will pay at the door; flag so admin can track it.
			$data['payment_status'] = 'in_person';

		} else {
			// ── Free event ────────────────────────────────────────────────────
			$data['payment_status'] = 'free';
		}

		$result = CEM_Registration::create( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		$reg = CEM_Registration::get( $result );
		$message = $reg->status === 'waitlisted'
			? __( 'You have been added to the waitlist! We will contact you if a spot becomes available.', 'church-event-manager' )
			: __( 'You\'re registered! Check your email for a confirmation.', 'church-event-manager' );

		wp_send_json_success( [
			'message' => $message,
			'code'    => $reg->registration_code,
			'status'  => $reg->status,
		] );
	}

	public function cancel_registration() {
		$code  = sanitize_text_field( $_POST['code'] ?? '' );
		$nonce = sanitize_text_field( $_POST['nonce'] ?? '' );

		if ( ! $code ) {
			wp_send_json_error( [ 'message' => __( 'No registration code provided.', 'church-event-manager' ) ] );
		}

		$result = CEM_Registration::cancel_by_code( $code, $nonce );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Registration cancelled.', 'church-event-manager' ) ] );
	}

	// ── Admin handlers ────────────────────────────────────────────────────────

	public function check_in() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_id = (int) ( $_POST['registration_id'] ?? 0 );
		$result = CEM_Registration::update_status( $reg_id, 'checked_in' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}
		wp_send_json_success( [ 'message' => __( 'Checked in!', 'church-event-manager' ) ] );
	}

	public function bulk_email() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_ids = array_map( 'intval', (array) ( $_POST['registration_ids'] ?? [] ) );
		$subject = sanitize_text_field( $_POST['subject'] ?? '' );
		$message = wp_kses_post( $_POST['message'] ?? '' );
		$event_id= (int) ( $_POST['event_id'] ?? 0 );

		if ( empty( $reg_ids ) || ! $subject || ! $message ) {
			wp_send_json_error( [ 'message' => __( 'Missing required fields.', 'church-event-manager' ) ] );
		}

		$result = CEM_Email::send_bulk( $reg_ids, $subject, $message, $event_id );
		wp_send_json_success( [
			'message' => sprintf(
				__( 'Sent %d emails. %d failed.', 'church-event-manager' ),
				$result['sent'], $result['failed']
			),
			'result' => $result,
		] );
	}

	public function export_registrations() {
		$this->require_admin();

		if ( ! check_admin_referer( 'cem_export_nonce', 'nonce' ) ) {
			wp_die( 'Security check failed.' );
		}

		$event_id = (int) ( $_GET['event_id'] ?? 0 );
		$status   = array_map( 'sanitize_key', (array) ( $_GET['status'] ?? [] ) );

		$data = CEM_Registration::get_export_data( $event_id, $status );
		$csv  = CEM_Helpers::array_to_csv( $data );

		$filename = 'registrations-' . date( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( "Content-Disposition: attachment; filename=\"$filename\"" );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		// phpcs:ignore WordPress.Security.EscapeOutput
		echo $csv;
		exit;
	}

	public function update_reg_status() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$ids    = array_map( 'intval', (array) ( $_POST['ids'] ?? [] ) );
		$status = sanitize_key( $_POST['status'] ?? '' );

		if ( empty( $ids ) || ! $status ) {
			wp_send_json_error( [ 'message' => __( 'Invalid request.', 'church-event-manager' ) ] );
		}

		$results = CEM_Registration::bulk_update_status( $ids, $status );
		$success = count( array_filter( $results, fn( $r ) => ! is_wp_error( $r ) && $r !== false ) );

		wp_send_json_success( [
			'message' => sprintf( __( '%d registration(s) updated.', 'church-event-manager' ), $success ),
		] );
	}

	public function send_reminder() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_ids = array_map( 'intval', (array) ( $_POST['registration_ids'] ?? [] ) );
		$sent = 0;
		foreach ( $reg_ids as $id ) {
			if ( CEM_Email::send_reminder( $id ) ) $sent++;
		}
		wp_send_json_success( [
			'message' => sprintf( __( 'Sent %d reminder(s).', 'church-event-manager' ), $sent ),
		] );
	}

	public function waitlist_promote() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_id = (int) ( $_POST['registration_id'] ?? 0 );
		$result = CEM_Registration::update_status( $reg_id, 'confirmed' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Remove from waitlist table
		global $wpdb;
		$reg = CEM_Registration::get( $reg_id );
		if ( $reg ) {
			$wpdb->delete( "{$wpdb->prefix}cem_waitlist",
				[ 'event_id' => $reg->event_id, 'email' => $reg->email ],
				[ '%d', '%s' ]
			);
			CEM_Email::send_waitlist_promotion( $reg_id );
		}

		wp_send_json_success( [ 'message' => __( 'Registration promoted from waitlist.', 'church-event-manager' ) ] );
	}

	public function delete_registration() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_id = (int) ( $_POST['registration_id'] ?? 0 );
		$result = CEM_Registration::delete( $reg_id );
		if ( $result ) {
			wp_send_json_success( [ 'message' => __( 'Registration deleted.', 'church-event-manager' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not delete registration.', 'church-event-manager' ) ] );
		}
	}

	public function get_reg_details() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_id = (int) ( $_GET['registration_id'] ?? 0 );
		$reg    = CEM_Registration::get( $reg_id );
		if ( ! $reg ) {
			wp_send_json_error( [ 'message' => __( 'Not found.', 'church-event-manager' ) ] );
		}
		$meta  = CEM_Registration::get_meta( $reg_id );
		$event = get_post( $reg->event_id );

		wp_send_json_success( [
			'registration' => (array) $reg,
			'meta'         => $meta,
			'event_title'  => $event ? $event->post_title : '',
		] );
	}

	public function save_settings() {
		$this->require_admin();
		check_ajax_referer( 'cem_settings_nonce', 'nonce' );

		// Plain text / number / email / color settings.
		$text_settings = [
			'cem_from_name', 'cem_from_email', 'cem_reply_to_email',
			'cem_admin_notify_email',
			'cem_cancellation_days_before', 'cem_reminder_days_before',
			'cem_confirmation_subject', 'cem_reminder_subject', 'cem_cancellation_subject',
			'cem_events_per_page', 'cem_date_format', 'cem_time_format',
			'cem_currency_symbol', 'cem_accent_color',
			'cem_church_phone', 'cem_capacity_alert_pct',
			// Stripe payment settings
			'cem_stripe_publishable_key', 'cem_stripe_secret_key', 'cem_stripe_currency',
		];

		foreach ( $text_settings as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		// Checkbox settings: always save '1' when checked, '0' when unchecked.
		// Standard HTML checkboxes are absent from POST when unchecked, so we
		// must explicitly write '0' to avoid stale truthy values in the database.
		$checkbox_settings = [
			'cem_admin_notify_on_register',
			'cem_registration_auto_confirm',
			'cem_waitlist_enabled',
			'cem_allow_cancellations',
			'cem_send_reminders',
			// Stripe payment toggles
			'cem_stripe_enabled',
			'cem_stripe_test_mode',
		];

		foreach ( $checkbox_settings as $key ) {
			update_option( $key, ( isset( $_POST[ $key ] ) && $_POST[ $key ] === '1' ) ? '1' : '0' );
		}

		wp_send_json_success( [ 'message' => __( 'Settings saved.', 'church-event-manager' ) ] );
	}

	public function dashboard_stats() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );
		wp_send_json_success( CEM_Registration::get_dashboard_stats() );
	}

	/**
	 * Return a list of registration recipients for the Email Center preview.
	 *
	 * GET params: event_id (int, 0 = all events), status (string, '' = all statuses)
	 *
	 * Returns: { recipients: [{name, email}, …], ids: [int, …] }
	 */
	public function get_recipients_preview() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$event_id = (int) ( $_GET['event_id'] ?? 0 );
		$status   = sanitize_key( $_GET['status'] ?? '' );

		// Build status filter array (empty = all statuses)
		$status_filter = $status ? [ $status ] : [];

		if ( $event_id ) {
			$regs = CEM_Registration::get_for_event( $event_id, [
				'status'   => $status_filter,
				'per_page' => 0,
			] );
		} else {
			$result = CEM_Registration::get_all( [
				'status'   => $status_filter,
				'per_page' => 0,
			] );
			$regs = $result['registrations'];
		}

		$recipients = [];
		$ids        = [];

		foreach ( $regs as $reg ) {
			$recipients[] = [
				'name'  => trim( $reg->first_name . ' ' . $reg->last_name ),
				'email' => $reg->email,
			];
			$ids[] = (int) $reg->id;
		}

		wp_send_json_success( [
			'recipients' => $recipients,
			'ids'        => $ids,
			'count'      => count( $ids ),
		] );
	}

	// ── Stripe ────────────────────────────────────────────────────────────────

	/**
	 * Create a Stripe PaymentIntent for the given event and return the client_secret.
	 * Called by cem-stripe.js on page load so the Payment Element can initialize.
	 */
	public function create_payment_intent() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'cem_payment_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'church-event-manager' ) ] );
		}

		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! $event_id || get_post_type( $event_id ) !== 'cem_event' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid event.', 'church-event-manager' ) ] );
		}

		$price     = get_post_meta( $event_id, '_cem_price', true );
		$price_num = ( $price !== '' ) ? (float) $price : 0.0;

		if ( $price_num <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'This event is free — no payment required.', 'church-event-manager' ) ] );
		}

		$secret_key = get_option( 'cem_stripe_secret_key', '' );
		if ( empty( $secret_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Payment processing is not configured.', 'church-event-manager' ) ] );
		}

		$amount_cents = (int) round( $price_num * 100 );
		$currency     = strtolower( get_option( 'cem_stripe_currency', 'usd' ) );
		$event_title  = get_the_title( $event_id );

		$response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
			'headers' => [
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body' => [
				'amount'   => $amount_cents,
				'currency' => $currency,
				// Use a nested PHP array so http_build_query encodes this as
				// payment_method_types[0]=card — the format Stripe's own PHP SDK
				// uses.  This is more reliable than the string-key 'payment_method_types[]'
				// trick, which encodes brackets as %5B%5D and can confuse some
				// intermediary layers.
				'payment_method_types' => [ 'card' ],
				// Automatic capture ensures the PI reaches 'succeeded' status
				// immediately after the customer confirms — no separate capture step.
				'capture_method'       => 'automatic',
				'description'          => sprintf( __( 'Event registration: %s', 'church-event-manager' ), $event_title ),
				'metadata[event_id]'   => $event_id,
				'metadata[event_name]' => $event_title,
				'metadata[plugin]'     => 'church-event-manager',
			],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not connect to payment processor. Please try again.', 'church-event-manager' ) ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			wp_send_json_error( [ 'message' => esc_html( $body['error']['message'] ?? __( 'Payment error.', 'church-event-manager' ) ) ] );
		}

		wp_send_json_success( [
			'client_secret'     => $body['client_secret'],
			'payment_intent_id' => $body['id'],
		] );
	}

	/**
	 * Verify a Stripe PaymentIntent server-side before creating the registration.
	 * Checks that the intent exists, has succeeded, and the amount matches.
	 *
	 * @return true|WP_Error
	 */
	private function verify_stripe_payment( $payment_intent_id, $event_id, $price ) {
		$secret_key = get_option( 'cem_stripe_secret_key', '' );
		if ( empty( $secret_key ) ) {
			return new WP_Error( 'stripe_not_configured', __( 'Payment processing is not configured.', 'church-event-manager' ) );
		}

		// Sanitize: PaymentIntent IDs start with pi_
		if ( ! preg_match( '/^pi_[a-zA-Z0-9_]+$/', $payment_intent_id ) ) {
			return new WP_Error( 'invalid_pi', __( 'Invalid payment reference.', 'church-event-manager' ) );
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $payment_intent_id ),
			[
				'headers' => [ 'Authorization' => 'Bearer ' . $secret_key ],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'stripe_unreachable', __( 'Could not verify payment. Please try again.', 'church-event-manager' ) );
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			// Stripe returned an API error (e.g. wrong key, invalid PI ID).
			$stripe_msg = esc_html( $body['error']['message'] ?? __( 'Stripe API error.', 'church-event-manager' ) );
			return new WP_Error( 'stripe_error', sprintf(
				/* translators: %s: Stripe error message */
				__( 'Payment verification failed: %s', 'church-event-manager' ),
				$stripe_msg
			) );
		}

		$pi_status = $body['status'] ?? '';

		if ( $pi_status !== 'succeeded' ) {
			// Include the actual status in the error message so it is visible
			// in the browser during development/testing.
			return new WP_Error( 'payment_not_complete', sprintf(
				/* translators: %s: Stripe PaymentIntent status string e.g. "requires_action" */
				__( 'Payment has not been completed (status: %s). Please try again.', 'church-event-manager' ),
				esc_html( $pi_status ?: "unknown (HTTP $http_code)" )
			) );
		}

		// Guard: ensure the amount matches (prevents a registrant from reusing
		// a small payment intent on a higher-priced event).
		$expected_cents = (int) round( $price * 100 );
		$actual_cents   = (int) ( $body['amount'] ?? 0 );
		if ( $actual_cents !== $expected_cents ) {
			return new WP_Error( 'amount_mismatch', __( 'Payment amount does not match the event price.', 'church-event-manager' ) );
		}

		return true;
	}

	// ── Support Ticket ────────────────────────────────────────────────────────

	/**
	 * Send a support ticket email to White Oak Media LLC.
	 * Admin-only; uses wp_mail() so it respects the site's SMTP config.
	 */
	public function submit_ticket() {
		$this->require_admin();
		check_ajax_referer( 'cem_ticket_nonce', 'nonce' );

		$name        = sanitize_text_field( wp_unslash( $_POST['ticket_name']        ?? '' ) );
		$email       = sanitize_email(      wp_unslash( $_POST['ticket_email']       ?? '' ) );
		$type        = sanitize_text_field( wp_unslash( $_POST['ticket_type']        ?? '' ) );
		$subject     = sanitize_text_field( wp_unslash( $_POST['ticket_subject']     ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['ticket_description'] ?? '' ) );
		$include_sys = ( $_POST['ticket_include_sysinfo'] ?? '' ) === '1';

		if ( ! $name || ! is_email( $email ) || ! $type || ! $subject || ! $description ) {
			wp_send_json_error( [ 'message' => __( 'Please fill in all required fields.', 'church-event-manager' ) ] );
		}

		$to      = 'zach@whiteoakmedia.io';
		$subject_line = sprintf( '[CEM Support] %s: %s', $type, $subject );

		$body  = "A support ticket was submitted from the Church Event Manager plugin.\n\n";
		$body .= "From:  {$name} <{$email}>\n";
		$body .= "Type:  {$type}\n";
		$body .= "Subject: {$subject}\n";
		$body .= str_repeat( '-', 60 ) . "\n\n";
		$body .= $description . "\n\n";

		if ( $include_sys ) {
			$theme = wp_get_theme();
			$body .= str_repeat( '-', 60 ) . "\n";
			$body .= "SYSTEM INFORMATION\n";
			$body .= str_repeat( '-', 60 ) . "\n";
			$body .= "Plugin Version : " . CEM_VERSION . "\n";
			$body .= "WordPress      : " . get_bloginfo( 'wpversion' ) . "\n";
			$body .= "PHP            : " . phpversion() . "\n";
			$body .= "Active Theme   : " . $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . "\n";
			$body .= "Site URL       : " . get_site_url() . "\n";
			$body .= "Locale         : " . get_locale() . "\n";
		}

		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			"Reply-To: {$name} <{$email}>",
		];

		$sent = wp_mail( $to, $subject_line, $body, $headers );

		if ( $sent ) {
			wp_send_json_success( [
				'message' => __( '✅ Your ticket has been sent! We\'ll be in touch at the email address you provided.', 'church-event-manager' ),
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'The email could not be sent. Please email us directly at zach@whiteoakmedia.io.', 'church-event-manager' ),
			] );
		}
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'cem_manage_events' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'church-event-manager' ) ], 403 );
		}
	}
}
