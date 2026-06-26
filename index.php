<?php
/**
 * 公開トップの出し分け（メンテナンス ⇄ 実アプリ）。
 *
 * production_hold_enabled.flag の内容で、`/baseball/`（DirectoryIndex）の表示を切り替える。
 *   - "true"（または 1 / on / yes、大小文字不問） : メンテ中 → index.html（アップデート準備中ページ）を出力
 *   - "false" / 空 / その他 / ファイル不在        : 公開     → app_shell.html（本番仕様の実アプリ）を出力
 *
 * 安全側デフォルト：フラグが無い・読めない場合は「公開」（= 実アプリ）。
 *   ※ 公開判定の安全側を「実アプリ」にしているのは、リリース後にフラグを削除しても
 *      実アプリが表示され続けるようにするため。メンテに固定したい場合は flag=true を明示すること。
 *
 * 実アプリのHTMLは app_shell.html（単一の正本）に集約し、index.php と
 * 秘密URL（index-preview-*.php）の双方が readfile で共有する。これにより
 * バージョン参照の二重管理による不整合を防ぐ。
 *
 * 運用メモ：
 *   - production_hold_enabled.flag は本番デプロイ(deploy.yml)でも配備される（既定 true）。
 *   - app_shell.html は .htaccess で直アクセス禁止（readfile はサーバー側のため影響なし）。
 *   - 詳細は docs/staged_release_preview.md / CLAUDE.md §3.6 を参照。
 */
$flagFile = __DIR__ . '/production_hold_enabled.flag';
$hold = false;
if (is_file($flagFile)) {
    $v = strtolower(trim((string) @file_get_contents($flagFile)));
    $hold = ($v === 'true' || $v === '1' || $v === 'on' || $v === 'yes');
}

header('Content-Type: text/html; charset=UTF-8');
// フラグ切替を即時反映するため、入口ドキュメント自体はブラウザキャッシュしない
// （アプリ本体のキャッシュは Service Worker と ?v= 版数で管理する）。
header('Cache-Control: no-store');

$target = $hold ? __DIR__ . '/index.html' : __DIR__ . '/app_shell.html';
readfile($target);
