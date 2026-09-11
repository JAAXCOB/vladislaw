<?php
require_once __DIR__.'/config.php'; av_require_user_page(); $u=av_current_user();
$orders=array_values(array_filter(av_read_orders(),function($o)use($u){return ($o['user_id']??'')===($u['id']??'');}));
$paySettings=av_payment_settings();
function av_account_status_ru($status){$map=array('new'=>'Новая заявка','searching'=>'Поиск исполнителя','offered'=>'Предложен исполнителю','assigned'=>'Исполнитель назначен','enroute'=>'Исполнитель в пути','arrived'=>'Исполнитель на месте','loading'=>'Погрузка','in_transit'=>'Автомобиль следует к месту назначения','done'=>'Завершён','completed'=>'Завершён','cancelled'=>'Отменён');return $map[$status]??'Статус уточняется';}
?><!doctype html><html data-av-auth-page="1" lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script><title>Личный кабинет — AV Rescue</title>

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
.orderActions{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.cancelOrder{background:#17191c;border-color:#555}
.btn.secondary{background:#111317;border-color:#4b4f55}.btn.full{width:100%;margin-top:14px}.muted{color:var(--muted)}.err{color:#ff7676}.ok{color:#62e594}
.idbox{padding:16px;border:1px solid #3a3d42;border-radius:8px;background:#0a0c0e;font-size:20px;font-weight:800;margin:14px 0}
.order{border:1px solid #30343a;border-radius:8px;padding:14px;background:#0a0c0e;margin:9px 0}.small{font-size:12px;color:#aaa}
.vehicleTypes{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:10px 0}.vehicleBtn{padding:6px;background:#0b0d0f;border:1px solid #34373b}.vehicleBtn.active{border-color:var(--red)}.vehicleBtn img{width:100%;height:72px;object-fit:cover;border-radius:5px}.vehicleBtn span{padding:6px;font-size:13px}#orderMap{height:300px;border-radius:8px;margin-top:10px;border:1px solid #34373b;overflow:hidden}
@media(max-width:650px){.row2{grid-template-columns:1fr}.vehicleTypes{grid-template-columns:repeat(2,1fr)}.top{height:auto;padding:14px 0;align-items:flex-start}.toplinks{justify-content:flex-end}.card{margin:20px auto;padding:18px}h1{font-size:27px}}
</style>

</head><body>
<div class="wrap"><div class="top"><a class="brand" href="/">AV<b>R</b> Rescue</a><div class="toplinks"><a href="/">Сайт</a><a href="/change-password.php">Изменить пароль</a><a href="/logout.php">Выйти</a></div></div>
<div class="card"><h1>Личный кабинет</h1><p>Здравствуйте, <?php echo htmlspecialchars($u['name']); ?></p><div class="idbox"><?php echo htmlspecialchars($u['id']); ?></div><p class="muted">Ваш персональный идентификатор AV Rescue.</p>
<p><b>Телефон:</b> <?php echo htmlspecialchars($u['phone']); ?><br><b>Логин:</b> <?php echo htmlspecialchars($u['login']); ?></p>
<?php if(!empty($u['staff_role'])):?><p><b>Роль в AV Rescue:</b> <?php echo $u['staff_role']==='admin'?'Администратор':'Диспетчер';?><?php if(!empty($u['staff_disabled'])):?> · доступ отключён<?php endif;?></p><?php endif;?>
<h2>Вызвать помощь</h2><p class="muted">Заказ будет автоматически привязан к вашему аккаунту.</p>
<form id="accountOrderForm">
<select id="service" required><option value="">Выберите услугу</option><option>Эвакуация</option><option>Техпомощь</option><option>Запуск двигателя</option><option>Замена колеса</option><option>Подвоз топлива</option><option>Вскрытие автомобиля</option></select>
<div class="vehicleTypes"><button type="button" class="vehicleBtn active" data-value="Легковой"><img src="/assets/vehicle-car-v30.jpg"><span>Легковой</span></button><button type="button" class="vehicleBtn" data-value="Внедорожник"><img src="/assets/vehicle-suv-v30.jpg"><span>Внедорожник</span></button><button type="button" class="vehicleBtn" data-value="Мотоцикл"><img src="/assets/vehicle-moto-v30.jpg"><span>Мотоцикл</span></button><button type="button" class="vehicleBtn" data-value="Коммерческий"><img src="/assets/vehicle-commercial-v30.jpg"><span>Коммерческий</span></button><button type="button" class="vehicleBtn" data-value="Грузовой"><img src="/assets/vehicle-truck-v30.jpg"><span>Грузовой</span></button><button type="button" class="vehicleBtn" data-value="Спецтехника"><img src="/assets/vehicle-special-v30.jpg"><span>Спецтехника</span></button></div><input type="hidden" id="vehicle_type" value="Легковой">
<input id="address" required placeholder="Адрес подачи">
<input id="destination" placeholder="Адрес назначения">
<div id="orderMap"></div><input type="hidden" id="lat"><input type="hidden" id="lng"><input type="hidden" id="destination_lat"><input type="hidden" id="destination_lng">
<input id="car" placeholder="Марка и модель автомобиля">
<textarea id="comment" placeholder="Комментарий"></textarea>
<select id="payment_method"><option value="cash">Наличные</option><option value="sbp">СБП</option><option value="card">Банковская карта</option></select>
<div class="row2"><input value="<?php echo htmlspecialchars($u['name']); ?>" readonly><input value="<?php echo htmlspecialchars($u['phone']); ?>" readonly></div>
<button class="btn full" type="submit">Подтвердить и вызвать помощь</button>
</form>
<h2>Мои заявки</h2><div class="orders"><?php if(!$orders): ?><p class="muted">Заявок пока нет.</p><?php else: foreach($orders as $o): $status=$o['status']??'';$active=!in_array($status,array('done','completed','cancelled'),true);$canPay=!empty($paySettings['acquiring_enabled'])&&in_array(($o['payment_method']??'cash'),array('online','card','sbp'),true)&&($o['payment_status']??'unpaid')!=='paid'&&$status!=='cancelled';?><div class="order"><b><?php echo htmlspecialchars($o['id']); ?></b><br><?php echo htmlspecialchars($o['service']); ?> · <?php echo htmlspecialchars(av_account_status_ru($status)); ?><div class="small"><?php echo htmlspecialchars($o['address']); ?></div><?php if(($o['payment_method']??'cash')!=='cash'):?><div class="small">Оплата: <?php echo htmlspecialchars(av_payment_method_label($o['payment_method']??''));?> · <?php echo htmlspecialchars(av_payment_status_label($o['payment_status']??'unpaid'));?><?php if(!empty($o['paid_amount'])):?> · <?php echo number_format((float)$o['paid_amount'],2,'.',' ');?> ₽<?php endif;?></div><?php endif;?><?php if($active):?><div class="orderActions"><button type="button" onclick="location.href='/track.php?order_id=<?php echo urlencode($o['id']);?>'">Отследить заказ / ETA</button><button type="button" class="cancelOrder" onclick="cancelOrder('<?php echo htmlspecialchars($o['id']);?>')">Отменить заказ</button><?php if($canPay):?><button type="button" onclick="payOrder('<?php echo htmlspecialchars($o['id']);?>')">Оплатить онлайн</button><?php endif;?></div><?php elseif($canPay):?><div class="orderActions"><button type="button" onclick="payOrder('<?php echo htmlspecialchars($o['id']);?>')">Оплатить онлайн</button></div><?php endif;?><?php if(in_array($status,array('done','completed'),true) && !empty($o['assigned_worker_id'])):?><div style="margin-top:9px">Оценить исполнителя: <?php for($i=1;$i<=5;$i++):?><button type="button" onclick="rateOrder('<?php echo htmlspecialchars($o['id']);?>',<?php echo $i;?>)" style="background:#17191c;color:#fff;border:1px solid #333;border-radius:5px;padding:5px 8px"><?php echo $i;?>★</button><?php endfor;?></div><?php endif;?></div><?php endforeach; endif; ?></div>
<h2>История платежей</h2><div class="orders"><?php $hasPayments=false;foreach($orders as $o):if(($o['payment_method']??'cash')==='cash'&&empty($o['payment_status']))continue;$hasPayments=true;?><div class="order"><b><?php echo htmlspecialchars($o['id']??'');?></b><br><?php echo htmlspecialchars(av_payment_method_label($o['payment_method']??'cash'));?> · <?php echo htmlspecialchars(av_payment_status_label($o['payment_status']??'unpaid'));?><div class="small">Сумма: <?php echo number_format((float)($o['paid_amount']??$o['final_price']??$o['estimated_price']??0),2,'.',' ');?> ₽<?php if(!empty($o['refund_requested_amount'])):?> · возврат <?php echo number_format((float)$o['refund_requested_amount'],2,'.',' ');?> ₽<?php endif;?></div><?php if(!empty($o['receipt_url'])):?><a class="btn secondary" href="<?php echo htmlspecialchars($o['receipt_url']);?>" target="_blank" rel="noopener">Открыть чек</a><?php endif;?></div><?php endforeach;if(!$hasPayments):?><p class="muted">Платежей пока нет.</p><?php endif;?></div>
</div></div><script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU"></script><script>
async function rateOrder(id,score){let r=await fetch('/api/rate_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:id,score,target:'worker'})});let d=await r.json();alert(d.ok?'Спасибо за оценку!':(d.error||'Ошибка'))}
async function cancelOrder(id){if(!confirm('Отменить заказ '+id+'?'))return;try{const r=await fetch('/api/client_cancel_order.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:id})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Не удалось отменить заказ');alert('Заказ отменён');location.reload()}catch(error){alert(error.message)}}
async function payOrder(id){try{const r=await fetch('/api/alfa_create_payment.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:id})});const d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Не удалось начать оплату');if(d.status==='paid'){alert('Заказ уже оплачен');location.reload();return}if(!d.payment_url)throw new Error('Банк не вернул ссылку на оплату');location.href=d.payment_url}catch(error){alert(error.message)}}
document.querySelectorAll('.vehicleBtn').forEach(b=>b.addEventListener('click',()=>{document.querySelectorAll('.vehicleBtn').forEach(x=>x.classList.remove('active'));b.classList.add('active');document.getElementById('vehicle_type').value=b.dataset.value}));
let orderMap=null,pickupMarker=null;
function initOrderMap(){orderMap=new ymaps.Map('orderMap',{center:[55.7558,37.6173],zoom:10,controls:['zoomControl','geolocationControl']},{restrictMapArea:[[41,19],[82,180]]});orderMap.events.add('click',async e=>{const c=e.get('coords');document.getElementById('lat').value=c[0].toFixed(6);document.getElementById('lng').value=c[1].toFixed(6);if(pickupMarker)pickupMarker.geometry.setCoordinates(c);else{pickupMarker=new ymaps.Placemark(c,{balloonContent:'Адрес подачи'},{preset:'islands#redIcon'});orderMap.geoObjects.add(pickupMarker)}try{const r=await ymaps.geocode(c,{results:1});const first=r.geoObjects.get(0);if(first)document.getElementById('address').value=first.getAddressLine()}catch(_){}})}
async function geocodeField(id,latId,lngId){let value=document.getElementById(id).value.trim();if(!value||typeof ymaps==='undefined')return false;try{const result=await ymaps.geocode(value,{results:1,boundedBy:[[41,19],[82,180]],strictBounds:false});const first=result.geoObjects.get(0);if(!first)return false;const point=first.geometry.getCoordinates();document.getElementById(latId).value=point[0].toFixed(6);document.getElementById(lngId).value=point[1].toFixed(6);if(id==='address'&&orderMap){if(pickupMarker)pickupMarker.geometry.setCoordinates(point);else{pickupMarker=new ymaps.Placemark(point,{balloonContent:'Адрес подачи'},{preset:'islands#redIcon'});orderMap.geoObjects.add(pickupMarker)}orderMap.setCenter(point,15)}return true}catch(_){return false}}
document.getElementById('address').addEventListener('change',()=>geocodeField('address','lat','lng'));document.getElementById('destination').addEventListener('change',()=>geocodeField('destination','destination_lat','destination_lng'));
document.getElementById('accountOrderForm').addEventListener('submit',async e=>{e.preventDefault();const v=id=>document.getElementById(id).value.trim();if(v('service')==='Эвакуация'&&!v('destination')){alert('Укажите адрес назначения');document.getElementById('destination').focus();return}const button=e.currentTarget.querySelector('button[type=submit]');button.disabled=true;button.textContent='Создаём заказ…';try{if(!v('lat')||!v('lng'))await geocodeField('address','lat','lng');if(v('destination')&&(!v('destination_lat')||!v('destination_lng')))await geocodeField('destination','destination_lat','destination_lng');const response=await fetch('/api/create_order.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({service:v('service'),vehicle_type:v('vehicle_type'),address:v('address'),destination:v('destination'),lat:v('lat'),lng:v('lng'),destination_lat:v('destination_lat'),destination_lng:v('destination_lng'),car:v('car'),comment:v('comment'),payment_method:v('payment_method'),name:<?php echo json_encode($u['name'],JSON_UNESCAPED_UNICODE);?>,phone:<?php echo json_encode($u['phone'],JSON_UNESCAPED_UNICODE);?>})});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Ошибка создания заказа');alert('Заказ '+data.order_id+' создан');location.reload()}catch(error){alert(error.message);button.disabled=false;button.textContent='Подтвердить и вызвать помощь'}});
sessionStorage.setItem('av_client_name',<?php echo json_encode($u['name'],JSON_UNESCAPED_UNICODE);?>);sessionStorage.setItem('av_client_phone',<?php echo json_encode($u['phone'],JSON_UNESCAPED_UNICODE);?>);ymaps.ready(initOrderMap);
</script>
<script>
async function trackOrder(id){
 const r=await fetch('/api/order_tracking.php?order_id='+encodeURIComponent(id),{cache:'no-store'});
 const d=await r.json(); if(!d.ok){alert(d.error||'Не удалось получить данные');return}
 const o=d.order;
 alert('Статус: '+o.status+(o.worker_name?'\nИсполнитель: '+o.worker_name:'')+(o.distance_km!==null?'\nДо вас: '+o.distance_km+' км\nОриентировочно: '+o.eta_min+' мин':''));
}

let eventSince=Math.floor(Date.now()/1000)-5;
async function pollEvents(){try{let r=await fetch('/api/client_events.php?since='+eventSince,{cache:'no-store'});let d=await r.json();if(!d.ok)return;eventSince=d.server_time||eventSince;(d.events||[]).forEach(e=>{if(['assigned','enroute','arrived','done'].includes(e.status))console.log('AV Rescue',e)})}catch(e){}}
setInterval(pollEvents,10000);
</script>
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
