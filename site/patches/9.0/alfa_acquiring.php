<?php
require_once __DIR__.'/config.php';

function av_alfa_base_url($settings=null){
 $settings=is_array($settings)?$settings:av_payment_settings();
 return ($settings['alfa_mode']??'test')==='live'?'https://pay.alfabank.ru/payment/rest/':'https://alfa.rbsuat.com/payment/rest/';
}
function av_alfa_has_credentials($settings=null){
 $settings=is_array($settings)?$settings:av_payment_settings();
 return !empty($settings['alfa_token'])||(!empty($settings['alfa_username'])&&!empty($settings['alfa_password']));
}
function av_alfa_ready($requireEnabled=true){
 $settings=av_payment_settings();
 return (!$requireEnabled||!empty($settings['acquiring_enabled']))&&av_alfa_has_credentials($settings);
}
function av_alfa_request($method,$params=array()){
 $settings=av_payment_settings();
 if(!av_alfa_has_credentials($settings))return array('ok'=>false,'error'=>'Эквайринг Альфа-Банка ещё не настроен');
 $allowed=array('register.do','getOrderStatusExtended.do','refund.do','reverse.do');
 if(!in_array($method,$allowed,true))return array('ok'=>false,'error'=>'Метод банка не разрешён');
 if(!empty($settings['alfa_token']))$params['token']=$settings['alfa_token'];
 else{$params['userName']=$settings['alfa_username'];$params['password']=$settings['alfa_password'];}
 $url=av_alfa_base_url($settings).$method;$body=http_build_query($params,'','&',PHP_QUERY_RFC3986);$raw=false;$httpCode=0;
 if(function_exists('curl_init')){
  $ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>array('Content-Type: application/x-www-form-urlencoded','Accept: application/json'),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2));$raw=curl_exec($ch);$httpCode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$curlError=curl_error($ch);curl_close($ch);if($raw===false)return array('ok'=>false,'error'=>'Не удалось связаться с Альфа-Банком','technical_error'=>$curlError);
 }else{
  $context=stream_context_create(array('http'=>array('method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",'content'=>$body,'timeout'=>20,'ignore_errors'=>true)));$raw=@file_get_contents($url,false,$context);if($raw===false)return array('ok'=>false,'error'=>'Не удалось связаться с Альфа-Банком');
 }
 $data=json_decode((string)$raw,true);if(!is_array($data))return array('ok'=>false,'error'=>'Альфа-Банк вернул некорректный ответ','http_code'=>$httpCode);
 $errorCode=(string)($data['errorCode']??$data['ErrorCode']??'0');
 if($errorCode!==''&&$errorCode!=='0')return array('ok'=>false,'error'=>$data['userMessage']??$data['errorMessage']??$data['ErrorMessage']??'Ошибка платёжного шлюза','bank_error_code'=>$errorCode,'data'=>$data);
 return array('ok'=>true,'data'=>$data,'http_code'=>$httpCode);
}
function av_alfa_status_name($status){
 $map=array(0=>'pending',1=>'authorized',2=>'paid',3=>'reversed',4=>'refunded',5=>'pending_3ds',6=>'declined');return $map[(int)$status]??'unknown';
}
function av_alfa_sync_order(&$order){
 $bankId=(string)($order['alfa_order_id']??'');if($bankId==='')return array('ok'=>false,'error'=>'Платёж для заказа не зарегистрирован');
 $response=av_alfa_request('getOrderStatusExtended.do',array('orderId'=>$bankId,'language'=>'ru'));
 if(empty($response['ok']))return $response;$data=$response['data'];$status=(int)($data['orderStatus']??$data['OrderStatus']??-1);$name=av_alfa_status_name($status);$previous=$order['payment_status']??'';
 $order['payment_provider']='alfa';$order['payment_status']=$name;$order['payment_updated_at']=date('c');$order['alfa_status_code']=$status;
 $paidAmount=(float)($data['paymentAmountInfo']['paymentState']??0);if(isset($data['amount']))$paidAmount=(float)$data['amount'];elseif(isset($data['Amount']))$paidAmount=(float)$data['Amount'];
 if($name==='paid'){$order['paid_at']=$order['paid_at']??date('c');$order['paid_amount']=round($paidAmount/100,2);}
 if($name!==$previous&&in_array($name,array('paid','refunded','reversed','declined'),true))av_order_event($order,'payment_'.$name,'Альфа-Банк: '.$name);
 return array('ok'=>true,'status'=>$name,'status_code'=>$status,'paid_amount'=>$order['paid_amount']??0);
}
function av_alfa_payment_log($order){
 $rows=av_secure_read(AV_PAYMENT_FILE);$found=false;foreach($rows as $k=>$row)if((string)($row['order_id']??'')===(string)($order['id']??'')){$rows[$k]=array_merge($row,array('bank_order_id'=>$order['alfa_order_id']??'','status'=>$order['payment_status']??'pending','amount'=>$order['alfa_amount']??$order['estimated_price']??0,'updated_at'=>date('c')));$found=true;break;}
 if(!$found)$rows[]=array('id'=>'PAY-'.date('ymdHis').'-'.substr(bin2hex(random_bytes(3)),0,6),'order_id'=>$order['id']??'','provider'=>'alfa','bank_order_id'=>$order['alfa_order_id']??'','status'=>$order['payment_status']??'pending','amount'=>$order['alfa_amount']??$order['estimated_price']??0,'created_at'=>date('c'),'updated_at'=>date('c'));
 return av_secure_write(AV_PAYMENT_FILE,$rows);
}
