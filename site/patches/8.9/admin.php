<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/partner_orders_helpers.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
av_require_staff_page();
$me=av_staff_user(); if(!av_staff_can_admin()){header('Location:/dispatcher.php');exit;}
$msg='';$err='';
function av_admin_active_order_status($status){
 return !in_array((string)$status,array('done','completed','cancelled','closed'),true);
}
function av_admin_revoke_principal_tokens($principalId,$principalType){
 $tokens=av_mobile_tokens_read();
 $tokens=array_values(array_filter($tokens,function($row)use($principalId,$principalType){
  $type=(string)($row['principal_type']??'user');
  $id=(string)($row['principal_id']??$row['user_id']??'');
  return !($type===$principalType&&$id===$principalId);
 }));
 return av_mobile_tokens_write($tokens);
}
function av_admin_delete_uploaded_documents($documents){
 if(!is_array($documents))return;
 $uploadRoot=realpath(__DIR__.'/uploads');
 if($uploadRoot===false)return;
 foreach($documents as $path){
  if(!is_string($path)||$path==='')continue;
  $candidate=realpath(__DIR__.'/'.ltrim(parse_url($path,PHP_URL_PATH)??'','/'));
  if($candidate!==false&&is_file($candidate)&&strpos($candidate,$uploadRoot.DIRECTORY_SEPARATOR)===0)@unlink($candidate);
 }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!av_check_csrf($_POST['csrf']??'')){$err='Сессия устарела. Обновите страницу.';}
 else{
  $action=$_POST['action']??'';
  if($action==='assign_user_role'){
    if(!av_is_superadmin()){$err='Только главный администратор может назначать роли сотрудников.';}
    else{
      $uid=trim($_POST['user_id']??'');
      $role=trim($_POST['role']??'');
      if(!in_array($role,array('admin','dispatcher',''),true)){$err='Некорректная роль.';}
      else{
        $users=av_read_users();$found=false;
        foreach($users as $k=>$u){
          if(($u['id']??'')===$uid){
            $users[$k]['staff_role']=$role;
            $users[$k]['staff_disabled']=false;
            $users[$k]['staff_role_updated_at']=date('c');
            $found=true;break;
          }
        }
        if(!$found)$err='Пользователь не найден.';
        elseif(av_write_users($users))$msg=$role?('Роль назначена: '.($role==='admin'?'Администратор':'Диспетчер')):'Роль сотрудника снята.';
        else $err='Не удалось сохранить роль.';
      }
    }
  } elseif($action==='toggle_user_staff'){
    if(!av_is_superadmin()){$err='Только главный администратор может менять доступ сотрудников.';}
    else{
      $uid=trim($_POST['user_id']??'');$users=av_read_users();$found=false;
      foreach($users as $k=>$u)if(($u['id']??'')===$uid){$users[$k]['staff_disabled']=empty($u['staff_disabled']);$found=true;break;}
      if(!$found)$err='Пользователь не найден.';elseif(av_write_users($users))$msg='Доступ сотрудника изменён.';else$err='Ошибка сохранения.';
    }
  } elseif($action==='toggle_worker_fleet'){
    if(!av_is_superadmin()){$err='Только главный администратор может менять состав собственного парка.';}
    else{
      $workerId=trim($_POST['worker_id']??'');$workers=av_read_workers();$found=false;$enabled=false;
      foreach($workers as $k=>$worker){
        if(($worker['id']??'')===$workerId){
          $enabled=empty($worker['own_fleet']);
          $workers[$k]['own_fleet']=$enabled;
          $workers[$k]['own_fleet_updated_at']=date('c');
          $workers[$k]['own_fleet_updated_by']=$me['id']??'';
          $found=true;break;
        }
      }
      if(!$found)$err='Аккаунт водителя не найден.';
      elseif(av_write_workers($workers))$msg=$enabled?'Водитель добавлен в собственный парк.':'Водитель исключён из собственного парка.';
      else $err='Не удалось сохранить состав собственного парка.';
    }
  } elseif($action==='delete_worker'){
    $workerId=trim((string)($_POST['worker_id']??''));$workers=av_read_workers();$target=null;
    foreach($workers as $worker)if((string)($worker['id']??'')===$workerId){$target=$worker;break;}
    if(!$target)$err='Аккаунт водителя не найден.';
    else{
      $active=false;foreach(av_read_orders() as $order)if((string)($order['assigned_worker_id']??'')===$workerId&&av_admin_active_order_status($order['status']??'')){$active=true;break;}
      if(!$active)foreach(av_partner_orders() as $order)if((string)($order['assigned_worker_id']??'')===$workerId&&av_admin_active_order_status($order['status']??'')){$active=true;break;}
      if($active)$err='Нельзя удалить водителя с активной заявкой. Сначала завершите или переназначьте её.';
      else{
        $userId=(string)($target['user_id']??'');
        $nextWorkers=array_values(array_filter($workers,function($worker)use($workerId){return (string)($worker['id']??'')!==$workerId;}));
        $apps=array_values(array_filter(av_executor_apps(),function($app)use($workerId,$userId){return (string)($app['id']??'')!==$workerId&&(!$userId||(string)($app['user_id']??'')!==$userId);}));
        $users=av_read_users();if($userId)foreach($users as &$user)if((string)($user['id']??'')===$userId){unset($user['executor_role']);break;}unset($user);
        $fleet=av_fleet();foreach($fleet as &$vehicle){if($userId&&(string)($vehicle['driver_user_id']??'')===$userId)$vehicle['driver_user_id']='';if((string)($vehicle['driver_worker_id']??'')===$workerId)$vehicle['driver_worker_id']='';}unset($vehicle);
        $saved=av_write_workers($nextWorkers)&&av_write_executor_apps($apps)&&av_write_users($users)&&av_write_fleet($fleet)&&av_admin_revoke_principal_tokens($workerId,'executor');
        if(!$saved)$err='Не удалось полностью удалить аккаунт водителя.';
        else{av_admin_delete_uploaded_documents($target['documents']??array());av_audit('worker_account_deleted',$workerId,array('user_id'=>$userId));$msg='Аккаунт водителя удалён. История выполненных заявок и финансов сохранена.';}
      }
    }
  } elseif($action==='delete_client'){
    $uid=trim((string)($_POST['user_id']??''));$users=av_read_users();$target=null;
    foreach($users as $user)if((string)($user['id']??'')===$uid){$target=$user;break;}
    if(!$target)$err='Аккаунт клиента не найден.';
    elseif(!empty($target['staff_role']))$err='Сначала снимите с пользователя роль сотрудника.';
    elseif(!empty($target['executor_role'])||av_worker_by_user_id($uid))$err='Сначала удалите связанный аккаунт водителя.';
    else{
      $active=false;foreach(av_read_orders() as $order)if((string)($order['user_id']??'')===$uid&&av_admin_active_order_status($order['status']??'')){$active=true;break;}
      if($active)$err='Нельзя удалить клиента с активной заявкой. Сначала завершите или отмените её.';
      else{
        $nextUsers=array_values(array_filter($users,function($user)use($uid){return (string)($user['id']??'')!==$uid;}));
        $blocks=array_values(array_filter(av_read_blocks(),function($block)use($uid){return (string)($block['user_id']??'')!==$uid;}));
        $saved=av_write_users($nextUsers)&&av_write_blocks($blocks)&&av_admin_revoke_principal_tokens($uid,'user');
        if(!$saved)$err='Не удалось полностью удалить аккаунт клиента.';
        else{av_audit('client_account_deleted',$uid);$msg='Аккаунт клиента удалён. История завершённых заявок сохранена.';}
      }
    }
  } elseif($action==='save_telegram'){
    if(!av_is_superadmin())$err='Только главный администратор может менять Telegram.';
    else{$c=array('enabled'=>!empty($_POST['enabled']),'bot_token'=>trim($_POST['bot_token']??''),'chat_id'=>trim($_POST['chat_id']??''));av_write_telegram_config($c);$msg='Настройки Telegram сохранены.';}
  } elseif($action==='block_client'){
    $uid=trim($_POST['user_id']??'');$phone=trim($_POST['phone']??'');$reason=trim($_POST['reason']??'Конфликт / неоплата');
    $blocks=av_read_blocks();$blocks[]=array('id'=>'BL-'.date('ymdHis').'-'.substr(bin2hex(random_bytes(3)),0,6),'user_id'=>$uid,'phone'=>$phone,'reason'=>$reason,'created_at'=>date('c'),'by'=>$me['id']);av_write_blocks($blocks);
    $users=av_read_users();foreach($users as $k=>$u)if($uid&&($u['id']??'')===$uid){$users[$k]['blocked']=true;$users[$k]['block_reason']=$reason;}av_write_users($users);$msg='Клиент заблокирован.';
  } elseif($action==='unblock_client'){
    $uid=trim($_POST['user_id']??'');$phone=trim($_POST['phone']??'');
    $blocks=array_values(array_filter(av_read_blocks(),function($b)use($uid,$phone){$m1=$uid&&($b['user_id']??'')===$uid;$m2=$phone&&preg_replace('/\D+/','',$b['phone']??'')===preg_replace('/\D+/','',$phone);return !($m1||$m2);}));
    av_write_blocks($blocks);$users=av_read_users();foreach($users as $k=>$u)if($uid&&($u['id']??'')===$uid){$users[$k]['blocked']=false;$users[$k]['block_reason']='';}av_write_users($users);$msg='Блокировка снята.';
  }
 }
}
$staff=av_read_staff();$users=av_read_users();$workers=av_read_workers();$orders=av_read_orders();$blocks=av_read_blocks();
$guest=array();foreach($orders as $o){if(empty($o['user_id'])&&!empty($o['phone'])){$key=preg_replace('/\D+/','',$o['phone']);$guest[$key]=array('phone'=>$o['phone'],'name'=>$o['name']??'Гость');}}
?><!doctype html><html data-av-auth-page="1" lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script><title>AV Rescue — Управление</title>
<style>:root{--r:#ed111c;--bg:#070809;--p:#111317;--l:#30343a;--m:#aaa}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:#fff;font-family:Arial}.w{width:min(1400px,calc(100% - 30px));margin:auto}.top{height:74px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #292c30}.brand{font-size:27px;font-weight:900}.brand b{color:var(--r)}a{color:inherit;text-decoration:none}.nav{display:flex;gap:9px}.btn,button{background:#17191c;color:#fff;border:1px solid #3a3d42;border-radius:7px;padding:10px 13px;cursor:pointer}.red{background:var(--r);border-color:var(--r)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin:18px 0}.card{background:var(--p);border:1px solid var(--l);border-radius:11px;padding:18px}.row{border-top:1px solid #292c30;padding:12px 0;display:grid;grid-template-columns:1fr auto;gap:12px;align-items:center}.muted{color:var(--m);font-size:12px}.form{display:grid;grid-template-columns:1fr 1fr 1fr 150px auto;gap:8px}input,select{min-width:0;width:100%;padding:11px;background:#0b0d0f;color:#fff;border:1px solid #34373b;border-radius:7px}.ok{color:#62e594}.err{color:#ff7676}.badge{border:1px solid #444;border-radius:20px;padding:4px 8px;font-size:11px}.blocked{color:#ff6f76}.active{color:#62e594}@media(max-width:900px){.grid{grid-template-columns:1fr}.form{grid-template-columns:1fr 1fr}}@media(max-width:560px){.form{grid-template-columns:1fr}.row{grid-template-columns:1fr}.nav{flex-wrap:wrap}.top{height:auto;padding:14px 0}}</style></head>
<body><style>
@media(max-width:900px){
  .w{width:min(100% - 22px,1400px)}
  .top{height:auto;display:block;padding:16px 0}
  .brand{font-size:22px;line-height:1.2}
  .nav{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px}
  .nav a{display:flex;justify-content:center;align-items:center;min-height:44px;border:1px solid #3a3d42;border-radius:7px;padding:9px;text-align:center;background:#17191c}
  .form{grid-template-columns:1fr}.row{grid-template-columns:1fr}
  .row form{display:grid!important;grid-template-columns:1fr 1fr!important;width:100%}
  .card{padding:14px}h2{font-size:24px;line-height:1.2}
}
</style><div class="w"><div class="top"><div class="brand">AV<b>R</b> Главный администратор</div><div class="nav"><a class="btn" href="/fleet.php">Транспорт</a><a href="/dispatcher.php">Диспетчерская</a><a class="btn" href="/">Сайт</a><a class="btn" href="/staff-logout.php">Выйти</a></div></div>
<?php if($msg):?><p class="ok"><?php echo htmlspecialchars($msg);?></p><?php endif;?><?php if($err):?><p class="err"><?php echo htmlspecialchars($err);?></p><?php endif;?>
<div class="grid"><section class="card"><h2>Администраторы и диспетчеры</h2>
<p class="muted">Роль назначается уже существующему личному аккаунту. Отдельная повторная регистрация сотрудника больше не нужна.</p>
<?php foreach($users as $u): $role=$u['staff_role']??''; ?>
<div class="row"><div>
<b><?php echo htmlspecialchars($u['name']??'');?></b> · <?php echo htmlspecialchars($u['phone']??'');?><br>
<span class="muted">ID <?php echo htmlspecialchars($u['id']??'');?> · логин <?php echo htmlspecialchars($u['login']??'');?></span><br>
<?php if($role):?><span class="badge <?php echo empty($u['staff_disabled'])?'active':'blocked';?>"><?php echo $role==='admin'?'Администратор':'Диспетчер';?> · <?php echo empty($u['staff_disabled'])?'доступ включён':'доступ отключён';?></span><?php else:?><span class="muted">Обычный пользователь</span><?php endif;?>
</div>
<?php if(av_is_superadmin()):?><div>
<form method="post" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>">
<input type="hidden" name="action" value="assign_user_role">
<input type="hidden" name="user_id" value="<?php echo htmlspecialchars($u['id']??'');?>">
<select name="role" style="width:auto;min-width:150px">
<option value="" <?php echo $role===''?'selected':'';?>>Пользователь</option>
<option value="dispatcher" <?php echo $role==='dispatcher'?'selected':'';?>>Диспетчер</option>
<option value="admin" <?php echo $role==='admin'?'selected':'';?>>Администратор</option>
</select>
<button>Сохранить роль</button>
</form>
<?php if($role):?><form method="post" style="display:inline">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>">
<input type="hidden" name="action" value="toggle_user_staff">
<input type="hidden" name="user_id" value="<?php echo htmlspecialchars($u['id']??'');?>">
<button><?php echo empty($u['staff_disabled'])?'Отключить доступ':'Включить доступ';?></button>
</form><?php endif;?>
</div><?php endif;?>
</div>
<?php endforeach;?>
</section>
<section class="card"><h2>Зарегистрированные водители</h2>
<p class="muted">Здесь администратор проверяет документы, управляет составом собственного парка и при необходимости удаляет водительский аккаунт.</p>
<?php foreach($workers as $worker): $ownFleet=!empty($worker['own_fleet']); ?>
<div class="row"><div>
<b><?php echo htmlspecialchars($worker['name']??'Исполнитель');?></b> · <?php echo htmlspecialchars($worker['phone']??'');?><br>
<span class="muted">ID <?php echo htmlspecialchars($worker['id']??'');?> · <?php echo htmlspecialchars($worker['vehicle_type']??'Техника не указана');?> · <?php echo htmlspecialchars($worker['status']??'offline');?></span><br>
<span class="badge <?php echo $ownFleet?'active':'';?>"><?php echo $ownFleet?'Наш парк · повышенный приоритет':'Сторонний исполнитель · стандартный приоритет';?></span>
</div>
<div><a class="btn" href="/admin-worker.php?id=<?php echo urlencode($worker['id']??'');?>">Профиль и документы</a>
<?php if(av_is_superadmin()):?><form method="post">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>">
<input type="hidden" name="action" value="toggle_worker_fleet">
<input type="hidden" name="worker_id" value="<?php echo htmlspecialchars($worker['id']??'');?>">
<button class="<?php echo $ownFleet?'':'red';?>"><?php echo $ownFleet?'Убрать из нашего парка':'Добавить в наш парк';?></button>
</form><?php endif;?>
<form method="post" onsubmit="return confirm('Удалить аккаунт водителя <?php echo htmlspecialchars(addslashes($worker['name']??''));?>? Вход и документы будут удалены. Действие нельзя отменить.');">
<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>">
<input type="hidden" name="action" value="delete_worker">
<input type="hidden" name="worker_id" value="<?php echo htmlspecialchars($worker['id']??'');?>">
<button class="red">Удалить аккаунт водителя</button>
</form>
</div></div>
<?php endforeach;?>
<?php if(!$workers):?><p class="muted">Зарегистрированных водителей пока нет.</p><?php endif;?>
</section>
<section class="card"><h2>Клиенты</h2><p class="muted">Зарегистрированный аккаунт можно заблокировать временно или удалить окончательно. Активные заявки и история завершённых заказов защищены.</p>
<?php foreach($users as $u): $b=av_client_is_blocked($u['id']??'',$u['phone']??'');?><div class="row"><div><b><?php echo htmlspecialchars($u['name']??'');?></b> · <?php echo htmlspecialchars($u['phone']??'');?><br><span class="muted"><?php echo htmlspecialchars($u['id']??'');?> · <?php echo htmlspecialchars($u['login']??'');?> · рейтинг <?php echo number_format(av_client_rating($u['id']??'',$u['phone']??''),2,'.','');?></span><?php if($b):?><br><span class="blocked"><?php echo htmlspecialchars($b['reason']??'Заблокирован');?></span><?php endif;?></div><div><form method="post" style="display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>"><input type="hidden" name="action" value="<?php echo $b?'unblock_client':'block_client';?>"><input type="hidden" name="user_id" value="<?php echo htmlspecialchars($u['id']??'');?>"><input type="hidden" name="phone" value="<?php echo htmlspecialchars($u['phone']??'');?>"><?php if(!$b):?><input name="reason" placeholder="Причина" value="Конфликт / неоплата"><?php endif;?><button><?php echo $b?'Разблокировать':'Блокировать';?></button></form><form method="post" style="display:inline" onsubmit="return confirm('Удалить зарегистрированный аккаунт клиента <?php echo htmlspecialchars(addslashes($u['name']??''));?>? Действие нельзя отменить.');"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>"><input type="hidden" name="action" value="delete_client"><input type="hidden" name="user_id" value="<?php echo htmlspecialchars($u['id']??'');?>"><button class="red">Удалить аккаунт</button></form></div></div><?php endforeach;?>
<h3>Клиенты без регистрации</h3><?php foreach($guest as $g): $b=av_client_is_blocked('',$g['phone']);?><div class="row"><div><b><?php echo htmlspecialchars($g['name']);?></b> · <?php echo htmlspecialchars($g['phone']);?><?php if($b):?><br><span class="blocked"><?php echo htmlspecialchars($b['reason']??'Заблокирован');?></span><?php endif;?></div><form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>"><input type="hidden" name="action" value="<?php echo $b?'unblock_client':'block_client';?>"><input type="hidden" name="phone" value="<?php echo htmlspecialchars($g['phone']);?>"><?php if(!$b):?><input name="reason" placeholder="Причина" value="Конфликт / неоплата"><?php endif;?><button class="<?php echo $b?'':'red';?>"><?php echo $b?'Разблокировать':'Блокировать';?></button></form></div><?php endforeach;?>
</section><section class="card"><h2>Telegram</h2><p class="muted">Уведомления в рабочий Telegram-чат/канал AV Rescue о новых заявках и статусах.</p><?php $tg=av_read_telegram_config();?><form method="post"><input type="hidden" name="csrf" value="<?php echo htmlspecialchars(av_csrf());?>"><input type="hidden" name="action" value="save_telegram"><input name="bot_token" placeholder="Bot token" value="<?php echo htmlspecialchars($tg['bot_token']??'');?>"><input name="chat_id" placeholder="Chat ID / @channelusername" value="<?php echo htmlspecialchars($tg['chat_id']??'');?>"><label><input type="checkbox" name="enabled" value="1" <?php echo !empty($tg['enabled'])?'checked':'';?>> Включить уведомления</label><br><button class="red">Сохранить Telegram</button></form><p class="muted">Бот должен быть добавлен в канал/группу и иметь право отправлять сообщения.</p></section><section class="card"><h2>Финансы AV Rescue</h2><p class="muted">Комиссия рассчитывается после завершения заказа. Базовая ставка исполнителя — 20%, с возможностью снижения по рейтингу.</p><div id="financeBox">Загрузка…</div><script>fetch('/api/finance_summary.php').then(r=>r.json()).then(d=>{if(d.ok)financeBox.innerHTML='<b>Оборот:</b> '+d.turnover+' ₽<br><b>Комиссия AV Rescue:</b> '+d.commission+' ₽<br><b>Выплаты исполнителям:</b> '+d.worker_payout+' ₽<br><b>Завершённых операций:</b> '+d.operations})</script></section><section class="card"><h2>Тарифы</h2><p class="muted">Базовая цена, цена за км и минимальная стоимость.</p><div id="tariffEditor">Загрузка…</div><button class="red" onclick="saveTariffs()">Сохранить тарифы</button><script>
fetch('/api/get_tariffs.php').then(r=>r.json()).then(d=>{if(!d.ok)return;tariffEditor.innerHTML=Object.entries(d.tariffs).map(([name,t])=>`<div class="row" style="grid-template-columns:1.4fr .7fr .7fr .7fr"><b>${name}</b><input data-s="${name}" data-k="base" value="${t.base}" placeholder="База"><input data-s="${name}" data-k="per_km" value="${t.per_km}" placeholder="₽/км"><input data-s="${name}" data-k="minimum" value="${t.minimum}" placeholder="Минимум"></div>`).join('')});
async function saveTariffs(){let data={};document.querySelectorAll('#tariffEditor input').forEach(i=>{data[i.dataset.s]=data[i.dataset.s]||{};data[i.dataset.s][i.dataset.k]=+i.value||0});let r=await fetch('/api/save_tariffs.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)});let d=await r.json();alert(d.ok?'Тарифы сохранены':(d.error||'Ошибка'))}
</script></section><section class="card"><h2>Выплаты исполнителям</h2><div id="payoutList">Загрузка…</div><script>
async function loadPayouts(){let r=await fetch('/api/payouts_list.php',{cache:'no-store'});let d=await r.json();payoutList.innerHTML=(d.payouts||[]).slice().reverse().map(p=>`<div class="row"><div><b>${p.id}</b> · ${p.worker_name}<br><span class="muted">${p.amount} ₽ · ${p.status}<br>${p.details||''}</span></div><div>${p.status==='new'?`<button onclick="payoutAct('${p.id}','approved')">Одобрить</button><button onclick="payoutAct('${p.id}','rejected')">Отклонить</button>`:''}${p.status==='approved'?`<button class="red" onclick="payoutAct('${p.id}','paid')">Выплачено</button>`:''}</div></div>`).join('')||'Заявок пока нет'}
async function payoutAct(id,status){let r=await fetch('/api/payout_action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status})});let d=await r.json();if(!d.ok)alert(d.error||'Ошибка');loadPayouts()}loadPayouts();
</script></section></div></div>
<script>
(function(){
  const token=sessionStorage.getItem('av_tab_session');
  const hasPortalHint=document.documentElement.getAttribute('data-av-auth-page')==='1';
  async function validate(){
    try{
      const r=await fetch('/api/tab_session.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-AV-Tab-Session':token||''},
        body:'token='+encodeURIComponent(token||''),
        cache:'no-store',
        credentials:'same-origin'
      });
      const d=await r.json();
      if(d.expired){
        sessionStorage.removeItem('av_tab_session');
        if(hasPortalHint) location.replace('/login.php');
      }
    }catch(e){}
  }
  validate();
})();
</script>

</body></html>
