<?php
require_once __DIR__.'/config.php';
$tariffs=av_read_tariffs();
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Стоимость услуг — AV Rescue</title>
<style>*{box-sizing:border-box}body{margin:0;background:#08090a;color:#fff;font-family:Arial,Helvetica,sans-serif}.w{width:min(900px,calc(100% - 28px));margin:auto}.top{height:76px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #282c31}.brand{font-size:28px;font-weight:900}.brand b,.red{color:#ed111c}a{color:#fff}.card{margin:28px 0;background:#111317;border:1px solid #30343a;border-radius:12px;padding:24px}.muted{color:#aaa;line-height:1.55}h1{font-size:32px}.price{display:grid;grid-template-columns:1.5fr repeat(3,.7fr);gap:10px;padding:14px 0;border-bottom:1px solid #30343a;align-items:center}.price:last-child{border:0}.price span:not(:first-child){text-align:right}@media(max-width:650px){.price{grid-template-columns:1fr 1fr}.price span:not(:first-child){text-align:left}.price .name{grid-column:1/-1;font-weight:700}}</style></head><body><div class="w">
<div class="top"><a class="brand" href="/">AV<b>R</b> Rescue</a><a href="/">На сайт</a></div>
<div class="card"><h1>Стоимость услуг</h1><p class="muted">Базовые тарифы AV Rescue. Точная стоимость рассчитывается по маршруту и выбранной технике и сообщается клиенту до подтверждения выполнения заказа.</p>
<div class="price"><b>Услуга</b><b>Подача</b><b>За км</b><b>Минимум</b></div>
<?php foreach($tariffs as $name=>$t):?>
<div class="price"><span class="name"><?php echo htmlspecialchars($name);?></span><span><?php echo number_format((float)($t['base']??0),0,'.',' ');?> ₽</span><span><?php echo number_format((float)($t['per_km']??0),0,'.',' ');?> ₽</span><span><?php echo number_format((float)($t['minimum']??0),0,'.',' ');?> ₽</span></div>
<?php endforeach;?>
<p class="muted">Коэффициент может применяться для манипулятора, ломаной платформы, подкатной тележки, автомобиля с низким клиренсом и других специальных условий. Дополнительные работы согласовываются до начала их выполнения.</p>
<p><a href="/legal.php?doc=offer">Условия оказания услуг</a> · <a href="/legal.php?doc=payment">Оплата и возврат</a></p></div>
</div></body></html>
