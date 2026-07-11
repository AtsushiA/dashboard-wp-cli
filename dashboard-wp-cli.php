<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: dashboard-wp-cli
 * Plugin URI:
 * Description: WP-CLI Plugin For WordPress.
 * Version: 1.3.0
 * Author:
 * Author URI:
 * License: GPLv2 or later
 * Text Domain: dashboard-wp-cli
 *
 * @package DashboardWPCLI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
error_reporting( E_ERROR | E_PARSE );

/**
 * WP-CLI Dashboard plugin main class.
 */
class DashboardWPCLI {

	/**
	 * Constructor: register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'wp_ajax_execute_wpcli', array( $this, 'execute_wpcli_command' ) );
		add_action( 'wp_ajax_download_wpcli', array( $this, 'download_wpcli_phar' ) );
		add_action( 'wp_ajax_cleanup_exports', array( $this, 'cleanup_exports_directory' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', array( $this, 'cleanup_on_page_change' ) );
	}

	/**
	 * Register the admin menu page.
	 */
	public function add_admin_menu() {
		add_management_page(
			'WP-CLI Dashboard',
			'WP-CLI',
			'manage_options',
			'dashboard-wpcli',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Enqueue plugin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'tools_page_dashboard-wpcli' !== $hook ) {
			return;
		}

		$plugin_url  = plugin_dir_url( __FILE__ );
		$plugin_path = plugin_dir_path( __FILE__ );

		wp_enqueue_style(
			'dashboard-wpcli-admin',
			$plugin_url . 'assets/css/admin.css',
			array(),
			filemtime( $plugin_path . 'assets/css/admin.css' )
		);

		wp_enqueue_script(
			'dashboard-wpcli-admin',
			$plugin_url . 'assets/js/admin.js',
			array( 'jquery' ),
			filemtime( $plugin_path . 'assets/js/admin.js' ),
			true
		);

		wp_localize_script(
			'dashboard-wpcli-admin',
			'wpcli_ajax',
			array(
				'ajax_url'      => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wpcli_nonce' ),
				'cleanup_nonce' => wp_create_nonce( 'wpcli_cleanup_nonce' ),
			)
		);
	}

	/**
	 * Render the admin page.
	 */
	public function admin_page() {
		// 管理画面表示時のエラーハンドリング.
		try {
			$is_wpcli_available = $this->is_wpcli_available();
		} catch ( Exception $e ) {
			$is_wpcli_available = false;
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'WP-CLI Plugin Error: ' . $e->getMessage() );
		}
		?>
		<div class="wrap dashboard-wpcli-wrap">
			<div class="dashboard-wpcli-header">
				<h1>WP-CLI Dashboard</h1>
				<button type="button"
						id="dashboard-wpcli-theme-toggle"
						class="button dashboard-wpcli-theme-toggle"
						aria-pressed="false">
					<span class="dashboard-wpcli-theme-toggle-icon" aria-hidden="true"></span>
					<span class="dashboard-wpcli-theme-toggle-label"><?php esc_html_e( 'ダークモード', 'dashboard-wp-cli' ); ?></span>
				</button>
			</div>
			<script>
			// ダークモードの初期状態をなるべく早く反映し、ページ読み込み直後の
			// チラつき（FOUC）を抑える.
			// is-dark の付与と aria-pressed の更新を同時に行い、フッターの jQuery 実行を
			// 待たずにボタンの状態（押下状態）が背景の明暗とズレないようにする.
			// aria-pressed をセットするため、ボタンがパース済みになるヘッダーの直後に配置する.
			( function () {
				try {
					if ( window.localStorage && 'dark' === window.localStorage.getItem( 'dashboardWpcliColorScheme' ) ) {
						var wrap = document.currentScript.closest( '.dashboard-wpcli-wrap' );
						if ( wrap ) {
							wrap.classList.add( 'is-dark' );
							var toggle = wrap.querySelector( '#dashboard-wpcli-theme-toggle' );
							if ( toggle ) {
								toggle.setAttribute( 'aria-pressed', 'true' );
							}
						}
					}
				} catch ( e ) {
					// プライベートブラウジング等で localStorage が使用できない場合は何もしない.
				}
			} )();
			</script>
			<div class="card">
				<h2>WP-CLIコマンドを実行</h2>
				<?php if ( $is_wpcli_available ) : ?>
				<div class="notice notice-success">
					<p>✅ WP-CLIが利用可能です</p>
				</div>
				<?php else : ?>
				<div class="notice notice-warning">
					<p>⚠️ WP-CLI pharファイルが見つかりません。</p>
					<p>
						<button type="button" id="download-wpcli-btn" class="button button-secondary">
							WP-CLI pharファイルをダウンロード
						</button>
						<span id="download-loading" style="display:none;">ダウンロード中...</span>
					</p>
					<p><small>このプラグインはpharファイル版のWP-CLIのみを使用します。</small></p>
				</div>
				<?php endif; ?>

