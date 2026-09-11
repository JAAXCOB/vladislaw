<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();
if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
av_require_post();

$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$id=trim((string)($input['id']??''));$reason=trim((string)($input['reason']??''));
if(mb_strlen($reason)<5||mb_strlen($reason)>1000)av_json_response(array('ok'=>false,'error'=>'Укажите причину от 5 до 1000 символов'),422);
$workers=av_read_workers();$found=false;$staff=av_staff_user();$now=date('c');
foreach($workers as &$worker)if((string)($worker['id']??'')===$id){$worker['approved']=false;$worker['review_status']='rejected';$worker['rejection_reason']=$reason;$worker['rejected_at']=$now;$worker['rejected_by']=$staff['id']??'';$worker['status']='offline';$found=true;break;}unset($worker);
if(!$found)av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);
if(!av_write_workers($workers))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);
$apps=av_executor_apps();foreach($apps as &$app)if((string)($app['id']??'')===$id){$app['status']='rejected';$app['rejection_reason']=$reason;$app['rejected_at']=$now;$app['rejected_by']=$staff['id']??'';}unset($app);av_write_executor_apps($apps);
av_audit('executor_rejected',$id);
av_json_response(array('ok'=>true));
