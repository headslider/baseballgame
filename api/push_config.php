<?php
require_once __DIR__ . '/feature_common.php';
// v665 Web Push configuration.
// 本番運用前に必要に応じてVAPIDキーを再生成してください。
// Composerで minishlink/web-push を利用する場合：composer require minishlink/web-push
const PUSH_VAPID_PUBLIC_KEY = 'BAVbJjf2eH19104MM1MWROnQF-j9vou7w2HMHSwZT1u1yQjtKzGOzii9i2jmvWUUnmLDWTeCMtM7eZRJxSsoUzg';
const PUSH_VAPID_PRIVATE_KEY = 'EiClzb0UHHoXqJHf3RmMJBaw83MLLZ79RxNjuIlhjqI';
const PUSH_VAPID_SUBJECT = 'mailto:tamaki.toriyabe@r-flash.com';
?>
