# 段階リリース（メンテ＋秘密URLプレビュー）運用ガイド

更新日: 2026-06-27

大型リリース時に「公開トップを一旦停止 → 全データを本番へアップ → 秘密URLで本番検証 → 告知・時刻決定 → 公開切替」を行うための仕組みと手順をまとめる。

---

## ★ 2026-06-27 仕様変更（重要）：公開トップは静的メンテ、秘密URLだけをフラグ制御

`index.php` による公開トップ自動切替は、リロード時の挙動問題があるため採用しない。現在は次の構成で、公開トップと秘密URLを分離する。

- **`index.html`**：公開トップのメンテナンス画面（静的）。`/baseball/` はこれを表示する。
- **`app_shell.html`**：実アプリHTMLの単一の正本。バージョン参照もここに集約。秘密URLPHPが `readfile` で出力する。`.htaccess` で直アクセス禁止。
- **`index-preview-<token>.php`**：秘密URL。フラグ有効時のみ `app_shell.html` を出力（メンテ中の本番プレビュー）。noindex は `X-Robots-Tag` ヘッダで付与。
- **`production_hold_enabled.flag`**：秘密URLの利用可否と、野球博士チャレンジのプレビュー用ライフ無制限を制御する。
- **Service Worker**：ナビゲーションは**常時 networkFirst**。古いHTMLキャッシュより、サーバー上の最新 `index.html` と秘密URLゲートを優先する。
- **公開手順**：公開時は、別途 `index.html` を実アプリ版へ差し替えるか、採用済みの公開方式に合わせて切替手順を実施する。`index.php` 方式は使わない。

以下の本文中に古い `index.php` 方式の記述が残っている場合は、本セクションを優先する。

---

## 0. ⚠️ 重要事項・指摘事項（必ず先に読む）

運用前に把握しておくべき重要点と、これまでに判明・指摘された注意点を集約する。

### 0-1. 🔴 切替直後の「キャッシュ漏れ（1回だけ）」は原理的に避けられない
- **指摘**：メンテナンス公開後も、キャッシュが残っていて実アプリに「一回普通にアクセスできてしまう」事象を確認。
- **原因**：Service Worker が `index.html` を `cacheFirst` で返していたため、**旧SWがキャッシュ済みの実アプリを返す**。
- **対策（実施済み）**：HTMLナビゲーション（`request.mode === 'navigate'`）は常時 networkFirst。公開トップの `index.html` メンテ画面と秘密URLのゲート判定を、古いHTMLキャッシュより優先する。
- **残る制約**：networkFirst を含む**新SWが有効化される前の旧SW端末では、切替直後の1回だけ旧キャッシュが表示され得る**（旧SWの挙動は遡って変更不可）。新SW有効化後は毎回最新が配信される。
  - 確実に切り替えるには **再読み込みを1〜2回**、それでも残る場合は DevTools → Application → Service Workers → **Unregister** 後に再読み込み。

### 0-2. 🔴 秘密URLは `production_hold_enabled.flag` で制御
- `true`＝利用可／`false`・空・**ファイル不在＝利用不可**（安全側デフォルト）。
- フラグは **Git追跡・コミット対象**。リポジトリの既定値は `true` で、テスト・本番の両方へ**自動デプロイされる**（deploy-test / deploy.yml）。これにより配備後すぐ秘密URLが使える。
- 切替（無効化）方法は2通り：①リポジトリのフラグを `false` にしてコミット＆再デプロイ、または②サーバー上のフラグを `false`／削除（FTP。次回デプロイまで即時有効）。
- 秘密URLは SW の `NETWORK_FIRST_PATTERNS` 登録済みで、**フラグ `false` 化はキャッシュ済み端末にも即時反映**される。

### 0-3. 🟡 秘密URLから PWA をホーム追加しない
- manifest の `start_url` は `/baseball/`（＝メンテ中はメンテ画面）。秘密URLからインストールするとアイコンがメンテ画面に飛ぶ。検証はブラウザで秘密URLを直接開く。

### 0-4. 🔴 公開切替時はキャッシュバージョンを必ず bump
- 公開（メンテ→実アプリ）切替時は `version.json` / `service-worker.js` / `index.html` を**新バージョンへ同期**（現行 staging=v1065 → 公開=v1066）。SW再インストールで既存ユーザーへ確実に配信する。
- 補足：staging 中に app.js / service-worker.js を変更した場合は、その都度キャッシュバージョンを上げないと SW が旧アセットを配信し続ける（同一 `?v=` だとキャッシュが更新されない）。

### 0-5. 🟡 運用データは触らない
- `scores/` `requests/` `vendor/` は本番デプロイで除外。変更・初期化しない。
- `production_hold_enabled.flag` はリポジトリ管理・自動デプロイ対象（既定 `true`）。サーバー上で直接編集した場合は、次回デプロイでリポジトリの値に上書きされる点に注意。

