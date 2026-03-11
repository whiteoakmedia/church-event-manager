<?php
/**
 * Public-facing hooks: scripts, styles, single event template, and iCal download.
 *
 * @package ChurchEventManager
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Public {

	/**
	 * True when the plugin's own templates/single-event.php is the FINAL
	 * template being served (i.e. no theme builder has overridden it).
	 * Set directly inside single_event_template() at priority 99.
	 *
	 * @var bool
	 */
	private $using_plugin_template = false;

	public function init() {
		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_event_template_assets' ] );
		add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_group_template_assets' ] );
		add_filter( 'the_content',         [ $this, 'append_registration_form' ] );

		/*
		 * Single hook at priority 99 — intentionally AFTER Elementor Pro (12),
		 * CMSMasters theme builder, and any other builder that runs at ≤ 50.
		 *
		 * WHY HIGH PRIORITY INSTEAD OF LOW?
		 *
		 * Many theme builders (including CMSMasters) select their canvas template
		 * via the 'single_template' filter, which runs INSIDE WordPress's template
		 * hierarchy resolution — BEFORE template_include ever fires.  When the
		 * builder's canvas path arrives at template_include, a low-priority hook
		 * (e.g. priority 5) would overwrite it with the plugin template, silently
		 * discarding the builder's work.  The user's CMSMasters edits were being
		 * thrown away for exactly this reason.
		 *
		 * By running at priority 99 we see the FINAL resolved template:
		 *   • Generic fallback (single.php / index.php) → no builder claimed the
		 *     page; we apply templates/single-event.php as a polished fallback.
		 *   • Non-generic path (builder's canvas.php, etc.) → a builder or bespoke
		 *     theme template is active; we leave it completely untouched so the
		 *     user's CMSMasters template edits take effect.
		 */
		add_filter( 'template_include',    [ $this, 'single_event_template' ],  99  );
		add_action( 'template_redirect',   [ $this, 'handle_ical_download' ] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Enqueue: global public assets (events list, calendar, my-registrations, etc.)
	// ────────────────────────────────────────────────────────────────────────────
	public function enqueue_assets() {
		wp_enqueue_style(
			'cem-public',
			CEM_PLUGIN_URL . 'public/css/cem-public.css',
			[],
			CEM_VERSION
		);

		// Inject the admin-configured accent colour as a CSS custom property so
		// every rule that references var(--cem-accent) picks it up automatically.
		$accent = sanitize_hex_color( get_option( 'cem_accent_color', '#3b5998' ) ) ?: '#3b5998';
		wp_add_inline_style( 'cem-public', ':root { --cem-accent: ' . $accent . '; }' );

		wp_enqueue_script(
			'cem-public',
			CEM_PLUGIN_URL . 'public/js/cem-public.js',
			[ 'jquery' ],
			CEM_VERSION,
			true
		);

		wp_localize_script( 'cem-public', 'cemPublic', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cem_public_nonce' ),
			'strings' => [
				'submitting'   => __( 'Submitting…', 'church-event-manager' ),
				'error'        => __( 'An error occurred. Please try again.', 'church-event-manager' ),
				'confirmCancel'=> __( 'Are you sure you want to cancel your registration?', 'church-event-manager' ),
			],
		] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Enqueue: single event template stylesheet + Stripe (only on cem_event pages)
	// ────────────────────────────────────────────────────────────────────────────
	public function enqueue_event_template_assets() {
		if ( ! is_singular( 'cem_event' ) ) {
			return;
		}

		wp_enqueue_style(
			'cem-single-event',
			CEM_PLUGIN_URL . 'templates/single-event.css',
			[ 'cem-public' ],
			CEM_VERSION
		);

		// single-event.css defines its own --cem-accent / --cem-accent-dark in
		// a :root block (pointing at Elementor's global token).  We must inject
		// the admin-configured colour AFTER that stylesheet so our rule wins the
		// cascade.  wp_add_inline_style outputs a <style> block immediately
		// after the <link> for 'cem-single-event', which appears later in the
		// document than single-event.css's own :root declarations.
		$accent      = sanitize_hex_color( get_option( 'cem_accent_color', '#3b5998' ) ) ?: '#3b5998';
		$accent_dark = self::darken_hex_color( $accent, 25 );
		wp_add_inline_style(
			'cem-single-event',
			':root { --cem-accent: ' . $accent . '; --cem-accent-dark: ' . $accent_dark . '; }'
		);

		// Load Stripe for the built-in single-event template.
		self::enqueue_stripe_for_event( get_the_ID() );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Enqueue: single group template stylesheet (only on cem_group pages)
	// ────────────────────────────────────────────────────────────────────────────
	public function enqueue_group_template_assets() {
		if ( ! is_singular( 'cem_group' ) ) {
			return;
		}

		wp_enqueue_style(
			'cem-single-group',
			CEM_PLUGIN_URL . 'templates/single-group.css',
			[ 'cem-public' ],
			CEM_VERSION
		);

		$accent      = sanitize_hex_color( get_option( 'cem_accent_color', '#3b5998' ) ) ?: '#3b5998';
		$accent_dark = self::darken_hex_color( $accent, 25 );
		wp_add_inline_style(
			'cem-single-group',
			':root { --cem-accent: ' . $accent . '; --cem-accent-dark: ' . $accent_dark . '; }'
		);
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Static helper: enqueue Stripe.js + cem-stripe.js for a specific event.
	//
	// Safe to call multiple times (wp_enqueue_script deduplicates by handle).
	// Called from enqueue_event_template_assets() for the built-in single-event
	// template AND from CEM_Shortcodes::registration_form() so shortcodes on
	// arbitrary pages (not just is_singular('cem_event')) also get Stripe loaded.
	// ────────────────────────────────────────────────────────────────────────────
	public static function enqueue_stripe_for_event( $event_id ) {
		$event_price    = get_post_meta( $event_id, '_cem_price', true );
		$price_num      = ( $event_price !== '' ) ? (float) $event_price : 0.0;
		$stripe_enabled = get_option( 'cem_stripe_enabled', '0' ) === '1';
		$stripe_pub_key = get_option( 'cem_stripe_publishable_key', '' );

		if ( ! ( $price_num > 0 && $stripe_enabled && ! empty( $stripe_pub_key ) ) ) {
			return; // No payment needed or Stripe not configured.
		}

		// Stripe.js must be loaded from Stripe's CDN — do NOT self-host.
		wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );

		wp_enqueue_script(
			'cem-stripe',
			CEM_PLUGIN_URL . 'public/js/cem-stripe.js',
			[ 'jquery', 'stripe-js', 'cem-public' ],
			CEM_VERSION,
			true
		);

		$currency_symbol = get_option( 'cem_currency_symbol', '$' );

		wp_localize_script( 'cem-stripe', 'cemStripe', [
			'publishableKey' => $stripe_pub_key,
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'cem_payment_nonce' ),
			'eventId'        => $event_id,
			'amountCents'    => (int) round( $price_num * 100 ),
			'currency'       => strtolower( get_option( 'cem_stripe_currency', 'usd' ) ),
			'priceDisplay'   => $currency_symbol . number_format( $price_num, 2 ),
			'strings'        => [
				'processing'  => __( 'Processing payment…', 'church-event-manager' ),
				'payButton'   => sprintf(
					/* translators: %s: formatted price */
					__( 'Pay %s & Register', 'church-event-manager' ),
					$currency_symbol . number_format( $price_num, 2 )
				),
				'registerBtn' => __( 'Register Now', 'church-event-manager' ),
				'error'       => __( 'Payment failed. Please check your card details and try again.', 'church-event-manager' ),
				'loadError'   => __( 'Could not load payment form. Please refresh the page.', 'church-event-manager' ),
			],
		] );
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Filter: append registration form after the_content on cem_event pages.
	//
	// Behaviour matrix:
	//   Plugin template active  → bail (form is already embedded in the template)
	//   CMSMasters/Elementor TB → bail (user places [cem_registration_form] in
	//                             the visual builder themselves)
	//   Theme single template   → append when ?register=1 is present in the URL
	//                             (legacy fallback for bespoke themes)
	// ────────────────────────────────────────────────────────────────────────────
	public function append_registration_form( $content ) {
		if ( ! is_singular( 'cem_event' ) || ! in_the_loop() ) {
			return $content;
		}

		// Plugin template already embeds the form — bail to prevent duplication.
		// $using_plugin_template is set reliably by track_active_template() after
		// ALL template_include filters (including theme builders) have run.
		if ( $this->using_plugin_template ) {
			return $content;
		}

		// A theme builder (Elementor / CMSMasters) is rendering this page.
		// The user controls the layout visually; they can drop in the shortcode
		// [cem_registration_form event_id="X"] wherever they like.  We must NOT
		// auto-append here or the form could appear in an unexpected location
		// within the builder's canvas structure.
		//
		// Detection: if $using_plugin_template is false AND no theme-level file
		// (single-cem_event.php) exists, a builder is in charge.
		$theme_file = locate_template( [ 'single-cem_event.php' ] );
		if ( ! $theme_file ) {
			return $content; // Builder is active — hands off.
		}

		// Legacy fallback for hand-coded theme templates: append on ?register=1.
		if ( empty( $_GET['register'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $content;
		}

		$shortcode_output = do_shortcode( '[cem_registration_form event_id="' . get_the_ID() . '"]' );
		return $content . '<div class="cem-inline-form">' . $shortcode_output . '</div>';
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Filter (template_include, priority 99):
	//
	//   Apply the plugin's built-in single event template ONLY when no theme
	//   builder or bespoke theme file has already claimed the page.
	//
	//   Detection strategy:
	//     • WordPress's template hierarchy resolves to a specific file before
	//       template_include fires.  If a builder (CMSMasters, Elementor, etc.)
	//       ran its own hook (often via 'single_template') it will have replaced
	//       the generic WP fallback with its own canvas path.
	//     • We compare wp_basename($template) against known WP generic fallbacks.
	//       A non-generic basename means a builder or theme template is active —
	//       we leave it completely alone so builder edits take effect.
	//     • A generic basename (single.php, index.php, …) means nobody claimed
	//       the page; we step in with our polished plugin template.
	//
	//   $using_plugin_template is set here (not by a separate observer) because
	//   we are already running after all builders, so our decision IS the final
	//   state.
	// ────────────────────────────────────────────────────────────────────────────
	public function single_event_template( $template ) {
		if ( ! is_singular( 'cem_event' ) ) {
			return $template;
		}

		// 1. Honour a hand-crafted theme override (single-cem_event.php in theme).
		$theme_template = locate_template( [ 'single-cem_event.php' ] );
		if ( $theme_template ) {
			$this->using_plugin_template = false;
			return $theme_template;
		}

		// 2. Check whether a builder/theme already resolved to a specific file.
		//    Generic WP fallbacks mean no builder is active for this post type.
		$basename        = wp_basename( $template );
		$wp_fallbacks    = [ 'single.php', 'singular.php', 'index.php', 'page.php', '' ];
		$builder_is_active = $basename && ! in_array( $basename, $wp_fallbacks, true );

		if ( $builder_is_active ) {
			// A theme builder (CMSMasters, Elementor, etc.) already set its canvas
			// template.  Leave it completely untouched — the user's builder edits
			// will now take effect.
			$this->using_plugin_template = false;
			return $template;
		}

		// 3. No builder template found → serve the plugin's built-in template.
		$plugin_template = CEM_PLUGIN_DIR . 'templates/single-event.php';
		if ( file_exists( $plugin_template ) ) {
			$this->using_plugin_template = true;
			return $plugin_template;
		}

		$this->using_plugin_template = false;
		return $template;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// iCal Download  — handles ?cem_ical=1&event_id=X
	//
	// Generates a standards-compliant .ics file so attendees can import the event
	// directly into Apple Calendar, Outlook, Google Calendar, etc.
	// ────────────────────────────────────────────────────────────────────────────
	public function handle_ical_download() {
		// phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $_GET['cem_ical'] ) || empty( $_GET['event_id'] ) ) {
			return;
		}

		$event_id = absint( $_GET['event_id'] ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( ! $event_id || get_post_type( $event_id ) !== 'cem_event' || get_post_status( $event_id ) !== 'publish' ) {
			wp_die( esc_html__( 'Event not found.', 'church-event-manager' ), 404 );
		}

		$title       = get_the_title( $event_id );
		$description = wp_strip_all_tags( get_the_excerpt( $event_id ) );
		$url         = get_permalink( $event_id );
		$location    = get_post_meta( $event_id, '_cem_location', true );
		$address     = get_post_meta( $event_id, '_cem_address', true );
		$start_dt    = get_post_meta( $event_id, '_cem_start_datetime', true );
		$end_dt      = get_post_meta( $event_id, '_cem_end_datetime', true );
		$organizer   = get_post_meta( $event_id, '_cem_organizer', true );
		$church_name = get_option( 'cem_church_name', get_bloginfo( 'name' ) );
		$admin_email = get_option( 'admin_email' );

		if ( ! $start_dt ) {
			wp_die( esc_html__( 'This event has no start date/time set.', 'church-event-manager' ), 400 );
		}

		$dtstart = gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) );
		$dtend   = $end_dt
			? gmdate( 'Ymd\THis\Z', strtotime( $end_dt ) )
			: gmdate( 'Ymd\THis\Z', strtotime( $start_dt ) + 3600 );
		$dtstamp = gmdate( 'Ymd\THis\Z' );
		$uid     = 'cem-' . $event_id . '-' . $dtstamp . '@' . wp_parse_url( home_url(), PHP_URL_HOST );

		$venue_location = trim( implode( ', ', array_filter( [ $location, $address ] ) ) );

		// Fold long lines to comply with RFC 5545 (max 75 octets per line)
		$fold = function ( $prefix, $value ) {
			$line    = $prefix . $value;
			$wrapped = '';
			while ( mb_strlen( $line, 'UTF-8' ) > 75 ) {
				$wrapped .= mb_substr( $line, 0, 75, 'UTF-8' ) . "\r\n ";
				$line     = mb_substr( $line, 75, null, 'UTF-8' );
			}
			return $wrapped . $line;
		};

		// Escape special chars per RFC 5545
		$esc = fn( $s ) => str_replace( [ '\\', ';', ',', "\n" ], [ '\\\\', '\;', '\,', '\n' ], $s );

		$ics_lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Church Event Manager//WordPress Plugin//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			"UID:{$uid}",
			"DTSTAMP:{$dtstamp}",
			"DTSTART:{$dtstart}",
			"DTEND:{$dtend}",
			$fold( 'SUMMARY:', $esc( $title ) ),
			$fold( 'DESCRIPTION:', $esc( $description ) ),
			$fold( 'URL:', $url ),
		];

		if ( $venue_location ) {
			$ics_lines[] = $fold( 'LOCATION:', $esc( $venue_location ) );
		}

		$organizer_name  = $organizer ?: $church_name;
		$ics_lines[] = "ORGANIZER;CN=\"{$esc($organizer_name)}\":mailto:{$admin_email}";

		$ics_lines[] = 'STATUS:CONFIRMED';
		$ics_lines[] = 'END:VEVENT';
		$ics_lines[] = 'END:VCALENDAR';

		$filename = sanitize_file_name( $title . '.ics' );

		// Send headers and content.
		header( 'Content-Type: text/calendar; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		echo implode( "\r\n", $ics_lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput

		exit;
	}

	// ────────────────────────────────────────────────────────────────────────────
	// Helper: darken a #rrggbb hex colour by subtracting a fixed amount from each
	// channel (clamped to 0).  Used to derive --cem-accent-dark at runtime so the
	// dark variant stays in the same hue family as the admin-chosen accent.
	// ────────────────────────────────────────────────────────────────────────────
	private static function darken_hex_color( $hex, $amount = 25 ) {
		$hex = ltrim( $hex, '#' );
		// Expand 3-char shorthand to 6 chars.
		if ( strlen( $hex ) === 3 ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$r = max( 0, hexdec( substr( $hex, 0, 2 ) ) - $amount );
		$g = max( 0, hexdec( substr( $hex, 2, 2 ) ) - $amount );
		$b = max( 0, hexdec( substr( $hex, 4, 2 ) ) - $amount );
		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}
}
