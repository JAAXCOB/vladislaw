<?php
require_once __DIR__.'/config.php';

$msg='';
// If a valid session already exists, the user should not authenticate again.
if ($_SERVER['REQUEST_METHOD']!=='POST') {
    if (!empty($_SESSION['superadmin_auth']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['executor_id']) || !empty($_SESSION['b2b_id']) || !empty($_SESSION['user_id'])) {
        header('Location:/portal.php'); exit;
    }
}

$selected=trim((string)($_GET['role']??$_POST['role']??'auto'));
$allowed=array('auto','client','executor','b2b','staff');
if(!in_array($selected,$allowed,true))$selected='auto';

function av_login_client($login,$pass){
    foreach(av_read_users() as $u){
        $matches=strtolower((string)($u['login']??''))===strtolower($login) || (!empty($u['email']) && strtolower($u['email'])===strtolower($login));
        if($matches && password_verify($pass,$u['password_hash']??'')){
            if(!empty($u['blocked'])) return array('error'=>'Аккаунт клиента заблокирован. Обратитесь в поддержку AV Rescue.');
            if(!av_verification_complete($u)) return array('verify'=>av_verification_url('user',$u));
            $_SESSION['user_id']=$u['id']; return array('url'=>'/account.php');
        }
    }
    return null;
}
function av_login_executor($login,$pass){
    foreach(av_read_workers() as $w){
        $matches=strtolower((string)($w['email']??''))===strtolower($login) || (!empty($w['login']) && strtolower($w['login'])===strtolower($login));
        if($matches && !empty($w['password_hash']) && password_verify($pass,$w['password_hash'])){
            if(!av_verification_complete($w)) return array('verify'=>av_verification_url('executor',$w));
            if(empty($w['approved'])) return array('error'=>'Аккаунт исполнителя ещё не подтверждён.');
            $_SESSION['executor_id']=$w['id'];
            return array('url'=>!empty($w['must_change_password'])?'/executor-change-password.php':'/executor.php');
        }
    }
    return null;
}
function av_login_b2b($login,$pass){
    foreach(av_read_b2b() as $b){
        $matches=strtolower((string)($b['email']??''))===strtolower($login) || (!empty($b['login']) && strtolower($b['login'])===strtolower($login));
        if($matches){
            if(!password_verify($pass,$b['password_hash']??'')) return null;
            if(!av_verification_complete($b)) return array('verify'=>av_verification_url('b2b',$b));
            if(($b['review_status']??'')==='rejected'){
                $reason=trim((string)($b['rejection_reason']??''));
                return array('error'=>'Заявка B2B отклонена.'.($reason!==''?' Причина: '.$reason:''));
            }
            if(empty($b['approved'])) return array('error'=>'B2B-аккаунт находится на модерации. Доступ откроется после одобрения администратора.');
            $_SESSION['b2b_id']=$b['id'];
            return array('url'=>!empty($b['must_change_password'])?'/b2b-change-password.php':'/b2b.php');
        }
    }
    return null;
}
function av_login_staff($login,$pass){
    if(hash_equals(AV_SUPERADMIN_LOGIN,$login) && hash_equals(AV_SUPERADMIN_PASSWORD_SHA256,hash('sha256',$pass))){
        $_SESSION['superadmin_auth']=true;
        unset($_SESSION['staff_id']);
        return array('url'=>'/admin.php');
    }

    // Preferred model: staff permissions live on the already registered personal account.
    foreach(av_read_users() as $u){
        $matches = strtolower((string)($u['login']??''))===strtolower($login)
            || (!empty($u['email']) && strtolower($u['email'])===strtolower($login));
        if($matches && password_verify($pass,$u['password_hash']??'')){
            if(!empty($u['blocked'])) return array('error'=>'Аккаунт заблокирован.');
            if(!av_verification_complete($u)) return array('verify'=>av_verification_url('user',$u));
            if(empty($u['staff_role'])) return null;
            if(!empty($u['staff_disabled'])) return array('error'=>'Доступ сотрудника отключён главным администратором.');
            $_SESSION['user_id']=$u['id'];
            unset($_SESSION['staff_id']);
            return array('url'=>(($u['staff_role']??'')==='admin'?'/admin.php':'/dispatcher.php'));
        }
    }

    // Legacy compatibility only.
    foreach(av_read_staff() as $s){
        if(empty($s['blocked']) && strtolower((string)($s['login']??''))===strtolower($login) && password_verify($pass,$s['password_hash']??'')){
            $_SESSION['staff_id']=$s['id'];
            return array('url'=>(($s['role']??'')==='admin'?'/admin.php':'/dispatcher.php'));
        }
    }
    return null;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $login=trim((string)($_POST['login']??''));
    $pass=(string)($_POST['password']??'');
    if(!$login||!$pass)$msg='Введите логин и пароль.';
    else{
        session_regenerate_id(true);
        $result=null;
        if($selected==='client')$result=av_login_client($login,$pass);
        elseif($selected==='executor')$result=av_login_executor($login,$pass);
        elseif($selected==='b2b')$result=av_login_b2b($login,$pass);
        elseif($selected==='staff')$result=av_login_staff($login,$pass);
        else{
            // Staff first so owner/admin/dispatcher credentials always work from the top site button.
            foreach(array('av_login_staff','av_login_client','av_login_executor','av_login_b2b') as $fn){
                $result=$fn($login,$pass);
                if($result)break;
            }
        }
        if($result){
            if(!empty($result['verify'])){header('Location:'.$result['verify']);exit;}
            elseif(!empty($result['error']))$msg=$result['error'];
            else{header('Location:/session-start.php?next='.urlencode($result['url']));exit;}
        }else $msg='Логин или пароль не найдены. Проверьте данные или выберите тип аккаунта.';
    }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script><title>Войти — AV Rescue</title>
<style>
:root{--r:#ed111c;--bg:#070809;--p:#111317;--l:#30343a;--m:#aeb0b4}
*{box-sizing:border-box}html,body{max-width:100%;overflow-x:hidden}body{margin:0;background:var(--bg);color:#fff;font-family:Arial,Helvetica,sans-serif}
a{color:inherit;text-decoration:none}.w{width:min(760px,calc(100% - 28px));margin:auto}.top{height:76px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #24272b}.brand{font-size:28px;font-weight:900}.brand b{color:var(--r)}
.card{margin:38px auto;background:var(--p);border:1px solid var(--l);border-radius:12px;padding:26px;max-width:600px}
h1{font-size:32px;margin:0 0 8px}.muted{color:var(--m);line-height:1.5}.err{color:#ff7676}
input,select{display:block;width:100%;max-width:100%;min-width:0;padding:13px;margin-top:10px;background:#0b0d0f;border:1px solid #34373b;border-radius:7px;color:#fff;font:inherit}
button{width:100%;padding:14px;margin-top:14px;border:1px solid var(--r);border-radius:7px;background:var(--r);color:#fff;font-weight:800;font:inherit;cursor:pointer}
.types{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin:18px 0}.types a{padding:10px 6px;text-align:center;border:1px solid #34373b;border-radius:7px;font-size:12px}.types a.active{border-color:var(--r);box-shadow:inset 0 0 0 1px var(--r)}
.note{margin-top:18px;padding:12px;background:#0b0d0f;border:1px solid #292c30;border-radius:8px;font-size:12px;color:#aaa;line-height:1.5}
@media(max-width:600px){.card{margin:20px auto;padding:18px}.types{grid-template-columns:1fr 1fr}h1{font-size:27px}.top{height:auto;padding:14px 0}}
</style></head><body><div class="w"><div class="top"><a class="brand" href="/">AV<b>R</b> Rescue</a><a href="/">На главную</a></div>
<div class="card"><h1>Войти в AV Rescue</h1><p class="muted">Одна страница входа для клиентов, исполнителей, B2B-партнёров, диспетчеров и администраторов.</p>
<div class="types">
<a class="<?php echo $selected==='client'?'active':'';?>" href="/login.php?role=client">Клиент</a>
<a class="<?php echo $selected==='b2b'?'active':'';?>" href="/login.php?role=b2b">B2B</a>
<a class="<?php echo $selected==='executor'?'active':'';?>" href="/login.php?role=executor">Исполнитель</a>
<a class="<?php echo $selected==='staff'?'active':'';?>" href="/login.php?role=staff">Сотрудник</a>
</div>
<?php if($msg):?><p class="err"><?php echo htmlspecialchars($msg);?></p><?php endif;?>
<form method="post"><input type="hidden" name="role" value="<?php echo htmlspecialchars($selected);?>"><input name="login" placeholder="<?php echo $selected==='executor'||$selected==='b2b'?'Email / логин':'Логин / email';?>" autocomplete="username" required><input type="password" name="password" placeholder="Пароль" autocomplete="current-password" required><button>Войти</button></form>
<div class="note">Если открыть страницу обычной кнопкой «Войти» сверху сайта, система автоматически определит тип аккаунта. При совпадающих логинах можно выбрать тип аккаунта вручную выше.</div>
</div></div></body></html>
