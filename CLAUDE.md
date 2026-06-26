# AGENTS.md / CLAUDE.md 運用ガイド
## 少年野球シミュレーター「野球やろうぜ！」

> **言語ルール**：このリポジトリに関する作業・報告・PR・Issueは、常に日本語で記載する。

| セクション | 内容 | PM視点の効果 |
|---|---|---|
| プロジェクト概要 | 目的・スコープ・技術スタック | エージェントの文脈理解 |
| ディレクトリマップ | フォルダ構成と各フォルダの役割 | ファイル探索の効率化 |
| ブランチ戦略 | 命名規則・PR運用・コミット方針 | 運用ルールの自動遵守 |
| タスク管理方針 | Issue／PRテンプレート・完了条件 | 成果物フォーマットの統一 |
| 品質基準 | テスト・レビュー・デプロイの基準 | 品質ゲートの自動適用 |
| 禁止事項 | 破壊的操作・本番データ・本番接続 | リスク排除 |

---

## 1. プロジェクト概要

### 目的
**野球やろうぜ！**は、小学生を主な対象に、少年野球の攻撃判断・守備判断・基本動作を学べる教育用PWAである。通常ゲームに加え、知識クイズモード「野球博士チャレンジ」を統合している。

### スコープ
- 通常ゲーム：攻撃・守備・基本動作の判断問題
- 野球博士チャレンジ：知識クイズ、デイリーライフ、ランキング、20段階称号
- プレイヤー登録、スコア保存、ランキング、マイページ、間違いチェック
- 管理画面、招待ID・管理者ID、要望管理、アカウント削除申請
- PWAキャッシュ、オフライン利用、Web Push通知

### 技術スタック
| 領域 | 構成 |
|---|---|
| フロントエンド | HTML / CSS / Vanilla JavaScript |
| バックエンド | PHP API |
| PWA | Service Worker / Web App Manifest |
| データ | JSON、CSV、サーバー上の運用データ |
| 通知 | Composer管理のWeb Pushライブラリ |
| ローカル起動 | `php -S localhost:8000` |
| 本番環境 | `/baseball/` |
| テスト環境 | `/baseball_test/` |
| ローカルURL | `http://localhost:8000/` |

### 現在の管理対象
- 本番版、キャッシュ版、公開版は `version.json` を正とする。
- フロントエンド更新時は、`index.html`、`service-worker.js`、`version.json` のバージョン・参照整合性を必ず確認する。
- ビルド工程は不要。PHP内蔵サーバーで直接動作確認する。

---

## 2. ディレクトリマップ

```text
/
├─ index.html                    # メインPWA、ゲーム、ランキング、マイページ
├─ admin.html                    # 最高位管理者用の管理画面
├─ manifest.webmanifest          # PWA設定
├─ service-worker.js             # キャッシュ、オフライン、Push通知
├─ version.json                  # app/cache/publicのバージョン管理
│
├─ assets/
│  ├─ app.js                     # ゲーム・採点・UI・ランキング・マイページの主ロジック
│  ├─ styles.css                 # ユーザー画面のスタイル
│  └─ quiz_icon/                 # 野球博士のランクアイコン
│
├─ api/
│  ├─ *.php                      # ゲーム、採点、認証、ランキング、通知等のAPI
│  └─ admin_api.php              # 管理画面API
│
├─ data/
│  ├─ questions.json             # 通常ゲーム問題
│  ├─ game_config.json           # ルール、守備位置、学年、描画設定
│  ├─ quiz_master_questions.json # 野球博士チャレンジ問題
│  └─ quiz_master_titles.json    # ランク・称号定義
│
├─ scores/                       # 本番プレイヤー・スコア・ID・ログ（変更禁止）
├─ requests/                     # 要望・削除申請・監査ログ（変更禁止）
└─ vendor/                       # Composer依存（原則変更禁止）
```

### ファイル探索の優先順
1. **仕様・問題内容を確認する**：`data/`
2. **画面・操作を確認する**：`index.html` → `assets/app.js` → `assets/styles.css`
3. **API・保存処理を確認する**：該当する `api/*.php`
4. **キャッシュ・更新不具合を確認する**：`service-worker.js` → `version.json` → `index.html`
5. **管理機能を確認する**：`admin.html` → `api/admin_api.php`
6. **運用データは閲覧のみ**：`scores/`、`requests/`

---

## 3. ブランチ戦略

### 採用方針
- `main` 相当の既定ブランチは常にデプロイ可能な状態を維持する。
- すべての変更は作業ブランチで行い、PRを通して統合する。
- 本番への直接編集・直接コミットはしない。

### ブランチ命名規則
```text
codex/<task-name>
```

例：
```text
codex/fix-quiz-ranking-css
codex/update-question-bq-123
codex/fix-pwa-cache-v1062
```

