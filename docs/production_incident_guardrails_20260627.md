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
| マイページ大量表示 | 「その他の上位ランキング」や履歴一覧が大量に縦表示される | マイページ内の一覧が件数に応じた高さ制限・折りたたみを持たず、特に上位ランキングカードは1件の高さが低いため10件前後見えてしまう | 5件以上はアコーディオン化して内部スクロール、10件以上はスクロール上部にページネーションを置く |
| お知らせ大量表示 | お知らせページに通知履歴が大量に縦表示される | `renderNotices()` が取得した全件を一括描画していた | 5件以上はお知らせ一覧を内部スクロール、10件以上はページネーションで10件ずつ表示する |
| PWA日次ライフ更新 | PWAを開きっぱなしにすると翌日になっても野球博士チャレンジのライフ表示が更新されない | 日付キーは変わるが、リロードや画面操作がない限りUI再描画が発火しない | JST日付変更タイマー、1分ポーリング、PWA復帰時の再判定で `updateQuizMasterDailyUI()` を実行する |
| 野球博士開始前進行 | 問題読込中なのにカウントだけ進み、メニューへ戻るとライフ消費警告が出る | 問題未確定の画面状態と古い非同期イントロ処理が残り、タイマー・ライフ消費の開始条件が曖昧だった | `loading`、`attemptConsumed`、`roundToken` で開始前状態を明示し、問題開始直前までタイマーとライフ消費を開始しない |
| 野球博士の椅子二重表示 | ゲーム画面の椅子が二重に見える | `quiz_master_chair.webp` が背景疑似要素とHTMLの `.quiz-master-chair` の2系統で描画されていた | 椅子は背景レイヤー固定が正仕様。HTMLの椅子imgは全画面で非表示にし、背景側だけ表示する |
| ゲームデータ読込全般 | 画面遷移後や連打時に古い通信結果が表示を上書きするリスク | 各画面の読込処理に timeout とロード世代管理が統一されていなかった | `fetchJsonWithTimeout()`、`GAME_DATA_LOAD_TOKEN`、`RANKING_LOAD_TOKEN`、`NOTICES_LOAD_TOKEN` で読込結果を制御する |
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

### ゲームデータ読込ガード

- データ読込は「表示だけ先に出す」場合でも、ゲーム開始条件を必ず分離する。
- 通常ゲームは `ensureQuestionsLoaded(false)` で公開問題API `api/get_game_questions.php?v=838` を読む。
- 通常ゲームの問題データは初回ロード後 `STATE.questions` に保持する。明示的な再読込が必要な管理用途以外で毎回 `forceReload=true` に戻さない。
- `api/get_game_questions.php` は `data/questions.json` を直接公開せず、`question_status` により draft/disabled を除外する安全ゲートである。通常ゲームから未フィルタの `data/questions.json` へフォールバックしてはいけない。
- 共通JSON取得は `fetchJsonWithTimeout(url, options, timeoutMs)` を使う。GETは既定で `cache:"no-store"` にする。
- 通常ゲームの重い問題APIは 12秒 timeout、野球博士問題JSONは 10秒 timeout、野球博士の統計・称号、お知らせは 7秒 timeout、ランキングは 9秒 timeout を目安にする。
- 画面遷移後に古い通信結果が戻ってきても表示を上書きしないよう、読込開始時にトークンを採番して完了時に一致確認する。
- 現在のロードトークンは以下。
  - `GAME_DATA_LOAD_TOKEN`: 通常ゲームの問題データ読込。
  - `RANKING_LOAD_TOKEN`: ランキングページ読込。
  - `NOTICES_LOAD_TOKEN`: お知らせページ読込。
- `show(id)` で別画面へ移動した場合、該当しない画面のロードトークンを進め、未完了ロード結果を破棄する。
- ロードトークンは通信キャンセルそのものではなく「戻ってきた古い結果を無視する」ためのガードである。
- データ読込改善時に、API返却データや保存データを削って軽くする変更を混ぜない。API軽量化は別件として設計する。

