<?php
require_once __DIR__.'/../config.php';
av_json_response(array(
 'ok'=>true,'platform'=>'AV Rescue','api_version'=>'1',
 'android'=>array(
  'status'=>'native_client_source_in_release',
  'auth'=>'bearer_token_30_days',
  'base_url'=>'https://av-rescue.ru',
  'endpoints'=>array(
   'login'=>'/api/mobile_login.php','logout'=>'/api/mobile_logout.php',
   'profile'=>'/api/mobile_profile.php','dashboard'=>'/api/mobile_dashboard.php',
   'orders'=>'/api/mobile_orders.php','create_order'=>'/api/mobile_create_order.php',
   'location'=>'/api/mobile_location.php','order_action'=>'/api/mobile_order_action.php',
   'driver_online'=>'/api/driver_online.php','create_payment'=>'/api/alfa_create_payment.php','payment_status'=>'/api/alfa_payment_status.php'
  )
 ),
 'single_account'=>true,'bank'=>'Альфа-Банк','payments'=>'server_ready_credentials_required','glonass'=>'deferred'
));
