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
                    if (response.data.output && response.data.output.trim() !== '' && response.data.output !== '(コマンドが実行されましたが、出力はありませんでした)') {
                        output = response.data.output;
                    } else {
                        // デバッグ情報を表示
                        output = 'デバッグ情報:\n';
                        output += 'コマンド: ' + response.data.command + '\n';
                        output += '完全コマンド: ' + response.data.full_command + '\n';
                        output += 'WP-CLIパス: ' + response.data.wp_cli_path + '\n';
                        output += 'WordPress パス: ' + response.data.abspath + '\n';
                        output += '実行結果: ' + (response.data.output || '(出力なし)') + '\n';

                        // db exportの追加デバッグ情報
                        if (response.data.export_dir) {
                            output += '\n--- db export デバッグ情報 ---\n';
                            output += 'エクスポートディレクトリ: ' + response.data.export_dir + '\n';
                            output += 'ディレクトリ存在: ' + (response.data.export_dir_exists ? 'Yes' : 'No') + '\n';
                            output += '書き込み可能: ' + (response.data.export_dir_writable ? 'Yes' : 'No') + '\n';
                            output += 'DB Host: ' + response.data.db_host + '\n';
                            output += 'DB Name: ' + response.data.db_name + '\n';
                            output += 'DB User: ' + response.data.db_user + '\n';

                            if (response.data.export_files && response.data.export_files.length > 0) {
                                output += 'エクスポートされたファイル: ' + response.data.export_files.join(', ') + '\n';
                            } else {
                                output += 'エクスポートされたファイル: なし\n';
                            }
                        }
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
    addToTerminal('セキュリティ: db exportファイルは安全なディレクトリに保存され、画面離脱時に自動削除されます', 'terminal-info');
    addToTerminal('', '');

    // 初期フォーカス
    $('#terminal-input').focus();

    // ページ離脱時のクリーンアップ
    $(window).on('beforeunload pagehide', function() {
        $.ajax({
            url: wpcli_ajax.ajax_url,
            type: 'POST',
            async: false,
            data: {
                action: 'cleanup_exports',
                nonce: wpcli_ajax.cleanup_nonce
            }
        });
    });

    // 他のWordPress管理ページへのリンククリック時
    $('a:not([href*="page=dashboard-wpcli"])').on('click', function(e) {
        var href = $(this).attr('href');
        if (href && (href.indexOf('/wp-admin/') !== -1 || href.indexOf('wp-admin') !== -1)) {
            $.ajax({
                url: wpcli_ajax.ajax_url,
                type: 'POST',
                async: false,
                data: {
                    action: 'cleanup_exports',
                    nonce: wpcli_ajax.cleanup_nonce
                }
            });
        }
    });

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