### 野球博士チャレンジの解放判定

- 本番では `QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true`。
- 開始時は `api/get_features.php` で `player_features.json` 由来の機能フラグを取得する。
- スコア保存時は `api/save_quiz_master_score.php` でも `player_has_quiz_master_access()` を確認する。
- 新規の招待ID・管理者IDは `default_features_for_code_type()` により `quiz_master` を含む。
- 既存本番ユーザー互換として、`flags.quiz_master` が無くても、`sources` に招待ID由来の有効機能が残っていれば `feature_flags_for_player()` が `quiz_master=true` を補完する。
- 管理者IDは `admin_mode` があれば引き続き野球博士チャレンジを利用できる。
- 最高位管理者が招待ID由来機能を解除したユーザーは、解除処理で invite source が削除されるため、互換補完の対象外になる。

### 野球博士チャレンジの日次ライフ更新

- デイリーライフの利用回数・通常ゲーム完了ボーナスは、`quizMasterTodayKey()` のJST日付を含む localStorage キーで日別管理する。
- PWAを開いたまま日付が変わる場合でも、リロードなしで表示が更新される必要がある。
- `setupQuizMasterDailyRefresh()` は以下を行う。
  - JST 24:00:05 付近に `updateQuizMasterDailyUI()` を実行するタイマーを予約する。
  - PWA/ブラウザのタイマー停止に備え、1分ごとに日付変更を確認する。
  - `visibilitychange`、`pageshow`、`focus` でアプリ復帰時に日付変更と `production_hold_enabled.flag` を再確認する。
- ライフ上限や使用回数を手動で移行・削除しない。日付キーが変われば新しい日のキーを読み、自然に残り回数が戻る。
- 通常ゲーム完了ボーナスも日付キー付きなので、翌日は再度1回だけ獲得できる。

### 野球博士チャレンジの開始条件・読込中仕様

- 野球博士チャレンジは、問題データ読込が完了するまでタイマーを開始しない。
- 読込中は `QUIZ_MASTER_STATE.loading=true` とし、タイマー表示は `--` にする。
- 読込中・開始前に「メニューに戻る」を押した場合は、ライフ消費警告を出さずにメニューへ戻す。
- ライフ消費は `startQuizMaster()` の読込直後ではなく、`startQuizMasterRoundIntro()` の最後、実際に第1問が開始される直前の `beginQuizMasterAttemptIfNeeded()` で行う。
- `QUIZ_MASTER_STATE.attemptConsumed` が `true` になる前は、ライフを消費したゲームとして扱わない。
- `QUIZ_MASTER_STATE.questionStartedAt` がセットされる前は、回答時間・タイマー・警告付き中断の対象にしない。
- `roundToken` は古い非同期イントロや読込処理を失効させるための世代番号である。新規開始・開始前メニュー戻り・警告OK時には進める。
- `tickQuizMasterTimer()` と `resumeQuizMasterTimerAfterPrompt()` は、`loading`、`animating`、`questionStartedAt` を確認し、開始前にカウントを進めてはいけない。
- 問題データ読込失敗時は、問題パネルに失敗メッセージを表示し、タイマー・ライフ消費・スコア保存を開始しない。

### 野球博士チャレンジの背景椅子仕様

- 椅子は `assets/styles.css` の `.quiz-master-shell::before` 背景レイヤーとして表示する。
- HTMLの `<img class="quiz-master-chair" src="assets/quiz_master_chair.webp">` は互換上残っているが、全画面で `display:none !important` にする。
- `.quiz-master-chair` を再表示したり、fixed配置で動かす仕様に戻すと、背景椅子と二重表示になる。
- 背景椅子の位置はPC・モバイルとも `url("quiz_master_chair.webp") center calc(56% + 5px) / ... no-repeat` を基準にする。
- モバイルでは椅子を独立要素としてスクロールさせず、背景と一体で動かす。

