<?php
/**
 * 公開トップの出し分け（メンテナンス ⇄ 実アプリ）。
 *
 * production_hold_enabled.flag の内容で、`/baseball/`（DirectoryIndex）の表示を切り替える。
 *   - "true"（または 1 / on / yes、大小文字不問） : メンテ中 → index-maint.html（アップデート準備中ページ）を出力
 *   - "false" / 空 / その他 / ファイル不在        : 公開     → index.html（実アプリ）を出力
 *
 * CLAUDE.md セクション 3.6 参照。
 */

// フラグファイルを読み込む
$flagFile = __DIR__ . '/production_hold_enabled.flag';
$isHoldEnabled = false;

if (file_exists($flagFile)) {
  $flagContent = trim(file_get_contents($flagFile));
  // 大小文字不問で判定
  $flagContent = strtolower($flagContent);
  if ($flagContent === 'true' || $flagContent === '1' ||
      $flagContent === 'on' || $flagContent === 'yes') {
    $isHoldEnabled = true;
  }
}

// フラグに基づいて出し分け
if ($isHoldEnabled) {
  // メンテ中：index-maint.html（メンテナンス画面）を出力
  readfile(__DIR__ . '/index-maint.html');
} else {
  // 公開：index.html（実アプリ）を出力
  readfile(__DIR__ . '/index.html');
}
