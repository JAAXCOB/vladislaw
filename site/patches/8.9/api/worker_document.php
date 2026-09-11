<?php
require_once __DIR__.'/../config.php';
av_require_staff_api();
if(!av_staff_can_admin()){http_response_code(403);exit('FORBIDDEN');}

$workerId=trim((string)($_GET['worker_id']??''));
$kind=preg_replace('/[^a-z0-9_]/','',strtolower((string)($_GET['kind']??'')));
$allowed=array('profile_photo','vehicle_front','vehicle_rear','vehicle_left','vehicle_right','driver_license_front','driver_license_back','sts_front','sts_back');
if(!in_array($kind,$allowed,true)){http_response_code(422);exit('INVALID_KIND');}

$path='';
foreach(av_read_workers() as $worker)if((string)($worker['id']??'')===$workerId){$path=(string)($worker['documents'][$kind]??'');break;}
if($path===''){http_response_code(404);exit('NOT_FOUND');}
$uploadsRoot=realpath(__DIR__.'/../uploads');
$file=realpath(__DIR__.'/../'.ltrim(parse_url($path,PHP_URL_PATH)??'','/'));
if($uploadsRoot===false||$file===false||!is_file($file)||strpos($file,$uploadsRoot.DIRECTORY_SEPARATOR)!==0){http_response_code(404);exit('NOT_FOUND');}

header('Content-Type: image/jpeg');
header('Content-Length: '.filesize($file));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($file);
