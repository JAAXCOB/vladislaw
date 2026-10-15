<?php
if(!function_exists('av_read_orders'))require_once __DIR__.'/config.php';
if(!function_exists('av_partner_orders'))require_once __DIR__.'/partner_orders_helpers.php';

function av_assignment_active_statuses(){return array('offered','assigned','enroute','arrived','loading','in_transit');}
function av_assignment_for_worker($workerId,$excludeType='',$excludeId=''){
    foreach(av_read_orders() as $o){
        if(($o['assigned_worker_id']??'')!==$workerId)continue;
        if($excludeType==='normal'&&($o['id']??'')===$excludeId)continue;
        if(in_array($o['status']??'',av_assignment_active_statuses(),true))return array('type'=>'normal','order'=>$o);
    }
    foreach(av_partner_orders() as $o){
        if(($o['assigned_worker_id']??'')!==$workerId)continue;
        if($excludeType==='partner'&&($o['id']??'')===$excludeId)continue;
        if(($o['status']??'')==='claimed')return array('type'=>'partner','order'=>$o);
    }
    return null;
}
function av_assignment_queued_for_worker($workerId,$excludeType='',$excludeId=''){
    foreach(av_read_orders() as $o)if(($o['assigned_worker_id']??'')===$workerId&&($o['status']??'')==='queued'&&!($excludeType==='normal'&&($o['id']??'')===$excludeId))return array('type'=>'normal','order'=>$o);
    foreach(av_partner_orders() as $o)if(($o['assigned_worker_id']??'')===$workerId&&($o['status']??'')==='queued'&&!($excludeType==='partner'&&($o['id']??'')===$excludeId))return array('type'=>'partner','order'=>$o);
    return null;
}
function av_assignment_effective_worker_status($workerId,$storedStatus='offline'){
    $reserved=false;
    foreach(av_read_orders() as $order){
        if(($order['assigned_worker_id']??'')!==$workerId)continue;
        $status=(string)($order['status']??'');
        if(in_array($status,array('assigned','enroute','arrived','loading','in_transit'),true))return 'busy';
        if(in_array($status,array('offered','queued'),true))$reserved=true;
    }
    foreach(av_partner_orders() as $order){
        if(($order['assigned_worker_id']??'')!==$workerId)continue;
        if(($order['status']??'')==='claimed')return 'busy';
        if(($order['status']??'')==='queued')$reserved=true;
    }
    if($reserved)return 'reserved';
    return in_array($storedStatus,array('busy','reserved'),true)?'online':$storedStatus;
}
function av_assignment_destination($active){
    if(!$active)return array(0,0);
    $o=$active['order'];
    return array((float)($o['destination_lat']??0),(float)($o['destination_lng']??0));
}
function av_assignment_decision($worker,$targetType='',$targetId=''){
    // Old MAX/B2B orders are route-chain targets, not a global stop switch.
    // The checks below still enforce exactly one active order and allow the
    // next order only while the driver is within 5 km of point B.
    $wid=(string)($worker['id']??'');
    $active=av_assignment_for_worker($wid,$targetType,$targetId);
    if(!$active)return array('ok'=>true,'mode'=>'active','remaining_km'=>null,'active'=>null);
    if(av_assignment_queued_for_worker($wid,$targetType,$targetId))return array('ok'=>false,'error'=>'NEXT_ORDER_ALREADY_QUEUED','active'=>$active);
    if($active['type']==='normal'&&(($active['order']['status']??'')!=='in_transit'))return array('ok'=>false,'error'=>'WORKER_BUSY','active'=>$active);
    list($dlat,$dlng)=av_assignment_destination($active);
    $wlat=(float)($worker['lat']??0);$wlng=(float)($worker['lng']??0);
    if(!$dlat||!$dlng||!$wlat||!$wlng)return array('ok'=>false,'error'=>'WORKER_BUSY','active'=>$active);
    $remaining=round(av_haversine_km($wlat,$wlng,$dlat,$dlng),2);
    if($remaining>5.0)return array('ok'=>false,'error'=>'WORKER_BUSY','remaining_km'=>$remaining,'active'=>$active);
    return array('ok'=>true,'mode'=>'queued','remaining_km'=>$remaining,'active'=>$active,'destination_lat'=>$dlat,'destination_lng'=>$dlng);
}
function av_assignment_mark_queue(&$order,$worker,$decision,$accepted=false){
    $order['assigned_worker_id']=$worker['id'];$order['assigned_worker_name']=$worker['name']??'';
    $order['status']='queued';$order['queued_at']=date('c');$order['queue_remaining_to_b_km']=$decision['remaining_km']??null;
    if($accepted)$order['queue_accepted_at']=date('c');else unset($order['queue_accepted_at']);
}
function av_assignment_promote_queue($workerId){
    // A queued order may only be promoted after every active order for the
    // worker is terminal. This also makes repeated reconciliation harmless.
    if(av_assignment_for_worker($workerId)){
        av_assignment_sync_worker_status($workerId);
        return null;
    }
    $workers=av_read_workers();$wi=null;foreach($workers as $i=>$w)if(($w['id']??'')===$workerId){$wi=$i;break;}
    $normal=av_read_orders();foreach($normal as $i=>$o)if(($o['assigned_worker_id']??'')===$workerId&&($o['status']??'')==='queued'){
        $accepted=!empty($o['queue_accepted_at']);$normal[$i]['status']=$accepted?'assigned':'offered';$normal[$i]['queue_promoted_at']=date('c');
        if($accepted){$normal[$i]['worker_accepted_at']=$o['queue_accepted_at'];}
        else{$normal[$i]['offer_created_at']=date('c');$normal[$i]['offer_timeout_seconds']=max(30,(int)($o['offer_timeout_seconds']??300));}
        if($wi!==null)$workers[$wi]['status']=$accepted?'busy':'reserved';
        av_write_orders($normal);if($wi!==null)av_write_workers($workers);return array('type'=>'normal','order'=>$normal[$i]);
    }
    $partner=av_partner_orders();foreach($partner as $i=>$o)if(($o['assigned_worker_id']??'')===$workerId&&($o['status']??'')==='queued'){
        if(empty($o['queue_accepted_at'])){$partner[$i]['status']='available';$partner[$i]['assigned_worker_id']='';$partner[$i]['assigned_worker_name']='';}
        else{$partner[$i]['status']='claimed';$partner[$i]['claimed_at']=date('c');$partner[$i]['driver_stage']='to_pickup';if($wi!==null)$workers[$wi]['status']='busy';}
        $partner[$i]['queue_promoted_at']=date('c');av_write_partner_orders($partner);if($wi!==null)av_write_workers($workers);return array('type'=>'partner','order'=>$partner[$i]);
    }
    return null;
}
function av_assignment_sync_worker_status($workerId){
    if($workerId==='')return;
    $workers=av_read_workers();foreach($workers as $i=>$w)if(($w['id']??'')===$workerId){
        $current=(string)($workers[$i]['status']??'offline');$next=av_assignment_effective_worker_status($workerId,$current);
        if($next!==$current){$workers[$i]['status']=$next;av_write_workers($workers);}
        return $next;
    }
}
function av_assignment_after_terminal($workerId){
    if($workerId==='')return null;
    if(av_assignment_for_worker($workerId)){av_assignment_sync_worker_status($workerId);return null;}
    $promoted=av_assignment_promote_queue($workerId);
    av_assignment_sync_worker_status($workerId);
    return $promoted;
}
function av_assignment_reconcile_all(){
    foreach(av_read_workers() as $worker){
        $workerId=(string)($worker['id']??'');if($workerId==='')continue;
        if(!av_assignment_for_worker($workerId)&&av_assignment_queued_for_worker($workerId))av_assignment_promote_queue($workerId);
        av_assignment_sync_worker_status($workerId);
    }
}
