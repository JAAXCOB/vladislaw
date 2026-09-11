<?php
require_once __DIR__ . '/../webpush.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') av_json_response(array('ok'=>false,'error'=>'Метод не поддерживается'),405);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;

function av_clean($v, $max = 500) {
    $v = trim((string)$v);
    $v = preg_replace('/\s+/u', ' ', $v);
    if ($v === null) $v = '';
    if (function_exists('mb_substr')) return mb_substr($v, 0, $max, 'UTF-8');
    return substr($v, 0, $max);
}
$current=av_current_user();
if($current){$input['name']=$current['name']??'';$input['phone']=$current['phone']??'';}
$required = array('service','vehicle_type','address','name','phone');
foreach ($required as $f) {
    if (av_clean(isset($input[$f]) ? $input[$f] : '') === '') av_json_response(array('ok'=>false,'error'=>'Заполните обязательные поля'),422);
}
$uid=$current ? ($current['id']??'') : '';
$block=av_client_is_blocked($uid,isset($input['phone'])?$input['phone']:'');
if($block) av_json_response(array('ok'=>false,'error'=>'Создание заявки недоступно. Обратитесь в поддержку AV Rescue. Причина: '.($block['reason']??'ограничение аккаунта')),403);

$orders = av_read_orders();
$pickupLat=(float)($input['lat']??0);$pickupLng=(float)($input['lng']??0);$destination=av_clean($input['destination']??'',700);$destinationLat=(float)($input['destination_lat']??0);$destinationLng=(float)($input['destination_lng']??0);
if(av_clean($input['service']??'')==='Эвакуация'&&$destination==='')av_json_response(array('ok'=>false,'error'=>'Укажите адрес назначения'),422);
function av_order_geocode($address){if(!$address||!function_exists('curl_init'))return array(0,0);$url='https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=ru&q='.rawurlencode($address);$ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_USERAGENT=>'AVRescue/1.8'));$raw=curl_exec($ch);curl_close($ch);$rows=$raw?json_decode($raw,true):null;if(!is_array($rows)||empty($rows[0]))return array(0,0);return array((float)$rows[0]['lat'],(float)$rows[0]['lon']);}
if(!$pickupLat||!$pickupLng){[$pickupLat,$pickupLng]=av_order_geocode(av_clean($input['address']??'',700));}
if($destination&&(!$destinationLat||!$destinationLng)){[$destinationLat,$destinationLng]=av_order_geocode($destination);}
$next = count($orders) + 1;
$id = 'AVR-' . date('ymd-His') . '-' . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
$paymentMethod=av_clean($input['payment_method']??'cash');if(!in_array($paymentMethod,array('cash','card','sbp'),true))$paymentMethod='cash';
$guestToken=$uid===''?bin2hex(random_bytes(32)):'';
$order = array(
'id'=>$id,'created_at'=>date('c'),'updated_at'=>date('c'),'status'=>'new',
'service'=>av_clean(isset($input['service'])?$input['service']:''),
'vehicle_type'=>av_clean(isset($input['vehicle_type'])?$input['vehicle_type']:''),
'car'=>av_clean(isset($input['car'])?$input['car']:''),
'condition'=>av_clean(isset($input['condition'])?$input['condition']:''),
'blocked'=>av_clean(isset($input['blocked'])?$input['blocked']:''),
'address'=>av_clean(isset($input['address'])?$input['address']:'',700),'destination'=>$destination,
'lat'=>$pickupLat,'lng'=>$pickupLng,'destination_lat'=>$destinationLat,'destination_lng'=>$destinationLng,
'comment'=>av_clean(isset($input['comment'])?$input['comment']:'',1200),
'name'=>av_clean(isset($input['name'])?$input['name']:''),'phone'=>av_clean(isset($input['phone'])?$input['phone']:''),'payment_method'=>$paymentMethod,'payment_status'=>$paymentMethod==='cash'?'cash_due':'unpaid',
'dispatcher_note'=>'','user_id'=>$uid,'estimated_price'=>av_estimate_price(av_clean(isset($input['service'])?$input['service']:''),0)
);
if($guestToken!=='')$order['guest_access_hash']=hash('sha256',$guestToken);
$workers=av_read_workers();$best=null;$bestIndex=null;$bestDistance=INF;$bestScore=INF;$now=time();if($pickupLat&&$pickupLng){foreach($workers as $workerIndex=>$worker){if(($worker['status']??'')!=='online'||empty($worker['lat'])||empty($worker['lng']))continue;if(!empty($worker['last_seen'])&&$now-strtotime($worker['last_seen'])>180)continue;if(!av_worker_supports_service($worker,$order['service']))continue;$d=av_haversine_km($pickupLat,$pickupLng,(float)$worker['lat'],(float)$worker['lng']);$score=av_dispatch_score($worker,$d);if($score<$bestScore){$bestScore=$score;$bestDistance=$d;$best=$worker;$bestIndex=$workerIndex;}}}
if($best){$order['assigned_worker_id']=$best['id'];$order['assigned_worker_name']=$best['name']??'';$order['worker_rating']=av_worker_rating($best['id']);$order['status']='offered';$order['offer_created_at']=date('c');$order['estimated_arrival_min']=max(1,(int)ceil(($bestDistance/30)*60));$workers[$bestIndex]['status']='reserved';}else{$order['status']='searching';}
array_unshift($orders, $order);
if (!av_write_orders($orders)||($best&&!av_write_workers($workers))) av_json_response(array('ok'=>false,'error'=>'Нет права записи в папку data'),500);
av_telegram_send("🚨 <b>Новая заявка AV Rescue</b>\n".$id."\n".htmlspecialchars($order['service'])." · ".htmlspecialchars($order['vehicle_type'])."\n".htmlspecialchars($order['address'])."\nКлиент: ".htmlspecialchars($order['name'])." · ".htmlspecialchars($order['phone']));
if($best)av_push_worker($best['id'],'Новый заказ AV Rescue',$order['service'].' · '.$order['address'],'/worker.php?token='.urlencode($best['token']??''),'order-'.$id);
$result=array('ok'=>true,'order_id'=>$id,'estimated_price'=>$order['estimated_price'],'payment_method'=>$paymentMethod);
if($guestToken!=='')$result['guest_status_url']='/guest-payment.php?order='.rawurlencode($id).'&token='.rawurlencode($guestToken);
av_json_response($result);
