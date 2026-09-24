<?php
declare(strict_types=1);

$root='/var/www/u3618641/data/www/av-rescue.ru';
$payload=__DIR__.'/payload';
$version='v339';
$files=array('index.html','pwa.js','assets/app-panels-v339.css');
$backup=$root.'/.deploy-backups/'.date('Ymd-His').'-APP-PANELS-'.$version;

function avDeployLint(string $file): void {
    if(!is_file($file))throw new RuntimeException('MISSING_PAYLOAD: '.$file);
    if(strtolower(pathinfo($file,PATHINFO_EXTENSION))!=='php')return;
    $output=array();$code=1;
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1',$output,$code);
    if($code!==0)throw new RuntimeException('PHP_LINT_FAILED: '.$file.' :: '.implode(' | ',$output));
}
function avDeployRestore(string $root,string $backup,array $copied): void {
    foreach(array_reverse($copied) as $file){
        $saved=$backup.'/'.$file;$target=$root.'/'.$file;
        if(is_file($saved))copy($saved,$target);
        elseif(is_file($target))unlink($target);
    }
}

if(!is_dir($root))throw new RuntimeException('ROOT_NOT_FOUND');
foreach($files as $file)avDeployLint($payload.'/'.$file);
if(!mkdir($backup,0775,true))throw new RuntimeException('BACKUP_DIR_FAILED');
foreach($files as $file){
    $target=$root.'/'.$file;$saved=$backup.'/'.$file;
    if(is_file($target)){
        if(!is_dir(dirname($saved))&&!mkdir(dirname($saved),0775,true))throw new RuntimeException('BACKUP_SUBDIR_FAILED: '.$file);
        if(!copy($target,$saved))throw new RuntimeException('BACKUP_FAILED: '.$file);
    }
}
$copied=array();
try{
    foreach($files as $file){
        $source=$payload.'/'.$file;$target=$root.'/'.$file;
        if(!is_dir(dirname($target))&&!mkdir(dirname($target),0775,true))throw new RuntimeException('TARGET_SUBDIR_FAILED: '.$file);
        if(!copy($source,$target))throw new RuntimeException('DEPLOY_FAILED: '.$file);
        @chmod($target,0664);$copied[]=$file;
    }
    foreach($files as $file)avDeployLint($root.'/'.$file);
}catch(Throwable $error){avDeployRestore($root,$backup,$copied);throw $error;}
file_put_contents($root.'/data/deploy_version.php',"<?php exit; ?>\n".json_encode(array('version'=>$version,'deployed_at'=>date('c'),'backup'=>$backup),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),LOCK_EX);
echo "AV_RESCUE_V339_DEPLOYED\nBACKUP=$backup\n";
