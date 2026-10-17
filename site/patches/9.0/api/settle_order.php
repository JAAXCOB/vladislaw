<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();
$i=json_decode(file_get_contents('php://input'),true);if(!is_array($i))$i=array();$id=trim((string)($i['order_id']??''));
$orders=av_read_orders();$o=null;$idx=null;foreach($orders as $k=>$x)if(($x['id']??'')===$id){$o=$x;$idx=$k;break;}
if(!$o)av_json_response(array('ok'=>false),404);
if(!in_array($o['status']??'',array('done','completed'),true))av_json_response(array('ok'=>false,'error'=>'Заказ не завершён'),422);
if(!empty($o['settled_at']))av_json_response(array('ok'=>false,'error'=>'Уже рассчитан'),409);
$wid=$o['assigned_worker_id']??'';$worker=$wid?av_worker_by_id($wid):null;$f=av_order_financials($o,$worker);
$posted=av_post_order_finance($orders[$idx],$worker);
if(!$posted&&($orders[$idx]['finance_posted']??'')!=='b2b_separate')av_json_response(array('ok'=>false,'error'=>'Безналичная оплата ещё не подтверждена банком'),422);
$orders[$idx]['settled_at']=date('c');
if(!av_write_orders($orders))av_json_response(array('ok'=>false,'error'=>'Ошибка записи'),500);
av_audit('order_settlement',$id,array('method'=>$o['payment_method']??'cash','commission'=>$f['commission'],'payout'=>$f['driver_net'],'loyalty'=>$f['loyalty_level']));
av_json_response(array('ok'=>true,'commission'=>$f['commission'],'worker_payout'=>$f['driver_net'],'driver_balance'=>$wid?av_driver_balance($wid):0,'b2b_separate'=>!empty($f['excluded'])));
