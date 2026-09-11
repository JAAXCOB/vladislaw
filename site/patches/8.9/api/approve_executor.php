<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
av_require_post();$i=json_decode(file_get_contents('php://input'),true);$id=trim((string)($i['id']??''));$ws=av_read_workers();$staff=av_staff_user();
foreach($ws as $k=>$w)if((string)($w['id']??'')===$id){$ws[$k]['approved']=true;$ws[$k]['review_status']='approved';$ws[$k]['approved_at']=date('c');$ws[$k]['approved_by']=$staff['id']??'';$ws[$k]['status']='offline';unset($ws[$k]['rejection_reason'],$ws[$k]['rejected_at'],$ws[$k]['rejected_by']);if(!av_write_workers($ws))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);$apps=av_executor_apps();foreach($apps as &$app)if((string)($app['id']??'')===$id){$app['status']='approved';$app['approved_at']=date('c');$app['approved_by']=$staff['id']??'';unset($app['rejection_reason'],$app['rejected_at'],$app['rejected_by']);}unset($app);av_write_executor_apps($apps);av_audit('executor_approved',$id);av_json_response(array('ok'=>true));}
av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);
