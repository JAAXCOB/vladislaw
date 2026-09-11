<?php
require_once __DIR__.'/../config.php';av_require_staff_api();if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
if($_SERVER['REQUEST_METHOD']!=='POST') av_json_response(array('ok'=>false,'error'=>'Метод не поддерживается'),405);
$in=json_decode(file_get_contents('php://input'),true); if(!is_array($in))$in=$_POST;
function cw($v){return trim((string)$v);}
$name=cw(isset($in['name'])?$in['name']:''); $phone=cw(isset($in['phone'])?$in['phone']:'');
$vehicle=cw(isset($in['vehicle_type'])?$in['vehicle_type']:'');
$allowedServices=array('Эвакуация','Техпомощь','Запуск двигателя','Замена колеса','Подвоз топлива','Вскрытие автомобиля');
$services=isset($in['services'])&&is_array($in['services'])?array_values(array_intersect($allowedServices,$in['services'])):$allowedServices;
if(!$name||!$phone||!$vehicle) av_json_response(array('ok'=>false,'error'=>'Заполните имя, телефон и тип техники'),422);
$workers=av_read_workers(); $id='W-'.date('ymdHis').'-'.str_pad((string)(count($workers)+1),3,'0',STR_PAD_LEFT);
$token=bin2hex(random_bytes(20));
$w=array('id'=>$id,'token'=>$token,'name'=>$name,'phone'=>$phone,'vehicle_type'=>$vehicle,'services'=>$services,'status'=>'offline','lat'=>'','lng'=>'','last_seen'=>'','created_at'=>date('c'),'rating'=>5.0,'commission'=>10,'commission_rate'=>10);
array_unshift($workers,$w); if(!av_write_workers($workers)) av_json_response(array('ok'=>false,'error'=>'Ошибка сохранения исполнителя'),500);
av_json_response(array('ok'=>true,'worker'=>$w,'worker_url'=>'/worker.php?token='.$token));
