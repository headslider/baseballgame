# 野球博士チャレンジ 本番反映チェックリスト

更新日: 2026-06-27

関連重要メモ: `docs/production_incident_guardrails_20260627.md`

## 現在の本番設定

`assets/app.js` の以下フラグは本番用に有効化済み。

```js
const QUIZ_MASTER_DAILY_LIMIT_ENABLED=true;
const QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true;
```

`QUIZ_MASTER_DAILY_LIMIT` は `5`。ただし `production_hold_enabled.flag=true` の段階リリース中は、秘密URLプレビューでの検証を優先し、野球博士チャレンジのライフ制限は無制限（×∞）になる。`flag=false` またはファイル不在では通常の1日5ライフに戻る。

## 本番時の利用条件

野球博士チャレンジは、以下を満たすユーザーのみ利用可能にする。

- プレイヤーIDが存在している
- そのプレイヤーIDでログイン済み
- 招待IDを登録済み

`QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true` にすると、開始時に `get_features.php` で `player_features.json` の `sources` を確認し、招待ID由来の解放があるプレイヤーIDのみ許可する。

既存本番ユーザー互換として、過去に招待IDで解放済みのユーザーに `flags.quiz_master` が保存されていない場合でも、`sources` に招待ID由来の有効な機能が残っていればサーバー側で `quiz_master=true` として扱う。最高位管理者が招待ID由来の機能を解除した場合は `sources` が削除されるため、この互換補完では再解放されない。

この互換処理は `api/feature_common.php` の共通判定で行う。`api/get_features.php` だけ、またはフロントの `assets/app.js` だけで個別対応しない。開始時とスコア保存時の両方が同じ判定に乗る必要があるため、問題があった場合は `feature_flags_for_player()` と `player_has_quiz_master_access()` を最初に確認する。

## 段階リリース中の扱い

現在の段階リリース構成では、以下の扱いにする。

- 公開トップ `/baseball/` は `index.html` のメンテナンス画面。
- 秘密URL `/baseball/index-preview-fa156bbda3.php` は `production_hold_enabled.flag=true` のときだけ `app_shell.html` を表示する。
- `production_hold_enabled.flag=true` の間は、野球博士チャレンジのライフ制限は無制限。
- `QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true` のため、野球博士チャレンジはプレイヤーIDログインと機能解放が必要。

## 注意

`scores/` と `requests/` の本番運用データは変更・削除・初期化しない。

特に以下は本番固有データとして扱う。

- `scores/player_registry.csv`
- `scores/score_log.csv`
- `scores/player_features.json`
- `scores/invite_codes.json`
- `scores/quiz_master_scores.json`
- `requests/`

PWA更新時は、以下のバージョン整合性を確認する。公開入口は常に `index.html`。段階リリース中は `index.html` がメンテ画面で、秘密URL用ゲームHTMLとして `app_shell.html` を確認する。

- `app_shell.html`
- `service-worker.js`
- `version.json`

段階リリース中の `index.html` はメンテナンス画面であり、ゲーム本体のJS/CSSバージョンは秘密URL用の `app_shell.html` で確認する。本公開時は `index.html` を実アプリ版へ差し替え、同じバージョン整合性を `index.html` で確認する。`scores/release_versions.json` は本番運用データのため、管理画面以外から勝手に編集しない。

## 開始前読込・ライフ消費の仕様

野球博士チャレンジは、問題データ読込完了前にゲームを開始した扱いにしない。

- 読込中は `QUIZ_MASTER_STATE.loading=true`。
- 読込中のタイマー表示は `--`。
- 問題読込中・第1問開始前に「メニューに戻る」を押しても、ライフ消費警告は出さない。
- ライフ消費は `startQuizMaster()` ではなく、実際に第1問が始まる直前の `beginQuizMasterAttemptIfNeeded()` で行う。
- `attemptConsumed=false` または `questionStartedAt=0` の状態は、まだライフ消費済みのゲームとして扱わない。
- `roundToken` は古い非同期イントロ・読込処理を失効させるために使う。新規開始や開始前離脱時にリセットせず、必ず進める。

この仕様を崩すと、問題が表示されていないのにカウントだけ進む、または開始していないのにライフが減る事故が再発する。

## 背景椅子の仕様

野球博士チャレンジの椅子は背景画像に貼り付けて動かさない。

- 正しい表示元は `.quiz-master-shell::before` の `quiz_master_chair.webp`。
- HTML上の `.quiz-master-chair` は互換上残っていても、CSSの最終ルールで `display:none !important` にする。
- `.quiz-master-chair` を fixed/relative 配置で再表示すると、背景椅子と二重表示になる。
- 位置調整はPC用と `@media(max-width:999px)` の背景位置を同時に変更する。
