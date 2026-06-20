<?php
function normalize_player_id($value) {
    $player_id = trim((string)($value ?? ''));
    return function_exists('mb_substr') ? mb_substr($player_id, 0, 20, 'UTF-8') : substr($player_id, 0, 20);
}
function canonical_player_id($value) {
    return strtolower(normalize_player_id($value));
}
function validate_player_id_format($player_id) {
    $id = normalize_player_id($player_id);
    if ($id === '') return 'プレイヤーIDを入力してください。';
    if (strlen($id) < 4) return 'プレイヤーIDは4文字以上で入力してください。';
    if (strlen($id) > 20) return 'プレイヤーIDは20文字以内で入力してください。';
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) return 'プレイヤーIDに使える文字は、半角英数字・ハイフン・アンダーバーのみです。';
    return validate_player_id_content($id);
}
function player_id_contains_any($id, $words) {
    $compact = str_replace(['-', '_'], '', $id);
    foreach ($words as $word) {
        $w = strtolower((string)$word);
        if ($w === '') continue;
        if (strpos($id, $w) !== false || strpos($compact, $w) !== false) return true;
    }
    return false;
}
function validate_player_id_content($player_id) {
    $id = strtolower(normalize_player_id($player_id));
    $compact = str_replace(['-', '_'], '', $id);

    $official_words = [
        'admin','administrator','root','system','support','official','owner','operator','staff',
        'master','manager','moderator','mod','運営','公式','管理者','サポート'
    ];
    if (player_id_contains_any($id, $official_words)) {
        return '運営者や公式と誤認される表現はプレイヤーIDに使用できません。';
    }

    $inappropriate_words = [
        'sex','sexy','porn','porno','fuck','fxxk','shit','bitch','asshole','kill','die','death',
        'rape','nazi','terror','weapon','drug','suicide','idiot','stupid','baka','aho','kuso'
    ];
    if (player_id_contains_any($id, $inappropriate_words)) {
        return '不適切な言葉を含むプレイヤーIDは使用できません。';
    }

    if (preg_match('/\d{10,}/', $compact)) {
        return '電話番号などの個人情報に見える数字列はプレイヤーIDに使用できません。';
    }
    if (preg_match('/(?:\d[ \-_]*?){13,19}/', $id)) {
        return 'クレジットカード番号などの個人情報に見える数字列はプレイヤーIDに使用できません。';
    }
    if (strpos($id, '@') !== false || preg_match('/https?|www/', $id)) {
        return 'メールアドレスやURLに見える表現はプレイヤーIDに使用できません。';
    }

    $brand_words = [
        'nintendo','pokemon','mario','pikachu','disney','mickey','marvel','sony','playstation',
        'apple','google','youtube','line','tiktok','instagram','twitter','xjapan','dragonball',
        'onepiece','naruto','doraemon','anpanman','kimetsu','totoro','ghibli'
    ];
    if (player_id_contains_any($id, $brand_words)) {
        return '企業名・商標・有名キャラクター名にあたる表現はプレイヤーIDに使用できません。';
    }

    return '';
}
function normalize_client_token($value) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value ?? '');
}
function normalize_invite_id($value) {
    return strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($value ?? '')));
}
function feature_scores_dir() {
    $dir = __DIR__ . '/../scores';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}
function read_json_file_safe($file, $default) {
    if (!is_file($file)) return $default;
    $raw = file_get_contents($file);
    $json = $raw ? json_decode($raw, true) : null;
    return is_array($json) ? $json : $default;
}
function write_json_file_locked($file, $data) {
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}

function player_suspensions_file() {
    return feature_scores_dir() . '/player_suspensions.json';
}
function load_player_suspensions() {
    $db = read_json_file_safe(player_suspensions_file(), ['players'=>[]]);
    if (!isset($db['players']) || !is_array($db['players'])) $db['players'] = [];
    return $db;
}
function save_player_suspensions($db) {
    if (!isset($db['players']) || !is_array($db['players'])) $db['players'] = [];
    return write_json_file_locked(player_suspensions_file(), $db);
}
function player_suspension_record($player_id) {
    $pid = normalize_player_id($player_id);
    if ($pid === '') return null;
    $db = load_player_suspensions();
    return $db['players'][$pid] ?? null;
}
function is_player_suspended($player_id) {
    $rec = player_suspension_record($player_id);
    return is_array($rec) && (($rec['status'] ?? '') === 'suspended');
}

function safe_csv_cell_feature($value) {
    $s = (string)$value;
    if ($s !== '' && preg_match('/^[=+\-@]/', $s)) return "'" . $s;
    return $s;
}

function safe_csv_cell($value) {
    return safe_csv_cell_feature($value);
}

