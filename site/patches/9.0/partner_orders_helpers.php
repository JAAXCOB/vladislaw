<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/dispatch_helpers.php';
require_once __DIR__.'/api/geo_provider.php';

define('AV_PARTNER_ORDERS_FILE', __DIR__.'/data/partner_orders.php');
define('AV_PARTNER_INTEGRATION_FILE', __DIR__.'/data/partner_integration.php');
define('AV_PARTNER_LOCK_FILE', __DIR__.'/data/partner_orders.lock');
define('AV_PARTNER_PRIORITY_AGE_SECONDS', 12*60*60);
define('AV_PARTNER_RADIUS_STAGE_SECONDS', 5*60);
define('AV_PARTNER_RADIUS_FIRST_KM', 30);
define('AV_PARTNER_RADIUS_SECOND_KM', 60);

function av_partner_is_max_order($order){
    return is_array($order)&&($order['source']??'')==='max_partner';
}

function av_partner_order_created_timestamp($order){
    $stamp=trim((string)($order['created_at']??''));
    if($stamp==='')return null;
    $time=strtotime($stamp);
    return $time===false?null:$time;
}

function av_partner_order_age_seconds($order,$now=null){
    $created=av_partner_order_created_timestamp($order);
    if($created===null)return null;
    $now=$now===null?time():(int)$now;
    return max(0,$now-$created);
}

function av_partner_order_is_open($order){
    return !in_array($order['status']??'available',array('closed','cancelled','canceled','rejected'),true);
}

function av_partner_order_is_overdue($order,$now=null){
    if(!av_partner_is_max_order($order)||!av_partner_order_is_open($order))return false;
    $age=av_partner_order_age_seconds($order,$now);
    return $age!==null&&$age>=AV_PARTNER_PRIORITY_AGE_SECONDS;
}

function av_partner_order_is_priority($order,$now=null){
    if(!av_partner_order_is_open($order))return false;
    if(array_key_exists('priority_override',$order))return (bool)$order['priority_override'];
    return !empty($order['manual_priority'])||av_partner_order_is_overdue($order,$now);
}

function av_partner_order_is_unassigned($order){
    return trim((string)($order['assigned_worker_id']??''))==='';
}

function av_partner_priority_state($orders=null,$now=null){
    if($orders===null)$orders=av_partner_orders();
    $now=$now===null?time():(int)$now;
    $priorityOrders=array();$blocking=array();
    foreach($orders as $order){
        if(!av_partner_order_is_priority($order,$now))continue;
        $item=array(
            'id'=>(string)($order['id']??''),
            'age_seconds'=>av_partner_order_age_seconds($order,$now),
            'assigned_worker_id'=>(string)($order['assigned_worker_id']??''),
            'manual'=>!empty($order['manual_priority'])||array_key_exists('priority_override',$order)
        );
        $priorityOrders[]=$item;
        if(av_partner_order_is_unassigned($order))$blocking[]=$item;
    }
    $sort=fn($a,$b)=>(int)$b['manual']<=>(int)$a['manual'] ?: (int)$b['age_seconds']<=>(int)$a['age_seconds'];
    usort($priorityOrders,$sort);usort($blocking,$sort);
    return array(
        'active'=>count($blocking)>0,
        'threshold_hours'=>12,
        'overdue_count'=>count(array_filter($priorityOrders,fn($x)=>empty($x['manual']))),
        'priority_count'=>count($priorityOrders),
        'unassigned_count'=>count($blocking),
        'blocking_order_ids'=>array_values(array_column($blocking,'id')),
        'oldest_unassigned'=>$blocking[0]??null
    );
}

function av_partner_enrich_order($order,$now=null){
    $now=$now===null?time():(int)$now;
    $age=av_partner_order_age_seconds($order,$now);
    $order['age_seconds']=$age;
    $order['age_minutes']=$age===null?null:(int)floor($age/60);
    $order['is_overdue']=av_partner_order_is_overdue($order,$now);
    $order['is_manual_priority']=av_partner_order_is_open($order)&&((array_key_exists('priority_override',$order)&&(bool)$order['priority_override'])||!empty($order['manual_priority']));
    $order['priority_auto_suppressed']=av_partner_order_is_open($order)&&array_key_exists('priority_override',$order)&&!(bool)$order['priority_override'];
    $order['is_priority']=av_partner_order_is_priority($order,$now);
    $order['priority_unassigned']=$order['is_priority']&&av_partner_order_is_unassigned($order);
    return $order;
}

