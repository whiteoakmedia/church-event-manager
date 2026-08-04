<?php
/**
 * Spam protection for the public registration form.
 *
 * The form previously had only a WordPress nonce, and the endpoint that mints
 * those nonces is public by necessity (see CEM_Public::rest_serve_nonce) — so a
 * script could fetch a fresh nonce and POST registrations indefinitely. That is
 * exactly what happened in the wild: hundreds of sign-ups with random names,
 * random notes, and Gmail dot-variant addresses used to slip past the
 * duplicate-email check, each one consuming real event capacity.
 *
 * Layers, cheapest first:
 *   1. Honeypot         – a field humans never see and bots fill in
 *   2. Submit timing    – a token written by our JS at submit time
 *   3. Gibberish        – the signature of a bot filling every field at random
 *   4. Per-IP rate cap  – blunt volume limit, catches whatever the rest misses
 *   5. Turnstile        – optional; the definitive fix for a determined bot
 *
 * DESIGN RULE: every check fails OPEN. A misconfigured key, a network blip, or
 * an exception inside a check must never stop a real person registering — the
 * cost of a missed spam row is a row to delete, the cost of a false positive is
 * a member who silently cannot sign up and does not tell anyone.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Antispam {

	/**
	 * Hidden field name.
	 *
	 * Deliberately meaningless rather than tempting ("website", "url"): browser
	 * autofill and password managers happily populate a lone field with a name
	 * they recognise, and a filled honeypot means a real member is turned away.
	 * Bots that fill every input in the form still trip it either way.
	 */
	const HONEYPOT_FIELD = 'cem_hp_check';

	/** Milliseconds between form render and submit, written by cem-public.js. */
	const TIMER_FIELD = 'cem_ft';

	const LOG_OPTION    = 'cem_spam_log';
	const LOG_MAX       = 50;
	const COUNT_OPTION  = 'cem_spam_blocked_count';

	public static function is_enabled() {
		return get_option( 'cem_antispam_enabled', '1' ) === '1';
	}

	/**
	 * Decide whether a submission is spam.
	 *
	 * @param array $post Raw $_POST.
	 * @return string|false Reason slug when blocked, false to allow.
	 */
	public static function check( array $post ) {
		if ( ! self::is_enabled() ) return false;

		// Never gate signed-in staff: they run walk-ins from the same endpoint
		// and are the people most likely to be testing the form.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) return false;

		try {
			// Count first, so an attacker's own rejected attempts push them
			// toward the rate cap instead of being free retries.
			$over_limit = self::record_attempt();

			$honeypot = trim( (string) ( $post[ self::HONEYPOT_FIELD ] ?? '' ) );
			if ( $honeypot !== '' ) return 'honeypot';

			$too_fast = self::submitted_too_fast( $post );
			if ( $too_fast ) return $too_fast;

			$gibberish = self::looks_like_gibberish( $post );
			if ( $gibberish ) return $gibberish;

			if ( $over_limit ) return 'rate_limit';

			$turnstile = self::verify_turnstile( $post );
			if ( $turnstile ) return $turnstile;

		} catch ( \Throwable $e ) {
			// A broken check must not become an outage.
			if ( class_exists( 'CEM_Error_Reporter' ) ) {
				CEM_Error_Reporter::report_exception( $e, 'CEM_Antispam::check' );
			}
			return false;
		}

		return false;
	}

	/** Generic message shown to a blocked submission — never says which check fired. */
	public static function rejection_message() {
		return __( "We couldn't process that sign-up. Please reload the page and try again — if it keeps happening, get in touch and we'll register you directly.", 'church-event-manager' );
	}

	// ── Layer 2: submit timing ────────────────────────────────────────────────

	/**
	 * Nobody types a name, email and phone in under a couple of seconds.
	 *
	 * The token is written by our JavaScript at submit time rather than baked
	 * into the page HTML, so a CDN serving a cached page can't poison it. A
	 * MISSING token is treated as allowed, not blocked: if stale cached HTML
	 * ever pointed at an older script, blocking would take the whole form
	 * offline. Direct POSTs that skip our JS are caught by the rate cap.
	 */
	private static function submitted_too_fast( array $post ) {
		$min_seconds = (int) get_option( 'cem_antispam_min_seconds', 3 );
		if ( $min_seconds <= 0 ) return false;
		if ( ! isset( $post[ self::TIMER_FIELD ] ) ) return false;

		$elapsed_ms = (int) $post[ self::TIMER_FIELD ];
		// 0 or negative means the script couldn't measure it; a value over a day
		// means a tab left open. Neither is evidence of anything.
		if ( $elapsed_ms <= 0 || $elapsed_ms > DAY_IN_SECONDS * 1000 ) return false;

		return ( $elapsed_ms < $min_seconds * 1000 ) ? 'too_fast' : false;
	}

	// ── Layer 3: gibberish heuristics ─────────────────────────────────────────

	/**
	 * Spot the signature of a bot that fills every field with random characters.
	 *
	 * Both rules are deliberately narrow. Real names are wildly varied across
	 * languages and a false positive here means a member who cannot register, so
	 * these only fire on patterns that human names essentially never produce.
	 */
	private static function looks_like_gibberish( array $post ) {
		return self::score_record(
			(string) ( $post['first_name'] ?? '' ),
			(string) ( $post['last_name'] ?? '' ),
			(string) ( $post['notes'] ?? '' )
		);
	}

	/**
	 * The gibberish test, in a form that works on a stored row as well as on a
	 * live submission — so the "likely spam" cleanup filter and the live gate
	 * can never disagree about what counts as spam.
	 *
	 * @return string|false Reason slug, or false if it looks human.
	 */
	public static function score_record( $first_name, $last_name, $notes = '' ) {
		foreach ( [ $first_name, $last_name ] as $value ) {
			$value = trim( (string) $value );
			if ( $value === '' ) continue;

			foreach ( preg_split( '/[\s\-\']+/', $value ) as $word ) {
				$word = preg_replace( '/[^A-Za-z]/', '', $word );
				if ( strlen( $word ) < 8 ) continue;

				// Case flipping mid-word: "UGWqQBuqErlieEKGrSoM" flips four
				// times. Real names manage one ("McDonald", "DeAnna"), and
				// all-caps or all-lowercase names never flip at all.
				if ( preg_match_all( '/[a-z][A-Z]/', $word ) >= 3 ) {
					return 'gibberish_name';
				}

				// A long run of letters with no vowel at all isn't a name.
				if ( ! preg_match( '/[aeiouy]/i', $word ) ) {
					return 'gibberish_name';
				}
			}
		}

		// A long unbroken mixed-case alphanumeric string in the notes box is a
		// bot filling a field it doesn't understand, e.g. "YWcvVSeTWeKcmzxLkJNcXZ".
		$notes = trim( (string) $notes );
		if ( strlen( $notes ) >= 12
			&& ! preg_match( '/\s/', $notes )
			&& preg_match( '/^[A-Za-z0-9]+$/', $notes )
			&& preg_match_all( '/[a-z][A-Z]/', $notes ) >= 2 ) {
			return 'gibberish_notes';
		}

		return false;
	}

	/**
	 * Registration IDs that look like the spam already sitting in the table.
	 *
	 * Scans in PHP rather than SQL because the test is a pattern over the whole
	 * name, which SQL can't express. Bounded by $limit so a huge table can't
	 * exhaust memory; the default covers a very large clean-up.
	 *
	 * @return int[]
	 */
	public static function find_suspicious_ids( $event_id = 0, $limit = 5000 ) {
		global $wpdb;

		$sql    = "SELECT id, first_name, last_name, notes FROM {$wpdb->prefix}cem_registrations";
		$params = [];
		if ( $event_id > 0 ) {
			$sql     .= " WHERE event_id = %d";
			$params[] = (int) $event_id;
		}
		$sql     .= " ORDER BY id DESC LIMIT %d";
		$params[] = max( 1, (int) $limit );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		$ids = [];
		foreach ( (array) $rows as $row ) {
			if ( self::score_record( $row->first_name, $row->last_name, (string) $row->notes ) ) {
				$ids[] = (int) $row->id;
			}
		}
		return $ids;
	}

	// ── Layer 4: per-IP rate cap ──────────────────────────────────────────────

	/**
	 * Record this attempt and report whether the caller is over the cap.
	 *
	 * Defaults are deliberately loose. A church running a sign-up table on one
	 * office wifi shares a single public IP, so a tight cap would lock out real
	 * members; the aim here is only to stop automated volume.
	 */
	private static function record_attempt() {
		$max = (int) get_option( 'cem_antispam_max_per_window', 8 );
		if ( $max <= 0 ) return false;

		$ip = self::client_ip();
		if ( ! $ip ) return false;

		$window  = max( 1, (int) get_option( 'cem_antispam_window_minutes', 10 ) );
		$key     = 'cem_rl_' . md5( $ip );
		$hits    = (int) get_transient( $key ) + 1;

		// Re-setting the transient extends the window, so a bot that keeps
		// hammering keeps its own lockout alive.
		set_transient( $key, $hits, $window * MINUTE_IN_SECONDS );

		return $hits > $max;
	}

	/**
	 * Best-effort visitor IP.
	 *
	 * Proxy headers are spoofable, but spoofing one only splits the attacker's
	 * own allowance across more buckets — it can't raise anyone else's.
	 */
	public static function client_ip() {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) continue;
			$ip = trim( explode( ',', (string) $_SERVER[ $key ] )[0] );
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
		}
		return '';
	}

	// ── Layer 5: Cloudflare Turnstile (optional) ──────────────────────────────

	public static function turnstile_site_key() {
		return trim( (string) get_option( 'cem_turnstile_site_key', '' ) );
	}

	/** Turnstile only counts as configured when BOTH keys are present. */
	public static function turnstile_active() {
		return self::turnstile_site_key() !== ''
			&& trim( (string) get_option( 'cem_turnstile_secret_key', '' ) ) !== '';
	}

	private static function verify_turnstile( array $post ) {
		if ( ! self::turnstile_active() ) return false;

		$token = (string) ( $post['cf-turnstile-response'] ?? '' );
		if ( $token === '' ) return 'turnstile_missing';

		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => trim( (string) get_option( 'cem_turnstile_secret_key', '' ) ),
				'response' => $token,
				'remoteip' => self::client_ip(),
			],
		] );

		// Cloudflare unreachable — allow the sign-up rather than block everyone
		// while their API or our outbound network is having a bad day.
		if ( is_wp_error( $response ) ) return false;

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) return false;

		return empty( $body['success'] ) ? 'turnstile_failed' : false;
	}

	// ── Email normalization ───────────────────────────────────────────────────

	/**
	 * Fold an address to the mailbox it actually reaches.
	 *
	 * Gmail ignores dots and everything after a "+" in the local part, so
	 * h.on.gv.o7.81@gmail.com and hongvo781@gmail.com are one inbox. Treating
	 * them as different people is what let a single spammer register hundreds
	 * of times for the same event.
	 */
	public static function normalize_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		if ( $email === '' || strpos( $email, '@' ) === false ) return $email;

		list( $local, $domain ) = explode( '@', $email, 2 );

		// Sub-addressing ("+tag") is honoured well beyond Gmail.
		$plus = strpos( $local, '+' );
		if ( $plus !== false ) $local = substr( $local, 0, $plus );

		if ( in_array( $domain, [ 'gmail.com', 'googlemail.com' ], true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}

		return $local . '@' . $domain;
	}

	// ── Blocked-attempt log ───────────────────────────────────────────────────

	/**
	 * Keep the last few blocked attempts so a false positive is discoverable.
	 *
	 * Without this, a heuristic that wrongly rejects someone is invisible: the
	 * member just gives up quietly. Reviewing this list is how we'd find out.
	 */
	public static function log_blocked( $reason, array $post ) {
		try {
			$log = get_option( self::LOG_OPTION, [] );
			if ( ! is_array( $log ) ) $log = [];

			array_unshift( $log, [
				'time'   => current_time( 'mysql' ),
				'reason' => (string) $reason,
				'name'   => mb_substr( trim( ( $post['first_name'] ?? '' ) . ' ' . ( $post['last_name'] ?? '' ) ), 0, 80 ),
				'email'  => mb_substr( (string) ( $post['email'] ?? '' ), 0, 120 ),
				'ip'     => self::client_ip(),
				'event'  => (int) ( $post['event_id'] ?? 0 ),
			] );

			update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_MAX ), false );
			update_option( self::COUNT_OPTION, (int) get_option( self::COUNT_OPTION, 0 ) + 1, false );
		} catch ( \Throwable $e ) {
			// Logging must never affect the response.
		}
	}

	public static function get_log() {
		$log = get_option( self::LOG_OPTION, [] );
		return is_array( $log ) ? $log : [];
	}

	public static function blocked_count() {
		return (int) get_option( self::COUNT_OPTION, 0 );
	}

	public static function clear_log() {
		delete_option( self::LOG_OPTION );
	}

	/** Human-readable labels for the reason slugs recorded above. */
	public static function reason_labels() {
		return [
			'honeypot'         => __( 'Filled a hidden field',        'church-event-manager' ),
			'too_fast'         => __( 'Submitted too fast',           'church-event-manager' ),
			'gibberish_name'   => __( 'Random-looking name',          'church-event-manager' ),
			'gibberish_notes'  => __( 'Random-looking notes',          'church-event-manager' ),
			'rate_limit'       => __( 'Too many tries from one place', 'church-event-manager' ),
			'turnstile_missing'=> __( 'No Turnstile check',            'church-event-manager' ),
			'turnstile_failed' => __( 'Failed Turnstile check',        'church-event-manager' ),
		];
	}

	// ── Front-end markup ──────────────────────────────────────────────────────

	/**
	 * The honeypot input.
	 *
	 * Positioned off-screen rather than display:none — some bots skip anything
	 * with display:none, and screen readers are handled with aria-hidden plus
	 * tabindex so it stays out of the keyboard path.
	 */
	public static function render_honeypot() {
		printf(
			'<div class="cem-hp" aria-hidden="true"><label for="%1$s">%2$s</label>'
			. '<input type="text" id="%1$s" name="%1$s" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( self::HONEYPOT_FIELD ),
			esc_html__( 'Leave this field empty', 'church-event-manager' )
		);
	}

	/** Turnstile widget + script, only when both keys are configured. */
	public static function render_turnstile() {
		if ( ! self::turnstile_active() ) return;
		printf(
			'<div class="cem-turnstile cf-turnstile" data-sitekey="%s"></div>',
			esc_attr( self::turnstile_site_key() )
		);
		wp_enqueue_script(
			'cloudflare-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js',
			[],
			null,
			true
		);
	}
}
