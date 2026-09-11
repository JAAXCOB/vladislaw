<?php
require_once __DIR__.'/../config.php';av_require_staff_api();if(!av_staff_can_admin())av_json_response(array('ok'=>false,'error'=>'FORBIDDEN'),403);
$i=json_decode(file_get_contents('php://input'),true);if(!is_array($i))$i=$_POST;
$name=trim($i['name']??'');$phone=trim($i['phone']??'');$email=strtolower(trim($i['email']??''));$vehicle=trim($i['vehicle_type']??'');$plate=trim($i['plate']??'');$capacity=trim($i['capacity']??'');$allowedServices=array('Эвакуация','Техпомощь','Запуск двигателя','Замена колеса','Подвоз топлива','Вскрытие автомобиля');$services=isset($i['services'])&&is_array($i['services'])?array_values(array_intersect($allowedServices,$i['services'])):$allowedServices;
if(!$name||!$phone||!$email||!$vehicle)av_json_response(array('ok'=>false,'error'=>'Нужны ФИО, телефон, email и тип техники'),422);
$ws=av_read_workers();foreach($ws as $w)if(strtolower($w['email']??'')===$email)av_json_response(array('ok'=>false,'error'=>'Email уже зарегистрирован'),409);
$temp=substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789'),0,10);$id='EX-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));
$ws[]=array('id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>$email,'password_hash'=>password_hash($temp,PASSWORD_DEFAULT),'vehicle_type'=>$vehicle,'plate'=>$plate,'capacity'=>$capacity,'status'=>'offline','approved'=>true,'must_change_password'=>true,'services'=>$services,'created_at'=>date('c'),'lat'=>'','lng'=>'','last_seen'=>'');
if(!av_write_workers($ws))av_json_response(array('ok'=>false,'error'=>'Ошибка записи'),500);
av_json_response(array('ok'=>true,'worker'=>array('id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>$email,'vehicle_type'=>$vehicle,'plate'=>$plate,'capacity'=>$capacity),'temporary_password'=>$temp,'login_url'=>'/executor-login.php'));
