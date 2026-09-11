<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();
if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
av_require_post();

$input=json_decode(file_get_contents('php://input'),true);if(!is_array($input))$input=$_POST;
$workerId=trim((string)($input['worker_id']??''));$enabled=!empty($input['enabled']);$workers=av_read_workers();$staff=av_staff_user();
foreach($workers as &$worker)if((string)($worker['id']??'')===$workerId){if(empty($worker['approved']))av_json_response(array('ok'=>false,'error'=>'Сначала подтвердите регистрацию'),422);$worker['own_fleet']=$enabled;$worker['own_fleet_updated_at']=date('c');$worker['own_fleet_updated_by']=$staff['id']??'';unset($worker);if(!av_write_workers($workers))av_json_response(array('ok'=>false,'error'=>'SAVE_FAILED'),500);av_audit($enabled?'worker_added_to_own_fleet':'worker_removed_from_own_fleet',$workerId);av_json_response(array('ok'=>true));}unset($worker);
av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);
