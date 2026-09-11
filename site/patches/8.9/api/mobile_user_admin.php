<?php
require_once __DIR__.'/../config.php';

$principal=av_mobile_principal();
if(!$principal)av_json_response(array('ok'=>false,'error'=>'AUTH_REQUIRED'),401);
$type=$principal['principal_type']??'';$principalId=(string)($principal['principal_id']??'');$role='';
if($type==='superadmin')$role='superadmin';
elseif($type==='staff')foreach(av_read_staff() as $staff)if((string)($staff['id']??'')===$principalId){$role=$staff['role']??'';break;}
elseif($type==='user'){$current=av_user_by_id($principalId);$role=$current['staff_role']??'';}
if(!in_array($role,array('superadmin','admin'),true))av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);

av_require_post();
$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$action=(string)($input['action']??'');$id=trim((string)($input['id']??''));
$users=av_read_users();$index=null;
foreach($users as $key=>$user)if((string)($user['id']??'')===$id){$index=$key;break;}
if($index===null)av_json_response(array('ok'=>false,'error'=>'NOT_FOUND'),404);

if($action==='delete'){
 $target=$users[$index];
 if(!empty($target['staff_role']))av_json_response(array('ok'=>false,'error'=>'Сначала снимите роль сотрудника'),422);
 if(!empty($target['executor_role'])||av_worker_by_user_id($id))av_json_response(array('ok'=>false,'error'=>'Сначала удалите связанный аккаунт водителя'),422);
 foreach(av_read_orders() as $order){
  $status=(string)($order['status']??'');
  if((string)($order['user_id']??'')===$id&&!in_array($status,array('done','completed','cancelled','closed'),true))
   av_json_response(array('ok'=>false,'error'=>'Нельзя удалить клиента с активной заявкой'),409);
 }
 unset($users[$index]);
 $blocks=array_values(array_filter(av_read_blocks(),fn($block)=>(string)($block['user_id']??'')!==$id));
 $tokens=array_values(array_filter(av_mobile_tokens_read(),function($token)use($id){
  $tokenType=(string)($token['principal_type']??'user');
  $tokenId=(string)($token['principal_id']??$token['user_id']??'');
  return !($tokenType==='user'&&$tokenId===$id);
 }));
 if(!av_write_users(array_values($users))||!av_write_blocks($blocks)||!av_mobile_tokens_write($tokens))
  av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);
 av_audit('client_account_deleted',$id,array('source'=>'mobile_admin'));
 av_json_response(array('ok'=>true));
}

if($action==='block')$users[$index]['blocked']=true;
elseif($action==='unblock')$users[$index]['blocked']=false;
elseif($action==='update')foreach(array('name','phone','email','login') as $field)if(isset($input[$field]))$users[$index][$field]=trim((string)$input[$field]);
else av_json_response(array('ok'=>false,'error'=>'UNKNOWN_ACTION'),422);

if(!av_write_users(array_values($users)))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);
av_audit('client_account_'.$action,$id,array('source'=>'mobile_admin'));
av_json_response(array('ok'=>true));
