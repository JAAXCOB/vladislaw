<?php
require_once __DIR__.'/../config.php';

$token = '';
if (isset($_SERVER['HTTP_X_AV_TAB_SESSION'])) $token = trim((string)$_SERVER['HTTP_X_AV_TAB_SESSION']);
elseif (isset($_POST['token'])) $token = trim((string)$_POST['token']);

$hasAny = !empty($_SESSION['superadmin_auth']) || !empty($_SESSION['staff_id']) || !empty($_SESSION['executor_id']) || !empty($_SESSION['b2b_id']) || !empty($_SESSION['user_id']);

if (!$hasAny) {
    av_json_response(array('ok'=>true,'logged_in'=>false));
}

if (!$token) {
    // iOS can suspend and rebuild an installed PWA while keeping the valid
    // HttpOnly PHP session cookie but losing sessionStorage. Recover the tab
    // marker instead of logging the user out merely for backgrounding the app.
    if (empty($_SESSION['av_tab_token'])) $_SESSION['av_tab_token']=bin2hex(random_bytes(24));
    av_json_response(array('ok'=>true,'logged_in'=>true,'recovered'=>true,'token'=>$_SESSION['av_tab_token']));
}

if (empty($_SESSION['av_tab_token']) || !hash_equals((string)$_SESSION['av_tab_token'],$token)) {
    // A non-empty but invalid marker is still treated as a real mismatch.
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $p=session_get_cookie_params();
        setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']);
    }
    session_destroy();
    av_json_response(array('ok'=>true,'logged_in'=>false,'expired'=>true));
}

if(av_staff_user()) av_touch_staff_presence();
av_json_response(array('ok'=>true,'logged_in'=>true));
