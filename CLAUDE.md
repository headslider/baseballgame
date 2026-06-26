# CLAUDE.md

このファイルは Claude Code (claude.ai/code) がこのリポジトリで作業する際の指針を提供します。

**🇯🇵 常に日本語を使用してください。このプロジェクトについてユーザーとコミュニケーションする際は、すべて日本語で対応してください。**

---

## プロジェクト概要

**野球やろうぜ！** — 少年野球の攻撃判断・守備判断・基本動作を学ぶ PWA

- **タイプ**: PHP バックエンド付き Progressive Web App (PWA)
- **フロントエンド**: 静的 HTML/CSS/JavaScript + Service Worker
- **バックエンド**: PHP API
- **現在の本番版**: v1065-production (index.html meta タグで追跡)
- **現在のキャッシュ版**: v1061
- **ビルドステップ不要** — `php -S localhost:8000` で直接実行

---

## クイックスタート

### ローカル開発

```powershell
php -S localhost:8000
```

その後、ブラウザで `http://localhost:8000/` を開きます。

### PHP 構文確認

```powershell
php -l path/to/file.php
```

コミット前に `api/` の修正済み `.php` ファイルすべてで実行してください。

### JSON 有効性確認

```powershell
# Windows PowerShell: JSON をパース
Get-Content data/questions.json | ConvertFrom-Json
Get-Content data/game_config.json | ConvertFrom-Json
```

### Composer 依存関係

```powershell
composer install
```

（`composer.json` が変更された場合のみ必要。`vendor/` は Web Push 通知用）

---

## アーキテクチャ

### 静的ファイル

| パス | 役割 |
|------|------|
| `index.html` | メイン PWA、ゲーム画面、チュートリアル、トップページ、ランキング、マイページ |
| `admin.html` | 管理画面（最高位管理者用） |
| `delete.html` | アカウント削除申請フォーム |
| `manifest.webmanifest` | PWA マニフェスト（ホーム画面追加用） |
| `service-worker.js` | PWA キャッシュ、プッシュ通知、オフライン対応 |
| `assets/app.js` | ゲームロジック、採点、UI 切替、ランキング、マイページ、間違いチェック |
| `assets/styles.css` | ユーザー画面のスタイル |

### バックエンド API

| パス | 役割 |
|------|------|
| `api/*.php` | ゲーム、採点、ランキング、認証、管理、通知、アカウント削除など |
| `api/admin_api.php` | 管理画面バックエンド |
| `api/user_delete_*.php` | アカウント削除ワークフロー |

### データ・設定

| パス | 役割 |
|------|------|
| `data/questions.json` | 600 問（攻撃 227、守備 325、基本 48） |
| `data/game_config.json` | ゲームルール、守備位置、学年、レンダリング設定 |
| `data/quiz_master_questions.json` | 野球博士チャレンジ（323 問） |
| `data/quiz_master_titles.json` | ランク・称号（20 段階） |
| `version.json` | 内部バージョン、キャッシュ版、公開版 |

### 本番運用データ（変更禁止）

| パス | 内容 |
|------|------|
| `scores/` | プレイヤー登録、スコア、設定、ログ、ID コード |
| `requests/` | ユーザー要望、削除ワークフロー、監査ログ |
| `vendor/` | Composer 依存（Web Push） |

---

## 重要な運用ルール

### 🔴 絶対禁止事項

**以下を変更・削除・再初期化しないでください**：

- `scores/` ディレクトリ全体
- `requests/` ディレクトリ全体
- `vendor/` （Composer 更新時を除く）
- 本番ユーザー登録データ
- スコアログ、監査ログ、アクセスログ
- 招待 ID / 管理者 ID 台帳
- `scores/release_versions.json` （管理画面経由で更新）
- 本番の `.htaccess` （`/baseball/`）

**例外を作る場合**: 理由、影響範囲、復旧方法を説明し、ユーザー確認を取ってください。

### PWA キャッシュ整合性

**フロントエンドファイルを更新する際、以下を同期させること（必須）**：

```
index.html:
  - assets/styles.css?v=...
  - assets/app.js?v=...
  - window.YAKYU_CACHE_VERSION（version.json cache_version と一致）

service-worker.js:
  - CACHE_VERSION（version.json cache_version と一致）
  - STATIC_ASSETS のクエリが index.html と一致

version.json:
  - app_version
  - cache_version
  - public_version
```

**不整合の影響**: 古い JS/CSS がキャッシュから配信、古い問題が表示、管理画面と実キャッシュが不一致

### 問題データルール

