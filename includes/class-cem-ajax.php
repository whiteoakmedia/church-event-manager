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
		add_action( 'wp_ajax_cem_leave_group',                  [ $this, 'leave_group' ] );
		add_action( 'wp_ajax_nopriv_cem_leave_group',           [ $this, 'leave_group' ] );
		add_action( 'wp_ajax_cem_create_payment_intent',        [ $this, 'create_payment_intent' ] );
		add_action( 'wp_ajax_nopriv_cem_create_payment_intent', [ $this, 'create_payment_intent' ] );
		add_action( 'wp_ajax_cem_update_payment_intent',        [ $this, 'update_payment_intent' ] );
		add_action( 'wp_ajax_nopriv_cem_update_payment_intent', [ $this, 'update_payment_intent' ] );
		add_action( 'wp_ajax_cem_email_my_registrations',        [ $this, 'email_my_registrations' ] );
		add_action( 'wp_ajax_nopriv_cem_email_my_registrations', [ $this, 'email_my_registrations' ] );

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
		add_action( 'wp_ajax_cem_test_stripe',            [ $this, 'test_stripe_connection' ] );
		add_action( 'wp_ajax_cem_preview_bulk_email',     [ $this, 'preview_bulk_email' ] );
		add_action( 'wp_ajax_cem_preview_email_log',      [ $this, 'preview_email_log' ] );

		// Check-in page
		add_action( 'wp_ajax_cem_checkin_load',            [ $this, 'checkin_load' ] );
		add_action( 'wp_ajax_cem_walkin_register',         [ $this, 'walkin_register' ] );
	}

	/**
	 * Create a walk-in registration that's already confirmed and checked in.
	 *
	 * Used by the "+ Add Walk-in" button on the Check-In screen so volunteers
	 * can add someone who shows up without an existing registration in a
	 * single tap rather than opening a separate registration form.
	 */
	public function walkin_register() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$event_id = (int) ( $_POST['event_id'] ?? 0 );
		if ( ! $event_id || get_post_type( $event_id ) !== 'cem_event' ) {
			wp_send_json_error( [ 'message' => __( 'Pick an event before adding a walk-in.', 'church-event-manager' ) ] );
		}

		$first = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$last  = sanitize_text_field( wp_unslash( $_POST['last_name']  ?? '' ) );
		if ( $first === '' || $last === '' ) {
			wp_send_json_error( [ 'message' => __( 'First and last name are required.', 'church-event-manager' ) ] );
		}

		// Email is optional for walk-ins. Synthesize a placeholder so the
		// duplicate-check + log keys still work; the placeholder is unique
		// per-registration and visibly fake so admins can spot it later.
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! $email ) {
			$email = 'walkin-' . wp_generate_password( 8, false, false ) . '@local.invalid';
		}

		$result = CEM_Registration::create( [
			'event_id'       => $event_id,
			'first_name'     => $first,
			'last_name'      => $last,
			'email'          => $email,
			'phone'          => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'num_attendees'  => max( 1, (int) ( $_POST['num_attendees'] ?? 1 ) ),
			'notes'          => __( 'Added as walk-in from check-in screen.', 'church-event-manager' ),
			'custom_fields'  => [],
			'user_id'        => get_current_user_id() ?: null,
			'payment_status' => 'free',
		] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Flip status to checked_in immediately. update_status() also writes
		// a row into cem_checkins for audit.
		CEM_Registration::update_status( (int) $result, 'checked_in' );

		wp_send_json_success( [
			'message'         => __( 'Walk-in added and checked in.', 'church-event-manager' ),
			'registration_id' => (int) $result,
		] );
	}

	// ── Public handlers ───────────────────────────────────────────────────────

	public function handle_registration() {
		// Buffer any stray PHP notices/warnings so they don't corrupt the JSON response.
		ob_start();

		// Verify nonce
		if ( ! isset( $_POST['cem_nonce'] ) || ! wp_verify_nonce( $_POST['cem_nonce'], 'cem_register_nonce' ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'church-event-manager' ) ] );
		}

		$event_id   = (int) ( $_POST['event_id'] ?? 0 );
		$post_type  = $event_id ? get_post_type( $event_id ) : false;
		if ( ! $event_id || ! in_array( $post_type, [ 'cem_event', 'cem_group' ], true ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => __( 'Invalid event.', 'church-event-manager' ) ] );
		}
		$is_group = ( $post_type === 'cem_group' );

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
			ob_end_clean();
			wp_send_json_error( [ 'message' => implode( '<br>', $field_errors ) ] );
		}

		// Registration type / pricing tier
		$reg_type_index = isset( $_POST['registration_type_index'] ) ? (int) $_POST['registration_type_index'] : -1;
		$reg_types_json = get_post_meta( $event_id, '_cem_registration_types', true );
		$reg_types      = $reg_types_json ? json_decode( $reg_types_json, true ) : [];
		$selected_type  = ( $reg_type_index >= 0 && isset( $reg_types[ $reg_type_index ] ) ) ? $reg_types[ $reg_type_index ] : null;

		$num_attendees = (int) ( $_POST['num_attendees'] ?? 1 );

		$data = [
			'event_id'      => $event_id,
			'first_name'    => sanitize_text_field( $_POST['first_name'] ?? '' ),
			'last_name'     => sanitize_text_field( $_POST['last_name'] ?? '' ),
			'email'         => sanitize_email( $_POST['email'] ?? '' ),
			'phone'         => sanitize_text_field( $_POST['phone'] ?? '' ),
			'num_attendees' => $num_attendees,
			'notes'         => sanitize_textarea_field( $_POST['notes'] ?? '' ),
			'custom_fields' => $custom_fields,
			'user_id'       => is_user_logged_in() ? get_current_user_id() : null,
		];

		if ( empty( $data['first_name'] ) || empty( $data['last_name'] ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => __( 'First and last name are required.', 'church-event-manager' ) ] );
		}
		if ( ! is_email( $data['email'] ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'church-event-manager' ) ] );
		}

		// ── Payment verification ───────────────────────────────────────────────
		// Groups (Event Series) are always free — no Stripe involved.
		$event_price    = $is_group ? '' : get_post_meta( $event_id, '_cem_price', true );
		$price_num      = ( $event_price !== '' ) ? (float) $event_price : 0.0;
		$allow_inperson = ! $is_group && get_post_meta( $event_id, '_cem_allow_inperson', true ) === '1';

		// If a registration type/tier was selected, use its price instead
		if ( $selected_type ) {
			$price_num = (float) $selected_type['price'];
		}

		if ( ! $is_group && $price_num > 0 && ! $allow_inperson && get_option( 'cem_stripe_enabled' ) === '1' ) {
			// ── Online payment required — verify with Stripe ──────────────────
			$pi_id = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );

			if ( empty( $pi_id ) ) {
				ob_end_clean();
				wp_send_json_error( [ 'message' => __( 'Payment is required for this event.', 'church-event-manager' ) ] );
			}

			// Verify the PaymentIntent server-side — prevents amount tampering.
			$verification = $this->verify_stripe_payment( $pi_id, $event_id, $price_num );
			if ( is_wp_error( $verification ) ) {
				ob_end_clean();
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

		// ── Category cap check ────────────────────────────────────────────────
		// For events (not groups), check if any assigned category has a registration cap
		// that would be exceeded by this registration.
		if ( ! $is_group ) {
			$cat_error = CEM_Helpers::check_category_cap( $event_id, $num_attendees );
			if ( $cat_error ) {
				ob_end_clean();
				wp_send_json_error( [ 'message' => $cat_error ] );
			}
		}

		$result = CEM_Registration::create( $data );

		if ( is_wp_error( $result ) ) {
			ob_end_clean();
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Save registration type/tier metadata
		if ( $selected_type ) {
			global $wpdb;
			$reg_meta_table = "{$wpdb->prefix}cem_registration_meta";

			$wpdb->insert( $reg_meta_table, [
				'registration_id' => $result,
				'meta_key'        => '_registration_type',
				'meta_value'      => sanitize_text_field( $selected_type['name'] ),
			], [ '%d', '%s', '%s' ] );

			$wpdb->insert( $reg_meta_table, [
				'registration_id' => $result,
				'meta_key'        => '_registration_type_price',
				'meta_value'      => number_format( (float) $selected_type['price'], 2, '.', '' ),
			], [ '%d', '%s', '%s' ] );
		}

		$reg = CEM_Registration::get( $result );
		// Guard: $reg should never be null here, but protect against it to prevent
		// a fatal "property on null" error in PHP 8 that would return a 500 and
		// trigger the JS .fail() handler.
		if ( ! $reg ) {
			ob_end_clean();
			wp_send_json_success( [
				'message'      => __( 'You\'re registered! Check your email for a confirmation.', 'church-event-manager' ),
				'code'         => '',
				'status'       => 'confirmed',
				'redirect_url' => '',
			] );
		}
		$message = $reg->status === 'waitlisted'
			? __( 'You have been added to the waitlist! We will contact you if a spot becomes available.', 'church-event-manager' )
			: __( 'You\'re registered! Check your email for a confirmation.', 'church-event-manager' );

		// Redirect URL — events always go to the church-events page,
		// groups go back to the groups listing page.
		if ( $is_group ) {
			$groups_page_id = get_option( 'cem_groups_page_id' );
			$redirect_url   = $groups_page_id ? get_permalink( $groups_page_id ) : home_url( '/groups/' );
		} else {
			$redirect_url = 'https://www.hillsidebristol.org/church-events';
		}

		ob_end_clean();
		wp_send_json_success( [
			'message'      => $message,
			'code'         => $reg->registration_code,
			'status'       => $reg->status,
			'redirect_url' => $redirect_url ?: '',
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

	public function leave_group() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'cem_leave_group_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'church-event-manager' ) ] );
		}

		$email    = sanitize_email( $_POST['email'] ?? '' );
		$group_id = (int) ( $_POST['group_id'] ?? 0 );

		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'church-event-manager' ) ] );
		}
		if ( ! $group_id || get_post_type( $group_id ) !== 'cem_group' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid group.', 'church-event-manager' ) ] );
		}

		$reg = CEM_Registration::get_by_email_and_event( $email, $group_id );
		if ( ! $reg ) {
			wp_send_json_error( [ 'message' => __( 'No active membership found for that email address.', 'church-event-manager' ) ] );
		}

		$result = CEM_Registration::update_status( $reg->id, 'cancelled' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		do_action( 'cem_registration_cancelled', $reg->id, $group_id );

		wp_send_json_success( [ 'message' => __( 'You have been removed from this group.', 'church-event-manager' ) ] );
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

		$event_id  = (int) ( $_GET['event_id'] ?? 0 );
		$status    = array_map( 'sanitize_key', (array) ( $_GET['status'] ?? [] ) );
		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to']   ?? '' ) );
		$search    = sanitize_text_field( wp_unslash( $_GET['s']         ?? '' ) );

		$data = CEM_Registration::get_export_data( $event_id, $status, [
			'date_from' => $date_from,
			'date_to'   => $date_to,
			'search'    => $search,
		] );
		$csv  = CEM_Helpers::array_to_csv( $data );

		// Use the WP timezone so a 9pm Saturday export doesn't get yesterday's
		// date stamped on the filename.
		$filename = 'registrations-' . current_time( 'Y-m-d' ) . '.csv';
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
		$reg    = CEM_Registration::get( $reg_id );

		if ( ! $reg ) {
			wp_send_json_error( [ 'message' => __( 'Registration not found.', 'church-event-manager' ) ] );
		}

		// Guard: don't promote if the event is already at capacity (race condition
		// protection — two admins clicking promote simultaneously).
		if ( CEM_Helpers::is_at_capacity( $reg->event_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Cannot promote: event is already at capacity.', 'church-event-manager' ) ] );
		}

		$result = CEM_Registration::update_status( $reg_id, 'confirmed' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// Remove from waitlist table
		global $wpdb;
		$wpdb->delete( "{$wpdb->prefix}cem_waitlist",
			[ 'event_id' => $reg->event_id, 'email' => $reg->email ],
			[ '%d', '%s' ]
		);
		CEM_Email::send_waitlist_promotion( $reg_id );

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

		// Single-address email settings.
		$email_settings = [ 'cem_from_email', 'cem_reply_to_email' ];
		foreach ( $email_settings as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, sanitize_email( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		// Admin notify email — accepts a comma-separated list of recipients.
		// We can't use sanitize_email() on the whole string because it strips
		// commas + spaces and mashes "a@x.com, b@y.com" into "a@x.comb@y.com".
		// Split on comma / semicolon / whitespace, sanitize each address
		// individually, then re-join with ", ".
		if ( isset( $_POST['cem_admin_notify_email'] ) ) {
			$raw   = trim( (string) wp_unslash( $_POST['cem_admin_notify_email'] ) );
			$parts = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
			$clean = [];
			foreach ( (array) $parts as $part ) {
				$email = sanitize_email( trim( $part ) );
				if ( $email && is_email( $email ) ) {
					$clean[] = $email;
				}
			}

			// If the user is intentionally clearing the field (raw is empty),
			// honor that. Otherwise: only persist when at least one address
			// validated — never silently wipe the previous good list because
			// of a typo or a single-character mistake in the form.
			if ( $raw === '' || ! empty( $clean ) ) {
				update_option( 'cem_admin_notify_email', implode( ', ', array_unique( $clean ) ) );
			}
		}

		// Page ID settings — saved as absint to ensure they're valid integers.
		$page_id_settings = [ 'cem_events_page_id', 'cem_groups_page_id', 'cem_registrations_page_id', 'cem_my_registrations_page_id' ];
		foreach ( $page_id_settings as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, absint( $_POST[ $key ] ) );
			}
		}

		// Plain text / number / color settings.
		$text_settings = [
			'cem_from_name',
			'cem_cancellation_days_before', 'cem_reminder_days_before',
			'cem_confirmation_subject', 'cem_reminder_subject', 'cem_cancellation_subject',
			'cem_events_per_page', 'cem_date_format', 'cem_time_format',
			'cem_currency_symbol', 'cem_accent_color',
			'cem_church_phone', 'cem_capacity_alert_pct',
			// Stripe payment settings
			'cem_stripe_publishable_key', 'cem_stripe_secret_key', 'cem_stripe_currency',
			// Error reporting
			'cem_client_id', 'cem_client_name', 'cem_error_reporting_endpoint',
		];

		foreach ( $text_settings as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		// Checkbox settings: only update checkboxes that were present on the submitted tab.
		// Each tab renders a hidden <input name="cem_checkbox_fields[]"> marker for every
		// checkbox it contains, so we know which ones to write '0' for when unchecked.
		// This prevents saving tab A from silently zeroing out checkboxes from tabs B/C.
		$allowed_checkboxes = [
			'cem_admin_notify_on_register',
			'cem_registration_auto_confirm',
			'cem_waitlist_enabled',
			'cem_allow_cancellations',
			'cem_send_reminders',
			'cem_stripe_enabled',
			'cem_stripe_test_mode',
			'cem_error_reporting_enabled',
		];
		$present_checkboxes = isset( $_POST['cem_checkbox_fields'] ) && is_array( $_POST['cem_checkbox_fields'] )
			? array_map( 'sanitize_key', $_POST['cem_checkbox_fields'] )
			: [];

		foreach ( $present_checkboxes as $key ) {
			if ( in_array( $key, $allowed_checkboxes, true ) ) {
				update_option( $key, ( isset( $_POST[ $key ] ) && $_POST[ $key ] === '1' ) ? '1' : '0' );
			}
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

		// "not_checked_in" is a synthetic filter: it means
		// "registered (confirmed or pending) but never checked in".
		// We fetch by status and filter in PHP since checked_in_at is
		// not exposed as a query argument.
		$post_filter = null;
		if ( $status === 'not_checked_in' ) {
			$status_filter = [ 'confirmed', 'pending' ];
			$post_filter   = 'no_checkin';
		} elseif ( $status ) {
			$status_filter = [ $status ];
		} else {
			$status_filter = [];
		}

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

		if ( $post_filter === 'no_checkin' ) {
			$regs = array_values( array_filter( (array) $regs, function ( $r ) {
				return empty( $r->checked_in_at );
			} ) );
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

		// Resolve price: use the selected registration tier if provided, otherwise
		// fall back to the event-level _cem_price meta.
		$reg_type_index = isset( $_POST['registration_type_index'] ) ? (int) $_POST['registration_type_index'] : -1;
		$reg_types_json = get_post_meta( $event_id, '_cem_registration_types', true );
		$reg_types      = $reg_types_json ? json_decode( $reg_types_json, true ) : [];

		if ( $reg_types && $reg_type_index >= 0 && isset( $reg_types[ $reg_type_index ] ) ) {
			$price_num = (float) $reg_types[ $reg_type_index ]['price'];
		} else {
			$price     = get_post_meta( $event_id, '_cem_price', true );
			$price_num = ( $price !== '' ) ? (float) $price : 0.0;
		}

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
	 * Update an existing PaymentIntent's amount when the user switches tier.
	 * Called by cem-stripe.js on registration_type_index change.
	 */
	public function update_payment_intent() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'cem_payment_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Security check failed.', 'church-event-manager' ) ] );
		}

		$event_id          = (int) ( $_POST['event_id'] ?? 0 );
		$payment_intent_id = sanitize_text_field( $_POST['payment_intent_id'] ?? '' );
		$reg_type_index    = isset( $_POST['registration_type_index'] ) ? (int) $_POST['registration_type_index'] : -1;

		if ( ! $event_id || get_post_type( $event_id ) !== 'cem_event' ) {
			wp_send_json_error( [ 'message' => __( 'Invalid event.', 'church-event-manager' ) ] );
		}

		if ( ! preg_match( '/^pi_[a-zA-Z0-9_]+$/', $payment_intent_id ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid payment reference.', 'church-event-manager' ) ] );
		}

		// Resolve the tier price
		$reg_types_json = get_post_meta( $event_id, '_cem_registration_types', true );
		$reg_types      = $reg_types_json ? json_decode( $reg_types_json, true ) : [];

		if ( $reg_types && $reg_type_index >= 0 && isset( $reg_types[ $reg_type_index ] ) ) {
			$price_num = (float) $reg_types[ $reg_type_index ]['price'];
		} else {
			$price     = get_post_meta( $event_id, '_cem_price', true );
			$price_num = ( $price !== '' ) ? (float) $price : 0.0;
		}

		if ( $price_num <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Selected tier is free — no payment required.', 'church-event-manager' ) ] );
		}

		$secret_key = get_option( 'cem_stripe_secret_key', '' );
		if ( empty( $secret_key ) ) {
			wp_send_json_error( [ 'message' => __( 'Payment processing is not configured.', 'church-event-manager' ) ] );
		}

		$amount_cents = (int) round( $price_num * 100 );
		$currency     = strtolower( get_option( 'cem_stripe_currency', 'usd' ) );

		$response = wp_remote_post(
			'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $payment_intent_id ),
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'    => [
					'amount'   => $amount_cents,
					'currency' => $currency,
				],
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => __( 'Could not connect to payment processor. Please try again.', 'church-event-manager' ) ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $body['error'] ) ) {
			wp_send_json_error( [ 'message' => esc_html( $body['error']['message'] ?? __( 'Payment error.', 'church-event-manager' ) ) ] );
		}

		wp_send_json_success( [
			'amount'        => $amount_cents,
			'amount_display' => get_option( 'cem_currency_symbol', '$' ) . number_format( $price_num, 2 ),
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

	// ── Stripe Test Connection ────────────────────────────────────────────────

	/**
	 * Verify Stripe API credentials by calling GET /v1/account (read-only, no charges).
	 */
	public function test_stripe_connection() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$secret_key = get_option( 'cem_stripe_secret_key', '' );
		if ( empty( $secret_key ) ) {
			wp_send_json_error( [ 'message' => __( 'No secret key configured. Enter your Stripe secret key and save first.', 'church-event-manager' ) ] );
		}

		$response = wp_remote_get( 'https://api.stripe.com/v1/account', [
			'headers' => [ 'Authorization' => 'Bearer ' . $secret_key ],
			'timeout' => 15,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => sprintf( __( 'Could not reach Stripe: %s', 'church-event-manager' ), $response->get_error_message() ) ] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code === 200 ) {
			$mode = str_starts_with( $secret_key, 'sk_test_' ) ? 'test' : 'live';
			$pub  = get_option( 'cem_stripe_publishable_key', '' );
			$pub_mode = str_starts_with( $pub, 'pk_test_' ) ? 'test' : ( str_starts_with( $pub, 'pk_live_' ) ? 'live' : 'unknown' );
			$mismatch = ( $mode === 'test' && $pub_mode === 'live' ) || ( $mode === 'live' && $pub_mode === 'test' );
			$msg = sprintf( __( 'Connected! Account: %s (mode: %s)', 'church-event-manager' ), $body['email'] ?? $body['id'] ?? 'unknown', $mode );
			if ( $mismatch ) {
				$msg .= ' ' . __( 'WARNING: Key mismatch — secret key is', 'church-event-manager' ) . " $mode " . __( 'but publishable key is', 'church-event-manager' ) . " $pub_mode.";
			}
			wp_send_json_success( [ 'message' => $msg ] );
		} else {
			$err = $body['error']['message'] ?? "HTTP $code";
			wp_send_json_error( [ 'message' => sprintf( __( 'Stripe error: %s', 'church-event-manager' ), $err ) ] );
		}
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
				'message' => __( "Your ticket has been sent. We'll be in touch at the email address you provided.", 'church-event-manager' ),
			] );
		} else {
			wp_send_json_error( [
				'message' => __( 'The email could not be sent. Please email us directly at zach@whiteoakmedia.io.', 'church-event-manager' ),
			] );
		}
	}

	// ── Check-In Page ────────────────────────────────────────────────────────

	/**
	 * Load all registrations for an event (for the check-in page).
	 * Returns registrants + counts for the live check-in UI.
	 */
	public function checkin_load() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$event_id = (int) ( $_GET['event_id'] ?? $_POST['event_id'] ?? 0 );
		if ( ! $event_id ) {
			wp_send_json_error( [ 'message' => __( 'No event selected.', 'church-event-manager' ) ] );
		}

		$regs = CEM_Registration::get_for_event( $event_id, [
			'status'   => [ 'confirmed', 'checked_in', 'pending' ],
			'per_page' => 0,
			'orderby'  => 'last_name',
			'order'    => 'ASC',
		] );

		$registrants = [];
		$checked_in  = 0;
		$total       = 0;
		foreach ( $regs as $r ) {
			$registrants[] = [
				'id'            => (int) $r->id,
				'first_name'    => $r->first_name,
				'last_name'     => $r->last_name,
				'email'         => $r->email,
				'phone'         => $r->phone,
				'num_attendees' => (int) $r->num_attendees,
				'status'        => $r->status,
				'checked_in_at' => $r->checked_in_at,
			];
			$total += (int) $r->num_attendees;
			if ( $r->status === 'checked_in' ) {
				$checked_in += (int) $r->num_attendees;
			}
		}

		$capacity = (int) get_post_meta( $event_id, '_cem_capacity', true );

		wp_send_json_success( [
			'registrants'    => $registrants,
			'total'          => $total,
			'checked_in'     => $checked_in,
			'capacity'       => $capacity,
			'event_title'    => get_the_title( $event_id ),
		] );
	}

	// ── Email My Registrations (public) ──────────────────────────────────────

	public function email_my_registrations() {
		check_ajax_referer( 'cem_public_nonce', 'nonce' );

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error( [ 'message' => __( 'Please enter a valid email address.', 'church-event-manager' ) ] );
		}

		$regs = CEM_Registration::get_for_user( $email );

		// Privacy: same response whether or not the email matches anything.
		// Don't reveal which addresses have registrations on file.
		if ( ! empty( $regs ) ) {
			CEM_Shortcodes::send_registration_list_email( $email, $regs );
		}

		wp_send_json_success( [
			'message' => __( "If we have any registrations for that email address, we've just sent them to you.", 'church-event-manager' ),
		] );
	}

	// ── Bulk Email Preview (admin) ─────────────────────────────────────────────

	public function preview_bulk_email() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$reg_id  = (int) ( $_POST['registration_id'] ?? 0 );
		$subject = sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) );
		$message = wp_kses_post( wp_unslash( $_POST['message'] ?? '' ) );

		if ( ! $reg_id || ! $subject || ! $message ) {
			wp_send_json_error( [ 'message' => __( 'Enter a subject and message, then preview recipients first.', 'church-event-manager' ) ] );
		}

		$reg = CEM_Registration::get( $reg_id );
		if ( ! $reg ) {
			wp_send_json_error( [ 'message' => __( 'Recipient not found.', 'church-event-manager' ) ] );
		}

		$event   = get_post( $reg->event_id );
		$vars    = CEM_Email::get_template_vars( $reg, $event );
		$subject_parsed = CEM_Helpers::parse_template( $subject, $vars );
		$body_parsed    = CEM_Helpers::parse_template( $message, $vars );

		wp_send_json_success( [
			'subject'         => $subject_parsed,
			'html'            => CEM_Email::wrap_in_layout( $body_parsed, $subject_parsed ),
			'recipient_name'  => esc_html( trim( $reg->first_name . ' ' . $reg->last_name ) ),
			'recipient_email' => esc_html( $reg->email ),
		] );
	}

	// ── Utility ───────────────────────────────────────────────────────────────

	private function require_admin() {
		$this->require_capability( 'cem_manage_events' );
	}

	private function require_capability( $cap ) {
		if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'church-event-manager' ) ], 403 );
		}
	}

	private function require_checkin() {
		$this->require_capability( 'cem_check_in' );
	}

	// ── Email Log Preview ─────────────────────────────────────────────────────

	/**
	 * Return the stored HTML for a logged email so the admin can preview it
	 * in a modal iframe without re-sending anything.
	 */
	public function preview_email_log() {
		$this->require_admin();
		check_ajax_referer( 'cem_admin_nonce', 'nonce' );

		$log_id = (int) ( $_POST['log_id'] ?? 0 );
		if ( ! $log_id ) {
			wp_send_json_error( [ 'message' => __( 'Invalid log ID.', 'church-event-manager' ) ] );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT subject, message FROM {$wpdb->prefix}cem_email_log WHERE id = %d LIMIT 1",
			$log_id
		) );

		if ( ! $row ) {
			wp_send_json_error( [ 'message' => __( 'Log entry not found.', 'church-event-manager' ) ] );
		}

		wp_send_json_success( [
			'subject' => $row->subject,
			'html'    => $row->message,
		] );
	}
}
