# 野球博士チャレンジ 本番反映チェックリスト

更新日: 2026-06-27

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
