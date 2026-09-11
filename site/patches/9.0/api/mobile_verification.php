<?php
require_once __DIR__.'/../config.php';av_require_post();$in=json_decode(file_get_contents('php://input'),true);if(!is_array($in))$in=$_POST;
$action=trim((string)($in['action']??'status'));$type=trim((string)($in['account_type']??''));$id=trim((string)($in['id']??''));$token=trim((string)($in['verification_token']??''));$channel=trim((string)($in['channel']??''));
if(!av_verification_authorized($type,$id,$token))av_json_response(array('ok'=>false,'error'=>'Сессия подтверждения недействительна'),403);
if($action==='send'){$r=av_verification_send($type,$id,$token,$channel);av_json_response($r,!empty($r['ok'])?200:422);}
if($action==='confirm'){$r=av_verification_confirm($type,$id,$token,$channel,trim((string)($in['code']??'')));av_json_response($r,!empty($r['ok'])?200:422);}
$record=av_verification_record($type,$id);av_json_response(array('ok'=>true,'phone_verified'=>!empty($record['phone_verified_at']),'email_verified'=>!empty($record['email_verified_at']),'complete'=>av_verification_complete($record),'phone_masked'=>av_verification_mask($record['phone']??'','phone'),'email_masked'=>av_verification_mask($record['email']??'','email')));
