<?php
require_once __DIR__.'/../alfa_acquiring.php';
$user=av_current_user();if(!$user)av_json_response(array('ok'=>false,'error'=>'Требуется вход клиента'),401);$orderId=trim((string)($_GET['order_id']??''));
$orders=av_read_orders();$index=null;foreach($orders as $k=>$order)if(($order['id']??'')===$orderId&&($order['user_id']??'')===($user['id']??'')){$index=$k;break;}if($index===null)av_json_response(array('ok'=>false,'error'=>'Заказ не найден'),404);
$result=av_alfa_sync_order($orders[$index]);if(!empty($result['ok'])){av_write_orders($orders);av_alfa_payment_log($orders[$index]);}av_json_response($result,empty($result['ok'])?502:200);
