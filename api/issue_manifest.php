<?php
header('Content-Type: application/manifest+json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function clean_issue_key($value) {
    $value = (string)$value;
    $value = preg_replace('/[^a-zA-Z0-9_\-]/', '', $value);
    return substr($value, 0, 128);
}

$issue = strtolower((string)($_GET['issue'] ?? $_GET['id_issue'] ?? ''));
$invite_key = clean_issue_key($_GET['invite_key'] ?? $_GET['inviteIssueKey'] ?? $_GET['issue_invite'] ?? '');
$admin_key = clean_issue_key($_GET['admin_key'] ?? $_GET['adminIssueKey'] ?? $_GET['issue_admin'] ?? '');

function issue_manifest_base_path() {
    $script = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '.' || $dir === '') return '/';
    $base = preg_replace('#/api$#', '', $dir);
    $base = '/' . trim((string)$base, '/') . '/';
    return preg_replace('#/+#', '/', $base);
}

$base_path = issue_manifest_base_path();
$start_url = $base_path;
$id = $base_path;
if ($issue === 'admin' && $admin_key !== '') {
    $start_url = $base_path . '?openExternalBrowser=1&issue=admin&admin_key=' . rawurlencode($admin_key);
    $id = $start_url;
} elseif ($issue === 'invite' && $invite_key !== '') {
    $start_url = $base_path . '?openExternalBrowser=1&issue=invite&invite_key=' . rawurlencode($invite_key);
    $id = $start_url;
}

$manifest = [
    'name' => '少年野球シミュレーター「野球やろうぜ！」',
    'short_name' => '野球やろうぜ',
    'description' => '少年野球の攻撃・守備判断を楽しく学べるシミュレーター',
    'lang' => 'ja',
    'start_url' => $start_url,
    'scope' => $base_path,
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#0057d8',
    'theme_color' => '#0046b8',
    'categories' => ['education', 'games', 'sports'],
    'icons' => [
        ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/maskable-icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']
    ],
    'id' => $id
];

echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
