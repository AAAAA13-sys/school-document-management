<?php

$root = dirname(__DIR__);
$bad = [];
foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'public/css', 'public/js', 'tests', 'docs', 'scripts'] as $directory) {
    if (! is_dir($root.'/'.$directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (! $file->isFile() || ! in_array($file->getExtension(), ['php', 'js', 'css', 'md', 'json', 'yaml', 'yml'])) {
            continue;
        }
        $content = file_get_contents($file->getPathname());
        if (str_starts_with($content, "\xEF\xBB\xBF") || ! mb_check_encoding($content, 'UTF-8')) {
            $bad[] = $file->getPathname();
        }
    }
}
foreach ($bad as $file) {
    fwrite(STDERR, $file.PHP_EOL);
}
echo $bad ? 'Encoding check failed.'.PHP_EOL : 'UTF-8 encoding check passed.'.PHP_EOL;
exit($bad ? 1 : 0);
