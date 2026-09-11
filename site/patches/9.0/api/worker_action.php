<?php
require_once __DIR__.'/../config.php';
$in=json_decode(file_get_contents('php://input'),true); if(!is_array($in))$in=$_POST;
$token=trim((string)($in['token']??''));$orderId=trim((string)($in['order_id']??''));$action=trim((string)($in['action']??''));
$allowed=array('accept','reject','enroute','arrived','done');
if(!$token||!$orderId||!in_array($action,$allowed,true)) av_json_response(array('ok'=>false,'error'=>'Некорректные данные'),422);

$workers=av_read_workers();$wi=null;
foreach($workers as $k=>$w){if(isset($w['token'])&&hash_equals($w['token'],$token)){$wi=$k;break;}}
if($wi===null) av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);

$orders=av_read_orders();$oi=null;
foreach($orders as $k=>$o){if(($o['id']??'')===$orderId && ($o['assigned_worker_id']??'')===($workers[$wi]['id']??'')){$oi=$k;break;}}
if($oi===null) av_json_response(array('ok'=>false,'error'=>'Заказ не найден'),404);
$current=$orders[$oi]['status']??'';
$valid=($action==='accept'&&$current==='offered')||($action==='reject'&&$current==='offered')||($action==='enroute'&&$current==='assigned')||($action==='arrived'&&$current==='enroute')||($action==='done'&&in_array($current,array('arrived','in_transit'),true));
if(!$valid) av_json_response(array('ok'=>false,'error'=>'Этот переход статуса уже недоступен'),409);

if($action==='accept'){
  $orders[$oi]['status']='assigned';
  $orders[$oi]['worker_accepted_at']=date('c');
  $workers[$wi]['status']='busy';
} elseif($action==='reject'){
  if(!isset($orders[$oi]['rejected_worker_ids'])||!is_array($orders[$oi]['rejected_worker_ids']))$orders[$oi]['rejected_worker_ids']=array();
  $orders[$oi]['rejected_worker_ids'][]=$workers[$wi]['id'];
  $orders[$oi]['assigned_worker_id']='';$orders[$oi]['assigned_worker_name']='';$orders[$oi]['status']='searching';
  $workers[$wi]['status']='online';
} elseif($action==='enroute'){
  $orders[$oi]['status']='enroute';$orders[$oi]['enroute_at']=date('c');$workers[$wi]['status']='busy';
} elseif($action==='arrived'){
  $orders[$oi]['status']='arrived';$orders[$oi]['arrived_at']=date('c');$workers[$wi]['status']='busy';
} elseif($action==='done'){
  $orders[$oi]['status']='done';$orders[$oi]['completed_at']=date('c');$workers[$wi]['status']='online';
  $distance=0;
  if(!empty($orders[$oi]['lat'])&&!empty($orders[$oi]['lng'])&&!empty($workers[$wi]['lat'])&&!empty($workers[$wi]['lng']))$distance=av_haversine_km($orders[$oi]['lat'],$orders[$oi]['lng'],$workers[$wi]['lat'],$workers[$wi]['lng']);
  if(empty($orders[$oi]['estimated_price']))$orders[$oi]['estimated_price']=av_estimate_price($orders[$oi]['service'],$distance);
  av_post_order_finance($orders[$oi],$workers[$wi]);
}
$orders[$oi]['updated_at']=date('c');
if(!av_write_orders($orders)||!av_write_workers($workers)) av_json_response(array('ok'=>false,'error'=>'Ошибка сохранения'),500);
$labels=array('accept'=>'✅ Принят','reject'=>'❌ Отказ','enroute'=>'🚗 В пути','arrived'=>'📍 На месте','done'=>'🏁 Завершён');
av_telegram_send(($labels[$action]??$action)." · ".$orderId." · ".htmlspecialchars($workers[$wi]['name']));
av_json_response(array('ok'=>true,'status'=>$orders[$oi]['status']));