### マイページの一覧表示

- マイページは `screen-mypage` 内に、プロフィール、機能解放、サマリー、上位ランキング、過去の結果、間違いプレイチェック、野球博士チャレンジ履歴を表示する。
- 5件以上になる一覧は、ページ全体へ大量展開せず、アコーディオンで閉じられる状態にする。
- 5件以上の一覧は、開いた状態でも内部スクロールにし、マイページ全体が過度に長くならないようにする。
- 10件以上の一覧は、内部スクロールの上部にページネーションを置き、10件ずつ描画する。
- 対象は以下。
  - `myTopRanksHtml()` が出す「その他の上位ランキング」または「あなたの上位ランキング」
  - `renderMyPage()` が出す「過去の結果」
  - `renderQuizMasterMyPage()` が出す「野球博士チャレンジ履歴」
  - `renderMistakeReviewSection()` が出す「間違えた問題一覧」「更新または停止された問題」
- 共通アコーディオンHTMLは `assets/app.js` の `myPageAccordionHtml(title,count,body,className)` を使う。
- 共通ページングは `listPageState()` と `listPaginationHtml()` を使う。ページサイズは `MY_PAGE_LIST_PAGE_SIZE=10`。
- 共通の見た目とスクロールは `assets/styles.css` の `.mypage-list-accordion` と `.mypage-list-scroll` で制御する。
- 「その他の上位ランキング」はカード1件の高さが履歴テーブルより低いため、`.rank-award-accordion .mypage-list-scroll` で専用の高さ制限を持つ。PCは約5カード分、スマホは縦積みカード約5件分でスクロール開始する。
- 5件以上でも中身を削除・省略してはいけない。表示は全件を保持し、スクロールで確認できるようにする。
- アコーディオンは初期状態 `open` でよい。ユーザーが閉じられること、内部だけスクロールできることが重要。
- ランキング専用ページ `screen-ranking` は別画面であり、今回のマイページ大量表示対策とは分けて扱う。ランキングページのTOP50表示をマイページ仕様に巻き込まない。

### 端末内UI設定の保存

- 学年・守備位置の前回選択は localStorage に保存し、同じプレイヤーIDで再アクセスしたときに自動復元する。
- 保存キーは `baseballGameSelection:<PLAYER_ID>`。未ログイン時は `guest` として扱う。
- 保存するのはUI選択だけであり、学年解放状態やスコア、ランキングは変更しない。
- 復元後も `updateGradeOptions()` で解放済み学年に合わせてクランプする。未解放学年をlocalStorageで強制選択できる仕様にしてはいけない。
- アコーディオン開閉状態は `baseballAccordionOpen:<PLAYER_ID>:<accordion-key>` に保存する。
- `details` 要素は `bindPersistentAccordions()` で保存・復元する。動的生成後は必ず再バインドする。
- `details` ではない独自アコーディオンは `savedAccordionOpen()` / `saveAccordionOpen()` で保存する。

### お知らせページの一覧表示