### 標準作業手順
```powershell
git switch -c codex/<task-name>
git status --short
git diff
```

### コミット規則
- コミットメッセージは日本語またはプロジェクト内で統一された表記で記載する。
- 「何を変えたか」だけでなく、**なぜ変更したか**を残す。
- キャッシュ版を更新した場合は、更新対象と新旧バージョンを明記する。

### 🔴 必須：コミット・PUSH 前の PWA キャッシュ整合性チェック

**これは絶対ルールです。すべての修正後、コミット前に必ず実施してください。**

| ファイル | 確認項目 | 例 |
|---------|---------|-----|
| `version.json` | `app_version` と `cache_version` を確認 | v1017、yakyu-yarouze-v1062-production |
| `service-worker.js` | `CACHE_VERSION` が `version.json` の `cache_version` と一致 | const CACHE_VERSION = 'yakyu-yarouze-v1062-production' |
| `index.html` | `styles.css?v=...` と `app.js?v=...` のクエリ + `window.YAKYU_CACHE_VERSION` が `version.json` と一致 | styles.css?v=1062、app.js?v=1062、window.YAKYU_CACHE_VERSION = 'yakyu-yarouze-v1062-production' |
| `manifest.webmanifest` | PWA メタデータ確認（通常変更なし） | display: "standalone" など |

**チェック手順**:
```powershell
# 1. version.json の現在値確認
Get-Content version.json | ConvertFrom-Json | Select-Object app_version, cache_version

# 2. service-worker.js の CACHE_VERSION 確認
Select-String -Path service-worker.js -Pattern "const CACHE_VERSION = " | Select-Object -First 1

# 3. index.html の styles.css、app.js、YAKYU_CACHE_VERSION 確認
Select-String -Path index.html -Pattern "styles\.css\?v=|app\.js\?v=|YAKYU_CACHE_VERSION"

# 4. 上記 3 つが一致していることを確認
```

**不整合のリスク**:
- ユーザー端末に古い JS/CSS がキャッシュされ続ける（セキュリティ修正が反映されない）
- 新しい API 仕様と古い JS が動作を一致させない
- 管理画面表示と実際のキャッシュが一貫性を失う
- PWA では古いコードが数日～数週間残る可能性

**コミット前チェックリスト（全バージョン参照の整合性確認）**:

すべてのバージョン参照が新バージョン（例：v1074）に一致していることを確認する。

#### ファイルごとの確認項目

| ファイル | 確認項目 | 例（v1074） | 必須レベル |
|---------|---------|----------|----------|
| `version.json` | `app_version` | `v1074-production` | ✅ 必須 |
| `version.json` | `cache_version` | `yakyu-yarouze-v1074-production` | ✅ 必須 |
| `service-worker.js` | `const CACHE_VERSION` | `yakyu-yarouze-v1074-production` | ✅ 必須 |
| `service-worker.js` | `STATIC_ASSETS` 内の CSS | `./assets/styles.css?v=1074` | ✅ 必須 |
| `service-worker.js` | `STATIC_ASSETS` 内の app.js | `./assets/app.js?v=1074` | ✅ 必須 |
| `service-worker.js` | `STATIC_ASSETS` 内の quiz_master_questions.js | `./assets/quiz_master_questions.js?v=1074` | ✅ 必須 |
| `index.html` | `styles.css` リンク | `href="assets/styles.css?v=1074"` | ✅ 必須 |
| `index.html` | `app.js` スクリプト | `<script src="assets/app.js?v=1074">` | ✅ 必須 |
| `index.html` | `quiz_master_questions.js` スクリプト | `<script src="assets/quiz_master_questions.js?v=1074">` | ✅ 必須 |
| `index.html` | `window.YAKYU_APP_VERSION` | `'v1074-production'` | ✅ 必須 |
| `index.html` | `window.YAKYU_CACHE_VERSION` | `'yakyu-yarouze-v1074-production'` | ✅ 必須 |
| `index.html` | `meta[name="app-version"]` | `content="v1074-production"` | ⚠️ 推奨 |

#### チェック実行手順

```powershell
# 1. version.json の確認
Write-Host "=== version.json ==="
(Get-Content version.json | ConvertFrom-Json) | Select-Object app_version, cache_version

# 2. service-worker.js の CACHE_VERSION 確認
Write-Host "`n=== service-worker.js CACHE_VERSION ==="
Select-String -Path service-worker.js -Pattern "const CACHE_VERSION = " | Select-Object -First 1

# 3. service-worker.js の STATIC_ASSETS 内の ?v= 確認
Write-Host "`n=== service-worker.js STATIC_ASSETS ==="
Select-String -Path service-worker.js -Pattern "\?v=" | Where-Object { $_ -match "STATIC_ASSETS|styles\.css|app\.js|quiz_master" }

