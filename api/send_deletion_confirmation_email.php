<?php
/**
 * プレイヤー削除確認メール送信
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function send_deletion_confirmation_email($player_id, $request_id, $player_email) {
    if (empty($player_email)) {
        // メール未設定の場合はスキップ
        return true;
    }

    try {
        $mail = new PHPMailer(true);

        // SMTP設定（環境変数から取得）
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'localhost';
        $mail->Port = getenv('SMTP_PORT') ?: 587;

        $smtp_user = getenv('SMTP_USER');
        $smtp_pass = getenv('SMTP_PASS');
        if ($smtp_user && $smtp_pass) {
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_user;
            $mail->Password = $smtp_pass;
        }

        $mail->SMTPSecure = getenv('SMTP_SECURE') ?: PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet = 'UTF-8';

        // メール設定
        $from_email = getenv('FROM_EMAIL') ?: 'noreply@example.com';
        $site_name = '野球やろうぜ！';
        $site_url = getenv('SITE_URL') ?: 'https://example.com/baseball/';

        $mail->setFrom($from_email, $site_name);
        $mail->addAddress($player_email);

        $mail->isHTML(true);
        $mail->Subject = '【' . $site_name . '】アカウント削除申請を受け付けました';

        // メール本文
        $body = <<<EOT
<html>
<body style="font-family: sans-serif; line-height: 1.6;">
<p>{$player_id} 様</p>

<p>いつも「{$site_name}」をご利用いただきありがとうございます。</p>

<p>アカウント削除申請を受け付けました。</p>

<h3>申請内容</h3>
<ul>
  <li>プレイヤーID: {$player_id}</li>
  <li>リクエストID: {$request_id}</li>
  <li>申請日時: " . date('Y年m月d日 H:i') . "</li>
</ul>

<h3>削除対象データ</h3>
<p>以下のデータが削除されます：</p>
<ul>
  <li>プレイヤープロフィール</li>
  <li>ゲーム成績・スコア</li>
  <li>ランキング記録</li>
  <li>間違いプレイチェック履歴</li>
  <li>野球博士チャレンジの成績</li>
</ul>

<h3>処理予定</h3>
<p>管理チームで確認後、<strong>3営業日以内</strong>にアカウントを削除いたします。</p>

<p>削除処理前に削除をキャンセルしたい場合は、お問い合わせフォームよりご連絡ください。</p>

<p>ご質問やご不明な点がある場合は、お気軽にお問い合わせください。</p>

<hr style="margin: 24px 0;">

<p style="color: #666; font-size: 12px;">
このメールは自動送信です。返信はお受けしていません。<br>
ご質問は、{$site_name} のお問い合わせフォームよりお願いいたします。
</p>
</body>
</html>
EOT;

        $mail->Body = $body;

        return $mail->send();
    } catch (Exception $e) {
        error_log('Failed to send deletion email: ' . $e->getMessage());
        return false;
    }
}
