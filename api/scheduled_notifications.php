<?php
require_once __DIR__ . '/feature_common.php';
require_once __DIR__ . '/send_push_notification.php';
require_once __DIR__ . '/question_status_common.php';
require_once __DIR__ . '/notice_duplicate_guard.php';


function scheduled_notifications_update_game_config_counts() {
    $qfile = __DIR__ . '/../data/questions.json';
    $cfile = __DIR__ . '/../data/game_config.json';
    $questions = json_decode(is_file($qfile) ? file_get_contents($qfile) : '[]', true);
    $config = json_decode(is_file($cfile) ? file_get_contents($cfile) : '{}', true);
    if (!is_array($questions) || !is_array($config)) return false;
    $total=0; $a=0; $d=0; $b=0;
    foreach ($questions as $q) {
        $id = (string)($q['id'] ?? '');
        $st = question_status_get($id);
        if ($st === 'draft' || $st === 'disabled') continue;
        $total++;
        $t = $q['type'] ?? '';
        if ($t === 'attack') $a++;
        elseif ($t === 'basic') $b++;
        elseif ($t === 'defense') $d++;
    }
    $counts = ['questionPoolTotal'=>$total,'attackQuestionPool'=>$a,'defenseQuestionPool'=>$d,'basicQuestionPool'=>$b];
    foreach ($counts as $k=>$v) {
        $config[$k] = $v;
        if (!isset($config['game']) || !is_array($config['game'])) $config['game'] = [];
        $config['game'][$k] = $v;
    }
    return write_json_file_locked($cfile, $config);
}


function scheduled_release_versions_file() {
    return feature_scores_dir() . '/release_versions.json';
}

function scheduled_release_versions_read() {
    $fallback = ['current_public_version'=>'v1.0.0','versions'=>[]];
    $file = scheduled_release_versions_file();
    if (!is_file($file)) return $fallback;
    $db = json_decode(file_get_contents($file), true);
    if (!is_array($db)) $db = $fallback;
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    if (!isset($db['current_public_version']) || !preg_match('/^v\d+\.\d+\.\d+$/', (string)$db['current_public_version'])) $db['current_public_version'] = 'v1.0.0';
    return $db;
}

function scheduled_release_versions_write($db) {
    if (!isset($db['versions']) || !is_array($db['versions'])) $db['versions'] = [];
    if (!isset($db['current_public_version']) || !preg_match('/^v\d+\.\d+\.\d+$/', (string)$db['current_public_version'])) $db['current_public_version'] = 'v1.0.0';
    $dir = dirname(scheduled_release_versions_file());
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return write_json_file_locked(scheduled_release_versions_file(), $db);
}

function scheduled_release_version_json() {
    $file = __DIR__ . '/../version.json';
    $raw = is_file($file) ? file_get_contents($file) : '{}';
    $json = json_decode($raw ?: '{}', true);
    return is_array($json) ? $json : [];
}

function scheduled_release_version_parts($v) {
    $v = trim((string)$v);
    if (preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $v, $m)) {
        return [intval($m[1]), intval($m[2]), intval($m[3])];
    }
    return [0, 0, 0];
}
function scheduled_release_compare_versions_desc($a, $b) {
    $av = scheduled_release_version_parts($a['public_version'] ?? '');
    $bv = scheduled_release_version_parts($b['public_version'] ?? '');
    for ($i = 0; $i < 3; $i++) {
        if ($av[$i] !== $bv[$i]) return $bv[$i] <=> $av[$i];
    }
    $ad = (string)($a['released_at'] ?? '');
    $bd = (string)($b['released_at'] ?? '');
    if ($ad !== $bd) return strcmp($bd, $ad);
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
}
function scheduled_release_sort_versions_desc($rows) {
    if (!is_array($rows)) return [];
    usort($rows, 'scheduled_release_compare_versions_desc');
    return $rows;
}

function scheduled_release_next_patch_version($current) {
    $current = trim((string)$current);
    if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)$/', $current, $m)) return 'v1.0.1';
    return 'v' . intval($m[1]) . '.' . intval($m[2]) . '.' . (intval($m[3]) + 1);
}

