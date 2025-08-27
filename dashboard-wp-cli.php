<?php
/*
Plugin Name: dashboard-wp-cli
Plugin URI:
Description: WP-CLI Plugin For WordPress.
Version: 1.0.1
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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
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
        
        wp_enqueue_script('jquery');
        wp_localize_script('jquery', 'wpcli_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpcli_nonce')
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
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var commandHistory = [];
            var historyIndex = -1;
            var isExecuting = false;
            
            // ターミナルにテキストを追加
            function addToTerminal(text, className) {
                className = className || '';
                var $output = $('#terminal-output');
                var $line = $('<div class="terminal-line ' + className + '"></div>').text(text);
                $output.append($line);
                $output.scrollTop($output[0].scrollHeight);
            }
            
            // プロンプト行を追加
            function addPromptLine(command) {
                addToTerminal('wp> ' + command, 'terminal-command');
            }
            
            // WP-CLIコマンド実行
            function executeCommand(command) {
                if (isExecuting || !command.trim()) {
                    return;
                }
                
                isExecuting = true;
                addPromptLine(command);
                addToTerminal('実行中...', 'terminal-loading');
                $('#terminal-input').prop('disabled', true);
                
                // コマンド履歴に追加
                commandHistory.push(command);
                historyIndex = commandHistory.length;
                
                $.ajax({
                    url: wpcli_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'execute_wpcli',
                        command: command,
                        nonce: wpcli_ajax.nonce
                    },
                    success: function(response) {
                        // "実行中..." の行を削除
                        $('#terminal-output .terminal-loading').last().remove();
                        
                        if (response.success) {
                            var output = '';
                            if (response.data.output) {
                                output = response.data.output;
                            } else {
                                // デバッグ情報を表示
                                output = 'デバッグ情報:\n';
                                output += 'コマンド: ' + response.data.command + '\n';
                                output += '完全コマンド: ' + response.data.full_command + '\n';
                                output += 'WP-CLIパス: ' + response.data.wp_cli_path + '\n';
                                output += 'WordPress パス: ' + response.data.abspath + '\n';
                                output += '実行結果: ' + (response.data.output || '(出力なし)');
                            }
                            
                            // 複数行の出力を処理
                            output.split('\n').forEach(function(line) {
                                addToTerminal(line, 'terminal-output-line');
                            });
                        } else {
                            addToTerminal('エラー: ' + response.data, 'terminal-error');
                        }
                    },
                    error: function() {
                        $('#terminal-output .terminal-loading').last().remove();
                        addToTerminal('通信エラーが発生しました。', 'terminal-error');
                    },
                    complete: function() {
                        isExecuting = false;
                        $('#terminal-input').prop('disabled', false).focus();
                    }
                });
            }
            
            // Enterキーでコマンド実行
            $('#terminal-input').on('keydown', function(e) {
                var $input = $(this);
                
                if (e.keyCode === 13) { // Enter
                    e.preventDefault();
                    var command = $input.val().trim();
                    if (command) {
                        executeCommand(command);
                        $input.val('');
                    }
                } else if (e.keyCode === 38) { // 上矢印 - 履歴を戻る
                    e.preventDefault();
                    if (historyIndex > 0) {
                        historyIndex--;
                        $input.val(commandHistory[historyIndex] || '');
                    }
                } else if (e.keyCode === 40) { // 下矢印 - 履歴を進む
                    e.preventDefault();
                    if (historyIndex < commandHistory.length - 1) {
                        historyIndex++;
                        $input.val(commandHistory[historyIndex] || '');
                    } else {
                        historyIndex = commandHistory.length;
                        $input.val('');
                    }
                }
            });
            
            // ターミナル領域をクリックしたときに入力欄にフォーカス
            $('#terminal-container').on('click', function() {
                if (!isExecuting) {
                    $('#terminal-input').focus();
                }
            });
            
            // 初期メッセージ
            addToTerminal('WP-CLI Terminal - WordPressコマンドライン実行環境', 'terminal-info');
            addToTerminal('使用例: option list, user list, plugin list など', 'terminal-info');
            addToTerminal('セキュリティ: db exportファイルは安全なディレクトリに保存されます', 'terminal-info');
            addToTerminal('', '');
            
            // 初期フォーカス
            $('#terminal-input').focus();
            
            // WP-CLIダウンロード
            $('#download-wpcli-btn').on('click', function() {
                if (!confirm('WP-CLI pharファイル（約3MB）をダウンロードしますか？')) {
                    return;
                }
                
                $('#download-wpcli-btn').prop('disabled', true);
                $('#download-loading').show();
                
                $.ajax({
                    url: wpcli_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'download_wpcli',
                        nonce: wpcli_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('WP-CLIのダウンロードが完了しました。ページを再読み込みしてください。');
                            location.reload();
                        } else {
                            alert('ダウンロードに失敗しました: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('通信エラーが発生しました。');
                    },
                    complete: function() {
                        $('#download-wpcli-btn').prop('disabled', false);
                        $('#download-loading').hide();
                    }
                });
            });
        });
        </script>
        
        <style>
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        /* 全体のレイアウト調整 */
        .wrap {
            max-width: none;
            margin: 0;
            width: 100%;
        }
        
        .card {
            margin: 0;
            padding: 20px;
            width: 100%;
            box-sizing: border-box;
        }
        
        /* ターミナル風スタイル */
        #terminal-container {
            background: #1e1e1e;
            color: #d4d4d4;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            border-radius: 8px;
            padding: 20px;
            margin: 15px -20px 0 -20px;
            min-height: 500px;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            width: calc(100% + 40px);
            box-sizing: border-box;
        }
        
        #terminal-output {
            flex: 1;
            overflow-y: auto;
            margin-bottom: 10px;
            padding: 10px 0;
        }
        
        .terminal-line {
            margin: 2px 0;
            white-space: pre-wrap;
            word-break: break-word;
        }
        
        .terminal-command {
            color: #569cd6;
            font-weight: bold;
        }
        
        .terminal-output-line {
            color: #d4d4d4;
        }
        
        .terminal-error {
            color: #f48771;
        }
        
        .terminal-info {
            color: #4ec9b0;
        }
        
        .terminal-loading {
            color: #ffce50;
            animation: blink 1s infinite;
        }
        
        @keyframes blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0.3; }
        }
        
        #terminal-input-line {
            display: flex;
            align-items: center;
            border-top: 1px solid #333;
            padding-top: 10px;
        }
        
        #terminal-prompt {
            color: #569cd6;
            font-weight: bold;
            margin-right: 8px;
            user-select: none;
        }
        
        #terminal-input {
            flex: 1;
            background: transparent;
            border: none;
            color: #d4d4d4;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            outline: none;
            padding: 0;
        }
        
        #terminal-input::placeholder {
            color: #666;
        }
        
        #terminal-cursor {
            color: #d4d4d4;
            animation: cursor-blink 1s infinite;
            margin-left: 2px;
        }
        
        @keyframes cursor-blink {
            0%, 50% { opacity: 1; }
            51%, 100% { opacity: 0; }
        }
        
        /* スクロールバーの見た目を調整 */
        #terminal-output::-webkit-scrollbar {
            width: 8px;
        }
        
        #terminal-output::-webkit-scrollbar-track {
            background: #2d2d2d;
            border-radius: 4px;
        }
        
        #terminal-output::-webkit-scrollbar-thumb {
            background: #666;
            border-radius: 4px;
        }
        
        #terminal-output::-webkit-scrollbar-thumb:hover {
            background: #888;
        }
        </style>
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
        $wp_cli_path = $this->get_wpcli_path();
        if (!$wp_cli_path) {
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
        
        $full_command = $env_vars . $wp_cli_path . ' ' . $escaped_command . ' --path=' . escapeshellarg(ABSPATH) . ' --no-color 2>&1';
        
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
        
        // デバッグ情報を追加
        $debug_info = array(
            'command' => $command,
            'full_command' => $full_command,
            'wp_cli_path' => $wp_cli_path,
            'abspath' => ABSPATH,
            'output' => $output
        );
        
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
        $wp_cli_path = $this->get_wpcli_path();
        if ($wp_cli_path) {
            return true;
        }
        
        return false;
    }
    
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
        
        foreach ($phar_paths as $phar_path) {
            if (file_exists($phar_path) && is_readable($phar_path)) {
                $test_command = $php_binary . ' ' . escapeshellarg($phar_path) . ' --version 2>/dev/null';
                $output = @shell_exec($test_command);
                if ($output && strpos($output, 'WP-CLI') !== false) {
                    return $php_binary . ' ' . escapeshellarg($phar_path);
                }
            }
        }
        
        return false;
    }
    
    private function get_php_binary() {
        // PHP実行可能ファイルのパスを特定
        $php_paths = array();
        
        // PHP_BINARYが定義されている場合は最優先
        if (defined('PHP_BINARY') && !empty(PHP_BINARY)) {
            $php_paths[] = PHP_BINARY;
        }
        
        // 一般的なPHPパスを追加
        $php_paths = array_merge($php_paths, array(
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/opt/alt/php74/usr/bin/php',
            '/opt/alt/php80/usr/bin/php',
            '/opt/alt/php81/usr/bin/php',
            '/opt/alt/php82/usr/bin/php',
            'php'
        ));
        
        foreach ($php_paths as $php_path) {
            if (!empty($php_path)) {
                $test_command = $php_path . ' --version 2>/dev/null';
                $output = @shell_exec($test_command);
                if ($output && strpos($output, 'PHP') !== false) {
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
        
        // Pharファイルのマジックナンバーは通常 "<?ph" で始まる
        if (substr($magic, 0, 3) !== '<?p') {
            // または単純にPHPファイルとして開始する場合
            if (substr($magic, 0, 2) !== '<?') {
                return false;
            }
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
        
        // ファイル名を生成（タイムスタンプ付き）
        $timestamp = date('Y-m-d_H-i-s');
        $filename = 'database_export_' . $timestamp . '.sql';
        $export_path = $export_dir . '/' . $filename;
        
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
        // プラグインディレクトリ内に安全なエクスポート用ディレクトリを作成
        $plugin_dir = plugin_dir_path(__FILE__);
        $export_dir = $plugin_dir . 'exports';
        
        // ディレクトリが存在しない場合は作成
        if (!file_exists($export_dir)) {
            if (!wp_mkdir_p($export_dir)) {
                return false;
            }
            
            // .htaccessファイルを作成してWebアクセスを拒否
            $htaccess_content = "Order deny,allow\nDeny from all\n";
            file_put_contents($export_dir . '/.htaccess', $htaccess_content);
            
            // index.phpファイルを作成
            $index_content = "<?php\n// Silence is golden.\n";
            file_put_contents($export_dir . '/index.php', $index_content);
        }
        
        return $export_dir;
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