function verify_player_client($player_id, $client_token) {
    $player_id = normalize_player_id($player_id);
    if (validate_player_id_format($player_id) !== '' || strlen($client_token) < 16) return false;
    if (is_player_suspended($player_id)) return false;
    $dir = feature_scores_dir();
    $file = $dir . '/player_registry.csv';
    $hash = hash('sha256', $client_token);
    $now = date('Y-m-d H:i:s');

    if (!is_file($file)) {
        $fp_new = fopen($file, 'w');
        if (!$fp_new) return false;
        fputcsv($fp_new, ['player_id','client_hash','created_at','last_login_at']);
        fputcsv($fp_new, [safe_csv_cell_feature($player_id), $hash, $now, $now]);
        fclose($fp_new);
        return true;
    }

    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }

    $rows = [];
    rewind($fp);
    $header = fgetcsv($fp);
    while (($row = fgetcsv($fp)) !== false) {
        if (count($row) < 4) continue;
        $rows[] = [
            'player_id' => $row[0] ?? '',
            'client_hash' => $row[1] ?? '',
            'created_at' => $row[2] ?? '',
            'last_login_at' => $row[3] ?? '',
        ];
    }

    $found = false;
    $ok = false;
    foreach ($rows as &$row) {
        if (canonical_player_id($row['player_id']) === canonical_player_id($player_id)) {
            $found = true;
            if (hash_equals($row['client_hash'], $hash)) {
                $row['last_login_at'] = $now;
                $ok = true;
            } else {
                $ok = false;
            }
            break;
        }
    }
    unset($row);

    if (!$found) {
        $rows[] = [
            'player_id' => $player_id,
            'client_hash' => $hash,
            'created_at' => $now,
            'last_login_at' => $now,
        ];
        $ok = true;
    }

    if ($ok) {
        rewind($fp);
        ftruncate($fp, 0);
        fputcsv($fp, ['player_id','client_hash','created_at','last_login_at']);
        foreach ($rows as $row) {
            fputcsv($fp, [
                safe_csv_cell_feature($row['player_id']),
                $row['client_hash'],
                $row['created_at'],
                $row['last_login_at'],
            ]);
        }
        fflush($fp);
    }

    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
function invite_hash_for_code($invite_id) {
    $salt = 'YAKYU_YAROUZE_INVITE_V534';
    return hash('sha256', $salt . ':' . normalize_invite_id($invite_id));
}
function default_invite_db() {
    return [
        'version' => 'v534',
        'hash_algorithm' => 'sha256',
        'salt_id' => 'YAKYU_YAROUZE_INVITE_V534',
        'codes' => []
    ];
}
function load_invite_db() {
    $file = feature_scores_dir() . '/invite_codes.json';
    $db = read_json_file_safe($file, null);
    if (!is_array($db) || !isset($db['codes'])) {
        $db = default_invite_db();
        write_json_file_locked($file, $db);
    }
    return $db;
}
function load_player_feature_db() {
    $file = feature_scores_dir() . '/player_features.json';
    $db = read_json_file_safe($file, ['players'=>[]]);
    if (!isset($db['players']) || !is_array($db['players'])) $db['players'] = [];
    return $db;
}
function save_player_feature_db($db) {
    return write_json_file_locked(feature_scores_dir() . '/player_features.json', $db);
}
function feature_flags_for_player($player_id) {
    $db = load_player_feature_db();
    $row = $db['players'][$player_id] ?? [];
    $flags = $row['flags'] ?? [];
    return is_array($flags) ? $flags : [];
}
function feature_sources_for_player($player_id) {
    $db = load_player_feature_db();
    $row = $db['players'][$player_id] ?? [];
    $sources = $row['sources'] ?? [];
    return is_array($sources) ? $sources : [];
}
function player_has_feature($player_id, $feature) {
    $flags = feature_flags_for_player($player_id);
    return !empty($flags[$feature]);
}

function player_has_quiz_master_access($player_id) {
    if (player_has_feature($player_id, 'admin_mode')) return true;
    return player_has_feature($player_id, 'quiz_master');
}

function normalize_admin_id($value) {
    return strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($value ?? '')));
}
function admin_hash_for_code($admin_id) {
    $salt = 'YAKYU_YAROUZE_ADMIN_V567';
    return hash('sha256', $salt . ':' . normalize_admin_id($admin_id));
}
function default_admin_db() {
    return [
        'version' => 'v567',
        'hash_algorithm' => 'sha256',
        'salt_id' => 'YAKYU_YAROUZE_ADMIN_V567',
        'codes' => []
    ];
}
function load_admin_db() {
    $file = feature_scores_dir() . '/admin_codes.json';
    $db = read_json_file_safe($file, null);
    if (!is_array($db) || !isset($db['codes'])) {
        $db = default_admin_db();
        write_json_file_locked($file, $db);
    }
    return $db;
}
function all_restricted_features() {
    return [
        'mistake_review'=>['label'=>'間違いプレイチェック機能','restricted'=>true],
        'device_transfer'=>['label'=>'端末引継ぎ機能','restricted'=>true],
        'quiz_master'=>['label'=>'野球博士チャレンジ','restricted'=>true],
        'admin_mode'=>['label'=>'管理者用モード','restricted'=>true]
    ];
}

