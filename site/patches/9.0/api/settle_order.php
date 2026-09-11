<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();
$i=json_decode(file_get_contents('php://input'),true);if(!is_array($i))$i=array();$id=trim((string)($i['order_id']??''));
$orders=av_read_orders();$o=null;$idx=null;foreach($orders as $k=>$x)if(($x['id']??'')===$id){$o=$x;$idx=$k;break;}
if(!$o)av_json_response(array('ok'=>false),404);
if(!in_array($o['status']??'',array('done','completed'),true))av_json_response(array('ok'=>false,'error'=>'Заказ не завершён'),422);
if(!empty($o['settled_at']))av_json_response(array('ok'=>false,'error'=>'Уже рассчитан'),409);
$wid=$o['assigned_worker_id']??'';$worker=$wid?av_worker_by_id($wid):null;$f=av_order_financials($o,$worker);
$orders[$idx]['final_price']=$f['gross'];$orders[$idx]['commission_rate']=$f['commission_rate'];$orders[$idx]['payout_rate']=$f['payout_rate'];$orders[$idx]['commission_amount']=$f['commission'];$orders[$idx]['worker_payout']=$f['driver_net'];$orders[$idx]['loyalty_level']=$f['loyalty_level'];$orders[$idx]['loyalty_name']=$f['loyalty_name'];
if(!empty($f['excluded']))$orders[$idx]['finance_posted']='b2b_separate';
elseif($wid){$cash=($o['payment_method']??'cash')==='cash';$amount=$cash?-$f['commission']:$f['driver_net'];$orders[$idx]['ledger_type']=$cash?'commission_debit':'driver_credit';$orders[$idx]['ledger_amount']=$amount;av_finance_record_order($orders[$idx],$f);av_ledger_add_once($wid,$id,$orders[$idx]['ledger_type'],$amount,$cash?'Комиссия за наличный заказ':'Оплата картой за заказ');$orders[$idx]['finance_posted']=true;}
$orders[$idx]['settled_at']=date('c');
if(!av_write_orders($orders))av_json_response(array('ok'=>false,'error'=>'Ошибка записи'),500);
av_audit('order_settlement',$id,array('method'=>$o['payment_method']??'cash','commission'=>$f['commission'],'payout'=>$f['driver_net'],'loyalty'=>$f['loyalty_level']));
av_json_response(array('ok'=>true,'commission'=>$f['commission'],'worker_payout'=>$f['driver_net'],'driver_balance'=>$wid?av_driver_balance($wid):0,'b2b_separate'=>!empty($f['excluded'])));
