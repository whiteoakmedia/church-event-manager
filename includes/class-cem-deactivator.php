<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class CEM_Deactivator {
	public static function deactivate() {
		wp_clear_scheduled_hook( 'cem_send_reminders_hook' );
		flush_rewrite_rules();
	}
}
