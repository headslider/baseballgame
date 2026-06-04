<?php
require_once __DIR__ . '/feature_common.php';

function notice_duplicate_guard_file() {
    return feature_scores_dir() . '/notice_duplicate_guard.json';
}

function notice_duplicate_guard_normalize_text($value) {
    $text = trim((string)$value);
    $text = preg_replace('/\r\n|\r/u', "\n", $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);
    $text = preg_replace('/\n{3,}/u', "\n\n", $text);
    return $text;
}

function notice_duplicate_guard_signature($kind, $title, $body, $url, $target='all') {
    $payload = [
        'kind'=>notice_duplicate_guard_normalize_text($kind),
        'title'=>notice_duplicate_guard_normalize_text($title),
        'body'=>notice_duplicate_guard_normalize_text($body),
        'url'=>notice_duplicate_guard_normalize_text($url ?: './'),
        'target'=>notice_duplicate_guard_normalize_text($target ?: 'all'),
    ];
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function notice_duplicate_guard_load_locked(&$fp) {
    $file = notice_duplicate_guard_file();
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $fp = fopen($file, 'c+');
    if (!$fp) return ['records'=>[]];
    if (!flock($fp, LOCK_EX)) { fclose($fp); $fp = null; return ['records'=>[]]; }
    rewind($fp);
    $raw = stream_get_contents($fp);
    $db = $raw ? json_decode($raw, true) : null;
    if (!is_array($db)) $db = ['records'=>[]];
    if (!isset($db['records']) || !is_array($db['records'])) $db['records'] = [];
    return $db;
}

function notice_duplicate_guard_save_unlock($fp, $db) {
    if (!$fp) return false;
    $db['updated_at'] = date('Y-m-d H:i:s');
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function notice_duplicate_guard_reserve($kind, $title, $body, $url='./', $context_id='', $window_seconds=86400) {
    $signature = notice_duplicate_guard_signature($kind, $title, $body, $url, 'all');
    $now = time();
    $fp = null;
    $db = notice_duplicate_guard_load_locked($fp);
    $records = $db['records'];
    $kept = [];
    foreach ($records as $rec) {
        if (!is_array($rec)) continue;
        $ts = strtotime($rec['updated_at'] ?? $rec['created_at'] ?? '') ?: 0;
        if ($ts && ($now - $ts) > max($window_seconds, 86400) * 7) continue;
        $kept[] = $rec;
    }
    $records = $kept;

    foreach ($records as $rec) {
        if (($rec['signature'] ?? '') !== $signature) continue;
        $status = (string)($rec['status'] ?? '');
        $ts = strtotime($rec['updated_at'] ?? $rec['created_at'] ?? '') ?: 0;
        $age = $ts ? ($now - $ts) : 0;
        if ($status === 'reserved' && $age < 600) {
            notice_duplicate_guard_save_unlock($fp, ['records'=>$records]);
            return ['ok'=>false,'duplicate'=>true,'message'=>'同じ内容の通知が現在送信処理中です。重複送信を防ぐため中止しました。','signature'=>$signature,'matched'=>$rec];
        }
        if ($status === 'sent' && $age < $window_seconds) {
            notice_duplicate_guard_save_unlock($fp, ['records'=>$records]);
            return ['ok'=>false,'duplicate'=>true,'message'=>'同じ内容の通知がすでに送信済みです。重複送信を防ぐため中止しました。','signature'=>$signature,'matched'=>$rec];
        }
    }

    $reservation_id = 'ng_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $records[] = [
        'id'=>$reservation_id,
        'signature'=>$signature,
        'kind'=>(string)$kind,
        'title'=>(string)$title,
        'body'=>(string)$body,
        'url'=>(string)($url ?: './'),
        'target'=>'all',
        'context_id'=>(string)$context_id,
        'status'=>'reserved',
        'created_at'=>date('Y-m-d H:i:s'),
        'updated_at'=>date('Y-m-d H:i:s'),
    ];
    notice_duplicate_guard_save_unlock($fp, ['records'=>$records]);
    return ['ok'=>true,'duplicate'=>false,'id'=>$reservation_id,'signature'=>$signature];
}

function notice_duplicate_guard_finish($reservation_id, $status, $result=[]) {
    $reservation_id = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$reservation_id);
    if ($reservation_id === '') return false;
    $fp = null;
    $db = notice_duplicate_guard_load_locked($fp);
    foreach ($db['records'] as &$rec) {
        if (($rec['id'] ?? '') === $reservation_id) {
            $rec['status'] = $status === 'sent' ? 'sent' : 'failed';
            $rec['sent'] = intval($result['sent'] ?? 0);
            $rec['failed'] = intval($result['failed'] ?? 0);
            $rec['message'] = (string)($result['message'] ?? '');
            $rec['updated_at'] = date('Y-m-d H:i:s');
            break;
        }
    }
    unset($rec);
    return notice_duplicate_guard_save_unlock($fp, $db);
}
?>
