<?php
require_once __DIR__.'/../config.php';require_once __DIR__.'/../registration_drafts.php';av_require_post();
$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;
$type=strtolower(trim((string)($in['type']??'client')));$name=trim((string)($in['name']??''));$phone=trim((string)($in['phone']??''));$email=strtolower(trim((string)($in['email']??'')));$login=trim((string)($in['login']??''));$pass=(string)($in['password']??'');
if(strlen($pass)<6)av_json_response(array('ok'=>false,'error'=>'Пароль должен содержать минимум 6 символов'),422);
if($type==='client'){
 $users=av_read_users();if(!$name||!$phone||!$login||!filter_var($email,FILTER_VALIDATE_EMAIL))av_json_response(array('ok'=>false,'error'=>'Заполните имя, телефон, почту и логин'),422);
 foreach($users as $user)if(strtolower((string)($user['login']??''))===strtolower($login))av_json_response(array('ok'=>false,'error'=>'Логин уже занят'),409);
 $id='AVR-U-'.date('ymd').'-'.str_pad((string)(count($users)+1),5,'0',STR_PAD_LEFT);$record=array_merge(array('id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>$email,'login'=>$login,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'created_at'=>date('c'),'blocked'=>false),av_verification_new_fields());$users[]=$record;if(!av_write_users($users))av_json_response(array('ok'=>false,'error'=>'Не удалось сохранить аккаунт'),500);
 if(!empty($record['verification_required']))av_json_response(array('ok'=>true,'status'=>'verification_required','account_type'=>'user','id'=>$id,'verification_token'=>$record['verification_token'],'next_channel'=>av_verification_next_channel($record),'message'=>'Подтвердите почту.'));
 $token=av_issue_mobile_token($id,'android','user');av_json_response(array('ok'=>true,'status'=>'active','token'=>$token,'id'=>$id));
}
if($type==='executor'){
 $vehicle=trim((string)($in['vehicle_type']??''));$plate=mb_strtoupper(trim((string)($in['plate']??'')),'UTF-8');$uploadToken=trim((string)($in['upload_token']??''));$draftDocs=avr_registration_draft_documents($uploadToken);
 $allowedServices=array('Эвакуация','Техпомощь','Запуск двигателя','Замена колеса','Подвоз топлива','Вскрытие автомобиля');$services=isset($in['services'])&&is_array($in['services'])?array_values(array_intersect($allowedServices,$in['services'])):array();$required=avr_registration_draft_kinds();$missing=array();foreach($required as $kind)if(empty($draftDocs[$kind]))$missing[]=$kind;
 if(!$name||!$phone||!$email||!$vehicle||!$plate)av_json_response(array('ok'=>false,'error'=>'Заполните ФИО, телефон, email, технику и госномер'),422);
 if(!$services)av_json_response(array('ok'=>false,'error'=>'Выберите хотя бы одну услугу'),422);
 if($missing)av_json_response(array('ok'=>false,'error'=>'Не загружены обязательные фото/документы: '.implode(', ',$missing)),422);
 if(empty($in['contract_accepted']))av_json_response(array('ok'=>false,'error'=>'Необходимо принять договор исполнителя AV Rescue'),422);
 $workers=av_read_workers();foreach($workers as $worker)if(strtolower((string)($worker['email']??''))===$email)av_json_response(array('ok'=>false,'error'=>'Email уже зарегистрирован'),409);
 $documents=avr_registration_draft_finalize($uploadToken);if(!$documents)av_json_response(array('ok'=>false,'error'=>'Не удалось закрепить фотографии. Загрузите их повторно.'),500);
 $id='EX-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$worker=array_merge(array('id'=>$id,'name'=>$name,'phone'=>$phone,'email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'vehicle_type'=>$vehicle,'plate'=>$plate,'capacity'=>trim((string)($in['capacity']??'')),'services'=>$services,'status'=>'offline','created_at'=>date('c'),'approved'=>false,'review_status'=>'review','documents'=>$documents,'contract'=>array('version'=>'AVR-EXECUTOR-1.0-DRAFT','accepted'=>true,'accepted_at'=>date('c')),'rating'=>5.0,'rating_count'=>0),av_verification_new_fields());$workers[]=$worker;
 if(!av_write_workers($workers))av_json_response(array('ok'=>false,'error'=>'Не удалось сохранить профиль исполнителя'),500);
 $apps=av_executor_apps();array_unshift($apps,array('id'=>$id,'type'=>'executor','name'=>$name,'phone'=>$phone,'email'=>$email,'vehicle_type'=>$vehicle,'plate'=>$plate,'services'=>$services,'documents'=>$documents,'status'=>'review','created_at'=>date('c'),'admin_review_required'=>true));av_write_executor_apps($apps);
 if(!empty($worker['verification_required']))av_json_response(array('ok'=>true,'status'=>'verification_required','account_type'=>'executor','id'=>$id,'verification_token'=>$worker['verification_token'],'next_channel'=>av_verification_next_channel($worker),'message'=>'Фотографии сохранены. Подтвердите почту, затем заявка поступит на модерацию.'));
 av_json_response(array('ok'=>true,'status'=>'review','account_type'=>'executor','id'=>$id,'message'=>'Заявка и фотографии сохранены. Доступ к заказам откроется после проверки администратора.'));
}
if($type==='b2b'){
 $company=trim((string)($in['company']??''));$inn=trim((string)($in['inn']??''));if(!$company||!$inn||!$name||!$phone||!$email)av_json_response(array('ok'=>false,'error'=>'Заполните обязательные поля'),422);
 $b2b=av_read_b2b();foreach($b2b as $partner)if(strtolower((string)($partner['email']??''))===$email)av_json_response(array('ok'=>false,'error'=>'Такой B2B-аккаунт уже существует'),409);
 $id='B2B-'.date('ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)),0,8));$record=array_merge(array('id'=>$id,'company'=>$company,'inn'=>$inn,'contact'=>$name,'phone'=>$phone,'email'=>$email,'password_hash'=>password_hash($pass,PASSWORD_DEFAULT),'approved'=>false,'review_status'=>'review','created_at'=>date('c')),av_verification_new_fields());$b2b[]=$record;if(!av_write_b2b($b2b))av_json_response(array('ok'=>false,'error'=>'Не удалось сохранить B2B-аккаунт'),500);
 if(!empty($record['verification_required']))av_json_response(array('ok'=>true,'status'=>'verification_required','account_type'=>'b2b','id'=>$id,'verification_token'=>$record['verification_token'],'next_channel'=>av_verification_next_channel($record),'message'=>'Подтвердите почту, затем заявка поступит на модерацию.'));
 av_json_response(array('ok'=>true,'status'=>'review','account_type'=>'b2b','id'=>$id,'message'=>'B2B-аккаунт создан. Доступ откроется после подтверждения администратора.'));
}
av_json_response(array('ok'=>false,'error'=>'Неизвестный тип регистрации'),422);
