<?php
define('AV_DISPATCHER_KEY', '6CdoagqvKzT23miUtrPNPu1nZ0QcwF-n');
define('AV_ORDER_FILE', __DIR__ . '/data/orders.php');
date_default_timezone_set('Europe/Moscow');

function av_json_response($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function av_read_orders() {
    if (!file_exists(AV_ORDER_FILE)) return array();
    $raw = file_get_contents(AV_ORDER_FILE);
    if ($raw === false) return array();
    $prefix = "<?php exit; ?>\n";
    if (substr($raw, 0, strlen($prefix)) === $prefix) {
        $raw = substr($raw, strlen($prefix));
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : array();
}
function av_write_orders($orders) {
    $payload = "<?php exit; ?>\n" . json_encode($orders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return file_put_contents(AV_ORDER_FILE, $payload, LOCK_EX) !== false;
}
function av_require_key() {
    $key = '';
    if (isset($_GET['key'])) $key = $_GET['key'];
    elseif (isset($_POST['key'])) $key = $_POST['key'];
    elseif (isset($_SERVER['HTTP_X_AV_KEY'])) $key = $_SERVER['HTTP_X_AV_KEY'];
    if (!hash_equals(AV_DISPATCHER_KEY, (string)$key)) {
        av_json_response(array('ok'=>false,'error'=>'Доступ запрещён'),403);
    }
}


define('AV_WORKER_FILE', __DIR__ . '/data/workers.php');

function av_read_workers() {
    if (!file_exists(AV_WORKER_FILE)) return array();
    $raw = file_get_contents(AV_WORKER_FILE);
    if ($raw === false) return array();
    $prefix = "<?php exit; ?>\n";
    if (substr($raw,0,strlen($prefix)) === $prefix) $raw = substr($raw,strlen($prefix));
    $data = json_decode($raw,true);
    return is_array($data) ? $data : array();
}
function av_write_workers($workers) {
    $payload = "<?php exit; ?>\n" . json_encode($workers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return file_put_contents(AV_WORKER_FILE,$payload,LOCK_EX)!==false;
}
function av_haversine_km($lat1,$lon1,$lat2,$lon2) {
    $r = 6371.0;
    $p1 = deg2rad((float)$lat1); $p2 = deg2rad((float)$lat2);
    $dp = deg2rad((float)$lat2-(float)$lat1); $dl = deg2rad((float)$lon2-(float)$lon1);
    $a = sin($dp/2)*sin($dp/2)+cos($p1)*cos($p2)*sin($dl/2)*sin($dl/2);
    return $r * 2 * atan2(sqrt($a),sqrt(1-$a));
}


define('AV_USER_FILE', __DIR__ . '/data/users.php');
define('AV_DISPATCHER_LOGIN', 'dispatcher');
define('AV_DISPATCHER_PASSWORD_SHA256', 'a1200a34dfe3c23e3d7055669618711f91f1d5cf74806fb6c0f3bc4d8da2c623');

if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(array(
        'lifetime'=>0,
        'path'=>'/',
        'domain'=>'',
        'secure'=>$secure,
        'httponly'=>true,
        'samesite'=>'Lax'
    ));
    session_start();
}

function av_read_users() {
    if (!file_exists(AV_USER_FILE)) return array();
    $raw = file_get_contents(AV_USER_FILE);
    if ($raw === false) return array();
    $prefix = "<?php exit; ?>\n";
    if (substr($raw,0,strlen($prefix)) === $prefix) $raw = substr($raw,strlen($prefix));
    $d = json_decode($raw,true);
    return is_array($d)?$d:array();
}
function av_write_users($users) {
    $payload = "<?php exit; ?>\n".json_encode($users,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    return file_put_contents(AV_USER_FILE,$payload,LOCK_EX)!==false;
}
function av_current_user() {
    $uid = !empty($_SESSION['user_id']) ? (string)$_SESSION['user_id'] : av_mobile_user_id();
    if ($uid==='') return null;
    $users = av_read_users();
    foreach ($users as $u) if (($u['id']??'') === $uid) return $u;
    return null;
}
function av_require_user_page() {
    if (!av_current_user()) { header('Location: /portal.php?role=client'); exit; }
}
function av_dispatcher_logged_in() {
    return (!empty($_SESSION['dispatcher_auth']) && $_SESSION['dispatcher_auth']===true) || av_staff_user()!==null;
}
function av_require_dispatcher_page() {
    if (!av_dispatcher_logged_in()) { header('Location: /portal.php?role=staff'); exit; }
}
function av_require_dispatcher_api() {
    if (!av_dispatcher_logged_in()) av_json_response(array('ok'=>false,'error'=>'Требуется вход сотрудника'),401);
}

define('AV_B2B_FILE', __DIR__ . '/data/b2b.php');
function av_read_b2b(){if(!file_exists(AV_B2B_FILE))return array();$r=file_get_contents(AV_B2B_FILE);$p="<?php exit; ?>\n";if(substr($r,0,strlen($p))===$p)$r=substr($r,strlen($p));$d=json_decode($r,true);return is_array($d)?$d:array();}
function av_write_b2b($x){return file_put_contents(AV_B2B_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;}
function av_current_executor(){if(empty($_SESSION['executor_id']))return null;foreach(av_read_workers() as $w)if(($w['id']??'')===$_SESSION['executor_id'])return $w;return null;}
function av_current_b2b(){if(empty($_SESSION['b2b_id']))return null;foreach(av_read_b2b() as $b)if(($b['id']??'')===$_SESSION['b2b_id'])return $b;return null;}

define('AV_STAFF_FILE', __DIR__ . '/data/staff.php');
define('AV_BLOCK_FILE', __DIR__ . '/data/blocked_clients.php');
define('AV_SUPERADMIN_LOGIN', 'owner');
define('AV_SUPERADMIN_PASSWORD_SHA256', 'dd11518cdfdb72970ded8fe7ba51e425eda29559ab0987373ce236dd8d07fa17');

function av_read_staff() {
    if (!file_exists(AV_STAFF_FILE)) return array();
    $raw=file_get_contents(AV_STAFF_FILE); if($raw===false) return array();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array();
}
function av_write_staff($x) {
    return file_put_contents(AV_STAFF_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_read_blocks() {
    if (!file_exists(AV_BLOCK_FILE)) return array();
    $raw=file_get_contents(AV_BLOCK_FILE); if($raw===false) return array();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array();
}
function av_write_blocks($x) {
    return file_put_contents(AV_BLOCK_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_staff_user() {
    if (!empty($_SESSION['superadmin_auth'])) {
        return array('id'=>'SUPERADMIN','name'=>'Главный администратор','login'=>AV_SUPERADMIN_LOGIN,'role'=>'superadmin','source'=>'superadmin');
    }

    // New model: administrator/dispatcher is an existing personal account with an assigned staff_role.
    if (!empty($_SESSION['user_id'])) {
        foreach (av_read_users() as $u) {
            if (($u['id']??'') === $_SESSION['user_id'] && empty($u['blocked']) && !empty($u['staff_role']) && empty($u['staff_disabled'])) {
                return array(
                    'id'=>$u['id'],
                    'name'=>$u['name']??'',
                    'login'=>$u['login']??'',
                    'role'=>$u['staff_role'],
                    'source'=>'user'
                );
            }
        }
    }

    // Legacy staff records remain readable so old accounts are not broken.
    if (!empty($_SESSION['staff_id'])) {
        foreach (av_read_staff() as $s) {
            if (($s['id']??'') === $_SESSION['staff_id'] && empty($s['blocked'])) {
                $s['source']='legacy';
                return $s;
            }
        }
    }
    return null;
}
define('AV_STAFF_PRESENCE_FILE', __DIR__ . '/data/staff_presence.php');
function av_read_staff_presence(){
    if(!file_exists(AV_STAFF_PRESENCE_FILE)) return array();
    $raw=file_get_contents(AV_STAFF_PRESENCE_FILE); if($raw===false) return array();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array();
}
function av_write_staff_presence($x){
    return file_put_contents(AV_STAFF_PRESENCE_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_touch_staff_presence(){
    $u=av_staff_user(); if(!$u) return false;
    $all=av_read_staff_presence(); $id=(string)($u['id']??''); if($id==='') return false;
    $all[$id]=array('id'=>$id,'name'=>$u['name']??'','login'=>$u['login']??'','role'=>$u['role']??'','last_activity'=>time(),'updated_at'=>date('c'));
    return av_write_staff_presence($all);
}
function av_staff_presence_status($id,$window=120){
    $all=av_read_staff_presence(); $x=$all[(string)$id]??null; $ts=(int)($x['last_activity']??0);
    return array('online'=>$ts>0 && (time()-$ts)<=$window,'last_activity'=>$ts,'data'=>$x);
}

function av_is_superadmin() { $u=av_staff_user(); return $u && ($u['role']??'')==='superadmin'; }
function av_staff_can_admin() { $u=av_staff_user(); return $u && in_array(($u['role']??''),array('superadmin','admin'),true); }
function av_require_staff_page() { if(!av_staff_user()){header('Location:/portal.php?role=staff');exit;} }
function av_require_staff_api() { if(!av_staff_user()) av_json_response(array('ok'=>false,'error'=>'Требуется вход сотрудника'),401); }
function av_client_is_blocked($userId,$phone) {
    foreach(av_read_blocks() as $b) {
        if(!empty($userId) && ($b['user_id']??'')===$userId) return $b;
        if(!empty($phone) && preg_replace('/\D+/','',(string)($b['phone']??''))===preg_replace('/\D+/','',(string)$phone)) return $b;
    }
    foreach(av_read_users() as $u) if(!empty($userId) && ($u['id']??'')===$userId && !empty($u['blocked'])) return array('reason'=>$u['block_reason']??'Аккаунт заблокирован');
    return null;
}
function av_csrf() {
    if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(20));
    return $_SESSION['csrf'];
}
function av_check_csrf($token) { return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'],(string)$token); }

define('AV_RATING_FILE', __DIR__ . '/data/ratings.php');
define('AV_TELEGRAM_CONFIG_FILE', __DIR__ . '/data/telegram.php');

function av_read_ratings(){
    if(!file_exists(AV_RATING_FILE)) return array();
    $raw=file_get_contents(AV_RATING_FILE); if($raw===false) return array();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array();
}
function av_write_ratings($x){
    return file_put_contents(AV_RATING_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_read_telegram_config(){
    if(!file_exists(AV_TELEGRAM_CONFIG_FILE)) return array('enabled'=>false,'bot_token'=>'','chat_id'=>'');
    $raw=file_get_contents(AV_TELEGRAM_CONFIG_FILE); if($raw===false) return array('enabled'=>false,'bot_token'=>'','chat_id'=>'');
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array('enabled'=>false,'bot_token'=>'','chat_id'=>'');
}
function av_write_telegram_config($x){
    return file_put_contents(AV_TELEGRAM_CONFIG_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_worker_rating($workerId){
    $sum=0;$n=0;foreach(av_read_ratings() as $r) if(($r['target_type']??'')==='worker' && ($r['target_id']??'')===$workerId){$sum+=(float)$r['score'];$n++;}
    return $n?round($sum/$n,2):5.0;
}
function av_client_rating($userId,$phone){
    $sum=0;$n=0;foreach(av_read_ratings() as $r){
      if(($r['target_type']??'')!=='client') continue;
      $match = ($userId && ($r['target_id']??'')===$userId) || (!$userId && $phone && preg_replace('/\D+/','',$r['target_phone']??'')===preg_replace('/\D+/','',$phone));
      if($match){$sum+=(float)$r['score'];$n++;}
    }
    return $n?round($sum/$n,2):5.0;
}
function av_telegram_send($text){
    $c=av_read_telegram_config();
    if(empty($c['enabled'])||empty($c['bot_token'])||empty($c['chat_id'])) return false;
    $url='https://api.telegram.org/bot'.$c['bot_token'].'/sendMessage';
    $payload=http_build_query(array('chat_id'=>$c['chat_id'],'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>'true'));
    $opts=array('http'=>array('method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>$payload,'timeout'=>5));
    $ctx=stream_context_create($opts);
    return @file_get_contents($url,false,$ctx)!==false;
}

define('AV_TARIFF_FILE', __DIR__ . '/data/tariffs.php');
define('AV_FINANCE_FILE', __DIR__ . '/data/finance.php');

function av_default_tariffs(){
    return array(
      'Эвакуация'=>array('base'=>3500,'per_km'=>90,'minimum'=>3500),
      'Замена колеса'=>array('base'=>1800,'per_km'=>45,'minimum'=>1800),
      'Запуск двигателя'=>array('base'=>1500,'per_km'=>40,'minimum'=>1500),
      'Подвоз топлива'=>array('base'=>1600,'per_km'=>40,'minimum'=>1600),
      'Техпомощь на дороге'=>array('base'=>2200,'per_km'=>50,'minimum'=>2200),
      'Вскрытие автомобиля'=>array('base'=>2500,'per_km'=>45,'minimum'=>2500)
    );
}
function av_read_tariffs(){
    if(!file_exists(AV_TARIFF_FILE)) return av_default_tariffs();
    $raw=file_get_contents(AV_TARIFF_FILE); if($raw===false) return av_default_tariffs();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:av_default_tariffs();
}
function av_write_tariffs($x){
    return file_put_contents(AV_TARIFF_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_read_finance(){
    if(!file_exists(AV_FINANCE_FILE)) return array();
    $raw=file_get_contents(AV_FINANCE_FILE); if($raw===false) return array();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array();
}
function av_write_finance($x){
    return file_put_contents(AV_FINANCE_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_estimate_price($service,$distanceKm){
    $t=av_read_tariffs(); $r=$t[$service]??array('base'=>2500,'per_km'=>60,'minimum'=>2500);
    $price=(float)$r['base']+max(0,(float)$distanceKm)*(float)$r['per_km'];
    return max((float)$r['minimum'], round($price/100)*100);
}
function av_commission_rate_for_worker($worker){
    $terms=av_worker_loyalty($worker);
    return (float)$terms['commission_rate'];
}

define('AV_B2B_DOC_FILE', __DIR__ . '/data/b2b_docs.php');

function av_read_b2b_docs(){
    if(!file_exists(AV_B2B_DOC_FILE)) return array();
    $raw=file_get_contents(AV_B2B_DOC_FILE); if($raw===false) return array();
    $p="<?php exit; ?>\n"; if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true); return is_array($d)?$d:array();
}
function av_write_b2b_docs($x){
    return file_put_contents(AV_B2B_DOC_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;
}
function av_b2b_discount($b2b){
    if(!$b2b) return 0;
    return isset($b2b['discount']) ? max(0,min(30,(float)$b2b['discount'])) : 10;
}
function av_order_price_for_b2b($service,$distanceKm,$b2b){
    $price=av_estimate_price($service,$distanceKm);
    $disc=av_b2b_discount($b2b);
    return round($price*(1-$disc/100)/100)*100;
}

define('AV_PAYOUT_FILE', __DIR__ . '/data/payouts.php');
function av_read_payouts(){if(!file_exists(AV_PAYOUT_FILE))return array();$r=file_get_contents(AV_PAYOUT_FILE);$p="<?php exit; ?>\n";if(substr($r,0,strlen($p))===$p)$r=substr($r,strlen($p));$d=json_decode($r,true);return is_array($d)?$d:array();}
function av_write_payouts($x){return file_put_contents(AV_PAYOUT_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;}
function av_worker_finance_summary($workerId){$earned=0;$commission=0;$gross=0;foreach(av_read_finance() as $f){if(($f['worker_id']??'')!==$workerId)continue;$earned+=(float)($f['worker_payout']??0);$commission+=(float)($f['commission_amount']??0);$gross+=(float)($f['price']??0);}$requested=0;$paid=0;foreach(av_read_payouts() as $p){if(($p['worker_id']??'')!==$workerId)continue;if(($p['status']??'')==='paid')$paid+=(float)$p['amount'];elseif(in_array(($p['status']??''),array('new','approved'),true))$requested+=(float)$p['amount'];}return array('gross'=>round($gross,2),'commission'=>round($commission,2),'earned'=>round($earned,2),'requested'=>round($requested,2),'paid'=>round($paid,2),'available'=>round(max(0,$earned-$requested-$paid),2));}

define('AV_AUDIT_FILE',__DIR__.'/data/audit.php');
define('AV_B2B_FLEET_FILE',__DIR__.'/data/b2b_fleet.php');
define('AV_B2B_MEMBERS_FILE',__DIR__.'/data/b2b_members.php');
define('AV_PAYMENT_FILE',__DIR__.'/data/payments.php');

function av_secure_read($file){if(!file_exists($file))return array();$r=file_get_contents($file);$p="<?php exit; ?>\n";if(substr($r,0,strlen($p))===$p)$r=substr($r,strlen($p));$d=json_decode($r,true);return is_array($d)?$d:array();}
function av_secure_write($file,$x){return file_put_contents($file,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),LOCK_EX)!==false;}
function av_audit($action,$target='',$details=array()){
 $a=av_secure_read(AV_AUDIT_FILE);$s=av_staff_user();$a[]=array('id'=>'LOG-'.date('ymdHis').'-'.substr(bin2hex(random_bytes(3)),0,6),'at'=>date('c'),'actor_id'=>$s['id']??($_SESSION['user_id']??''),'actor_name'=>$s['name']??'Система','actor_role'=>$s['role']??'client','action'=>$action,'target'=>$target,'details'=>$details);av_secure_write(AV_AUDIT_FILE,$a);
}
function av_payment_status($order){
 return $order['payment_status']??'unpaid';
}
function av_client_has_debt($userId){
 foreach(av_read_orders() as $o)if(($o['user_id']??'')===$userId&&($o['status']??'')==='done'&&in_array(av_payment_status($o),array('unpaid','overdue'),true))return true;
 return false;
}
function av_order_event(&$o,$status,$label=''){
 if(!isset($o['timeline'])||!is_array($o['timeline']))$o['timeline']=array();
 $o['timeline'][]=array('status'=>$status,'label'=>$label?:$status,'at'=>date('c'));
}
function av_equipment_match($o,$w){
 $need=$o['equipment_type']??'';
 if(!$need)return true;
 $types=$w['equipment_types']??array($w['equipment_type']??'');
 if(!is_array($types))$types=array($types);
 return in_array($need,$types,true);
}
function av_route_price($service,$distanceKm,$equipment=''){
 $t=av_get_tariff($service);$base=(float)($t['base']??0);$per=(float)($t['per_km']??0);$min=(float)($t['min']??0);
 $coef=array('flatbed'=>1,'broken_platform'=>1.08,'manipulator'=>1.45,'dolly'=>1.15,'low_clearance'=>1.2);
 $c=$coef[$equipment]??1;
 return max($min,round(($base+$per*max(0,(float)$distanceKm))*$c));
}

define('AV_FLEET_FILE',__DIR__.'/data/fleet.php');
define('AV_LEDGER_FILE',__DIR__.'/data/driver_ledger.php');
define('AV_PAYOUT_ACCOUNTS_FILE',__DIR__.'/data/payout_accounts.php');
define('AV_PAYMENT_SETTINGS_FILE',__DIR__.'/data/payment_settings.php');
define('AV_GEO_SETTINGS_FILE',__DIR__.'/data/geo_settings.php');
define('AV_TELEMATICS_FILE',__DIR__.'/data/telematics.php');

function av_fleet(){return av_secure_read(AV_FLEET_FILE);}
function av_write_fleet($x){return av_secure_write(AV_FLEET_FILE,$x);}
function av_ledger(){return av_secure_read(AV_LEDGER_FILE);}
function av_write_ledger($x){return av_secure_write(AV_LEDGER_FILE,$x);}
function av_payment_settings(){
 $defaults=array('legal_name'=>'','inn'=>'','ogrnip'=>'','legal_address'=>'','bank_account'=>'','bank_name'=>'Альфа-Банк','bik'=>'','correspondent_account'=>'','support_email'=>'AVRDriver@yandex.ru','support_phone'=>'8 925 584-58-58','site_name'=>'AV Rescue','acquiring_provider'=>'alfa','acquiring_enabled'=>false,'alfa_mode'=>'test','alfa_username'=>'','alfa_password'=>'','alfa_token'=>'','payout_provider'=>'','payout_enabled'=>false,'cash_commission_enabled'=>true);
 $x=av_secure_read(AV_PAYMENT_SETTINGS_FILE);return $x?array_merge($defaults,$x):$defaults;
}
function av_geo_settings(){ $x=av_secure_read(AV_GEO_SETTINGS_FILE); return $x?:array('base_address'=>'Дмитровское шоссе, 163с3, Москва','base_lat'=>55.929,'base_lng'=>37.545,'radius_km'=>25,'districts'=>array('САО','СВАО'),'enabled'=>true); }
function av_driver_balance($workerId){$v=0;foreach(av_ledger() as $x)if(($x['worker_id']??'')===$workerId&&($x['status']??'posted')==='posted')$v+=(float)($x['amount']??0);return round($v,2);}
function av_ledger_add($workerId,$orderId,$type,$amount,$note=''){
 $l=av_ledger();$l[]=array('id'=>'TX-'.date('ymdHis').'-'.substr(bin2hex(random_bytes(3)),0,6),'worker_id'=>$workerId,'order_id'=>$orderId,'type'=>$type,'amount'=>round((float)$amount,2),'note'=>$note,'status'=>'posted','at'=>date('c'));av_write_ledger($l);
}
function av_ledger_add_once($workerId,$orderId,$type,$amount,$note=''){
 foreach(av_ledger() as $row)if((string)($row['worker_id']??'')===(string)$workerId&&(string)($row['order_id']??'')===(string)$orderId&&($row['status']??'posted')==='posted')return false;
 av_ledger_add($workerId,$orderId,$type,$amount,$note);return true;
}
function av_geo_allowed($lat,$lng,$district=''){
 $g=av_geo_settings();if(empty($g['enabled']))return true;
 if($district&&in_array(mb_strtoupper(trim($district)),array_map('mb_strtoupper',$g['districts']??array()),true))return true;
 if(!$lat||!$lng)return false;
 return av_haversine_km((float)$g['base_lat'],(float)$g['base_lng'],(float)$lat,(float)$lng)<=(float)$g['radius_km'];
}

define('AV_PLATFORM_SETTINGS_FILE',__DIR__.'/data/platform_settings.php');
define('AV_EXECUTOR_APPLICATIONS_FILE',__DIR__.'/data/executor_applications.php');

function av_platform_settings(){
 $x=av_secure_read(AV_PLATFORM_SETTINGS_FILE);
 $defaults=array(
  'service_area_enabled'=>true,
  'base_address'=>'Дмитровское шоссе, 163с3, Москва',
  'base_lat'=>55.929,
  'base_lng'=>37.545,
  'radius_km'=>25,
  'districts'=>array('САО','СВАО'),
  'own_fleet_priority'=>true,
  'external_executors_enabled'=>true,
  'default_commission_percent'=>10,
  'external_driver_payout_percent'=>90,
  'external_driver_max_payout_percent'=>93,
  'external_driver_min_commission_percent'=>7,
  'external_driver_livery_discount_percent'=>1,
  'external_driver_order_steps'=>array(30,100,200),
  'own_fleet_payout_percent'=>35,
  'bank_name'=>'Альфа-Банк',
  'acquiring_mode'=>'not_connected',
  'payout_mode'=>'not_connected'
 );
 if(!$x)return $defaults;
 $settings=array_merge($defaults,$x);
 if(!array_key_exists('external_driver_payout_percent',$x)){
  $settings['default_commission_percent']=10;$settings['external_driver_payout_percent']=90;$settings['external_driver_max_payout_percent']=93;$settings['external_driver_min_commission_percent']=7;$settings['external_driver_livery_discount_percent']=1;$settings['external_driver_order_steps']=array(30,100,200);$settings['own_fleet_payout_percent']=35;
 }
 return $settings;
}
function av_write_platform_settings($x){return av_secure_write(AV_PLATFORM_SETTINGS_FILE,$x);}
function av_executor_apps(){return av_secure_read(AV_EXECUTOR_APPLICATIONS_FILE);}
function av_write_executor_apps($x){return av_secure_write(AV_EXECUTOR_APPLICATIONS_FILE,$x);}
function av_user_by_id($id){foreach(av_read_users() as $u)if(($u['id']??'')===$id)return $u;return null;}
function av_worker_by_id($id){foreach(av_read_workers() as $w)if((string)($w['id']??'')===(string)$id)return $w;return null;}
function av_worker_by_user_id($id){foreach(av_read_workers() as $w)if(($w['user_id']??'')===$id)return $w;return null;}
function av_fleet_vehicle_by_driver($uid){foreach(av_fleet() as $v)if(($v['driver_user_id']??'')===$uid)return $v;return null;}
if(!function_exists('av_worker_is_own_fleet')){
 function av_worker_is_own_fleet($worker){return !empty($worker['own_fleet']);}
}
function av_worker_loyalty($worker){
 $own=av_worker_is_own_fleet($worker);$settings=av_platform_settings();
 if($own)$payout=(float)($settings['own_fleet_payout_percent']??35);
 else{
  $basePayout=(float)($settings['external_driver_payout_percent']??90);$minCommission=(float)($settings['external_driver_min_commission_percent']??7);$commission=100-$basePayout;
  if(!empty($worker['avr_branded']))$commission-=(float)($settings['external_driver_livery_discount_percent']??1);
  $completed=0;foreach(av_read_orders() as $order)if((string)($order['assigned_worker_id']??'')===(string)($worker['id']??'')&&in_array($order['status']??'',array('done','completed'),true)&&empty($order['b2b_id'])&&($order['source']??'')!=='max_partner')$completed++;
  $steps=$settings['external_driver_order_steps']??array(30,100,200);if(!is_array($steps))$steps=array(30,100,200);foreach($steps as $threshold)if($completed>=(int)$threshold)$commission-=1;
  $commission=max($minCommission,$commission);$payout=100-$commission;
 }
 $payout=max(0,min(100,$payout));
 return array('level'=>$own?'own_fleet':'external','name'=>$own?'Собственный парк':(!empty($worker['avr_branded'])?'Сторонний исполнитель · оклейка AV Rescue':'Сторонний исполнитель'),'payout_rate'=>$payout,'commission_rate'=>round(100-$payout,2),'completed_orders'=>$own?null:$completed,'avr_branded'=>$own?false:!empty($worker['avr_branded']));
}
function av_order_financials($o,$worker=null){
 $gross=(float)($o['final_price']??$o['estimated_price']??0);
 if(!empty($o['b2b_id'])||($o['source']??'')==='max_partner')return array('gross'=>$gross,'commission_rate'=>0,'payout_rate'=>0,'commission'=>0,'driver_net'=>0,'loyalty_level'=>'b2b','loyalty_name'=>'B2B — отдельный учёт','excluded'=>true);
 if(!$worker&&!empty($o['assigned_worker_id']))$worker=av_worker_by_id($o['assigned_worker_id']);
 $terms=av_worker_loyalty(is_array($worker)?$worker:array());$rate=(float)$terms['commission_rate'];
 $commission=round($gross*$rate/100,2);
 return array('gross'=>$gross,'commission_rate'=>$rate,'payout_rate'=>(float)$terms['payout_rate'],'commission'=>$commission,'driver_net'=>round($gross-$commission,2),'loyalty_level'=>$terms['level'],'loyalty_name'=>$terms['name'],'excluded'=>false);
}
function av_finance_record_order($order,$financials){
 if(!empty($financials['excluded']))return false;$orderId=(string)($order['id']??'');if($orderId==='')return false;
 $rows=av_read_finance();foreach($rows as $row)if((string)($row['order_id']??'')===$orderId)return false;
 $rows[]=array('id'=>'FN-'.date('ymdHis').'-'.substr(bin2hex(random_bytes(3)),0,6),'order_id'=>$orderId,'worker_id'=>$order['assigned_worker_id']??'','price'=>$financials['gross'],'commission_rate'=>$financials['commission_rate'],'payout_rate'=>$financials['payout_rate'],'commission_amount'=>$financials['commission'],'worker_payout'=>$financials['driver_net'],'loyalty_level'=>$financials['loyalty_level'],'created_at'=>date('c'));
 return av_write_finance($rows);
}


/* AV Rescue unified security layer */
define('AV_RATE_LIMIT_FILE', __DIR__ . '/data/rate_limits.php');

function av_security_headers() {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(self), camera=(self), microphone=()');
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self';");
}
av_security_headers();

function av_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : 'unknown';
}
function av_rate_limits_read() {
    if (!file_exists(AV_RATE_LIMIT_FILE)) return array();
    $raw = file_get_contents(AV_RATE_LIMIT_FILE);
    if ($raw === false) return array();
    $p = "<?php exit; ?>\n";
    if (substr($raw,0,strlen($p)) === $p) $raw = substr($raw,strlen($p));
    $d = json_decode($raw,true);
    return is_array($d)?$d:array();
}
function av_rate_limits_write($x) {
    if (!is_dir(dirname(AV_RATE_LIMIT_FILE))) @mkdir(dirname(AV_RATE_LIMIT_FILE),0755,true);
    return file_put_contents(
        AV_RATE_LIMIT_FILE,
        "<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        LOCK_EX
    ) !== false;
}
function av_login_rate_check($login='') {
    $key = hash('sha256', av_client_ip().'|'.mb_strtolower(trim((string)$login)));
    $all = av_rate_limits_read();
    $r = $all[$key] ?? array('count'=>0,'first'=>time(),'blocked_until'=>0);
    if (!empty($r['blocked_until']) && time() < (int)$r['blocked_until']) {
        return max(1,(int)ceil(((int)$r['blocked_until']-time())/60));
    }
    if (time() - (int)($r['first']??0) > 900) {
        unset($all[$key]);
        av_rate_limits_write($all);
    }
    return 0;
}
function av_login_rate_fail($login='') {
    $key = hash('sha256', av_client_ip().'|'.mb_strtolower(trim((string)$login)));
    $all = av_rate_limits_read();
    $r = $all[$key] ?? array('count'=>0,'first'=>time(),'blocked_until'=>0);
    if (time() - (int)($r['first']??0) > 900) $r=array('count'=>0,'first'=>time(),'blocked_until'=>0);
    $r['count']=(int)$r['count']+1;
    if ($r['count'] >= 7) $r['blocked_until']=time()+900;
    $all[$key]=$r; av_rate_limits_write($all);
}
function av_login_rate_success($login='') {
    $key = hash('sha256', av_client_ip().'|'.mb_strtolower(trim((string)$login)));
    $all = av_rate_limits_read();
    if (isset($all[$key])) { unset($all[$key]); av_rate_limits_write($all); }
}
function av_require_post() {
    if (($_SERVER['REQUEST_METHOD']??'GET') !== 'POST') {
        av_json_response(array('ok'=>false,'error'=>'METHOD_NOT_ALLOWED'),405);
    }
}



define('AV_MOBILE_TOKENS_FILE', __DIR__.'/data/mobile_tokens.php');

function av_mobile_tokens_read(){
    if(!file_exists(AV_MOBILE_TOKENS_FILE))return array();
    $raw=file_get_contents(AV_MOBILE_TOKENS_FILE);
    $p="<?php exit; ?>\n";
    if(substr($raw,0,strlen($p))===$p)$raw=substr($raw,strlen($p));
    $d=json_decode($raw,true);
    return is_array($d)?$d:array();
}
function av_mobile_tokens_write($x){
    if(!is_dir(dirname(AV_MOBILE_TOKENS_FILE)))@mkdir(dirname(AV_MOBILE_TOKENS_FILE),0755,true);
    return file_put_contents(AV_MOBILE_TOKENS_FILE,"<?php exit; ?>\n".json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)!==false;
}
function av_bearer_token(){
    // 1) Standard Authorization: Bearer <token>
    $h='';
    foreach(array('HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION','Authorization') as $k){
        if(!empty($_SERVER[$k])){$h=(string)$_SERVER[$k];break;}
    }
    if(!$h && function_exists('getallheaders')){
        foreach(getallheaders() as $k=>$v){
            if(strtolower((string)$k)==='authorization'){$h=(string)$v;break;}
        }
    }
    if(!$h && function_exists('apache_request_headers')){
        foreach(apache_request_headers() as $k=>$v){
            if(strtolower((string)$k)==='authorization'){$h=(string)$v;break;}
        }
    }
    if(preg_match('/^\s*Bearer\s+(.+?)\s*$/i',$h,$m))return trim($m[1]);

    // 2) Fallback custom header for hosting/proxy stacks that strip Authorization.
    // Android sends: X-AVR-Token: <token>
    $x=(string)($_SERVER['HTTP_X_AVR_TOKEN']??'');
    if(!$x && function_exists('getallheaders')){
        foreach(getallheaders() as $k=>$v){
            if(strtolower((string)$k)==='x-avr-token'){$x=(string)$v;break;}
        }
    }
    if(!$x && function_exists('apache_request_headers')){
        foreach(apache_request_headers() as $k=>$v){
            if(strtolower((string)$k)==='x-avr-token'){$x=(string)$v;break;}
        }
    }
    return trim($x);
}
function av_mobile_principal(){
    $token=av_bearer_token();
    if(!$token)return null;
    $hash=hash('sha256',$token);
    $all=av_mobile_tokens_read();
    foreach($all as $row){
        if(hash_equals((string)($row['token_hash']??''),$hash)){
            if(!empty($row['expires_at']) && strtotime($row['expires_at'])<time())return null;
            if(empty($row['principal_type']))$row['principal_type']='user';
            if(empty($row['principal_id']) && !empty($row['user_id']))$row['principal_id']=$row['user_id'];
            return $row;
        }
    }
    return null;
}
function av_mobile_user_id(){
    $p=av_mobile_principal();
    if(!$p)return '';
    if(($p['principal_type']??'user')!=='user')return '';
    return (string)($p['user_id']??$p['principal_id']??'');
}
function av_issue_mobile_token($principalId,$device='android',$principalType='user'){
    $raw=bin2hex(random_bytes(32));
    $all=av_mobile_tokens_read();
    $now=time();
    $all=array_values(array_filter($all,function($x)use($now){return empty($x['expires_at'])||strtotime($x['expires_at'])>$now;}));
    $row=array(
        'token_hash'=>hash('sha256',$raw),
        'principal_type'=>$principalType,
        'principal_id'=>$principalId,
        'device'=>$device,
        'created_at'=>date('c'),
        'expires_at'=>date('c',time()+60*60*24*30)
    );
    if($principalType==='user')$row['user_id']=$principalId;
    $all[]=$row;
    av_mobile_tokens_write($all);
    return $raw;
}
function av_revoke_mobile_token($token){
    if(!$token)return;
    $h=hash('sha256',$token);
    $all=av_mobile_tokens_read();
    $all=array_values(array_filter($all,function($x)use($h){return !hash_equals((string)($x['token_hash']??''),$h);}));
    av_mobile_tokens_write($all);
}

require_once __DIR__.'/verification.php';