function client_ip_for_audit() {
    $keys = ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'];
    foreach ($keys as $k) {
        $v = $_SERVER[$k] ?? '';
        if ($v === '') continue;
        $first = trim(explode(',', $v)[0]);
        if ($first !== '') return substr($first, 0, 80);
    }
    return '';
}
function user_agent_for_audit() {
    return substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 240);
}
function hash_short($value) {
    $v = (string)$value;
    if ($v === '') return '';
    return substr(hash('sha256', $v), 0, 16);
}
function append_csv_locked($file, $header, $row) {
    $is_new = !is_file($file) || filesize($file) === 0;
    $fp = fopen($file, 'a');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    if ($is_new) fputcsv($fp, $header);
    fputcsv($fp, $row);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return true;
}
function audit_log_id_event($event) {
    $file = feature_scores_dir() . '/id_audit_log.csv';
    $header = ['at','action','result','player_id','id_type','code_hash_prefix','features','ip','user_agent','client_hash','message'];
    $row = [
        date('Y-m-d H:i:s'),
        safe_csv_cell_feature($event['action'] ?? ''),
        safe_csv_cell_feature($event['result'] ?? ''),
        safe_csv_cell_feature($event['player_id'] ?? ''),
        safe_csv_cell_feature($event['id_type'] ?? ''),
        safe_csv_cell_feature($event['code_hash_prefix'] ?? ''),
        safe_csv_cell_feature(is_array($event['features'] ?? null) ? implode('|', $event['features']) : ($event['features'] ?? '')),
        safe_csv_cell_feature($event['ip'] ?? client_ip_for_audit()),
        safe_csv_cell_feature($event['user_agent'] ?? user_agent_for_audit()),
        safe_csv_cell_feature($event['client_hash'] ?? ''),
        safe_csv_cell_feature($event['message'] ?? ''),
    ];
    return append_csv_locked($file, $header, $row);
}
function audit_log_admin_event($event) {
    $file = feature_scores_dir() . '/admin_audit_log.csv';
    $header = ['at','admin_label','action','result','target_type','target_hash_prefix','ip','user_agent','message'];
    $row = [
        date('Y-m-d H:i:s'),
        safe_csv_cell_feature($event['admin_label'] ?? ''),
        safe_csv_cell_feature($event['action'] ?? ''),
        safe_csv_cell_feature($event['result'] ?? ''),
        safe_csv_cell_feature($event['target_type'] ?? ''),
        safe_csv_cell_feature($event['target_hash_prefix'] ?? ''),
        safe_csv_cell_feature($event['ip'] ?? client_ip_for_audit()),
        safe_csv_cell_feature($event['user_agent'] ?? user_agent_for_audit()),
        safe_csv_cell_feature($event['message'] ?? ''),
    ];
    return append_csv_locked($file, $header, $row);
}
function id_attempt_key($player_id, $input_id) {
    $ip = client_ip_for_audit();
    return hash('sha256', $ip . '|' . normalize_player_id($player_id) . '|' . normalize_invite_id($input_id));
}
function id_attempt_status($player_id, $input_id) {
    $file = feature_scores_dir() . '/id_attempts.json';
    $db = read_json_file_safe($file, ['attempts'=>[]]);
    if (!isset($db['attempts']) || !is_array($db['attempts'])) $db['attempts'] = [];
    $now = time();
    $key = id_attempt_key($player_id, $input_id);
    $row = $db['attempts'][$key] ?? ['fails'=>[], 'blocked_until'=>0];
    $row['fails'] = array_values(array_filter($row['fails'] ?? [], function($ts) use ($now) { return intval($ts) >= $now - 600; }));
    $blocked_until = intval($row['blocked_until'] ?? 0);
    $db['attempts'][$key] = $row;
    write_json_file_locked($file, $db);
    return ['blocked'=>($blocked_until > $now), 'blocked_until'=>$blocked_until, 'recent_fails'=>count($row['fails'])];
}
function record_id_attempt($player_id, $input_id, $success) {
    $file = feature_scores_dir() . '/id_attempts.json';
    $db = read_json_file_safe($file, ['attempts'=>[]]);
    if (!isset($db['attempts']) || !is_array($db['attempts'])) $db['attempts'] = [];
    $now = time();
    $key = id_attempt_key($player_id, $input_id);
    $row = $db['attempts'][$key] ?? ['fails'=>[], 'blocked_until'=>0];
    $row['fails'] = array_values(array_filter($row['fails'] ?? [], function($ts) use ($now) { return intval($ts) >= $now - 600; }));
    if ($success) {
        $row['fails'] = [];
        $row['blocked_until'] = 0;
    } else {
        $row['fails'][] = $now;
        if (count($row['fails']) >= 5) $row['blocked_until'] = $now + 600;
    }
    $db['attempts'][$key] = $row;
    // 軽量化：24時間以上古い失敗のみの行を掃除
    foreach ($db['attempts'] as $k=>$v) {
        $fails = array_values(array_filter($v['fails'] ?? [], function($ts) use ($now) { return intval($ts) >= $now - 86400; }));
        if (!$fails && intval($v['blocked_until'] ?? 0) < $now) unset($db['attempts'][$k]);
        else $db['attempts'][$k]['fails'] = $fails;
    }
    return write_json_file_locked($file, $db);
}

