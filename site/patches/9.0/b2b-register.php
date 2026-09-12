<?php
require_once __DIR__.'/config.php';$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){$company=trim($_POST['company']??'');$inn=trim($_POST['inn']??'');$name=trim($_POST['name']??'');$phone=trim($_POST['phone']??'');$email=strtolower(trim($_POST['email']??''));$pass=$_POST['password']??'';
if(!$company||!$inn||!$name||!$phone||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<6)$msg='Заполните все поля и укажите корректную почту. Пароль — от 6 символов.';else{$bs=av_read_b2b();$exists=false;foreach($bs as $b)if(strtolower($b['email']??'')===$email)$exists=true;if($exists)$msg='Такой аккаунт уже существует.';else{$id='B2B-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$record=array_merge(array('id'=>$id,'company'=>$company,'inn'=>$inn,'contact'=>$name,'phone'=>$phone,'email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'approved'=>false,'review_status'=>'review','must_change_password'=>false,'created_at'=>date('c')),av_verification_new_fields());$bs[]=$record;if(av_write_b2b($bs)){if(!empty($record['verification_required'])){header('Location:'.av_verification_url('b2b',$record));exit;}$msg='B2B-заявка зарегистрирована. ID: '.$id.'. После проверки доступ будет активирован.';}else$msg='Не удалось создать B2B-аккаунт.';}}}
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script><title>B2B — AV Rescue</title>
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
@media(max-width:650px){.row2{grid-template-columns:1fr}.top{height:auto;padding:14px 0;align-items:flex-start}.toplinks{justify-content:flex-end}.card{margin:20px auto;padding:18px}h1{font-size:27px}}
</style>
</head><body>
<div class="wrap"><div class="top"><a class="brand" href="/">AV<b>R</b> Rescue</a><div class="toplinks"><a href="/b2b-login.php">Войти</a></div></div>
<div class="card"><h1>B2B партнёрам AV Rescue</h1><p class="muted">Регистрация корпоративного аккаунта</p><?php if($msg):?><p class="ok"><?php echo htmlspecialchars($msg);?></p><?php endif;?><form method="post"><input name="company" placeholder="Компания" required><input name="inn" placeholder="ИНН" required><input name="name" placeholder="Контактное лицо" required><input name="phone" placeholder="Телефон" required><input type="email" name="email" placeholder="Email / логин" required><input type="password" name="password" placeholder="Пароль, минимум 6 символов" required><p class="muted">Сначала подтвердите почту, затем заявка поступит администратору на модерацию.</p><button class="btn full">Создать B2B аккаунт</button></form></div></div></body></html>
