<?php
require_once __DIR__.'/../config.php';av_require_staff_api();if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;$id=trim((string)($in['worker_id']??''));
$allowed=array('Эвакуация','Техпомощь','Запуск двигателя','Замена колеса','Подвоз топлива','Вскрытие автомобиля');$services=isset($in['services'])&&is_array($in['services'])?array_values(array_intersect($allowed,$in['services'])):array();
if(!$id||!$services)av_json_response(array('ok'=>false,'error'=>'Выберите хотя бы одну услугу'),422);
$workers=av_read_workers();foreach($workers as $i=>$w)if(($w['id']??'')===$id){$workers[$i]['services']=$services;$workers[$i]['updated_at']=date('c');if(!av_write_workers($workers))av_json_response(array('ok'=>false,'error'=>'Ошибка сохранения'),500);av_json_response(array('ok'=>true,'services'=>$services));}
av_json_response(array('ok'=>false,'error'=>'Исполнитель не найден'),404);
