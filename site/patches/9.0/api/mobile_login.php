<?php
require_once __DIR__.'/../config.php';
av_require_post();

$in=json_decode(file_get_contents('php://input'),true);
if(!is_array($in))$in=$_POST;

$login=trim((string)($in['login']??''));
$pass=(string)($in['password']??'');
$appMode=trim((string)($in['app_mode']??''));
if(!in_array($appMode,array('client','driver'),true))$appMode='';
$allowClient=$appMode!=='driver';
$allowDriver=$appMode!=='client';

if(!$login||!$pass)av_json_response(array('ok'=>false,'error'=>'Введите логин и пароль'),422);

$wait=av_login_rate_check($login);
if($wait>0)av_json_response(array('ok'=>false,'error'=>'Слишком много попыток входа. Повторите через '.$wait.' мин.'),429);

function av_mobile_success($login,$type,$id,$profile,$roles=array(),$activeRole=''){
    av_login_rate_success($login);
    $token=av_issue_mobile_token($id,'android',$type);
    if($activeRole!==''){
        $hash=hash('sha256',$token);
        $tokens=av_mobile_tokens_read();
        foreach($tokens as $i=>$row)if(hash_equals((string)($row['token_hash']??''),$hash)){$tokens[$i]['active_role']=$activeRole;break;}
        av_mobile_tokens_write($tokens);
    }
    av_json_response(array(
        'ok'=>true,
        'token'=>$token,
        'expires_in'=>2592000,
        'account_type'=>$type,
        'user'=>$profile,
        'roles'=>$roles
    ));
}

/* 1. Главный администратор — тот же логин/пароль, что на сайте */
if($allowDriver && hash_equals(AV_SUPERADMIN_LOGIN,$login) && hash_equals(AV_SUPERADMIN_PASSWORD_SHA256,hash('sha256',$pass))){
    av_mobile_success(
        $login,'superadmin','SUPERADMIN',
        array('id'=>'SUPERADMIN','name'=>'Главный администратор','phone'=>'','login'=>AV_SUPERADMIN_LOGIN),
        array('client'=>false,'executor'=>false,'staff_role'=>'superadmin','b2b'=>false)
    );
}

/* 2. Исполнитель проверяется раньше клиента.
   Это важно для старых раздельных записей с одинаковым email: иначе Android
   останавливался на клиентской записи и не открывал водительский кабинет. */
if($allowDriver)foreach(av_read_workers() as $w){
    $matches=(!empty($w['login']) && strtolower((string)$w['login'])===strtolower($login))
        || (!empty($w['email']) && strtolower((string)$w['email'])===strtolower($login));

    if($matches && !empty($w['password_hash']) && password_verify($pass,(string)$w['password_hash'])){
        if(!av_verification_complete($w))av_json_response(array('ok'=>false,'error'=>'Подтвердите телефон и почту','verification_required'=>true,'account_type'=>'executor','id'=>$w['id'],'verification_token'=>$w['verification_token']??'','next_channel'=>empty($w['phone_verified_at'])?'phone':'email'),403);
        $linkedUser=!empty($w['user_id'])?av_user_by_id($w['user_id']):null;
        $linkedStaffRole=$linkedUser&&empty($linkedUser['staff_disabled'])?($linkedUser['staff_role']??null):null;
        if(empty($w['approved'])&&!$linkedStaffRole){$rejected=(($w['review_status']??'')==='rejected');$message=$rejected?'Регистрация отклонена. Причина: '.($w['rejection_reason']??'Исправьте данные анкеты.').' Войдите на сайт AV Rescue и отправьте исправления из профиля.':'Аккаунт исполнителя ещё не подтверждён';av_json_response(array('ok'=>false,'error'=>$message,'moderation_status'=>$w['review_status']??'review','rejection_reason'=>$w['rejection_reason']??''),403);}

        if($linkedUser){
            $u=$linkedUser;
            av_mobile_success(
                $login,'user',(string)$u['id'],
                array('id'=>$u['id'],'name'=>$u['name']??$w['name']??'','phone'=>$u['phone']??$w['phone']??'','login'=>$u['login']??$w['email']??''),
                array('client'=>true,'executor'=>true,'staff_role'=>$linkedStaffRole,'b2b'=>!empty($u['b2b_role'])),
                $linkedStaffRole?'staff':'executor'
            );
        }

        av_mobile_success(
            $login,'executor',(string)$w['id'],
            array('id'=>$w['id'],'name'=>$w['name']??'','phone'=>$w['phone']??'','login'=>$w['login']??$w['email']??''),
            array('client'=>false,'executor'=>true,'staff_role'=>null,'b2b'=>false)
        );
    }
}

