<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const ADMIN_USER = 'admin';
const ADMIN_PASS = '@buivandung2012';
$DATA_DIR = __DIR__ . '/admin_data';

if (!is_dir($DATA_DIR)) { @mkdir($DATA_DIR, 0755, true); }

function out(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function read_json_file(string $name, array $fallback = []): array {
    global $DATA_DIR;
    $file = $DATA_DIR . '/' . $name . '.json';
    if (!is_file($file)) return $fallback;
    $raw = @file_get_contents($file);
    if ($raw === false || trim($raw) === '') return $fallback;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $fallback;
}
function write_json_file(string $name, array $data): bool {
    global $DATA_DIR;
    if (!is_dir($DATA_DIR) && !@mkdir($DATA_DIR, 0755, true)) return false;
    $file = $DATA_DIR . '/' . $name . '.json';
    $tmp = $file . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) return false;
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return @rename($tmp, $file);
}
function request_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function admin_ok(): bool {
    $u = $_SERVER['PHP_AUTH_USER'] ?? '';
    $p = $_SERVER['PHP_AUTH_PW'] ?? '';
    return hash_equals(ADMIN_USER, (string)$u) && hash_equals(ADMIN_PASS, (string)$p);
}
function require_admin(): void {
    if (!admin_ok()) {
        header('WWW-Authenticate: Basic realm="@buivandung2012 Admin", charset="UTF-8"');
        out(['ok'=>false,'error'=>'Unauthorized'], 401);
    }
}
function banned(string $userId): bool {
    if ($userId === '') return false;
    $bans = read_json_file('bans', []);
    foreach ($bans as $b) {
        if (($b['userId'] ?? '') === $userId && !empty($b['active'])) return true;
    }
    return false;
}

function user_accounts(): array {
    return read_json_file('accounts', []);
}
function get_account(string $userId): array {
    $accounts = user_accounts();
    if (!isset($accounts[$userId]) || !is_array($accounts[$userId])) {
        $accounts[$userId] = ['userId'=>$userId,'coins'=>0,'updatedAt'=>gmdate('c')];
    }
    $accounts[$userId]['userId'] = $userId;
    $accounts[$userId]['coins'] = max(0, (int)($accounts[$userId]['coins'] ?? 0));
    return $accounts[$userId];
}
function save_account(array $account): bool {
    $userId = trim((string)($account['userId'] ?? ''));
    if ($userId === '') return false;
    $accounts = user_accounts();
    $account['coins'] = max(0, (int)($account['coins'] ?? 0));
    $account['updatedAt'] = gmdate('c');
    $accounts[$userId] = $account;
    return write_json_file('accounts', $accounts);
}

$action = $_GET['action'] ?? '';

