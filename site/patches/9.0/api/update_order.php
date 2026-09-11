<?php
require_once __DIR__ . '/../webpush.php';
av_require_dispatcher_api();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') av_json_response(array('ok'=>false,'error'=>'Метод не поддерживается'),405);
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$id=trim((string)($input['id']??''));$status=trim((string)($input['status']??''));$note=trim((string)($input['note']??''));
$allowed=array('new','searching','assigned','enroute','arrived','done','cancelled');
if(!$id||!in_array($status,$allowed,true))av_json_response(array('ok'=>false,'error'=>'Некорректные данные'),422);
$orders=av_read_orders();$workers=av_read_workers();$found=false;$workersChanged=false;$workerId='';
foreach($orders as $k=>$o){
 if(($o['id']??'')!==$id)continue;
 $current=$o['status']??'';
 $valid=($status==='cancelled'&&!in_array($current,array('done','completed','cancelled'),true))||($current==='assigned'&&$status==='enroute')||($current==='enroute'&&$status==='arrived')||(in_array($current,array('arrived','in_transit'),true)&&$status==='done')||in_array($status,array('new','searching'),true);
 if(!$valid)av_json_response(array('ok'=>false,'error'=>'Этот переход статуса недоступен'),422);
 $orders[$k]['status']=$status;$orders[$k]['dispatcher_note']=$note;$orders[$k]['updated_at']=date('c');$workerId=$o['assigned_worker_id']??'';$worker=null;
 if($status==='cancelled'){$orders[$k]['cancelled_by']='dispatcher';$orders[$k]['cancelled_at']=date('c');}
 if(in_array($status,array('cancelled','done'),true)&&$workerId){foreach($workers as $wi=>$w)if(($w['id']??'')===$workerId){$workers[$wi]['status']='online';$worker=$workers[$wi];$workersChanged=true;break;}}
 if($status==='done'){
  $orders[$k]['completed_at']=date('c');$f=av_order_financials($orders[$k],$worker);
  $orders[$k]['final_price']=$f['gross'];$orders[$k]['commission_rate']=$f['commission_rate'];$orders[$k]['payout_rate']=$f['payout_rate'];$orders[$k]['commission_amount']=$f['commission'];$orders[$k]['worker_payout']=$f['driver_net'];$orders[$k]['loyalty_level']=$f['loyalty_level'];$orders[$k]['loyalty_name']=$f['loyalty_name'];
  if(!empty($f['excluded']))$orders[$k]['finance_posted']='b2b_separate';
  elseif($workerId){$cash=($orders[$k]['payment_method']??'cash')==='cash';$orders[$k]['ledger_type']=$cash?'commission_debit':'driver_credit';$orders[$k]['ledger_amount']=$cash?-$f['commission']:$f['driver_net'];av_finance_record_order($orders[$k],$f);av_ledger_add_once($workerId,$id,$orders[$k]['ledger_type'],$orders[$k]['ledger_amount'],$cash?'Комиссия за наличный заказ':'Оплата картой за заказ');$orders[$k]['finance_posted']=true;}
 }
 $found=true;break;
}
if(!$found)av_json_response(array('ok'=>false,'error'=>'Заявка не найдена'),404);
if(!av_write_orders($orders)||($workersChanged&&!av_write_workers($workers)))av_json_response(array('ok'=>false,'error'=>'Ошибка записи'),500);
if($status==='cancelled'&&$workerId)av_push_worker($workerId,'Заказ отменён','Заказ '.$id.' отменён диспетчером','/portal.php?source=push','cancel-'.$id);
av_json_response(array('ok'=>true));