- **ID は不変** — 間違いチェック履歴と監査用
- **outs / outs_scope**: `attack` と `defense` は `outs: 0|1|2` または `outs_scope: "common"` のいずれか必須（両立不可）
- **visual フィールドは空欄禁止** — `batter_runner`、`ball_path`、`runners`、`holder`、`target_position`、`play` は必ず入力
- **visual.batter_runner は明示的** — `true` または `false` を指定（キャッチャーフライ = `false`）
- **学年制限**: `min_grade` と `max_grade` がある場合はそれを優先
- **同系問題の横断監査** — 単一問題だけで完了しない、関連問題もすべて確認

---

## 開発ワークフロー

### ブランチ・PR 構成

```powershell
git switch -c codex/<task-name>
# 作業...
git status --short
git diff
# コミット：「何を」ではなく「なぜ」を説明
```

### コミット前チェックリスト

**PR 作成前に以下を確認してください**：

1. ✅ `scores/` と `requests/` に変更なし
2. ✅ PHP 構文確認: `php -l api/file.php` （修正済み全ファイル）
3. ✅ JSON 有効性: `Get-Content data/questions.json | ConvertFrom-Json` と `quiz_master_questions.json`
4. ✅ 問題 ID 重複・ギャップなし（通常版・野球博士版の両方）
5. ✅ PWA キャッシュ整合性確認 (index.html ↔ service-worker.js ↔ version.json)
6. ✅ 通常ゲームテスト: 3年生以上・各守備位置でゲーム開始、アウト順序確認（0→1→2）、ゲーム内重複なし
7. ✅ 野球博士テスト: クイズ開始、ランク表示、デイリーライフシステム、ページネーション動作確認
8. ✅ スコア保存（両ゲームモード）、ランキング読み込み、マイページ表示、間違いチェック動作
9. ✅ メニューボタン、チュートリアル表示、中断/継続プロンプト動作確認
10. ✅ 管理画面ログイン・操作確認
11. ✅ チュートリアルアニメーション、ボーナスライフアニメーション再生確認

### PR 説明に含めるもの

- 変更の理由（「何が変わったか」ではなく「なぜ変わったか」）
- 影響範囲
- 検証方法
- PWA バージョン整合性確認

---

## よくあるトラブルシューティング

| 症状 | 確認項目 |
|------|---------|
| 古い画面が表示される | PWA/ブラウザキャッシュ、`service-worker.js`、`version.json` |
| PWA だけ動作が違う | Service Worker、キャッシュストレージ、`app.js?v=...` と `CACHE_VERSION` の一致 |
| ゲーム開始できない | `data/questions.json`、問題取得 API、PHP エラーログ |
| スコア保存できない | `api/save_score.php`、`scores/score_log.csv` の権限とバックアップ |
| ランキング表示されない | `api/get_ranking.php`、`scores/score_log.csv` |
| 間違い履歴が出ない | `scores/mistake_review.json`、`assets/app.js`、管理者モード設定 |
| 問題内容が違う | `data/questions.json` で問題 ID 検索、同系統問題も一括確認 |
| 招待 ID/管理者 ID ボタンおかしい | `scores/issue_link_settings.json`、`api/issue_link_status.php`、マニフェスト |
| 通知が届かない | Push 購読、`vendor/`、通知履歴、ブラウザ権限 |

---

## 環境

- **本番**: `/baseball/`
- **テスト**: `/baseball_test/`
- **ローカル**: `http://localhost:8000/`

本番とテストの `scores/` と `requests/` を混在させないでください。

---

## 最近の修正（2026-06-26）

### CSS 修正・スタイル更新

**ランキングページタイトル配置修正**
- `.quiz-master-ranking-page-card` セレクタ修正（複数セレクタ間のカンマ追加）
- `.quiz-master-result-card h2` に `text-align:center;` 追加（モバイル・デスクトップ対応）
- コミット: f80b1ce

**ランキングスコア表示修正**
- `.quiz-master-ranking li span` セレクタ修正（カンマ追加）
- ランキングスコアフォントサイズを `calc(1em + 2px)` で 2px 拡大
- 可視性改善と CSS 構文エラー修正
- コミット: 0fa41b8

**キャッシュ・バージョン更新**
- Service Worker CACHE_VERSION を v1060 → v1061 に更新
- service-worker.js と index.html の CSS 参照を ?v=1061 に更新
- コミット: 2195c28

### テストモード管理
- デイリーライフ上限を一時的に無効化（QUIZ_MASTER_DAILY_LIMIT_ENABLED = false）でテスト実施
- テスト完了後に再有効化（QUIZ_MASTER_DAILY_LIMIT_ENABLED = true）
- テストサイクル中にキャッシュバージョンを適宜更新

---