function av_partner_normalize_order_destination($order){
    if(!is_array($order))return array();
    $service=trim((string)($order['service']??''));
    $destination=trim((string)($order['destination']??''));
    if(in_array($service,array('','Эвакуация'),true)
        &&preg_match('/^(.+?)\s*\(([^()]*)\)\s*$/u',$destination,$parts)){
        $destinationService=trim($parts[1]);
        $destinationAddress=trim($parts[2]);
        if($destinationService!==''&&$destinationAddress!==''){
            $order['service']=$destinationService;
            $order['destination']=$destinationAddress;
        }
    }
    return $order;
}

function av_partner_orders(){
    $orders=av_secure_read(AV_PARTNER_ORDERS_FILE);
    foreach($orders as $index=>$order)$orders[$index]=av_partner_normalize_order_destination($order);
    return $orders;
}

function av_write_partner_orders($orders){
    return av_secure_write(AV_PARTNER_ORDERS_FILE,array_values($orders));
}

function av_partner_lock(){
    $handle=@fopen(AV_PARTNER_LOCK_FILE,'c');
    if(!$handle||!flock($handle,LOCK_EX))return null;
    return $handle;
}

function av_partner_integration_config(){
    return av_secure_read(AV_PARTNER_INTEGRATION_FILE);
}

function av_partner_sync_key(){
    $env=getenv('AV_PARTNER_SYNC_KEY');
    if(is_string($env)&&trim($env)!=='')return trim($env);
    $config=av_partner_integration_config();
    return trim((string)($config['api_key']??''));
}

function av_partner_require_sync_key(){
    $expected=av_partner_sync_key();
    if($expected==='')av_json_response(array('ok'=>false,'error'=>'PARTNER_INTEGRATION_NOT_CONFIGURED'),503);
    $provided=trim((string)($_SERVER['HTTP_X_AVR_PARTNER_KEY']??''));
    if($provided===''||!hash_equals($expected,$provided)){
        av_json_response(array('ok'=>false,'error'=>'PARTNER_AUTH_REQUIRED'),403);
    }
}

function av_partner_normalize_plate($plate){
    $plate=mb_strtoupper(trim((string)$plate),'UTF-8');
    $plate=preg_replace('/[^A-ZА-ЯЁ0-9]/u','',$plate);
    // MAX users may type visually identical Russian letters while a source
    // system stores Latin ones. Canonicalise all letters valid on RU plates.
    return strtr($plate,array('А'=>'A','В'=>'B','Е'=>'E','К'=>'K','М'=>'M','Н'=>'H','О'=>'O','Р'=>'P','С'=>'C','Т'=>'T','У'=>'Y','Х'=>'X'));
}

function av_partner_number_or_null($value,$min,$max){
    if($value===null||$value==='')return null;
    if(!is_numeric($value))return null;
    $number=(float)$value;
    return ($number<$min||$number>$max)?null:$number;
}

function av_partner_current_worker($webToken=''){
    $webToken=trim((string)$webToken);
    if($webToken!==''){
        foreach(av_read_workers() as $worker){
            if(!empty($worker['token'])&&hash_equals((string)$worker['token'],$webToken))return $worker;
        }
    }
    $principal=av_mobile_principal();
    if(!$principal)return null;
    $type=$principal['principal_type']??'user';
    $id=(string)($principal['principal_id']??$principal['user_id']??'');
    if($type==='executor'){
        foreach(av_read_workers() as $worker)if(($worker['id']??'')===$id)return $worker;
    }
    if($type==='user')return av_worker_by_user_id($id);
    return null;
}

function av_partner_distribution_radius_km($order,$now=null){
    // Partner/MAX orders are currently distributed only to approved drivers
    // of the AV Rescue fleet.  The approved rule for that fleet has no radius
    // limit; distance is used strictly for nearest-first sorting.
    return null;
}

function av_partner_distribution_stage($order,$now=null){
    return 'own_fleet_unlimited';
}

