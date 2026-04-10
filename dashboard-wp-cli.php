<?php
/*
Plugin Name: dashboard-wp-cli
Plugin URI:
Description: WP-CLI Plugin For WordPress.
Version: 1.1.0
Author: 
Author URI: 
License: GPLv2 or later
*/
 
 
if (!defined('ABSPATH')) {
    exit;
}

// エラー報告を抑制してサーバー環境での問題を回避
error_reporting(E_ERROR | E_PARSE);

class DashboardWPCLI {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_execute_wpcli', array($this, 'execute_wpcli_command'));
        add_action('wp_ajax_download_wpcli', array($this, 'download_wpcli_phar'));
        add_action('wp_ajax_cleanup_exports', array($this, 'cleanup_exports_directory'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'cleanup_on_page_change'));
    }
    
    public function add_admin_menu() {
        add_management_page(
            'WP-CLI Dashboard',
            'WP-CLI',
            'manage_options',
            'dashboard-wpcli',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_dashboard-wpcli') {
            return;
        }

        $plugin_url = plugin_dir_url(__FILE__);
        $plugin_path = plugin_dir_path(__FILE__);

        wp_enqueue_style(
            'dashboard-wpcli-admin',
            $plugin_url . 'assets/css/admin.css',
            array(),
            filemtime($plugin_path . 'assets/css/admin.css')
        );

        wp_enqueue_script(
            'dashboard-wpcli-admin',
            $plugin_url . 'assets/js/admin.js',
            array('jquery'),
            filemtime($plugin_path . 'assets/js/admin.js'),
            true
        );

        wp_localize_script('dashboard-wpcli-admin', 'wpcli_ajax', array(
            'ajax_url'      => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('wpcli_nonce'),
            'cleanup_nonce' => wp_create_nonce('wpcli_cleanup_nonce'),
        ));
    }
    
    public function admin_page() {
        // 管理画面表示時のエラーハンドリング
        try {
            $is_wpcli_available = $this->is_wpcli_available();
        } catch (Exception $e) {
            $is_wpcli_available = false;
            error_log('WP-CLI Plugin Error: ' . $e->getMessage());
        }
        ?>
        <div class="wrap">
            <h1>WP-CLI Dashboard</h1>
            <div class="card">
                <h2>WP-CLIコマンドを実行</h2>
                <?php if ($is_wpcli_available): ?>
                <div class="notice notice-success">
                    <p>✅ WP-CLIが利用可能です</p>
                </div>
                <?php else: ?>
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
    
    public function execute_wpcli_command() {
        // 基本的なセキュリティチェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpcli_nonce')) {
            wp_send_json_error('セキュリティチェックに失敗しました。');
            return;
        }
        
        $command = sanitize_text_field($_POST['command']);
        
        if (empty($command)) {
            wp_send_json_error('コマンドが空です。');
        }
        
        $dangerous_commands = array('rm', 'del', 'format', 'sudo', 'chmod', 'chown', '>', '>>', '|', '&', ';');
        foreach ($dangerous_commands as $dangerous) {
            if (strpos($command, $dangerous) !== false) {
                wp_send_json_error('危険なコマンドは実行できません。');
            }
        }
        
        // db exportコマンドの安全な処理
        $command = $this->process_db_export_command($command);
        
        // WP-CLIクラスが利用可能な場合は直接実行
        if (class_exists('WP_CLI')) {
            try {
                ob_start();
                $result = WP_CLI::runcommand($command, array(
                    'return' => 'all',
                    'parse' => 'json'
                ));
                $output = ob_get_clean();
                
                if (isset($result->stdout)) {
                    $output .= $result->stdout;
                }
                if (isset($result->stderr)) {
                    $output .= $result->stderr;
                }
                
                wp_send_json_success(array('output' => $output));
            } catch (Exception $e) {
                wp_send_json_error('WP-CLIコマンドの実行中にエラーが発生しました: ' . $e->getMessage());
            }
        }
        
        // コマンドライン版のWP-CLIを実行
        $wp_cli = $this->get_wpcli_path();
        if (!$wp_cli) {
            wp_send_json_error('WP-CLI pharファイルが見つかりません。「WP-CLI pharファイルをダウンロード」ボタンをクリックしてwp-cli.pharをダウンロードしてください。');
        }

        // コマンドを個別の引数として分割
        $command_parts = explode(' ', $command);
        $escaped_parts = array_map('escapeshellarg', $command_parts);
        $escaped_command = implode(' ', $escaped_parts);

        // 環境変数を設定してコマンド実行
        $env_vars = '';
        if (function_exists('putenv')) {
            $env_vars = 'COLUMNS=120 ';
        }

        // DB_HOST=localhost の場合、PHP CLI のデフォルトソケットが Local WP のソケットと
        // 異なるため mysqli.default_socket を -d オプションで上書きする。
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

        // コマンド実行時のタイムアウト設定
        $old_time_limit = ini_get('max_execution_time');
        if ($old_time_limit < 300) {
            @ini_set('max_execution_time', 300);
        }

        $output = @shell_exec($full_command);

        // タイムアウトを元に戻す
        if ($old_time_limit < 300) {
            @ini_set('max_execution_time', $old_time_limit);
        }

        if ($output === null) {
            wp_send_json_error('コマンドの実行に失敗しました。サーバーの設定でshell_exec()が無効になっているか、PHPの実行時間制限に達した可能性があります。');
        }
        
        // 空の出力の場合のハンドリング
        if (trim($output) === '') {
            $output = '(コマンドが実行されましたが、出力はありませんでした)';
        }
        
        // db exportコマンドの場合は追加のデバッグ情報を収集
        $debug_info = array(
            'command' => $command,
            'full_command' => $full_command,
            'wp_cli_path' => $wp_cli_path,
            'abspath' => ABSPATH,
            'output' => $output
        );
        
        // db exportコマンドの場合のみ追加情報
        if (strpos($command, 'db export') !== false) {
            $upload_dir = wp_upload_dir();
            $export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';
            
            $debug_info['export_dir'] = $export_dir;
            $debug_info['export_dir_exists'] = file_exists($export_dir);
            $debug_info['export_dir_writable'] = is_writable($export_dir);
            $debug_info['upload_dir_info'] = $upload_dir;
            
            // 作成されたファイルがあるかチェック
            if (file_exists($export_dir)) {
                $files = scandir($export_dir);
                $debug_info['export_files'] = array_diff($files, array('.', '..'));
            }
            
            // データベース接続情報の確認
            $debug_info['db_host'] = DB_HOST;
            $debug_info['db_name'] = DB_NAME;
            $debug_info['db_user'] = DB_USER;
            $debug_info['db_charset'] = DB_CHARSET;
        }
        
        wp_send_json_success($debug_info);
    }
    
    public function download_wpcli_phar() {
        // 基本的なセキュリティチェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpcli_nonce')) {
            wp_send_json_error('セキュリティチェックに失敗しました。');
            return;
        }
        
        $plugin_dir = plugin_dir_path(__FILE__);
        $phar_path = $plugin_dir . 'wp-cli.phar';
        
        // 既にファイルが存在する場合は削除
        if (file_exists($phar_path)) {
            if (!@unlink($phar_path)) {
                wp_send_json_error('既存のファイルを削除できませんでした。ファイルの権限を確認してください。');
            }
        }
        
        // ディレクトリが書き込み可能かチェック
        $plugin_dir = plugin_dir_path(__FILE__);
        if (!is_writable($plugin_dir)) {
            wp_send_json_error('プラグインディレクトリに書き込み権限がありません: ' . $plugin_dir);
        }
        
        // WP-CLI pharファイルをダウンロード
        $download_url = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
        
        $response = wp_remote_get($download_url, array(
            'timeout' => 120,
            'sslverify' => false, // ローカル環境でのSSL問題を回避
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            'headers' => array(
                'Accept' => 'application/octet-stream'
            )
        ));
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            $error_code = $response->get_error_code();
            
            // 具体的なエラーメッセージを提供
            if (strpos($error_code, 'http_request_failed') !== false) {
                $error_msg .= ' ネットワーク接続を確認してください。wp-envのローカル環境では外部接続が制限されている可能性があります。';
            }
            
            wp_send_json_error('ダウンロードに失敗しました: ' . $error_msg);
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $response_message = wp_remote_retrieve_response_message($response);
            wp_send_json_error('ダウンロードに失敗しました。HTTPステータス: ' . $response_code . ' (' . $response_message . ')');
        }
        
        $file_content = wp_remote_retrieve_body($response);
        if (empty($file_content)) {
            wp_send_json_error('ダウンロードしたファイルが空です。サーバーの応答を確認してください。');
        }
        
        if (strlen($file_content) < 500000) { // 500KB未満は異常
            wp_send_json_error('ダウンロードしたファイルのサイズが小さすぎます (' . number_format(strlen($file_content)) . ' bytes)。');
        }
        
        // ファイルを保存
        $result = @file_put_contents($phar_path, $file_content, LOCK_EX);
        if ($result === false) {
            $error_msg = 'ファイルの保存に失敗しました。';
            if (!is_writable(dirname($phar_path))) {
                $error_msg .= ' ディレクトリに書き込み権限がありません。';
            }
            if (disk_free_space(dirname($phar_path)) < strlen($file_content)) {
                $error_msg .= ' ディスク容量が不足しています。';
            }
            wp_send_json_error($error_msg);
        }
        
        // ファイルの実行権限を設定（エラーハンドリング付き）
        if (!@chmod($phar_path, 0755)) {
            // 権限変更に失敗してもダウンロードは続行
            error_log('WP-CLI Plugin: Could not set permissions for ' . $phar_path);
        }
        
        // ダウンロードしたファイルが正常なPharファイルかチェック
        if (!$this->is_valid_phar_file($phar_path)) {
            unlink($phar_path);
            wp_send_json_error('ダウンロードしたファイルは正常なPharファイルではありません。');
        }
        
        // WP-CLIファイルの基本的な検証（ファイルサイズとマジックナンバー）
        $file_size = filesize($phar_path);
        if ($file_size < 1000000) { // 1MB未満は異常
            unlink($phar_path);
            wp_send_json_error('ダウンロードしたファイルのサイズが異常です。');
        }
        
        wp_send_json_success('WP-CLIのダウンロードが完了しました。');
    }
    
    private function is_wpcli_available() {
        // WP-CLIクラスが利用可能かチェック
        if (class_exists('WP_CLI')) {
            return true;
        }

        // コマンドライン版のWP-CLIをチェック
        $wp_cli = $this->get_wpcli_path();
        if ($wp_cli) {
            return true;
        }

        return false;
    }

    /**
     * WP-CLI の PHP バイナリと phar パスを返す。
     * 戻り値: array( 'php' => escaped_php, 'phar' => escaped_phar ) or false
     */
    private function get_wpcli_path() {
        // PHP実行可能パスを取得
        $php_binary = $this->get_php_binary();

        // pharファイルのみを使用する（サーバーインストール版は使わない）
        $phar_paths = array(
            // プラグインディレクトリ内（最優先）
            plugin_dir_path(__FILE__) . 'wp-cli.phar',
            // WordPressルート
            ABSPATH . 'wp-cli.phar',
            // WordPressの親ディレクトリ
            dirname(ABSPATH) . '/wp-cli.phar',
            // ドキュメントルート
            $_SERVER['DOCUMENT_ROOT'] . '/wp-cli.phar',
            // 現在のディレクトリ
            getcwd() . '/wp-cli.phar'
        );

        $escaped_php = escapeshellarg( $php_binary );

        foreach ($phar_paths as $phar_path) {
            if (file_exists($phar_path) && is_readable($phar_path)) {
                $test_command = $escaped_php . ' ' . escapeshellarg($phar_path) . ' --version 2>/dev/null';
                $output = @shell_exec($test_command);
                $phar_ok = ( $output && strpos($output, 'WP-CLI') !== false )
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
     * DB 接続に使用している Unix ソケットパスを返す（なければ空文字）。
     */
    private function get_db_socket() {
        global $wpdb;
        $row = $wpdb->get_row( "SHOW VARIABLES LIKE 'socket'" );
        if ( $row && ! empty( $row->Value ) && file_exists( $row->Value ) ) {
            return $row->Value;
        }
        return '';
    }
    
    private function get_php_binary() {
        // PHP_BINARY が php-fpm の場合は CLI バイナリを探す（Local WP 対応）。
        // Local WP では php-fpm は .../sbin/php-fpm、CLI は .../bin/php に存在する。
        // shell_exec が制限されている環境でも is_executable() で直接確認する。
        if ( defined( 'PHP_BINARY' ) && ! empty( PHP_BINARY ) ) {
            $php_bin = PHP_BINARY;
            if ( basename( $php_bin ) === 'php-fpm' ) {
                $sbin_dir      = dirname( $php_bin );
                $base_dir      = dirname( $sbin_dir );
                $cli_candidate = $base_dir . '/bin/php';
                if ( is_executable( $cli_candidate ) ) {
                    return $cli_candidate;
                }
            } elseif ( is_executable( $php_bin ) && strpos( $php_bin, 'php-fpm' ) === false ) {
                return $php_bin;
            }
        }

        // 一般的な PHP CLI パスを追加。
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
                $output       = @shell_exec( $test_command ); // phpcs:ignore
                if ( $output && strpos( $output, 'PHP' ) !== false && strpos( $output, 'php-fpm' ) === false ) {
                    return $php_path;
                }
            }
        }

        return 'php';
    }
    
    private function is_valid_phar_file($file_path) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        // Pharファイルのマジックナンバーをチェック
        $handle = fopen($file_path, 'rb');
        if (!$handle) {
            return false;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        // WP-CLI pharファイルは shebang または PHPタグで始まる。
        $is_shebang = substr( $magic, 0, 2 ) === '#!';
        $is_php_tag = substr( $magic, 0, 2 ) === '<?';
        $is_zip     = substr( $magic, 0, 4 ) === "\x50\x4b\x03\x04";

        if ( ! $is_shebang && ! $is_php_tag && ! $is_zip ) {
            return false;
        }
        
        // ファイル内容に"WP-CLI"文字列が含まれているかチェック（簡易的な検証）
        $content_sample = file_get_contents($file_path, false, null, 0, 10000);
        if (strpos($content_sample, 'WP-CLI') === false && strpos($content_sample, 'wp-cli') === false) {
            return false;
        }
        
        return true;
    }
    
    private function process_db_export_command($command) {
        // db exportコマンドかチェック
        if (!preg_match('/^db\s+export/', $command)) {
            return $command;
        }
        
        // 安全なエクスポートディレクトリを作成
        $export_dir = $this->get_secure_export_directory();
        if (!$export_dir) {
            wp_send_json_error('エクスポート用ディレクトリの作成に失敗しました。');
        }
        
        // ディレクトリの存在と書き込み権限を再確認
        if (!file_exists($export_dir)) {
            if (!wp_mkdir_p($export_dir)) {
                wp_send_json_error('エクスポートディレクトリの作成に失敗しました: ' . $export_dir);
            }
        }
        
        if (!is_writable($export_dir)) {
            wp_send_json_error('エクスポートディレクトリに書き込み権限がありません: ' . $export_dir);
        }
        
        // ファイル名を生成（タイムスタンプ付き）
        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'database_export_' . $timestamp . '.sql';
        $export_path = $export_dir . '/' . $filename;
        
        // パスの正規化（ダブルスラッシュなどを修正）
        $export_path = str_replace('//', '/', $export_path);
        
        // コマンドにファイルパスが指定されていない場合は追加
        if (!preg_match('/\s+[^\s]+\.sql(\s|$)/', $command)) {
            $command .= ' ' . escapeshellarg($export_path);
        } else {
            // 既存のパスを安全なパスに置換
            $command = preg_replace('/(\s+)([^\s]+\.sql)(\s|$)/', '$1' . escapeshellarg($export_path) . '$3', $command);
        }
        
        return $command;
    }
    
    private function get_secure_export_directory() {
        // wp-uploadsディレクトリ内にエクスポート用ディレクトリを作成
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';
        
        // パスの正規化
        $export_dir = str_replace('\\', '/', $export_dir);
        $export_dir = rtrim($export_dir, '/');
        
        // ディレクトリが存在しない場合は作成
        if (!file_exists($export_dir)) {
            // 権限を明示的に設定してディレクトリ作成
            if (!wp_mkdir_p($export_dir)) {
                error_log('WP-CLI Plugin: Failed to create export directory: ' . $export_dir);
                return false;
            }
            
            // ディレクトリの権限を設定
            @chmod($export_dir, 0755);
            
            // .htaccessファイルを作成してWebアクセスを拒否
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            $htaccess_result = @file_put_contents($export_dir . '/.htaccess', $htaccess_content);
            if ($htaccess_result === false) {
                error_log('WP-CLI Plugin: Failed to create .htaccess file in: ' . $export_dir);
            }
            
            // index.phpファイルを作成
            $index_content = "<?php\n// Silence is golden.\n";
            $index_result = @file_put_contents($export_dir . '/index.php', $index_content);
            if ($index_result === false) {
                error_log('WP-CLI Plugin: Failed to create index.php file in: ' . $export_dir);
            }
        }
        
        // ディレクトリが書き込み可能かチェック
        if (!is_writable($export_dir)) {
            @chmod($export_dir, 0755);
            if (!is_writable($export_dir)) {
                error_log('WP-CLI Plugin: Export directory is not writable: ' . $export_dir);
                return false;
            }
        }
        
        return $export_dir;
    }
    
    public function cleanup_exports_directory() {
        // セキュリティチェック
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
            return;
        }
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wpcli_cleanup_nonce')) {
            wp_send_json_error('セキュリティチェックに失敗しました。');
            return;
        }
        
        $upload_dir = wp_upload_dir();
        $export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';
        
        if (file_exists($export_dir)) {
            $this->recursive_remove_directory($export_dir);
            wp_send_json_success('エクスポートディレクトリを削除しました。');
        } else {
            wp_send_json_success('エクスポートディレクトリは存在しません。');
        }
    }
    
    public function cleanup_on_page_change() {
        // 現在のページがプラグインページでない場合、exportsフォルダを削除
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';
        
        if (is_admin() && $current_page !== 'dashboard-wpcli') {
            // セッションでプラグインページにアクセスしていたかチェック
            if (isset($_SESSION['wpcli_accessed']) || get_transient('wpcli_page_accessed_' . get_current_user_id())) {
                $upload_dir = wp_upload_dir();
                $export_dir = $upload_dir['basedir'] . '/wp-cli-plugin-export';
                
                if (file_exists($export_dir)) {
                    $this->recursive_remove_directory($export_dir);
                }
                
                // セッション/トランジェントをクリア
                unset($_SESSION['wpcli_accessed']);
                delete_transient('wpcli_page_accessed_' . get_current_user_id());
            }
        } elseif ($current_page === 'dashboard-wpcli') {
            // プラグインページにアクセス中であることを記録
            set_transient('wpcli_page_accessed_' . get_current_user_id(), true, HOUR_IN_SECONDS);
        }
    }
    
    private function recursive_remove_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->recursive_remove_directory($path);
            } else {
                @unlink($path);
            }
        }
        
        return @rmdir($dir);
    }
}

// プラグインの初期化（エラーハンドリング付き）
try {
    new DashboardWPCLI();
} catch (Exception $e) {
    error_log('WP-CLI Plugin Initialization Error: ' . $e->getMessage());
    // 致命的なエラーの場合でも管理画面を表示できるように
    if (is_admin()) {
        add_action('admin_notices', function() use ($e) {
            echo '<div class="notice notice-error"><p>WP-CLI Plugin Error: ' . esc_html($e->getMessage()) . '</p></div>';
        });
    }
}

