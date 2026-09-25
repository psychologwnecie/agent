<?php
/**
 * Plugin Name:       PWN Recovery Audit (read-only)
 * Description:       Jednorazowy, tylko-do-odczytu audyt instalacji LatePoint / WooCommerce / WP-Cron przed budową wtyczki PWN Recovery Agent. Nie zapisuje niczego w bazie danych.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            psychologwnecie.pl
 * License:           Proprietary
 * Text Domain:       pwn-recovery-audit
 *
 * Zasady tego narzędzia:
 * - wykonuje wyłącznie zapytania SELECT / SHOW,
 * - nie tworzy tabel, opcji, transientów ani zadań CRON,
 * - nie zwraca danych osobowych: tylko struktury (nazwy kolumn, klucze JSON),
 *   liczniki i rozkłady wartości kolumn typu "status".
 */

defined( 'ABSPATH' ) || exit;

define( 'PWN_AUDIT_VERSION', '0.1.0' );
define( 'PWN_AUDIT_DIR', plugin_dir_path( __FILE__ ) );

require_once PWN_AUDIT_DIR . 'includes/class-pwn-audit-runner.php';

if ( is_admin() ) {
	require_once PWN_AUDIT_DIR . 'includes/class-pwn-audit-admin.php';
	PWN_Audit_Admin::init();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once PWN_AUDIT_DIR . 'includes/class-pwn-audit-cli.php';
	WP_CLI::add_command( 'pwn-audit', 'PWN_Audit_CLI' );
}