function av_partner_feed_for_worker($worker){
    $empty=array('orders'=>array(),'counts'=>array('total_open'=>0,'available'=>0,'eligible_available'=>0,'waiting_radius'=>0,'assigned_to_me'=>0,'busy'=>0));
    if(!$worker||empty($worker['approved'])||!av_worker_is_own_fleet($worker))return $empty;
    if(!in_array($worker['status']??'offline',array('online','reserved','busy'),true))return $empty;
    $workerId=(string)($worker['id']??'');
    $locationStamp=(string)($worker['location_captured_at']??$worker['last_seen']??'');
    $locationTime=$locationStamp!==''?strtotime($locationStamp):false;
$locationAge=$locationTime===false?PHP_INT_MAX:max(0,time()-$locationTime);
$locationFresh=$locationTime!==false&&$locationTime<=time()+120&&$locationAge<=180;
// Keep nearest-first ordering during a temporary mobile GPS pause.
$locationUsable=$locationTime!==false&&$locationTime<=time()+120&&$locationAge<=21600;
$workerLat=$locationUsable?av_partner_number_or_null($worker['lat']??null,-90,90):null;
$workerLng=$locationUsable?av_partner_number_or_null($worker['lng']??null,-180,180):null;
    $allOrders=av_partner_orders();
    $now=time();
    $priority=av_partner_priority_state($allOrders,$now);
    $blockingIds=array_flip($priority['blocking_order_ids']);
    $mine=array();$available=array();$counts=$empty['counts'];$candidates=array();$routeTargets=array();
    foreach($allOrders as $orderIndex=>$order){
        $order=av_partner_enrich_order($order,$now);
        $status=$order['status']??'available';
        if(in_array($status,array('closed','cancelled'),true))continue;
        $lat=av_partner_number_or_null($order['pickup_lat']??null,-90,90);
        $lng=av_partner_number_or_null($order['pickup_lng']??null,-180,180);
        $assignedId=(string)($order['assigned_worker_id']??'');
        $isMine=$assignedId!==''&&$assignedId===$workerId;
        $counts['total_open']++;
        if($isMine)$counts['assigned_to_me']++;
        elseif($status==='available'&&$assignedId==='')$counts['available']++;
        else{$counts['busy']++;continue;}
        $candidateKey=(string)$orderIndex;
        $candidates[$candidateKey]=array('order'=>$order,'status'=>$status,'lat'=>$lat,'lng'=>$lng,'assigned_id'=>$assignedId,'is_mine'=>$isMine);
        if($lat!==null&&$lng!==null&&$workerLat!==null&&$workerLng!==null)$routeTargets[$candidateKey]=array('lat'=>$lat,'lng'=>$lng);
    }
    $routeMetrics=($workerLat!==null&&$workerLng!==null)?av_geo_route_matrix_from_origin($workerLat,$workerLng,$routeTargets,30):array();
    foreach($candidates as $candidateKey=>$candidate){
        $order=$candidate['order'];$status=$candidate['status'];$lat=$candidate['lat'];$lng=$candidate['lng'];$assignedId=$candidate['assigned_id'];$isMine=$candidate['is_mine'];
        $metrics=$routeMetrics[$candidateKey]??array('distance_km'=>null,'eta_min'=>null,'source'=>'missing');
        $distance=$metrics['distance_km'];
        $radius=av_partner_distribution_radius_km($order,$now);
        $withinRadius=true;
        $priorityAllowed=!$priority['active']||isset($blockingIds[(string)($order['id']??'')]);
        $canClaim=$status==='claimed'&&$isMine;
        if($status==='available'&&$withinRadius&&$priorityAllowed)$canClaim=true;
        if(!$isMine){
            if($withinRadius)$counts['eligible_available']++;
            else $counts['waiting_radius']++;
        }
        $row=array(
            'id'=>$order['id']??'',
            'service'=>$order['service']??'Эвакуация',
            'vehicle'=>$order['vehicle']??'',
            'license_plate'=>$order['license_plate']??'',
            'pickup_address'=>$order['pickup_address']??'',
            'pickup_lat'=>$lat,
            'pickup_lng'=>$lng,
            'destination'=>$isMine?($order['destination']??''):'',
            'destination_lat'=>$isMine?av_partner_number_or_null($order['destination_lat']??null,-90,90):null,
            'destination_lng'=>$isMine?av_partner_number_or_null($order['destination_lng']??null,-180,180):null,
            'service_until'=>$order['service_until']??'',
            'phone'=>$isMine?($order['phone']??''):'',
            'comment'=>$isMine?($order['comment']??''):'',
            'status'=>$status,
            'assigned_worker_id'=>$isMine?$assignedId:'',
            'is_mine'=>$isMine,
            'driver_stage'=>$isMine?($order['driver_stage']??'to_pickup'):'',
            'can_claim'=>$canClaim,
            'claim_blocked'=>$status==='available'&&$priority['active']&&!isset($blockingIds[(string)($order['id']??'')]),
            'age_seconds'=>$order['age_seconds'],
            'age_minutes'=>$order['age_minutes'],
            'is_overdue'=>$order['is_overdue'],
            'is_priority'=>$order['is_priority'],
            'priority_unassigned'=>$order['priority_unassigned'],
            'distance_km'=>$distance,
            'eta_min'=>$metrics['eta_min'],
            'distance_source'=>$metrics['source'],
            'distribution_radius_km'=>$radius,
            'distribution_stage'=>av_partner_distribution_stage($order,$now),
            'eligible_by_radius'=>$withinRadius,
            'location_status'=>$locationFresh?'fresh':($locationUsable?'last_known':'missing'),
            'created_at'=>$order['created_at']??''
        );
        if($isMine)$mine[]=$row;elseif($withinRadius)$available[]=$row;
    }
    $sort=function($a,$b){
        if($a['is_priority']!==$b['is_priority'])return $a['is_priority']?-1:1;
        if($a['is_priority']&&$a['age_seconds']!==$b['age_seconds'])return (int)$b['age_seconds']<=>(int)$a['age_seconds'];
        $ad=$a['distance_km'];$bd=$b['distance_km'];
        if(($ad===null)!==($bd===null))return $ad===null?1:-1;
        if($ad!==null&&$bd!==null&&$ad!=$bd)return $ad<=>$bd;
        return strcmp((string)($b['created_at']??''),(string)($a['created_at']??''));
    };
    usort($mine,$sort);usort($available,$sort);
    // A driver receives exactly one actionable row: their own active job, or the
    // nearest available one. Everything else is represented only by counters.
    $visible=!empty($mine)?$mine:$available;
    return array('orders'=>$visible,'counts'=>$counts);
}