# 4. index.html の CSS/JS ?v= 確認
Write-Host "`n=== index.html CSS/JS リンク ==="
Select-String -Path index.html -Pattern "styles\.css\?v=|app\.js\?v=|quiz_master_questions\.js\?v="

# 5. index.html の window.YAKYU_* 確認
Write-Host "`n=== index.html window.YAKYU_* ==="
Select-String -Path index.html -Pattern "window\.YAKYU_APP_VERSION|window\.YAKYU_CACHE_VERSION"

# 6. 一覧表示（テキスト整合確認用）
Write-Host "`n=== 全バージョン参照一覧 ==="
Write-Host "構成要素                                              参照内容"
Write-Host "---"
Write-Host "version.json app_version:                " ((Get-Content version.json | ConvertFrom-Json).app_version)
Write-Host "version.json cache_version:              " ((Get-Content version.json | ConvertFrom-Json).cache_version)
Write-Host "service-worker.js CACHE_VERSION:         " (Select-String -Path service-worker.js -Pattern "const CACHE_VERSION = '(.+)'" -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value })
Write-Host "index.html styles.css?v=:                " (Select-String -Path index.html -Pattern "styles\.css\?v=([0-9]+)" -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value } | Select-Object -First 1)
Write-Host "index.html app.js?v=:                    " (Select-String -Path index.html -Pattern "app\.js\?v=([0-9]+)" -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value } | Select-Object -First 1)
Write-Host "index.html quiz_master_questions.js?v=: " (Select-String -Path index.html -Pattern "quiz_master_questions\.js\?v=([0-9]+)" -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value } | Select-Object -First 1)
Write-Host "index.html YAKYU_APP_VERSION:            " (Select-String -Path index.html -Pattern "window\.YAKYU_APP_VERSION = '(.+?)'" -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value })
Write-Host "index.html YAKYU_CACHE_VERSION:          " (Select-String -Path index.html -Pattern "window\.YAKYU_CACHE_VERSION = '(.+?)'" -AllMatches | ForEach-Object { $_.Matches.Groups[1].Value })
```

#### チェックリスト

- [ ] 上記表の全 **✅ 必須** 項目が新バージョンに統一されていることを確認
- [ ] `version.json` の `cache_version` 番号（数字部分）と、`?v=` の数字が一致確認
- [ ] `window.YAKYU_APP_VERSION` と `window.YAKYU_CACHE_VERSION` が共に新バージョンに更新
- [ ] `git diff` で修正ファイルを確認（意図しない変更がないか）
- [ ] `git status` で修正対象のファイルのみが変更されていることを確認（scores/ などは含まない）

例：
```text
fix: ランキングのスコア表示を読みやすくする

CSSセレクタの構文エラーによりスタイルが適用されなかったため修正。
キャッシュ版を v1061 に更新し、index.html と service-worker.js の参照も同期。
```

### ブランチ統合の条件
- 品質基準をすべて満たすこと
- `scores/` と `requests/` に意図しない差分がないこと
- PR説明に、理由・影響範囲・検証結果・バージョン整合性を記載すること

---

## 3.5. 🔴【最重要】デプロイの仕組みと GitHub 認証・連携

> **このセクションは過去にデプロイ事故（本番未反映）を起こした最重要事項である。デプロイ作業前に必ず全文を読むこと。**

### 🔴 大原則：`git push` だけでは本番に反映されない

`main` への `git push` は **GitHub リポジトリにコミットを反映するだけ**であり、
**本番サーバー（CORESERVER `/baseball/`）には一切反映されない。**
本番反映は **`deploy.yml` ワークフローを `apply=true` で手動起動した時のみ** 行われる。

| 操作 | 効果 | 本番反映 |
|------|------|---------|
| `git push origin main` | origin/main にコミット反映 | ❌ されない |
| `deploy.yml` を `apply=false` で実行 | dry-run（FTPシミュレーションのみ） | ❌ されない |
| **`deploy.yml` を `apply=true` で実行** | **FTPで本番へ実アップロード** | **✅ される** |

### デプロイ用ワークフローの構成（`.github/workflows/`）

| ファイル | トリガー | 対象環境 | 反映条件 |
|---------|---------|---------|---------|
| `deploy.yml` | `workflow_dispatch`（手動のみ） | 本番 `/baseball/` | `apply=true` 入力が必須 |
| `deploy-test.yml` | `codex/` ブランチからの PR | テスト `/baseball_test/` | PR作成・更新で自動 |

- **本番（`deploy.yml`）には push トリガーが存在しない。** 必ず手動起動が必要。
- ワークフローは起動時点の `main` HEAD をチェックアウトする。**最新コミットを push してから起動すること。**
- 起動後は GitHub API で `head_sha` が最新コミットと一致するか必ず確認する（古いコミットで動いていないか）。

### 🔴 必須：デプロイ後の `head_sha` 検証

過去に「ワークフローは success だが古いコミット（push 前）で実行されており本番未反映」という事故が発生した。
**デプロイ実行後は、対象 run の `head_sha` がローカル/リモートの最新 `main` と一致することを必ず確認する。**

```powershell
# リモート main の最新SHA
git ls-remote origin main
# 直近の deploy.yml run の head_sha と run_number / conclusion を確認し、上記と一致するか照合
```

### GitHub 認証・連携について（API 経由でのワークフロー起動）

- このリポジトリのリモートは SSH（`git@github.com:headslider/baseballgame.git`）。
- ワークフローの手動起動には GitHub REST API を使う。認証トークンは
  **ローカルの git 認証ヘルパーに保存済み**であり、以下で取得できる（**トークンは絶対に画面出力・ログ・コミットに残さない**）。

```bash
# トークンを変数に取得（出力しない）
TOKEN=$(printf "protocol=https\nhost=github.com\n\n" | git credential fill 2>/dev/null | grep "^password=" | cut -d= -f2)

