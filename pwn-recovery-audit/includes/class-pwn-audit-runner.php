<?php
/**
 * Read-only audit runner.
 *
 * Każda sekcja jest niezależna: błąd w jednej nie przerywa pozostałych.
 * Żadna metoda nie zwraca danych osobowych (imion, e-maili, telefonów, treści).
 */

defined( 'ABSPATH' ) || exit;

final class PWN_Audit_Runner {

	/** Maksymalna liczba intentów analizowanych w sekcjach próbkujących. */
	const SAMPLE_LIMIT = 300;

	/** Maksymalna liczba intentów analizowanych w symulacji dopasowania. */
	const MATCH_LIMIT = 3000;

	/** Kolumny, których rozkład wartości wolno pokazać (typy wyliczeniowe, nie dane osobowe). */
	const ENUM_COLUMN_PATTERN = '/(^|_)(status|processor|method|portion|kind|type|source|variant|fulfillment_status)$/i';

	/** Klucze JSON, których wartości (nie tylko obecność) wolno pokazać. */
	const ENUM_JSON_KEYS = array( 'payment_processor', 'payment_method', 'payment_portion', 'payment_time', 'processor', 'method', 'portion', 'variant', 'order_item_variant', 'type', 'status' );

	/** Wzorce nazw, których wartości nigdy nie są wypisywane. */
	const SECRET_PATTERN = '/(key|secret|token|pass|pwd|auth|salt|license|licence)/i';

	/** @var wpdb */
	private $db;

	/** @var array<string,string> nazwa logiczna => pełna nazwa tabeli */
	private $tables = array();

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	public function run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$report = array(
			'audit_tool_version' => PWN_AUDIT_VERSION,
			'generated_at_utc'   => gmdate( 'c' ),
		);

		$sections = array(
			'environment',
			'plugins',
			'cron',
			'latepoint_constants',
			'latepoint_tables',
			'latepoint_classes',
			'latepoint_hooks',
			'latepoint_source_keywords',
			'booking_intents_profile',
			'bookings_profile',
			'orders_profile',
			'completion_linkage',
			'abandonment_simulation',
			'woocommerce',
			'mail',
		);

		foreach ( $sections as $section ) {
			$started = microtime( true );
			try {
				$report[ $section ] = call_user_func( array( $this, 'section_' . $section ) );
			} catch ( \Throwable $e ) {
				$report[ $section ] = array( 'error' => get_class( $e ) . ': ' . $this->strip_paths( $e->getMessage() ) );
			}
			$report['_timings_ms'][ $section ] = (int) round( ( microtime( true ) - $started ) * 1000 );
		}

