<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/dispatch_helpers.php';
$token=isset($_GET['token'])?$_GET['token']:'';
$workers=av_read_workers();$worker=null;
foreach($workers as $w){if(isset($w['token'])&&hash_equals($w['token'],(string)$token)){$worker=$w;break;}}
if(!$worker){http_response_code(403);exit('Неверная ссылка исполнителя');}
$isOwnFleet=av_worker_is_own_fleet($worker);
?><!doctype html><html data-av-auth-page="1" lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=223"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=223" defer></script><script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU"></script><title>AV Rescue — Исполнитель</title>
<style>
:root{--r:#ed111c;--bg:#08090a;--p:#111317;--l:#30343a;--g:#4ade80;--y:#fbbf24}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:#fff;font-family:Arial,sans-serif}.w{max-width:720px;margin:auto;padding:18px}.brand{font-size:28px;font-weight:900}.brand b{color:var(--r)}
.statusbar{display:flex;justify-content:space-between;align-items:center;margin:18px 0}.pill{padding:7px 10px;border-radius:20px;border:1px solid #444;font-size:12px}.online{color:var(--g)}.offline{color:#888}.reserved{color:var(--y)}.busy{color:#c084fc}
.card{background:var(--p);border:1px solid var(--l);border-radius:12px;padding:18px;margin:12px 0}.muted{color:#aaa;line-height:1.45}.line{margin-top:9px;line-height:1.45}.btn{width:100%;padding:14px;border:1px solid #444;border-radius:8px;background:#181a1d;color:#fff;font-weight:800;margin-top:8px}.red{background:var(--r);border-color:var(--r)}.green{background:#15803d;border-color:#15803d}.two{display:grid;grid-template-columns:1fr 1fr;gap:8px}.coords{font:12px monospace;color:#aaa}.offer{border-color:#7a2228;box-shadow:0 0 0 1px #7a2228 inset}.empty{text-align:center;padding:35px 10px;color:#777}
.mapHead{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:12px}.mapHead .btn{width:auto;margin:0;padding:9px 12px}.teamMap{height:380px;border:1px solid var(--l);border-radius:10px;overflow:hidden;background:#0b0d0f}.driverRow{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border-top:1px solid #2b2e32;padding:12px 0}.driverRow:first-child{border-top:0}.driverMeta{font-size:13px;color:#aaa;margin-top:4px}.ownBadge{display:inline-block;color:#c4b5fd;border:1px solid #6d4cb5;border-radius:14px;padding:3px 7px;font-size:11px;margin-left:6px}.driverEta{text-align:right;font-weight:800;color:var(--g)}
.partnerOrder{border-top:1px solid #2b2e32;padding:14px 0}.partnerOrder:first-child{border-top:0}.partnerTitle{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.partnerStatus{font-size:11px;border:1px solid #555;border-radius:14px;padding:4px 8px;color:#ddd}.partnerMine{color:var(--g);border-color:#267a46}.partnerBusy{color:var(--y);border-color:#7a6425}.routePair{margin-top:9px;line-height:1.5}.routePair b{color:#fff}.partnerActions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.partnerDistance{color:var(--g);font-weight:800;margin-top:7px}
@media(max-width:560px){.w{padding:14px}.teamMap{height:330px}.driverRow{grid-template-columns:1fr}.driverEta{text-align:left}}
</style></head><body><div class="w">
<div style="display:flex;justify-content:space-between;gap:12px;align-items:center"><div class="brand">AV<b>R</b> Исполнитель</div><div><a href="/" style="color:#fff;text-decoration:none;margin-right:10px">Сайт</a><a href="/portal.php" style="color:#fff;text-decoration:none">Кабинет</a></div></div>
<div class="statusbar"><div><b id="name"><?php echo htmlspecialchars($worker['name']); ?></b><div class="muted"><?php echo htmlspecialchars($worker['vehicle_type']); ?></div></div><span class="pill" id="state">Офлайн</span></div>
<button class="btn red" id="lineBtn">Выйти на линию</button><button class="btn" id="notifyBtn">Включить уведомления о заказах</button><div class="coords" id="coords"></div>
<?php if($isOwnFleet):?><div class="card" id="teamCard"><div class="mapHead"><div><b>Наша смена</b><div class="muted">Водители собственного парка, которые сейчас на линии</div></div><button class="btn" type="button" onclick="loadTeamMap()">Обновить</button></div><div id="teamState" class="muted">Выйдите на линию, чтобы увидеть сменщиков.</div><div id="teamMap" class="teamMap" hidden></div><div id="teamDrivers"></div></div><?php endif;?>
<?php if($isOwnFleet):?><div class="card" id="partnerCard"><div class="mapHead"><div><b>Заявки партнёра</b><div class="muted">Без стоимости — только маршрут и распределение внутри нашей смены</div></div><button class="btn" type="button" onclick="loadPartnerOrders()">Обновить</button></div><div id="partnerState" class="muted">Выйдите на линию, чтобы увидеть заявки.</div><div id="partnerOrders"></div></div><?php endif;?>
<div class="card"><b>Мой баланс</b><div id="financeBox" class="muted">Загрузка…</div><input id="payoutAmount" placeholder="Сумма выплаты" inputmode="decimal"><input id="payoutDetails" placeholder="Реквизиты / комментарий"><button class="btn" onclick="requestPayout()">Запросить выплату</button></div><div id="orders"></div>
<p class="muted">Для автоподбора разрешите геолокацию и держите страницу открытой. В фоне мобильный браузер может ограничивать обновление координат.</p>
</div><script>
const TOKEN=<?php echo json_encode($token); ?>;const OWN_FLEET=<?php echo $isOwnFleet?'true':'false'; ?>;let online=false,watch=null,lastSent=0,currentWorker=null,teamMap=null,teamMarkers=[],partnerMarkers=[];
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
function statusRu(s){return ({offline:'Офлайн',online:'На линии',reserved:'Есть новый заказ',busy:'Занят'})[s]||s}
function teamStatusRu(s){return ({online:'Свободен',reserved:'Получает заказ',busy:'На заказе'})[s]||s}
async function loadTeamMap(){
 if(!OWN_FLEET)return;
 const stateEl=document.getElementById('teamState'),mapEl=document.getElementById('teamMap'),listEl=document.getElementById('teamDrivers');
 try{
  const r=await fetch('api/worker_map.php?token='+encodeURIComponent(TOKEN),{cache:'no-store'}),d=await r.json();
  if(!d.ok){stateEl.textContent=d.error==='OWN_FLEET_ONLY'?'Карта доступна только собственному автопарку.':(d.error||'Не удалось загрузить карту');mapEl.hidden=true;listEl.innerHTML='';return}
  if(!d.online){stateEl.textContent='Выйдите на линию, чтобы увидеть сменщиков.';mapEl.hidden=true;listEl.innerHTML='';return}
  const rows=d.workers||[];stateEl.textContent=rows.length?'Позиции обновляются автоматически.':'Сейчас на линии нет машин с актуальной геопозицией.';
  listEl.innerHTML=rows.map(w=>`<div class="driverRow"><div><b>${esc(w.name)}${w.is_self?' · вы':''}</b><span class="ownBadge">Наш парк</span><div class="driverMeta">${esc(w.vehicle_type)}${w.plate?' · '+esc(w.plate):''} · ${esc(teamStatusRu(w.status))}</div>${w.phone?`<div class="driverMeta"><a style="color:#ff747b" href="tel:${esc(w.phone)}">${esc(w.phone)}</a></div>`:''}</div><div class="driverEta">${w.is_self?'Ваша позиция':(w.distance_km===null?'':w.distance_km+' км · ≈ '+w.eta_min+' мин')}</div></div>`).join('');
  if(typeof ymaps==='undefined'||!rows.length){mapEl.hidden=true;return}
  mapEl.hidden=false;
  const self=rows.find(w=>w.is_self),center=self?[self.lat,self.lng]:[rows[0].lat,rows[0].lng];
  if(!teamMap)teamMap=new ymaps.Map(mapEl,{center,zoom:12,controls:['zoomControl','geolocationControl']});
  teamMarkers.forEach(m=>teamMap.geoObjects.remove(m));teamMarkers=[];
  rows.forEach(w=>{const preset=w.is_self?'islands#redIcon':w.status==='busy'?'islands#yellowIcon':w.status==='reserved'?'islands#blueIcon':'islands#greenIcon';const info='<b>'+esc(w.name)+(w.is_self?' · вы':'')+'</b><br>'+esc(w.vehicle_type)+(w.plate?' · '+esc(w.plate):'')+'<br>'+esc(teamStatusRu(w.status))+(w.distance_km===null||w.is_self?'':'<br>'+w.distance_km+' км · около '+w.eta_min+' мин');const marker=new ymaps.Placemark([w.lat,w.lng],{balloonContent:info},{preset});teamMap.geoObjects.add(marker);teamMarkers.push(marker)});
  if(rows.length>1)teamMap.setBounds(teamMap.geoObjects.getBounds(),{checkZoomRange:true,zoomMargin:38});else teamMap.setCenter(center,13);
  setTimeout(()=>teamMap.container.fitToViewport(),50);
 }catch(e){stateEl.textContent='Нет связи с картой. Повторите обновление.'}
}
async function resolvePartnerCoords(order){
 if(order.pickup_lat!==null&&order.pickup_lng!==null)return [Number(order.pickup_lat),Number(order.pickup_lng)];
 if(typeof ymaps==='undefined'||!order.pickup_address)return null;
 try{const result=await ymaps.geocode(order.pickup_address,{results:1});const first=result.geoObjects.get(0);return first?first.geometry.getCoordinates():null}catch(e){return null}
}
async function drawPartnerMarkers(rows){
 if(typeof ymaps==='undefined')return;
 partnerMarkers.forEach(m=>teamMap&&teamMap.geoObjects.remove(m));partnerMarkers=[];
 for(const order of rows){
  const coords=await resolvePartnerCoords(order);if(!coords)continue;
  if(!teamMap){const mapElement=document.getElementById('teamMap');mapElement.hidden=false;teamMap=new ymaps.Map(mapElement,{center:coords,zoom:12,controls:['zoomControl','geolocationControl']})}
  const status=order.is_mine?'В работе у вас':order.status==='claimed'?'Взял '+esc(order.assigned_worker_name):'Свободна';
  const destination=order.destination||((order.destination_lat!==null&&order.destination_lng!==null)?order.destination_lat+', '+order.destination_lng:'Не указано');
  const content='<b>'+esc(order.service)+'</b><br>Подача: '+esc(order.pickup_address||coords.join(', '))+'<br>Куда: '+esc(destination)+(order.service_until?'<br>Сервис работает: '+esc(order.service_until):'')+'<br>'+status;
  const preset=order.is_mine?'islands#greenIcon':order.status==='claimed'?'islands#yellowIcon':'islands#redIcon';
  const marker=new ymaps.Placemark(coords,{balloonContent:content},{preset});teamMap.geoObjects.add(marker);partnerMarkers.push(marker);
 }
 if(teamMap&&teamMap.geoObjects.getLength()>1){const bounds=teamMap.geoObjects.getBounds();if(bounds)teamMap.setBounds(bounds,{checkZoomRange:true,zoomMargin:38})}
}
function partnerStatus(order){if(order.is_mine)return 'У вас в работе';if(order.status==='claimed')return 'Взял '+(order.assigned_worker_name||'водитель');return 'Свободна'}
async function loadPartnerOrders(){
 if(!OWN_FLEET)return;
 const stateEl=document.getElementById('partnerState'),listEl=document.getElementById('partnerOrders');
 try{
  const r=await fetch('api/partner_orders.php?token='+encodeURIComponent(TOKEN),{cache:'no-store'}),d=await r.json();
  if(!d.ok){stateEl.textContent=d.error==='OWN_FLEET_ONLY'?'Доступно только нашему парку.':(d.error||'Не удалось загрузить заявки');listEl.innerHTML='';return}
  if(!d.online){stateEl.textContent='Выйдите на линию, чтобы увидеть заявки.';listEl.innerHTML='';await drawPartnerMarkers([]);return}
  const rows=d.orders||[];stateEl.textContent=rows.length?'Актуальные заявки из рабочего чата MAX.':'Свободных партнёрских заявок сейчас нет.';
  listEl.innerHTML=rows.map(o=>{const destination=o.destination||((o.destination_lat!==null&&o.destination_lng!==null)?o.destination_lat+', '+o.destination_lng:'Не указано');const badge=o.is_mine?'partnerMine':o.status==='claimed'?'partnerBusy':'';const action=o.is_mine?`<button class="btn" onclick="partnerAct('${esc(o.id)}','release')">Вернуть в список</button>`:o.status==='available'?`<button class="btn green" onclick="partnerAct('${esc(o.id)}','claim')">Взять в работу</button>`:'';const mapLink=o.pickup_lat!==null&&o.pickup_lng!==null?`<a class="btn" style="display:block;text-align:center;text-decoration:none" target="_blank" href="https://yandex.ru/maps/?ll=${o.pickup_lng}%2C${o.pickup_lat}&z=16&pt=${o.pickup_lng}%2C${o.pickup_lat}%2Cpm2rdm">Маршрут к подаче</a>`:'';return `<div class="partnerOrder"><div class="partnerTitle"><div><b>${esc(o.service)}</b>${o.vehicle?' · '+esc(o.vehicle):''}${o.license_plate?' · '+esc(o.license_plate):''}</div><span class="partnerStatus ${badge}">${esc(partnerStatus(o))}</span></div><div class="routePair"><b>Откуда:</b> ${esc(o.pickup_address||'Координаты на карте')}<br><b>Куда:</b> ${esc(destination)}${o.service_until?'<br><b>Время работы:</b> '+esc(o.service_until):''}</div>${o.distance_km!==null?`<div class="partnerDistance">${o.distance_km} км · примерно ${o.eta_min} мин</div>`:''}${o.comment?`<div class="line muted">${esc(o.comment)}</div>`:''}<div class="partnerActions">${mapLink}${action}</div></div>`}).join('');
  await drawPartnerMarkers(rows);
 }catch(e){stateEl.textContent='Нет связи с заявками. Повторите обновление.'}
}
async function partnerAct(id,action){const r=await fetch('api/partner_order_action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,order_id:id,action})}),d=await r.json();if(!d.ok){alert(d.error==='ALREADY_CLAIMED'?'Заявку уже взял '+(d.worker||'другой водитель'):(d.error||'Не удалось изменить заявку'));return}loadPartnerOrders()}
async function sendLoc(lat,lng,status){await fetch('api/worker_location.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,lat,lng,status})})}
async function start(){
 if(!navigator.geolocation){alert('Геолокация не поддерживается');return}
 online=true;lineBtn.textContent='Уйти с линии';lineBtn.classList.remove('red');
 if(window.AVRPWA) window.AVRPWA.keepAwake(true);
 watch=navigator.geolocation.watchPosition(async p=>{coords.textContent=p.coords.latitude.toFixed(5)+', '+p.coords.longitude.toFixed(5);let n=Date.now();if(n-lastSent>15000){lastSent=n;await sendLoc(p.coords.latitude,p.coords.longitude,'online');load();loadTeamMap();loadPartnerOrders()}},()=>alert('Разрешите доступ к геолокации'),{enableHighAccuracy:true,maximumAge:10000,timeout:15000});
}
async function stop(){online=false;if(watch!==null)navigator.geolocation.clearWatch(watch);watch=null;if(window.AVRPWA)window.AVRPWA.keepAwake(false);await sendLoc('','','offline');lineBtn.textContent='Выйти на линию';lineBtn.classList.add('red');load();loadTeamMap()}
lineBtn.onclick=()=>online?stop():start();
notifyBtn.onclick=async()=>{try{if(!window.AVRPWA)throw new Error('Обновите страницу');await window.AVRPWA.enablePush(TOKEN);notifyBtn.textContent='Уведомления включены';notifyBtn.disabled=true;window.AVRPWA.toast('Уведомления о заказах включены')}catch(e){alert(e.message)}};
window.addEventListener('load',()=>{if(window.AVRPWA&&window.AVRPWA.pushEnabled()){notifyBtn.textContent='Уведомления включены';notifyBtn.disabled=true}});

async function load(){
 let r=await fetch('api/worker_orders.php?token='+encodeURIComponent(TOKEN),{cache:'no-store'});let d=await r.json();if(!d.ok)return;
 currentWorker=d.worker;state.textContent=statusRu(d.worker.status);state.className='pill '+d.worker.status;
 orders.innerHTML=d.orders.length?d.orders.map(o=>{
   const offered=o.status==='offered';
   let buttons=offered?`<div class="two"><button class="btn green" onclick="act('${esc(o.id)}','accept')">Принять</button><button class="btn" onclick="act('${esc(o.id)}','reject')">Отказаться</button></div>`:
   `<button class="btn red" onclick="act('${esc(o.id)}','enroute')">В пути</button><button class="btn" onclick="act('${esc(o.id)}','arrived')">На месте</button><button class="btn green" onclick="act('${esc(o.id)}','done')">Завершить</button>`;
   return `<div class="card ${offered?'offer':''}"><b>${esc(o.id)}</b><div class="line"><b>${esc(o.service)}</b> · ${esc(o.vehicle_type)}</div><div class="line">${esc(o.address)}</div><div class="line">Клиент: <a style="color:#ff6b72" href="tel:${esc(o.phone)}">${esc(o.name)} · ${esc(o.phone)}</a></div>${o.comment?`<div class="line muted">${esc(o.comment)}</div>`:''}<a class="btn" style="display:block;text-align:center;text-decoration:none" target="_blank" href="https://yandex.ru/maps/?ll=${o.lng}%2C${o.lat}&z=16&pt=${o.lng}%2C${o.lat}%2Cpm2rdm">Открыть маршрут</a>${buttons}</div>`;
 }).join(''):'<div class="empty">Активных заказов нет</div>';
}
async function act(id,action){let r=await fetch('api/worker_action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,order_id:id,action})});let d=await r.json();if(!d.ok)alert(d.error);load()}
async function loadFinance(){let r=await fetch('api/worker_finance.php?token='+encodeURIComponent(TOKEN),{cache:'no-store'});let d=await r.json();if(!d.ok)return;let x=d.summary;financeBox.innerHTML='Доступно к выплате: <b>'+x.available+' ₽</b><br>На проверке 48 часов: '+(x.pending||0)+' ₽ · Текущий баланс: '+(x.current_balance||0)+' ₽<br>Заработано: '+x.earned+' ₽ · Комиссия: '+x.commission+' ₽ · Выплачено: '+x.paid+' ₽<br><span class="small">Выплаты формируются по вторникам и пятницам после проверки.</span>';}
async function requestPayout(){let r=await fetch('api/request_payout.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token:TOKEN,amount:+payoutAmount.value,details:payoutDetails.value})});let d=await r.json();alert(d.ok?'Заявка '+d.payout_id+' создана':(d.error||'Ошибка'));if(d.ok){payoutAmount.value='';payoutDetails.value='';loadFinance()}}
load();loadFinance();if(OWN_FLEET){if(typeof ymaps!=='undefined')ymaps.ready(()=>{loadTeamMap();loadPartnerOrders()});else{loadTeamMap();loadPartnerOrders()}setInterval(loadTeamMap,15000);setInterval(loadPartnerOrders,10000)}setInterval(load,7000);setInterval(loadFinance,15000);
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

<script>
async function loadLedger(){try{let r=await fetch('/api/driver_ledger.php?token='+encodeURIComponent(TOKEN),{cache:'no-store'}),d=await r.json();if(d.ok&&document.getElementById('financeBox')&&d.debt>0)financeBox.innerHTML+=' <br><b style="color:#ff7676">Задолженность перед AV Rescue: '+d.debt+' ₽</b>'}catch(e){}}loadLedger();setInterval(loadLedger,15000);
</script></body></html>
