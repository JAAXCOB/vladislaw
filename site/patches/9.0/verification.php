<?php
/* Contact verification for newly created AV Rescue accounts. */

define('AV_VERIFICATION_SETTINGS_FILE', __DIR__.'/data/verification_settings.php');
define('AV_VERIFICATION_CODES_FILE', __DIR__.'/data/verification_codes.php');

function av_verification_settings(){
    $saved=av_secure_read(AV_VERIFICATION_SETTINGS_FILE);
    return array_merge(array(
        'enabled'=>false,
        'sms_provider'=>'smsru',
        'smsru_api_id'=>'',
        'smtp_host'=>'smtp.yandex.ru',
        'smtp_port'=>465,
        'smtp_username'=>'AVRDriver@yandex.ru',
        'smtp_app_password'=>'',
        'from_email'=>'AVRDriver@yandex.ru'
    ),is_array($saved)?$saved:array());
}
function av_write_verification_settings($settings){return av_secure_write(AV_VERIFICATION_SETTINGS_FILE,$settings);}
function av_verification_ready(){
    $s=av_verification_settings();
    return !empty($s['enabled']) && trim((string)$s['smsru_api_id'])!=='' && trim((string)$s['smtp_app_password'])!=='';
}
function av_verification_codes(){return av_secure_read(AV_VERIFICATION_CODES_FILE);}
function av_write_verification_codes($rows){return av_secure_write(AV_VERIFICATION_CODES_FILE,array_values($rows));}
function av_verification_new_fields(){
    if(!av_verification_ready())return array('verification_required'=>false,'verification_deferred'=>true);
    return array('verification_required'=>true,'phone_verified_at'=>'','email_verified_at'=>'','verified_at'=>'','verification_token'=>bin2hex(random_bytes(24)));
}
function av_verification_complete($record){
    return empty($record['verification_required']) || (!empty($record['phone_verified_at'])&&!empty($record['email_verified_at']));
}
function av_verification_url($type,$record){
    return '/verify-account.php?type='.rawurlencode($type).'&id='.rawurlencode((string)($record['id']??'')).'&token='.rawurlencode((string)($record['verification_token']??''));
}
function av_verification_record($type,$id){
    $all=$type==='user'?av_read_users():($type==='executor'?av_read_workers():($type==='b2b'?av_read_b2b():array()));
    foreach($all as $row)if((string)($row['id']??'')===(string)$id)return $row;
    return null;
}
function av_verification_update($type,$id,$fields){
    $all=$type==='user'?av_read_users():($type==='executor'?av_read_workers():av_read_b2b());$found=false;
    foreach($all as $k=>$row)if((string)($row['id']??'')===(string)$id){$all[$k]=array_merge($row,$fields);$found=true;break;}
    if(!$found)return false;
    return $type==='user'?av_write_users($all):($type==='executor'?av_write_workers($all):av_write_b2b($all));
}
function av_verification_authorized($type,$id,$token){
    $r=av_verification_record($type,$id);
    return $r && !empty($r['verification_token']) && hash_equals((string)$r['verification_token'],(string)$token);
}
function av_verification_phone($phone){
    $d=preg_replace('/\D+/','',(string)$phone);
    if(strlen($d)===10)$d='7'.$d;
    if(strlen($d)===11&&$d[0]==='8')$d='7'.substr($d,1);
    return strlen($d)===11?$d:'';
}
function av_verification_mask($value,$channel){
    if($channel==='email'){
        $parts=explode('@',(string)$value,2);if(count($parts)!==2)return '';
        return mb_substr($parts[0],0,2).'***@'.$parts[1];
    }
    $d=av_verification_phone($value);return $d?'+'.substr($d,0,1).' *** ***-'.substr($d,-4):'';
}
function av_verification_send_sms($phone,$code,$settings){
    if(!function_exists('curl_init'))return array(false,'На сервере недоступна отправка SMS.');
    $url='https://sms.ru/sms/send?'.http_build_query(array('api_id'=>$settings['smsru_api_id'],'to'=>$phone,'msg'=>'AV Rescue: код подтверждения '.$code.'. Никому его не сообщайте.','json'=>1));
    $ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>15));
    $raw=curl_exec($ch);$http=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=json_decode((string)$raw,true);
    return array($http===200&&is_array($data)&&($data['status']??'')==='OK',$http===200?'SMS-провайдер отклонил отправку.':'SMS-провайдер недоступен.');
}
function av_smtp_read($socket){$out='';while(($line=fgets($socket,515))!==false){$out.=$line;if(strlen($line)<4||$line[3]===' ')break;}return $out;}
function av_smtp_command($socket,$command,$codes){
    if($command!=='')fwrite($socket,$command."\r\n");$reply=av_smtp_read($socket);$code=(int)substr($reply,0,3);return in_array($code,$codes,true);
}
function av_verification_send_email($email,$code,$settings){
    $host=trim((string)$settings['smtp_host']);$port=(int)$settings['smtp_port'];$user=trim((string)$settings['smtp_username']);$pass=(string)$settings['smtp_app_password'];$from=trim((string)$settings['from_email']);
    $socket=@stream_socket_client('ssl://'.$host.':'.$port,$errno,$errstr,15);if(!$socket)return array(false,'Почтовый сервер недоступен.');stream_set_timeout($socket,15);
    $ok=av_smtp_command($socket,'',array(220))&&av_smtp_command($socket,'EHLO av-rescue.ru',array(250))&&av_smtp_command($socket,'AUTH LOGIN',array(334))&&av_smtp_command($socket,base64_encode($user),array(334))&&av_smtp_command($socket,base64_encode($pass),array(235))&&av_smtp_command($socket,'MAIL FROM:<'.$from.'>',array(250))&&av_smtp_command($socket,'RCPT TO:<'.$email.'>',array(250,251))&&av_smtp_command($socket,'DATA',array(354));
    if($ok){$subject='=?UTF-8?B?'.base64_encode('Код подтверждения AV Rescue').'?=';$body="Ваш код подтверждения: {$code}\r\n\r\nКод действует 15 минут. Никому его не сообщайте.";$headers="From: AV Rescue <{$from}>\r\nTo: <{$email}>\r\nSubject: {$subject}\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";fwrite($socket,$headers."\r\n".$body."\r\n.\r\n");$ok=av_smtp_command($socket,'',array(250));}
    av_smtp_command($socket,'QUIT',array(221));fclose($socket);return array($ok,$ok?'':'Не удалось отправить письмо. Проверьте пароль приложения Яндекса.');
}
function av_verification_send($type,$id,$token,$channel){
    if(!in_array($type,array('user','executor','b2b'),true)||!in_array($channel,array('phone','email'),true))return array('ok'=>false,'error'=>'Некорректный запрос.');
    if(!av_verification_ready())return array('ok'=>false,'error'=>'Подтверждение ещё не настроено администратором.');
    if(!av_verification_authorized($type,$id,$token))return array('ok'=>false,'error'=>'Ссылка подтверждения недействительна.');
    $record=av_verification_record($type,$id);if(!$record||av_verification_complete($record))return array('ok'=>true,'complete'=>true);
    if($channel==='email'&&empty($record['phone_verified_at']))return array('ok'=>false,'error'=>'Сначала подтвердите телефон.');
    if($channel==='phone'&&!empty($record['phone_verified_at']))return array('ok'=>true,'already_verified'=>true);
    if($channel==='email'&&!empty($record['email_verified_at']))return array('ok'=>true,'already_verified'=>true);
    $recipient=$channel==='phone'?av_verification_phone($record['phone']??''):strtolower(trim((string)($record['email']??'')));
    if(($channel==='phone'&&$recipient==='')||($channel==='email'&&!filter_var($recipient,FILTER_VALIDATE_EMAIL)))return array('ok'=>false,'error'=>$channel==='phone'?'Укажите корректный телефон.':'Укажите корректную почту.');
    $key=$type.'|'.$id.'|'.$channel;$rows=av_verification_codes();$now=time();
    foreach($rows as $row)if(($row['key']??'')===$key&&$now-(int)($row['sent_at']??0)<60)return array('ok'=>false,'error'=>'Новый код можно запросить через минуту.','retry_after'=>60-($now-(int)$row['sent_at']));
    $code=(string)random_int(100000,999999);$settings=av_verification_settings();
    list($sent,$error)=$channel==='phone'?av_verification_send_sms($recipient,$code,$settings):av_verification_send_email($recipient,$code,$settings);
    if(!$sent)return array('ok'=>false,'error'=>$error);
    $rows=array_values(array_filter($rows,function($row)use($key,$now){return ($row['key']??'')!==$key&&(int)($row['expires_at']??0)>$now;}));
    $rows[]=array('key'=>$key,'code_hash'=>password_hash($code,PASSWORD_DEFAULT),'sent_at'=>$now,'expires_at'=>$now+($channel==='phone'?300:900),'attempts'=>0,'ip_hash'=>hash('sha256',av_client_ip()));av_write_verification_codes($rows);
    av_audit('verification_code_sent',$type.':'.$id,array('channel'=>$channel));
    return array('ok'=>true,'masked'=>av_verification_mask($recipient,$channel));
}
function av_verification_confirm($type,$id,$token,$channel,$code){
    if(!av_verification_authorized($type,$id,$token))return array('ok'=>false,'error'=>'Ссылка подтверждения недействительна.');
    $key=$type.'|'.$id.'|'.$channel;$rows=av_verification_codes();$found=-1;
    foreach($rows as $i=>$row)if(($row['key']??'')===$key){$found=$i;break;}
    if($found<0)return array('ok'=>false,'error'=>'Сначала запросите новый код.');$row=$rows[$found];
    if((int)($row['expires_at']??0)<time()){unset($rows[$found]);av_write_verification_codes($rows);return array('ok'=>false,'error'=>'Срок действия кода истёк. Запросите новый.');}
    $attempts=(int)($row['attempts']??0)+1;if($attempts>5){unset($rows[$found]);av_write_verification_codes($rows);return array('ok'=>false,'error'=>'Слишком много попыток. Запросите новый код.');}
    if(!preg_match('/^\d{6}$/',(string)$code)||!password_verify((string)$code,(string)$row['code_hash'])){$rows[$found]['attempts']=$attempts;av_write_verification_codes($rows);return array('ok'=>false,'error'=>'Неверный код.');}
    unset($rows[$found]);av_write_verification_codes($rows);$field=$channel==='phone'?'phone_verified_at':'email_verified_at';$fields=array($field=>date('c'));
    $record=av_verification_record($type,$id);if($channel==='email'&&!empty($record['phone_verified_at']))$fields['verified_at']=date('c');
    av_verification_update($type,$id,$fields);av_audit('contact_verified',$type.':'.$id,array('channel'=>$channel));$record=av_verification_record($type,$id);
    return array('ok'=>true,'complete'=>av_verification_complete($record));
}