## CSS 修正時の注意事項（2026-06-26 から学習）

### 🔴 Critical: セレクタの Syntax エラー

**複数セレクタを指定する際、カンマの忘却が頻繁に発生**

**エラー例**:
```css
/* ❌ 間違い — これは動作しない */
.selector-1
.selector-2 {
  property: value;
}

/* ✅ 正しい */
.selector-1,
.selector-2 {
  property: value;
}
```

**影響**: セレクタの syntax エラーがあると、スタイルが全く適用されず、意図した見た目にならない。

**確認方法**:
- ブラウザの Developer Tools で要素を inspect
- 期待したスタイルが適用されていなければ、セレクタを確認
- `grep` で複数行にまたがるセレクタをチェック

**修正方法**:
1. セレクタ間にカンマを追加
2. CSS を minify する前に可読性で検証
3. 修正後は cache version を increment

### フォントサイズの計算式

**相対値と絶対値を混合する場合は `calc()` を使用**:
```css
/* ✅ 相対単位 + 絶対単位 */
font-size: calc(1em + 2px);  /* 基本サイズ + 2px 増加 */

/* ❌ 避けるべき */
font-size: 1em + 2px;  /* これは無効 */
```

**用途**: モバイルでの読みやすさ改善、スコア表示など視認性が重要な要素

---

## PWA キャッシュ管理の実践的ルール（実装者の学習）

### 📋 CSS 修正時の必須チェックリスト

**CSS を修正したら、以下の 3 ファイルを必ず更新**：

1. **`service-worker.js` の CACHE_VERSION**
   ```javascript
   const CACHE_VERSION = 'yakyu-yarouze-v1061-production';  // increment version
   ```

2. **`service-worker.js` の STATIC_ASSETS 内の CSS query**
   ```javascript
   './assets/styles.css?v=1061',  // version と一致させる
   ```

3. **`index.html` の CSS link タグ**
   ```html
   <link rel="stylesheet" href="assets/styles.css?v=1061">
   ```

**理由**: 3 つが不一致だと、ブラウザキャッシュから古い CSS が配信され続ける。

### ⚠️ キャッシュ版の Increment タイミング

- **CSS 修正時**: 毎回 increment
- **JS 修正時**: app.js の query も increment（service-worker.js と index.html）
- **問題データ修正時**: 必要に応じて increment（再起動時に新データが読み込まれない場合）
- **その他修正**: 必ずしも不要（ただし「キャッシュに乗った」と感じたら increment）

### Commit Message の記録ルール

**cache version 更新時は commit message に明記**：
```
chore: Update cache version to v1061

- Update Service Worker CACHE_VERSION from v1060 to v1061
- Update CSS reference in service-worker.js and index.html
```

こうすることで、future conversations でバージョン追跡が簡単になる。

---

## 野球博士チャレンジ（Baseball Master Challenge）

### 概要

通常ゲームに加えた知識クイズモード（20 問）。別途ランキング、デイリーライフシステム、20 段階ランク制度を持つが、メインゲームと完全統合。

### 主要機能

**デイリーライフシステム**
- 基本配分: 1 日 5 ライフ
- ボーナス: 通常ゲーム 1 日 1 回クリア時に +1（最大 6）
- リセット: 毎日 24:00 JST
- ボーナス時アニメーション: ハート（600×600px → 300×300px）+ スライドイン テキスト（「野球博士デイリーライフをゲット!」）
- コード: `assets/app.js` 51 行、2256-2273 行（アニメーション）

**ランクシステム**
- 20 段階ランク、対応アイコン（`assets/quiz_icon/1.png` → `20.png`）
- 累積スコアでランク決定
- リアルタイムランク一覧更新（ランク別ユーザー数表示）
- API: `api/get_quiz_master_ranking.php` が `rank_user_counts` を追跡

**ゲーム操作**
- 第 15 問以降: 中断/継続プロンプト（現在スコア + 次問ボーナス表示）
- メニュー離脱警告: ゲーム中の離脱をブロック、タイマー保持
- 理由: "stopped"（意図的中断）/ "completed"（全 20 問回答）

**チュートリアル・メニュー**
- メニューボタン刷新: ネイビーグラデーション + シェブロン、ゴールドホバー/アクティブ状態
- チュートリアルカード: ゴールドボーダー + 統一デザイン、小ぶりフォント（v1006-v1008）
- 重要説明: ライフシステム、50:50 ルール、ランク情報（すべてゴールド `#ffe26a`）
- アクセス: メニューの「遊び方」または初回ゲーム時

