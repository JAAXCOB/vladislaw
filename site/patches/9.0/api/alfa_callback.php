<?php
require_once __DIR__.'/../alfa_acquiring.php';
$bankId=trim((string)($_REQUEST['mdOrder']??$_REQUEST['orderId']??''));$bankNumber=trim((string)($_REQUEST['orderNumber']??''));if($bankId===''&&$bankNumber===''){http_response_code(400);echo 'missing order';exit;}
$orders=av_read_orders();$index=null;foreach($orders as $k=>$order)if(($bankId!==''&&($order['alfa_order_id']??'')===$bankId)||($bankNumber!==''&&($order['alfa_order_number']??'')===$bankNumber)){$index=$k;break;}if($index===null){http_response_code(404);echo 'not found';exit;}
$result=av_alfa_sync_order($orders[$index]);if(empty($result['ok'])){http_response_code(502);echo 'status error';exit;}
if(!av_write_orders($orders)){http_response_code(500);echo 'save error';exit;}av_alfa_payment_log($orders[$index]);echo 'ok';
