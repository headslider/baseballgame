<?php
function quiz_master_titles_file() {
    return __DIR__ . '/../data/quiz_master_titles.json';
}

function quiz_master_default_titles() {
    return [
        ['title'=>'ボールボーイ','point'=>0],
        ['title'=>'バットボーイ','point'=>50000],
        ['title'=>'ベンチ入り','point'=>120000],
        ['title'=>'代打の切り札','point'=>220000],
        ['title'=>'スタメン','point'=>350000],
        ['title'=>'クリーンアップ','point'=>550000],
        ['title'=>'四番打者','point'=>800000],
        ['title'=>'エース','point'=>1100000],
        ['title'=>'キャプテン','point'=>1450000],
        ['title'=>'ベストナイン','point'=>1850000],
        ['title'=>'オールスター','point'=>2350000],
        ['title'=>'甲子園スター','point'=>3000000],
        ['title'=>'ドラフト候補','point'=>3700000],
        ['title'=>'プロ野球選手','point'=>4500000],
        ['title'=>'メジャーリーガー','point'=>5400000],
        ['title'=>'首位打者','point'=>6300000],
        ['title'=>'ホームラン王','point'=>7100000],
        ['title'=>'サイヤング賞','point'=>7900000],
        ['title'=>'MVP','point'=>8800000],
        ['title'=>'野球殿堂','point'=>10000000]
    ];
}

function quiz_master_normalize_titles($rows) {
    if (!is_array($rows)) $rows = [];
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $title = trim((string)($row['title'] ?? ''));
        $point = intval($row['point'] ?? -1);
        if ($title === '' || $point < 0) continue;
        $out[] = ['title'=>(function_exists('mb_substr') ? mb_substr($title, 0, 40, 'UTF-8') : substr($title, 0, 120)), 'point'=>$point];
    }
    usort($out, function($a, $b) {
        if (($a['point'] ?? 0) !== ($b['point'] ?? 0)) return ($a['point'] ?? 0) <=> ($b['point'] ?? 0);
        return strcmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
    });
    return $out ?: quiz_master_default_titles();
}

function quiz_master_load_titles_payload() {
    $file = quiz_master_titles_file();
    $fallback = ['version'=>1, 'titles'=>quiz_master_default_titles()];
    if (!is_file($file)) return $fallback;
    $raw = file_get_contents($file);
    $json = $raw ? json_decode($raw, true) : null;
    if (!is_array($json)) return $fallback;
    $json['titles'] = quiz_master_normalize_titles($json['titles'] ?? []);
    if (!isset($json['version'])) $json['version'] = 1;
    return $json;
}

function quiz_master_title_for_score($score, $titles = null) {
    $score = intval($score);
    $titles = quiz_master_normalize_titles($titles ?? (quiz_master_load_titles_payload()['titles'] ?? []));
    $current = $titles[0] ?? ['title'=>'ボールボーイ','point'=>0];
    foreach ($titles as $row) {
        if ($score >= intval($row['point'] ?? 0)) $current = $row;
    }
    return $current;
}
?>
