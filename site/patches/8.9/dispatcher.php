<?php require_once __DIR__.'/config.php'; header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0'); header('Pragma: no-cache'); av_require_dispatcher_page(); $staffMe=av_staff_user(); ?><!doctype html>
<html data-av-auth-page="1" lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script>
<title>AV Rescue — Диспетчерская</title>
<style>
:root{--red:#ed111c;--bg:#070809;--panel:#111317;--panel2:#0c0e10;--line:#30343a;--muted:#9ea1a7;--green:#4ade80;--yellow:#fbbf24;--blue:#60a5fa}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:#fff;font-family:Arial,sans-serif}a{color:inherit;text-decoration:none}button,input,select,textarea{font:inherit}
.w{width:min(1450px,calc(100% - 30px));margin:auto}header{height:74px;border-bottom:1px solid #272a2e;background:#090a0bf2;position:sticky;top:0;z-index:1000}
.head{height:100%;display:flex;align-items:center;justify-content:space-between;gap:20px}.brand{font-size:28px;font-weight:900}.brand b{color:var(--red)}.clock{color:#aaa;font-size:13px}
.tabs{display:flex;gap:8px;padding:18px 0 8px}.tab,.f{background:#111317;color:#ccc;border:1px solid #333;border-radius:8px;padding:10px 14px;cursor:pointer}.tab.active,.f.active{border-color:var(--red);color:#fff;box-shadow:inset 0 0 0 1px var(--red)}
.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin:14px 0 18px}.sum{background:var(--panel);border:1px solid var(--line);border-radius:9px;padding:14px}.sum b{font-size:24px;display:block}.sum span{font-size:11px;color:#aaa}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;padding-bottom:30px}.card{background:var(--panel);border:1px solid var(--line);border-radius:11px;padding:17px}.top{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.id{font-weight:900;font-size:17px}
.badge{font-size:11px;border:1px solid #444;border-radius:20px;padding:5px 9px}.badge.new{color:#ff6b72;border-color:#7a2228}.badge.searching{color:var(--yellow);border-color:#6f5513}.badge.offered,.badge.assigned{color:var(--blue);border-color:#274a71}.badge.enroute{color:#c084fc;border-color:#59316d}.badge.arrived{color:#fb923c;border-color:#7c3f14}.badge.done,.badge.completed{color:var(--green);border-color:#245d37}
.badge.available{color:#6ee7a0;background:#12351f;border-color:#2f9e55}.badge.claimed{color:#ffd166;background:#3b2d0c;border-color:#c98917}.badge.closed{color:#d1d5db;background:#2b2e33;border-color:#666b73}.badge.cancelled{color:#ff8d94;background:#3a171a;border-color:#9d3037}.partnerCard{border-left-width:5px;transition:border-color .2s,background .2s}.partnerCard.available{border-left-color:#2fbe63;background:linear-gradient(90deg,#12321e 0,#111317 22%)}.partnerCard.claimed{border-left-color:#e8a51d;background:linear-gradient(90deg,#35280d 0,#111317 22%)}.partnerCard.closed{border-left-color:#737981;background:linear-gradient(90deg,#282b30 0,#111317 22%);opacity:.82}.partnerCard.cancelled{border-left-color:#a43b43;background:linear-gradient(90deg,#35171a 0,#111317 22%);opacity:.82}.partnerSummaryAvailable{border-color:#286b3e}.partnerSummaryAvailable b{color:#4ade80}.partnerSummaryClaimed{border-color:#765615}.partnerSummaryClaimed b{color:#fbbf24}.partnerSummaryClosed{border-color:#555b63}.partnerSummaryClosed b{color:#c7cbd1}
.line{font-size:13px;margin-top:10px;color:#ddd;line-height:1.4}.line b{color:#fff}.muted{color:var(--muted)}.assignedBox{margin-top:12px;border:1px solid #2d3035;border-radius:8px;padding:10px;background:#0b0d0f}
.note{width:100%;margin-top:10px;background:#0b0d0f;color:#fff;border:1px solid #333;border-radius:7px;padding:9px;min-height:54px}
.a{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:11px}.a button,.a a{background:#17191c;color:#fff;border:1px solid #393c41;border-radius:7px;padding:10px;text-align:center;cursor:pointer}.a .red{background:var(--red);border-color:var(--red)}.match{margin-top:8px;padding:10px;border:1px solid #30343a;border-radius:7px;background:#0a0c0e;font-size:12px}.match button{margin-left:8px;background:#ed111c;color:#fff;border:0;border-radius:5px;padding:6px 9px;cursor:pointer}
.mapPanel{border:1px solid var(--line);border-radius:10px;overflow:hidden;margin:0 0 30px}#fleetMap{height:480px;background:#111}.mapHead{padding:14px 16px;background:#0d0f11;border-bottom:1px solid #292c30;display:flex;justify-content:space-between;gap:15px}.legend{font-size:12px;color:#aaa}
.empty{color:#777;padding:30px 0}
.manualOrder{margin:14px 0}.manualOrder summary{cursor:pointer;font-size:18px;font-weight:900}.manualGrid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:14px}.manualGrid .wide{grid-column:span 2}.manualGrid .full{grid-column:1/-1}.manualGrid input,.manualGrid select,.manualGrid textarea{width:100%;background:#0b0d0f;color:#fff;border:1px solid #34373b;border-radius:7px;padding:11px}.manualGrid textarea{min-height:72px;resize:vertical}.priceChoice{display:flex;gap:16px;align-items:center;flex-wrap:wrap}.priceChoice label{display:flex;gap:6px;align-items:center}.manualResult{color:var(--green);font-weight:800;align-self:center}
@media(max-width:1050px){.grid{grid-template-columns:1fr 1fr}.summary{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.grid,.manualGrid{grid-template-columns:1fr}.manualGrid .wide,.manualGrid .full{grid-column:auto}.summary{grid-template-columns:1fr 1fr}#fleetMap{height:380px}}
</style></head>
<body><style>
@media(max-width:900px){
  .w{width:min(100% - 22px,1450px)}
  header{height:auto;position:relative}
  .head{height:auto;display:block;padding:16px 0}
  .head>.brand{font-size:23px;line-height:1.2}
  .head>div:last-child{display:grid!important;grid-template-columns:1fr 1fr;gap:8px!important;margin-top:13px;align-items:stretch!important}
  .head>div:last-child a{display:flex;justify-content:center;align-items:center;min-height:42px;padding:8px 10px;border:1px solid #333;border-radius:7px;background:#111317;font-size:14px!important}
  .clock{grid-column:1/-1;width:100%;font-size:13px}
  .tabs{overflow-x:auto;flex-wrap:nowrap;padding:14px 0 8px;scrollbar-width:none}.tabs::-webkit-scrollbar{display:none}.tab{flex:0 0 auto}
  .grid{grid-template-columns:1fr}.summary{grid-template-columns:1fr 1fr}#fleetMap{height:380px}
}
</style><header><div class="w head"><div class="brand">AV<b>R</b> Диспетчерская</div><div style="display:flex;gap:14px;align-items:center"><div class="clock" id="clock"></div><?php if(av_staff_can_admin()):?><a href="/admin.php" style="font-size:12px;color:#fff">Управление</a><?php endif;?><a href="/" style="font-size:12px;color:#fff">Сайт</a><a href="/staff-logout.php" style="font-size:12px;color:#ff7077">Выйти</a></div></div></header>
<main class="w">
<div class="tabs"><button class="tab active" data-tab="orders">Заказы <span id="tabOrders"></span></button><button class="tab" data-tab="partner">MAX / B2B <span id="tabPartner"></span></button><button class="tab" data-tab="map">Карта заявок</button></div>

<section id="ordersTab">
<details class="card manualOrder"><summary>Создать заявку по звонку</summary>
<div class="manualGrid">
<input id="moName" placeholder="Имя клиента *"><input id="moPhone" placeholder="Телефон *" inputmode="tel"><select id="moService"><option>Эвакуация</option><option>Техпомощь на дороге</option><option>Запуск двигателя</option><option>Замена колеса</option><option>Подвоз топлива</option><option>Вскрытие автомобиля</option></select>
<select id="moVehicle"><option>Легковой</option><option>Внедорожник</option><option>Мотоцикл</option><option>Коммерческий</option><option>Грузовой</option><option>Спецтехника</option></select><input id="moCar" placeholder="Марка, модель, госномер"><select id="moCondition"><option>На ходу</option><option>Не заводится</option><option>После ДТП</option><option>Заблокировано рулевое управление</option><option>Другое</option></select><select id="moBlocked"><option>Нет</option><option>1 колесо</option><option>2 колеса</option><option>3 колеса</option><option>4 колеса</option></select><input id="moAddress" class="wide" placeholder="Откуда / точка А *"><button type="button" onclick="manualGeocodeRoute()">Определить маршрут</button><input id="moLat" placeholder="Широта А"><input id="moLng" placeholder="Долгота А"><input id="moDistance" placeholder="Расстояние, км" inputmode="decimal"><input id="moDestination" class="wide" placeholder="Куда / точка Б"><span></span><input id="moDestinationLat" placeholder="Широта Б"><input id="moDestinationLng" placeholder="Долгота Б"><select id="moPayment"><option value="cash">Наличные</option><option value="online">Оплата картой</option></select>
<textarea id="moComment" class="wide" placeholder="Комментарий: неисправность, особенности погрузки, контакты"></textarea><textarea id="moDispatcherNote" placeholder="Внутренняя заметка диспетчера"></textarea>
<div class="priceChoice full"><b>Стоимость:</b><label><input type="radio" name="moPriceMode" value="auto" checked onchange="manualPriceMode()"> Автоматически по тарифам</label><label><input type="radio" name="moPriceMode" value="fixed" onchange="manualPriceMode()"> Фиксированная</label></div>
<input id="moFixedPrice" placeholder="Фиксированная сумма, ₽" inputmode="decimal" style="display:none"><button type="button" onclick="manualQuote()">Рассчитать стоимость</button><div id="moResult" class="manualResult"></div>
<button type="button" class="red full" id="moSubmit" onclick="manualCreateOrder()">Создать заявку без регистрации клиента</button>
</div></details>
<div class="summary">
<div class="sum"><b id="cNew">0</b><span>Новые</span></div><div class="sum"><b id="cSearch">0</b><span>Поиск</span></div><div class="sum"><b id="cAssigned">0</b><span>Назначены</span></div><div class="sum"><b id="cEnroute">0</b><span>В пути</span></div><div class="sum"><b id="cDone">0</b><span>Завершены</span></div>
</div>
<div class="filters"><button class="f active" data-f="all">Все</button><button class="f" data-f="new">Новые</button><button class="f" data-f="searching">Поиск</button><button class="f" data-f="assigned">Назначены</button><button class="f" data-f="enroute">В пути</button><button class="f" data-f="done">Завершены</button></div>
<div id="grid" class="grid"></div>
</section>

<section id="partnerTab" style="display:none">
<div class="summary"><div class="sum partnerSummaryAvailable"><b id="pAvailable">0</b><span>Свободные</span></div><div class="sum partnerSummaryClaimed"><b id="pClaimed">0</b><span>Назначены</span></div><div class="sum partnerSummaryClosed"><b id="pClosed">0</b><span>Закрыты</span></div></div>
<div class="filters"><button class="f partnerFilter active" data-pf="active">Активные</button><button class="f partnerFilter" data-pf="all">Все</button><button class="f partnerFilter" data-pf="closed">Закрытые</button></div>
<div id="partnerGrid" class="grid"></div>
</section>

<section id="mapTab" style="display:none">
<div class="mapPanel"><div class="mapHead"><b>Карта заказов и исполнителей</b><div class="legend">Красный — заказ · зелёный — свободен · жёлтый — занят</div></div><div id="fleetMap"></div></div>
</section></main>

<script src="https://api-maps.yandex.ru/2.1/?lang=ru_RU"></script><script>
let orders=[],partnerOrders=[],workers=[],flt='all',partnerFlt='active',fleetMap=null,markers=[];
const statusNames={new:'Новая',searching:'Поиск исполнителя',offered:'Предложена водителю',assigned:'Назначена',enroute:'В пути к клиенту',arrived:'На месте',loading:'Погрузка',in_transit:'В пути к месту назначения',done:'Завершена',completed:'Завершена',cancelled:'Отменена'};
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
function setClock(){document.getElementById('clock').textContent=new Date().toLocaleString('ru-RU')}setInterval(setClock,1000);setClock();
async function load(){
 try{
  const [a,b,c]=await Promise.all([fetch('api/list_orders.php',{cache:'no-store'}),fetch('api/list_workers.php',{cache:'no-store'}),fetch('api/partner_orders_staff.php',{cache:'no-store'})]);
  orders=(await a.json()).orders||[]; workers=(await b.json()).workers||[]; partnerOrders=(await c.json()).orders||[];
  renderSummary();renderOrders();renderPartnerOrders();renderMap();
 }catch(e){console.error(e)}
}
function manualPayload(preview=false){return {preview,name:moName.value.trim(),phone:moPhone.value.trim(),service:moService.value,vehicle_type:moVehicle.value,car:moCar.value.trim(),condition:moCondition.value,blocked:moBlocked.value,address:moAddress.value.trim(),lat:moLat.value.trim(),lng:moLng.value.trim(),destination:moDestination.value.trim(),destination_lat:moDestinationLat.value.trim(),destination_lng:moDestinationLng.value.trim(),distance_km:moDistance.value.trim().replace(',','.'),comment:moComment.value.trim(),dispatcher_note:moDispatcherNote.value.trim(),payment_method:moPayment.value,price_mode:document.querySelector('input[name="moPriceMode"]:checked').value,fixed_price:moFixedPrice.value.trim().replace(',','.')}}
function manualPriceMode(){const fixed=document.querySelector('input[name="moPriceMode"]:checked').value==='fixed';moFixedPrice.style.display=fixed?'block':'none';moResult.textContent=fixed?'Будет сохранена указанная сумма.':'Будут использованы действующие тарифы и коэффициенты.'}
async function manualGeocodeOne(address,latField,lngField){if(!address)throw new Error('Укажите адрес');const result=await ymaps.geocode(address,{results:1});const point=result.geoObjects.get(0);if(!point)throw new Error('Адрес не найден');const coords=point.geometry.getCoordinates();latField.value=coords[0].toFixed(6);lngField.value=coords[1].toFixed(6);return coords}
async function manualGeocodeRoute(){try{moResult.textContent='Определяем точки…';const a=await manualGeocodeOne(moAddress.value.trim(),moLat,moLng);if(!moDestination.value.trim()){moResult.textContent='Точка А определена.';return}const b=await manualGeocodeOne(moDestination.value.trim(),moDestinationLat,moDestinationLng);const route=await ymaps.route([a,b]);moDistance.value=(route.getLength()/1000).toFixed(1);moResult.textContent='Маршрут: '+moDistance.value+' км';await manualQuote()}catch(e){moResult.textContent=e.message||'Не удалось определить маршрут'}}
async function manualQuote(){try{const r=await fetch('api/staff_create_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(manualPayload(true))}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Ошибка расчёта');let detail='';if(d.price_mode==='auto'&&d.price_breakdown){const b=d.price_breakdown;detail=' · база и километраж '+Math.round(b.base||0)+' ₽ · техника ×'+Number(b.vehicle_coef||1).toFixed(2)+' · спрос ×'+Number(b.demand_coef||1).toFixed(2)+' · время ×'+Number(b.time_coef||1).toFixed(2)+' · дальняя поездка ×'+Number(b.long_trip_coef||1).toFixed(2)}moResult.textContent=(d.price_mode==='fixed'?'Фиксированная сумма: ':'Расчётная стоимость: ')+Math.round(d.estimated_price)+' ₽'+(d.distance_km?' · '+d.distance_km+' км':'')+detail}catch(e){moResult.textContent=e.message||'Ошибка расчёта'}}
async function manualCreateOrder(){const button=moSubmit;button.disabled=true;button.textContent='Создаём…';try{if(!moLat.value||!moLng.value||(!moDestinationLat.value&&moDestination.value))await manualGeocodeRoute();const r=await fetch('api/staff_create_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(manualPayload(false))}),d=await r.json();if(!r.ok||!d.ok)throw new Error(d.error||'Ошибка создания');alert('Заявка '+d.order_id+' создана · '+Math.round(d.estimated_price)+' ₽');['moName','moPhone','moCar','moAddress','moLat','moLng','moDestination','moDestinationLat','moDestinationLng','moDistance','moComment','moDispatcherNote','moFixedPrice'].forEach(id=>document.getElementById(id).value='');moCondition.selectedIndex=0;moBlocked.selectedIndex=0;moResult.textContent='';load()}catch(e){alert(e.message||'Не удалось создать заявку')}finally{button.disabled=false;button.textContent='Создать заявку без регистрации клиента'}}
function renderSummary(){
 const c=s=>orders.filter(o=>o.status===s).length;
 cNew.textContent=c('new');cSearch.textContent=c('searching');cAssigned.textContent=c('offered')+c('assigned');cEnroute.textContent=c('enroute')+c('arrived')+c('loading')+c('in_transit');cDone.textContent=c('done')+c('completed');
  tabOrders.textContent='('+orders.length+')';
 tabPartner.textContent='('+partnerOrders.filter(o=>!['closed','cancelled'].includes(o.status)).length+')';
 pAvailable.textContent=partnerOrders.filter(o=>o.status==='available').length;pClaimed.textContent=partnerOrders.filter(o=>o.status==='claimed').length;pClosed.textContent=partnerOrders.filter(o=>['closed','cancelled'].includes(o.status)).length;
}
function renderOrders(){
 let list=flt==='all'?orders:orders.filter(o=>flt==='assigned'?['offered','assigned'].includes(o.status):flt==='enroute'?['enroute','arrived','loading','in_transit'].includes(o.status):flt==='done'?['done','completed'].includes(o.status):o.status===flt);
 grid.innerHTML=list.length?list.map(o=>`<article class="card">
 <div class="top"><div class="id">${esc(o.id)}</div><div class="badge ${esc(o.status)}">${esc(statusNames[o.status]||o.status)}</div></div>
 <div class="line"><b>${esc(o.service)}</b> · ${esc(o.vehicle_type)} · ${esc(o.car||'модель не указана')}</div>
 <div class="line">${esc(o.condition||'')}${o.blocked?` · колёса: ${esc(o.blocked)}`:''}</div>
 <div class="line"><b>Откуда:</b> ${esc(o.address)}</div>${o.destination?`<div class="line"><b>Куда:</b> ${esc(o.destination)}</div>`:''}
 <div class="line"><b>${o.price_mode==='fixed'?'Фиксированная':'Расчётная'} стоимость:</b> ${esc(o.estimated_price||'—')} ₽</div><div class="line"><b>Клиент:</b> ${esc(o.name)} · <a style="color:#ff646b" href="tel:${esc(o.phone)}">${esc(o.phone)}</a></div>
 ${o.assigned_worker_name?`<div class="assignedBox"><b>Исполнитель:</b> ${esc(o.assigned_worker_name)}<br><span class="muted">${esc(o.assigned_worker_id||'')}</span></div>`:''}
 ${o.comment?`<div class="line muted">${esc(o.comment)}</div>`:''}
 <textarea class="note" id="n-${esc(o.id)}" placeholder="Заметка диспетчера">${esc(o.dispatcher_note||'')}</textarea>
 <div id="m-${esc(o.id)}"></div>
 <div class="a">${orderActions(o)}${o.lat&&o.lng?`<a target="_blank" href="https://yandex.ru/maps/?ll=${o.lng}%2C${o.lat}&z=16&pt=${o.lng}%2C${o.lat}%2Cpm2rdm">Карта</a>`:''}<a href="tel:${esc(o.phone)}">Позвонить</a></div>
 </article>`).join(''):'<div class="empty">Заявок в этом разделе пока нет</div>';
}
function orderActions(o){const id=esc(o.id),s=o.status;let x='';if(['new','searching'].includes(s))x+=`<button onclick="autoOffer('${id}')">Автоподбор</button><button onclick="match('${id}')">Показать ближайших</button>`;if(s==='assigned')x+=`<button onclick="st('${id}','enroute')">В пути к клиенту</button>`;if(s==='enroute')x+=`<button onclick="st('${id}','arrived')">Прибыл</button>`;if(s==='in_transit')x+=`<button class="red" onclick="st('${id}','done')">Завершить</button>`;if(!['done','completed','cancelled'].includes(s))x+=`<button onclick="st('${id}','cancelled')">Отменить</button>`;return x}
const partnerStatusNames={available:'Свободна',claimed:'Назначена',closed:'Закрыта в MAX',cancelled:'Отменена'};
function renderPartnerOrders(){
 let list=partnerOrders.filter(o=>partnerFlt==='all'||(partnerFlt==='active'&&!['closed','cancelled'].includes(o.status))||(partnerFlt==='closed'&&['closed','cancelled'].includes(o.status)));
 const own=workers.filter(w=>w.is_own_fleet);
 partnerGrid.innerHTML=list.length?list.map(o=>{const id=esc(o.id),closed=['closed','cancelled'].includes(o.status);const options=own.map(w=>`<option value="${esc(w.id)}" ${w.id===o.assigned_worker_id?'selected':''}>${esc(w.name)} · ${w.status==='online'?'онлайн':w.status==='busy'?'занят':w.status==='reserved'?'ожидает':'офлайн'}</option>`).join('');return `<article class="card partnerCard ${esc(o.status)}">
 <div class="top"><div class="id">${id}<br><span class="muted" style="font-size:11px">Партнёр MAX</span></div><div class="badge ${esc(o.status)}">${esc(partnerStatusNames[o.status]||o.status)}</div></div>
 <div class="line"><b>${esc(o.service||'Эвакуация')}</b> · ${esc(o.vehicle||'автомобиль не указан')} · <b>${esc(o.license_plate||'без госномера')}</b></div>
 <div class="line"><b>Точка А:</b> ${esc(o.pickup_address||([o.pickup_lat,o.pickup_lng].filter(x=>x!==null&&x!=='').join(', ')||'не указана'))}</div>
 <div class="line"><b>Точка Б:</b> ${esc(o.destination||'не указана')}</div>${o.service_until?`<div class="line"><b>Время работы:</b> ${esc(o.service_until)}</div>`:''}
 <div class="line"><b>Телефон:</b> ${o.phone?`<a style="color:#ff646b" href="tel:${esc(o.phone)}">${esc(o.phone)}</a>`:'не указан'}</div>${o.comment?`<div class="line muted"><b>Комментарий:</b> ${esc(o.comment)}</div>`:''}
 ${o.assigned_worker_name?`<div class="assignedBox"><b>Водитель:</b> ${esc(o.assigned_worker_name)}</div>`:''}
 <select id="pw-${id}" class="note" style="min-height:auto"><option value="">Выберите водителя нашего парка</option>${options}</select>
 <textarea class="note" id="pn-${id}" placeholder="Заметка диспетчера">${esc(o.dispatcher_note||'')}</textarea>
 <div class="a"><button onclick="partnerStaff('${id}','assign')">${o.assigned_worker_id?'Переназначить':'Назначить'}</button>${o.assigned_worker_id&&!closed?`<button onclick="partnerStaff('${id}','release')">Вернуть в свободные</button>`:''}<button onclick="partnerStaff('${id}','note')">Сохранить заметку</button>${!closed?`<button class="red" onclick="partnerStaff('${id}','status','closed')">Закрыть на сайте</button><button onclick="partnerStaff('${id}','status','cancelled')">Отменить</button>`:`<button onclick="partnerStaff('${id}','status','available')">Вернуть в работу</button>`}${o.pickup_lat&&o.pickup_lng?`<a target="_blank" href="https://yandex.ru/maps/?ll=${o.pickup_lng}%2C${o.pickup_lat}&z=16&pt=${o.pickup_lng}%2C${o.pickup_lat}%2Cpm2rdm">Точка А</a>`:''}${o.destination?`<a target="_blank" href="https://yandex.ru/maps/?text=${encodeURIComponent(o.destination)}">Точка Б</a>`:''}</div>
 </article>`}).join(''):'<div class="empty">Партнёрских заявок в этом разделе пока нет</div>';
}
async function partnerStaff(order_id,action,status=''){const body={order_id,action,status};if(action==='assign')body.worker_id=document.getElementById('pw-'+order_id)?.value||'';if(action==='note')body.note=document.getElementById('pn-'+order_id)?.value||'';if(action==='assign'&&!body.worker_id){alert('Выберите водителя нашего парка');return}const r=await fetch('api/partner_order_staff_action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)}),d=await r.json();if(!d.ok){alert(d.error==='WORKER_OFFLINE'?'Водитель должен быть на линии':d.error||'Не удалось изменить заявку');return}load()}
async function match(id){
 const r=await fetch('api/match_order.php?order_id='+encodeURIComponent(id));const d=await r.json();const el=document.getElementById('m-'+id);
 if(!d.ok){el.innerHTML='<div class="match">'+esc(d.error)+'</div>';return}
 el.innerHTML=d.matches.length?d.matches.map(w=>`<div class="match"><b>${esc(w.name)}</b> · ${w.distance_km} км · рейтинг ${w.rating} · комиссия ${w.commission}% <button onclick="assign('${id}','${w.id}')">Назначить</button></div>`).join(''):'<div class="match">Подходящих свободных исполнителей рядом нет</div>';
}
async function autoOffer(id){
 const r=await fetch('api/auto_offer_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id:id})});
 const d=await r.json();
 if(!d.ok){alert(d.error);return}
 alert('Заказ предложен: '+d.worker.name+' · '+d.worker.distance_km+' км');
 load();
}
async function assign(order_id,worker_id){const r=await fetch('api/assign_worker.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({order_id,worker_id})});const d=await r.json();if(!d.ok)alert(d.error);load()}
async function st(id,status){let note=document.getElementById('n-'+id)?.value||'';await fetch('api/update_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,status,note})});load()}
function renderMap(){
 if(typeof ymaps==='undefined')return;if(!fleetMap)fleetMap=new ymaps.Map('fleetMap',{center:[55.7558,37.6173],zoom:10,controls:['zoomControl']},{restrictMapArea:[[41,19],[82,180]]});
 markers.forEach(m=>fleetMap.geoObjects.remove(m));markers=[];
 orders.filter(o=>o.lat&&o.lng&&!['done','completed','cancelled'].includes(o.status)).forEach(o=>{let m=new ymaps.Placemark([+o.lat,+o.lng],{balloonContent:'<b>'+esc(o.id)+'</b><br>'+esc(o.address)},{preset:'islands#redIcon'});fleetMap.geoObjects.add(m);markers.push(m)});
 partnerOrders.filter(o=>o.pickup_lat&&o.pickup_lng&&!['closed','cancelled'].includes(o.status)).forEach(o=>{let m=new ymaps.Placemark([+o.pickup_lat,+o.pickup_lng],{balloonContent:'<b>MAX / B2B · '+esc(o.license_plate||o.id)+'</b><br>Точка А: '+esc(o.pickup_address||'координаты')},{preset:'islands#violetIcon'});fleetMap.geoObjects.add(m);markers.push(m)});
 workers.filter(w=>w.lat&&w.lng&&w.position_fresh&&['online','reserved','busy'].includes(w.status)).forEach(w=>{let preset=w.is_own_fleet?'islands#violetIcon':w.status==='online'?'islands#greenIcon':w.status==='reserved'?'islands#blueIcon':'islands#yellowIcon';let own=w.is_own_fleet?'<br><b>Собственный парк · повышенный приоритет</b>':'';let m=new ymaps.Placemark([+w.lat,+w.lng],{balloonContent:'<b>'+esc(w.name)+'</b><br>'+esc(w.vehicle_type)+'<br>'+esc(w.status)+own},{preset});fleetMap.geoObjects.add(m);markers.push(m)});
}
document.querySelectorAll('.f:not(.partnerFilter)').forEach(b=>b.onclick=()=>{document.querySelectorAll('.f:not(.partnerFilter)').forEach(x=>x.classList.remove('active'));b.classList.add('active');flt=b.dataset.f;renderOrders()});
document.querySelectorAll('.partnerFilter').forEach(b=>b.onclick=()=>{document.querySelectorAll('.partnerFilter').forEach(x=>x.classList.remove('active'));b.classList.add('active');partnerFlt=b.dataset.pf;renderPartnerOrders()});
document.querySelectorAll('.tab[data-tab]').forEach(b=>b.onclick=()=>{document.querySelectorAll('.tab[data-tab]').forEach(x=>x.classList.remove('active'));b.classList.add('active');ordersTab.style.display=b.dataset.tab==='orders'?'block':'none';partnerTab.style.display=b.dataset.tab==='partner'?'block':'none';mapTab.style.display=b.dataset.tab==='map'?'block':'none';if(b.dataset.tab==='map')setTimeout(renderMap,100)});
async function tickOffers(){try{await fetch('api/auto_dispatch_cycle.php',{method:'POST'});}catch(e){}}
const pageParams=new URLSearchParams(location.search),requestedTab=pageParams.get('tab');
if(['orders','partner','map'].includes(requestedTab)){const requestedTabButton=document.querySelector('.tab[data-tab="'+requestedTab+'"]');if(requestedTabButton)requestedTabButton.click()}
ymaps.ready(load);setInterval(load,10000);setInterval(tickOffers,15000);

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
