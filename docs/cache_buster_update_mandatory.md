# キャッシュバスター更新義務ルール

最終更新: 2026-06-28

この文書は、PWA/ブラウザ/Service Worker の古い資産キャッシュによる不整合を防ぐための必須ルールです。

## 絶対ルール

- `version.json` の `app_version` / `cache_version` を更新するリリースでは、下記のキャッシュバスター参照をすべて同じ数字へ更新する。
- CSS、JavaScript、JSON、画像、Service Worker、ゲームHTMLのいずれかを変更した場合も、原則として同じリリース番号へキャッシュバスターを更新する。
- `index.html` と `app_shell.html` は同じゲームHTML系入口として扱い、片方だけ更新してはいけない。
- `service-worker.js` の `CACHE_VERSION` と `STATIC_ASSETS` は、`version.json` の `cache_version` と必ず同期する。
- `assets/app.js` 内の `fetch(...?v=...)` と画像URLテンプレートも、HTMLやService Workerと同じキャッシュ番号へ同期する。
- すべての `?v=` が最新番号に統一されていない状態で、コミット、push、PR、デプロイをしてはいけない。

例: `v1098-production` の場合、クエリはすべて `?v=1098`、キャッシュ版は `yakyu-yarouze-v1098-production` に統一する。

## 更新必須箇所

### `version.json`

| 項目 | 必須値の例 |
|---|---|
| `app_version` | `v1098-production` |
| `cache_version` | `yakyu-yarouze-v1098-production` |
| `public_version` | 公開版番号 |
| `updated_at` / `release_date` | リリース日 |
| `change_summary` | 変更内容 |

### `index.html`

| 箇所 | 必須値の例 |
|---|---|
| `<meta name="app-version">` | `v1098-production` |
| `assets/styles.css?v=...` | `assets/styles.css?v=1098` |
| 先読みスプライト画像 `assets/sprite_*.webp?v=...` | すべて `?v=1098` |
| `assets/quiz_master_questions.js?v=...` | `assets/quiz_master_questions.js?v=1098` |
| `assets/app.js?v=...` | `assets/app.js?v=1098` |
| `window.YAKYU_APP_VERSION` | `v1098-production` |
| `window.YAKYU_CACHE_VERSION` | `yakyu-yarouze-v1098-production` |

### `app_shell.html`

段階リリース中の秘密URL用ゲームHTMLです。`index.html` と同じ項目を必ず更新する。

| 箇所 | 必須値の例 |
|---|---|
| `<meta name="app-version">` | `v1098-production` |
| `assets/styles.css?v=...` | `assets/styles.css?v=1098` |
| 先読みスプライト画像 `assets/sprite_*.webp?v=...` | すべて `?v=1098` |
| `assets/quiz_master_questions.js?v=...` | `assets/quiz_master_questions.js?v=1098` |
| `assets/app.js?v=...` | `assets/app.js?v=1098` |
| `window.YAKYU_APP_VERSION` | `v1098-production` |
| `window.YAKYU_CACHE_VERSION` | `yakyu-yarouze-v1098-production` |

### `service-worker.js`

| 箇所 | 必須値の例 |
|---|---|
| `const CACHE_VERSION` | `yakyu-yarouze-v1098-production` |
| `./assets/styles.css?v=...` | `./assets/styles.css?v=1098` |
| `./assets/app.js?v=...` | `./assets/app.js?v=1098` |
| `./assets/quiz_master_questions.js?v=...` | `./assets/quiz_master_questions.js?v=1098` |
| `./data/quiz_master_questions.json?v=...` | `./data/quiz_master_questions.json?v=1098` |
| `./data/quiz_master_titles.json?v=...` | `./data/quiz_master_titles.json?v=1098` |

### `assets/app.js`

| 箇所 | 必須値の例 |
|---|---|
| `api/get_game_questions.php?v=...` | `api/get_game_questions.php?v=1098` |
| `data/questions_admin_master.json?v=...` | `data/questions_admin_master.json?v=1098` |
| `data/questions.json?v=...` | `data/questions.json?v=1098` |
| `data/game_config.json?v=...` | `data/game_config.json?v=1098` |
| `data/quiz_master_questions.json?v=...` | `data/quiz_master_questions.json?v=1098` |
| `api/get_quiz_master_question_stats.php?v=...` | `api/get_quiz_master_question_stats.php?v=1098` |
| `api/get_quiz_master_titles.php?v=...` | `api/get_quiz_master_titles.php?v=1098` |
| `assets/sprite_*.webp?v=...` を返すURLテンプレート | すべて `?v=1098` |

## 変更不要の例外

- 問題IDや参照IDとしての `BQ1094`、`BQ1016` などはキャッシュバスターではないため変更しない。
- 文章中の履歴、監査メモ、過去バージョン名は、キャッシュ参照でなければ変更しない。
- `manifest.webmanifest` は通常 `?v=` を持たない。内容変更時だけ通常の差分として扱う。

## コミット前必須チェック

PowerShellで以下を実行し、`?v=` がすべて最新番号だけになっていることを確認する。

```powershell
$expected = "1098"

Write-Host "=== version.json ==="
$version = Get-Content version.json | ConvertFrom-Json
$version | Select-Object app_version, cache_version, public_version, updated_at

Write-Host "`n=== all cache busters ==="
rg -n "\?v=" index.html app_shell.html service-worker.js assets\app.js

Write-Host "`n=== unexpected ?v= values ==="
$matches = Select-String -Path index.html,app_shell.html,service-worker.js,assets\app.js -Pattern "\?v=([0-9]+)" -AllMatches
$bad = foreach($m in $matches){
  foreach($hit in $m.Matches){
    if($hit.Groups[1].Value -ne $expected){
      [pscustomobject]@{
        Path = $m.Path
        Line = $m.LineNumber
        Value = $hit.Groups[1].Value
        Text = $m.Line.Trim()
      }
    }
  }
}
if($bad){
  $bad | Format-Table -AutoSize
  throw "Cache buster mismatch found."
}
"OK: all ?v= values are $expected"

Write-Host "`n=== app/cache version strings ==="
Select-String -Path version.json,index.html,app_shell.html,service-worker.js -Pattern "v$expected-production|yakyu-yarouze-v$expected-production|CACHE_VERSION|YAKYU_APP_VERSION|YAKYU_CACHE_VERSION|app-version"
```

加えて、構文チェックを必ず実行する。

```powershell
C:\Users\owner\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe --check assets\app.js
C:\Users\owner\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe --check service-worker.js
git diff --check
```

## PR / コミットに必ず書くこと

- 旧キャッシュ番号と新キャッシュ番号。
- 更新したファイル一覧。
- 上記チェックの実行結果。
- `scores/`、`requests/`、`vendor/` を変更していないこと。

## 禁止事項

- `index.html` だけ、または `app_shell.html` だけを更新して終えること。
- `service-worker.js` の `CACHE_VERSION` だけを更新し、`STATIC_ASSETS` の `?v=` を更新しないこと。
- `assets/app.js` 内のデータJSON/API取得URLの `?v=` を古いまま残すこと。
- `quiz_master_questions.js` と `data/quiz_master_questions.json` の片方だけを更新すること。
- バージョン整合性チェックを省略して push すること。
