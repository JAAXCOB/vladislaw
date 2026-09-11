<?php
require_once __DIR__.'/../config.php';
$p=av_mobile_principal();if(!$p)av_json_response(array('ok'=>false,'error'=>'AUTH_REQUIRED'),401);
$type=$p['principal_type']??'';$role='';$pid=(string)($p['principal_id']??$p['user_id']??'');
if($type==='superadmin')$role='superadmin';elseif($type==='staff'){foreach(av_read_staff() as $s)if(($s['id']??'')===$pid){$role=$s['role']??'';break;}}elseif($type==='user'){$u=av_user_by_id($pid);$role=$u['staff_role']??'';}
if(!in_array($role,array('superadmin','admin'),true))av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
if($_SERVER['REQUEST_METHOD']==='GET')av_json_response(array('ok'=>true,'applications'=>av_executor_apps(),'b2b'=>av_read_b2b()));
av_require_post();$in=json_decode(file_get_contents('php://input'),true);$action=$in['action']??'';$id=$in['id']??'';
if($action==='approve_executor'){$workers=av_read_workers();$found=false;$approvedAt=date('c');foreach($workers as &$worker)if(($worker['id']??'')===$id){$worker['approved']=true;$worker['review_status']='approved';$worker['approved_at']=$approvedAt;$worker['approved_by']=$pid;$worker['status']='offline';unset($worker['rejection_reason'],$worker['rejected_at'],$worker['rejected_by']);$found=true;}unset($worker);if(!$found)av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);if(!av_write_workers($workers))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);$apps=av_executor_apps();foreach($apps as &$app)if(($app['id']??'')===$id){$app['status']='approved';$app['approved_at']=$approvedAt;$app['approved_by']=$pid;unset($app['rejection_reason'],$app['rejected_at'],$app['rejected_by']);}unset($app);av_write_executor_apps($apps);if(function_exists('av_audit'))av_audit('executor_approved',$id);av_json_response(array('ok'=>true));}
if($action==='approve_b2b'){$partners=av_read_b2b();$found=false;foreach($partners as &$partner)if(($partner['id']??'')===$id){$partner['approved']=true;$partner['review_status']='approved';$partner['approved_at']=date('c');$partner['approved_by']=$pid;$found=true;}unset($partner);if(!$found)av_json_response(array('ok'=>false,'error'=>'B2B-аккаунт не найден'),404);if(!av_write_b2b($partners))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);if(function_exists('av_audit'))av_audit('b2b_approved',$id);av_json_response(array('ok'=>true));}
if($action==='delete_user'){
 $users=av_read_users();$target=null;
 foreach($users as $user)if((string)($user['id']??'')===(string)$id){$target=$user;break;}
 if(!$target)av_json_response(array('ok'=>false,'error'=>'NOT_FOUND'),404);
 if(!empty($target['staff_role']))av_json_response(array('ok'=>false,'error'=>'Сначала снимите роль сотрудника'),422);
 if(!empty($target['executor_role'])||av_worker_by_user_id((string)$id))av_json_response(array('ok'=>false,'error'=>'Сначала удалите связанный аккаунт водителя'),422);
 foreach(av_read_orders() as $order){$status=(string)($order['status']??'');if((string)($order['user_id']??'')===(string)$id&&!in_array($status,array('done','completed','cancelled','closed'),true))av_json_response(array('ok'=>false,'error'=>'Нельзя удалить клиента с активной заявкой'),409);}
 $users=array_values(array_filter($users,fn($user)=>(string)($user['id']??'')!==(string)$id));
 $blocks=array_values(array_filter(av_read_blocks(),fn($block)=>(string)($block['user_id']??'')!==(string)$id));
 $tokens=array_values(array_filter(av_mobile_tokens_read(),function($token)use($id){$tokenType=(string)($token['principal_type']??'user');$tokenId=(string)($token['principal_id']??$token['user_id']??'');return !($tokenType==='user'&&$tokenId===(string)$id);}));
 if(!av_write_users($users)||!av_write_blocks($blocks)||!av_mobile_tokens_write($tokens))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);
 av_audit('client_account_deleted',(string)$id,array('source'=>'mobile_registration_admin'));
 av_json_response(array('ok'=>true));
}
av_json_response(array('ok'=>false,'error'=>'UNKNOWN_ACTION'),422);
