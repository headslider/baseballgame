<?php
/**
 * 段階リリース用プレビュー（秘密URL）のアクセスゲート。
 *
 * production_hold_enabled.flag の内容で、この秘密URLの利用可否を制御する。
 *   - "true"（または 1 / on / yes）       : 利用可（本番仕様の実アプリを表示）
 *   - "false" / 空 / その他 / ファイル不在 : 利用不可（403を返し、案内のみ表示）
 *
 * 安全側デフォルト：フラグが無い・読めない場合は「利用不可」。
 *
 * 運用メモ：
 *   - production_hold_enabled.flag は本番デプロイ(deploy.yml)では除外され、
 *     サーバー上で手動管理する（FTPで作成・編集）。
 *   - 公開（リリース）後はフラグを false にする／削除すると秘密URLは無効化される。
 *   - 詳細は docs/staged_release_preview.md を参照。
 */
$flagFile = __DIR__ . '/production_hold_enabled.flag';
$enabled = false;
if (is_file($flagFile)) {
    $v = strtolower(trim((string) @file_get_contents($flagFile)));
    $enabled = ($v === 'true' || $v === '1' || $v === 'on' || $v === 'yes');
}
if (!$enabled) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    ?><!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>利用できません</title>
<style>
  *{box-sizing:border-box}
  html,body{margin:0;height:100%}
  body{font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans","Yu Gothic",Meiryo,sans-serif;background:#0a361a;color:#fff;display:flex;align-items:center;justify-content:center;padding:24px;min-height:100dvh}
  .card{width:min(520px,92vw);background:rgba(255,255,255,.96);color:#102030;border-radius:24px;padding:clamp(24px,5vw,40px);box-shadow:0 16px 44px rgba(0,0,0,.35);text-align:center}
  .card h1{font-size:clamp(20px,4.6vw,26px);margin:0 0 12px;color:#0b3a75}
  .card p{font-size:clamp(14px,3.4vw,16px);line-height:1.8;margin:0 0 8px;color:#33414f}
  .note{margin-top:16px;font-size:13px;color:#6c757d}
</style>
</head>
<body>
  <main class="card">
    <h1>このページは現在利用できません</h1>
    <p>プレビューは現在公開されていません。</p>
    <p class="note">© REALEMOTIONFACTORY.COM</p>
  </main>
</body>
</html><?php
    exit;
}
// production_hold_enabled.flag が true のときのみ到達。
// app_shell.html（ゲーム画面）を出力する。
// 秘密URLでは検索インデックス回避を HTTP ヘッダ（X-Robots-Tag）で付与する。
header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex, nofollow');
$filePath = __DIR__ . '/app_shell.html';
if (file_exists($filePath) && is_readable($filePath)) {
    readfile($filePath);
} else {
    http_response_code(500);
    echo '<html><head><meta charset="utf-8"><title>エラー</title></head><body>';
    echo '<h1>エラー</h1>';
    echo '<p>ファイルが見つかりません: ' . htmlspecialchars($filePath) . '</p>';
    echo '</body></html>';
}
