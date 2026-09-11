<?php
require_once __DIR__.'/../config.php';av_require_staff_api();if(!av_staff_can_admin())av_json_response(array('ok'=>false),403);
$apps=av_executor_apps();
if($_SERVER['REQUEST_METHOD']==='POST'){
 $i=json_decode(file_get_contents('php://input'),true);$id=trim($i['id']??'');$action=$i['action']??'';
 foreach($apps as $k=>$a)if(($a['id']??'')===$id){
  if($action==='approve'){
   $apps[$k]['status']='approved';$apps[$k]['approved_at']=date('c');
   $users=av_read_users();foreach($users as $uk=>$u)if(($u['id']??'')===($a['user_id']??'')){$users[$uk]['executor_role']=true;break;}av_write_users($users);
   $workers=av_read_workers();$exists=false;foreach($workers as $w)if(($w['user_id']??'')===($a['user_id']??''))$exists=true;
   if(!$exists){$workers[]=array('id'=>'WRK-'.substr(bin2hex(random_bytes(5)),0,10),'user_id'=>$a['user_id'],'name'=>$a['name']??'','phone'=>$a['phone']??'','vehicle'=>$a['vehicle']??'','plate'=>$a['plate']??'','equipment_type'=>$a['equipment_type']??'flatbed','capacity'=>$a['capacity']??0,'approved'=>true,'online'=>false,'rating'=>5,'commission_rate'=>av_platform_settings()['default_commission_percent']??10,'token'=>bin2hex(random_bytes(24)),'created_at'=>date('c'));av_write_workers($workers);}
   av_audit('executor_approved',$id,array('user_id'=>$a['user_id']??''));
  }elseif($action==='reject'){$apps[$k]['status']='rejected';$apps[$k]['rejected_at']=date('c');av_audit('executor_rejected',$id);}
  av_write_executor_apps($apps);av_json_response(array('ok'=>true));
 }
 av_json_response(array('ok'=>false,'error'=>'Заявка не найдена'),404);
}
av_json_response(array('ok'=>true,'applications'=>array_reverse($apps)));
