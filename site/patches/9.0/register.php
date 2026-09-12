<?php
require_once __DIR__.'/config.php'; $msg='';
$type=trim((string)($_GET['type']??$_POST['type']??''));
if(!in_array($type,array('','client'),true))$type='';
if($_SERVER['REQUEST_METHOD']==='POST'&&$type==='client'){
 $name=trim((string)($_POST['name']??''));$phone=trim((string)($_POST['phone']??''));$email=trim((string)($_POST['email']??''));$login=trim((string)($_POST['login']??''));$pass=(string)($_POST['password']??'');
 if(!$name||!$phone||!filter_var($email,FILTER_VALIDATE_EMAIL)||!$login||strlen($pass)<6){$msg='Заполните имя, корректные телефон и почту, логин и пароль минимум из 6 символов.';}
 else{$users=av_read_users();$exists=false;foreach($users as $u)if(strtolower($u['login']??'')===strtolower($login))$exists=true;
  if($exists)$msg='Такой логин уже занят.'; else{$id='AVR-U-'.date('ymd').'-'.str_pad((string)(count($users)+1),5,'0',STR_PAD_LEFT);$record=array_merge(array('id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>strtolower($email),'login'=>$login,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'created_at'=>date('c'),'blocked'=>false),av_verification_new_fields());$users[]=$record;if(av_write_users($users)){if(!empty($record['verification_required'])){header('Location:'.av_verification_url('user',$record));exit;}$_SESSION['user_id']=$id;header('Location:/session-start.php?next='.urlencode('/account.php'));exit;}else $msg='Не удалось создать аккаунт.';}
 }
}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script><title>Регистрация — AV Rescue</title>
<style>
:root{--red:#ed111c;--bg:#070809;--panel:#111317;--panel2:#0b0d0f;--line:#30343a;--muted:#aeb0b4;--green:#4ade80}
*{box-sizing:border-box}html,body{max-width:100%;overflow-x:hidden}
body{margin:0;background:var(--bg);color:#fff;font-family:Arial,Helvetica,sans-serif}
a{color:inherit;text-decoration:none}.wrap{width:min(980px,calc(100% - 28px));margin:auto}
.top{height:76px;display:flex;align-items:center;justify-content:space-between;gap:18px;border-bottom:1px solid #24272b}
.brand{font-size:28px;font-weight:900;letter-spacing:-1px}.brand b{color:var(--red)}
.toplinks{display:flex;gap:10px;align-items:center;flex-wrap:wrap}.toplinks a{padding:9px 12px;border:1px solid #34373b;border-radius:7px;font-size:13px}
.card{width:min(650px,100%);margin:38px auto;background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:26px}
h1,h2,h3{font-family:Arial,Helvetica,sans-serif;margin-top:0}h1{font-size:32px;line-height:1.12}p{line-height:1.5}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
input,select,textarea{display:block;width:100%;max-width:100%;min-width:0;background:#0b0d0f;border:1px solid #34373b;border-radius:7px;color:#fff;padding:13px;margin-top:10px;font:inherit}
button,.btn{display:inline-flex;align-items:center;justify-content:center;padding:13px 18px;border-radius:7px;background:var(--red);border:1px solid var(--red);color:#fff;font-weight:800;cursor:pointer;font:inherit}
.btn.secondary{background:#111317;border-color:#4b4f55}.btn.full{width:100%;margin-top:14px}.muted{color:var(--muted)}.err{color:#ff7676}.ok{color:#62e594}
.idbox{padding:16px;border:1px solid #3a3d42;border-radius:8px;background:#0a0c0e;font-size:20px;font-weight:800;margin:14px 0}
.order{border:1px solid #30343a;border-radius:8px;padding:14px;background:#0a0c0e;margin:9px 0}.small{font-size:12px;color:#aaa}
.types{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:18px 0}.type{display:flex;min-height:88px;flex-direction:column;justify-content:center;align-items:center;text-align:center;padding:14px;border:1px solid #3a3d42;border-radius:9px;background:#0b0d0f;font-weight:800}.type span{display:block;margin-top:5px;color:var(--muted);font-size:12px;font-weight:400}.type.active{border-color:var(--red);box-shadow:inset 0 0 0 1px var(--red)}
@media(max-width:650px){.row2{grid-template-columns:1fr}.top{height:auto;padding:14px 0;align-items:flex-start}.toplinks{justify-content:flex-end}.card{margin:20px auto;padding:18px}h1{font-size:27px}}
</style>
</head><body>
<div class="wrap"><div class="top"><a class="brand" href="/">AV<b>R</b> Rescue</a><div class="toplinks"><a href="/login.php">Войти</a></div></div>
<div class="card"><h1>Регистрация в AV Rescue</h1><p class="muted">Выберите, какой аккаунт вы хотите создать.</p>
<div class="types"><a class="type <?php echo $type==='client'?'active':'';?>" href="/register.php?type=client">Клиент<span>Вызов помощи и история заказов</span></a><a class="type" href="/b2b-register.php">B2B-партнёр<span>Корпоративный кабинет</span></a><a class="type" href="/executor-register.php">Исполнитель<span>Работа с заявками</span></a></div>
<?php if($type==='client'):?><h2>Регистрация клиента</h2><?php if($msg):?><p class="err"><?php echo htmlspecialchars($msg);?></p><?php endif;?>
<form method="post"><input type="hidden" name="type" value="client"><div class="row2"><input name="name" placeholder="Ваше имя" required><input name="phone" placeholder="Телефон" required></div><input type="email" name="email" placeholder="Email" required><input name="login" placeholder="Логин" required><input type="password" name="password" placeholder="Пароль, минимум 6 символов" required><p class="muted">После регистрации подтвердите почту кодом из письма.</p><button class="btn full">Зарегистрироваться как клиент</button></form><?php else:?><p class="muted">После выбора откроется нужная форма регистрации.</p><?php endif;?></div></div></body></html>
