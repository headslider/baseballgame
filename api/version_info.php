<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/feature_common.php';

function version_info_read_json($file, $fallback) {
    if (!is_file($file)) return $fallback;
    $raw = file_get_contents($file);
    $json = json_decode($raw ?: '', true);
    return is_array($json) ? $json : $fallback;
}
function version_info_release_parts($v) {
    $v = trim((string)$v);
    if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $v, $m)) {
        return [intval($m[1]), intval($m[2]), intval($m[3])];
    }
    return [0, 0, 0];
}
function version_info_compare_desc($a, $b) {
    $av = version_info_release_parts($a['public_version'] ?? '');
    $bv = version_info_release_parts($b['public_version'] ?? '');
    for ($i = 0; $i < 3; $i++) {
        if ($av[$i] !== $bv[$i]) return $bv[$i] <=> $av[$i];
    }
    $ad = (string)($a['released_at'] ?? '');
    $bd = (string)($b['released_at'] ?? '');
    if ($ad !== $bd) return strcmp($bd, $ad);
    return strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
}
function version_info_public_row($row) {
    return [
        'id'=>(string)($row['id'] ?? ''),
        'public_version'=>(string)($row['public_version'] ?? ''),
        'release_type'=>(string)($row['release_type'] ?? ''),
        'released_at'=>(string)($row['released_at'] ?? ''),
        'title'=>(string)($row['title'] ?? ''),
        'public_summary'=>(string)($row['public_summary'] ?? ''),
        'is_current'=>!empty($row['is_current'])
    ];
}
$version = version_info_read_json(__DIR__ . '/../version.json', []);
$db = version_info_read_json(feature_scores_dir() . '/release_versions.json', ['current_public_version'=>'v1.0.0','versions'=>[]]);
$rows = [];
foreach (($db['versions'] ?? []) as $row) {
    if (!is_array($row) || empty($row['visible'])) continue;
    $rows[] = version_info_public_row($row);
}
usort($rows, 'version_info_compare_desc');
$current = null;
foreach ($rows as $row) {
    if (!empty($row['is_current'])) { $current = $row; break; }
}
if (!$current && count($rows)) $current = $rows[0];
if (!$current) {
    $current = [
        'public_version'=>(string)($version['public_version'] ?? 'v1.0.0'),
        'release_type'=>'major',
        'released_at'=>(string)($version['updated_at'] ?? ''),
        'title'=>'正式公開版',
        'public_summary'=>'少年野球シミュレーター「野球やろうぜ！」を正式公開しました。',
        'is_current'=>true
    ];
}
echo json_encode([
    'ok'=>true,
    'current'=>$current,
    'history'=>array_slice($rows, 0, 20),
    'internal'=>[
        'app_version'=>(string)($version['app_version'] ?? ''),
        'cache_version'=>(string)($version['cache_version'] ?? ''),
        'updated_at'=>(string)($version['updated_at'] ?? '')
    ]
], JSON_UNESCAPED_UNICODE | (defined('JSON_INVALID_UTF8_SUBSTITUTE') ? JSON_INVALID_UTF8_SUBSTITUTE : 0));
