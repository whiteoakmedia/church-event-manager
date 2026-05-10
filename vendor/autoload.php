<?php
/**
 * Minimal PSR-4 autoloader for the bundled QR-code dependencies
 * (chillerlan/php-qrcode v4.4.2 + chillerlan/settings-container v2.1.6).
 *
 * We do NOT use Composer in this plugin — keeping a single ~150 KB
 * vendor tree avoids requiring agencies to run `composer install`
 * every time they pull from GitHub. Each library's source `src/`
 * folder is copied into vendor/chillerlan/<lib>/ verbatim, and this
 * autoloader maps the two top-level namespaces to those folders.
 */

spl_autoload_register( function ( $class ) {
	static $map = [
		'chillerlan\\QRCode\\'   => __DIR__ . '/chillerlan/php-qrcode/',
		'chillerlan\\Settings\\' => __DIR__ . '/chillerlan/settings-container/',
	];

	foreach ( $map as $prefix => $base_dir ) {
		if ( strpos( $class, $prefix ) !== 0 ) continue;
		$relative = substr( $class, strlen( $prefix ) );
		$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
			return;
		}
	}
} );
