# 野球博士チャレンジ 本番反映チェックリスト

更新日: 2026-06-19

## 本番反映時に必ず有効化する設定

`assets/app.js` の以下フラグを本番用に変更する。

```js
const QUIZ_MASTER_DAILY_LIMIT_ENABLED=true;
const QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true;
```

## 本番時の利用条件

野球博士チャレンジは、以下を満たすユーザーのみ利用可能にする。

- プレイヤーIDが存在している
- そのプレイヤーIDでログイン済み
- 招待IDを登録済み

`QUIZ_MASTER_PRODUCTION_ACCESS_ENABLED=true` にすると、開始時に `get_features.php` で `player_features.json` の `sources` を確認し、招待ID由来の解放があるプレイヤーIDのみ許可する。

## 開発・テスト時の扱い

現在の開発版ではテストをしやすくするため、以下の状態にしている。

- 挑戦回数制限は無効
- 未ログインでもテストプレイ可能
- ランキング保存はログイン済みユーザーのみ

## 注意

`scores/` と `requests/` の本番運用データは変更・削除・初期化しない。

特に以下は本番固有データとして扱う。

- `scores/player_registry.csv`
- `scores/score_log.csv`
- `scores/player_features.json`
- `scores/invite_codes.json`
- `scores/quiz_master_scores.json`
- `requests/`

PWA更新時は、以下のバージョン整合性を確認する。

- `index.html`
- `service-worker.js`
- `version.json`
- `scores/release_versions.json`
