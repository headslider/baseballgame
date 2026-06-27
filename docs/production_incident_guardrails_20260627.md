# 2026-06-27 本番復旧で判明した重要事項

この文書は、2026-06-27 の本番公開・PWA更新・野球博士チャレンジ解放判定まわりで実際に問題になった箇所を、再発防止のためにまとめた運用ガードである。該当箇所を触る前に必ず確認する。

## 1. 今回問題になった箇所

| 領域 | 問題 | 原因 | 復旧方針 |
|---|---|---|---|
| 公開入口 | `/baseball/` が意図した画面にならない可能性 | `index.html`、`app_shell.html`、`production_hold_enabled.flag`、Service Worker の役割が混線 | 公開入口は常に `index.html`。段階リリース中だけ秘密URLが `app_shell.html` を出す |
| `index.php` 切替 | リロード時に表示が不安定になる | PHPで公開・プレビューを動的切替すると、PWAナビゲーションやリロードと相性が悪い | `index.php` 方式は使わない。静的 `index.html` と秘密URLPHPに分離する |
| PWAキャッシュ | 古いPWAで古い `index.html` が開かれ続ける | HTMLナビゲーションが `cacheFirst` だと、旧メンテ画面や旧アプリHTMLが残る | `request.mode === 'navigate'` は必ず `networkFirst(request)` |
| キャッシュ戦略 | どれを cacheFirst / networkFirst にするか不明確 | HTML、API、静的アセットを同じ扱いにすると不整合が出る | HTMLナビゲーション/API/秘密URL/flagは networkFirst。CSS/JS/画像は cacheFirst |
| 野球博士解放 | 既存の招待ID解放ユーザーが野球博士チャレンジを開始できない | 過去の `player_features.json` には `flags.quiz_master` が保存されていないユーザーがいる | `sources` に招待ID由来の有効機能が残っていれば、サーバー側で `quiz_master=true` として扱う |
| 遊び方ページ | 野球博士チャレンジ説明文が白文字で見えない | CSSのカンマ抜けにより `.option-feature-card` の濃色文字指定が効かず、親の `color:#fff !important` を継承 | セレクタのカンマを復旧し、カード内 `p` / `li` は濃色を明示する |
| マイページ大量表示 | 「その他の上位ランキング」や履歴一覧が大量に縦表示される | マイページ内の一覧が件数に応じた高さ制限・折りたたみを持たず、特に上位ランキングカードは1件の高さが低いため10件前後見えてしまう | 5件以上のマイページ一覧はアコーディオン化し、一覧種別ごとの1件の高さに合わせてスクロール上限を設定する |
| 招待解除済みユーザー | 互換救済で再解放されるリスク | 古い `flags` だけを見て補完すると解除済みまで復活する | 補完条件は「有効な invite source が残っていること」。解除処理は source を削除するため再解放しない |
| 本番運用データ | 復旧時に `scores/` を直接編集・上書きするリスク | 本番固有のプレイヤー・ID・スコア・監査データが入っている | コードで互換対応する。`scores/`、`requests/`、`vendor/` は原則変更しない |
| デプロイ | push しただけで本番反映されたと誤認 | 本番 `deploy.yml` は手動 `workflow_dispatch` のみ | `deploy.yml` を `apply=true` で実行して初めて本番反映 |

## 2. 搭載している仕様

### 公開入口と段階リリース

- 本番公開入口は常に `/baseball/index.html`。
- 通常公開中の `index.html` は実アプリ画面を持つ。
- 段階リリース中の `index.html` はメンテナンス画面にする。
- 秘密URL `index-preview-fa156bbda3.php` は、`production_hold_enabled.flag=true` のときだけ `app_shell.html` を `readfile` で表示する。
- `production_hold_enabled.flag=false` またはファイル不在では、秘密URLは利用不可にする。
- `index.php` による自動切替方式は採用しない。

### Service Worker / PWA

- `service-worker.js` の HTMLナビゲーションは常に `networkFirst`。
- 対象は `request.mode === 'navigate'`。
- 目的は、古いPWAでもネットワーク接続時に最新の `index.html` を確認させること。
- `api/`、`data/game_config.json`、`data/quiz_master_questions.json`、秘密URL、`production_hold_enabled.flag` は `networkFirst`。
- CSS、JS、画像、アイコンなどの静的アセットは `cacheFirst`。
- フロント更新時は `version.json`、`service-worker.js`、配信中のゲームHTMLのバージョンを同期する。
- 段階リリース中のゲームHTMLは `app_shell.html`、本公開後は `index.html` を確認対象にする。