function scheduled_release_question_short_lines($question_ids, $max=8) {
    $lines = [];
    foreach (array_slice(array_values($question_ids), 0, $max) as $qid) {
        $lines[] = scheduled_notifications_notice_line_from_history($qid);
    }
    if (count($question_ids) > $max) $lines[] = 'ほか' . (count($question_ids) - $max) . '問';
    return $lines;
}

function scheduled_release_record_patch_for_new_questions($schedule_id, $question_ids, $admin_label='CRON') {
    $schedule_id = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$schedule_id);
    $question_ids = array_values(array_unique(array_filter(array_map('strval', $question_ids), function($v){ return trim($v) !== ''; })));
    if ($schedule_id === '' || empty($question_ids)) return ['ok'=>false, 'message'=>'パッチ履歴の対象がありません。'];

    $db = scheduled_release_versions_read();
    foreach ($db['versions'] as $row) {
        if (($row['source'] ?? '') === 'new_question_notice' && ($row['source_schedule_id'] ?? '') === $schedule_id) {
            return ['ok'=>true, 'skipped'=>true, 'public_version'=>$row['public_version'] ?? ($db['current_public_version'] ?? 'v1.0.0'), 'message'=>'この新規問題通知のパッチ履歴は登録済みです。'];
        }
    }

    $current = $db['current_public_version'] ?? 'v1.0.0';
    foreach ($db['versions'] as $row) {
        if (!empty($row['is_current']) && preg_match('/^v\d+\.\d+\.\d+$/', (string)($row['public_version'] ?? ''))) {
            $current = $row['public_version'];
            break;
        }
    }
    $next = scheduled_release_next_patch_version($current);
    $now = date('Y-m-d H:i:s');
    $version = scheduled_release_version_json();
    $lines = scheduled_release_question_short_lines($question_ids);
    $summary = '新しい問題を' . count($question_ids) . '問追加しました。';
    if (!empty($lines)) $summary .= "\n" . implode("\n", $lines);

    foreach ($db['versions'] as &$row) {
        $row['is_current'] = false;
    }
    unset($row);

    $db['versions'][] = [
        'id'=>'rel_' . date('Ymd_His') . '_patch_' . bin2hex(random_bytes(2)),
        'public_version'=>$next,
        'internal_version'=>$version['app_version'] ?? '',
        'cache_version'=>$version['cache_version'] ?? '',
        'release_type'=>'patch',
        'released_at'=>date('Y-m-d'),
        'title'=>'新しい問題を追加しました',
        'public_summary'=>$summary,
        'admin_note'=>'新規問題追加通知の配信と同時に自動追加。schedule_id=' . $schedule_id . ' / question_ids=' . implode(',', $question_ids),
        'visible'=>true,
        'is_current'=>true,
        'source'=>'new_question_notice',
        'source_schedule_id'=>$schedule_id,
        'question_ids'=>$question_ids,
        'created_at'=>$now,
        'updated_at'=>$now,
        'updated_by'=>$admin_label ?: 'CRON'
    ];
    $db['current_public_version'] = $next;
    $db['versions'] = scheduled_release_sort_versions_desc($db['versions']);
    if (!scheduled_release_versions_write($db)) return ['ok'=>false, 'message'=>'パッチ履歴を保存できませんでした。'];
    return ['ok'=>true, 'public_version'=>$next, 'message'=>'新規問題追加通知に合わせてパッチ履歴を追加しました。'];
}

function scheduled_notifications_file() {
    return feature_scores_dir() . '/scheduled_notifications.json';
}

function scheduled_notifications_default_db() {
    return ['items'=>[], 'updated_at'=>date('Y-m-d H:i:s')];
}

function scheduled_notifications_read_db() {
    $file = scheduled_notifications_file();
    if (!is_file($file)) return scheduled_notifications_default_db();
    $db = json_decode(file_get_contents($file), true);
    if (!is_array($db)) return scheduled_notifications_default_db();
    if (!isset($db['items']) || !is_array($db['items'])) $db['items'] = [];
    return $db;
}

function scheduled_notifications_write_db($db) {
    if (!isset($db['items']) || !is_array($db['items'])) $db['items'] = [];
    $db['updated_at'] = date('Y-m-d H:i:s');
    $dir = dirname(scheduled_notifications_file());
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return write_json_file_locked(scheduled_notifications_file(), $db);
}

