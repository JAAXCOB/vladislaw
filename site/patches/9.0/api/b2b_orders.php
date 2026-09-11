<?php
require_once __DIR__.'/../config.php';
$b=av_current_b2b(); if(!$b) av_json_response(array('ok'=>false,'error'=>'Нет доступа'),401);
$list=array();$total=0;$paid=0;$outstanding=0;foreach(av_read_orders() as $o)if(($o['b2b_id']??'')===($b['id']??'')){if(($o['source']??'')==='max_partner')continue;$o['payment_status_label']=av_payment_status_label($o['payment_status']??'b2b_postpay');$o['payment_method_label']=av_payment_method_label($o['payment_method']??'bank_transfer');$amount=(float)($o['final_price']??$o['estimated_price']??0);$total+=$amount;if(($o['payment_status']??'')==='paid')$paid+=$amount;else $outstanding+=$amount;$list[]=$o;}
av_json_response(array('ok'=>true,'orders'=>$list,'discount'=>av_b2b_discount($b),'summary'=>array('total'=>round($total,2),'paid'=>round($paid,2),'outstanding'=>round($outstanding,2))));
