<?php
/**
 * 公開トップの出し分け（メンテナンス ⇄ 実アプリ）。
 *
 * production_hold_enabled.flag の内容で、`/baseball/`（DirectoryIndex）の表示を切り替える。
 *   - "true"（または 1 / on / yes、大小文字不問） : メンテ中 → index.html（アップデート準備中ページ）を出力
 *   - "false" / 空 / その他 / ファイル不在        : 公開     → index.html（本番仕様の実アプリ）を出力
 *
 * シンプル化：app_shell.html は廃止し、index.html が実アプリ・メンテ画面の両方を兼ねるようにした。
 * フラグは Java Script 側で参照される（app.js の quizMasterLimitActive() など）。
 */
readfile(__DIR__ . '/index.html');