### 0-6. 🟡 秘密URLは noindex
- 秘密URLファイルには `noindex,nofollow` を付与済み（検索インデックス回避）。トークンは推測困難な値を使用。

### 0-7. 🟡 野球博士チャレンジのライフはフラグ連動
- **flag=true（メンテ中）→ ライフ無制限（×∞）**、**flag=false／不在（公開後）→ 通常の1日5ライフ**。
- アプリ（`app.js`）が起動時に `production_hold_enabled.flag` を読み、`quizMasterLimitActive()` で判定する。フラグ自体は SW の `NETWORK_FIRST_PATTERNS` に登録済みで常に最新値を取得する。

---

## 0.5. 🔴 `production_hold_enabled.flag` 仕様（重要・最重要スイッチ）

このフラグ1つで段階リリースの挙動を一括制御する。**判定値**は大文字小文字を問わず `true` / `1` / `on` / `yes` を「有効（メンテ中）」、それ以外（`false`・空・**ファイル不在**・取得失敗）を「無効（公開）」とする（安全側＝不在は公開扱い）。

### このフラグが制御する3つの挙動

| 対象 | `true`（メンテ中／プレビュー） | `false`・不在（公開後） | 実装 |
|---|---|---|---|
| **① 秘密URL `index-preview-<token>.php`** | 利用可（本番アプリを表示・200） | 利用不可（案内ページ・403） | php先頭ゲート |
| **② ページ遷移（HTMLナビゲーション）** | **networkFirst**（常に最新＝メンテを配信。実アプリのキャッシュ漏れ防止） | **networkFirst**（公開切替後も古いHTMLを避ける） | `service-worker.js` fetch handler |
| **③ 野球博士チャレンジのライフ** | **無制限（×∞）** | **通常 1日5ライフ** | `app.js` `quizMasterLimitActive()` |

### 配置・管理
- **リポジトリ管理・Git追跡**。既定値は `true`。`deploy.yml`／`deploy-test.yml` 双方で配備され、テスト（`/baseball_test/`）・本番（`/baseball/`）の各直下に置かれる（`__DIR__` 基準。両環境で独立）。
- **常に最新値が使われる**：SW の `NETWORK_FIRST_PATTERNS` に `production_hold_enabled.flag` を登録済み。アプリ・SW とも古い値で固定されない。

### 切替方法（2通り）
1. **リポジトリで切替**：フラグの中身を `true`/`false` にしてコミット＆デプロイ（恒久的・推奨）。
2. **サーバーで切替**：FTPで該当環境の `production_hold_enabled.flag` を直接編集／削除（即時。ただし**次回デプロイでリポジトリの値に上書き**される）。

