<?php

// Run only with: php -S 127.0.0.1:8011 -t public scripts/frontend-router.php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root=dirname(__DIR__);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH) ?: '/';
$public=realpath($root.'/public');$static=realpath($root.'/public'.rawurldecode($path));
if($static && str_starts_with($static,$public.DIRECTORY_SEPARATOR) && is_file($static) && strtolower(pathinfo($static,PATHINFO_EXTENSION))!=='php') return false;
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo 'Frontend preview accepts GET requests only.';exit;}
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!$app->environment(['local','testing'])){http_response_code(404);exit;}
config(['session.driver'=>'array','cache.default'=>'array']);
$request=Illuminate\Http\Request::capture();$app->instance('request',$request);
$session=$app['session']->driver();$session->start();$request->setLaravelSession($session);
Illuminate\Support\Facades\View::share('errors',new Illuminate\Support\ViewErrorBag);
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
try {
    if($path==='/'){header('Location: /admin');exit;}
    $fixtures=require $root.'/resources/preview/data.php';
    if($path==='/login'){$view='login';$data=['frontendPreview'=>true];}
    elseif(preg_match('~^/admin(?:/(overview|repository|accounts|connectors|scans|audit))?/?$~',$path,$m)){$view='admin';$data=$fixtures('DMS',$m[1]??'overview');}
    elseif(preg_match('~^/workspaces/(ems|pms|es|oas)(?:/(overview|documents|history|snapshots))?/?$~',$path,$m)){$view='workspace';$data=$fixtures(strtoupper($m[1]),$m[2]??'overview');}
    elseif(preg_match('~^/documents/([a-f0-9-]{36})/?$~',$path,$m)){$view='document';$data=$fixtures('EMS','documents',$m[1]);}
    else{http_response_code(404);echo 'Preview page not found. <a href="/admin">Return to the frontend</a>';exit;}
    echo view($view,$data)->render();
}catch(Throwable $e){http_response_code(500);error_log($e->getMessage());echo 'Frontend rendering failed. Check the local terminal for the template error.';}