		return $report;
	}

	/* ---------------------------------------------------------------------
	 * 1. Środowisko
	 * ------------------------------------------------------------------- */

	private function section_environment() {
		global $wp_version;

		$db_now = $this->db->get_row( 'SELECT NOW() AS now_local, UTC_TIMESTAMP() AS now_utc, @@session.time_zone AS session_tz, @@global.time_zone AS global_tz', ARRAY_A );

		return array(
			'wordpress_version'  => $wp_version,
			'php_version'        => PHP_VERSION,
			'db_server'          => method_exists( $this->db, 'db_server_info' ) ? $this->db->db_server_info() : $this->db->db_version(),
			'table_prefix'       => $this->db->prefix,
			'charset'            => $this->db->charset,
			'collate'            => $this->db->collate,
			'is_multisite'       => is_multisite(),
			'locale'             => get_locale(),
			'wp_timezone'        => function_exists( 'wp_timezone_string' ) ? wp_timezone_string() : get_option( 'timezone_string' ),
			'php_now_utc'        => gmdate( 'Y-m-d H:i:s' ),
			'wp_now_local'       => current_time( 'mysql' ),
			'db_time'            => $db_now,
			'wp_debug'           => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'       => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
			'memory_limit'       => ini_get( 'memory_limit' ),
			'max_execution_time' => ini_get( 'max_execution_time' ),
			'object_cache'       => wp_using_ext_object_cache(),
			'environment_type'   => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * 2. Wtyczki
	 * ------------------------------------------------------------------- */

	private function section_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all    = get_plugins();
		$out    = array( 'relevant' => array(), 'all_active' => array() );
		$needle = '/(latepoint|woocommerce|action.?scheduler|smtp|mail|postman|sendgrid|mailgun|brevo|sendinblue|postmark|amazon.?ses|cron|queue|cache|security|firewall)/i';

		foreach ( $all as $file => $data ) {
			$active = is_plugin_active( $file );
			$row    = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => $active,
			);
			if ( $active ) {
				$out['all_active'][] = $data['Name'] . ' ' . $data['Version'];
			}
			if ( preg_match( $needle, $file . ' ' . $data['Name'] . ' ' . $data['TextDomain'] ) ) {
				$out['relevant'][] = $row;
			}
		}

		$out['mu_plugins'] = array_map(
			function ( $d ) {
				return $d['Name'] . ' ' . $d['Version'];
			},
			get_mu_plugins()
		);

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 3. WP-Cron i Action Scheduler
	 * ------------------------------------------------------------------- */

	private function section_cron() {
		$now    = time();
		$crons  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		$events = array();
		$overdue_5min = 0;

		foreach ( (array) $crons as $timestamp => $hooks ) {
			foreach ( (array) $hooks as $hook => $instances ) {
				foreach ( (array) $instances as $instance ) {
					if ( $timestamp < $now - 300 ) {
						$overdue_5min++;
					}
					if ( ! isset( $events[ $hook ] ) ) {
						$events[ $hook ] = array(
							'next_run_utc' => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
							'schedule'     => isset( $instance['schedule'] ) ? $instance['schedule'] : 'single',
							'count'        => 0,
						);
					}
					$events[ $hook ]['count']++;
				}
			}
		}

		$out = array(
			'DISABLE_WP_CRON'        => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false,
			'ALTERNATE_WP_CRON'      => defined( 'ALTERNATE_WP_CRON' ) ? (bool) ALTERNATE_WP_CRON : false,
			'WP_CRON_LOCK_TIMEOUT'   => defined( 'WP_CRON_LOCK_TIMEOUT' ) ? WP_CRON_LOCK_TIMEOUT : null,
			'overdue_events_gt_5min' => $overdue_5min,
			'interpretation'         => 'Duża liczba zaległych zdarzeń przy DISABLE_WP_CRON=true sugeruje brak systemowego crona; przy false - mały ruch na stronie.',
			'schedules'              => array_keys( wp_get_schedules() ),
			'events'                 => $events,
		);

		// Action Scheduler (zwykle dostarczany przez WooCommerce).
		$as = array( 'available' => class_exists( 'ActionScheduler_Versions' ) || class_exists( 'ActionScheduler' ) );
		if ( class_exists( 'ActionScheduler_Versions' ) ) {
			$as['latest_version'] = ActionScheduler_Versions::instance()->latest_version();
		}
		$as_table = $this->db->prefix . 'actionscheduler_actions';
		if ( $this->table_exists( $as_table ) ) {
			$as['status_counts'] = $this->db->get_results( "SELECT status, COUNT(*) AS c FROM `{$as_table}` GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$as['pending_overdue_gt_10min'] = (int) $this->db->get_var(
				$this->db->prepare( "SELECT COUNT(*) FROM `{$as_table}` WHERE status = 'pending' AND scheduled_date_gmt < %s", gmdate( 'Y-m-d H:i:s', $now - 600 ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			);
			$as['latest_completed_gmt'] = $this->db->get_var( "SELECT MAX(last_attempt_gmt) FROM `{$as_table}` WHERE status = 'complete'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$out['action_scheduler'] = $as;

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 4. Stałe LatePoint
	 * ------------------------------------------------------------------- */

	private function section_latepoint_constants() {
		$constants = get_defined_constants( true );
		$user      = isset( $constants['user'] ) ? $constants['user'] : array();
		$out       = array();

		foreach ( $user as $name => $value ) {
			if ( stripos( $name, 'LATEPOINT' ) !== 0 ) {
				continue;
			}
			if ( preg_match( self::SECRET_PATTERN, $name ) ) {
				$out[ $name ] = '[redacted]';
				continue;
			}
			$out[ $name ] = is_scalar( $value ) ? $this->strip_paths( (string) $value ) : gettype( $value );
		}
		ksort( $out );

		return array(
			'count'     => count( $out ),
			'constants' => $out,
		);
	}

	/* ---------------------------------------------------------------------
	 * 5. Tabele LatePoint: kolumny, indeksy, liczność
	 * ------------------------------------------------------------------- */

	private function section_latepoint_tables() {
		$out = array();
		foreach ( $this->latepoint_tables() as $table ) {
			$columns = $this->db->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexes = $this->db->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$cols = array();
			foreach ( (array) $columns as $c ) {
				$cols[ $c['Field'] ] = trim( $c['Type'] . ' ' . ( 'NO' === $c['Null'] ? 'NOT NULL' : 'NULL' ) . ' ' . $c['Key'] . ' ' . $c['Extra'] );
			}

			$idx = array();
			foreach ( (array) $indexes as $i ) {
				$key = $i['Key_name'] . ( '0' === (string) $i['Non_unique'] ? ' (unique)' : '' );
				$idx[ $key ][] = $i['Column_name'];
			}

			$out[ $table ] = array(
				'rows'    => (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$table}`" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'columns' => $cols,
				'indexes' => $idx,
				'enum_distributions' => $this->enum_distributions( $table, array_keys( $cols ) ),
			);
		}

		return array(
			'count'  => count( $out ),
			'tables' => $out,
		);
	}

	/* ---------------------------------------------------------------------
	 * 6. Klasy LatePoint (publiczne API PHP)
	 * ------------------------------------------------------------------- */

	private function section_latepoint_classes() {
		$dirs    = $this->latepoint_plugin_dirs();
		$pattern = '/(Intent|Booking|Order|Customer|Service|Agent|Location|Cart|Payment|Transaction|Coupon|Woo|Step|Notification|Activit|Process|Event|Model|Helper$)/i';
		$out     = array();
		$other   = array();

		foreach ( get_declared_classes() as $class ) {
			$ref  = new ReflectionClass( $class );
			$file = $ref->getFileName();
			if ( ! $file || ! $this->path_in_dirs( $file, $dirs ) ) {
				continue;
			}
			if ( ! preg_match( $pattern, $class ) ) {
				$other[] = $class;
				continue;
			}

			$methods = array();
			foreach ( $ref->getMethods( ReflectionMethod::IS_PUBLIC ) as $m ) {
				if ( $m->getDeclaringClass()->getName() !== $class ) {
					continue; // tylko metody zadeklarowane w tej klasie
				}
				$params = array();
				foreach ( $m->getParameters() as $p ) {
					$params[] = '$' . $p->getName() . ( $p->isOptional() ? '?' : '' );
				}
				$methods[] = ( $m->isStatic() ? 'static ' : '' ) . $m->getName() . '(' . implode( ', ', $params ) . ')';
			}

			$defaults = $ref->getDefaultProperties();
			$props    = array();
			foreach ( array( 'table_name', 'nice_names', 'db_columns' ) as $prop ) {
				if ( array_key_exists( $prop, $defaults ) && is_scalar( $defaults[ $prop ] ) ) {
					$props[ $prop ] = $defaults[ $prop ];
				}
			}

			$parent        = $ref->getParentClass();
			$out[ $class ] = array(
				'file'    => $this->strip_paths( $file ),
				'parent'  => $parent ? $parent->getName() : null,
				'props'   => $props,
				'methods' => $methods,
			);
		}

		ksort( $out );
		sort( $other );

		return array(
			'note'                 => 'Tylko klasy już załadowane w tym żądaniu. Klasy ładowane leniwie mogą nie być widoczne - sprawdź sekcję latepoint_source_keywords.',
			'relevant_classes'     => $out,
			'other_loaded_classes' => $other,
		);
	}

	/* ---------------------------------------------------------------------
	 * 7. Hooki (do_action / apply_filters) w kodzie LatePoint i dodatków
	 * ------------------------------------------------------------------- */

	private function section_latepoint_hooks() {
		global $wp_filter;

		$hooks   = array();
		$dynamic = array();
		$regex   = '/\b(do_action|do_action_ref_array|apply_filters|apply_filters_ref_array)\s*\(\s*([\'"])([^\'"]+)\2/';
		$dyn_rx  = '/\b(do_action|do_action_ref_array|apply_filters|apply_filters_ref_array)\s*\(\s*([^\'"\s][^,)]{0,80})/';

		foreach ( $this->latepoint_php_files() as $file ) {
			$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $code ) {
				continue;
			}
			$rel = $this->strip_paths( $file );

			if ( preg_match_all( $regex, $code, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					$name = $match[3][0];
					$line = substr_count( $code, "\n", 0, $match[0][1] ) + 1;
					if ( ! isset( $hooks[ $name ] ) ) {
						$hooks[ $name ] = array(
							'type'        => false !== strpos( $match[1][0], 'filter' ) ? 'filter' : 'action',
							'occurrences' => 0,
							'locations'   => array(),
							'args_sample' => $this->call_args_sample( $code, $match[0][1] ),
						);
					}
					$hooks[ $name ]['occurrences']++;
					if ( count( $hooks[ $name ]['locations'] ) < 5 ) {
						$hooks[ $name ]['locations'][] = $rel . ':' . $line;
					}
				}
			}

			if ( preg_match_all( $dyn_rx, $code, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
				foreach ( $m as $match ) {
					if ( count( $dynamic ) >= 60 ) {
						break;
					}
					$line      = substr_count( $code, "\n", 0, $match[0][1] ) + 1;
					$dynamic[] = $rel . ':' . $line . '  ' . $match[1][0] . '(' . trim( $match[2][0] );
				}
			}
		}

		foreach ( $hooks as $name => &$h ) {
			$h['registered_callbacks_now'] = 0;
			if ( isset( $wp_filter[ $name ] ) ) {
				foreach ( $wp_filter[ $name ]->callbacks as $cbs ) {
					$h['registered_callbacks_now'] += count( $cbs );
				}
			}
		}
		unset( $h );
		ksort( $hooks );

		$highlight = array();
		foreach ( array_keys( $hooks ) as $name ) {
			if ( preg_match( '/(intent|booking|order|payment|transaction|cart|customer|checkout|status|coupon|woo)/i', $name ) ) {
				$highlight[] = $name;
			}
		}

		return array(
			'total_static_hooks'   => count( $hooks ),
			'relevant_hook_names'  => $highlight,
			'hooks'                => $hooks,
			'dynamic_hook_samples' => $dynamic,
		);
	}

	/* ---------------------------------------------------------------------
	 * 8. Słowa kluczowe w kodzie (np. czy LatePoint ma własny "abandoned" / cron intentów)
	 * ------------------------------------------------------------------- */

	private function section_latepoint_source_keywords() {
		$keywords = array(
			'abandon'             => '/abandon/i',
			'booking_intent'      => '/booking_intent/i',
			'intent_key'          => '/intent_key/',
			'convert_to'          => '/function\s+convert_to\w*/i',
			'wp_schedule_event'   => '/wp_schedule_(single_)?event\s*\(/',
			'as_schedule'         => '/as_(schedule|enqueue)_\w+\s*\(/',
			'woocommerce_hooks'   => '/[\'"]woocommerce_[a-z_]+[\'"]/',
			'wc_create_order'     => '/wc_create_order|new\s+WC_Order\b/',
			'class_declarations'  => '/^\s*(abstract\s+|final\s+)?class\s+Os\w+/m',
		);
		$out = array();
		foreach ( $keywords as $k => $rx ) {
			$out[ $k ] = array( 'files' => 0, 'hits' => 0, 'examples' => array() );
		}

		foreach ( $this->latepoint_php_files() as $file ) {
			$code = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $code ) {
				continue;
			}
			foreach ( $keywords as $k => $rx ) {
				$n = preg_match_all( $rx, $code, $m );
				if ( $n ) {
					$out[ $k ]['files']++;
					$out[ $k ]['hits'] += $n;
					if ( count( $out[ $k ]['examples'] ) < 25 ) {
						$out[ $k ]['examples'][] = $this->strip_paths( $file ) . ' (' . $n . ')' . ( in_array( $k, array( 'class_declarations', 'woocommerce_hooks', 'convert_to' ), true ) ? ': ' . implode( ', ', array_slice( array_unique( array_map( 'trim', $m[0] ) ), 0, 12 ) ) : '' );
					}
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 9. Booking intents: profil struktury i wieku (bez danych osobowych)
	 * ------------------------------------------------------------------- */

	private function section_booking_intents_profile() {
		$t = $this->find_table( 'intent' );
		if ( ! $t ) {
			return array( 'found' => false, 'note' => 'Nie znaleziono tabeli LatePoint zawierającej "intent" w nazwie.' );
		}
		$cols = $this->columns( $t );
		$out  = array( 'table' => $t, 'columns' => $cols, 'rows' => (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$t}`" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( in_array( 'intent_key', $cols, true ) ) {
			$out['distinct_intent_keys'] = (int) $this->db->get_var( "SELECT COUNT(DISTINCT intent_key) FROM `{$t}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( in_array( 'customer_id', $cols, true ) ) {
			$out['with_customer_id']      = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE customer_id IS NOT NULL AND customer_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['distinct_customer_ids'] = (int) $this->db->get_var( "SELECT COUNT(DISTINCT customer_id) FROM `{$t}` WHERE customer_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( in_array( 'order_id', $cols, true ) ) {
			$out['order_id_null_or_zero'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$t}` WHERE order_id IS NULL OR order_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['order_id_set']          = $out['rows'] - $out['order_id_null_or_zero'];
		}
		foreach ( array( 'created_at', 'updated_at' ) as $dc ) {
			if ( in_array( $dc, $cols, true ) ) {
				$out[ $dc . '_range' ] = $this->db->get_row( "SELECT MIN({$dc}) AS min, MAX({$dc}) AS max FROM `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( in_array( 'created_at', $cols, true ) ) {
			$out['created_per_month_last_12'] = $this->db->get_results( "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS c FROM `{$t}` WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH) GROUP BY month ORDER BY month", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( in_array( 'updated_at', $cols, true ) && in_array( 'order_id', $cols, true ) ) {
			$out['open_intents_age_since_update'] = $this->db->get_row(
				"SELECT
					SUM(updated_at >= DATE_SUB(NOW(), INTERVAL 3 HOUR)) AS lt_3h,
					SUM(updated_at <  DATE_SUB(NOW(), INTERVAL 3 HOUR) AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS h3_24,
					SUM(updated_at <  DATE_SUB(NOW(), INTERVAL 24 HOUR) AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS d1_7,
					SUM(updated_at <  DATE_SUB(NOW(), INTERVAL 7 DAY) AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS d7_30,
					SUM(updated_at <  DATE_SUB(NOW(), INTERVAL 30 DAY)) AS gt_30d
				FROM `{$t}` WHERE order_id IS NULL OR order_id = 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				ARRAY_A
			);
			$out['open_intents_age_note'] = 'Liczone względem NOW() bazy danych - porównaj strefę czasową z sekcją environment.db_time.';
		}

		// Struktura pól JSON / serialized: wyłącznie ścieżki kluczy, typy i częstość występowania.
		$json_cols = array_values( array_intersect( array( 'cart_items_data', 'restrictions_data', 'presets_data', 'payment_data', 'booking_data', 'customer_data', 'other_data' ), $cols ) );
		if ( $json_cols ) {
			$order = in_array( 'id', $cols, true ) ? 'ORDER BY id DESC' : '';
			$rows  = $this->db->get_results( 'SELECT `' . implode( '`,`', $json_cols ) . "` FROM `{$t}` {$order} LIMIT " . (int) self::SAMPLE_LIMIT, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['json_structure_sample_size'] = count( $rows );
			foreach ( $json_cols as $jc ) {
				$paths  = array();
				$enums  = array();
				$format = array( 'json' => 0, 'serialized' => 0, 'empty' => 0, 'other' => 0 );
				$items_per_intent = array();
				foreach ( $rows as $r ) {
					list( $decoded, $fmt ) = $this->decode_blob( $r[ $jc ] );
					$format[ $fmt ]++;
					if ( is_array( $decoded ) ) {
						$this->collect_paths( $decoded, '', $paths, $enums );
						if ( 'cart_items_data' === $jc ) {
							$n = count( $decoded );
							$items_per_intent[ $n ] = isset( $items_per_intent[ $n ] ) ? $items_per_intent[ $n ] + 1 : 1;
						}
					}
				}
				ksort( $paths );
				foreach ( $paths as &$p ) {
					$p['types'] = implode( '|', array_keys( $p['types'] ) );
				}
				unset( $p );
				ksort( $items_per_intent );
				$out['json'][ $jc ] = array(
					'format'      => $format,
					'key_paths'   => $paths,
					'enum_values' => $enums,
				);
				if ( 'cart_items_data' === $jc ) {
					$out['json'][ $jc ]['top_level_entries_per_intent'] = $items_per_intent;
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 10. Bookings / 11. Orders: struktura i rozkłady statusów
	 * ------------------------------------------------------------------- */

	private function section_bookings_profile() {
		$t = $this->find_table( 'bookings', true );
		if ( ! $t ) {
			return array( 'found' => false );
		}
		return $this->table_profile( $t );
	}

	private function section_orders_profile() {
		$out = array();
		foreach ( array( 'orders', 'order_items', 'transactions', 'payment_requests', 'order_intents', 'carts', 'cart_items', 'coupons' ) as $suffix ) {
			$t = $this->find_table( $suffix, true );
			$out[ $suffix ] = $t ? $this->table_profile( $t ) : array( 'found' => false );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 12. Powiązania intent -> order -> order_item -> booking
	 * ------------------------------------------------------------------- */

	private function section_completion_linkage() {
		$out = array( 'linking_columns' => array() );

		foreach ( $this->latepoint_tables() as $t ) {
			foreach ( $this->columns( $t ) as $c ) {
				if ( preg_match( '/(intent|order_id|order_item_id|booking_id|cart|source|woo|wc_)/i', $c ) ) {
					$out['linking_columns'][ $t ][] = $c;
				}
			}
		}

		$intents = $this->find_table( 'intent' );
		$orders  = $this->find_table( 'orders', true );
		$items   = $this->find_table( 'order_items', true );
		$books   = $this->find_table( 'bookings', true );

		if ( $intents && $orders && in_array( 'order_id', $this->columns( $intents ), true ) ) {
			$out['intents_with_order_id_existing_order'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$intents}` i JOIN `{$orders}` o ON o.id = i.order_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['intents_with_order_id_missing_order']  = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$intents}` i LEFT JOIN `{$orders}` o ON o.id = i.order_id WHERE i.order_id > 0 AND o.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			$ocols = $this->columns( $orders );
			foreach ( array( 'status', 'payment_status', 'fulfillment_status' ) as $sc ) {
				if ( in_array( $sc, $ocols, true ) ) {
					$out[ 'order_' . $sc . '_for_intents_with_order' ] = $this->db->get_results( "SELECT o.`{$sc}` AS v, COUNT(*) AS c FROM `{$intents}` i JOIN `{$orders}` o ON o.id = i.order_id GROUP BY o.`{$sc}` ORDER BY c DESC LIMIT 30", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				}
			}
		}

		if ( $books ) {
			$bcols = $this->columns( $books );
			if ( in_array( 'order_item_id', $bcols, true ) ) {
				$out['bookings_with_order_item_id'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$books}` WHERE order_item_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$out['bookings_without_order_item_id'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$books}` WHERE order_item_id IS NULL OR order_item_id = 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( in_array( 'order_id', $bcols, true ) ) {
				$out['bookings_with_order_id'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$books}` WHERE order_id > 0" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			if ( $items && in_array( 'order_item_id', $bcols, true ) && in_array( 'order_id', $this->columns( $items ), true ) ) {
				$out['bookings_joinable_to_orders_via_items'] = (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$books}` b JOIN `{$items}` oi ON oi.id = b.order_item_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 13. Symulacja wykrywania porzuceń (tylko liczniki, bez danych osobowych)
	 * ------------------------------------------------------------------- */

	private function section_abandonment_simulation() {
		$intents = $this->find_table( 'intent' );
		$books   = $this->find_table( 'bookings', true );
		if ( ! $intents || ! $books ) {
			return array( 'skipped' => 'Brak tabeli intentów lub bookings.' );
		}

		$icols = $this->columns( $intents );
		$bcols = $this->columns( $books );
		foreach ( array( 'id', 'customer_id', 'cart_items_data', 'created_at' ) as $req ) {
			if ( ! in_array( $req, $icols, true ) ) {
				return array( 'skipped' => "Tabela intentów nie ma kolumny {$req}." );
			}
		}
		foreach ( array( 'customer_id', 'created_at' ) as $req ) {
			if ( ! in_array( $req, $bcols, true ) ) {
				return array( 'skipped' => "Tabela bookings nie ma kolumny {$req}." );
			}
		}

		$has_updated = in_array( 'updated_at', $icols, true );
		$has_order   = in_array( 'order_id', $icols, true );
		$activity    = $has_updated ? 'GREATEST(created_at, COALESCE(updated_at, created_at))' : 'created_at';

		$where = 'customer_id > 0 AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND ' . $activity . ' < DATE_SUB(NOW(), INTERVAL 3 HOUR)';
		if ( $has_order ) {
			$where .= ' AND (order_id IS NULL OR order_id = 0)';
		}

		$rows = $this->db->get_results( "SELECT id, customer_id, cart_items_data, created_at, {$activity} AS last_activity FROM `{$intents}` WHERE {$where} ORDER BY id DESC LIMIT " . (int) self::MATCH_LIMIT, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$b_select = array( 'id', 'customer_id', 'created_at' );
		foreach ( array( 'service_id', 'agent_id', 'location_id', 'start_date', 'start_time', 'status' ) as $c ) {
			if ( in_array( $c, $bcols, true ) ) {
				$b_select[] = $c;
			}
		}

		$stats = array(
			'window'                                    => 'intenty z ostatnich 90 dni, bez order_id, ostatnia aktywność > 3h temu, customer_id > 0',
			'candidates_raw'                            => count( $rows ),
			'no_valid_cart_item'                        => 0,
			'with_valid_cart_item'                      => 0,
			'booking_same_customer_after_intent_any'    => 0,
			'booking_same_customer_same_service'        => 0,
			'booking_same_customer_same_service_agent'  => 0,
			'booking_exact_slot_same_service'           => 0,
			'booking_before_intent_same_service_slot'   => 0,
			'no_later_booking_for_customer'             => 0,
			'unique_customers_without_later_booking'    => 0,
			'dedup_groups_customer_service_7d'          => 0,
			'later_booking_statuses'                    => array(),
			'hours_from_intent_to_later_booking'        => array( 'lt_1h' => 0, 'h1_24' => 0, 'd1_7' => 0, 'gt_7d' => 0 ),
		);

		$by_customer_cache = array();
		$unique_abandoned  = array();
		$dedup_keys        = array();

		foreach ( $rows as $r ) {
			list( $cart ) = $this->decode_blob( $r['cart_items_data'] );
			$items        = $this->extract_cart_items( $cart );
			if ( ! $items ) {
				$stats['no_valid_cart_item']++;
				continue;
			}
			$stats['with_valid_cart_item']++;

			$cid = (int) $r['customer_id'];
			if ( ! isset( $by_customer_cache[ $cid ] ) ) {
				$by_customer_cache[ $cid ] = $this->db->get_results(
					$this->db->prepare( 'SELECT `' . implode( '`,`', $b_select ) . "` FROM `{$books}` WHERE customer_id = %d", $cid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
			}
			$bookings = $by_customer_cache[ $cid ];

			$intent_ts = strtotime( $r['created_at'] );
			$later     = array();
			$flags     = array( 'service' => false, 'agent' => false, 'slot' => false, 'before_slot' => false );

			foreach ( $bookings as $b ) {
				$b_ts = strtotime( $b['created_at'] );
				foreach ( $items as $it ) {
					$same_service = isset( $b['service_id'], $it['service_id'] ) && (int) $b['service_id'] === (int) $it['service_id'];
					$same_slot    = $same_service && isset( $b['start_date'], $b['start_time'], $it['start_date'], $it['start_time'] )
						&& (string) $b['start_date'] === (string) $it['start_date'] && (int) $b['start_time'] === (int) $it['start_time'];
					if ( $b_ts >= $intent_ts - 60 ) {
						$later[ $b['id'] ] = $b;
						if ( $same_service ) {
							$flags['service'] = true;
							if ( isset( $b['agent_id'], $it['agent_id'] ) && (int) $b['agent_id'] === (int) $it['agent_id'] ) {
								$flags['agent'] = true;
							}
						}
						if ( $same_slot ) {
							$flags['slot'] = true;
						}
					} elseif ( $same_slot ) {
						$flags['before_slot'] = true;
					}
				}
			}

			if ( $later ) {
				$stats['booking_same_customer_after_intent_any']++;
				$first = null;
				foreach ( $later as $b ) {
					$st = isset( $b['status'] ) ? (string) $b['status'] : 'n/a';
					$stats['later_booking_statuses'][ $st ] = isset( $stats['later_booking_statuses'][ $st ] ) ? $stats['later_booking_statuses'][ $st ] + 1 : 1;
					$ts = strtotime( $b['created_at'] );
					$first = null === $first ? $ts : min( $first, $ts );
				}
				$h = max( 0, ( $first - $intent_ts ) / 3600 );
				$bucket = $h < 1 ? 'lt_1h' : ( $h < 24 ? 'h1_24' : ( $h < 168 ? 'd1_7' : 'gt_7d' ) );
				$stats['hours_from_intent_to_later_booking'][ $bucket ]++;
			} else {
				$stats['no_later_booking_for_customer']++;
				$unique_abandoned[ $cid ] = true;
				$service = isset( $items[0]['service_id'] ) ? (int) $items[0]['service_id'] : 0;
				$dedup_keys[ $cid . ':' . $service . ':' . gmdate( 'o-W', $intent_ts ) ] = true;
			}
			$stats['booking_same_customer_same_service']       += $flags['service'] ? 1 : 0;
			$stats['booking_same_customer_same_service_agent'] += $flags['agent'] ? 1 : 0;
			$stats['booking_exact_slot_same_service']          += $flags['slot'] ? 1 : 0;
			$stats['booking_before_intent_same_service_slot']  += $flags['before_slot'] ? 1 : 0;
		}

		$stats['unique_customers_without_later_booking'] = count( $unique_abandoned );
		$stats['dedup_groups_customer_service_7d']       = count( $dedup_keys );
		$stats['note'] = 'To jest przybliżenie oparte na hipotetycznych nazwach pól (service_id, agent_id, start_date, start_time w cart items). Liczby są wiarygodne tylko, jeśli sekcja booking_intents_profile.json.cart_items_data potwierdzi te klucze. "dedup_groups" = unikalne pary klient+usługa w tygodniu ISO (przybliżenie okna 7 dni).';

		return $stats;
	}

	/* ---------------------------------------------------------------------
	 * 14. WooCommerce
	 * ------------------------------------------------------------------- */

	private function section_woocommerce() {
		$out = array(
			'active'  => class_exists( 'WooCommerce' ),
			'version' => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		);
		if ( ! $out['active'] ) {
			return $out;
		}

		$hpos = null;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		$out['hpos_enabled'] = $hpos;

		$like = '%' . $this->db->esc_like( 'latepoint' ) . '%';

		// Klucze meta zamówień związane z LatePoint (tylko nazwy kluczy i liczniki, bez wartości).
		$orders_meta = $this->db->prefix . 'wc_orders_meta';
		if ( $this->table_exists( $orders_meta ) ) {
			$out['hpos_order_meta_keys'] = $this->db->get_results( $this->db->prepare( "SELECT meta_key, COUNT(*) AS c FROM `{$orders_meta}` WHERE meta_key LIKE %s GROUP BY meta_key", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$out['postmeta_order_meta_keys'] = $this->db->get_results(
			$this->db->prepare(
				"SELECT pm.meta_key, p.post_type, COUNT(*) AS c FROM {$this->db->postmeta} pm JOIN {$this->db->posts} p ON p.ID = pm.post_id WHERE pm.meta_key LIKE %s GROUP BY pm.meta_key, p.post_type",
				$like
			),
			ARRAY_A
		);
		$itemmeta = $this->db->prefix . 'woocommerce_order_itemmeta';
		if ( $this->table_exists( $itemmeta ) ) {
			$out['order_item_meta_keys'] = $this->db->get_results( $this->db->prepare( "SELECT meta_key, COUNT(*) AS c FROM `{$itemmeta}` WHERE meta_key LIKE %s GROUP BY meta_key", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// Statusy zamówień WC, które mają powiązanie z LatePoint.
		$wc_orders = $this->db->prefix . 'wc_orders';
		if ( $hpos && $this->table_exists( $wc_orders ) && $this->table_exists( $orders_meta ) ) {
			$out['latepoint_linked_order_statuses'] = $this->db->get_results( $this->db->prepare( "SELECT o.status, COUNT(DISTINCT o.id) AS c FROM `{$wc_orders}` o JOIN `{$orders_meta}` m ON m.order_id = o.id WHERE m.meta_key LIKE %s GROUP BY o.status", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$out['latepoint_linked_order_statuses'] = $this->db->get_results( $this->db->prepare( "SELECT p.post_status AS status, COUNT(DISTINCT p.ID) AS c FROM {$this->db->posts} p JOIN {$this->db->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = 'shop_order' AND pm.meta_key LIKE %s GROUP BY p.post_status", $like ), ARRAY_A );
		}

		// Możliwości kuponów WooCommerce (Phase 8).
		$coupon_methods = array( 'set_email_restrictions', 'set_usage_limit', 'set_usage_limit_per_user', 'set_date_expires', 'set_individual_use', 'set_discount_type', 'set_product_ids' );
		foreach ( $coupon_methods as $m ) {
			$out['coupon_api'][ $m ] = class_exists( 'WC_Coupon' ) && method_exists( 'WC_Coupon', $m );
		}
		$out['coupons_enabled_setting'] = get_option( 'woocommerce_enable_coupons' );

		// Które hooki WooCommerce są podpięte przez kod LatePoint (nazwy funkcji/klas).
		global $wp_filter;
		$dirs = $this->latepoint_plugin_dirs();
		foreach ( $wp_filter as $hook => $obj ) {
			if ( 0 !== strpos( $hook, 'woocommerce_' ) ) {
				continue;
			}
			foreach ( $obj->callbacks as $prio => $cbs ) {
				foreach ( $cbs as $cb ) {
					$file = $this->callback_file( $cb['function'] );
					if ( $file && $this->path_in_dirs( $file, $dirs ) ) {
						$out['woocommerce_hooks_used_by_latepoint'][ $hook ][] = $this->callback_name( $cb['function'] ) . ' @' . $prio;
					}
				}
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * 15. Poczta
	 * ------------------------------------------------------------------- */

	private function section_mail() {
		$out = array();

		$ref = new ReflectionFunction( 'wp_mail' );
		$out['wp_mail_defined_in'] = $this->strip_paths( (string) $ref->getFileName() );
		$out['wp_mail_overridden'] = false === strpos( (string) $ref->getFileName(), 'wp-includes' );

		global $wp_filter;
		foreach ( array( 'phpmailer_init', 'pre_wp_mail', 'wp_mail', 'wp_mail_from', 'wp_mail_from_name', 'wp_mail_failed', 'wp_mail_succeeded' ) as $hook ) {
			$names = array();
			if ( isset( $wp_filter[ $hook ] ) ) {
				foreach ( $wp_filter[ $hook ]->callbacks as $prio => $cbs ) {
					foreach ( $cbs as $cb ) {
						$names[] = $this->callback_name( $cb['function'] ) . ' @' . $prio;
					}
				}
			}
			$out['callbacks'][ $hook ] = $names;
		}
		$out['note'] = 'Nie odczytujemy konfiguracji SMTP ani danych logowania. Wystarczy wiedzieć, która wtyczka obsługuje wysyłkę.';

		return $out;
	}

	/* =====================================================================
	 * Helpers
	 * =================================================================== */

	private function latepoint_tables() {
		if ( $this->tables ) {
			return $this->tables;
		}
		$like  = $this->db->esc_like( $this->db->prefix . 'latepoint' ) . '%';
		$names = $this->db->get_col( $this->db->prepare( 'SHOW TABLES LIKE %s', $like ) );
		foreach ( (array) $names as $n ) {
			if ( preg_match( '/^[A-Za-z0-9_]+$/', $n ) ) {
				$this->tables[] = $n;
			}
		}
		sort( $this->tables );
		return $this->tables;
	}

	/**
	 * Znajduje tabelę LatePoint po fragmencie nazwy.
	 *
	 * @param string $fragment Fragment nazwy.
	 * @param bool   $suffix   Czy fragment ma być dokładnym sufiksem (np. "_bookings").
	 */
	private function find_table( $fragment, $suffix = false ) {
		foreach ( $this->latepoint_tables() as $t ) {
			if ( $suffix ) {
				if ( substr( $t, -strlen( '_' . $fragment ) ) === '_' . $fragment ) {
					return $t;
				}
			} elseif ( false !== stripos( $t, $fragment ) ) {
				return $t;
			}
		}
		return null;
	}

	private function table_exists( $table ) {
		return $table === $this->db->get_var( $this->db->prepare( 'SHOW TABLES LIKE %s', $this->db->esc_like( $table ) ) );
	}

	private function columns( $table ) {
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = (array) $this->db->get_col( "SHOW COLUMNS FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $cache[ $table ];
	}

	private function table_profile( $t ) {
		$cols = $this->columns( $t );
		$out  = array(
			'table'   => $t,
			'rows'    => (int) $this->db->get_var( "SELECT COUNT(*) FROM `{$t}`" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'columns' => $cols,
			'enum_distributions' => $this->enum_distributions( $t, $cols ),
		);
		if ( in_array( 'created_at', $cols, true ) ) {
			$out['created_at_range'] = $this->db->get_row( "SELECT MIN(created_at) AS min, MAX(created_at) AS max FROM `{$t}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return $out;
	}

	private function enum_distributions( $table, array $cols ) {
		$out = array();
		foreach ( $cols as $c ) {
			if ( ! preg_match( self::ENUM_COLUMN_PATTERN, $c ) || preg_match( self::SECRET_PATTERN, $c ) ) {
				continue;
			}
			$rows = $this->db->get_results( "SELECT `{$c}` AS v, COUNT(*) AS c FROM `{$table}` GROUP BY `{$c}` ORDER BY c DESC LIMIT 31", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( count( $rows ) > 30 ) {
				$out[ $c ] = 'high_cardinality (pominięto - może zawierać dane swobodne)';
				continue;
			}
			foreach ( $rows as $r ) {
				$v = null === $r['v'] ? 'NULL' : (string) $r['v'];
				$out[ $c ][ mb_substr( $v, 0, 40 ) ] = (int) $r['c'];
			}
		}
		return $out;
	}

	/**
	 * @return array{0:mixed,1:string}
	 */
	private function decode_blob( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return array( null, 'empty' );
		}
		$json = json_decode( $raw, true );
		if ( JSON_ERROR_NONE === json_last_error() && is_array( $json ) ) {
			return array( $json, 'json' );
		}
		if ( is_serialized( $raw ) ) {
			$un = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false !== $un || 'b:0;' === $raw ) {
				return array( $un, 'serialized' );
			}
		}
		return array( null, 'other' );
	}

	/**
	 * Zbiera ścieżki kluczy (bez wartości). Klucze dynamiczne (ID, hashe) są normalizowane do "*".
	 */
	private function collect_paths( $data, $prefix, array &$paths, array &$enums, $depth = 0 ) {
		if ( $depth > 6 || ! is_array( $data ) ) {
			return;
		}
		foreach ( $data as $k => $v ) {
			$key  = $this->normalize_key( $k );
			$path = '' === $prefix ? $key : $prefix . '.' . $key;
			$type = is_array( $v ) ? 'array' : gettype( $v );

			// Wartość może być zakodowanym JSON-em w stringu.
			if ( is_string( $v ) && strlen( $v ) > 1 && ( '{' === $v[0] || '[' === $v[0] ) ) {
				$inner = json_decode( $v, true );
				if ( is_array( $inner ) ) {
					$type = 'json-string';
					$v    = $inner;
				}
			}

			if ( ! isset( $paths[ $path ] ) ) {
				$paths[ $path ] = array( 'types' => array(), 'seen' => 0 );
			}
			$paths[ $path ]['seen']++;
			$paths[ $path ]['types'][ $type ] = true;

			if ( is_scalar( $v ) && in_array( (string) $k, self::ENUM_JSON_KEYS, true ) ) {
				$sv = mb_substr( (string) $v, 0, 40 );
				$enums[ $path ][ $sv ] = isset( $enums[ $path ][ $sv ] ) ? $enums[ $path ][ $sv ] + 1 : 1;
			}

			if ( is_array( $v ) ) {
				$this->collect_paths( $v, $path, $paths, $enums, $depth + 1 );
			}
		}
	}

	private function normalize_key( $k ) {
		$k = (string) $k;
		if ( ctype_digit( $k ) || strlen( $k ) > 24 || preg_match( '/^[a-f0-9]{8,}$/i', $k ) ) {
			return '*';
		}
		// Identyfikatory z prefiksem, np. "ci_65a1b2c3d4" -> "ci_*".
		if ( preg_match( '/^([a-z]{1,6}_)([A-Za-z0-9]{6,})$/', $k, $m ) && preg_match( '/\d/', $m[2] ) ) {
			return $m[1] . '*';
		}
		// Losowe tokeny bez podkreślników, zawierające cyfry i litery.
		if ( preg_match( '/^[A-Za-z0-9]{10,}$/', $k ) && preg_match( '/\d/', $k ) && preg_match( '/[a-z]/i', $k ) ) {
			return '*';
		}
		return $k;
	}

	/**
	 * Wyciąga z cart_items_data listę elementów z polami rezerwacji.
	 * Obsługuje: listę elementów, mapę id=>element, oraz element z polem item_data (JSON).
	 */
	private function extract_cart_items( $cart ) {
		if ( ! is_array( $cart ) ) {
			return array();
		}
		$candidates = isset( $cart['service_id'] ) ? array( $cart ) : $cart;
		$items      = array();
		foreach ( $candidates as $c ) {
			if ( is_string( $c ) ) {
				$c = json_decode( $c, true );
			}
			if ( ! is_array( $c ) ) {
				continue;
			}
			foreach ( array( 'item_data', 'booking_data', 'booking' ) as $nested ) {
				if ( ! isset( $c['service_id'] ) && isset( $c[ $nested ] ) ) {
					$inner = is_string( $c[ $nested ] ) ? json_decode( $c[ $nested ], true ) : $c[ $nested ];
					if ( is_array( $inner ) ) {
						$c = array_merge( $c, $inner );
					}
				}
			}
			if ( ! empty( $c['service_id'] ) ) {
				$items[] = $c;
			}
		}
		return $items;
	}

	private function latepoint_plugin_dirs() {
		static $dirs = null;
		if ( null !== $dirs ) {
			return $dirs;
		}
		$dirs = array();
		foreach ( (array) glob( trailingslashit( WP_PLUGIN_DIR ) . '*', GLOB_ONLYDIR ) as $d ) {
			if ( false !== stripos( basename( $d ), 'latepoint' ) ) {
				$dirs[] = wp_normalize_path( $d );
			}
		}
		return $dirs;
	}

	private function latepoint_php_files() {
		static $files = null;
		if ( null !== $files ) {
			return $files;
		}
		$files = array();
		foreach ( $this->latepoint_plugin_dirs() as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				/** @var SplFileInfo $f */
				if ( 'php' === strtolower( $f->getExtension() ) && $f->getSize() < 2 * 1024 * 1024 && false === strpos( wp_normalize_path( $f->getPathname() ), '/vendor/' ) ) {
					$files[] = $f->getPathname();
				}
			}
		}
		sort( $files );
		return $files;
	}

	private function path_in_dirs( $file, array $dirs ) {
		$file = wp_normalize_path( $file );
		foreach ( $dirs as $d ) {
			if ( 0 === strpos( $file, trailingslashit( $d ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function call_args_sample( $code, $offset ) {
		$snippet = substr( $code, $offset, 240 );
		$end     = strpos( $snippet, ');' );
		if ( false !== $end ) {
			$snippet = substr( $snippet, 0, $end + 1 );
		}
		return preg_replace( '/\s+/', ' ', $snippet );
	}

	private function callback_name( $cb ) {
		if ( is_string( $cb ) ) {
			return $cb;
		}
		if ( is_array( $cb ) && 2 === count( $cb ) ) {
			return ( is_object( $cb[0] ) ? get_class( $cb[0] ) : (string) $cb[0] ) . '::' . $cb[1];
		}
		if ( $cb instanceof Closure ) {
			$f = $this->callback_file( $cb );
			return 'Closure' . ( $f ? ' in ' . $this->strip_paths( $f ) : '' );
		}
		return is_object( $cb ) ? get_class( $cb ) : gettype( $cb );
	}

	private function callback_file( $cb ) {
		try {
			if ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
				$cb = explode( '::', $cb, 2 );
			}
			if ( is_array( $cb ) && 2 === count( $cb ) ) {
				return ( new ReflectionMethod( $cb[0], $cb[1] ) )->getFileName();
			}
			if ( is_string( $cb ) && function_exists( $cb ) ) {
				return ( new ReflectionFunction( $cb ) )->getFileName();
			}
			if ( $cb instanceof Closure ) {
				return ( new ReflectionFunction( $cb ) )->getFileName();
			}
		} catch ( \Throwable $e ) {
			return null;
		}
		return null;
	}

	private function strip_paths( $s ) {
		return str_replace( array( wp_normalize_path( ABSPATH ), ABSPATH ), '', (string) $s );
	}
}