### 野球博士チャレンジの解放判定

- 本番では `QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true`。
- 開始時は `api/get_features.php` で `player_features.json` 由来の機能フラグを取得する。
- スコア保存時は `api/save_quiz_master_score.php` でも `player_has_quiz_master_access()` を確認する。
- 新規の招待ID・管理者IDは `default_features_for_code_type()` により `quiz_master` を含む。
- 既存本番ユーザー互換として、`flags.quiz_master` が無くても、`sources` に招待ID由来の有効機能が残っていれば `feature_flags_for_player()` が `quiz_master=true` を補完する。
- 管理者IDは `admin_mode` があれば引き続き野球博士チャレンジを利用できる。
- 最高位管理者が招待ID由来機能を解除したユーザーは、解除処理で invite source が削除されるため、互換補完の対象外になる。

### マイページの一覧表示

- マイページは `screen-mypage` 内に、プロフィール、機能解放、サマリー、上位ランキング、過去の結果、間違いプレイチェック、野球博士チャレンジ履歴を表示する。
- 5件以上になる一覧は、ページ全体へ大量展開せず、アコーディオンで閉じられる状態にする。
- 5件以上の一覧は、開いた状態でも内部スクロールにし、マイページ全体が過度に長くならないようにする。
- 対象は以下。
  - `myTopRanksHtml()` が出す「その他の上位ランキング」または「あなたの上位ランキング」
  - `renderMyPage()` が出す「過去の結果」
  - `renderQuizMasterMyPage()` が出す「野球博士チャレンジ履歴」
  - `renderMistakeReviewSection()` が出す「間違えた問題一覧」「更新または停止された問題」
- 共通アコーディオンHTMLは `assets/app.js` の `myPageAccordionHtml(title,count,body,className)` を使う。
- 共通の見た目とスクロールは `assets/styles.css` の `.mypage-list-accordion` と `.mypage-list-scroll` で制御する。
- 「その他の上位ランキング」はカード1件の高さが履歴テーブルより低いため、`.rank-award-accordion .mypage-list-scroll` で専用の高さ制限を持つ。PCは約5カード分、スマホは縦積みカード約5件分でスクロール開始する。
- 5件以上でも中身を削除・省略してはいけない。表示は全件を保持し、スクロールで確認できるようにする。
- アコーディオンは初期状態 `open` でよい。ユーザーが閉じられること、内部だけスクロールできることが重要。
- ランキング専用ページ `screen-ranking` は別画面であり、今回のマイページ大量表示対策とは分けて扱う。ランキングページのTOP50表示をマイページ仕様に巻き込まない。

## 3. 触ってはいけない箇所

以下は本番運用データまたは環境依存データであり、通常の復旧・機能修正で直接編集しない。

- `scores/`
- `requests/`
- `vendor/`
- 本番サーバー上の `scores/player_registry.csv`
- 本番サーバー上の `scores/score_log.csv`
- 本番サーバー上の `scores/player_features.json`
- 本番サーバー上の `scores/invite_codes.json`
- 本番サーバー上の `scores/admin_codes.json`
- 本番サーバー上の `scores/quiz_master_scores.json`
- 本番サーバー上の `requests/` 配下の申請・監査ログ
- 管理画面以外からの `scores/release_versions.json` 更新
- マイページUI調整時のランキング・スコア保存API、実スコアデータ、招待IDデータ

特に、ZIPや検査データから `scores/` を丸ごと戻すことは禁止。既存プレイヤー、招待ID、ランキング、監査履歴が失われる。

## 4. 修正してはいけない方針

