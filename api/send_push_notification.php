<?php
require_once __DIR__ . '/feature_common.php';
// 管理者用テスト通知送信API。api/admin_api.php から呼び出されます。
require_once __DIR__ . '/push_config.php';


function push_subscription_file_path() {
    return __DIR__ . '/../scores/push_subscriptions.json';
}
function read_push_subscription_records() {
    $file = push_subscription_file_path();
    if (!is_file($file)) {
        return ['exists'=>false, 'subscriptions'=>[]];
    }
    $db = json_decode(file_get_contents($file), true);
    $subs = is_array($db['subscriptions'] ?? null) ? $db['subscriptions'] : [];
    return ['exists'=>true, 'subscriptions'=>$subs];
}
function push_subscription_stats() {
    $read = read_push_subscription_records();
    $subs = $read['subscriptions'];
    $active = 0;
    $inactive = 0;
    $invalid = 0;
    $players = [];
    foreach ($subs as $s) {
        if (!is_array($s)) { $invalid++; continue; }
        $isActive = (($s['status'] ?? 'active') === 'active');
        $hasShape = trim((string)($s['endpoint'] ?? '')) !== ''
            && is_array($s['keys'] ?? null)
            && !empty($s['keys']['p256dh'])
            && !empty($s['keys']['auth']);
        if (!$hasShape) { $invalid++; continue; }
        if ($isActive) {
            $active++;
            $pid = trim((string)($s['player_id'] ?? ''));
            if ($pid !== '') $players[$pid] = true;
        } else {
            $inactive++;
        }
    }
    return [
        'file_exists'=>$read['exists'],
        'total'=>count($subs),
        'active'=>$active,
        'inactive'=>$inactive,
        'invalid'=>$invalid,
        'active_players'=>count($players),
        'message'=> $active > 0 ? '有効な購読端末があります。' : ($read['exists'] ? '有効な購読端末がありません。' : '購読端末がありません。')
    ];
}

function send_web_push_notifications($title, $body, $url='./') {
    $url = normalize_notice_url($url);
    $stats = push_subscription_stats();
    $file = push_subscription_file_path();
    if (!is_file($file)) return ['sent'=>0,'failed'=>0,'message'=>'購読端末がありません。','subscription_stats'=>$stats];
    $db = json_decode(file_get_contents($file), true);
    $subs = is_array($db['subscriptions'] ?? null) ? $db['subscriptions'] : [];
    $subs = array_values(array_filter($subs, function($s){ return (($s['status'] ?? 'active') === 'active'); }));
    if (!$subs) return ['sent'=>0,'failed'=>0,'message'=>'有効な購読端末がありません。','subscription_stats'=>$stats];

    $autoloads = [
        __DIR__ . '/../vendor/autoload.php',
        __DIR__ . '/../../vendor/autoload.php',
    ];
    $loaded = false;
    foreach ($autoloads as $a) {
        if (is_file($a)) { require_once $a; $loaded = true; break; }
    }
    if (!$loaded || !class_exists('Minishlink\\WebPush\\WebPush')) {
        return [
            'sent'=>0,
            'failed'=>count($subs),
            'message'=>'Web Push送信用ライブラリが未導入です。サーバーで composer require minishlink/web-push を実行してください。',
            'subscription_stats'=>$stats
        ];
    }

    $auth = [
        'VAPID' => [
            'subject' => PUSH_VAPID_SUBJECT,
            'publicKey' => PUSH_VAPID_PUBLIC_KEY,
            'privateKey' => PUSH_VAPID_PRIVATE_KEY,
        ],
    ];
    $webPush = new Minishlink\WebPush\WebPush($auth);
    $payload = json_encode([
        'title'=>$title ?: '野球やろうぜ！',
        'body'=>$body ?: '新しいお知らせがあります。',
        'url'=>$url ?: './',
        'tag'=>'yakyu-yarouze-admin-test'
    ], JSON_UNESCAPED_UNICODE);

    foreach ($subs as $s) {
        $subscription = Minishlink\WebPush\Subscription::create([
            'endpoint'=>$s['endpoint'],
            'keys'=>$s['keys']
        ]);
        $webPush->queueNotification($subscription, $payload);
    }

    $sent = 0; $failed = 0;
    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) $sent++;
        else $failed++;
    }
    return ['sent'=>$sent,'failed'=>$failed,'message'=>'','subscription_stats'=>push_subscription_stats()];
}
?>
