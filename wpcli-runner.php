<?php
/**
 * In-process WP-CLI runner for shell-less environments (e.g. WordPress Playground).
 *
 * WordPress Playground の PHP (WASM) では shell_exec / proc_open で
 * ホスト側のプロセスを起動できないため、この単独エンドポイントが
 * wp-cli.phar を同一 PHP プロセス内で include して実行する。
 *
 * このファイルは WordPress をロードせずに直接アクセスされる。
 * WordPress の認証は使えないため、AJAX ハンドラー（権限 + nonce 検証済み）が
 * 発行したワンタイムトークンファイルで認可する。コマンド内容はトークン
 * ファイル側に保存されたものだけを実行し、リクエストパラメータからは受け取らない。
 *
 * @package DashboardWPCLI
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions, WordPress.PHP.DevelopmentFunctions, WordPress.PHP.IniSet -- WordPress 非ロードの単独エンドポイントのため WP API は使用できない.

// WordPress 経由で include された場合は何もしない（直接アクセス専用）.
if ( defined( 'ABSPATH' ) ) {
	exit;
}

// Playground では Web リクエストでも PHP_SAPI が 'cli' になる。
// 通常のサーバー（fpm / apache 等）ではこのランナーは動作させない.
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo 'This endpoint is only available in WordPress Playground.';
	exit;
}

header( 'Content-Type: text/plain; charset=utf-8' );
ini_set( 'html_errors', '0' );

/**
 * Abort with a 403 and a plain-text message.
 *
 * @param string $message Error message.
 * @return void
 */
function dashboard_wpcli_runner_forbidden( $message ) {
	http_response_code( 403 );
	echo $message;
	exit;
}

$dashboard_wpcli_token = isset( $_REQUEST['token'] ) ? (string) $_REQUEST['token'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ワンタイムトークンで認可する.
if ( ! preg_match( '/^[a-f0-9]{64}$/', $dashboard_wpcli_token ) ) {
	dashboard_wpcli_runner_forbidden( 'Invalid token.' );
}

$dashboard_wpcli_token_file = sys_get_temp_dir() . '/dashboard-wpcli-' . $dashboard_wpcli_token . '.json';
if ( ! is_file( $dashboard_wpcli_token_file ) ) {
	dashboard_wpcli_runner_forbidden( 'Unknown or expired token.' );
}

$dashboard_wpcli_payload = json_decode( (string) file_get_contents( $dashboard_wpcli_token_file ), true );

// ワンタイム利用: 実行前に必ず削除してリプレイを防ぐ.
unlink( $dashboard_wpcli_token_file );

if ( ! is_array( $dashboard_wpcli_payload )
	|| empty( $dashboard_wpcli_payload['argv'] )
	|| ! is_array( $dashboard_wpcli_payload['argv'] )
	|| empty( $dashboard_wpcli_payload['phar'] )
	|| empty( $dashboard_wpcli_payload['created'] )
) {
	dashboard_wpcli_runner_forbidden( 'Invalid token payload.' );
}

if ( time() - (int) $dashboard_wpcli_payload['created'] > 120 ) {
	dashboard_wpcli_runner_forbidden( 'Token expired.' );
}

$dashboard_wpcli_phar = (string) $dashboard_wpcli_payload['phar'];
if ( 'wp-cli.phar' !== basename( $dashboard_wpcli_phar ) || ! is_file( $dashboard_wpcli_phar ) ) {
	dashboard_wpcli_runner_forbidden( 'WP-CLI phar not found.' );
}

// WP-CLI 用に CLI 環境をエミュレートする.
$dashboard_wpcli_argv = array_values( array_map( 'strval', $dashboard_wpcli_payload['argv'] ) );

$_SERVER['argv'] = $dashboard_wpcli_argv;
$_SERVER['argc'] = count( $dashboard_wpcli_argv );
$GLOBALS['argv'] = $dashboard_wpcli_argv;
$GLOBALS['argc'] = count( $dashboard_wpcli_argv );

if ( ! defined( 'STDIN' ) ) {
	define( 'STDIN', fopen( 'php://stdin', 'r' ) );
}
if ( ! defined( 'STDOUT' ) ) {
	define( 'STDOUT', fopen( 'php://stdout', 'w' ) );
}
if ( ! defined( 'STDERR' ) ) {
	// エラー出力もレスポンスに含めてターミナルに表示できるようにする.
	define( 'STDERR', fopen( 'php://stdout', 'w' ) );
}

set_time_limit( 300 );

// phar スタブ先頭の shebang 行が出力に混ざるので取り除く.
ob_start(
	function ( $buffer ) {
		return preg_replace( '/^#!.*\n/', '', $buffer );
	}
);

require $dashboard_wpcli_phar;
