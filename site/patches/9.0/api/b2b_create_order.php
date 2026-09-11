<?php
require_once __DIR__.'/../config.php';
$b=av_current_b2b(); if(!$b) av_json_response(array('ok'=>false,'error'=>'Нет доступа'),401);
$in=json_decode(file_get_contents('php://input'),true); if(!is_array($in))$in=$_POST;
$service=trim((string)($in['service']??''));$vehicle=trim((string)($in['vehicle_type']??''));$address=trim((string)($in['address']??''));$phone=trim((string)($in['phone']??$b['phone']??''));$name=trim((string)($in['name']??$b['contact']??''));
if(!$service||!$vehicle||!$address) av_json_response(array('ok'=>false,'error'=>'Заполните обязательные поля'),422);
$orders=av_read_orders();$id='B2B-ORD-'.date('ymd-His').'-'.str_pad((string)(count($orders)+1),3,'0',STR_PAD_LEFT);
$price=av_order_price_for_b2b($service,0,$b);
$order=array('id'=>$id,'created_at'=>date('c'),'updated_at'=>date('c'),'status'=>'new','service'=>$service,'vehicle_type'=>$vehicle,'address'=>$address,'lat'=>trim((string)($in['lat']??'')),'lng'=>trim((string)($in['lng']??'')),'comment'=>trim((string)($in['comment']??'')),'name'=>$name,'phone'=>$phone,'b2b_id'=>$b['id'],'estimated_price'=>$price,'payment_method'=>'bank_transfer','payment_status'=>'b2b_postpay','dispatcher_note'=>'');
array_unshift($orders,$order);if(!av_write_orders($orders))av_json_response(array('ok'=>false,'error'=>'Ошибка сохранения'),500);
av_telegram_send("🏢 <b>Новый B2B заказ</b>\n".$id."\n".htmlspecialchars($b['company'])."\n".htmlspecialchars($service)." · ".htmlspecialchars($address));
av_json_response(array('ok'=>true,'order_id'=>$id,'estimated_price'=>$price,'discount'=>av_b2b_discount($b)));