- お知らせページは `screen-notices` の `renderNotices()` で表示する。
- 5件以上のお知らせは `.notice-list-scroll` で内部スクロールにする。
- 10件以上のお知らせは `listPaginationHtml("notices",...)` を使い、スクロール領域の上にページネーションを出す。
- お知らせ本文は `details.notice-item` の中身として保持する。本文や通知履歴を削除・省略してはいけない。
- お知らせ取得API `api/get_public_notices.php` の返却件数をUI都合で削らない。大量表示対策はフロント表示層で行う。

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
- マイページやお知らせの大量表示対策で、APIの返却件数や保存データ自体を減らさない。UI側で折りたたみ・スクロール・ページネーションする。
- 「その他の上位ランキング」を `slice(0,5)` などで切り捨てない。5件超は全件保持したまま内部スクロールにする。
- 上位ランキングカードの高さ制限を共通 `.mypage-list-scroll` だけに頼らない。カード高さが低いため、専用 `.rank-award-accordion .mypage-list-scroll` の上限を維持する。
- マイページ修正をランキングページ `screen-ranking` のTOP50表示へ不用意に波及させない。
- マイページUI修正だけで `api/get_ranking.php`、`api/save_score.php`、`api/save_quiz_master_score.php` を変更しない。必要性がある場合は別件として原因を切り分ける。
- お知らせUI修正だけで `api/get_public_notices.php` の返却件数や保存データを変更しない。
- 野球博士の日次ライフ更新のために localStorage の過去キーを一括削除しない。更新発火の問題は `setupQuizMasterDailyRefresh()` 側で直す。
- 日次ライフ更新目的で強制リロードを標準仕様にしない。PWAでは利用中のゲームや入力状態を失うため、UI再判定で解決する。
- 野球博士の問題読込中にタイマーやライフ消費を開始しない。`loading`、`attemptConsumed`、`questionStartedAt` のガードを外さない。
- 野球博士の「メニューに戻る」ボタンで、開始前・読込中にライフ消費警告を出さない。
- 野球博士のライフ消費を `startQuizMaster()` の読込直後へ戻さない。実際の第1問開始直前にだけ消費する。
- `.quiz-master-chair` を表示・アニメーション対象に戻さない。椅子は背景固定レイヤーが正仕様。
- 通常ゲームの `ensureQuestionsLoaded(false)` を安易に `true` へ戻さない。毎回2MB級の問題データ再取得になり、開始遅延と読込中事故の原因になる。
- 通常ゲームから未フィルタの `data/questions.json` へフォールバックしない。draft/disabled が出題されるリスクがある。
- ランキングやお知らせのtimeout・ロードトークンを外さない。画面遷移後の古い通信結果が表示を上書きする原因になる。
- 学年・ポジション復元のために、学年解放判定 `isSelectedGradeUnlocked()` や `maxUnlockedGrade()` を迂回しない。
- アコーディオン保存のために、ユーザーのスコア・ランキング・通知本文などの業務データをlocalStorageへ複製しない。
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
8. 10件以上の場合、ページネーションがスクロール領域の上部に表示され、ページ切替で10件ずつ描画されることを確認する。
9. 修正後は `index.html`、`app_shell.html`、`service-worker.js`、`version.json` を同じ新バージョンへ同期する。
10. 実機確認では、5件以上の「その他の上位ランキング」が約5カード分でスクロールし、summaryを押すと閉じられることを確認する。

### お知らせページの一覧が大量表示される

1. `assets/app.js` の `renderNotices()` を確認する。
2. 5件以上のお知らせが `.notice-list-scroll` の内部スクロールに入っているか確認する。
3. 10件以上の場合、`NOTICE_LIST_PAGE` と `listPaginationHtml("notices",...)` で10件ずつ表示しているか確認する。
4. ページネーションはスクロール領域の上に置く。スクロール領域の中に入れると操作が見つけづらくなる。
5. `api/get_public_notices.php` 側で返却件数を削らない。
6. 修正後は `index.html`、`app_shell.html`、`service-worker.js`、`version.json` を同じ新バージョンへ同期する。

### PWAで翌日になっても野球博士ライフが戻らない

1. `assets/app.js` の `quizMasterTodayKey()` がJST基準で日付を返していることを確認する。
2. `quizMasterDailyStorageKey()` と `quizMasterBonusLifeStorageKey()` が `quizMasterTodayKey()` を含んでいることを確認する。
3. `setupQuizMasterDailyRefresh()` が初期化時に呼ばれているか確認する。
4. `scheduleQuizMasterDailyRefresh()` がJST 24:00:05 付近に `refreshQuizMasterDailyState(true)` を実行することを確認する。
5. `visibilitychange`、`pageshow`、`focus` で `refreshQuizMasterDailyState(false)` と `refreshQuizMasterHoldPreview()` が走ることを確認する。
6. 強制リロードや localStorage 削除ではなく、`updateQuizMasterDailyUI()` の再実行で直す。

