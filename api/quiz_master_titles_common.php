<?php
function quiz_master_titles_file() {
    return __DIR__ . '/../data/quiz_master_titles.json';
}

function quiz_master_default_titles() {
    return [
        ['level'=>1,  'title'=>'ボールボーイ',   'point'=>0],
        ['level'=>2,  'title'=>'バットボーイ',   'point'=>10000],
        ['level'=>3,  'title'=>'ベンチ入り',     'point'=>40000],
        ['level'=>4,  'title'=>'代打の切り札',   'point'=>130000],
        ['level'=>5,  'title'=>'スタメン',       'point'=>290000],
        ['level'=>6,  'title'=>'クリーンアップ', 'point'=>500000],
        ['level'=>7,  'title'=>'四番打者',       'point'=>770000],
        ['level'=>8,  'title'=>'エース',         'point'=>1110000],
        ['level'=>9,  'title'=>'キャプテン',     'point'=>1510000],
        ['level'=>10, 'title'=>'ベストナイン',   'point'=>1970000],
        ['level'=>11, 'title'=>'オールスター',   'point'=>2500000],
        ['level'=>12, 'title'=>'甲子園スター',   'point'=>3080000],
        ['level'=>13, 'title'=>'ドラフト候補',   'point'=>3730000],
        ['level'=>14, 'title'=>'プロ野球選手',   'point'=>4440000],
        ['level'=>15, 'title'=>'メジャーリーガー','point'=>5210000],
        ['level'=>16, 'title'=>'首位打者',       'point'=>6040000],
        ['level'=>17, 'title'=>'ホームラン王',   'point'=>6930000],
        ['level'=>18, 'title'=>'サイヤング賞',   'point'=>7890000],
        ['level'=>19, 'title'=>'MVP',            'point'=>8910000],
        ['level'=>20, 'title'=>'野球殿堂',       'point'=>10000000]
    ];
}

/**
 * タイトル行配列を正規化する。
 * - point 昇順でソート後、level を 1 始まりで振り直す。
 * - level フィールドを保持（修正前は剥落していたバグを修正）。
 */
function quiz_master_normalize_titles($rows) {
    if (!is_array($rows)) $rows = [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $title = trim((string)($row['title'] ?? ''));
        $point = intval($row['point'] ?? -1);
        if ($title === '' || $point < 0) continue;
        $out[] = [
            'level' => intval($row['level'] ?? 0),
            'title' => function_exists('mb_substr') ? mb_substr($title, 0, 40, 'UTF-8') : substr($title, 0, 120),
            'point' => $point
        ];
    }
    usort($out, function($a, $b) {
        if (($a['point'] ?? 0) !== ($b['point'] ?? 0)) return ($a['point'] ?? 0) <=> ($b['point'] ?? 0);
        return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
    // ソート後に level を 1 始まりで採番し直す
    foreach ($out as $i => &$row) {
        $row['level'] = $i + 1;
    }
    unset($row);
    return $out ?: quiz_master_default_titles();
}

function quiz_master_load_titles_payload() {
    $file     = quiz_master_titles_file();
    $fallback = ['version'=>1, 'titles'=>quiz_master_default_titles()];
    if (!is_file($file)) return $fallback;
    $raw  = file_get_contents($file);
    $json = $raw ? json_decode($raw, true) : null;
    if (!is_array($json)) return $fallback;
    $json['titles'] = quiz_master_normalize_titles($json['titles'] ?? []);
    if (!isset($json['version'])) $json['version'] = 1;
    return $json;
}

/**
 * スコアに対応するタイトル行を返す。
 * $titles が渡された場合は既に正規化済みとみなし、二重正規化を避ける。
 */
function quiz_master_title_for_score($score, $titles = null) {
    $score = intval($score);
    if ($titles === null) {
        $titles = quiz_master_load_titles_payload()['titles'] ?? quiz_master_default_titles();
    }
    $current = $titles[0] ?? ['level'=>1,'title'=>'ボールボーイ','point'=>0];
    foreach ($titles as $row) {
        if ($score >= intval($row['point'] ?? 0)) $current = $row;
    }
    return $current;
}