### 注意
- ②の networkFirst は、**新しい `service-worker.js` が有効化済みの端末**で有効（旧SW端末は切替直後の1回だけ旧キャッシュが出得る → 再読み込み1〜2回 or SW Unregister）。
- ③のライフ判定はアプリ**起動時**に確定する。プレイ中にフラグを変えても、反映は次回起動（再読み込み）後。
- 公開（リリース）完了後にこの仕組みごと撤去する場合は、[4. リリース手順](#4-リリース手順) のクリーンアップ（秘密php削除・SWパターン削除・フラグfalse）を行う。

---

## 1. 構成ファイル

| ファイル | 役割 | デプロイ | Git |
|---|---|---|---|
| `index.html` | 公開トップ＝**アップデート準備中ページ**（メンテナンス） | する | 追跡 |
| `index-preview-<token>.php` | **本番仕様の実アプリ（秘密URL）**。フラグで利用可否を判定 | する | 追跡 |
| `production_hold_enabled.flag` | 秘密URLの利用可否スイッチ（既定 `true`） | **する（test/本番）** | 追跡 |

現行の秘密URLファイル名: `index-preview-fa156bbda3.php`
本番URL例: `https://www.realemotionfactory.com/baseball/index-preview-fa156bbda3.php`

---

## 2. production_hold_enabled.flag による制御

秘密URL（`index-preview-<token>.php`）は、先頭の PHP ゲートで `production_hold_enabled.flag` を読み、利用可否を判定する。

| フラグの状態 | 秘密URLの挙動 |
|---|---|
| 内容が `true`（または `1` / `on` / `yes`、大文字小文字不問） | **利用可**：本番仕様の実アプリを表示（HTTP 200） |
| 内容が `false` / 空 / その他 | **利用不可**：案内ページを表示（HTTP 403） |
| ファイルが存在しない | **利用不可**（安全側デフォルト） |

- フラグは **リポジトリで管理**し（既定値 `true`）、`/baseball/`・`/baseball_test/` 直下へ自動デプロイされる。配備後すぐに秘密URLが有効になる。
- 有効化：リポジトリのフラグを `true` のままデプロイ（既定）。
- 無効化：①リポジトリのフラグを `false` にしてコミット＆再デプロイ、または②サーバーの `production_hold_enabled.flag` を `false`／削除（FTP・即時。ただし次回デプロイでリポジトリ値に上書き）。
- 置き場所は php と同階層（`__DIR__` 基準）。`/baseball/` と `/baseball_test/` でそれぞれ独立に存在する。

### Service Worker との整合
秘密URLは `service-worker.js` の `NETWORK_FIRST_PATTERNS` に登録済み（`/index-preview-[a-z0-9]+\.php`）。常にネットワーク経由でゲートを評価するため、**一度アクセスしてキャッシュ済みの端末でも、フラグを `false` にすれば即座に利用不可**になる（オフライン時のみ最後のキャッシュにフォールバック）。

### ページ遷移（index.html）のキャッシュ対策
`service-worker.js` は **HTMLナビゲーション（`request.mode === 'navigate'`）を常時 networkFirst** で配信する。`production_hold_enabled.flag` は、秘密URLの利用可否と野球博士ライフ制限に使う。

| 対象 | ページ遷移の戦略 | 目的 |
|---|---|---|
| 公開トップ `/baseball/` | **networkFirst** | 常に最新の `index.html` を配信。キャッシュ済みの実アプリが表示される漏れを防ぐ |
| 秘密URL `index-preview-*.php` | **networkFirst** | 常にPHPゲートを通し、フラグ変更を反映する |

- 注意：この挙動を含む新しい `service-worker.js` が**まだ有効化されていない旧SW端末では、切替直後の1回だけ旧キャッシュが表示され得る**（旧SWの挙動は遡って変えられないため）。新SW有効化後は上表の通り動作する。確実に切り替えたい場合はブラウザの再読み込みを1〜2回行う。
- 秘密URL（`index-preview-*.php`）はフラグに関係なく**常に networkFirst**（ゲートを必ず評価するため）。

---

## 3. リリース手順

### フェーズ1：トップ停止＋全データアップ（ステージング）
1. リポジトリを「`index.html`＝メンテ／`index-preview-<token>.php`＝実アプリ」の状態にする（本ドキュメント時点で構成済み）。
2. PWA キャッシュ整合性（v1065）を確認：`version.json` / `service-worker.js` / `index.html`。
   - 注：この段階の `index.html` はメンテ版。SW は `index.html`（メンテ）を precache する。
3. 本番へ反映（`main` にマージ → `deploy.yml` を `apply=true`）。`production_hold_enabled.flag`（既定 `true`）も一緒に配備され、秘密URLは有効状態になる。
4. （任意）一時的に秘密URLを止めたい場合のみ、サーバーの `production_hold_enabled.flag` を `false`／削除。
5. 既存ユーザーは SW 更新によりメンテ表示へ切り替わる。

### フェーズ2：秘密URLで本番検証
- `…/baseball/index-preview-<token>.php` を開き、本番環境で実アプリを検証する。
- 注意：このページから **PWA をホーム追加しない**（manifest の `start_url` は `/baseball/`＝メンテのため）。検証はブラウザで秘密URLを直接開く。

### フェーズ3：告知・配信時刻決定
- 管理画面でアップデート告知を登録し、配信時刻を決定する。

### フェーズ4：公開切替（リリース）
1. `index.html` を実アプリ版に差し替える（メンテ版を置換）。
2. `index-preview-<token>.php` を削除する。
3. **PWA キャッシュを v1066 へ bump**（`version.json` / `service-worker.js` の `CACHE_VERSION`・アセットクエリ / `index.html` の `YAKYU_CACHE_VERSION`・CSS/JSクエリ・`app-version`）。
   - これにより既存ユーザーの SW が再インストールされ、メンテ版 `index.html` を新アプリへ更新する。
4. `service-worker.js` の `NETWORK_FIRST_PATTERNS` から秘密URL用パターンを削除（任意。ファイル削除済みなら実害なし）。
5. `production_hold_enabled.flag` を `false` にしてコミット（または秘密URLファイル削除済みのため任意）。
6. 本番へ再反映（`deploy.yml` を `apply=true`）。秘密php は削除済み、フラグも `false` で二重に無効化される。
7. 公開後、表示・保存・ランキング・キャッシュ更新を確認。

---

## 4. 注意事項

- `scores/` `requests/` `vendor/` は本番デプロイで除外。運用データは変更・初期化しない。
- `production_hold_enabled.flag` はリポジトリ管理・自動デプロイ（既定 `true`）。サーバー直接編集は次回デプロイで上書きされる。
- 秘密URLファイルには `noindex,nofollow` を付与済み（検索インデックス回避）。
- トークン（ファイル名の `<token>` 部分）は推測困難な値にする。ローテーションする場合はファイル名を変更し、`service-worker.js` のパターンが `index-preview-[a-z0-9]+\.php` に一致することを確認する。
