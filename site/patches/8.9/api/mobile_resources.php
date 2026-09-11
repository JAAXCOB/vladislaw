<?php
require_once __DIR__.'/../config.php';
require_once __DIR__.'/../partner_orders_helpers.php';
$p=av_mobile_principal();if(!$p)av_json_response(array('ok'=>false,'error'=>'AUTH_REQUIRED'),401);
$type=$p['principal_type']??'user';$pid=(string)($p['principal_id']??$p['user_id']??'');
$role='client';if($type==='superadmin')$role='superadmin';elseif($type==='staff'){foreach(av_read_staff() as $s)if(($s['id']??'')===$pid){$role=$s['role']??'dispatcher';break;}}elseif($type==='executor')$role='executor';elseif($type==='b2b')$role='b2b';elseif($type==='user'){$u=av_user_by_id($pid);$role=$u['staff_role']??'client';}
$isStaff=in_array($role,array('superadmin','admin','dispatcher','staff'),true);
if(!$isStaff)av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
$workers=array();foreach(av_read_workers() as $worker){if(empty($worker['approved']))continue;$workers[]=array('id'=>$worker['id']??'','name'=>$worker['name']??'','phone'=>$worker['phone']??'','vehicle_type'=>$worker['vehicle_type']??$worker['vehicle']??'','plate'=>$worker['plate']??'','capacity'=>$worker['capacity']??null,'services'=>is_array($worker['services']??null)?$worker['services']:array(),'status'=>$worker['status']??'offline','approved'=>true,'lat'=>$worker['lat']??null,'lng'=>$worker['lng']??null,'last_seen'=>$worker['last_seen']??'','rating'=>av_worker_rating($worker['id']??''),'distance_km'=>null,'online'=>in_array($worker['status']??'',array('online','busy','reserved'),true),'is_own_fleet'=>av_worker_is_own_fleet($worker));}
$out=array('ok'=>true,'role'=>$role,'workers'=>$workers,'fleet'=>av_fleet(),'partner_orders'=>av_partner_orders());
if(in_array($role,array('superadmin','admin'),true)){$out['users']=av_read_users();$out['b2b']=av_read_b2b();$out['staff']=av_read_staff();$out['platform']=av_platform_settings();}
av_json_response($out);