function admin_login_attempts_file() {
    return feature_scores_dir() . '/super_admin_login_attempts.json';
}
function admin_login_attempt_key($admin_id) {
    $id = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($admin_id ?? '')));
    $ip = client_ip_for_audit();
    return hash('sha256', $ip . ':' . $id);
}
function admin_login_attempt_status($admin_id) {
    $file = admin_login_attempts_file();
    $db = read_json_file_safe($file, ['attempts'=>[]]);
    if (!isset($db['attempts']) || !is_array($db['attempts'])) $db['attempts'] = [];
    $now = time();
    $key = admin_login_attempt_key($admin_id);
    $row = $db['attempts'][$key] ?? ['fails'=>[], 'blocked_until'=>0];
    $fails = array_values(array_filter($row['fails'] ?? [], function($ts) use ($now) { return intval($ts) >= $now - 900; }));
    $blocked_until = intval($row['blocked_until'] ?? 0);
    if ($blocked_until > $now) {
        return [
            'blocked'=>true,
            'blocked_until'=>$blocked_until,
            'retry_after'=>$blocked_until - $now,
            'remaining'=>0,
            'fail_count'=>count($fails)
        ];
    }
    return [
        'blocked'=>false,
        'blocked_until'=>0,
        'retry_after'=>0,
        'remaining'=>max(0, 5 - count($fails)),
        'fail_count'=>count($fails)
    ];
}
function record_admin_login_attempt($admin_id, $success) {
    $file = admin_login_attempts_file();
    $db = read_json_file_safe($file, ['attempts'=>[]]);
    if (!isset($db['attempts']) || !is_array($db['attempts'])) $db['attempts'] = [];
    $now = time();
    $key = admin_login_attempt_key($admin_id);
    $row = $db['attempts'][$key] ?? ['fails'=>[], 'blocked_until'=>0, 'last_admin_id'=>''];
    $fails = array_values(array_filter($row['fails'] ?? [], function($ts) use ($now) { return intval($ts) >= $now - 900; }));
    if ($success) {
        $fails = [];
        $row['blocked_until'] = 0;
    } else {
        $fails[] = $now;
        if (count($fails) >= 5) $row['blocked_until'] = $now + 900;
    }
    $row['fails'] = $fails;
    $row['last_attempt_at'] = $now;
    $row['last_admin_id'] = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($admin_id ?? '')));
    $row['ip_hash'] = hash_short(client_ip_for_audit());
    $db['attempts'][$key] = $row;
    foreach ($db['attempts'] as $k=>$v) {
        $old_fails = array_values(array_filter($v['fails'] ?? [], function($ts) use ($now) { return intval($ts) >= $now - 86400; }));
        if (!$old_fails && intval($v['blocked_until'] ?? 0) < $now) unset($db['attempts'][$k]);
        else $db['attempts'][$k]['fails'] = $old_fails;
    }
    return write_json_file_locked($file, $db);
}
function normalize_notice_url($url) {
    $url = trim((string)$url);
    if ($url === '') return './';
    $url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
    if (preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url)) return './';
    if (strpos($url, '//') === 0) return './';
    if (strpos($url, '\\') !== false) return './';
    if (preg_match('/^(\.\/|\/|\?|#|[A-Za-z0-9_\-\.\/]+(?:\?.*)?(?:#.*)?)$/u', $url)) {
        if (strpos($url, '..') !== false) return './';
        return $url;
    }
    return './';
}

