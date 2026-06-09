# 「野球やろうぜ！」問題修正ルール + 完全データ照合ルール

## 0. 最重要ルール

問題修正は **IDだけで適用してはいけない**。  
必ず `id + situation + ball_tag + theme + positions + choices + visual` を照合してから修正する。

今回のD281誤適用の原因は、IDだけを見て、実データの `situation` / `ball_tag` を確認せずに別テーマの選択肢を当てたこと。今後はこの方法を禁止する。

---

## 1. 推奨エクスポート形式

今後の問題修正では、CSVより **管理画面JSONエクスポート** を優先する。

### JSONを優先する理由

- `raw_json` が文字列化されず、構造を保てる
- `choices_by_position` の守備位置別選択肢を正確に比較できる
- `positions` / `role` / `position` のズレを検出しやすい
- `situation` / `ball_tag` / `theme` を一括照合できる
- 各問題に `semantic_signature` と `raw_json_sha256` を持たせられる
- CSVの引用符・改行・JSON文字列化による誤読を防げる

### 今後ChatGPTへ渡す優先順位

```text
1. questions_admin_master_*.json  ← 最優先
2. data/questions.json             ← 出題用の確認
3. data/game_config.json           ← 問題数確認
4. questions_all_*.csv             ← 表計算・一覧確認用
```

---

## 2. 完全照合に必要なキー

各問題は以下のキーで照合する。

```text
id
status
type
grade
min_grade / max_grade
theme
stage
outs
outs_scope
position / positions / role
ball_tag
situation
prompt
visual.runners
visual.batter_runner
visual.ball_path
visual.ball_holder
visual.target_position
choices / choices_by_position
```

---

## 3. semantic_signature ルール

各問題に、以下をまとめた `semantic_signature` を作る。

```json
{
  "id": "D281",
  "type": "defense",
  "theme": "pitcher_pitch_selection_by_batting_order",
  "grade": 5,
  "stage": "2B",
  "outs": 1,
  "ball_tag": "3番打者への入り方",
  "situation": "ランナー二塁。相手は3番打者。長打が出ると失点しやすい場面。",
  "positions": ["P", "C"],
  "visual_runners": ["2B"],
  "visual_ball_path": "unknown_to_pitcher",
  "choices_sha256": "..."
}
```

修正適用前に、想定している `semantic_signature` と実データの `semantic_signature` を比較する。  
一致しない場合は **自動修正禁止** とし、確認対象にする。

---

## 4. 修正適用前チェック

任意のIDに修正を当てる前に、必ず以下を確認する。

```text
1. id が存在する
2. situation が想定テーマと一致する
3. ball_tag が想定テーマと一致する
4. theme が想定テーマと一致する
5. positions / role / position が想定守備位置と一致する
6. visual.runners がランナー状況と一致する
7. visual.ball_path が打球・送球・投球の種類と一致する
8. 現在の選択肢が想定している旧選択肢または同テーマの選択肢である
9. choices_by_position のキーが positions と一致する
10. CSV表示カラムと raw_json が一致する
```

このうち1つでも不一致なら、自動修正せず確認する。

---

## 5. CSVとraw_jsonの同期ルール

CSVを使う場合は、以下が一致していることを必ず検証する。

```text
CSV id               == raw_json.id
CSV type             == raw_json.type
CSV grade            == raw_json.grade
CSV theme            == raw_json.theme
CSV stage            == raw_json.stage
CSV outs             == raw_json.outs
CSV ball_tag         == raw_json.ball_tag
CSV situation        == raw_json.situation
CSV prompt           == raw_json.prompt
CSV position         == raw_json.positions / raw_json.position / raw_json.role
CSV choice_1〜3      == active position の choices
CSV score_1〜3       == active position の score
```

CSV側だけ、またはraw_json側だけを修正してはいけない。必ず両方を同期する。

---

## 6. positions と choices_by_position の整合ルール

守備問題で `choices_by_position` を持つ場合、以下を満たす必要がある。

```text
raw_json.positions の全ポジションに choices_by_position が存在する
choices_by_position に raw_json.positions 以外のキーを残さない
CSV position と raw_json.positions を同期する
```

例：C専用問題なら以下が正しい。

```json
"positions": ["C"],
"choices_by_position": {
  "C": [...]
}
```

Pや1Bなどの不要な非アクティブ選択肢を残さない。

---

## 7. 管理画面JSONエクスポート仕様

管理画面からは、CSVに加えて以下のJSONを吐き出せるようにする。

