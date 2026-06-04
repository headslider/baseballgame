<?php
require_once __DIR__ . '/feature_common.php';
// 管理者用テスト通知送信API。api/admin_api.php から呼び出されます。
require_once __DIR__ . '/push_config.php';

function send_web_push_notifications($title, $body, $url='./') {
    $url = normalize_notice_url($url);
    $file = __DIR__ . '/../scores/push_subscriptions.json';
    if (!is_file($file)) return ['sent'=>0,'failed'=>0,'message'=>'購読端末がありません。'];
    $db = json_decode(file_get_contents($file), true);
    $subs = is_array($db['subscriptions'] ?? null) ? $db['subscriptions'] : [];
    $subs = array_values(array_filter($subs, function($s){ return (($s['status'] ?? 'active') === 'active'); }));
    if (!$subs) return ['sent'=>0,'failed'=>0,'message'=>'有効な購読端末がありません。'];

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
            'message'=>'Web Push送信用ライブラリが未導入です。サーバーで composer require minishlink/web-push を実行してください。'
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
    return ['sent'=>$sent,'failed'=>$failed,'message'=>''];
}
?>