				<?php if ( $this->is_playground_environment() ) : ?>
				<div class="notice notice-warning">
					<p>WordPress Playground環境で実行しています。この環境はSQLiteで動作しているため、db コマンド（db export、db optimize など）は使用できません。</p>
				</div>
				<?php endif; ?>

				<div id="terminal-container">
					<div id="terminal-output"></div>
					<div id="terminal-input-line">
						<span id="terminal-prompt">wp&gt; </span>
						<input type="text"
								id="terminal-input"
								autocomplete="off"
								placeholder="WP-CLIコマンドを入力してください (例: option list)" />
						<span id="terminal-cursor">_</span>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: execute a WP-CLI command.
	 */
	public function execute_wpcli_command() {
		// 基本的なセキュリティチェック.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
			return;
		}

		$nonce = isset( $_POST['nonce'] ) ? wp_unslash( $_POST['nonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $nonce, 'wpcli_nonce' ) ) {
			wp_send_json_error( 'セキュリティチェックに失敗しました。' );
			return;
		}

		$command = isset( $_POST['command'] ) ? sanitize_text_field( wp_unslash( $_POST['command'] ) ) : '';

		if ( empty( $command ) ) {
			wp_send_json_error( 'コマンドが空です。' );
		}

		$dangerous_commands = array( 'rm', 'del', 'format', 'sudo', 'chmod', 'chown', '>', '>>', '|', '&', ';' );
		foreach ( $dangerous_commands as $dangerous ) {
			if ( strpos( $command, $dangerous ) !== false ) {
				wp_send_json_error( '危険なコマンドは実行できません。' );
			}
		}

		// WordPress Playground など shell 実行不可の環境では、
		// ワンタイムトークンを発行してインプロセスランナー（wpcli-runner.php）に委譲する.
		if ( $this->is_playground_environment() ) {
			// Playground は SQLite で動作しており、db コマンドは mysqldump 等に依存するため実行できない.
			if ( preg_match( '/^db(\s|$)/', $command ) ) {
				wp_send_json_error( 'WordPress Playground環境ではdbコマンドは使用できません。この環境はSQLiteで動作しているため、mysqldump等に依存するdbコマンド（db export、db optimizeなど）は実行できません。' );
			}

			$phar_path = $this->find_wpcli_phar();
			if ( ! $phar_path ) {
				wp_send_json_error( 'WP-CLI pharファイルが見つかりません。「WP-CLI pharファイルをダウンロード」ボタンをクリックしてwp-cli.pharをダウンロードしてください。' );
			}

			$token = $this->create_runner_token( $command, $phar_path );
			if ( ! $token ) {
				wp_send_json_error( '実行用ワンタイムトークンの発行に失敗しました。' );
			}

			wp_send_json_success(
				array(
					'command'      => $command,
					'runner_url'   => plugins_url( 'wpcli-runner.php', __FILE__ ),
					'runner_token' => $token,
				)
			);
		}

		// db export コマンドの安全な処理.
		$command = $this->process_db_export_command( $command );

		// WP-CLI クラスが利用可能な場合は直接実行.
		if ( class_exists( 'WP_CLI' ) ) {
			try {
				ob_start();
				$result = WP_CLI::runcommand(
					$command,
					array(
						'return' => 'all',
						'parse'  => 'json',
					)
				);
				$output = ob_get_clean();

				if ( isset( $result->stdout ) ) {
					$output .= $result->stdout;
				}
				if ( isset( $result->stderr ) ) {
					$output .= $result->stderr;
				}

				wp_send_json_success( array( 'output' => $output ) );
			} catch ( Exception $e ) {
				wp_send_json_error( 'WP-CLIコマンドの実行中にエラーが発生しました: ' . $e->getMessage() );
			}
		}

		// コマンドライン版の WP-CLI を実行.
		$wp_cli = $this->get_wpcli_path();
		if ( ! $wp_cli ) {
			wp_send_json_error( 'WP-CLI pharファイルが見つかりません。「WP-CLI pharファイルをダウンロード」ボタンをクリックしてwp-cli.pharをダウンロードしてください。' );
		}

		// コマンドを個別の引数として分割.
		$command_parts   = explode( ' ', $command );
		$escaped_parts   = array_map( 'escapeshellarg', $command_parts );
		$escaped_command = implode( ' ', $escaped_parts );

		// 環境変数を設定してコマンド実行.
		$env_vars = '';
		if ( function_exists( 'putenv' ) ) {
			$env_vars = 'COLUMNS=120 ';
		}

		// DB_HOST=localhost の場合、PHP CLI のデフォルトソケットが Local WP のソケットと
		// 異なるため mysqli.default_socket を -d オプションで上書きする.
		$php_ini_args = '';
		if ( ! defined( 'DB_HOST' ) || 'localhost' === DB_HOST ) {
			$socket = $this->get_db_socket();
			if ( $socket ) {
				$php_ini_args = ' -d ' . escapeshellarg( 'mysqli.default_socket=' . $socket );
			}
		}

		$full_command = $env_vars . $wp_cli['php'] . $php_ini_args . ' ' . $wp_cli['phar']
			. ' ' . $escaped_command
			. ' --path=' . escapeshellarg( ABSPATH )
			. ' --no-color 2>&1';

		// コマンド実行時のタイムアウト設定.
		$old_time_limit = (int) ini_get( 'max_execution_time' );
		if ( $old_time_limit < 300 ) {
			set_time_limit( 300 );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$output = shell_exec( $full_command );

		// タイムアウトを元に戻す.
		if ( $old_time_limit < 300 ) {
			set_time_limit( $old_time_limit );
		}

		if ( null === $output ) {
			wp_send_json_error( 'コマンドの実行に失敗しました。サーバーの設定でshell_exec()が無効になっているか、PHPの実行時間制限に達した可能性があります。' );
		}

		// 空の出力の場合のハンドリング.
		if ( '' === trim( $output ) ) {
			$output = '(コマンドが実行されましたが、出力はありませんでした)';
		}

		// レスポンスデータを組み立てる.
		$response_data = array(
			'command'      => $command,
			'full_command' => $full_command,
			'wp_cli_path'  => $wp_cli['phar'],
			'abspath'      => ABSPATH,
			'output'       => $output,
		);

		// db export コマンドの場合のみ追加情報.
		if ( false !== strpos( $command, 'db export' ) ) {
			$upload_dir = wp_upload_dir();
			$export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';

			$response_data['export_dir']          = $export_dir;
			$response_data['export_dir_exists']   = file_exists( $export_dir );
			$response_data['export_dir_writable'] = is_writable( $export_dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$response_data['upload_dir_info']     = $upload_dir;

			// 作成されたファイルがあるかチェック.
			if ( file_exists( $export_dir ) ) {
				$files                         = scandir( $export_dir );
				$response_data['export_files'] = array_diff( $files, array( '.', '..' ) );
			}

			// データベース接続情報の確認.
			$response_data['db_host']    = DB_HOST;
			$response_data['db_name']    = DB_NAME;
			$response_data['db_user']    = DB_USER;
			$response_data['db_charset'] = DB_CHARSET;
		}

		wp_send_json_success( $response_data );
	}

	/**
	 * AJAX handler: download wp-cli.phar.
	 */
	public function download_wpcli_phar() {
		// 基本的なセキュリティチェック.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
			return;
		}

		$nonce = isset( $_POST['nonce'] ) ? wp_unslash( $_POST['nonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $nonce, 'wpcli_nonce' ) ) {
			wp_send_json_error( 'セキュリティチェックに失敗しました。' );
			return;
		}

		$plugin_dir = plugin_dir_path( __FILE__ );
		$phar_path  = $plugin_dir . 'wp-cli.phar';

		// 既にファイルが存在する場合は削除.
		if ( file_exists( $phar_path ) ) {
			if ( ! wp_delete_file( $phar_path ) ) {
				wp_send_json_error( '既存のファイルを削除できませんでした。ファイルの権限を確認してください。' );
			}
		}

		// ディレクトリが書き込み可能かチェック.
		if ( ! is_writable( $plugin_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			wp_send_json_error( 'プラグインディレクトリに書き込み権限がありません: ' . $plugin_dir );
		}

		// WP-CLI phar ファイルをダウンロード.
		$download_url = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';

		$response = wp_remote_get(
			$download_url,
			array(
				'timeout'    => 120,
				'sslverify'  => false, // ローカル環境での SSL 問題を回避.
				'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
				'headers'    => array(
					'Accept' => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$error_msg  = $response->get_error_message();
			$error_code = $response->get_error_code();

			// 具体的なエラーメッセージを提供.
			if ( false !== strpos( $error_code, 'http_request_failed' ) ) {
				$error_msg .= ' ネットワーク接続を確認してください。wp-envのローカル環境では外部接続が制限されている可能性があります。';
			}

			wp_send_json_error( 'ダウンロードに失敗しました: ' . $error_msg );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			$response_message = wp_remote_retrieve_response_message( $response );
			wp_send_json_error( 'ダウンロードに失敗しました。HTTPステータス: ' . $response_code . ' (' . $response_message . ')' );
		}

		$file_content = wp_remote_retrieve_body( $response );
		if ( empty( $file_content ) ) {
			wp_send_json_error( 'ダウンロードしたファイルが空です。サーバーの応答を確認してください。' );
		}

		if ( strlen( $file_content ) < 500000 ) { // 500KB 未満は異常.
			wp_send_json_error( 'ダウンロードしたファイルのサイズが小さすぎます (' . number_format( strlen( $file_content ) ) . ' bytes)。' );
		}

		// ファイルを保存.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $phar_path, $file_content, LOCK_EX );
		if ( false === $result ) {
			$error_msg = 'ファイルの保存に失敗しました。';
			if ( ! is_writable( dirname( $phar_path ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				$error_msg .= ' ディレクトリに書き込み権限がありません。';
			}
			if ( disk_free_space( dirname( $phar_path ) ) < strlen( $file_content ) ) {
				$error_msg .= ' ディスク容量が不足しています。';
			}
			wp_send_json_error( $error_msg );
		}

		// ファイルの実行権限を設定（失敗してもダウンロードは続行）.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		chmod( $phar_path, 0755 );

		// ダウンロードしたファイルが正常な Phar ファイルかチェック.
		if ( ! $this->is_valid_phar_file( $phar_path ) ) {
			wp_delete_file( $phar_path );
			wp_send_json_error( 'ダウンロードしたファイルは正常なPharファイルではありません。' );
		}

		// WP-CLI ファイルの基本的な検証（ファイルサイズ）.
		$file_size = filesize( $phar_path );
		if ( $file_size < 1000000 ) { // 1MB 未満は異常.
			wp_delete_file( $phar_path );
			wp_send_json_error( 'ダウンロードしたファイルのサイズが異常です。' );
		}

		wp_send_json_success( 'WP-CLIのダウンロードが完了しました。' );
	}

	/**
	 * Check whether WP-CLI is available.
	 *
	 * @return bool
	 */
	private function is_wpcli_available() {
		// WP-CLI クラスが利用可能かチェック.
		if ( class_exists( 'WP_CLI' ) ) {
			return true;
		}

		// phar ファイルの存在をチェック（shell 実行不可の環境でも判定できるようにする）.
		if ( $this->find_wpcli_phar() ) {
			return true;
		}

		return false;
	}

	/**
	 * Return candidate paths for the wp-cli.phar file.
	 *
	 * @return string[]
	 */
	private function get_phar_candidate_paths() {
		// phar ファイルのみを使用する（サーバーインストール版は使わない）.
		return array(
			plugin_dir_path( __FILE__ ) . 'wp-cli.phar', // プラグインディレクトリ内（最優先）.
			ABSPATH . 'wp-cli.phar',                     // WordPress ルート.
			dirname( ABSPATH ) . '/wp-cli.phar',         // WordPress の親ディレクトリ.
			( isset( $_SERVER['DOCUMENT_ROOT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ) : '' ) . '/wp-cli.phar',  // ドキュメントルート.
			getcwd() . '/wp-cli.phar',                   // 現在のディレクトリ.
		);
	}

	/**
	 * Find a readable and valid wp-cli.phar without relying on shell access.
	 *
	 * @return string|false Raw phar path, or false if not found.
	 */
	private function find_wpcli_phar() {
		foreach ( $this->get_phar_candidate_paths() as $phar_path ) {
			if ( file_exists( $phar_path ) && is_readable( $phar_path ) && $this->is_valid_phar_file( $phar_path ) ) {
				return $phar_path;
			}
		}
		return false;
	}

	/**
	 * Check whether shell_exec() actually works in this environment.
	 *
	 * WordPress Playground (PHP WASM) では shell_exec は定義されているが
	 * 常に null を返すため、function_exists だけでは判定できない.
	 *
	 * @return bool
	 */
	private function is_shell_exec_working() {
		static $works = null;
		if ( null !== $works ) {
			return $works;
		}

		if ( ! function_exists( 'shell_exec' ) ) {
			$works = false;
			return $works;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
		$output = @shell_exec( 'echo dashboard_wpcli_shell_test' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disable_functions 環境では warning が出るため抑制する.
		$works  = is_string( $output ) && false !== strpos( $output, 'dashboard_wpcli_shell_test' );

		return $works;
	}

	/**
	 * Check whether the current request is running in a shell-less environment
	 * such as WordPress Playground (PHP WASM), where the in-process runner
	 * (wpcli-runner.php) is used instead of shell_exec().
	 *
	 * @return bool
	 */
	private function is_playground_environment() {
		return 'cli' === PHP_SAPI && ! $this->is_shell_exec_working();
	}

	/**
	 * Create a one-time token file for the in-process runner (wpcli-runner.php).
	 *
	 * ランナーは WordPress をロードせずに直接アクセスされるため、
	 * 認証済みのこのコンテキストで実行内容（argv）を確定させて
	 * 一時ファイルに保存し、トークンだけをクライアントに返す.
	 *
	 * @param string $command   Sanitized WP-CLI command (without leading "wp").
	 * @param string $phar_path Path to wp-cli.phar.
	 * @return string|false Token on success, false on failure.
	 */
	private function create_runner_token( $command, $phar_path ) {
		try {
			$token = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $e ) {
			return false;
		}

		$argv = array_merge(
			array( 'wp' ),
			array_values( array_filter( explode( ' ', $command ), 'strlen' ) ),
			array( '--path=' . ABSPATH, '--no-color' )
		);

		$payload = array(
			'argv'    => $argv,
			'phar'    => $phar_path,
			'created' => time(),
		);

		$token_file = sys_get_temp_dir() . '/dashboard-wpcli-' . $token . '.json';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = file_put_contents( $token_file, wp_json_encode( $payload ) );

		return ( false === $written ) ? false : $token;
	}

	/**
	 * Return WP-CLI PHP binary and phar path.
	 *
	 * @return array|false Array with 'php' and 'phar' keys (shell-escaped), or false if not found.
	 */
	private function get_wpcli_path() {
		// PHP 実行可能パスを取得.
		$php_binary = $this->get_php_binary();

		$phar_paths = $this->get_phar_candidate_paths();

		$escaped_php = escapeshellarg( $php_binary );

		foreach ( $phar_paths as $phar_path ) {
			if ( file_exists( $phar_path ) && is_readable( $phar_path ) ) {
				$test_command = $escaped_php . ' ' . escapeshellarg( $phar_path ) . ' --version 2>/dev/null';
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
				$output  = shell_exec( $test_command );
				$phar_ok = ( $output && false !== strpos( $output, 'WP-CLI' ) )
					|| $this->is_valid_phar_file( $phar_path );
				if ( $phar_ok ) {
					return array(
						'php'  => $escaped_php,
						'phar' => escapeshellarg( $phar_path ),
					);
				}
			}
		}

		return false;
	}

	/**
	 * Return the Unix socket path used by the current DB connection, or empty string if none.
	 *
	 * @return string
	 */
	private function get_db_socket() {
		global $wpdb;
		$row = $wpdb->get_row( "SHOW VARIABLES LIKE 'socket'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- MySQL returns column name "Value" with capital V.
		if ( $row && ! empty( $row->Value ) && file_exists( $row->Value ) ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			return $row->Value;
		}
		return '';
	}

	/**
	 * Detect the PHP CLI binary path.
	 *
	 * @return string
	 */
	private function get_php_binary() {
		// PHP_BINARY が php-fpm の場合は CLI バイナリを探す（Local WP 対応）.
		// Local WP では php-fpm は .../sbin/php-fpm、CLI は .../bin/php に存在する.
		// shell_exec が制限されている環境でも is_executable() で直接確認する.
		if ( defined( 'PHP_BINARY' ) && ! empty( PHP_BINARY ) ) {
			$php_bin = PHP_BINARY;
			if ( 'php-fpm' === basename( $php_bin ) ) {
				$sbin_dir      = dirname( $php_bin );
				$base_dir      = dirname( $sbin_dir );
				$cli_candidate = $base_dir . '/bin/php';
				if ( is_executable( $cli_candidate ) ) {
					return $cli_candidate;
				}
			} elseif ( is_executable( $php_bin ) && false === strpos( $php_bin, 'php-fpm' ) ) {
				return $php_bin;
			}
		}

		// 一般的な PHP CLI パス.
		$php_paths = array(
			'/usr/bin/php',
			'/usr/local/bin/php',
			'/opt/alt/php74/usr/bin/php',
			'/opt/alt/php80/usr/bin/php',
			'/opt/alt/php81/usr/bin/php',
			'/opt/alt/php82/usr/bin/php',
			'php',
		);

		foreach ( $php_paths as $php_path ) {
			if ( ! empty( $php_path ) ) {
				$test_command = escapeshellarg( $php_path ) . ' --version 2>/dev/null';
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
				$output = shell_exec( $test_command );
				if ( $output && false !== strpos( $output, 'PHP' ) && false === strpos( $output, 'php-fpm' ) ) {
					return $php_path;
				}
			}
		}

		return 'php';
	}

	/**
	 * Validate that a file is a legitimate WP-CLI phar.
	 *
	 * @param string $file_path Path to the file.
	 * @return bool
	 */
	private function is_valid_phar_file( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Phar ファイルのマジックナンバーをチェック.
		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		$magic = fread( $handle, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// WP-CLI phar ファイルは shebang または PHP タグで始まる.
		$is_shebang = '#!' === substr( $magic, 0, 2 );
		$is_php_tag = '<?' === substr( $magic, 0, 2 );
		$is_zip     = "\x50\x4b\x03\x04" === substr( $magic, 0, 4 );

		if ( ! $is_shebang && ! $is_php_tag && ! $is_zip ) {
			return false;
		}

		// ファイル内容に "WP-CLI" 文字列が含まれているかチェック（簡易的な検証）.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content_sample = file_get_contents( $file_path, false, null, 0, 10000 );
		if ( false === strpos( $content_sample, 'WP-CLI' ) && false === strpos( $content_sample, 'wp-cli' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Rewrite db export command to use a safe output directory.
	 *
	 * @param string $command The original command string.
	 * @return string
	 */
	private function process_db_export_command( $command ) {
		// db export コマンドかチェック.
		if ( ! preg_match( '/^db\s+export/', $command ) ) {
			return $command;
		}

		// 安全なエクスポートディレクトリを作成.
		$export_dir = $this->get_secure_export_directory();
		if ( ! $export_dir ) {
			wp_send_json_error( 'エクスポート用ディレクトリの作成に失敗しました。' );
		}

		// ディレクトリの存在と書き込み権限を再確認.
		if ( ! file_exists( $export_dir ) ) {
			if ( ! wp_mkdir_p( $export_dir ) ) {
				wp_send_json_error( 'エクスポートディレクトリの作成に失敗しました: ' . $export_dir );
			}
		}

		if ( ! is_writable( $export_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			wp_send_json_error( 'エクスポートディレクトリに書き込み権限がありません: ' . $export_dir );
		}

		// ファイル名を生成（タイムスタンプ付き）.
		$timestamp   = gmdate( 'Y-m-d_H-i-s' );
		$filename    = 'database_export_' . $timestamp . '.sql';
		$export_path = $export_dir . '/' . $filename;

		// パスの正規化（ダブルスラッシュなどを修正）.
		$export_path = str_replace( '//', '/', $export_path );

		// コマンドにファイルパスが指定されていない場合は追加.
		if ( ! preg_match( '/\s+[^\s]+\.sql(\s|$)/', $command ) ) {
			$command .= ' ' . escapeshellarg( $export_path );
		} else {
			// 既存のパスを安全なパスに置換.
			$command = preg_replace( '/(\s+)([^\s]+\.sql)(\s|$)/', '$1' . escapeshellarg( $export_path ) . '$3', $command );
		}

		return $command;
	}

	/**
	 * Create and return a secure directory for db export files.
	 *
	 * @return string|false Directory path, or false on failure.
	 */
	private function get_secure_export_directory() {
		// wp-uploads ディレクトリ内にエクスポート用ディレクトリを作成.
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';

		// パスの正規化.
		$export_dir = str_replace( '\\', '/', $export_dir );
		$export_dir = rtrim( $export_dir, '/' );

		// ディレクトリが存在しない場合は作成.
		if ( ! file_exists( $export_dir ) ) {
			// 権限を明示的に設定してディレクトリ作成.
			if ( ! wp_mkdir_p( $export_dir ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP-CLI Plugin: Failed to create export directory: ' . $export_dir );
				return false;
			}

			// ディレクトリの権限を設定.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $export_dir, 0755 );

			// .htaccess ファイルを作成して Web アクセスを拒否.
			$htaccess_content = "Order deny,allow\nDeny from all\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$htaccess_result = file_put_contents( $export_dir . '/.htaccess', $htaccess_content );
			if ( false === $htaccess_result ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP-CLI Plugin: Failed to create .htaccess file in: ' . $export_dir );
			}

			// index.php ファイルを作成.
			$index_content = "<?php\n// Silence is golden.\n";
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$index_result = file_put_contents( $export_dir . '/index.php', $index_content );
			if ( false === $index_result ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP-CLI Plugin: Failed to create index.php file in: ' . $export_dir );
			}
		}

		// ディレクトリが書き込み可能かチェック.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		if ( ! is_writable( $export_dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod
			chmod( $export_dir, 0755 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			if ( ! is_writable( $export_dir ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'WP-CLI Plugin: Export directory is not writable: ' . $export_dir );
				return false;
			}
		}

		return $export_dir;
	}

	/**
	 * AJAX handler: delete the exports directory.
	 */
	public function cleanup_exports_directory() {
		// セキュリティチェック.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( '権限がありません。' );
			return;
		}

		$nonce = isset( $_POST['nonce'] ) ? wp_unslash( $_POST['nonce'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! wp_verify_nonce( $nonce, 'wpcli_cleanup_nonce' ) ) {
			wp_send_json_error( 'セキュリティチェックに失敗しました。' );
			return;
		}

		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';

		if ( file_exists( $export_dir ) ) {
			$this->recursive_remove_directory( $export_dir );
			wp_send_json_success( 'エクスポートディレクトリを削除しました。' );
		} else {
			wp_send_json_success( 'エクスポートディレクトリは存在しません。' );
		}
	}

	/**
	 * On admin page change, clean up the exports directory.
	 */
	public function cleanup_on_page_change() {
		// 現在のページがプラグインページでない場合、exports フォルダを削除.
		$current_page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( is_admin() && 'dashboard-wpcli' !== $current_page ) {
			// セッションでプラグインページにアクセスしていたかチェック.
			if ( isset( $_SESSION['wpcli_accessed'] ) || get_transient( 'wpcli_page_accessed_' . get_current_user_id() ) ) {
				$upload_dir = wp_upload_dir();
				$export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';

				if ( file_exists( $export_dir ) ) {
					$this->recursive_remove_directory( $export_dir );
				}

				// セッション/トランジェントをクリア.
				unset( $_SESSION['wpcli_accessed'] );
				delete_transient( 'wpcli_page_accessed_' . get_current_user_id() );
			}
		} elseif ( 'dashboard-wpcli' === $current_page ) {
			// プラグインページにアクセス中であることを記録.
			set_transient( 'wpcli_page_accessed_' . get_current_user_id(), true, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Recursively remove a directory and its contents.
	 *
	 * @param string $dir Directory path.
	 * @return bool
	 */
	private function recursive_remove_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . '/' . $file;
			if ( is_dir( $path ) ) {
				$this->recursive_remove_directory( $path );
			} else {
				wp_delete_file( $path );
			}
		}

		return rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}

// プラグインの初期化（エラーハンドリング付き）.
try {
	new DashboardWPCLI();
} catch ( Exception $e ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	error_log( 'WP-CLI Plugin Initialization Error: ' . $e->getMessage() );
	// 致命的なエラーの場合でも管理画面を表示できるように.
	if ( is_admin() ) {
		add_action(
			'admin_notices',
			function () use ( $e ) {
				echo '<div class="notice notice-error"><p>WP-CLI Plugin Error: ' . esc_html( $e->getMessage() ) . '</p></div>';
			}
		);
	}
}
