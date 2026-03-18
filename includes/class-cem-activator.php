<?php
/**
 * Fired during plugin activation.
 * Creates DB tables, sets default options, schedules cron, creates pages.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Activator {

	public static function activate() {
		self::create_tables();
		self::set_default_options();
		self::create_pages();
		self::schedule_cron();

		// Flush so CPT permalinks work
		flush_rewrite_rules();
		update_option( 'cem_db_version', CEM_DB_VERSION );
	}

	// ── Database Tables ────────────────────────────────────────────────────────

	private static function create_tables() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// Registrations (v1.1.0 adds payment_status + payment_intent_id)
		$sql = "CREATE TABLE {$wpdb->prefix}cem_registrations (
			id                BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id          BIGINT(20) UNSIGNED NOT NULL,
			user_id           BIGINT(20) UNSIGNED DEFAULT NULL,
			first_name        VARCHAR(100) NOT NULL,
			last_name         VARCHAR(100) NOT NULL,
			email             VARCHAR(200) NOT NULL,
			phone             VARCHAR(50)  DEFAULT NULL,
			num_attendees     INT(11)      NOT NULL DEFAULT 1,
			status            ENUM('pending','confirmed','cancelled','waitlisted','checked_in') NOT NULL DEFAULT 'pending',
			registration_code VARCHAR(32)  NOT NULL,
			notes             TEXT DEFAULT NULL,
			payment_status    VARCHAR(20)  NOT NULL DEFAULT 'free',
			payment_intent_id VARCHAR(255) DEFAULT NULL,
			checked_in_at     DATETIME DEFAULT NULL,
			created_at        DATETIME NOT NULL,
			updated_at        DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY registration_code (registration_code),
			KEY event_id          (event_id),
			KEY email             (email),
			KEY status            (status),
			KEY user_id           (user_id),
			KEY payment_status    (payment_status)
		) $charset_collate;";
		dbDelta( $sql );

		// Registration custom-field answers
		$sql = "CREATE TABLE {$wpdb->prefix}cem_registration_meta (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_id BIGINT(20) UNSIGNED NOT NULL,
			meta_key        VARCHAR(255) NOT NULL,
			meta_value      LONGTEXT DEFAULT NULL,
			PRIMARY KEY (id),
			KEY registration_id (registration_id),
			KEY meta_key (meta_key(191))
		) $charset_collate;";
		dbDelta( $sql );

		// Waitlist
		$sql = "CREATE TABLE {$wpdb->prefix}cem_waitlist (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id      BIGINT(20) UNSIGNED NOT NULL,
			first_name    VARCHAR(100) NOT NULL,
			last_name     VARCHAR(100) NOT NULL,
			email         VARCHAR(200) NOT NULL,
			phone         VARCHAR(50)  DEFAULT NULL,
			num_attendees INT(11)      NOT NULL DEFAULT 1,
			position      INT(11)      NOT NULL DEFAULT 0,
			notified      TINYINT(1)   NOT NULL DEFAULT 0,
			created_at    DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id (event_id),
			KEY position (position)
		) $charset_collate;";
		dbDelta( $sql );

		// Email log
		$sql = "CREATE TABLE {$wpdb->prefix}cem_email_log (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id        BIGINT(20) UNSIGNED DEFAULT NULL,
			registration_id BIGINT(20) UNSIGNED DEFAULT NULL,
			to_email        VARCHAR(200) NOT NULL,
			to_name         VARCHAR(200) DEFAULT NULL,
			subject         VARCHAR(500) NOT NULL,
			message         LONGTEXT NOT NULL,
			type            VARCHAR(50) DEFAULT 'general',
			status          ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
			error_message   TEXT DEFAULT NULL,
			sent_at         DATETIME DEFAULT NULL,
			created_at      DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY event_id        (event_id),
			KEY registration_id (registration_id),
			KEY status          (status),
			KEY type            (type)
		) $charset_collate;";
		dbDelta( $sql );

		// Event custom field definitions
		$sql = "CREATE TABLE {$wpdb->prefix}cem_custom_fields (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id     BIGINT(20) UNSIGNED NOT NULL,
			field_label  VARCHAR(255) NOT NULL,
			field_name   VARCHAR(255) NOT NULL,
			field_type   VARCHAR(50)  NOT NULL DEFAULT 'text',
			field_options LONGTEXT DEFAULT NULL,
			required     TINYINT(1)   NOT NULL DEFAULT 0,
			sort_order   INT(11)      NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY event_id (event_id)
		) $charset_collate;";
		dbDelta( $sql );

		// Volunteer assignments
		$sql = "CREATE TABLE {$wpdb->prefix}cem_volunteer_assignments (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			event_id        BIGINT(20) UNSIGNED NOT NULL,
			user_id         BIGINT(20) UNSIGNED NOT NULL,
			role_label      VARCHAR(100) DEFAULT NULL,
			notes           TEXT DEFAULT NULL,
			reminder_sent   TINYINT(1) NOT NULL DEFAULT 0,
			created_at      DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY event_user (event_id, user_id),
			KEY event_id (event_id),
			KEY user_id (user_id)
		) $charset_collate;";
		dbDelta( $sql );

		// Attendance / check-in log
		$sql = "CREATE TABLE {$wpdb->prefix}cem_checkins (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			registration_id BIGINT(20) UNSIGNED NOT NULL,
			event_id        BIGINT(20) UNSIGNED NOT NULL,
			checked_in_by   BIGINT(20) UNSIGNED DEFAULT NULL,
			checked_in_at   DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY registration_id (registration_id),
			KEY event_id        (event_id)
		) $charset_collate;";
		dbDelta( $sql );
	}

	// ── Default Options ────────────────────────────────────────────────────────

	private static function set_default_options() {
		$defaults = [
			'cem_stripe_enabled'             => '0',
			'cem_stripe_test_mode'           => '1',
			'cem_stripe_publishable_key'     => '',
			'cem_stripe_secret_key'          => '',
			'cem_from_name'                  => get_bloginfo( 'name' ),
			'cem_from_email'                 => get_option( 'admin_email' ),
			'cem_reply_to_email'             => get_option( 'admin_email' ),
			'cem_admin_notify_email'         => get_option( 'admin_email' ),
			'cem_admin_notify_on_register'   => '1',
			'cem_registration_auto_confirm'  => '1',
			'cem_waitlist_enabled'           => '1',
			'cem_allow_cancellations'        => '1',
			'cem_cancellation_days_before'   => '2',
			'cem_send_reminders'             => '1',
			'cem_reminder_days_before'       => '1',
			'cem_confirmation_subject'       => __( 'Registration Confirmed – {event_title}', 'church-event-manager' ),
			'cem_reminder_subject'           => __( 'Reminder: {event_title} is Tomorrow!', 'church-event-manager' ),
			'cem_cancellation_subject'       => __( 'Your Registration Has Been Cancelled – {event_title}', 'church-event-manager' ),
			'cem_events_per_page'            => '10',
			'cem_date_format'                => 'F j, Y',
			'cem_time_format'                => 'g:i a',
			'cem_currency_symbol'            => '$',
			'cem_accent_color'               => '#3b5998',
		];

		foreach ( $defaults as $key => $value ) {
			if ( get_option( $key ) === false ) {
				add_option( $key, $value );
			}
		}
	}

	// ── Upgrade (runs on every request, returns early when already current) ───

	public static function maybe_upgrade() {
		if ( get_option( 'cem_db_version' ) === CEM_DB_VERSION ) {
			return;
		}
		self::create_tables();
		self::set_default_options();
		update_option( 'cem_db_version', CEM_DB_VERSION );
	}

	// ── Auto-create Pages ─────────────────────────────────────────────────────

	private static function create_pages() {
		$pages = [
			'cem_events_page_id' => [
				'title'   => __( 'Church Events', 'church-event-manager' ),
				'content' => '[cem_events]',
				'slug'    => 'church-events',
			],
			'cem_my_registrations_page_id' => [
				'title'   => __( 'My Registrations', 'church-event-manager' ),
				'content' => '[cem_my_registrations]',
				'slug'    => 'my-registrations',
			],
			'cem_volunteer_portal_page_id' => [
				'title'   => __( 'Volunteer Portal', 'church-event-manager' ),
				'content' => '[cem_volunteer_portal]',
				'slug'    => 'volunteer-portal',
			],
		];

		foreach ( $pages as $option_key => $page ) {
			if ( get_option( $option_key ) ) {
				continue;
			}

			// Check if page with same slug already exists
			$existing = get_page_by_path( $page['slug'] );
			if ( $existing ) {
				update_option( $option_key, $existing->ID );
				continue;
			}

			$page_id = wp_insert_post( [
				'post_title'   => $page['title'],
				'post_content' => $page['content'],
				'post_name'    => $page['slug'],
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_author'  => 1,
			] );

			if ( ! is_wp_error( $page_id ) ) {
				update_option( $option_key, $page_id );
			}
		}
	}

	// ── Cron ──────────────────────────────────────────────────────────────────

	private static function schedule_cron() {
		if ( ! wp_next_scheduled( 'cem_send_reminders_hook' ) ) {
			// Run daily at 8 AM site time
			$timestamp = strtotime( 'today 08:00:00' );
			if ( $timestamp < time() ) {
				$timestamp = strtotime( 'tomorrow 08:00:00' );
			}
			wp_schedule_event( $timestamp, 'daily', 'cem_send_reminders_hook' );
		}
	}
}
