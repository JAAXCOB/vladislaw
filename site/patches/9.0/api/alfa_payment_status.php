<?php
require_once __DIR__.'/../alfa_acquiring.php';
$user=av_current_user();$orderId=trim((string)($_GET['order_id']??''));$guestToken=trim((string)($_GET['guest_token']??''));
$orders=av_read_orders();$index=null;foreach($orders as $k=>$candidate){if(($candidate['id']??'')!==$orderId)continue;$owned=$user&&($candidate['user_id']??'')===($user['id']??'');$guest=empty($candidate['user_id'])&&av_guest_order_access($candidate,$guestToken);if($owned||$guest){$index=$k;break;}}if($index===null)av_json_response(array('ok'=>false,'error'=>'Заказ не найден'),404);
if(empty($orders[$index]['alfa_order_id']))av_json_response(array('ok'=>true,'status'=>$orders[$index]['payment_status']??'unpaid','payment_status_label'=>av_payment_status_label($orders[$index]['payment_status']??'unpaid')));
$result=av_alfa_sync_order($orders[$index]);if(!empty($result['ok'])){av_write_orders($orders);av_alfa_payment_log($orders[$index]);}av_json_response($result,empty($result['ok'])?502:200);
