<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();
if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);

$id=trim((string)($_GET['id']??''));
foreach(av_read_workers() as $worker){
 if((string)($worker['id']??'')!==$id)continue;
 unset($worker['password_hash'],$worker['token']);
 av_json_response(array('ok'=>true,'worker'=>$worker));
}
av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);
