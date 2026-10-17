<?php
require_once __DIR__.'/../alfa_acquiring.php';
$settings=av_payment_settings();
$connected=av_alfa_ready(true);
av_json_response(array(
 'ok'=>true,
 'provider'=>'alfa',
 'acquiring_connected'=>$connected,
 'mode'=>av_alfa_mode($settings),
 'live_allowed'=>av_alfa_live_allowed(),
 'card_enabled'=>$connected,
 'sbp_enabled'=>$connected&&!empty($settings['alfa_sbp_enabled']),
));