```text
questions_admin_master_YYYYMMDD_HHMMSS.json
```

JSON内には以下を含める。

```text
schema_version
exported_at
counts.master_total
counts.active_total
counts.stopped_total
counts.stopped_ids
questions[].id
questions[].status
questions[].type
questions[].grade
questions[].position
questions[].theme
questions[].ball_tag
questions[].situation
questions[].prompt
questions[].raw_json
questions[].semantic_signature
questions[].semantic_signature_sha256
questions[].raw_json_sha256
audit.alignment_issues
audit.master_sha256
```

このJSONをChatGPTへ渡せば、IDだけでなく意味照合まで行える。

---

## 8. 表記統一ルール

ユーザー表示文言では、原則として「走者」を「ランナー」に統一する。

対象：

```text
問題文
選択肢
raw_json.text
raw_json.choices_by_position
ball_tag
runner_tag
その他タグ類
```

変更例：

```text
一塁走者 → 一塁ランナー
二塁走者 → 二塁ランナー
三塁走者 → 三塁ランナー
走者を確認する → ランナーを確認する
```

ただし、以下は変更しない。

```text
打者走者
```

---

## 9. 冒頭・塁状況表記ルール

```text
一塁。 → ランナー一塁。
二塁。 → ランナー二塁。
三塁。 → ランナー三塁。
満塁。 → ランナー満塁。
一塁二塁。 → ランナー一塁・二塁。
一塁三塁。 → ランナー一塁・三塁。
二塁三塁。 → ランナー二塁・三塁。
```

タグ類も同じ表記に統一する。

---

## 10. 変更してはいけない塁名表現

以下は塁の名称やプレイ名なので、「ランナー」を付けない。

```text
一塁送球
二塁送球
三塁送球
一塁牽制
二塁牽制
三塁牽制
一塁線
三塁線
一塁側
三塁側
一塁カバー
二塁カバー
三塁カバー
一塁後方
二塁後方
三塁後方
```

---

## 11. 選択肢文章ルール

選択肢は、説明文ではなく短い行動表現にする。

```text
捕球後どこに送球するか考える → 送球先を確認する
ボールの方向へ寄っていく → ボール方向へ寄る
その場で次のプレイを待つ → その場で待つ
走者を確認してから打球を見る → ランナーと打球を確認する
```

0点選択肢では以下を避ける。

```text
確認せず
見ずに
考えずに
〜しないで
〜できない
〜遅れる
〜無理に
〜だから〜しない
```

---

## 12. 外野返球ルール

外野から内野方向へのボールは「送球」ではなく「返球」を使う。

```text
3点：捕球後すぐステップし、中継へ返球する
1点：捕球後にゆっくり中継へ返球する
0点：本塁へ返球する
```

本塁返球が0点なら、問題文に「本塁への返球は間に合わなそうだ」を入れる。

---

## 13. キャッチャー本塁待機表現

```text
3点：ホームベース前で送球に備える
1点：本塁後ろで待つ
0点：本塁上で待つ
```

---

## 14. 外野フライのグローブ位置

```text
3点：顔(おでこ)の横でとる
1点：頭の上で捕る
0点：身体の前で捕る
```

---

## 15. 出題停止ルール

今回の出題停止対象：

```text
D280
D291
D292
D307
D352
D353
```

新たな出題停止は勝手に追加しない。必ず確認する。

---

## 16. 最終検証ルール

修正後は必ず以下を検証する。

```text
CSV行数
JSON master件数
questions.json 出力数
攻撃・守備・基本の問題数
出題停止数
問題ID重複なし
JSON構文OK
raw_json整合OK
semantic_signature生成OK
CSV表示カラムと raw_json 同期OK
positions と choices_by_position 同期OK
問題文・選択肢内の未変換「走者」なし
ただし「打者走者」は保持
冒頭の一塁・二塁・三塁・満塁表記OK
ball_tag / runner_tag の塁状態表記OK
A530 / D972 / D973 など追加問題の存在確認OK
game_config.json の問題数整合OK
```

---

## 17. ルール外修正の確認基準

以下は自動修正禁止。必ず確認する。

```text
正解が変わる
1点と3点の評価が入れ替わる
0点の内容が野球判断として変わる
守備位置を削除する
守備位置を追加する
問題を出題停止にする
問題文の意味を変える
重複問題として統合・停止する
学年設定を変える
アウトカウントを変える
ランナー状況を変える
タグの意味が変わる
semantic_signature が一致しない
```

表記統一・文言短縮・確定済み言い換えのみ、自動修正してよい。