- `index.php` 方式へ戻さない。
- `/baseball/` の公開入口を `app_shell.html` や `index-preview-*.php` にしない。
- 段階リリース中の公開トップをゲーム本体入り `index.html` にしない。
- HTMLナビゲーションを `cacheFirst` に戻さない。
- `production_hold_enabled.flag` を Service Worker の networkFirst 対象から外さない。
- 秘密URLを cacheFirst 対象にしない。
- 招待ID互換のために本番 `player_features.json` を直接一括書換しない。
- 招待解除済みユーザーまで復活する条件で `quiz_master` を補完しない。
- CSSの複数セレクタからカンマを落とさない。特に `.option-feature-card, .option-feature-section .option-feature-card` と、未解放UIを隠す `body:not(...)` 系はカンマ抜けで意図した指定が無効になる。
- マイページの大量表示対策で、APIの返却件数や保存データ自体を減らさない。UI側で折りたたみ・スクロールする。
- 「その他の上位ランキング」を `slice(0,5)` などで切り捨てない。5件超は全件保持したまま内部スクロールにする。
- 上位ランキングカードの高さ制限を共通 `.mypage-list-scroll` だけに頼らない。カード高さが低いため、専用 `.rank-award-accordion .mypage-list-scroll` の上限を維持する。
- マイページ修正をランキングページ `screen-ranking` のTOP50表示へ不用意に波及させない。
- マイページUI修正だけで `api/get_ranking.php`、`api/save_score.php`、`api/save_quiz_master_score.php` を変更しない。必要性がある場合は別件として原因を切り分ける。
- `scores/`、`requests/`、`vendor/` をデプロイ対象に含めない。
- `git push` だけで本番反映済みと判断しない。

## 5. 問題があった場合の修正方法

### 公開トップがメンテ/ゲームの想定と違う

1. 現在の運用状態を確認する。
   - 通常公開: `index.html` は実アプリ。
   - 段階リリース: `index.html` はメンテ、秘密URLだけ `app_shell.html`。
2. `production_hold_enabled.flag` の値を確認する。
3. `index-preview-fa156bbda3.php` が `app_shell.html` を出しているか確認する。
4. `index.php` を復活させず、静的 `index.html` と秘密URLPHPの分離で直す。
5. 変更後は `version.json`、`service-worker.js`、配信中HTMLのバージョン整合性を確認する。

### 古いPWAで最新画面が出ない

1. `service-worker.js` の fetch handler を確認する。
2. `request.mode === 'navigate'` が `networkFirst(request)` になっていることを確認する。
3. `version.json` の `cache_version` と `service-worker.js` の `CACHE_VERSION` を同期する。
4. 配信中HTMLの CSS/JS クエリ、`window.YAKYU_CACHE_VERSION` を同期する。
5. デプロイ後、`/baseball/version.json` と `/baseball/service-worker.js` をキャッシュ避けクエリ付きで確認する。
6. 旧SW端末は1回だけ古いHTMLを返す可能性があるため、再読み込み1〜2回またはアプリ再起動を案内する。

### 既存招待IDユーザーが野球博士チャレンジできない

1. フロントだけでなく、必ず `api/feature_common.php` を確認する。
2. `feature_flags_for_player()` が `player_features.json` の `flags` と `sources` を読んでいるか確認する。
3. `flags.quiz_master` が無い既存ユーザーでも、invite source が有効なら `quiz_master=true` を補完する。
4. 補完条件は「`sources` の該当機能が `type=invite` で、かつ対応する `flags[feature]` が有効」であること。
5. `admin_mode` は引き続き許可する。
6. `api/get_features.php` と `api/save_quiz_master_score.php` の両方が同じ判定に乗るよう、共通関数で直す。
7. 本番 `scores/player_features.json` は直接編集しない。

### 招待解除済みユーザーが復活してしまう

1. `api/admin_api.php` の `revoke_invite_features` を確認する。
2. 解除処理が invite source を削除していることを確認する。
3. 互換補完が古い `flags` だけを見ていないことを確認する。
4. 補完は active invite source が残っているユーザーに限定する。

### 遊び方ページの文字が白くて見えない

1. `assets/styles.css` の `.option-feature-section` 周辺を確認する。
2. `.option-feature-card, .option-feature-section .option-feature-card` のカンマが抜けていないか確認する。
3. `.option-feature-section h3, .option-feature-section h4` のカンマが抜けていないか確認する。
4. 未解放UI非表示の `body:not(...)` 複数セレクタにもカンマがあるか確認する。
5. `.option-feature-section .option-feature-card p` と `.option-feature-section .option-feature-card li` に濃色文字が明示されているか確認する。
6. CSSを直したら `index.html`、`app_shell.html`、`service-worker.js`、`version.json` を同じ新バージョンへ同期する。

### マイページの一覧が大量表示される