function normalize_admin_action($value) {
    return preg_replace('/[^a-zA-Z0-9_\-]/', '', $value ?? '');
}
function super_admin_hash_for_id($id, $salt) {
    return hash('sha256', $salt . ':' . strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper($id ?? ''))));
}
function super_admin_password_hash($password, $salt) {
    return hash('sha256', $salt . ':' . (string)$password);
}
function load_super_admin_db() {
    $file = feature_scores_dir() . '/super_admins.json';
    $db = read_json_file_safe($file, ['version'=>'v570','hash_algorithm'=>'sha256','salt_id'=>'YAKYU_YAROUZE_SUPER_ADMIN_V570','admins'=>[]]);
    if (!isset($db['admins']) || !is_array($db['admins'])) $db['admins'] = [];
    return $db;
}
function load_admin_session_db() {
    $file = feature_scores_dir() . '/admin_sessions.json';
    $db = read_json_file_safe($file, ['sessions'=>[]]);
    if (!isset($db['sessions']) || !is_array($db['sessions'])) $db['sessions'] = [];
    return $db;
}
function save_admin_session_db($db) {
    return write_json_file_locked(feature_scores_dir() . '/admin_sessions.json', $db);
}
function create_admin_session($admin_label) {
    $token = bin2hex(random_bytes(24));
    $db = load_admin_session_db();
    $now = time();
    foreach ($db['sessions'] as $k=>$s) {
        if (intval($s['expires_at'] ?? 0) < $now) unset($db['sessions'][$k]);
    }
    $db['sessions'][$token] = [
        'admin_label'=>$admin_label,
        'created_at'=>$now,
        'last_seen_at'=>$now,
        'expires_at'=>$now + 21600,
        'ip'=>client_ip_for_audit(),
        'user_agent_hash'=>hash_short(user_agent_for_audit())
    ];
    save_admin_session_db($db);
    return $token;
}
function require_super_admin_session($token) {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower($token ?? ''));
    if ($token === '') return null;
    $db = load_admin_session_db();
    $now = time();
    $s = $db['sessions'][$token] ?? null;
    if (!$s || intval($s['expires_at'] ?? 0) < $now) return null;
    $s['last_seen_at'] = $now;
    $s['expires_at'] = $now + 21600;
    $db['sessions'][$token] = $s;
    save_admin_session_db($db);
    return $s;
}
function code_hash_for_type($type, $code) {
    if ($type === 'admin') return admin_hash_for_code($code);
    return invite_hash_for_code($code);
}
function load_code_db_by_type($type) {
    return $type === 'admin' ? load_admin_db() : load_invite_db();
}
function code_db_file_by_type($type) {
    return feature_scores_dir() . ($type === 'admin' ? '/admin_codes.json' : '/invite_codes.json');
}
function default_features_for_code_type($type) {
    return $type === 'admin' ? ['mistake_review','device_transfer','quiz_master','admin_mode'] : ['mistake_review','device_transfer','quiz_master'];
}
function summarize_code_db($type, $db) {
    $out = [];
    foreach (($db['codes'] ?? []) as $hash=>$row) {
        $used_by = isset($row['used_by']) && is_array($row['used_by']) ? $row['used_by'] : [];
        $used_details = [];
        foreach ($used_by as $player_id=>$used_at) {
            $used_details[] = [
                'player_id'=>(string)$player_id,
                'used_at'=>(string)$used_at
            ];
        }
        usort($used_details, function($a,$b){
            $cmp = strcmp($a['used_at'] ?? '', $b['used_at'] ?? '');
            if ($cmp !== 0) return $cmp;
            return strcmp($a['player_id'] ?? '', $b['player_id'] ?? '');
        });
        $first_used_at = $used_details[0]['used_at'] ?? '';
        $last_used_at = '';
        if (!empty($used_details)) {
            $last = $used_details[count($used_details)-1];
            $last_used_at = $last['used_at'] ?? '';
        }
        $out[] = [
            'hash_prefix'=>substr($hash, 0, 12),
            'enabled'=>!empty($row['enabled']),
            'features'=>array_values($row['features'] ?? []),
            'label'=>$row['label'] ?? '',
            'max_uses'=>intval($row['max_uses'] ?? 0),
            'used_count'=>count($used_by),
            'used_by_details'=>$used_details,
            'first_used_at'=>$first_used_at,
            'last_used_at'=>$last_used_at,
            'created_at'=>$row['created_at'] ?? '',
            'disabled_at'=>$row['disabled_at'] ?? '',
            'disabled_reason'=>$row['disabled_reason'] ?? '',
            'type'=>$type,
            'self_issued'=>!empty($row['self_issued']),
            'owner_player_id'=>$row['owner_player_id'] ?? '',
            'issued_to_player_id'=>$row['issued_to_player_id'] ?? '',
            'issued_at'=>$row['issued_at'] ?? '',
            'plain_code'=>$row['plain_code'] ?? ''
        ];
    }
    usort($out, function($a,$b){ return strcmp(($b['created_at'] ?? '').($b['hash_prefix'] ?? ''), ($a['created_at'] ?? '').($a['hash_prefix'] ?? '')); });
    return $out;
}
function read_recent_csv_assoc($file, $limit=200) {
    if (!is_file($file)) return [];
    $rows = [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    $header = fgetcsv($fp);
    if (!is_array($header)) { fclose($fp); return []; }
    while (($row = fgetcsv($fp)) !== false) {
        $assoc = [];
        foreach ($header as $i=>$h) $assoc[$h] = $row[$i] ?? '';
        $rows[] = $assoc;
    }
    fclose($fp);
    return array_slice(array_reverse($rows), 0, $limit);
}

function access_log_event($event) {
    $file = feature_scores_dir() . '/access_log.csv';
    $header = ['at','event','page','player_id','grade','position','ip','user_agent','client_hash','display_mode','device_type','os','browser','platform','extra'];
    $ua = $event['user_agent'] ?? user_agent_for_audit();
    $row = [
        date('Y-m-d H:i:s'),
        safe_csv_cell_feature($event['event'] ?? 'page_view'),
        safe_csv_cell_feature($event['page'] ?? ''),
        safe_csv_cell_feature($event['player_id'] ?? ''),
        safe_csv_cell_feature($event['grade'] ?? ''),
        safe_csv_cell_feature($event['position'] ?? ''),
        safe_csv_cell_feature($event['ip'] ?? client_ip_for_audit()),
        safe_csv_cell_feature($ua),
        safe_csv_cell_feature($event['client_hash'] ?? ''),
        safe_csv_cell_feature($event['display_mode'] ?? detect_display_mode_from_access_event($event)),
        safe_csv_cell_feature($event['device_type'] ?? detect_device_type_from_user_agent($ua)),
        safe_csv_cell_feature($event['os'] ?? detect_os_from_user_agent($ua)),
        safe_csv_cell_feature($event['browser'] ?? detect_browser_from_user_agent($ua)),
        safe_csv_cell_feature($event['platform'] ?? ''),
        safe_csv_cell_feature($event['extra'] ?? ''),
    ];
    return append_csv_locked($file, $header, $row);
}
function detect_display_mode_from_access_event($event) {
    $m = $event['display_mode'] ?? '';
    return $m !== '' ? $m : 'unknown';
}
function detect_device_type_from_user_agent($ua) {
    $ua = (string)$ua;
    if (preg_match('/iPad|Tablet/i', $ua)) return 'tablet';
    if (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) return 'tablet';
    if (preg_match('/iPhone|iPod|Android|Mobile/i', $ua)) return 'mobile';
    return 'desktop';
}
function detect_os_from_user_agent($ua) {
    $ua = (string)$ua;
    if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'iOS';
    if (preg_match('/Android/i', $ua)) return 'Android';
    if (preg_match('/Windows/i', $ua)) return 'Windows';
    if (preg_match('/Mac OS X|Macintosh/i', $ua)) return 'macOS';
    if (preg_match('/Linux/i', $ua)) return 'Linux';
    return 'other';
}
function detect_browser_from_user_agent($ua) {
    $ua = (string)$ua;
    if (preg_match('/CriOS/i', $ua)) return 'Chrome iOS';
    if (preg_match('/FxiOS/i', $ua)) return 'Firefox iOS';
    if (preg_match('/EdgiOS/i', $ua)) return 'Edge iOS';
    if (preg_match('/Edg/i', $ua)) return 'Edge';
    if (preg_match('/Chrome|Chromium/i', $ua) && !preg_match('/CriOS/i', $ua)) return 'Chrome';
    if (preg_match('/Safari/i', $ua) && !preg_match('/Chrome|CriOS|Chromium|Android/i', $ua)) return 'Safari';
    if (preg_match('/Firefox/i', $ua)) return 'Firefox';
    return 'other';
}
function read_csv_assoc_all($file, $limit=5000) {
    if (!is_file($file)) return [];
    $rows = [];
    $fp = fopen($file, 'r');
    if (!$fp) return [];
    $header = fgetcsv($fp);
    if (!is_array($header)) { fclose($fp); return []; }
    while (($row = fgetcsv($fp)) !== false) {
        $assoc = [];
        foreach ($header as $i=>$h) $assoc[$h] = $row[$i] ?? '';
        $rows[] = $assoc;
    }
    fclose($fp);
    if ($limit > 0 && count($rows) > $limit) $rows = array_slice($rows, -$limit);
    return $rows;
}
function csv_file_info($file) {
    return [
        'exists'=>is_file($file),
        'size'=>is_file($file) ? filesize($file) : 0,
        'path'=>basename($file)
    ];
}
function normalize_assoc_value($row, $keys, $default='') {
    foreach ($keys as $k) {
        if (isset($row[$k]) && $row[$k] !== '') return $row[$k];
    }
    return $default;
}

function parse_score_records_for_analytics() {
    $file = feature_scores_dir() . '/score_log.csv';
    $rows = read_csv_assoc_all($file, 20000);
    $records = [];
    foreach ($rows as $r) {
        $played_at = normalize_assoc_value($r, ['played_at','date','created_at','timestamp'], '');
        $player_id = normalize_assoc_value($r, ['player_id','player','id','name'], '');
        $records[] = [
            'played_at'=>$played_at,
            'player_id'=>$player_id,
            'grade'=>intval(normalize_assoc_value($r, ['grade','gakunen'], 0)),
            'position'=>normalize_assoc_value($r, ['position','pos'], ''),
            'total_score'=>intval(normalize_assoc_value($r, ['total_score','score','total'], 0)),
            'attack_score'=>intval(normalize_assoc_value($r, ['attack_score','attack'], 0)),
            'defense_score'=>intval(normalize_assoc_value($r, ['defense_score','defense'], 0)),
            'max_score'=>intval(normalize_assoc_value($r, ['max_score','max'], 54)),
            'logs_json'=>normalize_assoc_value($r, ['logs_json','logs'], '[]'),
        ];
    }
    return $records;
}
function analytics_date_key($value) {
    $s = (string)$value;
    return strlen($s) >= 10 ? substr($s, 0, 10) : '';
}

function analytics_logs_correct_question_ids($logs_json, $fallback_score = 0, $fallback_prefix = '') {
    $logs = json_decode($logs_json ?? '[]', true);
    $ids = [];
    if (is_array($logs)) {
        foreach ($logs as $i=>$log) {
            if (!is_array($log)) continue;
            $score = intval($log['score'] ?? 0);
            if ($score < 3) continue;
            $qid = (string)($log['id'] ?? ($log['question_id'] ?? ''));
            if ($qid === '') $qid = $fallback_prefix !== '' ? ($fallback_prefix . '_' . $i) : ('q_' . $i);
            $ids[$qid] = true;
        }
    }
    if (!count($ids) && intval($fallback_score) > 0) {
        $count = intdiv(intval($fallback_score), 3);
        for ($i=0; $i<$count; $i++) $ids[($fallback_prefix ?: 'legacy') . '_' . $i] = true;
    }
    return array_keys($ids);
}
function build_user_analytics() {
    $scores = parse_score_records_for_analytics();
    $registry_rows = read_csv_assoc_all(feature_scores_dir() . '/player_registry.csv', 20000);
    $access_rows = read_csv_assoc_all(feature_scores_dir() . '/access_log.csv', 30000);
    $latest_access_by_player = [];
    $device_summary = ['display_mode'=>[], 'device_type'=>[], 'os'=>[], 'browser'=>[]];
    foreach ($access_rows as $ar) {
        $pid = $ar['player_id'] ?? '';
        $ua = $ar['user_agent'] ?? '';
        $display = ($ar['display_mode'] ?? '') ?: detect_display_mode_from_access_event($ar);
        $device = ($ar['device_type'] ?? '') ?: detect_device_type_from_user_agent($ua);
        $os_name = ($ar['os'] ?? '') ?: detect_os_from_user_agent($ua);
        $browser_name = ($ar['browser'] ?? '') ?: detect_browser_from_user_agent($ua);
        if ($display !== '') $device_summary['display_mode'][$display] = ($device_summary['display_mode'][$display] ?? 0) + 1;
        if ($device !== '') $device_summary['device_type'][$device] = ($device_summary['device_type'][$device] ?? 0) + 1;
        if ($os_name !== '') $device_summary['os'][$os_name] = ($device_summary['os'][$os_name] ?? 0) + 1;
        if ($browser_name !== '') $device_summary['browser'][$browser_name] = ($device_summary['browser'][$browser_name] ?? 0) + 1;
        if ($pid !== '') {
            $at = $ar['at'] ?? '';
            if (!isset($latest_access_by_player[$pid]) || $at >= ($latest_access_by_player[$pid]['at'] ?? '')) {
                $latest_access_by_player[$pid] = [
                    'at'=>$at,
                    'display_mode'=>$display,
                    'device_type'=>$device,
                    'os'=>$os_name,
                    'browser'=>$browser_name,
                    'platform'=>$ar['platform'] ?? ''
                ];
            }
        }
    }
    $features_db = load_player_feature_db();
    $players = [];
    foreach ($registry_rows as $r) {
        $pid = $r['player_id'] ?? '';
        if ($pid === '') continue;
        $players[$pid] = [
            'player_id'=>$pid,
            'registered_at'=>$r['created_at'] ?? '',
            'last_login_at'=>$r['last_login_at'] ?? '',
            'play_count'=>0,
            'best_score'=>0,
            'average_score'=>0,
            'total_score_sum'=>0,
            'last_played_at'=>'',
            'positions'=>[],
            'grades'=>[],
            'features'=>array_keys($features_db['players'][$pid]['flags'] ?? []),
            'correct_ids'=>[],
            'correct_count'=>0,
            'display_mode'=>'',
            'device_type'=>'',
            'os'=>'',
            'browser'=>'',
            'platform'=>''
        ];
    }
    foreach ($scores as $s) {
        $pid = $s['player_id'];
        if ($pid === '') continue;
        if (!isset($players[$pid])) {
            $players[$pid] = [
                'player_id'=>$pid,'registered_at'=>'','last_login_at'=>'','play_count'=>0,'best_score'=>0,
                'average_score'=>0,'total_score_sum'=>0,'last_played_at'=>'','positions'=>[],'grades'=>[],
                'features'=>array_keys($features_db['players'][$pid]['flags'] ?? []),
                'correct_ids'=>[],
                'correct_count'=>0,
                'display_mode'=>'',
                'device_type'=>'',
                'os'=>'',
                'browser'=>'',
                'platform'=>''
            ];
        }
        $players[$pid]['play_count']++;
        $record_key = ($s['played_at'] ?? '') . '_' . $pid . '_' . $players[$pid]['play_count'];
        foreach (analytics_logs_correct_question_ids($s['logs_json'] ?? '[]', $s['total_score'] ?? 0, $record_key) as $qid) {
            $players[$pid]['correct_ids'][$qid] = true;
        }
        $players[$pid]['total_score_sum'] += $s['total_score'];
        if ($s['total_score'] > $players[$pid]['best_score']) $players[$pid]['best_score'] = $s['total_score'];
        if (($s['played_at'] ?? '') > $players[$pid]['last_played_at']) $players[$pid]['last_played_at'] = $s['played_at'];
        if ($s['position'] !== '') $players[$pid]['positions'][$s['position']] = true;
        if ($s['grade']) $players[$pid]['grades'][(string)$s['grade']] = true;
    }
    foreach ($players as &$p) {
        $access_info = $latest_access_by_player[$p['player_id']] ?? null;
        if ($access_info) {
            $p['display_mode'] = $access_info['display_mode'] ?? '';
            $p['device_type'] = $access_info['device_type'] ?? '';
            $p['os'] = $access_info['os'] ?? '';
            $p['browser'] = $access_info['browser'] ?? '';
            $p['platform'] = $access_info['platform'] ?? '';
        }
        $p['average_score'] = $p['play_count'] > 0 ? round($p['total_score_sum'] / $p['play_count'], 1) : 0;
        $p['positions'] = array_keys($p['positions']);
        sort($p['positions']);
        $p['grades'] = array_keys($p['grades']);
        sort($p['grades']);
        $p['correct_count'] = count($p['correct_ids'] ?? []);
        unset($p['correct_ids']);
        unset($p['total_score_sum']);
    }
    unset($p);
    $list = array_values($players);
    usort($list, function($a,$b){
        $al = $a['last_played_at'] ?: $a['last_login_at'];
        $bl = $b['last_played_at'] ?: $b['last_login_at'];
        return strcmp($bl, $al);
    });
    $by_grade = [];
    $by_position = [];
    $active_7 = [];
    $active_30 = [];
    $now = time();
    foreach ($scores as $s) {
        $g = (string)$s['grade'];
        if ($g !== '0') $by_grade[$g] = ($by_grade[$g] ?? 0) + 1;
        $pos = $s['position'] ?: '-';
        $by_position[$pos] = ($by_position[$pos] ?? 0) + 1;
        $t = strtotime($s['played_at']);
        if ($t && $t >= $now - 7*86400) $active_7[$s['player_id']] = true;
        if ($t && $t >= $now - 30*86400) $active_30[$s['player_id']] = true;
    }
    return [
        'summary'=>[
            'registered_users'=>count($players),
            'played_users'=>count(array_filter($players, function($p){ return $p['play_count'] > 0; })),
            'total_plays'=>count($scores),
            'active_users_7d'=>count($active_7),
            'active_users_30d'=>count($active_30),
            'device_summary'=>$device_summary,
            'source_status'=>[
                'score_log'=>csv_file_info(feature_scores_dir() . '/score_log.csv'),
                'player_registry'=>csv_file_info(feature_scores_dir() . '/player_registry.csv')
            ]
        ],
        'by_grade'=>$by_grade,
        'by_position'=>$by_position,
        'top_players'=>array_slice(array_values(array_filter($list, function($p){ return $p['play_count'] > 0; })), 0, 100),
        'recent_users'=>array_slice($list, 0, 200)
    ];
}
function build_access_analytics() {
    $access = read_csv_assoc_all(feature_scores_dir() . '/access_log.csv', 30000);
    $idlog = read_csv_assoc_all(feature_scores_dir() . '/id_audit_log.csv', 30000);
    $daily = [];
    $pages = [];
    $events = [];
    $display_modes = [];
    $device_types = [];
    $os_counts = [];
    $browser_counts = [];
    $unique_hashes = [];
    foreach ($access as &$r) {
        $ua = $r['user_agent'] ?? '';
        if (($r['display_mode'] ?? '') === '') $r['display_mode'] = detect_display_mode_from_access_event($r);
        if (($r['device_type'] ?? '') === '') $r['device_type'] = detect_device_type_from_user_agent($ua);
        if (($r['os'] ?? '') === '') $r['os'] = detect_os_from_user_agent($ua);
        if (($r['browser'] ?? '') === '') $r['browser'] = detect_browser_from_user_agent($ua);
        $display_modes[$r['display_mode'] ?: '-'] = ($display_modes[$r['display_mode'] ?: '-'] ?? 0) + 1;
        $device_types[$r['device_type'] ?: '-'] = ($device_types[$r['device_type'] ?: '-'] ?? 0) + 1;
        $os_counts[$r['os'] ?: '-'] = ($os_counts[$r['os'] ?: '-'] ?? 0) + 1;
        $browser_counts[$r['browser'] ?: '-'] = ($browser_counts[$r['browser'] ?: '-'] ?? 0) + 1;
        $d = analytics_date_key($r['at'] ?? '');
        if ($d !== '') $daily[$d] = ($daily[$d] ?? 0) + 1;
        $page = $r['page'] ?: '-';
        $pages[$page] = ($pages[$page] ?? 0) + 1;
        $ev = $r['event'] ?: '-';
        $events[$ev] = ($events[$ev] ?? 0) + 1;
        if (($r['client_hash'] ?? '') !== '') $unique_hashes[$r['client_hash']] = true;
    }
    unset($r);
    ksort($daily);
    arsort($pages);
    arsort($events);
    $id_results = [];
    $id_daily = [];
    foreach ($idlog as $r) {
        $res = $r['result'] ?: '-';
        $id_results[$res] = ($id_results[$res] ?? 0) + 1;
        $d = analytics_date_key($r['at'] ?? '');
        if ($d !== '') $id_daily[$d] = ($id_daily[$d] ?? 0) + 1;
    }
    ksort($id_daily);
    return [
        'summary'=>[
            'total_access_events'=>count($access),
            'unique_clients'=>count($unique_hashes),
            'id_audit_events'=>count($idlog),
            'id_failures'=>($id_results['invalid'] ?? 0) + ($id_results['blocked'] ?? 0) + ($id_results['limit'] ?? 0),
            'source_status'=>[
                'access_log'=>csv_file_info(feature_scores_dir() . '/access_log.csv'),
                'id_audit_log'=>csv_file_info(feature_scores_dir() . '/id_audit_log.csv')
            ]
        ],
        'daily_access'=>$daily,
        'pages'=>array_slice($pages, 0, 20, true),
        'events'=>$events,
        'id_results'=>$id_results,
        'id_daily'=>$id_daily,
        'display_modes'=>$display_modes,
        'device_types'=>$device_types,
        'os_counts'=>$os_counts,
        'browser_counts'=>$browser_counts,
        'recent_access'=>array_slice(array_reverse($access), 0, 300)
    ];
}

?>
