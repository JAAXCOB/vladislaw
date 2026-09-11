<?php
require_once __DIR__.'/../config.php'; require_once __DIR__.'/../dispatch_helpers.php'; av_require_dispatcher_api();
$workers=array();
foreach(av_read_workers() as $source){
    if(empty($source['approved']))continue;
    $seen=strtotime($source['last_seen']??'');
    $workers[]=array(
        'id'=>$source['id']??'',
        'name'=>$source['name']??'',
        'phone'=>$source['phone']??'',
        'vehicle_type'=>$source['vehicle_type']??$source['vehicle']??'',
        'plate'=>$source['plate']??'',
        'capacity'=>$source['capacity']??null,
        'services'=>is_array($source['services']??null)?$source['services']:array(),
        'status'=>$source['status']??'offline',
        'approved'=>true,
        'lat'=>$source['lat']??null,
        'lng'=>$source['lng']??null,
        'last_seen'=>$source['last_seen']??'',
        'rating'=>av_worker_rating($source['id']??''),
        'is_own_fleet'=>av_worker_is_own_fleet($source),
        'position_fresh'=>$seen&&time()-$seen<=600
    );
}
av_json_response(array('ok'=>true,'workers'=>$workers));