/* 3. Единые личные аккаунты — клиенты + назначенные админы/диспетчеры + новые исполнители */
foreach(av_read_users() as $u){
    $matches=strtolower((string)($u['login']??''))===strtolower($login)
        || (!empty($u['email']) && strtolower((string)$u['email'])===strtolower($login));

    if($matches && password_verify($pass,(string)($u['password_hash']??''))){
        if(!empty($u['blocked']))av_json_response(array('ok'=>false,'error'=>'Аккаунт заблокирован'),403);
        if(!av_verification_complete($u))av_json_response(array('ok'=>false,'error'=>'Подтвердите телефон и почту','verification_required'=>true,'account_type'=>'user','id'=>$u['id'],'verification_token'=>$u['verification_token']??'','next_channel'=>empty($u['phone_verified_at'])?'phone':'email'),403);

        $w=av_worker_by_user_id($u['id']??'');
        $staffRole=!empty($u['staff_disabled'])?null:($u['staff_role']??null);
        if($appMode==='driver'&&!$w&&!$staffRole)av_json_response(array('ok'=>false,'error'=>'Этот аккаунт предназначен для приложения AV Rescue'),403);
        if($appMode==='driver'&&$w&&empty($w['approved'])){$rejected=(($w['review_status']??'')==='rejected');$message=$rejected?'Регистрация отклонена. Причина: '.($w['rejection_reason']??'Исправьте данные анкеты.').' Войдите на сайт AV Rescue и отправьте исправления из профиля.':'Анкета исполнителя проверяется. Доступ к заказам откроется после подтверждения администратором.';av_json_response(array('ok'=>false,'error'=>$message,'moderation_status'=>$w['review_status']??'review','rejection_reason'=>$w['rejection_reason']??''),403);}
        av_mobile_success(
            $login,'user',(string)$u['id'],
            array(
                'id'=>$u['id'],
                'name'=>$u['name']??'',
                'phone'=>$u['phone']??'',
                'login'=>$u['login']??'',
                'email'=>$u['email']??''
            ),
            array(
                'client'=>true,
                'executor'=>!empty($u['executor_role'])||!!$w,
                'staff_role'=>$staffRole,
                'b2b'=>!empty($u['b2b_role'])
            ),
            $appMode==='client'?'client':($staffRole?'staff':($w?'executor':''))
        );
    }
}

/* 4. Старые аккаунты исполнителей — совместимость без повторной регистрации */
if($allowDriver)foreach(av_read_workers() as $w){
    $matches=(!empty($w['login']) && strtolower((string)$w['login'])===strtolower($login))
        || (!empty($w['email']) && strtolower((string)$w['email'])===strtolower($login));

    if($matches && !empty($w['password_hash']) && password_verify($pass,(string)$w['password_hash'])){
        if(!av_verification_complete($w))av_json_response(array('ok'=>false,'error'=>'Подтвердите телефон и почту','verification_required'=>true,'account_type'=>'executor','id'=>$w['id'],'verification_token'=>$w['verification_token']??'','next_channel'=>empty($w['phone_verified_at'])?'phone':'email'),403);
        if(empty($w['approved'])){$rejected=(($w['review_status']??'')==='rejected');$message=$rejected?'Регистрация отклонена. Причина: '.($w['rejection_reason']??'Исправьте данные анкеты.').' Войдите на сайт AV Rescue и отправьте исправления из профиля.':'Аккаунт исполнителя ещё не подтверждён';av_json_response(array('ok'=>false,'error'=>$message,'moderation_status'=>$w['review_status']??'review','rejection_reason'=>$w['rejection_reason']??''),403);}

        // If this worker is already linked to a unified user, issue a user token.
        if(!empty($w['user_id']) && av_user_by_id($w['user_id'])){
            $u=av_user_by_id($w['user_id']);
            av_mobile_success(
                $login,'user',(string)$u['id'],
                array('id'=>$u['id'],'name'=>$u['name']??$w['name']??'','phone'=>$u['phone']??$w['phone']??'','login'=>$u['login']??$w['email']??''),
                array('client'=>true,'executor'=>true,'staff_role'=>$u['staff_role']??null,'b2b'=>!empty($u['b2b_role']))
            );
        }

        av_mobile_success(
            $login,'executor',(string)$w['id'],
            array('id'=>$w['id'],'name'=>$w['name']??'','phone'=>$w['phone']??'','login'=>$w['login']??$w['email']??''),
            array('client'=>false,'executor'=>true,'staff_role'=>null,'b2b'=>false)
        );
    }
}

/* 5. B2B — те же данные, что на веб-входе */
if($allowClient)foreach(av_read_b2b() as $b){
    $matches=(!empty($b['login']) && strtolower((string)$b['login'])===strtolower($login))
        || (!empty($b['email']) && strtolower((string)$b['email'])===strtolower($login));

    if($matches && password_verify($pass,(string)($b['password_hash']??''))){
        if(!av_verification_complete($b))av_json_response(array('ok'=>false,'error'=>'Подтвердите телефон и почту','verification_required'=>true,'account_type'=>'b2b','id'=>$b['id'],'verification_token'=>$b['verification_token']??'','next_channel'=>empty($b['phone_verified_at'])?'phone':'email'),403);
        if(empty($b['approved']))av_json_response(array('ok'=>false,'error'=>'B2B аккаунт ожидает подтверждения'),403);

        av_mobile_success(
            $login,'b2b',(string)$b['id'],
            array('id'=>$b['id'],'name'=>$b['company']??$b['contact']??'B2B','phone'=>$b['phone']??'','login'=>$b['login']??$b['email']??''),
            array('client'=>false,'executor'=>false,'staff_role'=>null,'b2b'=>true)
        );
    }
}

/* 6. Старые staff-аккаунты — совместимость */
if($allowDriver)foreach(av_read_staff() as $s){
    if(
        empty($s['blocked'])
        && strtolower((string)($s['login']??''))===strtolower($login)
        && !empty($s['password_hash'])
        && password_verify($pass,(string)$s['password_hash'])
    ){
        av_mobile_success(
            $login,'staff',(string)$s['id'],
            array('id'=>$s['id'],'name'=>$s['name']??'Сотрудник','phone'=>'','login'=>$s['login']??''),
            array('client'=>false,'executor'=>false,'staff_role'=>$s['role']??'dispatcher','b2b'=>false)
        );
    }
}

av_login_rate_fail($login);
av_json_response(array('ok'=>false,'error'=>'Неверный логин или пароль'),401);
