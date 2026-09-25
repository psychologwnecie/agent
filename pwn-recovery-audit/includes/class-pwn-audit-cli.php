<?php
/**
 * WP-CLI: wp pwn-audit run > pwn-recovery-audit.json
 */

defined( 'ABSPATH' ) || exit;

final class PWN_Audit_CLI {

	/**
	 * Uruchamia audyt tylko do odczytu i wypisuje raport JSON na stdout.
	 *
	 * ## EXAMPLES
	 *
	 *     wp pwn-audit run > pwn-recovery-audit.json
	 *
	 * @when after_wp_load
	 */
	public function run( $args, $assoc_args ) {
		$report = ( new PWN_Audit_Runner() )->run();
		WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}
}
