<?php
/**
 * QR-code generation + caching for registration codes.
 *
 * What we encode:
 *   The "manage registration" URL — same one delivered in the
 *   confirmation email. A registrant can scan their own QR with
 *   their phone camera anywhere and it opens their manage page;
 *   at check-in, the volunteer's scanner extracts `?cem_code=`
 *   from the same URL.
 *
 * Where the PNGs live:
 *   wp-content/uploads/cem-qr/{code}.png
 *
 *   Cached on first request. Code values are cryptographically
 *   random (CEM_Helpers::generate_code) so they're already
 *   filesystem-safe.
 *
 * Public URL:
 *   /wp-json/cem/v1/qr/{code}.png
 *
 *   Served by CEM_Public::register_qr_route(). Streams the cached
 *   PNG with long-lived cache headers.
 *
 * Privacy:
 *   The QR encodes a URL that itself contains the code. Anyone
 *   with the code already has full access to the registration —
 *   so the QR adds no new exposure surface.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_QR {

	const CACHE_SUBDIR = 'cem-qr';
	const PNG_SIZE     = 5; // chillerlan "scale" — 5 = ~145px square at the default version (4)

	/**
	 * Public URL where the browser / email client should fetch the QR.
	 * Generation is lazy — the PNG isn't created until something hits
	 * this URL.
	 */
	public static function get_url( $code ) {
		$code = self::sanitize_code( $code );
		if ( ! $code ) return '';
		return rest_url( 'cem/v1/qr/' . $code . '.png' );
	}

	/**
	 * Render the manage URL into a PNG, cache it, return the file path.
	 * Returns WP_Error on failure (autoloader missing, GD missing, write fail, etc.).
	 *
	 * @return string|WP_Error
	 */
	public static function generate_png( $code ) {
		$code = self::sanitize_code( $code );
		if ( ! $code ) {
			return new WP_Error( 'cem_qr_invalid_code', 'Invalid registration code.' );
		}

		$reg = CEM_Registration::get_by_code( $code );
		if ( ! $reg ) {
			return new WP_Error( 'cem_qr_not_found', 'Registration not found.' );
		}

		$path = self::cache_path( $code );
		if ( ! $path ) {
			return new WP_Error( 'cem_qr_no_dir', 'Cache directory unavailable.' );
		}

		// Already generated and registration not stale — serve it.
		if ( file_exists( $path ) ) {
			return $path;
		}

		// Lazy-load the bundled chillerlan library.
		if ( ! class_exists( 'chillerlan\\QRCode\\QRCode' ) ) {
			$autoload = CEM_PLUGIN_DIR . 'vendor/autoload.php';
			if ( file_exists( $autoload ) ) {
				require_once $autoload;
			}
		}
		if ( ! class_exists( 'chillerlan\\QRCode\\QRCode' ) ) {
			return new WP_Error( 'cem_qr_lib_missing', 'QR library not available.' );
		}

		$payload = CEM_Helpers::get_manage_url( $code );

		try {
			$options = new \chillerlan\QRCode\QROptions( [
				'version'      => \chillerlan\QRCode\QRCode::VERSION_AUTO,
				'outputType'   => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
				'eccLevel'     => \chillerlan\QRCode\QRCode::ECC_M,
				'scale'        => self::PNG_SIZE,
				'imageBase64'  => false,
				'addQuietzone' => true,
				'quietzoneSize'=> 2,
				// Plain black on white — most reliable for scanners.
				'imageTransparent' => false,
				'bgColor' => [255, 255, 255],
			] );

			$png = ( new \chillerlan\QRCode\QRCode( $options ) )->render( $payload );
		} catch ( \Throwable $e ) {
			if ( class_exists( 'CEM_Error_Reporter' ) ) {
				CEM_Error_Reporter::report_exception( $e, 'CEM_QR::generate_png' );
			}
			return new WP_Error( 'cem_qr_render_failed', $e->getMessage() );
		}

		if ( ! is_string( $png ) || $png === '' ) {
			return new WP_Error( 'cem_qr_empty', 'QR render returned empty payload.' );
		}

		// chillerlan returns the raw PNG binary as a string when
		// imageBase64 is false. Write atomically (write to .tmp,
		// rename) so concurrent requests don't see a half-written
		// file.
		$tmp = $path . '.tmp';
		if ( file_put_contents( $tmp, $png ) === false ) {
			return new WP_Error( 'cem_qr_write_failed', 'Could not write QR cache file.' );
		}
		if ( ! @rename( $tmp, $path ) ) {
			@unlink( $tmp );
			return new WP_Error( 'cem_qr_rename_failed', 'Could not finalize QR cache file.' );
		}

		return $path;
	}

	/**
	 * Drop the cached PNG for a specific code. Called when a
	 * registration is deleted or cancelled.
	 */
	public static function bust_cache( $code ) {
		$code = self::sanitize_code( $code );
		if ( ! $code ) return;
		$path = self::cache_path( $code );
		if ( $path && file_exists( $path ) ) {
			@unlink( $path );
		}
	}

	// ── Helpers ───────────────────────────────────────────────────────────────

	/**
	 * Restrict to alphanumerics + hyphens. Registration codes from
	 * CEM_Helpers::generate_code() are uppercase alphanumeric, so
	 * this is a safe, conservative whitelist for filesystem use.
	 */
	private static function sanitize_code( $code ) {
		$code = (string) $code;
		if ( $code === '' ) return '';
		return preg_match( '/^[A-Za-z0-9-]{4,64}$/', $code ) ? $code : '';
	}

	/**
	 * Resolve the absolute filesystem path where {code}.png is cached.
	 * Creates the directory on first call.
	 */
	private static function cache_path( $code ) {
		$uploads = wp_upload_dir();
		if ( ! empty( $uploads['error'] ) ) return '';

		$dir = trailingslashit( $uploads['basedir'] ) . self::CACHE_SUBDIR;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			// Drop a tiny index.html so directory listing is blocked
			// even if the host doesn't auto-disable indexes.
			@file_put_contents( trailingslashit( $dir ) . 'index.html', '' );
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) return '';

		return trailingslashit( $dir ) . $code . '.png';
	}
}
