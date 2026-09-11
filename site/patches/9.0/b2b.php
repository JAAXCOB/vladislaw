<?php
require_once __DIR__.'/config.php';$b=av_current_b2b();if(!$b){header('Location:/b2b-login.php');exit;}
?><!doctype html><html data-av-auth-page="1" lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#08090a"><link rel="manifest" href="/manifest.webmanifest?v=221"><link rel="apple-touch-icon" href="/assets/pwa-icon-180.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="AV Rescue"><script src="/pwa.js?v=221" defer></script><title>AV Rescue — B2B</title>
<style>*{box-sizing:border-box}body{margin:0;background:#08090a;color:#fff;font-family:Arial}.w{width:min(1150px,calc(100% - 28px));margin:auto}.top{height:72px;display:flex;align-items:center;justify-content:space-between}.brand{font-size:28px;font-weight:900}.brand b{color:#ed111c}.cards{display:grid;grid-template-columns:1fr 1fr;gap:14px}.card{background:#111317;border:1px solid #30343a;border-radius:12px;padding:19px}.muted{color:#aaa}input,select,textarea{width:100%;box-sizing:border-box;background:#0b0d0f;color:#fff;border:1px solid #34373b;border-radius:7px;padding:12px;margin-top:8px}.btn,button{display:inline-block;background:#ed111c;color:#fff;border:0;border-radius:7px;padding:12px 16px;font-weight:800;cursor:pointer;text-decoration:none}.secondary{background:#17191c;border:1px solid #3a3d42}.order{border-top:1px solid #2c2f33;padding:12px 0}.badge{border:1px solid #444;border-radius:20px;padding:4px 8px;font-size:11px}@media(max-width:800px){.cards{grid-template-columns:1fr}.top{height:auto;padding:14px 0;gap:10px;flex-wrap:wrap}}</style></head><body><div class="w">
<div class="top"><div class="brand">AV<b>R</b> B2B</div><div><a class="btn secondary" href="/">Сайт</a> <a class="btn secondary" href="/b2b-change-password.php">Пароль</a> <a class="btn secondary" href="/b2b-logout.php">Выйти</a></div></div>
<h1><?php echo htmlspecialchars($b['company']);?></h1><p class="muted">ID <?php echo htmlspecialchars($b['id']);?> · ИНН <?php echo htmlspecialchars($b['inn']);?> · корпоративная скидка <?php echo av_b2b_discount($b);?>%</p>
<div class="cards"><section class="card"><h2>Новый корпоративный заказ</h2><select id="service"><option>Эвакуация</option><option>Техпомощь на дороге</option><option>Запуск двигателя</option><option>Замена колеса</option><option>Подвоз топлива</option></select><select id="vehicle"><option>Легковой</option><option>Внедорожник</option><option>Коммерческий</option><option>Грузовой</option><option>Спецтехника</option></select><input id="address" placeholder="Адрес подачи"><textarea id="comment" placeholder="Комментарий"></textarea><button onclick="createOrder()">Создать заказ</button><p id="msg" class="muted"></p></section>
<section class="card"><h2>Заказы и документы</h2><p id="paymentSummary" class="muted">Загрузка расчётов…</p><div id="orders">Загрузка…</div></section></div></div>
<script>
const esc=s=>String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
async function createOrder(){let r=await fetch('/api/b2b_create_order.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({service:service.value,vehicle_type:vehicle.value,address:address.value,comment:comment.value})});let d=await r.json();msg.textContent=d.ok?'Заказ '+d.order_id+' создан. Ориентир: '+d.estimated_price+' ₽, скидка '+d.discount+'%':(d.error||'Ошибка');if(d.ok){address.value='';comment.value='';load()}}
async function doc(id){let r=await fetch('/api/b2b_document.php?order_id='+encodeURIComponent(id));let d=await r.json();if(!d.ok){alert(d.error);return}alert('Документ '+d.document.id+'\nСумма: '+d.document.amount+' ₽')}
async function load(){let r=await fetch('/api/b2b_orders.php',{cache:'no-store'});let d=await r.json();let s=d.summary||{};paymentSummary.textContent='Всего: '+(s.total||0)+' ₽ · оплачено: '+(s.paid||0)+' ₽ · к оплате: '+(s.outstanding||0)+' ₽';orders.innerHTML=(d.orders||[]).map(o=>`<div class="order"><b>${esc(o.id)}</b> <span class="badge">${esc(o.status)}</span><br>${esc(o.service)} · ${esc(o.address)}<br><span class="muted">${esc(o.estimated_price||'—')} ₽ · ${esc(o.payment_status_label||'Оплата по счёту')}</span><br><button onclick="location.href='/b2b-invoice.php?order_id='+encodeURIComponent('${esc(o.id)}')">Счёт</button> ${o.status==='done'?`<button class="secondary" onclick="location.href='/b2b-document.php?order_id='+encodeURIComponent('${esc(o.id)}')">Акт</button>`:''}</div>`).join('')||'<span class="muted">Заказов пока нет</span>'}
load();setInterval(load,15000);
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
