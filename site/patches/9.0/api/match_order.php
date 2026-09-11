<?php
require_once __DIR__.'/../config.php'; av_require_dispatcher_api();
$orderId=trim((string)(isset($_GET['order_id'])?$_GET['order_id']:''));
$orders=av_read_orders(); $order=null;
foreach($orders as $o){if(isset($o['id'])&&$o['id']===$orderId){$order=$o;break;}}
if(!$order) av_json_response(array('ok'=>false,'error'=>'Заказ не найден'),404);
if(empty($order['lat'])||empty($order['lng'])) av_json_response(array('ok'=>false,'error'=>'У заказа нет координат'),422);

$workers=av_read_workers(); $now=time(); $matches=array();
foreach($workers as $w){
    if(($w['status']??'offline')!=='online') continue;
    if(empty($w['lat'])||empty($w['lng'])||empty($w['last_seen'])) continue;
    if($now-strtotime($w['last_seen'])>180) continue; // no update for 3 minutes = unavailable
    $wt=mb_strtolower((string)($w['vehicle_type']??''),'UTF-8');$ov=$order['vehicle_type']??'';$state=mb_strtolower(($order['condition']??'').' '.($order['comment']??''),'UTF-8');
    if((strpos($state,'кювет')!==false||strpos($state,'перевер')!==false||strpos($state,'манипуля')!==false)&&strpos($wt,'манипулятор')===false)continue;
    if($ov==='Грузовой'&&strpos($wt,'грузов')===false&&strpos($wt,'от 3')===false)continue;
    if($ov==='Мотоцикл'&&strpos($wt,'мото')===false&&strpos($wt,'эвакуатор')===false)continue;
    $services=$w['services']??array();
    if(!empty($services) && !in_array($order['service'],$services,true)) continue;
    $d=av_haversine_km($order['lat'],$order['lng'],$w['lat'],$w['lng']);
    $terms=av_worker_loyalty($w);
    $matches[]=array('id'=>$w['id'],'name'=>$w['name'],'phone'=>$w['phone'],'vehicle_type'=>$w['vehicle_type'],'distance_km'=>round($d,1),'rating'=>$w['rating']??5,'commission'=>$terms['commission_rate'],'payout_rate'=>$terms['payout_rate'],'loyalty_level'=>$terms['level']);
}
usort($matches,function($a,$b){return $a['distance_km']<=>$b['distance_km'];});
av_json_response(array('ok'=>true,'matches'=>array_slice($matches,0,10)));