# deploy.yml を本番反映(apply=true)で起動。HTTP 204 が成功。
curl -s -o /dev/null -w "%{http_code}" -X POST \
  -H "Accept: application/vnd.github+json" \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-GitHub-Api-Version: 2022-11-28" \
  https://api.github.com/repos/headslider/baseballgame/actions/workflows/deploy.yml/dispatches \
  -d '{"ref":"main","inputs":{"apply":"true"}}'

# 起動後、最新 run の head_sha / conclusion を確認
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://api.github.com/repos/headslider/baseballgame/actions/workflows/deploy.yml/runs?per_page=1"
```

- claude.ai 側の「GitHub連携」パネルは Chat / Projects / リモートセッション用であり、
  **このローカルセッションから Actions を起動する権限は付与しない。** Actions 起動は上記 API 経由で行う。
- `gh` CLI はこの環境に未インストール。インストール不要で上記 API 方式を使う。

### 🔴 トークン取り扱いの絶対ルール

- トークンを `echo` / `Write` / コミット / PR / チャットに**絶対に出力しない**。
- 取得は同一コマンド内の変数代入に留め、`curl` に渡したらそのコマンドで完結させる。
- レスポンス本文を保存する場合もトークンが含まれないことを確認する。

### デプロイ作業の標準手順（本番）

1. 作業ブランチで実装・検証し、PWAキャッシュ3ファイルの整合性を確認する。
2. `main` に統合し、`git push origin main` する。
3. `git ls-remote origin main` でリモート最新SHAを控える。
4. 上記 API で `deploy.yml` を `apply=true` 起動（HTTP 204 を確認）。
5. 最新 run の `head_sha` が手順3のSHAと一致し、`conclusion=success` であることを確認する。
6. 本番 `/baseball/` を実機確認する。PWAキャッシュが残る場合はスーパーリロード／Service Worker 再登録を案内する。

---

## 3.6. 🔴【最重要】公開トップの出し分け（`index.php` / `production_hold_enabled.flag` / 秘密URL / `app_shell.html`）

> **段階リリース（メンテ ⇄ 公開）の中核。詳細運用は `docs/staged_release_preview.md` を正とし、本セクションはその要点を集約する。両者は常に同期させること。**

### 全体像：フラグ1つで公開トップを切り替える（手動 index.html 差し替えは不要）

公開トップ `/baseball/`（DirectoryIndex=`index.php`）は、**`production_hold_enabled.flag` 1つ**でメンテ画面と実アプリを自動で出し分ける。**従来の「手動で index.html を実アプリ版へ差し替える」手順は廃止**され、フラグ切替だけで公開/非公開が切り替わる。

### 構成ファイルと役割

| ファイル | 役割 | 直アクセス |
|---|---|---|
| **`index.php`** | 公開トップの入口。`production_hold_enabled.flag` を読み、`true`→`index.html`(メンテ) / `false`・不在→`app_shell.html`(実アプリ) を `readfile` で出力 | `/baseball/` の実体 |
| **`app_shell.html`** | 実アプリHTMLの**単一の正本**（バージョン参照もここに集約）。`index.php` と秘密URLの双方が共有 | **`.htaccess` で禁止**（readfile はサーバー側のため影響なし） |
| **`index.html`** | メンテナンス（アップデート準備中）画面。静的HTML | 可 |
| **`index-preview-<token>.php`** | 秘密URL。フラグ有効時のみ `app_shell.html` を出力（メンテ中の本番プレビュー用） | ゲートで制御 |
| **`production_hold_enabled.flag`** | 上記すべてを制御するスイッチ（既定 `true`） | — |

### 🔴 `production_hold_enabled.flag` の判定ルール（安全側デフォルト）
内容を小文字化・trim して判定する。

| フラグの値 | 判定 | 公開トップ `/` | 秘密URL | 野球博士ライフ |
|---|---|---|---|---|
| `true` / `1` / `on` / `yes`（大小文字不問） | メンテ中 | **index.html（メンテ）** | 利用可（実アプリ・200） | 無制限(×∞) |
| `false` / 空 / その他 | 公開 | **app_shell.html（実アプリ）** | 利用不可（403） | 通常 1日5回 |
| **ファイル不在** / 取得失敗 | 公開（安全側） | **app_shell.html（実アプリ）** | 利用不可（403）※ | 通常 1日5回 |

> ※ 入口 `index.php` の安全側は「公開＝実アプリ」（リリース後にフラグを消しても実アプリが出続けるため）。秘密URLゲートの安全側は逆に「不在＝403」（漏洩防止）。**両者で安全側の向きが異なる**点に注意。

### フラグが制御する3つの挙動と実装箇所

| # | 対象 | 実装箇所 |
|---|---|---|
| ① | 公開トップ `/` のメンテ/実アプリ出し分け | `index.php`（`readfile` で `index.html` か `app_shell.html`） |
| ② | 秘密URL の利用可否（200/403） | `index-preview-<token>.php` 先頭ゲート |
| ③ | 野球博士チャレンジのライフ（無制限/5回） | `app.js` の `quizMasterLimitActive()` |

### 🔴 Service Worker の配信戦略（重要：ナビゲーションは常時 networkFirst）

- **HTMLナビゲーション（`/`＝index.php）は常に networkFirst**。SW はフラグを読まず、毎回最新の `index.php` を取得するだけ。`index.php` がサーバー側でフラグ判定するため、**`flag` の true/false 切替がキャッシュ版数 bump 無しで全ユーザーに即時反映**される（オフライン時のみ直近キャッシュにフォールバック）。
- CSS/JS 等のアセットは `cacheFirst` で高速配信（`?v=` 版数で更新管理）。
- 秘密URL（`/index-preview-[a-z0-9]+\.php`）と `production_hold_enabled.flag` は `NETWORK_FIRST_PATTERNS` 登録済みで常に最新を取得。
- 旧仕様の `isHoldEnabled()`（SWがフラグで networkFirst↔cacheFirst を切替）は**廃止**。

### 配置・管理・切替方法
- `production_hold_enabled.flag` は **Git追跡・自動デプロイ対象（既定 `true`）**。`deploy.yml`／`deploy-test.yml` 双方で本番・テストの各直下に独立配備される。
- 切替：①リポジトリでフラグを `true`/`false` にしてコミット＆デプロイ（恒久・推奨）、②サーバーでFTP直接編集（即時。ただし次回デプロイでリポジトリ値に上書き）。

### 🔴 バージョン整合性（正本は app_shell.html に集約済み）

実アプリのバージョン参照は **`app_shell.html` 1ファイルに集約**された（旧来の index.html / index-preview 二重管理による不整合は解消）。フロント更新・キャッシュ bump 時は次を同一バージョンに揃える：

| ファイル | 揃える項目 |
|---|---|
| `version.json` | `app_version` / `cache_version` / `public_version` |
| `service-worker.js` | `CACHE_VERSION` / `STATIC_ASSETS` 内の `?v=` |
| `app_shell.html` | `styles.css?v=` / `app.js?v=` / `quiz_master_questions.js?v=` / `app-version` meta / `YAKYU_APP_VERSION` / `YAKYU_CACHE_VERSION` |

```powershell
# app_shell.html のバージョン参照を一括確認
Select-String -Path app_shell.html -Pattern "v=10|YAKYU_APP_VERSION|YAKYU_CACHE_VERSION|app-version"
```

### 🟡 その他の注意
- **秘密URLは noindex を HTTPヘッダ（`X-Robots-Tag: noindex, nofollow`）で付与**（app_shell.html 側の `<meta robots>` は公開用に除去済みのため）。
- **秘密URLから PWA をホーム追加しない**（`manifest` の `start_url` は `/baseball/`）。検証はブラウザで直接開く。
- `app_shell.html` の**直アクセスは `.htaccess` で禁止**（フラグ=メンテ中に実アプリを覗き見されないため）。`readfile` はファイルシステム経由なので出力には影響しない。

### 公開切替（リリース）の手順（簡素化）
1. `production_hold_enabled.flag` を **`false`** にしてコミット（これだけで `/` が実アプリに切替わる）。
2. （任意）秘密URLを完全に閉じたい場合は `index-preview-<token>.php` を削除。
3. アプリ更新を伴う場合は PWAキャッシュを次バージョンへ bump（version.json / service-worker.js / app_shell.html）。
4. `deploy.yml` を `apply=true` で本番反映 → `head_sha`・success・実機を確認。

---

## 4. タスク管理方針

### Issue作成テンプレート
```md
## 背景・目的
なぜこの対応が必要か。

