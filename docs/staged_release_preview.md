# 段階リリース（メンテ＋秘密URLプレビュー）運用ガイド

更新日: 2026-06-26

大型リリース時に「公開トップを一旦停止 → 全データを本番へアップ → 秘密URLで本番検証 → 告知・時刻決定 → 公開切替」を行うための仕組みと手順をまとめる。

---

## 1. 構成ファイル

| ファイル | 役割 | デプロイ | Git |
|---|---|---|---|
| `index.html` | 公開トップ＝**アップデート準備中ページ**（メンテナンス） | する | 追跡 |
| `index-preview-<token>.php` | **本番仕様の実アプリ（秘密URL）**。フラグで利用可否を判定 | する | 追跡 |
| `production_hold_enabled.flag` | 秘密URLの利用可否スイッチ | **しない（手動管理）** | **.gitignore（追跡しない）** |

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

- フラグは **サーバー上に手動で作成・編集**する（FTP）。Git では `.gitignore` 済みでコミットされず、`deploy.yml`（本番）でもアップロード除外されている。CI のクリーンチェックアウトにも存在しないため、**初期状態は「利用不可」**。
- 有効化：サーバーの `/baseball/`（テストは `/baseball_test/`）直下に `production_hold_enabled.flag` を置き、内容を `true` にする。
- 無効化：内容を `false` にする、またはファイルを削除する。

### Service Worker との整合
秘密URLは `service-worker.js` の `NETWORK_FIRST_PATTERNS` に登録済み（`/index-preview-[a-z0-9]+\.php`）。常にネットワーク経由でゲートを評価するため、**一度アクセスしてキャッシュ済みの端末でも、フラグを `false` にすれば即座に利用不可**になる（オフライン時のみ最後のキャッシュにフォールバック）。

### ページ遷移（index.html）のキャッシュ対策
`service-worker.js` は **HTMLナビゲーション（`request.mode === 'navigate'`）を networkFirst** で処理する。
これにより、メンテナンス公開後に**キャッシュ済みの実アプリが一度表示されてしまう問題を防ぐ**（オンライン時は常に最新の `index.html`＝メンテ/公開版を配信。オフライン時のみキャッシュにフォールバック）。
- 注意：この networkFirst 化を含む新しい `service-worker.js` が**まだ有効化されていない旧SW端末では、切替直後の1回だけ旧キャッシュが表示され得る**（旧SWの挙動は遡って変えられないため）。新SWが有効化された以降は、毎回のページ遷移で最新が配信される。確実に切り替えたい場合はブラウザの再読み込みを1〜2回行う。

---

## 3. リリース手順

### フェーズ1：トップ停止＋全データアップ（ステージング）
1. リポジトリを「`index.html`＝メンテ／`index-preview-<token>.php`＝実アプリ」の状態にする（本ドキュメント時点で構成済み）。
2. PWA キャッシュ整合性（v1064）を確認：`version.json` / `service-worker.js` / `index.html`。
   - 注：この段階の `index.html` はメンテ版。SW は `index.html`（メンテ）を precache する。
3. 本番へ反映（`main` にマージ → `deploy.yml` を `apply=true`）。
4. 本番サーバーに `production_hold_enabled.flag = true` を手動作成（秘密URLを有効化）。
5. 既存ユーザーは SW 更新によりメンテ表示へ切り替わる。

### フェーズ2：秘密URLで本番検証
- `…/baseball/index-preview-<token>.php` を開き、本番環境で実アプリを検証する。
- 注意：このページから **PWA をホーム追加しない**（manifest の `start_url` は `/baseball/`＝メンテのため）。検証はブラウザで秘密URLを直接開く。

### フェーズ3：告知・配信時刻決定
- 管理画面でアップデート告知を登録し、配信時刻を決定する。

### フェーズ4：公開切替（リリース）
1. `index.html` を実アプリ版に差し替える（メンテ版を置換）。
2. `index-preview-<token>.php` を削除する。
3. **PWA キャッシュを v1065 へ bump**（`version.json` / `service-worker.js` の `CACHE_VERSION`・アセットクエリ / `index.html` の `YAKYU_CACHE_VERSION`・CSS/JSクエリ・`app-version`）。
   - これにより既存ユーザーの SW が再インストールされ、メンテ版 `index.html` を新アプリへ更新する。
4. `service-worker.js` の `NETWORK_FIRST_PATTERNS` から秘密URL用パターンを削除（任意。ファイル削除済みなら実害なし）。
5. 本番へ再反映（`deploy.yml` を `apply=true`）。
6. 本番サーバーの `production_hold_enabled.flag` を `false`／削除（秘密URLを無効化。ファイル自体も削除済みのため二重に無効）。
7. 公開後、表示・保存・ランキング・キャッシュ更新を確認。

---

## 4. 注意事項

- `scores/` `requests/` `vendor/` は本番デプロイで除外。運用データは変更・初期化しない。
- `production_hold_enabled.flag` は **コミットしない／本番へ自動デプロイしない**。サーバーで手動管理する。
- 秘密URLファイルには `noindex,nofollow` を付与済み（検索インデックス回避）。
- トークン（ファイル名の `<token>` 部分）は推測困難な値にする。ローテーションする場合はファイル名を変更し、`service-worker.js` のパターンが `index-preview-[a-z0-9]+\.php` に一致することを確認する。
