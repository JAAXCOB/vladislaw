<?php
require_once __DIR__.'/config.php';
$s=av_payment_settings();
$doc=trim((string)($_GET['doc']??'terms'));
$allowed=array('terms','privacy','offer','payment');
if(!in_array($doc,$allowed,true))$doc='terms';
$title=array(
 'terms'=>'Пользовательское соглашение',
 'privacy'=>'Политика конфиденциальности',
 'offer'=>'Условия оказания услуг',
 'payment'=>'Оплата и возврат'
)[$doc];
$legal=$s['legal_name']??'ИП / оператор AV Rescue';
$inn=$s['inn']??'';
$ogrnip=$s['ogrnip']??'';
$address=$s['legal_address']??'';
$email=$s['support_email']??'';
$phone=$s['support_phone']??'8 925 584-58-58';
?><!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo htmlspecialchars($title);?> — AV Rescue</title>
<style>*{box-sizing:border-box}body{margin:0;background:#08090a;color:#fff;font-family:Arial,Helvetica,sans-serif}.w{width:min(900px,calc(100% - 28px));margin:auto}.top{height:76px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #282c31}.brand{font-size:28px;font-weight:900}.brand b{color:#ed111c}a{color:#fff}.card{margin:28px 0;background:#111317;border:1px solid #30343a;border-radius:12px;padding:24px;line-height:1.65}.muted{color:#aaa}h1{font-size:32px}.nav{display:flex;gap:10px;flex-wrap:wrap}.nav a{padding:8px 10px;border:1px solid #3b3f45;border-radius:7px;text-decoration:none}</style></head><body><div class="w">
<div class="top"><a class="brand" href="/">AV<b>R</b> Rescue</a><a href="/">На сайт</a></div>
<div class="nav" style="margin-top:18px"><a href="/legal.php?doc=terms">Соглашение</a><a href="/legal.php?doc=privacy">Конфиденциальность</a><a href="/legal.php?doc=offer">Услуги</a><a href="/legal.php?doc=payment">Оплата и возврат</a></div>
<div class="card"><h1><?php echo htmlspecialchars($title);?></h1>
<?php if($doc==='privacy'):?>
<p>AV Rescue обрабатывает данные, которые пользователь предоставляет при регистрации и оформлении заявки: имя, телефон, данные автомобиля, адрес и координаты подачи. Данные используются для исполнения заказа, связи с клиентом, работы личного кабинета и обеспечения безопасности сервиса.</p>
<p>Доступ к данным предоставляется только в объёме, необходимом для выполнения заказа и работы платформы. Платёжные реквизиты банковских карт AV Rescue не хранит: при подключении эквайринга обработка карточных данных выполняется платёжной инфраструктурой банка.</p>
<?php elseif($doc==='offer'):?>
<p>AV Rescue предоставляет цифровую платформу для оформления услуг помощи на дороге, работы собственного автопарка и подключения независимых исполнителей. Итоговые условия конкретного заказа, стоимость, тип техники и исполнитель отображаются в системе до/в процессе исполнения заказа.</p>
<p>Зона обслуживания может временно ограничиваться. Актуальная территория приёма заказов проверяется системой при создании заявки.</p>
<?php elseif($doc==='payment'):?>
<p>Платформа поддерживает наличную, безналичную и корпоративную оплату. Онлайн-платёж проводится на защищённой платёжной странице Альфа-Банка; AV Rescue не получает и не хранит номер карты, срок действия и защитный код. При наличной оплате клиент рассчитывается с исполнителем, а комиссия агрегатора отражается на расчётном балансе исполнителя.</p>
<p>До подтверждения оплаты услуга считается неоплаченной. Возврат выполняется на тот же способ оплаты с учётом фактического статуса заказа и объёма уже оказанных услуг. Срок зачисления возвращённых средств зависит от банка покупателя.</p>
<?php else:?>
<p>Используя AV Rescue, пользователь соглашается с правилами сервиса, корректностью предоставляемых данных и обработкой информации, необходимой для выполнения заказа. Пользователь может работать через сайт, личный кабинет и в дальнейшем через официальное мобильное приложение AV Rescue.</p>
<p>Для сотрудников, исполнителей и B2B-партнёров права доступа определяются ролью единого аккаунта.</p>
<?php endif;?>
<hr style="border:0;border-top:1px solid #30343a;margin:24px 0">
<h3>Оператор сервиса</h3>
<p><b><?php echo htmlspecialchars($legal);?></b><br>
<?php if($inn):?>ИНН: <?php echo htmlspecialchars($inn);?><br><?php endif;?>
<?php if($ogrnip):?>ОГРНИП: <?php echo htmlspecialchars($ogrnip);?><br><?php endif;?>
<?php if($address):?>Адрес: <?php echo htmlspecialchars($address);?><br><?php endif;?>
Телефон: <?php echo htmlspecialchars($phone);?><br>
<?php if($email):?>Email: <?php echo htmlspecialchars($email);?><?php endif;?></p>
<p class="muted">Юридические тексты являются рабочей основой платформы и должны быть финально проверены с учётом фактической модели работы ИП перед публичным запуском и включением боевых платежей.</p>
</div></div></body></html>