try {
    if ($action === 'auth_register' || $action === 'auth_login') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'],405);
        $b=request_body();
        $email=strtolower(trim((string)($b['email']??'')));
        $password=(string)($b['password']??'');
        $name=trim((string)($b['name']??''));
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || strlen($password)<6 || strlen($password)>200) {
            out(['ok'=>false,'error'=>'Vui lòng nhập email hợp lệ và mật khẩu từ 6 ký tự.'],400);
        }
        $accounts=user_accounts();
        if ($action === 'auth_register') {
            foreach ($accounts as $existing) {
                if (strtolower((string)($existing['email']??'')) === $email) out(['ok'=>false,'error'=>'Email này đã có tài khoản. Hãy đăng nhập.'],409);
            }
            do { $id='TK-'.random_int(1000,9999).'-VN'; } while (isset($accounts[$id]));
            $account=['userId'=>$id,'email'=>$email,'name'=>($name!==''?substr($name,0,80):explode('@',$email)[0]),'passwordHash'=>password_hash($password,PASSWORD_DEFAULT),'coins'=>0,'createdAt'=>gmdate('c'),'updatedAt'=>gmdate('c')];
            $accounts[$id]=$account;
            if (!write_json_file('accounts',$accounts)) out(['ok'=>false,'error'=>'Không lưu được tài khoản trên máy chủ.'],500);
        } else {
            $account=null;
            foreach ($accounts as $existing) {
                if (strtolower((string)($existing['email']??'')) === $email) { $account=$existing; break; }
            }
            if (!$account || empty($account['passwordHash']) || !password_verify($password,(string)$account['passwordHash'])) out(['ok'=>false,'error'=>'Email hoặc mật khẩu chưa đúng.'],401);
            $id=(string)$account['userId'];
        }
        out(['ok'=>true,'user'=>['email'=>$email,'name'=>(string)($account['name']??''),'userId'=>(string)$account['userId'],'coins'=>max(0,(int)($account['coins']??0))]]);
    }
    if ($action === 'check_ban') {

        $id = trim((string)($_GET['userId'] ?? ''));
        out(['ok'=>true, 'banned'=>banned($id)]);
    }

    if ($action === 'account') {
        $id = trim((string)($_GET['userId'] ?? ''));
        if ($id === '' || strlen($id) > 64) out(['ok'=>false,'error'=>'invalid_user_id'], 400);
        $a = get_account($id);
        save_account($a);
        out(['ok'=>true,'userId'=>$id,'coins'=>(int)$a['coins']]);
    }

    if ($action === 'topup') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'],405);
        $b=request_body(); $id=trim((string)($b['userId']??''));
        $amount=(int)($b['amount']??0); $coins=(int)($b['coins']??0);
        $packages=[10000=>1,25000=>10,80000=>3667];
        if($id===''||!isset($packages[$amount])||$packages[$amount]!==$coins) out(['ok'=>false,'error'=>'invalid_topup_package'],400);
        $all=read_json_file('topups',[]);
        $all[]=['id'=>'TOP-'.date('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6)),'userId'=>$id,'package'=>$amount.' VND','amount'=>$amount,'coins'=>$coins,'status'=>'pending','createdAt'=>gmdate('c')];
        if(!write_json_file('topups',array_slice($all,-5000))) out(['ok'=>false,'error'=>'cannot_save'],500);
        get_account($id); out(['ok'=>true]);
    }
    if ($action === 'topup_history') {
        $id=trim((string)($_GET['userId']??'')); if($id==='') out(['ok'=>false,'error'=>'invalid_user'],400); $rows=array_values(array_filter(read_json_file('topups',[]),fn($x)=>(string)($x['userId']??'')===$id)); out(['ok'=>true,'rows'=>array_reverse($rows)]);
    }
    if ($action === 'admin_topups') {
        require_admin(); $rows=read_json_file('topups',[]); out(['ok'=>true,'rows'=>array_reverse($rows)]);
    }
    if ($action === 'approve_topup' || $action === 'reject_topup') {
        require_admin(); if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'],405); $b=request_body();$id=trim((string)($b['id']??''));$rows=read_json_file('topups',[]);$done=false;
        foreach($rows as &$r){if(($r['id']??'')===$id && ($r['status']??'')==='pending'){ $r['status']=$action==='approve_topup'?'approved':'rejected';$r['updatedAt']=gmdate('c');$done=true;if($action==='approve_topup'){ $a=get_account((string)$r['userId']);$a['coins']=(int)$a['coins']+(int)$r['coins'];if(!save_account($a))out(['ok'=>false,'error'=>'cannot_save_account'],500); } break;}} unset($r); if(!$done)out(['ok'=>false,'error'=>'not_found_or_processed'],404);if(!write_json_file('topups',$rows))out(['ok'=>false,'error'=>'cannot_save'],500);out(['ok'=>true]);
    }

    if ($action === 'submit') {

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'], 405);
        $b = request_body();
        $userId = trim((string)($b['userId'] ?? ''));
        $tiktokId = trim((string)($b['tiktokId'] ?? ''));
        $email = trim((string)($b['email'] ?? ''));
        $note = trim((string)($b['note'] ?? ''));
        if (banned($userId)) out(['ok'=>false,'banned'=>true], 403);
        if ($userId === '' || $tiktokId === '' || $email === '' || $note === '') out(['ok'=>false,'error'=>'missing_fields'], 400);
        $cost = 1;
        $account = get_account($userId);
        if ((int)$account['coins'] < $cost) out(['ok'=>false,'error'=>'not_enough_coins','coins'=>(int)$account['coins'],'cost'=>$cost], 402);
        $account['coins'] = (int)$account['coins'] - $cost;
        if (!save_account($account)) out(['ok'=>false,'error'=>'cannot_save_account'], 500);
        $rows = read_json_file('submissions', []);
        $rows[] = [
            'id' => 'SUB-' . date('YmdHis') . '-' . strtoupper(substr(bin2hex(random_bytes(3)),0,6)),
            'userId'=>$userId, 'tiktokId'=>$tiktokId, 'email'=>$email, 'note'=>$note,
            'submittedAt'=>gmdate('c')
        ];
        if (!write_json_file('submissions', array_slice($rows, -5000))) out(['ok'=>false,'error'=>'cannot_save'], 500);
        out(['ok'=>true]);
    }

    if ($action === 'my_submissions') {
        $userId = trim((string)($_GET['userId'] ?? ''));
        if ($userId === '' || strlen($userId) > 64) out(['ok'=>false,'error'=>'invalid_user'], 400);
        $all = read_json_file('submissions', []);
        $rows = [];
        foreach ($all as $r) {
            if ((string)($r['userId'] ?? '') === $userId) {
                $rows[] = [
                    'id' => (string)($r['id'] ?? ''),
                    'tiktokId' => (string)($r['tiktokId'] ?? ''),
                    'note' => (string)($r['note'] ?? ''),
                    'submittedAt' => (string)($r['submittedAt'] ?? '')
                ];
            }
        }
        $rows = array_slice(array_reverse($rows), 0, 100);
        out(['ok'=>true, 'userId'=>$userId, 'total'=>count(array_filter($all, fn($r) => (string)($r['userId'] ?? '') === $userId)), 'rows'=>$rows]);
    }

    if ($action === 'admin_accounts') {
        require_admin();
        $accounts = user_accounts();
        $out = [];
        foreach ($accounts as $id=>$a) $out[] = ['userId'=>(string)$id,'email'=>(string)($a['email']??''),'name'=>(string)($a['name']??''),'coins'=>max(0,(int)($a['coins']??0)),'createdAt'=>(string)($a['createdAt']??''),'updatedAt'=>(string)($a['updatedAt']??'')];
        usort($out, fn($a,$b)=>strcmp($b['updatedAt'],$a['updatedAt']));
        out(['ok'=>true,'accounts'=>$out]);
    }

    if ($action === 'set_coins' || $action === 'add_coins') {
        require_admin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'], 405);
        $b=request_body(); $id=trim((string)($b['userId']??''));
        if ($id==='' || strlen($id)>64) out(['ok'=>false,'error'=>'invalid_user_id'],400);
        $a=get_account($id);
        if ($action==='set_coins') $a['coins']=max(0,(int)($b['coins']??0));
        else $a['coins']=max(0,(int)$a['coins']+(int)($b['coins']??0));
        if (!save_account($a)) out(['ok'=>false,'error'=>'cannot_save'],500);
        out(['ok'=>true,'userId'=>$id,'coins'=>(int)$a['coins']]);
    }

    if ($action === 'admin_list') {

        require_admin();
        $rows = read_json_file('submissions', []);
        $bans = read_json_file('bans', []);
        $users = [];
        foreach ($rows as $r) if (!empty($r['userId'])) $users[(string)$r['userId']] = true;
        $active = 0;
        foreach ($bans as $b) if (!empty($b['active'])) $active++;
        out(['ok'=>true,'totalSubmissions'=>count($rows),'uniqueUsers'=>count($users),'activeBans'=>$active,'rows'=>array_reverse($rows),'bans'=>array_reverse($bans)]);
    }

    if ($action === 'ban_user' || $action === 'unban_user') {
        require_admin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'], 405);
        $b = request_body();
        $userId = trim((string)($b['userId'] ?? ''));
        if ($userId === '' || strlen($userId) > 64) out(['ok'=>false,'error'=>'invalid_user_id'], 400);
        $bans = read_json_file('bans', []);
        $active = $action === 'ban_user';
        $found = false;
        foreach ($bans as &$item) {
            if (($item['userId'] ?? '') === $userId) {
                $item['active'] = $active; $item['updatedAt'] = gmdate('c'); $found = true;
            }
        }
        unset($item);
        if (!$found) $bans[] = ['userId'=>$userId,'active'=>$active,'updatedAt'=>gmdate('c')];
        if (!write_json_file('bans', $bans)) out(['ok'=>false,'error'=>'cannot_save'], 500);
        out(['ok'=>true,'active'=>$active,'userId'=>$userId]);
    }

    if ($action === 'delete_submission') {
        require_admin();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') out(['ok'=>false,'error'=>'POST required'], 405);
        $b = request_body(); $id = trim((string)($b['id'] ?? ''));
        $rows = read_json_file('submissions', []); $next=[]; $removed=false;
        foreach ($rows as $r) { if (($r['id'] ?? '') === $id) $removed=true; else $next[]=$r; }
        if (!write_json_file('submissions', $next)) out(['ok'=>false,'error'=>'cannot_save'],500);
        out(['ok'=>$removed]);
    }
    out(['ok'=>false,'error'=>'not_found'], 404);
} catch (Throwable $e) {
    out(['ok'=>false,'error'=>$e->getMessage()], 500);
}
