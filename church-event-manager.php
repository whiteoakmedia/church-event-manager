<?php
/**
 * Plugin Name:  Church Event Manager
 * Plugin URI:   https://whiteoakmedia.io
 * Description:  A comprehensive event management system built for churches. Includes event registration, custom fields, bulk emailing, waitlists, check-ins, volunteer management, and a volunteer-friendly admin dashboard.
 * Version:      1.0.3
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author:       White Oak Media LLC
 * Author URI:   https://whiteoakmedia.io
 * License:      GPL v2 or later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  church-event-manager
 * Domain Path:  /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Constants ───────────────────────────────────────────────────────────────
define( 'CEM_VERSION',         '1.0.3' );
define( 'CEM_PLUGIN_DIR',      plugin_dir_path( __FILE__ ) );
define( 'CEM_PLUGIN_URL',      plugin_dir_url( __FILE__ ) );
define( 'CEM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'CEM_DB_VERSION',      '1.1.0' );

// ─── Autoload dependencies ────────────────────────────────────────────────────
function cem_load_dependencies() {
	$files = [
		'includes/class-cem-activator.php',
		'includes/class-cem-deactivator.php',
		'includes/class-cem-helpers.php',
		'includes/class-cem-post-types.php',
		'includes/class-cem-registration.php',
		'includes/class-cem-email.php',
		'includes/class-cem-custom-fields.php',
		'includes/class-cem-shortcodes.php',
		'includes/class-cem-ajax.php',
		'includes/class-cem-notifications.php',
		'includes/class-cem-group.php',
		'admin/class-cem-admin.php',
		'public/class-cem-public.php',
	];
	foreach ( $files as $file ) {
		require_once CEM_PLUGIN_DIR . $file;
	}
}

/**
 * IMPORTANT:
 * Activation runs BEFORE WordPress fires `init`, so CPT rewrite rules won't exist yet unless
 * we register post types manually during activation. If rewrite rules are flushed without CPTs
 * being registered, archives may appear (depending on theme/routes) while single URLs 404 until
 * permalinks are re-saved.
 */
function cem_register_post_types_for_rewrites() {
	if ( class_exists( 'CEM_Post_Types' ) ) {
		$post_types = new CEM_Post_Types();
		if ( method_exists( $post_types, 'register' ) ) {
			$post_types->register();
		}
	}
	// Also register the cem_group CPT so its rewrite rules are included.
	if ( class_exists( 'CEM_Group' ) ) {
		$group = new CEM_Group();
		if ( method_exists( $group, 'register_cpt' ) ) {
			$group->register_cpt();
		}
	}
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────
function CEM_init() {
	cem_load_dependencies();

	// Localization
	load_plugin_textdomain( 'church-event-manager', false, dirname( CEM_PLUGIN_BASENAME ) . '/languages' );

	// Register CPTs & taxonomies (priority 0 so rewrite rules/hooks exist as early as possible)
	$post_types = new CEM_Post_Types();
	add_action( 'init', [ $post_types, 'register' ], 0 );

	// Shortcodes
	$shortcodes = new CEM_Shortcodes();
	add_action( 'init', [ $shortcodes, 'register' ] );

	// Admin
	if ( is_admin() ) {
		$admin = new CEM_Admin();
		$admin->init();
	}

	// Public
	$public = new CEM_Public();
	$public->init();

	// AJAX (front + back)
	$ajax = new CEM_Ajax();
	$ajax->init();

	// Notifications / email triggers
	$notifications = new CEM_Notifications();
	$notifications->init();

	// Custom fields meta box
	$custom_fields = new CEM_Custom_Fields();
	$custom_fields->init();

	// Groups (Event Series)
	$group = new CEM_Group();
	$group->init();

	// Scheduled reminders
	add_action( 'cem_send_reminders_hook', [ $notifications, 'send_event_reminders' ] );

	// DB migrations — runs on every request but returns immediately when already up-to-date
	add_action( 'init', [ 'CEM_Activator', 'maybe_upgrade' ] );
}
add_action( 'plugins_loaded', 'CEM_init' );

// ─── Activation / Deactivation ───────────────────────────────────────────────
function cem_activate_plugin() {
	cem_load_dependencies();

	// Ensure CPTs are registered BEFORE flushing rewrite rules.
	cem_register_post_types_for_rewrites();

	if ( class_exists( 'CEM_Activator' ) && method_exists( 'CEM_Activator', 'activate' ) ) {
		CEM_Activator::activate();
	}

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cem_activate_plugin' );

function cem_deactivate_plugin() {
	cem_load_dependencies();

	if ( class_exists( 'CEM_Deactivator' ) && method_exists( 'CEM_Deactivator', 'deactivate' ) ) {
		CEM_Deactivator::deactivate();
	}

	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cem_deactivate_plugin' );
