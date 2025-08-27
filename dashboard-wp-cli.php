<?php
/*
Plugin Name: dashboard-wp-cli
Plugin URI:
Description: WP-CLI Plugin For WordPress.
Version: 1.0.0
Author: 
Author URI: 
License: GPLv2 or later
*/
 
 
if (!defined('ABSPATH')) {
    exit;
}

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
        ?>
        <div class="wrap">
            <h1>WP-CLI Dashboard</h1>
            <div class="card">
                <h2>WP-CLIコマンドを実行</h2>
                <?php if ($this->is_wpcli_available()): ?>
                <div class="notice notice-success">
                    <p>✅ WP-CLIが利用可能です</p>
                </div>
                <?php else: ?>
                <div class="notice notice-warning">
                    <p>⚠️ WP-CLIが見つかりません。</p>
                    <p>
                        <button type="button" id="download-wpcli-btn" class="button button-secondary">
                            WP-CLI pharファイルをダウンロード
                        </button>
                        <span id="download-loading" style="display:none;">ダウンロード中...</span>
                    </p>
                    <p><small>または<a href="https://wp-cli.org/#installing" target="_blank">手動でWP-CLIをインストール</a>してください。</small></p>
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
        if (!current_user_can('manage_options')) {
            wp_die(__('権限がありません。'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'wpcli_nonce')) {
            wp_die(__('セキュリティチェックに失敗しました。'));
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
            wp_send_json_error('WP-CLIが利用できません。WP-CLIをインストールするか、wp-cli.pharファイルをWordPressルートディレクトリに配置してください。');
        }
        
        // コマンドを個別の引数として分割
        $command_parts = explode(' ', $command);
        $escaped_parts = array_map('escapeshellarg', $command_parts);
        $escaped_command = implode(' ', $escaped_parts);
        
        $full_command = $wp_cli_path . ' ' . $escaped_command . ' --path=' . escapeshellarg(ABSPATH) . ' 2>&1';
        
        $output = @shell_exec($full_command);
        
        if ($output === null) {
            wp_send_json_error('コマンドの実行に失敗しました。サーバーの設定でshell_exec()が無効になっている可能性があります。');
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
        if (!current_user_can('manage_options')) {
            wp_die(__('権限がありません。'));
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'wpcli_nonce')) {
            wp_die(__('セキュリティチェックに失敗しました。'));
        }
        
        $plugin_dir = plugin_dir_path(__FILE__);
        $phar_path = $plugin_dir . 'wp-cli.phar';
        
        // 既にファイルが存在する場合は削除
        if (file_exists($phar_path)) {
            unlink($phar_path);
        }
        
        // WP-CLI pharファイルをダウンロード
        $download_url = 'https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar';
        
        $response = wp_remote_get($download_url, array(
            'timeout' => 120,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ));
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            wp_send_json_error('ダウンロードに失敗しました: ' . $error_msg . ' URLを確認してください: ' . $download_url);
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
        $result = file_put_contents($phar_path, $file_content);
        if ($result === false) {
            wp_send_json_error('ファイルの保存に失敗しました。');
        }
        
        // ファイルの実行権限を設定
        chmod($phar_path, 0755);
        
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
        // まずプラグインディレクトリ内のwp-cli.pharをチェック
        $plugin_phar_path = plugin_dir_path(__FILE__) . 'wp-cli.phar';
        if (file_exists($plugin_phar_path)) {
            $test_command = 'php ' . escapeshellarg($plugin_phar_path) . ' --version 2>/dev/null';
            $output = @shell_exec($test_command);
            if ($output && strpos($output, 'WP-CLI') !== false) {
                return 'php ' . escapeshellarg($plugin_phar_path);
            }
        }
        
        // 一般的なWP-CLIのパスをチェック
        $paths = array(
            '/usr/local/bin/wp',
            '/usr/bin/wp',
            '/opt/homebrew/bin/wp',
            getcwd() . '/wp-cli.phar',
            'wp'
        );
        
        foreach ($paths as $path) {
            if (is_executable($path) || $path === 'wp') {
                $test_command = $path . ' --version 2>/dev/null';
                $output = @shell_exec($test_command);
                if ($output && strpos($output, 'WP-CLI') !== false) {
                    return $path;
                }
            }
        }
        
        // WordPress内でWP-CLI pharファイルを探す
        $phar_paths = array(
            ABSPATH . 'wp-cli.phar',
            dirname(ABSPATH) . '/wp-cli.phar'
        );
        
        foreach ($phar_paths as $phar_path) {
            if (file_exists($phar_path)) {
                $test_command = 'php ' . escapeshellarg($phar_path) . ' --version 2>/dev/null';
                $output = @shell_exec($test_command);
                if ($output && strpos($output, 'WP-CLI') !== false) {
                    return 'php ' . escapeshellarg($phar_path);
                }
            }
        }
        
        return false;
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
}

new DashboardWPCLI();