function scheduled_notifications_generate_id() {
    return 'sch_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
}

function scheduled_notifications_signature_for_item($kind, $title, $body, $url, $scheduled_at) {
    return notice_duplicate_guard_signature('scheduled_' . $kind . '_' . $scheduled_at, $title, $body, $url ?: './', 'all');
}

function scheduled_notifications_find_pending_duplicate($db, $kind, $title, $body, $url, $scheduled_at, $exclude_id='') {
    $sig = scheduled_notifications_signature_for_item($kind, $title, $body, $url, $scheduled_at);
    foreach (($db['items'] ?? []) as $item) {
        if (!is_array($item)) continue;
        if (($item['id'] ?? '') === $exclude_id && $exclude_id !== '') continue;
        $norm = scheduled_notifications_normalize_item($item);
        if (($norm['status'] ?? '') !== 'pending') continue;
        $other = scheduled_notifications_signature_for_item($norm['kind'] ?? 'admin_notice', $norm['title'] ?? '', $norm['body'] ?? '', $norm['url'] ?? './', $norm['scheduled_at'] ?? '');
        if ($other === $sig) return $norm;
    }
    return null;
}

function scheduled_notifications_status_label($status) {
    if ($status === 'sent') return '配信済み';
    if ($status === 'failed') return '配信失敗';
    if ($status === 'cancelled') return '取消';
    return '未配信';
}