**マイページ機能強化**
- 間違いチェックページネーション: 10 項目/ページ
- 利用不可問題ページネーション: 5 項目/ページ（問題更新・停止時）
- アコーディオン切り替え: ▼/▶ アイコン、開閉アニメーション
- 状態保存: `MISTAKE_REVIEW_LIST_OPEN`、`MISTAKE_REVIEW_UNAVAILABLE_OPEN`

**スコア表示アニメーション（v1013-v1014）**
- 回転なし、優雅なポップアップ + フェードアウト
- ゴールドボーダーフレーム（`#ffd700`）、グラデーション背景（濃茶→黒）
- 4 層ゴールドグロー効果で高級感

### 実装時の重要ポイント

- **問題データ**: `data/quiz_master_questions.json`（323 問）
- **ランク・称号**: `data/quiz_master_titles.json`（20 段階）
- **JavaScript**: `assets/app.js` 内すべてのロジック（`quizMaster*`、`clearQuizMasterTimer`、`saveQuizMasterScore` 関数検索）
- **CSS**: `assets/styles.css` 9687-10105 行（野球博士 UI）
- **キャッシュ同期**: 野球博士アセットはメインゲームとバージョン一致必須
- **日次リセット**: localStorage 日付チェックで日次リセット — キャッシュ更新後に検証

### バージョン追跡（v980-v1016）

| v | 主要変更 | | v | 主要変更 |
|---|--------|---|---|--------|
| v980 | メニューボタン位置修正 | | v998 | ページネーション上部のみ |
| v981 | メニューボタン刷新 | | v999 | アコーディオン機能 |
| v982 | ランク一覧自動更新 | | v1000 | 「遊び方」ボタン追加 |
| v983 | 最終ランクアイコン | | v1006 | チュートリアル統一デザイン |
| v985 | 中断/継続プロンプト | | v1013 | スコアアニメーション強化 |
| v987 | メニュー離脱警告 | | v1014 | ゴールドボーダーフレーム |
| v988 | 5 ライフ+ボーナス | | v1015 | 問題データ v68（表記修正） |
| v989 | ボーナスアニメーション | | v1016 | 問題データ v69（定義修正） |

---

## 野球博士チャレンジ修正時のコンポーネント

| コンポーネント | ファイル | 注記 |
|-------------|--------|------|
| デイリーライフ+ボーナス | `assets/app.js:51, 2256-2273` | localStorage 日付リセットロジック |
| ランク一覧更新 | `api/get_quiz_master_ranking.php:138-150` | `rank_user_counts` 返却 |
| 中断/継続プロンプト | `assets/app.js:2214-2250` | Q15 以降で発動、スコア確保 |
| メニュー離脱警告 | `assets/app.js:1491-1501`、`styles.css:8140-8200` | ダイアログ表示中はタイマー停止 |
| ページネーション/アコーディオン | `assets/app.js:3371-3494` | マイページ間違いチェック UI |
| スコアアニメーション | `styles.css:7831-7850, 8587-8591` | ゴールドボーダー、ポップアップのみ（回転なし） |
| ボーナスアニメーション | `styles.css:9970-10050` | ハート 150×150px、`#dd0e2d`、計 2.6 秒 |
| ランクアイコン | `assets/quiz_icon/1-20.png` | 600×400px 正規化、約 85KB 各 |

### 野球博士キャッシュ版制御

野球博士アセット更新時:
1. `assets/styles.css` 更新 → キャッシュバージョン更新
2. ランクアイコン更新 → キャッシュバージョン更新
3. `data/quiz_master_questions.json` 更新 → `version.json` の **cache_version** と **app_version** を更新
4. 常に同期: `index.html` クエリ文字列 + `service-worker.js` CACHE_VERSION + `version.json` cache_version

同期失敗の影響: 古いアイコン表示、古いアニメーション、ランキング陳腐化

---

## 最初に読むべきファイル

- `README.md` — 包括的な運用ガイド（日本語）
- `data/game_config.json` — ゲームルール、守備位置、学年、レンダリング設定
- `assets/app.js` — メインゲームロジックエントリーポイント
- `api/admin_api.php` — 管理画面バックエンド

---

## ユーザー確認が必要な場合

以下を行う前に確認を取ってください：

- `scores/` または `requests/` の任意ファイル修正
- 問題削除・リネーム
- 履歴書き換え・強制プッシュ
- `vendor/` や Composer 関連の変更
- 本番 URL・`.htaccess` 変更
- `scores/release_versions.json` 修正

---

## バージョン番号体系

- **app_version**: 内部追跡用（例: v1016）
- **cache_version**: Service Worker キャッシュ無効化キー
- **public_version**: ユーザー向けリリース版（例: v1.1.67）

すべて 3 つが同期され、PR に記載される必要があります。
