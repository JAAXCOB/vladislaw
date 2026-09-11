<?php
require_once __DIR__.'/../config.php';av_require_staff_api();if(!av_is_superadmin())av_json_response(array('ok'=>false,'error'=>'Только главный администратор'),403);
if($_SERVER['REQUEST_METHOD']==='POST'){
 $i=json_decode(file_get_contents('php://input'),true);if(!is_array($i))$i=array();if(!av_check_csrf($i['csrf']??''))av_json_response(array('ok'=>false,'error'=>'Сессия устарела'),403);
 $old=av_payment_settings();$allowed=array('legal_name','inn','bank_account','bank_name','bik','correspondent_account','acquiring_provider','acquiring_enabled','alfa_mode','alfa_username','payout_provider','payout_enabled','cash_commission_enabled','ogrnip','legal_address','support_email','support_phone','site_name');$safe=array_merge($old,array_intersect_key($i,array_flip($allowed)));
 if(!empty($i['alfa_password']))$safe['alfa_password']=(string)$i['alfa_password'];if(!empty($i['alfa_token']))$safe['alfa_token']=(string)$i['alfa_token'];
 if(!av_secure_write(AV_PAYMENT_SETTINGS_FILE,$safe))av_json_response(array('ok'=>false,'error'=>'Ошибка сохранения'),500);av_audit('payment_settings','',array('acquiring_enabled'=>!empty($safe['acquiring_enabled']),'alfa_mode'=>$safe['alfa_mode']??'test'));av_json_response(array('ok'=>true));
}
$settings=av_payment_settings();$settings['alfa_password_set']=!empty($settings['alfa_password']);$settings['alfa_token_set']=!empty($settings['alfa_token']);unset($settings['alfa_password'],$settings['alfa_token']);av_json_response(array('ok'=>true,'settings'=>$settings));
