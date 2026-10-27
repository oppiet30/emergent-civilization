<?php
declare(strict_types=1);
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Infrastructure\Database;

function checkHttp(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function requestViewer(string $url,string $method='GET'): array
{
    $context=stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>2,'follow_location'=>0]]);
    $stream=@fopen($url,'rb',false,$context);
    if ($stream===false) return [0,[], ''];
    $headers=stream_get_meta_data($stream)['wrapper_data'];
    $body=stream_get_contents($stream);fclose($stream);
    preg_match('/HTTP\/\S+ (\d+)/',$headers[0],$match);
    return [(int)$match[1],$headers,$body];
}
$root=dirname(__DIR__);
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if ($socket===false) throw new RuntimeException('Cannot reserve a test port');
$address=stream_socket_get_name($socket,false);fclose($socket);
$log=tempnam(sys_get_temp_dir(),'viewer-http-');
$process=proc_open([PHP_BINARY,'-S',$address,'-t',$root.'/public',$root.'/public/router.php'],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
if (!is_resource($process)) throw new RuntimeException('Cannot start viewer test server');
fclose($pipes[0]);
try {
    $db=Database::connect();
    $before=$db->query('SELECT id,current_tick,rng_state FROM worlds ORDER BY id')->fetchAll();
    $base='http://'.$address;
    for ($attempt=0;$attempt<100;$attempt++) {
        [$status,$headers,$html]=requestViewer($base.'/');
        if ($status===200) break;
        usleep(50000);
    }
    checkHttp($status===200 && str_contains($html,'World map'),'Viewer HTML unavailable');
    checkHttp((bool)array_filter($headers,static fn($h)=>str_starts_with(strtolower($h),'content-security-policy:')),'CSP missing');
    [$status,,$body]=requestViewer($base.'/api/worlds');
    $data=json_decode($body,true,512,JSON_THROW_ON_ERROR);
    checkHttp($status===200 && isset($data['worlds']),'World list HTTP failed');
    if ($data['worlds']) {
        $id=$data['worlds'][0]['id'];
        [$status,,$body]=requestViewer($base.'/api/worlds/'.$id);
        checkHttp($status===200 && isset(json_decode($body,true,512,JSON_THROW_ON_ERROR)['tiles']),'Snapshot HTTP failed');
    }
    foreach (['POST','DELETE','PUT','PATCH'] as $method) {
        [$status,$headers]=requestViewer($base.'/api/worlds',$method);
        checkHttp($status===405 && in_array('Allow: GET',$headers,true),'Mutation method was not rejected');
    }
    foreach (['/.env','/composer.json','/../.env','/api/unknown'] as $path) {
        [$status,,$body]=requestViewer($base.$path);
        checkHttp($status===404 && !str_contains($body,'DB_PASSWORD'),'Non-public file exposed');
    }
    foreach (['/assets/app.js','/assets/app.css'] as $path) {
        [$status]=requestViewer($base.$path);checkHttp($status===200,'Static asset missing');
    }
    checkHttp($before===$db->query('SELECT id,current_tick,rng_state FROM worlds ORDER BY id')->fetchAll(),'HTTP viewer changed world state');
    echo "PASS: viewer HTTP, read-only methods, private files, CSP and assets\n";
} finally {
    proc_terminate($process);proc_close($process);unlink($log);
}
