<?php
/**
 * Static helper / utility functions.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Helpers {

	/** Generate a cryptographically random registration code. */
	public static function generate_code( $length = 12 ) {
		return strtoupper( substr( bin2hex( random_bytes( 8 ) ), 0, $length ) );
	}

	/** Format a MySQL datetime for display. */
	public static function format_datetime( $datetime, $format = null ) {
		if ( ! $datetime || $datetime === '0000-00-00 00:00:00' ) {
			return '—';
		}
		$date_format = $format ?? ( get_option( 'cem_date_format', 'F j, Y' ) . ' ' . get_option( 'cem_time_format', 'g:i a' ) );
		return date_i18n( $date_format, strtotime( $datetime ) );
	}

	/** Format a date only. */
	public static function format_date( $datetime ) {
		return self::format_datetime( $datetime, get_option( 'cem_date_format', 'F j, Y' ) );
	}

	/** Format a time only. */
	public static function format_time( $datetime ) {
		return self::format_datetime( $datetime, get_option( 'cem_time_format', 'g:i a' ) );
	}

	/** Sanitize a phone number (keep digits and + - ()). */
	public static function sanitize_phone( $phone ) {
		return preg_replace( '/[^0-9+\-() ]/', '', $phone );
	}

	/** Get status label with colour indicator. */
	public static function status_badge( $status ) {
		$labels = [
			'pending'    => [ 'label' => __( 'Waiting for Approval', 'church-event-manager' ), 'class' => 'cem-badge--yellow' ],
			'confirmed'  => [ 'label' => __( 'Approved',             'church-event-manager' ), 'class' => 'cem-badge--green'  ],
			'cancelled'  => [ 'label' => __( 'Cancelled',            'church-event-manager' ), 'class' => 'cem-badge--red'    ],
			'waitlisted' => [ 'label' => __( 'On Waiting List',      'church-event-manager' ), 'class' => 'cem-badge--blue'   ],
			'checked_in' => [ 'label' => __( 'Here',                 'church-event-manager' ), 'class' => 'cem-badge--purple' ],
		];
		$info = $labels[ $status ] ?? [ 'label' => ucfirst( $status ), 'class' => 'cem-badge--grey' ];
		return '<span class="cem-badge ' . esc_attr( $info['class'] ) . '">' . esc_html( $info['label'] ) . '</span>';
	}

	/** Get event meta with a default fallback. */
	public static function get_event_meta( $event_id, $key, $default = '' ) {
		$val = get_post_meta( $event_id, '_cem_' . $key, true );
		return ( $val !== '' && $val !== false ) ? $val : $default;
	}

	/** Count confirmed registrations for an event (including check-ins). */
	public static function get_registration_count( $event_id, $include_statuses = [ 'confirmed', 'checked_in' ] ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $include_statuses ), '%s' ) );
		$query_args   = array_merge( [ $event_id ], $include_statuses );
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(num_attendees),0)
				 FROM {$wpdb->prefix}cem_registrations
				 WHERE event_id = %d AND status IN ($placeholders)",
				$query_args
			)
		);
		return (int) $count;
	}

	/** Check if an event is at capacity. */
	public static function is_at_capacity( $event_id ) {
		$capacity = (int) self::get_event_meta( $event_id, 'capacity', 0 );
		if ( $capacity <= 0 ) return false; // 0 = unlimited
		$taken = self::get_registration_count( $event_id );
		return $taken >= $capacity;
	}

	/** Get spots remaining for an event. */
	public static function get_spots_remaining( $event_id ) {
		$capacity = (int) self::get_event_meta( $event_id, 'capacity', 0 );
		if ( $capacity <= 0 ) return null; // unlimited
		$taken = self::get_registration_count( $event_id );
		return max( 0, $capacity - $taken );
	}

	/**
	 * Get total confirmed registrations across all events in a given category.
	 *
	 * @param int $term_id  Term ID of the cem_event_category.
	 * @return int
	 */
	public static function get_category_registration_count( $term_id ) {
		global $wpdb;
		$event_ids = get_objects_in_term( (int) $term_id, 'cem_event_category' );
		if ( empty( $event_ids ) || is_wp_error( $event_ids ) ) return 0;

		$event_ids  = array_map( 'intval', $event_ids );
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(num_attendees),0)
				 FROM {$wpdb->prefix}cem_registrations
				 WHERE event_id IN ($placeholders)
				 AND status IN ('confirmed','checked_in')",
				$event_ids
			)
		);
	}

	/**
	 * Check if adding $num_attendees to any category this event belongs to would
	 * exceed that category's cap.
	 *
	 * @param int $event_id
	 * @param int $num_attendees  Number being added in this registration.
	 * @return string|false  Error message string if capped, false if OK.
	 */
	public static function check_category_cap( $event_id, $num_attendees = 1 ) {
		$terms = get_the_terms( $event_id, 'cem_event_category' );
		if ( empty( $terms ) || is_wp_error( $terms ) ) return false;

		foreach ( $terms as $term ) {
			$cap = (int) get_term_meta( $term->term_id, '_cem_cat_cap', true );
			if ( $cap <= 0 ) continue; // no cap set for this category

			$taken = self::get_category_registration_count( $term->term_id );
			if ( ( $taken + $num_attendees ) > $cap ) {
				return sprintf(
					/* translators: %1$s = category name, %2$d = spots remaining */
					_n(
						'Registration limit reached for the "%1$s" category (%2$d spot remaining).',
						'Registration limit reached for the "%1$s" category (%2$d spots remaining).',
						max( 0, $cap - $taken ),
						'church-event-manager'
					),
					esc_html( $term->name ),
					max( 0, $cap - $taken )
				);
			}
		}
		return false;
	}

	/** Build a download-ready CSV string from array of arrays. */
	public static function array_to_csv( array $data ) {
		if ( empty( $data ) ) return '';
		ob_start();
		$out = fopen( 'php://output', 'w' );
		foreach ( $data as $i => $row ) {
			if ( $i === 0 ) {
				fputcsv( $out, array_keys( $row ) );
			}
			fputcsv( $out, $row );
		}
		fclose( $out );
		return ob_get_clean();
	}

	/** Return a list of countries for dropdowns. */
	public static function get_countries() {
		return [
			'US' => 'United States',
			'CA' => 'Canada',
			'GB' => 'United Kingdom',
			'AU' => 'Australia',
			// … add more as needed
		];
	}

	/** Return the cancellation deadline for a registration (datetime string or false). */
	public static function get_cancellation_deadline( $event_id ) {
		if ( ! get_option( 'cem_allow_cancellations' ) ) return false;
		$start   = self::get_event_meta( $event_id, 'start_datetime' );
		$days    = (int) get_option( 'cem_cancellation_days_before', 2 );
		if ( ! $start ) return false;
		return date( 'Y-m-d H:i:s', strtotime( $start ) - ( $days * DAY_IN_SECONDS ) );
	}

	/** Replace template variables in a string. */
	public static function parse_template( $template, array $vars ) {
		foreach ( $vars as $key => $value ) {
			// Coerce to string. PHP 8.1+ throws TypeError if str_replace's
			// $replace is not a string — and several template vars are arrays
			// (calendar_links) or booleans (checkin_enabled) that templates
			// reference via PHP, not via {placeholder} substitution. Skip
			// non-scalars entirely and stringify scalars defensively.
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			if ( is_bool( $value ) ) {
				$value = $value ? '1' : '';
			} elseif ( $value === null ) {
				$value = '';
			} else {
				$value = (string) $value;
			}
			$template = str_replace( '{' . $key . '}', $value, $template );
		}
		return $template;
	}

	/**
	 * Return a URL for managing a registration.
	 *
	 * The registration_code is itself a cryptographically random, unique token
	 * (generated via md5(uniqid(mt_rand(),true))). We do NOT include a WordPress
	 * nonce here because nonces expire in 24-48 hours and are tied to the
	 * user's login session — both of which cause "invalid link" errors for
	 * typical church registrants who are not logged in to WordPress.
	 *
	 * CSRF protection for the destructive cancel action is handled by embedding
	 * a *fresh* nonce in the cancel button at page-render time (see
	 * CEM_Shortcodes::render_manage_registration()).
	 */
	/**
	 * Is check-in enabled for this event?
	 *
	 * Gates the QR code in confirmation emails and on the manage page,
	 * the event's presence in the Check-In screen dropdown, and the
	 * walk-in registration endpoint. Opt-in: missing/empty meta = OFF.
	 *
	 * Always returns false for non-event post types (e.g. groups, where
	 * check-in is conceptually different and not exposed today).
	 */
	public static function is_checkin_enabled( $event_id ) {
		if ( ! $event_id ) return false;
		if ( get_post_type( $event_id ) !== 'cem_event' ) return false;
		return get_post_meta( $event_id, '_cem_checkin_enabled', true ) === '1';
	}

	public static function get_manage_url( $registration_code ) {
		$page_id = get_option( 'cem_my_registrations_page_id' );
		$base    = $page_id ? get_permalink( $page_id ) : home_url( '/my-registrations/' );
		return add_query_arg( [
			'cem_code' => urlencode( $registration_code ),
		], $base );
	}

	/**
	 * Build "Save to Calendar" deep-links for a given event.
	 *
	 * Returns an array with three keys, or an empty array if the event has
	 * no start datetime (in which case there's nothing useful to add).
	 *
	 *   - google : Google Calendar quick-add URL (works on mobile + desktop)
	 *   - outlook: Outlook Web quick-add URL
	 *   - ics    : Direct download of the .ics file served by
	 *              CEM_Public::handle_ical_download (Apple Calendar /
	 *              iCal / Outlook desktop / Thunderbird / anything that
	 *              speaks RFC 5545)
	 *
	 * Times are sent in UTC because all three providers normalize to the
	 * viewer's local timezone on import.
	 *
	 * @param int $event_id
	 * @return array
	 */
	public static function get_calendar_links( $event_id ) {
		$event_id = (int) $event_id;
		if ( ! $event_id || get_post_type( $event_id ) !== 'cem_event' ) {
			return [];
		}

		$start_dt = get_post_meta( $event_id, '_cem_start_datetime', true );
		if ( ! $start_dt ) return [];

		$end_dt    = get_post_meta( $event_id, '_cem_end_datetime', true );
		$start_ts  = strtotime( $start_dt );
		$end_ts    = $end_dt ? strtotime( $end_dt ) : ( $start_ts + 3600 );

		$title       = get_the_title( $event_id );
		// Build description WITHOUT touching get_the_excerpt() — that triggers
		// the WP filter chain (strip_shortcodes → excerpt_remove_blocks →
		// wp_trim_words) which can silently throw on sites running Elementor
		// or other page builders. A failure here used to take down the entire
		// email-send pipeline because this helper was called from inside
		// get_template_vars(). Use the raw post_content directly instead.
		$post        = get_post( $event_id );
		$raw_content = $post ? $post->post_content : '';
		$description = wp_trim_words( wp_strip_all_tags( strip_shortcodes( $raw_content ) ), 30 );
		$url         = get_permalink( $event_id );
		$location    = trim( implode( ', ', array_filter( [
			get_post_meta( $event_id, '_cem_location', true ),
			get_post_meta( $event_id, '_cem_location_address', true ),
		] ) ) );

		// Google + Outlook accept their own date formats but both accept
		// ISO-style UTC timestamps. We use the format each one prefers
		// for maximum compatibility with their inbound parsers.
		$gcal_dates = gmdate( 'Ymd\THis\Z', $start_ts ) . '/' . gmdate( 'Ymd\THis\Z', $end_ts );
		$outlook_st = gmdate( 'Y-m-d\TH:i:s\Z', $start_ts );
		$outlook_et = gmdate( 'Y-m-d\TH:i:s\Z', $end_ts );

		$details_text = trim( $description . ( $url ? "\n\n" . $url : '' ) );

		$google = add_query_arg( array_filter( [
			'action'   => 'TEMPLATE',
			'text'     => $title,
			'dates'    => $gcal_dates,
			'details'  => $details_text,
			'location' => $location,
		] ), 'https://calendar.google.com/calendar/render' );

		$outlook = add_query_arg( array_filter( [
			'path'     => '/calendar/action/compose',
			'rru'      => 'addevent',
			'subject'  => $title,
			'startdt'  => $outlook_st,
			'enddt'    => $outlook_et,
			'body'     => $details_text,
			'location' => $location,
		] ), 'https://outlook.live.com/calendar/0/deeplink/compose' );

		$ics = add_query_arg( [
			'cem_ical' => 1,
			'event_id' => $event_id,
		], home_url( '/' ) );

		return [
			'google'  => $google,
			'outlook' => $outlook,
			'ics'     => $ics,
		];
	}
}
