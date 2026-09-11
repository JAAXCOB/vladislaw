<?php
require_once __DIR__.'/../alfa_acquiring.php';
if($_SERVER['REQUEST_METHOD']!=='POST')av_json_response(array('ok'=>false,'error'=>'Метод не поддерживается'),405);
$user=av_current_user();
if(!av_alfa_ready(true))av_json_response(array('ok'=>false,'error'=>'Онлайн-оплата пока не включена'),503);
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;$orderId=trim((string)($input['order_id']??''));$guestToken=trim((string)($input['guest_token']??''));
$orders=av_read_orders();$index=null;foreach($orders as $k=>$candidate){if(($candidate['id']??'')!==$orderId)continue;$owned=$user&&($candidate['user_id']??'')===($user['id']??'');$guest=empty($candidate['user_id'])&&av_guest_order_access($candidate,$guestToken);if($owned||$guest){$index=$k;break;}}
if($index===null)av_json_response(array('ok'=>false,'error'=>'Заказ не найден'),404);$order=$orders[$index];
if(!empty($order['b2b_id'])||($order['source']??'')==='max_partner')av_json_response(array('ok'=>false,'error'=>'B2B оплачивается отдельно'),422);
if(in_array($order['status']??'',array('cancelled'),true))av_json_response(array('ok'=>false,'error'=>'Отменённый заказ оплатить нельзя'),422);
if(($order['payment_status']??'')==='paid')av_json_response(array('ok'=>true,'status'=>'paid','message'=>'Заказ уже оплачен'));
if(!empty($order['alfa_order_id'])&&!empty($order['alfa_form_url'])&&in_array($order['payment_status']??'pending',array('pending','pending_3ds'),true))av_json_response(array('ok'=>true,'status'=>$order['payment_status']??'pending','payment_url'=>$order['alfa_form_url']));
$amount=(float)($order['final_price']??$order['estimated_price']??0);if($amount<=0)av_json_response(array('ok'=>false,'error'=>'Стоимость заказа ещё не рассчитана'),422);
$attempt=(int)($order['alfa_attempt']??0)+1;$clean=preg_replace('/[^A-Za-z0-9_-]/','',(string)$orderId);$bankNumber=substr($clean,0,24).'-'.$attempt;
$return='https://av-rescue.ru/payment_return.php?order='.rawurlencode($orderId);if(!$user)$return.='&token='.rawurlencode($guestToken);$params=array('orderNumber'=>$bankNumber,'amount'=>(int)round($amount*100),'returnUrl'=>$return,'failUrl'=>$return.'&failed=1','dynamicCallbackUrl'=>'https://av-rescue.ru/api/alfa_callback.php','description'=>'AV Rescue — заказ '.$orderId,'language'=>'ru');
if($user&&!empty($user['email']))$params['email']=$user['email'];if($user&&!empty($user['phone']))$params['phone']=$user['phone'];if(!$user&&!empty($order['phone']))$params['phone']=$order['phone'];
$response=av_alfa_request('register.do',$params);if(empty($response['ok']))av_json_response(array('ok'=>false,'error'=>$response['error']??'Ошибка регистрации платежа','bank_error_code'=>$response['bank_error_code']??''),502);
$data=$response['data'];if(empty($data['orderId'])||empty($data['formUrl']))av_json_response(array('ok'=>false,'error'=>'Банк не вернул ссылку на оплату'),502);
$channel=in_array($order['payment_method']??'',array('card','sbp'),true)?$order['payment_method']:'card';$orders[$index]['payment_method']=$channel;$orders[$index]['payment_provider']='alfa';$orders[$index]['payment_status']='pending';$orders[$index]['alfa_order_id']=$data['orderId'];$orders[$index]['alfa_form_url']=$data['formUrl'];$orders[$index]['alfa_order_number']=$bankNumber;$orders[$index]['alfa_attempt']=$attempt;$orders[$index]['alfa_amount']=$amount;$orders[$index]['payment_created_at']=date('c');
if(!av_write_orders($orders))av_json_response(array('ok'=>false,'error'=>'Не удалось сохранить платёж'),500);av_alfa_payment_log($orders[$index]);
av_json_response(array('ok'=>true,'status'=>'pending','payment_url'=>$data['formUrl']));