function scheduled_notifications_normalize_item($item) {
    if (!is_array($item)) $item = [];
    $id = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($item['id'] ?? ''));
    $title = trim((string)($item['title'] ?? ''));
    $body = trim((string)($item['body'] ?? ''));
    $url = normalize_notice_url($item['url'] ?? './');
    $date = trim((string)($item['delivery_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $hour = intval($item['delivery_hour'] ?? 0);
    if ($hour < 0) $hour = 0;
    if ($hour > 23) $hour = 23;
    $status = (string)($item['status'] ?? 'pending');
    if (!in_array($status, ['pending','sent','failed','cancelled'], true)) $status = 'pending';
    $kind = (string)($item['kind'] ?? 'admin_notice');
    if (!in_array($kind, ['admin_notice','new_question_notice'], true)) $kind = 'admin_notice';
    $question_ids = isset($item['question_ids']) && is_array($item['question_ids']) ? array_values(array_filter(array_map('strval', $item['question_ids']))) : [];
    $publish_on_send = !empty($item['publish_on_send']);
    $scheduled_at = sprintf('%s %02d:00:00', $date, $hour);
    return [
        'id'=>$id,
        'title'=>$title,
        'body'=>$body,
        'url'=>$url,
        'target'=>'all',
        'kind'=>$kind,
        'kind_label'=>($kind === 'new_question_notice' ? '新規問題追加' : '管理者からのお知らせ'),
        'question_ids'=>$question_ids,
        'publish_on_send'=>$publish_on_send,
        'delivery_date'=>$date,
        'delivery_hour'=>$hour,
        'scheduled_at'=>$scheduled_at,
        'status'=>$status,
        'status_label'=>scheduled_notifications_status_label($status),
        'created_at'=>(string)($item['created_at'] ?? ''),
        'updated_at'=>(string)($item['updated_at'] ?? ''),
        'sent_at'=>(string)($item['sent_at'] ?? ''),
        'sent'=>intval($item['sent'] ?? 0),
        'failed'=>intval($item['failed'] ?? 0),
        'message'=>(string)($item['message'] ?? '')
    ];
}

function scheduled_notifications_list() {
    $db = scheduled_notifications_read_db();
    $items = [];
    foreach ($db['items'] as $item) {
        $norm = scheduled_notifications_normalize_item($item);
        if ($norm['id'] !== '') $items[] = $norm;
    }
    usort($items, function($a, $b){
        $ta = strtotime($a['scheduled_at'] ?? '') ?: 0;
        $tb = strtotime($b['scheduled_at'] ?? '') ?: 0;
        if ($ta !== $tb) return $tb <=> $ta;
        return strcmp($b['id'] ?? '', $a['id'] ?? '');
    });
    return $items;
}

function scheduled_notifications_upsert($input, $admin_label='') {
    $db = scheduled_notifications_read_db();
    $id = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)($input['id'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    $body = trim((string)($input['body'] ?? ''));
    $url = normalize_notice_url($input['url'] ?? './');
    $date = trim((string)($input['delivery_date'] ?? ''));
    $hour = intval($input['delivery_hour'] ?? 0);
    $kind = (string)($input['kind'] ?? 'admin_notice');
    if (!in_array($kind, ['admin_notice','new_question_notice'], true)) $kind = 'admin_notice';
    $question_ids = isset($input['question_ids']) && is_array($input['question_ids']) ? array_values(array_unique(array_filter(array_map('strval', $input['question_ids'])))) : [];
    $publish_on_send = !empty($input['publish_on_send']);
    if ($kind === 'new_question_notice' && empty($question_ids)) return ['ok'=>false,'message'=>'新規問題通知では通知対象の問題を選択してください。'];
    if ($title === '') return ['ok'=>false,'message'=>'通知タイトルを入力してください。'];
    if ($body === '') return ['ok'=>false,'message'=>'通知本文を入力してください。'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return ['ok'=>false,'message'=>'配信日を入力してください。'];
    if ($hour < 0 || $hour > 23) return ['ok'=>false,'message'=>'配信時刻は0〜23時で指定してください。'];
    $scheduled_at_candidate = sprintf('%s %02d:00:00', $date, $hour);
    $duplicatePending = scheduled_notifications_find_pending_duplicate($db, $kind, $title, $body, $url, $scheduled_at_candidate, $id);
    if ($duplicatePending) {
        return ['ok'=>false,'message'=>'同じ内容・同じ配信日時の未配信スケジュールがすでに登録されています。重複送信を防ぐため登録できません。'];
    }

    $found = false;
    $now = date('Y-m-d H:i:s');
    foreach ($db['items'] as &$item) {
        if (($item['id'] ?? '') === $id && $id !== '') {
            $status = (string)($item['status'] ?? 'pending');
            // 編集後は未配信に戻す。ただし配信済みデータは誤再送を避けるため、内容更新のみで配信済みを維持する。
            $newStatus = ($status === 'sent') ? 'sent' : 'pending';
            $item = array_merge($item, [
                'title'=>$title,
                'body'=>$body,
                'url'=>$url,
                'target'=>'all',
                'kind'=>$kind,
                'question_ids'=>$question_ids,
                'publish_on_send'=>$publish_on_send,
                'delivery_date'=>$date,
                'delivery_hour'=>$hour,
                'scheduled_at'=>sprintf('%s %02d:00:00', $date, $hour),
                'status'=>$newStatus,
                'updated_at'=>$now,
                'updated_by'=>$admin_label,
                'message'=>($newStatus === 'pending' ? '' : ($item['message'] ?? ''))
            ]);
            $found = true;
            break;
        }
    }
    unset($item);
    if (!$found) {
        $id = scheduled_notifications_generate_id();
        $db['items'][] = [
            'id'=>$id,
            'title'=>$title,
            'body'=>$body,
            'url'=>$url,
            'target'=>'all',
            'kind'=>$kind,
            'question_ids'=>$question_ids,
            'publish_on_send'=>$publish_on_send,
            'delivery_date'=>$date,
            'delivery_hour'=>$hour,
            'scheduled_at'=>sprintf('%s %02d:00:00', $date, $hour),
            'status'=>'pending',
            'created_at'=>$now,
            'updated_at'=>$now,
            'created_by'=>$admin_label,
            'updated_by'=>$admin_label,
            'sent_at'=>'',
            'sent'=>0,
            'failed'=>0,
            'message'=>''
        ];
    }
    scheduled_notifications_write_db($db);
    if ($kind === 'new_question_notice') mark_new_questions_scheduled($question_ids, $id, sprintf('%s %02d:00:00', $date, $hour));
    return ['ok'=>true,'id'=>$id,'message'=>($found ? 'スケジュール通知を更新しました。' : 'スケジュール通知を登録しました。')];
}

function scheduled_notifications_delete($id) {
    $id = preg_replace('/[^0-9A-Za-z_\-]/', '', (string)$id);
    if ($id === '') return false;
    $db = scheduled_notifications_read_db();
    $before = count($db['items']);
    $db['items'] = array_values(array_filter($db['items'], function($item) use ($id){ return (($item['id'] ?? '') !== $id); }));
    if (count($db['items']) === $before) return false;
    scheduled_notifications_write_db($db);
    return true;
}


function scheduled_notifications_notice_line_from_history($id) {
    $items = new_question_notice_candidates();
    foreach ($items as $row) {
        if (($row['id'] ?? '') === $id) {
            $type = $row['type_label'] ?? question_type_label_for_notice($row['type'] ?? '');
            $grade = $row['grade_label'] ?? question_grade_label_for_notice($row['grade'] ?? '');
            $tag = $row['tag'] ?? ($row['ball_tag'] ?? '-');
            return '種別：' . $type . ' / 学年：' . $grade . ' / タグ：' . ($tag !== '' ? $tag : '-');
        }
    }
    return '問題ID：' . $id;
}

function scheduled_notifications_rebuild_new_question_message($ids) {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function($v){ return trim($v) !== ''; })));
    if (count($ids) === 1) {
        return [
            'title'=>'新しい問題が追加されました',
            'body'=>'新しい問題が追加されました。' . "
" . scheduled_notifications_notice_line_from_history($ids[0])
        ];
    }
    $lines = [];
    foreach (array_slice($ids, 0, 10) as $i=>$id) {
        $lines[] = ($i + 1) . '. ' . scheduled_notifications_notice_line_from_history($id);
    }
    $more = count($ids) > 10 ? "
" . 'ほか' . (count($ids) - 10) . '問' : '';
    return [
        'title'=>'新しい問題が追加されました',
        'body'=>'新しい問題が' . count($ids) . '問追加されました。' . "
" . implode("
", $lines) . $more
    ];
}

function scheduled_notifications_unschedule_questions($ids) {
    $ids = array_values(array_unique(array_filter(array_map('strval', $ids), function($v){ return trim($v) !== ''; })));
    if (empty($ids)) return ['ok'=>false, 'message'=>'スケジュール解除する問題を選択してください。'];
    $set = array_fill_keys($ids, true);
    $db = scheduled_notifications_read_db();
    $removed = 0;
    $touchedSchedules = [];
    $now = date('Y-m-d H:i:s');
    foreach ($db['items'] as &$item) {
        $norm = scheduled_notifications_normalize_item($item);
        if (($norm['kind'] ?? '') !== 'new_question_notice') continue;
        if (($norm['status'] ?? '') !== 'pending') continue;
        $qids = $norm['question_ids'] ?? [];
        $remain = [];
        $removedHere = [];
        foreach ($qids as $qid) {
            if (isset($set[$qid])) $removedHere[] = $qid;
            else $remain[] = $qid;
        }
        if (!$removedHere) continue;
        $removed += count($removedHere);
        $touchedSchedules[] = $norm['id'];
        mark_new_questions_unscheduled($removedHere, $norm['id']);
        if (empty($remain)) {
            $item['status'] = 'cancelled';
            $item['question_ids'] = [];
            $item['message'] = '新規問題のスケジュール解除により取消しました。';
            $item['updated_at'] = $now;
        } else {
            $msg = scheduled_notifications_rebuild_new_question_message($remain);
            $item['question_ids'] = $remain;
            $item['title'] = $msg['title'];
            $item['body'] = $msg['body'];
            $item['updated_at'] = $now;
        }
    }
    unset($item);
    if ($removed > 0) scheduled_notifications_write_db($db);
    return ['ok'=>true, 'removed_count'=>$removed, 'schedule_ids'=>$touchedSchedules, 'message'=>$removed > 0 ? $removed . '件の新規問題通知スケジュールを解除しました。' : '解除対象の未配信スケジュールはありません。'];
}

function scheduled_notifications_append_push_history($rec) {
    $file = feature_scores_dir() . '/push_notice_history.json';
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $db = ['history'=>[]];
    if (is_file($file)) {
        $tmp = json_decode(file_get_contents($file), true);
        if (is_array($tmp)) $db = $tmp;
    }
    if (!isset($db['history']) || !is_array($db['history'])) $db['history'] = [];
    array_unshift($db['history'], $rec);
    $db['history'] = array_slice($db['history'], 0, 200);
    write_json_file_locked($file, $db);
}

function scheduled_notifications_run_due($limit = 20) {
    $db = scheduled_notifications_read_db();
    $now_ts = time();
    $executed = [];
    $count = 0;
    foreach ($db['items'] as &$item) {
        $norm = scheduled_notifications_normalize_item($item);
        if ($norm['status'] !== 'pending') continue;
        $scheduled_ts = strtotime($norm['scheduled_at']);
        if (!$scheduled_ts || $scheduled_ts > $now_ts) continue;
        if ($count >= $limit) break;
        if (($norm['kind'] ?? '') === 'new_question_notice' && !empty($norm['publish_on_send']) && !empty($norm['question_ids'])) {
            question_status_set_many($norm['question_ids'], 'published');
            scheduled_notifications_update_game_config_counts();
        }
        $guardKind = (($norm['kind'] ?? '') === 'new_question_notice') ? 'new_question_notice' : 'scheduled_notice';
        $guard = notice_duplicate_guard_reserve($guardKind, $norm['title'], $norm['body'], $norm['url'], $norm['id'], 86400);
        if (empty($guard['ok'])) {
            $sentAt = date('Y-m-d H:i:s');
            $item['status'] = 'cancelled';
            $item['sent_at'] = $sentAt;
            $item['sent'] = 0;
            $item['failed'] = 0;
            $item['message'] = $guard['message'] ?? '同じ内容の通知が送信済みのため中止しました。';
            $item['updated_at'] = $sentAt;
            $executed[] = ['id'=>$norm['id'],'status'=>'cancelled','sent'=>0,'failed'=>0,'message'=>$item['message']];
            $count++;
            continue;
        }
        $result = send_web_push_notifications($norm['title'], $norm['body'], $norm['url']);
        $status = (($result['failed'] ?? 0) > 0 && ($result['sent'] ?? 0) <= 0) ? 'failed' : 'sent';
        notice_duplicate_guard_finish($guard['id'] ?? '', $status === 'sent' ? 'sent' : 'failed', $result);
        $sentAt = date('Y-m-d H:i:s');
        $item['status'] = $status;
        $item['sent_at'] = $sentAt;
        $item['sent'] = intval($result['sent'] ?? 0);
        $item['failed'] = intval($result['failed'] ?? 0);
        $item['message'] = (string)($result['message'] ?? '');
        $item['updated_at'] = $sentAt;
        scheduled_notifications_append_push_history([
            'sent_at'=>$sentAt,
            'title'=>$norm['title'],
            'body'=>$norm['body'],
            'url'=>$norm['url'],
            'target'=>'all',
            'sent'=>$result['sent'] ?? 0,
            'failed'=>$result['failed'] ?? 0,
            'message'=>$result['message'] ?? '',
            'schedule_id'=>$norm['id'],
            'scheduled_at'=>$norm['scheduled_at'],
            'kind'=>(($norm['kind'] ?? '') === 'new_question_notice' ? 'new_question_notice' : 'scheduled_notice'),
            'question_ids'=>$norm['question_ids'] ?? [],
            'duplicate_guard_signature'=>$guard['signature'] ?? ''
        ]);
        $releasePatch = null;
        if (($norm['kind'] ?? '') === 'new_question_notice' && !empty($norm['question_ids'])) {
            mark_new_questions_notified($norm['question_ids'], $norm['id']);
            if ($status === 'sent') {
                $releasePatch = scheduled_release_record_patch_for_new_questions($norm['id'], $norm['question_ids'], $item['created_by'] ?? 'CRON');
            }
        }
        $executed[] = ['id'=>$norm['id'],'status'=>$status,'sent'=>$result['sent'] ?? 0,'failed'=>$result['failed'] ?? 0,'message'=>$result['message'] ?? '','release_patch'=>$releasePatch];
        $count++;
    }
    unset($item);
    if ($count > 0) scheduled_notifications_write_db($db);
    return ['executed_count'=>$count,'executed'=>$executed];
}
?>