## 対象範囲
- 対象画面／機能：
- 対象ファイル候補：
- 影響するゲームモード：

## 要件・受入条件
- [ ] 利用者視点で達成すべき状態
- [ ] 変更してはいけない既存挙動
- [ ] 必要なデータ整合性

## リスク・確認事項
- PWAキャッシュ更新の要否：
- scores/・requests/への影響：
- 本番反映時の注意：
```

### PR作成テンプレート
```md
## 変更理由
なぜこの変更が必要だったか。

## 変更内容
- 変更した機能：
- 変更したファイル：
- 変更していない領域：

## 影響範囲
- 通常ゲーム：
- 野球博士チャレンジ：
- 管理画面：
- 保存データ／ランキング：
- PWAキャッシュ：

## 検証結果
- [ ] PHP構文確認
- [ ] JSON有効性確認
- [ ] 通常ゲーム確認
- [ ] 野球博士確認
- [ ] 保存・ランキング・マイページ確認
- [ ] 管理画面確認
- [ ] PWAキャッシュ整合性確認

## バージョン整合性
| 対象 | 確認内容 |
|---|---|
| `index.html` | CSS / JSクエリ、`window.YAKYU_CACHE_VERSION` |
| `service-worker.js` | `CACHE_VERSION`、`STATIC_ASSETS` |
| `version.json` | `app_version`、`cache_version`、`public_version` |

