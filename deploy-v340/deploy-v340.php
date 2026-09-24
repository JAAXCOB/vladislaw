<?php
declare(strict_types=1);

$root = '/var/www/u3618641/data/www/av-rescue.ru';
$counterId = '113022722';
$stamp = gmdate('Ymd-His') . '-METRIKA-v340';
$backupRoot = $root . '/.deploy-backups/' . $stamp;

$snippet = <<<'HTML'

<!-- Yandex.Metrika counter: AV Rescue -->
<script>
(function(m,e,t,r,i,k,a){
  m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments)};
  m[i].l=1*new Date();
  for (var j=0;j<document.scripts.length;j++){if(document.scripts[j].src===r){return;}}
  k=e.createElement(t),a=e.getElementsByTagName(t)[0],k.async=1,k.src=r,a.parentNode.insertBefore(k,a);
})(window,document,'script','https://mc.yandex.ru/metrika/tag.js?id=113022722','ym');
ym(113022722,'init',{ssr:true,webvisor:true,clickmap:true,ecommerce:'dataLayer',referrer:document.referrer,url:location.href,accurateTrackBounce:true,trackLinks:true});

(function(){
  function goal(name, params) {
    if (typeof window.ym === 'function') window.ym(113022722, 'reachGoal', name, params || {});
  }
  document.addEventListener('click', function(event) {
    var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
    if (!link) return;
    var href = link.getAttribute('href') || '';
    if (href.indexOf('tel:') === 0) goal('phone_click');
    else if (href.indexOf('max.ru/') !== -1) goal('max_click');
    else if (href.indexOf('rustore.ru/') !== -1) goal('rustore_click');
    else if (href.indexOf('executor-register') !== -1) goal('driver_register');
    else if (href.indexOf('b2b-register') !== -1) goal('b2b_register');
    else if (href.indexOf('login') !== -1 || href.indexOf('portal') !== -1) goal('portal_login');
  }, true);
  document.addEventListener('submit', function(event) {
    var form = event.target;
    var marker = ((form && form.id) || '') + ' ' + ((form && form.getAttribute && form.getAttribute('action')) || '');
    goal(/order|request|calc|заказ/i.test(marker) ? 'order_submit' : 'form_submit');
  }, true);
})();
</script>
<noscript><div><img src="https://mc.yandex.ru/watch/113022722" style="position:absolute;left:-9999px" alt=""></div></noscript>
<!-- /Yandex.Metrika counter -->
HTML;

if (!is_dir($root)) {
    fwrite(STDERR, "Site root not found\n");
    exit(1);
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file): bool {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), ['.deploy-backups', '.git', 'vendor', 'node_modules'], true);
            }
            return true;
        }
    )
);

$changed = [];
foreach ($iterator as $file) {
    if (!$file->isFile()) continue;
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['html', 'htm', 'php'], true)) continue;

    $path = $file->getPathname();
    $data = file_get_contents($path);
    if ($data === false || stripos($data, '</head>') === false) continue;
    if (strpos($data, 'mc.yandex.ru/metrika/tag.js?id=' . $counterId) !== false) continue;

    $relative = ltrim(substr($path, strlen($root)), '/');
    $backup = $backupRoot . '/' . $relative;
    if (!is_dir(dirname($backup)) && !mkdir(dirname($backup), 0755, true) && !is_dir(dirname($backup))) {
        fwrite(STDERR, "Cannot create backup directory for {$relative}\n");
        exit(1);
    }
    if (!copy($path, $backup)) {
        fwrite(STDERR, "Cannot back up {$relative}\n");
        exit(1);
    }

    $patched = preg_replace('/<\/head>/i', $snippet . "\n</head>", $data, 1);
    if (!is_string($patched) || file_put_contents($path, $patched, LOCK_EX) === false) {
        copy($backup, $path);
        fwrite(STDERR, "Cannot patch {$relative}\n");
        exit(1);
    }
    $changed[] = $relative;
}

echo "AV_RESCUE_V340_METRIKA_DEPLOYED\n";
echo "COUNTER={$counterId}\n";
echo "BACKUP={$backupRoot}\n";
echo "FILES=" . count($changed) . "\n";
foreach ($changed as $relative) echo $relative . "\n";
