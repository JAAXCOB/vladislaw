<?php
/* Only a server-verified bank response may create cashless driver income. */
function av_payment_minor($value) {
 if(!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0)return null;
 return (int)round((float)$value*100);
}
function av_verified_cashless_payment($order) {
 $proof=$order['payment_verification']??array();
 $expected=av_payment_minor($order['alfa_amount']??null);
 $gross=av_payment_minor($order['final_price']??$order['estimated_price']??null);
 return ($order['payment_status']??'')==='paid'
  && ($order['payment_provider']??'')==='alfa'
  && ($proof['provider']??'')==='alfa'
  && !empty($proof['verified_at'])
  && !empty($order['alfa_order_id'])
  && ($proof['bank_order_id']??null)===$order['alfa_order_id']
  && !empty($order['alfa_order_number'])
  && ($proof['order_number']??null)===$order['alfa_order_number']
  && ($proof['status_code']??null)===2
  && ($proof['currency']??null)==='643'
  && $expected!==null && $expected===$gross
  && ($proof['amount_minor']??null)===$expected
  && av_payment_minor($order['paid_amount']??null)===$expected;
}
function av_alfa_verification($order,$data) {
 $amount=$data['amount']??$data['Amount']??null;
 if(!(is_int($amount)||(is_string($amount)&&preg_match('/^[0-9]+$/D',$amount))))return null;
 $expected=av_payment_minor($order['alfa_amount']??null);
 if(empty($order['alfa_order_id'])||$expected===null||(int)$amount!==$expected
  || (string)($data['currency']??$data['Currency']??'')!=='643'
  || empty($order['alfa_order_number'])
  || (string)($data['orderNumber']??$data['OrderNumber']??'')!==$order['alfa_order_number']
  || (int)($data['orderStatus']??$data['OrderStatus']??-1)!==2)return null;
 return array('provider'=>'alfa','bank_order_id'=>$order['alfa_order_id'],
  'order_number'=>$order['alfa_order_number'],'status_code'=>2,'currency'=>'643',
  'amount_minor'=>(int)$amount,'verified_at'=>date('c'));
}