### 野球博士で問題読込中にタイマーだけ進む・ライフ警告が出る

1. `assets/app.js` の `QUIZ_MASTER_STATE.loading`、`attemptConsumed`、`questionStartedAt` を確認する。
2. `startQuizMaster()` が読込開始時に `loading=true`、`attemptConsumed=false`、`questionStartedAt=0`、`quizMasterTimer="--"` にしているか確認する。
3. `loadQuizMasterQuestions()`、`loadQuizMasterQuestionStats()`、`loadQuizMasterTitles()` が `fetchJsonWithTimeout()` を使っているか確認する。
4. 読込完了前に `quizMasterConsumeDailyAttempt()` が呼ばれていないか確認する。
5. ライフ消費は `beginQuizMasterAttemptIfNeeded()` だけで行う。
6. `startQuizMasterRoundIntro()` の最後、`questionStartedAt=Date.now()` の直前に `beginQuizMasterAttemptIfNeeded()` があることを確認する。
7. `quizMasterExitBtn` のクリック処理が、`loading` または `!questionStartedAt` または `!attemptConsumed` の場合に警告なしでメニューへ戻すことを確認する。
8. `tickQuizMasterTimer()` が `loading`、`animating`、`!questionStartedAt` の場合に何もしないことを確認する。
9. 修正後は `assets/app.js` の構文チェックと、野球博士開始直後に「問題データを読み込み中...」でカウントが進まないことをテスト環境で確認する。

### 野球博士の椅子が二重表示される

1. `assets/styles.css` の `.quiz-master-shell::before` に `quiz_master_chair.webp` があることを確認する。
2. `index.html` と `app_shell.html` に `<img class="quiz-master-chair">` が残っていてもよいが、CSSの最終ルールで `.quiz-master-chair{display:none !important;}` になっていることを確認する。
3. `.quiz-master-chair` を fixed/relative で表示する過去CSSを復活させない。
4. 椅子位置調整は `.quiz-master-shell::before` の背景位置だけを変更する。PC用と `@media(max-width:999px)` のモバイル用を同時に揃える。
5. 二重表示が再発した場合は、`quiz_master_chair.webp` の参照数と最終CSSルールを確認する。

### 通常ゲーム・ランキング・お知らせの読込結果が遅れて上書きされる

1. `assets/app.js` の `GAME_DATA_LOAD_TOKEN`、`RANKING_LOAD_TOKEN`、`NOTICES_LOAD_TOKEN` を確認する。
2. `show(id)` で該当しない画面のトークンを進めているか確認する。
3. `startGame()` は `const loadToken=++GAME_DATA_LOAD_TOKEN` を採番し、`ensureQuestionsLoaded(false)` 後に一致確認しているか確認する。
4. `openRanking()` と `openNotices()` は読込開始時にトークンを採番し、描画直前に一致確認と画面active確認をしているか確認する。
5. `fetchJsonWithTimeout()` が使われているか確認する。timeoutなしの直接 `fetch()` に戻さない。
6. 通常ゲームの問題APIは `api/get_game_questions.php?v=838` を読む。未フィルタの `data/questions.json` を直接読ませない。
7. 修正後は、読込中に別画面へ戻っても古い結果が表示を上書きしないことを確認する。

### 学年・ポジションやアコーディオン開閉が復元されない

