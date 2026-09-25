<?php
/**
 * Strona Narzędzia → PWN Recovery Audit.
 * Raport jest liczony na żądanie i nie jest nigdzie zapisywany.
 */

defined( 'ABSPATH' ) || exit;

final class PWN_Audit_Admin {

	const CAP    = 'manage_options';
	const SLUG   = 'pwn-recovery-audit';
	const ACTION = 'pwn_recovery_audit_download';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'download' ) );
	}

	public static function menu() {
		add_management_page( 'PWN Recovery Audit', 'PWN Recovery Audit', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'pwn-recovery-audit' ) );
		}

		$run    = isset( $_POST['pwn_audit_run'] ) && check_admin_referer( 'pwn_audit_run' );
		$report = $run ? ( new PWN_Audit_Runner() )->run() : null;
		?>
		<div class="wrap">
			<h1>PWN Recovery Audit (tylko odczyt)</h1>
			<p>Narzędzie wykonuje wyłącznie zapytania <code>SELECT</code>/<code>SHOW</code>. Nie zapisuje niczego w bazie i nie wysyła danych poza serwer.
			Raport zawiera strukturę tabel, nazwy hooków, liczniki i rozkłady statusów, bez imion, e-maili, telefonów i treści.</p>
			<p>Przed przesłaniem raportu przejrzyj go. Po zakończeniu audytu dezaktywuj i usuń tę wtyczkę.</p>

			<form method="post" style="display:inline-block;margin-right:8px">
				<?php wp_nonce_field( 'pwn_audit_run' ); ?>
				<input type="hidden" name="pwn_audit_run" value="1" />
				<?php submit_button( 'Uruchom audyt', 'primary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php submit_button( 'Pobierz raport JSON', 'secondary', 'submit', false ); ?>
			</form>

			<?php if ( $report ) : ?>
				<h2>Wynik</h2>
				<textarea readonly style="width:100%;height:70vh;font-family:monospace;font-size:12px"><?php echo esc_textarea( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?></textarea>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function download() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'pwn-recovery-audit' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$report = ( new PWN_Audit_Runner() )->run();
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pwn-recovery-audit-' . gmdate( 'Ymd-His' ) . '.json"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
