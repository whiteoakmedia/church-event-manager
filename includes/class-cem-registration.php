<?php
/**
 * Registration CRUD and business logic.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Registration {

	// ── Create ────────────────────────────────────────────────────────────────

	/**
	 * Create a new registration.
	 *
	 * @param array $data {
	 *   event_id, first_name, last_name, email, phone, num_attendees, notes,
	 *   custom_fields (assoc array), user_id,
	 *   payment_intent_id (string|null), payment_status ('free'|'paid'|'pending')
	 * }
	 * @return int|WP_Error  registration ID on success, WP_Error on failure
	 */
	public static function create( array $data ) {
		global $wpdb;

		$event_id  = (int) $data['event_id'];
		$post_type = $event_id ? get_post_type( $event_id ) : false;
		$is_group  = ( $post_type === 'cem_group' );

		if ( ! $event_id || ! in_array( $post_type, [ 'cem_event', 'cem_group' ], true ) ) {
			return new WP_Error( 'invalid_event', __( 'Invalid event.', 'church-event-manager' ) );
		}

		if ( $is_group ) {
			// For groups, use the group's own status field
			$group_status = get_post_meta( $event_id, '_cem_group_status', true ) ?: 'open';
			if ( $group_status !== 'open' ) {
				return new WP_Error( 'registration_closed', __( 'This group is not currently accepting new members.', 'church-event-manager' ) );
			}
		} else {
			// Check registration is open (events only)
			$reg_status = get_post_meta( $event_id, '_cem_registration_status', true );
			if ( $reg_status === 'closed' ) {
				return new WP_Error( 'registration_closed', __( 'Registration is closed for this event.', 'church-event-manager' ) );
			}

			// Check deadline (events only)
			$deadline = get_post_meta( $event_id, '_cem_registration_deadline', true );
			if ( $deadline && strtotime( $deadline ) < time() ) {
				return new WP_Error( 'registration_closed', __( 'The registration deadline has passed.', 'church-event-manager' ) );
			}
		}

		$num_attendees = max( 1, (int) ( $data['num_attendees'] ?? 1 ) );
		$waitlisted    = false;

		// Capacity check
		$at_capacity = $is_group
			? CEM_Group::is_at_capacity( $event_id )
			: CEM_Helpers::is_at_capacity( $event_id );

		if ( $at_capacity ) {
			if ( ! $is_group && get_option( 'cem_waitlist_enabled' ) ) {
				$waitlisted = true;
			} else {
				return new WP_Error( 'capacity_full', $is_group
					? __( 'This group is full.', 'church-event-manager' )
					: __( 'This event is full.', 'church-event-manager' )
				);
			}
		}

		// Duplicate check (same email + event/group, not cancelled)
		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$wpdb->prefix}cem_registrations
			 WHERE event_id = %d AND email = %s AND status NOT IN ('cancelled')",
			$event_id, sanitize_email( $data['email'] )
		) );
		if ( $existing ) {
			return new WP_Error( 'duplicate', $is_group
				? __( 'You are already a member of this group.', 'church-event-manager' )
				: __( 'You are already registered for this event.', 'church-event-manager' )
			);
		}

		$auto_confirm = get_option( 'cem_registration_auto_confirm', '1' );
		$status       = $waitlisted ? 'waitlisted' : ( $auto_confirm ? 'confirmed' : 'pending' );
		$code         = CEM_Helpers::generate_code();
		$now          = current_time( 'mysql' );

		$payment_status    = sanitize_key( $data['payment_status']    ?? 'free' );
		$payment_intent_id = sanitize_text_field( $data['payment_intent_id'] ?? '' ) ?: null;

		$inserted = $wpdb->insert(
			"{$wpdb->prefix}cem_registrations",
			[
				'event_id'          => $event_id,
				'user_id'           => ! empty( $data['user_id'] ) ? (int) $data['user_id'] : null,
				'first_name'        => sanitize_text_field( $data['first_name'] ),
				'last_name'         => sanitize_text_field( $data['last_name'] ),
				'email'             => sanitize_email( $data['email'] ),
				'phone'             => isset( $data['phone'] ) ? CEM_Helpers::sanitize_phone( $data['phone'] ) : null,
				'num_attendees'     => $num_attendees,
				'status'            => $status,
				'registration_code' => $code,
				'notes'             => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : null,
				'payment_status'    => $payment_status,
				'payment_intent_id' => $payment_intent_id,
				'created_at'        => $now,
				'updated_at'        => $now,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);

		if ( ! $inserted ) {
			return new WP_Error( 'db_error', __( 'Could not save your registration. Please try again.', 'church-event-manager' ) );
		}

		$registration_id = (int) $wpdb->insert_id;

		// Save custom field answers
		if ( ! empty( $data['custom_fields'] ) && is_array( $data['custom_fields'] ) ) {
			foreach ( $data['custom_fields'] as $field_name => $field_value ) {
				$wpdb->insert(
					"{$wpdb->prefix}cem_registration_meta",
					[
						'registration_id' => $registration_id,
						'meta_key'        => sanitize_key( $field_name ),
						'meta_value'      => is_array( $field_value )
							? implode( ', ', array_map( 'sanitize_text_field', $field_value ) )
							: sanitize_text_field( $field_value ),
					],
					[ '%d', '%s', '%s' ]
				);
			}
		}

		// If waitlisted, record in waitlist table
		if ( $waitlisted ) {
			$position = self::get_waitlist_count( $event_id ) + 1;
			$wpdb->insert(
				"{$wpdb->prefix}cem_waitlist",
				[
					'event_id'      => $event_id,
					'first_name'    => sanitize_text_field( $data['first_name'] ),
					'last_name'     => sanitize_text_field( $data['last_name'] ),
					'email'         => sanitize_email( $data['email'] ),
					'phone'         => isset( $data['phone'] ) ? CEM_Helpers::sanitize_phone( $data['phone'] ) : null,
					'num_attendees' => $num_attendees,
					'position'      => $position,
					'created_at'    => $now,
				],
				[ '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ]
			);
		}

		// Fire actions so notifications can hook in
		do_action( 'cem_after_registration', $registration_id, $event_id );
		if ( $status === 'confirmed' ) {
			do_action( 'cem_registration_confirmed', $registration_id, $event_id );
		}

		return $registration_id;
	}

	// ── Read ──────────────────────────────────────────────────────────────────

	/** Get a single registration by ID. */
	public static function get( $registration_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_registrations WHERE id = %d",
			$registration_id
		) );
	}

	/** Get an active (non-cancelled) registration by email + event/group ID. */
	public static function get_by_email_and_event( $email, $event_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_registrations
			 WHERE event_id = %d AND email = %s AND status NOT IN ('cancelled')
			 ORDER BY created_at DESC LIMIT 1",
			$event_id, sanitize_email( $email )
		) );
	}

	/** Get a registration by its unique code. */
	public static function get_by_code( $code ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_registrations WHERE registration_code = %s",
			$code
		) );
	}

	/** Get all registrations for an event (with optional status filter). */
	public static function get_for_event( $event_id, $args = [] ) {
		global $wpdb;

		$defaults = [
			'status'   => [],     // array of statuses to include, empty = all
			'per_page' => 0,      // 0 = no limit
			'page'     => 1,
			'search'   => '',
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		];
		$args = wp_parse_args( $args, $defaults );

		$where   = [ $wpdb->prepare( "event_id = %d", $event_id ) ];
		$values  = [];

		if ( ! empty( $args['status'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
			$where[]  = "status IN ($placeholders)";
			$values   = array_merge( $values, $args['status'] );
		}

		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = "( first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR registration_code LIKE %s )";
			$values  = array_merge( $values, [ $like, $like, $like, $like ] );
		}

		$allowed_orderby = [ 'created_at', 'last_name', 'first_name', 'email', 'status', 'num_attendees' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$sql = "SELECT * FROM {$wpdb->prefix}cem_registrations WHERE " . implode( ' AND ', $where )
			 . " ORDER BY $orderby $order";

		if ( $args['per_page'] > 0 ) {
			$offset = ( $args['page'] - 1 ) * $args['per_page'];
			$sql   .= $wpdb->prepare( " LIMIT %d OFFSET %d", $args['per_page'], $offset );
		}

		return $wpdb->get_results( empty( $values ) ? $sql : $wpdb->prepare( $sql, $values ) );
	}

	/** Get all registrations across all events with optional filters. */
	public static function get_all( $args = [] ) {
		global $wpdb;

		$defaults = [
			'status'    => [],
			'event_id'  => 0,
			'event_ids' => [], // array of IDs — used for filtering to a specific set
			'per_page'  => 25,
			'page'      => 1,
			'search'    => '',
			'orderby'   => 'created_at',
			'order'     => 'DESC',
			'date_from' => '',
			'date_to'   => '',
		];
		$args   = wp_parse_args( $args, $defaults );
		$where  = [ '1=1' ];
		$values = [];

		if ( $args['event_id'] ) {
			$where[] = $wpdb->prepare( "event_id = %d", $args['event_id'] );
		} elseif ( ! empty( $args['event_ids'] ) ) {
			$ids          = array_map( 'absint', $args['event_ids'] );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where[]      = $wpdb->prepare( "event_id IN ($placeholders)", ...$ids ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
		}
		if ( ! empty( $args['status'] ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $args['status'] ), '%s' ) );
			$where[]  = "status IN ($placeholders)";
			$values   = array_merge( $values, $args['status'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[] = "( first_name LIKE %s OR last_name LIKE %s OR email LIKE %s )";
			$values  = array_merge( $values, [ $like, $like, $like ] );
		}
		if ( $args['date_from'] ) {
			$where[] = $wpdb->prepare( "created_at >= %s", $args['date_from'] . ' 00:00:00' );
		}
		if ( $args['date_to'] ) {
			$where[] = $wpdb->prepare( "created_at <= %s", $args['date_to'] . ' 23:59:59' );
		}

		$allowed_orderby = [ 'created_at', 'last_name', 'first_name', 'email', 'status', 'event_id' ];
		$orderby = in_array( $args['orderby'], $allowed_orderby ) ? $args['orderby'] : 'created_at';
		$order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$wpdb->prefix}cem_registrations WHERE $where_sql";
		$total = (int) ( empty( $values ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $values ) ) );

		$sql = "SELECT * FROM {$wpdb->prefix}cem_registrations WHERE $where_sql ORDER BY $orderby $order";

		if ( $args['per_page'] > 0 ) {
			// Paginated query — append LIMIT / OFFSET.
			$offset            = ( $args['page'] - 1 ) * $args['per_page'];
			$sql              .= " LIMIT %d OFFSET %d";
			$values_for_query  = array_merge( $values, [ $args['per_page'], $offset ] );
		} else {
			// per_page = 0 means "no limit" (used by exports).
			$values_for_query = $values;
		}

		$rows = empty( $values_for_query )
			? $wpdb->get_results( $sql )
			: $wpdb->get_results( $wpdb->prepare( $sql, $values_for_query ) );

		return [ 'registrations' => $rows, 'total' => $total ];
	}

	/** Get custom field answers for a registration. */
	public static function get_meta( $registration_id ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT meta_key, meta_value FROM {$wpdb->prefix}cem_registration_meta WHERE registration_id = %d",
			$registration_id
		) );
		$meta = [];
		foreach ( $rows as $row ) {
			$meta[ $row->meta_key ] = $row->meta_value;
		}
		return $meta;
	}

	/** Get registrations for a user by email or user ID. */
	public static function get_for_user( $email = '', $user_id = 0 ) {
		global $wpdb;
		if ( $user_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cem_registrations WHERE user_id = %d ORDER BY created_at DESC",
				$user_id
			) );
		} elseif ( $email ) {
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}cem_registrations WHERE email = %s ORDER BY created_at DESC",
				$email
			) );
		} else {
			return [];
		}
		return $rows;
	}

	// ── Update ────────────────────────────────────────────────────────────────

	/** Update registration status. */
	public static function update_status( $registration_id, $status ) {
		global $wpdb;
		$allowed = [ 'pending', 'confirmed', 'cancelled', 'waitlisted', 'checked_in' ];
		if ( ! in_array( $status, $allowed ) ) {
			return new WP_Error( 'invalid_status', __( 'Invalid status.', 'church-event-manager' ) );
		}

		$reg = self::get( $registration_id );
		if ( ! $reg ) {
			return new WP_Error( 'not_found', __( 'Registration not found.', 'church-event-manager' ) );
		}

		$update_data = [ 'status' => $status, 'updated_at' => current_time( 'mysql' ) ];
		$update_fmt  = [ '%s', '%s' ];

		if ( $status === 'checked_in' && $reg->status !== 'checked_in' ) {
			$update_data['checked_in_at'] = current_time( 'mysql' );
			$update_fmt[] = '%s';

			// Log check-in
			$wpdb->insert( "{$wpdb->prefix}cem_checkins", [
				'registration_id' => $registration_id,
				'event_id'        => $reg->event_id,
				'checked_in_by'   => get_current_user_id() ?: null,
				'checked_in_at'   => current_time( 'mysql' ),
			], [ '%d', '%d', '%d', '%s' ] );
		}

		$result = $wpdb->update(
			"{$wpdb->prefix}cem_registrations",
			$update_data,
			[ 'id' => $registration_id ],
			$update_fmt,
			[ '%d' ]
		);

		// Fire action hooks for notifications
		if ( $result !== false ) {
			if ( $status === 'confirmed' && $reg->status !== 'confirmed' ) {
				do_action( 'cem_registration_confirmed', $registration_id, $reg->event_id );
			}
			if ( $status === 'cancelled' && $reg->status !== 'cancelled' ) {
				do_action( 'cem_registration_cancelled', $registration_id, $reg->event_id );
				// Promote from waitlist if a spot opens up
				self::promote_from_waitlist( $reg->event_id );
			}
		}

		return $result;
	}

	/**
	 * Update editable fields of a registration by code (front-end use).
	 *
	 * Accepts a nonce verified against 'cem_manage_{code}' — the same action
	 * used by cancel_by_code(), so the same fresh nonce works for both.
	 *
	 * @param string $code  registration_code
	 * @param string $nonce WordPress nonce
	 * @param array  $data  Keys: num_attendees (int), phone (string), notes (string).
	 *                      Pass null for a key to leave it unchanged.
	 * @return true|WP_Error
	 */
	public static function update_by_code( $code, $nonce, array $data ) {
		if ( ! wp_verify_nonce( $nonce, 'cem_manage_' . $code ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'church-event-manager' ) );
		}

		$reg = self::get_by_code( $code );
		if ( ! $reg ) {
			return new WP_Error( 'not_found', __( 'Registration not found.', 'church-event-manager' ) );
		}
		if ( $reg->status === 'cancelled' ) {
			return new WP_Error( 'cancelled', __( 'Cannot update a cancelled registration.', 'church-event-manager' ) );
		}

		global $wpdb;
		$update  = [];
		$formats = [];

		if ( array_key_exists( 'num_attendees', $data ) && $data['num_attendees'] !== null ) {
			$update['num_attendees'] = max( 1, (int) $data['num_attendees'] );
			$formats[] = '%d';
		}
		if ( array_key_exists( 'phone', $data ) && $data['phone'] !== null ) {
			$update['phone'] = CEM_Helpers::sanitize_phone( $data['phone'] );
			$formats[] = '%s';
		}
		if ( array_key_exists( 'notes', $data ) && $data['notes'] !== null ) {
			$update['notes'] = sanitize_textarea_field( $data['notes'] );
			$formats[] = '%s';
		}

		if ( empty( $update ) ) {
			return true; // nothing to change
		}

		$update['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';

		$result = $wpdb->update(
			"{$wpdb->prefix}cem_registrations",
			$update,
			[ 'id' => $reg->id ],
			$formats,
			[ '%d' ]
		);

		return ( $result !== false )
			? true
			: new WP_Error( 'db_error', __( 'Could not save your changes. Please try again.', 'church-event-manager' ) );
	}

	/** Cancel a registration by code (front-end use). */
	public static function cancel_by_code( $code, $nonce ) {
		if ( ! wp_verify_nonce( $nonce, 'cem_manage_' . $code ) ) {
			return new WP_Error( 'invalid_nonce', __( 'Security check failed.', 'church-event-manager' ) );
		}

		$reg = self::get_by_code( $code );
		if ( ! $reg ) {
			return new WP_Error( 'not_found', __( 'Registration not found.', 'church-event-manager' ) );
		}

		if ( $reg->status === 'cancelled' ) {
			return new WP_Error( 'already_cancelled', __( 'This registration has already been cancelled.', 'church-event-manager' ) );
		}

		// Check deadline
		$deadline = CEM_Helpers::get_cancellation_deadline( $reg->event_id );
		if ( $deadline && strtotime( $deadline ) < time() ) {
			return new WP_Error( 'past_deadline', __( 'The cancellation deadline has passed.', 'church-event-manager' ) );
		}

		return self::update_status( $reg->id, 'cancelled' );
	}

	// ── Waitlist ──────────────────────────────────────────────────────────────

	public static function get_waitlist_count( $event_id ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}cem_waitlist WHERE event_id = %d",
			$event_id
		) );
	}

	public static function get_waitlist( $event_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_waitlist WHERE event_id = %d ORDER BY position ASC",
			$event_id
		) );
	}

	/** Move first waitlisted registration to confirmed when a spot opens. */
	public static function promote_from_waitlist( $event_id ) {
		if ( CEM_Helpers::is_at_capacity( $event_id ) ) return;

		global $wpdb;

		// Get the first waitlisted registration
		$reg = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}cem_registrations
			 WHERE event_id = %d AND status = 'waitlisted'
			 ORDER BY created_at ASC LIMIT 1",
			$event_id
		) );

		if ( ! $reg ) return;

		self::update_status( $reg->id, 'confirmed' );

		// Remove from waitlist table
		$wpdb->delete( "{$wpdb->prefix}cem_waitlist", [
			'event_id' => $event_id,
			'email'    => $reg->email,
		], [ '%d', '%s' ] );

		do_action( 'cem_waitlist_promoted', $reg->id, $event_id );
	}

	// ── Delete ────────────────────────────────────────────────────────────────

	public static function delete( $registration_id ) {
		global $wpdb;
		$reg = self::get( $registration_id );
		if ( ! $reg ) return false;

		$wpdb->delete( "{$wpdb->prefix}cem_registration_meta", [ 'registration_id' => $registration_id ], [ '%d' ] );
		return $wpdb->delete( "{$wpdb->prefix}cem_registrations", [ 'id' => $registration_id ], [ '%d' ] );
	}

	// ── Bulk ─────────────────────────────────────────────────────────────────

	public static function bulk_update_status( array $ids, $status ) {
		$results = [];
		foreach ( $ids as $id ) {
			$results[ $id ] = self::update_status( (int) $id, $status );
		}
		return $results;
	}

	// ── Export ────────────────────────────────────────────────────────────────

	public static function get_export_data( $event_id = 0, $status_filter = [] ) {
		$args = [
			'per_page' => 0,
			'status'   => $status_filter,
			'orderby'  => 'last_name',
			'order'    => 'ASC',
		];

		if ( $event_id ) {
			$regs = self::get_for_event( $event_id, $args );
		} else {
			$result = self::get_all( $args );
			$regs   = $result['registrations'];
		}

		$rows = [];
		foreach ( $regs as $reg ) {
			$event = get_post( $reg->event_id );
			$meta  = self::get_meta( $reg->id );
			$row   = [
				'ID'                => $reg->id,
				'Code'              => $reg->registration_code,
				'Event'             => $event ? $event->post_title : $reg->event_id,
				'First Name'        => $reg->first_name,
				'Last Name'         => $reg->last_name,
				'Email'             => $reg->email,
				'Phone'             => $reg->phone,
				'# Attendees'       => $reg->num_attendees,
				'Status'            => $reg->status,
				'Payment Status'    => $reg->payment_status ?? 'free',
				'Payment Intent ID' => $reg->payment_intent_id ?? '',
				'Registered At'     => $reg->created_at,
				'Checked In At'     => $reg->checked_in_at,
				'Notes'             => $reg->notes,
			];
			foreach ( $meta as $key => $value ) {
				$row[ 'Custom: ' . $key ] = $value;
			}
			$rows[] = $row;
		}
		return $rows;
	}

	// ── Stats ─────────────────────────────────────────────────────────────────

	public static function get_dashboard_stats() {
		global $wpdb;
		$table = "{$wpdb->prefix}cem_registrations";
		$today = current_time( 'Y-m-d' );

		return [
			'total_events'         => wp_count_posts( 'cem_event' )->publish ?? 0,
			'total_registrations'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status NOT IN ('cancelled')" ),
			'registrations_today'  => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE DATE(created_at) = %s AND status NOT IN ('cancelled')", $today
			) ),
			'upcoming_events'      => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_cem_start_datetime'
				 WHERE p.post_type = 'cem_event' AND p.post_status = 'publish' AND pm.meta_value >= %s",
				current_time( 'mysql' )
			) ),
			'pending_confirmations'=> (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'pending'" ),
			'total_waitlisted'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'waitlisted'" ),
		];
	}
}