## ロールバック方法
問題発生時に戻すコミット、または復旧手順を記載する。
```

### タスク完了の定義
- 受入条件を満たす
- 必要なテスト結果をPRに記録する
- 本番運用データに変更がない、または事前承認がある
- 変更理由・影響範囲・復旧手段が第三者にも分かる
- キャッシュ更新が必要な場合、3ファイルの整合性が取れている

---

## 5. 品質基準

### 必須の静的確認
| 対象 | 基準・コマンド |
|---|---|
| PHP | 修正した全PHPに対して `php -l path/to/file.php` |
| JSON | `questions.json`、`game_config.json`、`quiz_master_questions.json` をPowerShellでパース |
| Git差分 | `git status --short` と `git diff` で意図しない変更がない |
| 問題ID | 通常ゲーム・野球博士ともに重複・欠番・不正変更がない |
| 運用データ | `scores/`、`requests/` に差分がない |

### 問題データ品質基準
- 問題IDは不変。削除・リネームは事前確認が必要。
- `attack` / `defense` 問題は、`outs: 0|1|2` または `outs_scope: "common"` のいずれかを必須とし、両方を設定しない。
- `visual` の必須項目を空欄にしない。
  - `batter_runner`
  - `ball_path`
  - `runners`
  - `holder`
  - `target_position`
  - `play`
- `visual.batter_runner` は `true` / `false` を明示する。
- `min_grade` / `max_grade` がある場合は優先する。
- 同系統問題は横断監査し、単一問題だけで修正完了としない。

### PWAキャッシュ品質ゲート
フロントエンド更新時は、次の整合性を必ず確認する。

| ファイル | 必須確認項目 |
|---|---|
| `index.html` | CSS/JSのクエリ、`window.YAKYU_CACHE_VERSION` |
| `service-worker.js` | `CACHE_VERSION`、`STATIC_ASSETS`内のクエリ |
| `version.json` | `app_version`、`cache_version`、`public_version` |

CSSまたはJavaScriptを変更した場合、キャッシュ版の更新要否を判断し、更新する場合は3ファイルを同期する。CSSの複数セレクタはカンマ抜けを特に確認する。

### 動作確認基準
- 通常ゲーム：3年生以上、各守備位置、アウトカウント `0 → 1 → 2`、問題重複なし
- 野球博士：開始、ランク表示、デイリーライフ、ページネーション、中断／継続
- 共通機能：スコア保存、ランキング、マイページ、間違いチェック、メニュー、チュートリアル
- 管理機能：管理画面ログインと主要操作
- 演出：チュートリアル、ボーナスライフ、スコア表示アニメーション
- 更新後：Service Workerの更新、古いCSS/JSが残らないこと

### デプロイ基準
1. 作業ブランチで検証する  
2. テスト環境 `/baseball_test/` で確認する  
3. PRレビュー・承認後に本番 `/baseball/` へ反映する  
4. 本番反映後、表示・保存・ランキング・キャッシュを再確認する  

---

## 6. 禁止事項

### 絶対に変更・削除・初期化しない対象
- `scores/` ディレクトリ全体
- `requests/` ディレクトリ全体
- `vendor/`（Composer更新が必要な場合を除く）
- 本番のプレイヤー登録データ
- スコアログ、監査ログ、アクセスログ
- 招待ID／管理者ID台帳
- `scores/release_versions.json`（管理画面以外からの更新）
- 本番 `/baseball/` の `.htaccess`

### 事前承認が必要な操作
- `scores/` または `requests/` 内の任意ファイルの修正
- 問題の削除、ID変更、リネーム
- Git履歴の書き換え、rebase後の強制プッシュ
- Composer、`vendor/`、依存関係の変更
- 本番URL、`.htaccess`、本番設定の変更
- `scores/release_versions.json` の変更
- 本番環境のデータ削除・置換・再初期化

### 例外時の必須報告
禁止対象または承認対象に触れる必要がある場合は、作業前に以下を明記して承認を得る。

```md
- 実施理由：
- 変更対象：
- 影響範囲：
- バックアップ有無：
- 復旧方法：
- 本番／テスト環境への影響：
```

---

## 7. 本番反映時の重大リスク と必須対応

本番反映時に以下のリスクが確認されています。これらは **必ず事前チェック** してください。

### 🔴 最優先：scores/ の丸ごと上書き禁止

| 項目 | 内容 |
|------|------|
| **リスク** | 新機能の `scores/` をコピーして本番を上書きすると、33 ファイルの既存運用データが全消失 |
| **影響対象** | `player_registry.csv`、`score_log.csv`、`invite_codes.json`、`admin_codes.json`、`player_features.json`、問題バージョン履歴など |
| **必須対応** | `scores/` は **絶対にコピーしない**。`quiz_master_scores.json` だけが必要な場合は個別ファイルのみ追加。既存本番データの運用を継続 |

### 🔴 最優先：PWA キャッシュバージョン整合性不足

| 項目 | 内容 |
|------|------|
| **リスク** | `version.json`、`service-worker.js`、`index.html` のバージョン参照が不一致。古い JS/CSS が長期キャッシュされ、新しい機能が反映されない |
| **実例（2026-06-27）** | `index.html` で `app.js?v=1074` と指定しているが、`window.YAKYU_APP_VERSION = 'v1070-production'`、`window.YAKYU_CACHE_VERSION = 'yakyu-yarouze-v1070-production'` のままだった。Service Worker が v1070 のキャッシュを使用し続け、v1074 の新しい app.js が読み込まれず、`updateLoginUI()` 関数が古いバージョンで実行されたため、**ログイン前に表示されるべきでない UI 要素（ランキングボタン、学年セレクタ）が表示される** 重大なバグが発生 |
| **影響対象** | ユーザー端末にて古いゲームロジック、古い問題、古いアニメーション継続表示 / セキュリティ修正が反映されない / UI の条件付き表示が機能しない |
| **必須対応** | リリース前に以下 **6つの参照を一つの新バージョン（例：v1074）に統一** 必須（CLAUDE.md セクション3のチェックリスト参照）：<br>1. `version.json`: `app_version`, `cache_version`<br>2. `service-worker.js`: `CACHE_VERSION`, `STATIC_ASSETS` 内の `?v=`<br>3. `index.html`: CSS/JS リンク `?v=`, `window.YAKYU_APP_VERSION`, `window.YAKYU_CACHE_VERSION`<br>**すべてが完全に一致していることを確認しない限り、コミット・本番反映を実施しない** |

### 🟡 中：機能追加 ZIP に運用データ同梱

| 項目 | 内容 |
|------|------|
| **リスク** | 新機能追加ファイルに旧バージョンの `scores/` データが含まれている場合、ファイルサイズが大きく、本番反映時に同梱される可能性 |
| **影響対象** | 古い運用データで本番が上書きされる |
| **必須対応** | 反映用ファイルは **コード・新機能アセット・新規 API・新問題データ** のみを厳選。運用データは絶対に同梱しない |

### 🟡 中：メタデータが同梱

| 項目 | 内容 |
|------|------|
| **リスク** | `.git`（132 件）、`.claude`（2 件）、`.github`、`README`、`CLAUDE`、`unminified_preview.css` など、本番不要なメタデータが含まれる |
| **影響対象** | Web 公開ディレクトリに Git 履歴、開発ドキュメント、プレビュー CSS が公開される |
| **必須対応** | Web 公開ディレクトリ下に `.git/`、`.claude/` を展開しない。`.gitignore` と GitHub `pages` 設定で除外管理。本番用デプロイファイルから完全に除外 |

---

## 8. 🔴 コード実装と説明文の整合性（必須ルール）

### 原則：説明文と実装の一致を厳守

すべての UI 説明文、メッセージ、ドキュメント、コメントは、**実装されている機能**を正確に説明する必要があります。

**禁止事項**:
- ❌ 実装していない機能を説明文に記述する
- ❌ 実装を簡略化した場合、説明文のみ修正して機能を放置する
- ❌ 計画中の機能を説明文に先走って記載する
- ❌ 削除した機能の説明文が残っている

### チェックリスト（修正前に必ず実施）

変更を加える際、以下のいずれかを必ず実施してください：

- [ ] **説明文を修正する場合**：実装機能と説明文が一致しているか確認
- [ ] **実装を修正する場合**：説明文も合わせて修正
- [ ] **機能を削除する場合**：説明文・コメント・ドキュメントも削除
- [ ] **新規機能を追加する場合**：実装完了後に説明文を追加

**二重確認ルール**：
```
修正内容の確認：
1. コード上で実装されているか？ →「yes」の場合のみ説明文に記載
2. 説明文に記載されているか？ →「no」の場合は実装と説明文を一致させる
```

### 違反時の対応

説明文と実装の不整合が見つかった場合：
1. **実装がある場合**：説明文を実装に合わせる
2. **実装がない場合**：説明文を削除する（機能実装は改めてタスク化）
3. **本番での説明不一致リスク**：ユーザーが期待と異なる仕様に遭遇 → 信用失墜

---

## 8. セキュリティ対応状況

### プレイヤーアカウント削除機能（2026-06-26 完了）

#### 実装内容

**認証方式**：client_token ベース（セッション不要）
- register_player.php と同じ verify_player_client() で認証
- セッション切れ時も削除可能（アプリ再起動後にも対応）
- **重大修正**：verify_player_client() が未登録IDを自動登録していた問題を廃止

**トークン検証**：2段階認証
- フェーズ1：client_token で認証 → 削除トークン生成・サーバー側保存
- フェーズ2：削除トークンを hash_equals() で検証 → 削除実行

**削除対象（8ファイル）**
| ファイル | 形式 | 削除方式 |
|---------|------|--------|
| `quiz_master_scores.json` | JSON (scores[] + totals{}) | scores 配列フィルタリング + totals 削除 |
| `player_registry.csv` | CSV | ヘッダー保持してフィルタリング |
| `score_log.csv` | CSV | ヘッダー保持してフィルタリング |
| `player_features.json` | JSON (players{}) | db['players'][ID] 削除 |
| `mistake_review.json` | JSON (records[]) | records 配列から array_filter |
| `push_subscriptions.json` | JSON ({ID: {}}) | オブジェクトキー削除 |
| `access_log.csv` | CSV | player_id カラムでフィルタリング |
| 削除ログ | JSON | requests/delete_logs に記録（監査証跡） |

**並行処理対応**：全ファイル操作を flock(LOCK_EX) で排他制御
- CSV ヘッダーを正しく処理（fgetcsv で分離して保持）
- 削除と並行して行われるスコア保存による復活を防止

#### 重大セキュリティ修正（2026-06-26 最新）

| # | 問題 | 修正内容 | 効果 |
|----|------|--------|------|
| 1 | 削除後データ復活 | verify_player_client() が未登録IDを自動登録 → 廃止 | 古い client_token で再登録不可 |
| 2 | 野球博士スコア未移行 | change_player_id.php に migrate_quiz_master_scores_change_id() 追加 | ID変更後も成績が新IDに継続 |
| 3 | Push登録未移行 | change_player_id.php に migrate_push_subscriptions_change_id() 追加 | ID変更後も通知が新IDで受信 |
| 4 | 4ファイル削除失敗 | player_features, mistake_review, push_subscriptions, access_log の形式修正 | 8ファイルすべてから完全削除 |

#### 本番・テストサーバー反映状況
| ファイル | 本番環境 | テスト環境 | 方法 |
|---------|--------|---------|-----|
| `requests/.htaccess` | ✅ 反映済 | ✅ 反映済 | FTP（2026-06-26） |
| `api/feature_common.php` | デプロイ待機 | デプロイ待機 | GitHub deploy.yml |
| `api/change_player_id.php` | デプロイ待機 | デプロイ待機 | GitHub deploy.yml |
| `api/user_delete_request.php` | デプロイ待機 | デプロイ待機 | GitHub deploy.yml |
| `api/user_delete_dialog.js` | デプロイ待機 | デプロイ待機 | GitHub deploy.yml |
| `service-worker.js` (キャッシュ) | デプロイ待機 | デプロイ待機 | GitHub deploy.yml |

#### ID変更対応
- 旧ID から新ID へ変更後、新ID で削除可能
- セッション不要のため、ID変更後のセッション不整合を排除
- **新規修正**：quiz_master_scores.json と push_subscriptions.json も ID変更時に移行

---

## エージェント実行ルール

1. 最初に `README.md`、`data/game_config.json`、`assets/app.js`、`api/admin_api.php` を読む。  
2. 変更前に対象ファイルと依存関係を確認する。  
3. `scores/` と `requests/` は閲覧専用として扱う。  
4. 問題データ・UI・API・キャッシュの関係を横断して確認する。  
5. 作業後は差分、構文、JSON、機能、キャッシュを順に検証する。  
6. PRには「なぜ」「影響範囲」「検証」「ロールバック」を必ず残す。  