1. `assets/app.js` の `saveGameSelection()` と `restoreGameSelection()` を確認する。
2. ログイン後の `setLoggedInPlayer()` と初期化時に `restoreGameSelection()` が呼ばれているか確認する。
3. 学年・ポジション変更時に `updateGradeOptionsAndSave()` が呼ばれているか確認する。
4. 動的に描画される画面では、描画後に `bindPersistentAccordions()` が呼ばれているか確認する。
5. 独自アコーディオンの場合は `savedAccordionOpen()` と `saveAccordionOpen()` を使っているか確認する。
6. 未解放学年を復元しようとしていないか、`updateGradeOptions()` によるクランプを確認する。

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
Select-String -Path assets/app.js -Pattern "listPageState|listPaginationHtml|NOTICE_LIST_PAGE"
Select-String -Path assets/app.js -Pattern "setupQuizMasterDailyRefresh|scheduleQuizMasterDailyRefresh|refreshQuizMasterDailyState|quizMasterNextJstMidnightDelay"
Select-String -Path assets/app.js -Pattern "saveGameSelection|restoreGameSelection|bindPersistentAccordions|savedAccordionOpen"
Select-String -Path assets/app.js -Pattern "GAME_DATA_LOAD_TOKEN|RANKING_LOAD_TOKEN|NOTICES_LOAD_TOKEN|fetchJsonWithTimeout|beginQuizMasterAttemptIfNeeded"
Select-String -Path assets/app.js -Pattern "loading|attemptConsumed|questionStartedAt|roundToken|quizMasterTimer"
Select-String -Path assets/styles.css -Pattern "mypage-list-accordion|mypage-list-scroll|rank-award-accordion|notice-list-scroll|mypage-list-pagination"
Select-String -Path assets/styles.css -Pattern "quiz-master-shell::before|quiz-master-chair|quiz_master_chair.webp"
Select-String -Path version.json,service-worker.js,index.html,app_shell.html -Pattern "v<新番号>|yakyu-yarouze-v<新番号>-production|\\?v=<新番号>"
```

## 7. 今回の復旧で入った重要実装

- `service-worker.js`: HTMLナビゲーションを `networkFirst` にして、古いPWAでも最新 `index.html` を優先取得する。
- `api/feature_common.php`: 既存招待IDユーザー互換として active invite source から `quiz_master` を補完する。
- `docs/quiz_master_production_checklist.md`: 既存招待IDユーザー互換の仕様を明記する。
- `assets/styles.css`: 遊び方ページのオプション機能カードは、親の白文字を継承しないようカード本文を濃色で明示する。
- `assets/app.js`: マイページの5件以上の一覧を `myPageAccordionHtml()` で折りたたみ可能にする。
- `assets/app.js`: マイページとお知らせの10件以上の一覧を `listPageState()` / `listPaginationHtml()` で10件ずつ表示する。
- `assets/app.js`: `setupQuizMasterDailyRefresh()` でPWA起動中・復帰時の日付変更を検知し、野球博士チャレンジのライフUIをリロードなしで更新する。
- `assets/app.js`: `saveGameSelection()` / `restoreGameSelection()` で学年・ポジションをプレイヤーID別に保存し、`bindPersistentAccordions()` でアコーディオン開閉状態を保存する。
- `assets/app.js`: `loading`、`attemptConsumed`、`roundToken`、`beginQuizMasterAttemptIfNeeded()` で野球博士の読込中タイマー開始・開始前ライフ消費を防止する。
- `assets/app.js`: `fetchJsonWithTimeout()`、`GAME_DATA_LOAD_TOKEN`、`RANKING_LOAD_TOKEN`、`NOTICES_LOAD_TOKEN` で通常ゲーム・ランキング・お知らせの読込結果を制御する。
- `assets/styles.css`: `.mypage-list-scroll`、`.notice-list-scroll`、`.rank-award-accordion .mypage-list-scroll` で、一覧種別ごとにスクロール上限を制御する。
- `assets/styles.css`: 野球博士の椅子は `.quiz-master-shell::before` の背景固定レイヤーで表示し、`.quiz-master-chair` は二重表示防止のため非表示にする。

この実装は、同じ症状が再発したときの最初の確認ポイントである。
