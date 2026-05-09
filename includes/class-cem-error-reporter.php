<?php
/**
 * Phones home to the White Oak Media client portal whenever this plugin
 * encounters a PHP error, warning, or fatal — so the agency can see
 * problems without the church needing to file a ticket.
 *
 * What it captures:
 *   - PHP errors / warnings / notices originating in plugin files
 *   - Fatal errors (via register_shutdown_function)
 *   - Exceptions caught from AJAX handlers (CEM_Ajax::report_exception)
 *
 * What it sends (only):
 *   - Site URL, client identifier, plugin version, WP version, PHP version
 *   - Error message, file (relative to plugin), line, type
 *   - Stack trace (when available)
 *   - Request URI of the failing request
 *
 * Throttling: identical errors (same file:line:message) are only reported
 * once per hour, stored in a transient, to avoid flooding the portal.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Error_Reporter {

	/** Default endpoint on the WOM client portal. */
	const DEFAULT_ENDPOINT = 'https://us-central1-white-oak-media-client-portal.cloudfunctions.net/reportPluginError';

	/** Throttle window per unique error signature (seconds). */
	const THROTTLE_SECONDS = 3600;

	/** PHP error levels we actually care about. */
	const REPORTABLE_LEVELS = E_ERROR | E_WARNING | E_PARSE | E_NOTICE
		| E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING
		| E_USER_ERROR | E_USER_WARNING | E_USER_NOTICE | E_RECOVERABLE_ERROR;

	private static $booted = false;

	/**
	 * Wire up error/shutdown handlers. Safe to call multiple times.
	 */
	public static function boot() {
		if ( self::$booted ) return;
		self::$booted = true;

		// Only run when reporting is enabled.
		if ( get_option( 'cem_error_reporting_enabled', '1' ) !== '1' ) {
			return;
		}

		// Chain on top of any previous handler so we don't kill other plugins'
		// error handling — we capture, then return false to let PHP keep going.
		set_error_handler( [ __CLASS__, 'handle_php_error' ], self::REPORTABLE_LEVELS );
		register_shutdown_function( [ __CLASS__, 'handle_shutdown' ] );
	}

	/**
	 * PHP error handler. We only act on errors raised from inside the plugin
	 * directory; everything else is left to the default handler.
	 */
	public static function handle_php_error( $errno, $errstr, $errfile, $errline ) {
		if ( ! self::is_plugin_file( $errfile ) ) {
			return false; // let PHP / other handlers process it
		}

		self::report( [
			'type'    => self::error_level_label( $errno ),
			'message' => (string) $errstr,
			'file'    => self::relative_path( $errfile ),
			'line'    => (int) $errline,
			'trace'   => self::short_trace(),
		] );

		// Returning false keeps the standard PHP error logger active too.
		return false;
	}

	/**
	 * Catches fatal errors that can't be intercepted by set_error_handler.
	 */
	public static function handle_shutdown() {
		$err = error_get_last();
		if ( ! $err ) return;

		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
		if ( ! in_array( $err['type'], $fatal_types, true ) ) return;
		if ( ! self::is_plugin_file( $err['file'] ) ) return;

		self::report( [
			'type'    => self::error_level_label( $err['type'] ),
			'message' => $err['message'],
			'file'    => self::relative_path( $err['file'] ),
			'line'    => (int) $err['line'],
			'trace'   => '',
			'fatal'   => true,
		] );
	}

	/**
	 * Public helper for try/catch blocks elsewhere in the plugin to log a
	 * caught exception (registration AJAX handler uses this).
	 */
	public static function report_exception( \Throwable $e, $context = '' ) {
		self::report( [
			'type'    => 'Exception',
			'message' => $e->getMessage() . ( $context ? " [$context]" : '' ),
			'file'    => self::relative_path( $e->getFile() ),
			'line'    => $e->getLine(),
			'trace'   => substr( $e->getTraceAsString(), 0, 4000 ),
		] );
	}

	/**
	 * Build the payload and POST it to the portal endpoint (non-blocking).
	 */
	private static function report( array $err ) {
		// Throttle by signature so a busy hook doesn't spam the portal.
		$signature  = md5( ( $err['file'] ?? '' ) . ':' . ( $err['line'] ?? '' ) . ':' . ( $err['message'] ?? '' ) );
		$throttle   = 'cem_err_' . $signature;
		if ( get_transient( $throttle ) ) {
			return;
		}
		set_transient( $throttle, 1, self::THROTTLE_SECONDS );

		$endpoint = get_option( 'cem_error_reporting_endpoint', self::DEFAULT_ENDPOINT );
		if ( ! $endpoint ) return;

		$payload = [
			'clientId'      => get_option( 'cem_client_id', '' ),
			'clientName'    => get_option( 'cem_client_name', get_option( 'blogname' ) ),
			'siteUrl'       => home_url(),
			'pluginVersion' => defined( 'CEM_VERSION' ) ? CEM_VERSION : '',
			'wpVersion'     => get_bloginfo( 'version' ),
			'phpVersion'    => PHP_VERSION,
			'requestUri'    => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'isAjax'        => defined( 'DOING_AJAX' ) && DOING_AJAX,
			'occurredAt'    => gmdate( 'c' ),
			'error'         => array_merge( [
				'type'    => 'Error',
				'message' => '',
				'file'    => '',
				'line'    => 0,
				'trace'   => '',
				'fatal'   => false,
			], $err ),
		];

		// Fire-and-forget. Never let reporting block or break the request.
		try {
			wp_remote_post( $endpoint, [
				'timeout'     => 1.5,
				'blocking'    => false,
				'redirection' => 0,
				'sslverify'   => true,
				'headers'     => [
					'Content-Type'  => 'application/json',
					'X-CEM-Plugin'  => defined( 'CEM_VERSION' ) ? CEM_VERSION : 'unknown',
				],
				'body' => wp_json_encode( $payload ),
			] );
		} catch ( \Throwable $e ) {
			// Last resort: write to PHP error log so we don't loop.
			error_log( 'CEM error reporter failed: ' . $e->getMessage() );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	private static function is_plugin_file( $path ) {
		if ( ! $path ) return false;
		$plugin_dir = wp_normalize_path( CEM_PLUGIN_DIR );
		return strpos( wp_normalize_path( $path ), $plugin_dir ) === 0;
	}

	private static function relative_path( $path ) {
		$plugin_dir = wp_normalize_path( CEM_PLUGIN_DIR );
		$path       = wp_normalize_path( $path );
		return ltrim( str_replace( $plugin_dir, '', $path ), '/' );
	}

	private static function short_trace() {
		// Skip the first two frames (this function + handle_php_error).
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 );
		$frames = array_slice( $frames, 2 );
		$lines  = [];
		foreach ( $frames as $f ) {
			$file = isset( $f['file'] ) ? self::relative_path( $f['file'] ) : '[internal]';
			$line = $f['line'] ?? '?';
			$call = ( $f['class'] ?? '' ) . ( $f['type'] ?? '' ) . ( $f['function'] ?? '' ) . '()';
			$lines[] = "{$file}:{$line} → {$call}";
		}
		return implode( "\n", $lines );
	}

	private static function error_level_label( $level ) {
		$map = [
			E_ERROR             => 'Error',
			E_WARNING           => 'Warning',
			E_PARSE             => 'Parse',
			E_NOTICE            => 'Notice',
			E_CORE_ERROR        => 'CoreError',
			E_CORE_WARNING      => 'CoreWarning',
			E_COMPILE_ERROR     => 'CompileError',
			E_COMPILE_WARNING   => 'CompileWarning',
			E_USER_ERROR        => 'UserError',
			E_USER_WARNING      => 'UserWarning',
			E_USER_NOTICE       => 'UserNotice',
			E_RECOVERABLE_ERROR => 'RecoverableError',
			E_DEPRECATED        => 'Deprecated',
			E_USER_DEPRECATED   => 'UserDeprecated',
		];
		return $map[ $level ] ?? ( 'Unknown(' . $level . ')' );
	}
}