1. まず問題がマイページ `screen-mypage` なのか、ランキングページ `screen-ranking` なのかを切り分ける。
2. マイページの場合、`assets/app.js` の以下を確認する。
   - `myTopRanksHtml()` の「その他の上位ランキング」
   - `renderMyPage()` の「過去の結果」
   - `renderQuizMasterMyPage()` の「野球博士チャレンジ履歴」
   - `renderMistakeReviewSection()` の間違い一覧
3. 5件以上の一覧が `myPageAccordionHtml()` を通っているか確認する。
4. `assets/styles.css` に `.mypage-list-accordion` と `.mypage-list-scroll` があり、`max-height` と `overflow:auto` が効いているか確認する。
5. 「その他の上位ランキング」だけ多く見える場合は、`.rank-award-accordion .mypage-list-scroll` の高さを調整する。共通 `.mypage-list-scroll` をむやみに低くすると、過去の結果テーブルや間違い一覧が窮屈になる。
6. PCとスマホでカード高さが違うため、`@media(max-width:720px)` 内の `.rank-award-accordion .mypage-list-scroll` も同時に確認する。
7. `slice(0,5)` による件数削減では直さない。ユーザーは全履歴・全入賞をスクロールして確認できる必要がある。
8. 修正後は `index.html`、`app_shell.html`、`service-worker.js`、`version.json` を同じ新バージョンへ同期する。
9. 実機確認では、5件以上の「その他の上位ランキング」が約5カード分でスクロールし、summaryを押すと閉じられることを確認する。

### デプロイ後に反映されない

1. `main` に対象コミットが push 済みか確認する。
2. GitHub Actions の `deploy.yml` を `apply=true` で実行したか確認する。
3. `apply=false` は dry-run なので本番へ反映されない。
4. 本番確認は以下を直接見る。
   - `https://www.realemotionfactory.com/baseball/version.json?<任意の確認クエリ>`
   - `https://www.realemotionfactory.com/baseball/service-worker.js?<任意の確認クエリ>`
   - `https://www.realemotionfactory.com/baseball/`
5. `scores/`、`requests/`、`vendor/` がデプロイ差分に含まれていないことを確認する。

## 6. 最低限の検証コマンド

PHP構文確認:

```powershell
php -l api/feature_common.php
php -l api/get_features.php
php -l api/save_quiz_master_score.php
```

PWA公開ファイル確認:

```powershell
Invoke-WebRequest -Uri 'https://www.realemotionfactory.com/baseball/version.json?check=YYYYMMDDHHMM' -UseBasicParsing | Select-Object -ExpandProperty Content
Invoke-WebRequest -Uri 'https://www.realemotionfactory.com/baseball/service-worker.js?check=YYYYMMDDHHMM' -UseBasicParsing | Select-Object -ExpandProperty Content | Select-String -Pattern 'CACHE_VERSION|request.mode|navigate|networkFirst'
```

Git差分確認:

```powershell
git status --short
git diff --name-only
```

差分に `scores/`、`requests/`、`vendor/` が含まれている場合は、原則コミットしてはいけない。

マイページ一覧修正後の確認:

```powershell
Select-String -Path assets/app.js -Pattern "myPageAccordionHtml|rank-award-accordion|records-history-accordion|quiz-master-history-accordion"
Select-String -Path assets/styles.css -Pattern "mypage-list-accordion|mypage-list-scroll|rank-award-accordion"
Select-String -Path version.json,service-worker.js,index.html,app_shell.html -Pattern "v<新番号>|yakyu-yarouze-v<新番号>-production|\\?v=<新番号>"
```

## 7. 今回の復旧で入った重要実装

- `service-worker.js`: HTMLナビゲーションを `networkFirst` にして、古いPWAでも最新 `index.html` を優先取得する。
- `api/feature_common.php`: 既存招待IDユーザー互換として active invite source から `quiz_master` を補完する。
- `docs/quiz_master_production_checklist.md`: 既存招待IDユーザー互換の仕様を明記する。
- `assets/styles.css`: 遊び方ページのオプション機能カードは、親の白文字を継承しないようカード本文を濃色で明示する。
- `assets/app.js`: マイページの5件以上の一覧を `myPageAccordionHtml()` で折りたたみ可能にする。
- `assets/styles.css`: `.mypage-list-scroll` と `.rank-award-accordion .mypage-list-scroll` で、一覧種別ごとにスクロール上限を制御する。

この実装は、同じ症状が再発したときの最初の確認ポイントである。