function av_partner_claim_eligibility($worker,$orderId){
    $orderId=(string)$orderId;
    $feed=av_partner_feed_for_worker($worker);
    $visible=null;foreach($feed["orders"] as $candidate)if((string)($candidate["id"]??"")===$orderId){$visible=$candidate;break;}
    if(is_array($visible)&&(string)($visible['id']??'')===$orderId&&!empty($visible['can_claim']))return array('ok'=>true,'order'=>$visible);
    $requested=null;
    foreach(av_partner_orders() as $order)if((string)($order['id']??'')===$orderId){$requested=$order;break;}
    if(!$requested)return array('ok'=>false,'error'=>'ORDER_NOT_FOUND');
    if((string)($requested['assigned_worker_id']??'')===(string)($worker['id']??''))return array('ok'=>true,'order'=>$requested);
    return array('ok'=>false,'error'=>'NOT_ELIGIBLE');
}

function av_partner_orders_for_worker($worker){
    $feed=av_partner_feed_for_worker($worker);return $feed['orders'];
}

function av_partner_order_history_for_worker($worker,$limit=100){
    if(!$worker||!av_worker_is_own_fleet($worker))return array();
    $workerId=(string)($worker['id']??'');
    $result=array();
    foreach(av_partner_orders() as $order){
        if(!in_array($order['status']??'',array('closed','cancelled'),true))continue;
        if((string)($order['assigned_worker_id']??'')!==$workerId)continue;
        $result[]=array(
            'id'=>$order['id']??'',
            'service'=>$order['service']??'Эвакуация',
            'vehicle'=>$order['vehicle']??'',
            'license_plate'=>$order['license_plate']??'',
            'pickup_address'=>$order['pickup_address']??'',
            'pickup_lat'=>av_partner_number_or_null($order['pickup_lat']??null,-90,90),
            'pickup_lng'=>av_partner_number_or_null($order['pickup_lng']??null,-180,180),
            'destination'=>$order['destination']??'',
            'destination_lat'=>av_partner_number_or_null($order['destination_lat']??null,-90,90),
            'destination_lng'=>av_partner_number_or_null($order['destination_lng']??null,-180,180),
            'phone'=>$order['phone']??'',
            'comment'=>$order['comment']??'',
            'status'=>$order['status']??'closed',
            'claimed_at'=>$order['claimed_at']??'',
            'arrived_at'=>$order['arrived_at']??'',
            'closed_at'=>$order['closed_at']??'',
            'updated_at'=>$order['updated_at']??''
        );
    }
    usort($result,fn($a,$b)=>strcmp($b['closed_at']?:$b['updated_at'],$a['closed_at']?:$a['updated_at']));
    return array_slice($result,0,max(1,(int)$limit));
}
