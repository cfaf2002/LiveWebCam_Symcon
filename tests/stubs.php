<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs).
 *
 * Lädt die Bibliothek wie Symcon, legt die Instanz an, öffnet das Formular, erzeugt die Kachel und
 * schaltet das Farbschema um. Danach: Erkennung der Quellen (YouTube, Kanal, Standbild, MJPEG, Video),
 * abgelehnte Adressen, Umschalten der Kameras, HTML-Variable und der WebHook für Standbilder
 * (mit kleinem lokalem Testserver: Bild wird ausgeliefert, HTML nicht, falscher Schlüssel = 403).
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

set_error_handler(static function (int $no, string $str): bool {
    // Header im WebHook lassen sich auf der Kommandozeile nicht setzen
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found')
        || str_contains($str, 'headers already sent');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime) und liefern bei RegisterHook keinen Rückgabewert.
$copy = sys_get_temp_dir() . '/symcon-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
        $code = (string) preg_replace(
            '/(protected function RegisterHook\(string \$HookPath\): bool\s*\{)\s*\}/',
            "$1\n        \$GLOBALS['registeredHooks'][] = \$HookPath;\n        return true;\n    }",
            $code
        );
    }
    file_put_contents($copy . '/' . basename($file), $code);
}

// Kleiner Testserver für den WebHook-Abruf
$docroot = $copy . '/www';
@mkdir($docroot);
file_put_contents($docroot . '/cam.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64));
file_put_contents($docroot . '/fake.jpg', '<html><script>alert(1)</script></html>');
$port = random_int(20000, 40000);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);

register_shutdown_function(static function () use ($copy, $docroot, $server): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    array_map('unlink', glob($docroot . '/*'));
    @rmdir($docroot);
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

function tileData(int $id): array
{
    $html = WEBCAM_GetVisualizationTile($id);
    preg_match('#var initial = (.*?);\n#', $html, $m);
    return json_decode($m[1] ?? 'null', true) ?: [];
}

function setCameras(int $id, array $rows): void
{
    IPS_SetProperty($id, 'Cameras', json_encode($rows));
    IPS_ApplyChanges($id);
}

function hook(int $id, array $get): array
{
    $_GET = $get;
    $instance = \IPS\InstanceManager::getInstanceInterface($id);
    $method = new ReflectionMethod($instance, 'ProcessHookData');
    ob_start();
    $method->invoke($instance);
    return ['body' => (string) ob_get_clean()];
}

foreach (glob(__DIR__ . '/../*/module.json') as $moduleJson) {
    $module = json_decode((string) file_get_contents($moduleJson), true);
    echo $module['name'] . PHP_EOL;
    try {
        $id = IPS_CreateInstance($module['id']);
        ok($id > 0, 'Instanz angelegt');
        $form = json_decode(IPS_GetConfigurationForm($id), true);
        ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');
        ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'Ohne Kamera Status 104');

        $tile = WEBCAM_GetVisualizationTile($id);
        ok(str_contains($tile, 'Kachel-Grundlage') && str_contains($tile, 'handleMessage'), 'Kachel mit Grundlage und Startdaten');
        ok(!str_contains($tile, '/*INITIAL_DATA*/'), 'Startdaten eingesetzt');
        foreach ([1, 2, 0] as $theme) {
            IPS_SetProperty($id, 'TileTheme', $theme);
            IPS_ApplyChanges($id);
        }
        ok(IPS_GetProperty($id, 'TileTheme') === 0, 'Farbschema umschaltbar (Symcon-Design, Dunkel, Hell)');

        // Quellen erkennen
        setCameras($id, [
            ['Active' => true, 'Name' => 'Kugelbake', 'Type' => 0, 'Source' => 'https://www.youtube.com/watch?v=nQs-B8SNcWQ', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Kanal', 'Type' => 0, 'Source' => 'https://www.youtube.com/channel/UC1234567890abcdefghijkl', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Kurz', 'Type' => 0, 'Source' => 'https://youtu.be/nQs-B8SNcWQ?si=x', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Bild', 'Type' => 0, 'Source' => 'https://example.org/cam/current.jpg', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Garten', 'Type' => 4, 'Source' => 'http://admin:geheim@127.0.0.1:' . $port . '/cam.jpg', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'MJPEG', 'Type' => 0, 'Source' => 'https://example.org/video.mjpg', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'HLS', 'Type' => 0, 'Source' => 'https://example.org/live/index.m3u8', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Anbieter', 'Type' => 0, 'Source' => 'https://player.example.org/embed?id=1', 'Proxy' => 0],
            ['Active' => false, 'Name' => 'Aus', 'Type' => 0, 'Source' => 'https://example.org/x.jpg', 'Proxy' => 0],
        ]);
        ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Mit Kameras Status 102');
        $data = tileData($id);
        $kinds = array_column($data['cams'], 'kind');
        ok($kinds === ['youtube', 'youtube', 'youtube', 'image', 'image', 'mjpeg', 'video', 'page'], 'Arten erkannt: ' . implode(', ', $kinds));
        ok(str_starts_with($data['cams'][0]['src'], 'https://www.youtube-nocookie.com/embed/nQs-B8SNcWQ?'), 'YouTube im Datenschutzmodus');
        ok(str_contains($data['cams'][1]['src'], 'live_stream?channel=UC1234567890abcdefghijkl'), 'Kanal → aktueller Livestream');
        ok(str_contains($data['cams'][2]['src'], '/embed/nQs-B8SNcWQ'), 'youtu.be-Link');
        ok($data['cams'][3]['src'] === 'https://example.org/cam/current.jpg', 'Standbild direkt (https)');
        $garden = $data['cams'][4];
        ok(str_starts_with($garden['src'], '/hook/webcam' . $id . '?cam=4&t='), 'Standbild mit Zugangsdaten über WebHook');
        ok(!str_contains(json_encode($data), 'geheim') && !str_contains($tile . WEBCAM_GetVisualizationTile($id), 'geheim'), 'Zugangsdaten kommen nie in der Kachel an');
        ok($garden['link'] === '', 'Kein Browser-Link bei Zugangsdaten');
        ok(in_array('/hook/webcam' . $id, $GLOBALS['registeredHooks'] ?? [], true), 'WebHook registriert');
        ok(count($data['cams']) === 8, 'Inaktive Kamera ausgeblendet');

        // Abgelehnte Einträge
        setCameras($id, [
            ['Active' => true, 'Name' => 'ok', 'Type' => 1, 'Source' => 'nQs-B8SNcWQ', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'js', 'Type' => 3, 'Source' => 'javascript:alert(1)', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'quote', 'Type' => 3, 'Source' => 'https://x.org/"><script>', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'handle', 'Type' => 2, 'Source' => 'https://www.youtube.com/@Kanal', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'cred', 'Type' => 3, 'Source' => 'https://a:b@x.org/', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'never', 'Type' => 4, 'Source' => 'http://a:b@x.org/s.jpg', 'Proxy' => 2],
            ['Active' => true, 'Name' => 'empty', 'Type' => 0, 'Source' => '', 'Proxy' => 0],
        ]);
        ok(IPS_GetInstance($id)['InstanceStatus'] === 201, 'Ungültige Einträge: Status 201');
        ok(count(tileData($id)['cams']) === 1, 'Nur der gültige Eintrag bleibt (javascript:, Anführungszeichen, @Name, Zugangsdaten abgelehnt)');
        $form = json_decode(IPS_GetConfigurationForm($id), true);
        ok(str_contains(json_encode($form['actions'][0], JSON_UNESCAPED_UNICODE), 'quote'), 'Fehler im Formular aufgelistet');

        // Umschalten und HTML-Variable
        setCameras($id, [
            ['Active' => true, 'Name' => 'A', 'Type' => 1, 'Source' => 'nQs-B8SNcWQ', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'B"<x>', 'Type' => 4, 'Source' => 'https://example.org/b.jpg', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'C', 'Type' => 4, 'Source' => 'http://admin:geheim@127.0.0.1:' . $port . '/cam.jpg', 'Proxy' => 0],
        ]);
        IPS_SetProperty($id, 'HtmlVariable', true);
        IPS_ApplyChanges($id);
        ok(WEBCAM_NextCamera($id) && tileData($id)['current'] === 1, 'WEBCAM_NextCamera');
        ok(GetValue(IPS_GetObjectIDByIdent('Camera', $id)) === 1, 'Variable „Kamera“ folgt');
        $html = GetValue(IPS_GetObjectIDByIdent('Embed', $id));
        ok(str_contains($html, '<img src="https://example.org/b.jpg"') && !str_contains($html, '<x>'), 'HTML-Variable maskiert');
        ok(WEBCAM_PreviousCamera($id) && WEBCAM_PreviousCamera($id) && tileData($id)['current'] === 2, 'WEBCAM_PreviousCamera mit Umlauf');
        ok(!str_contains(GetValue(IPS_GetObjectIDByIdent('Embed', $id)), 'geheim'), 'HTML-Variable ohne Zugangsdaten');
        ok(WEBCAM_SelectCamera($id, 9) === false, 'WEBCAM_SelectCamera mit ungültiger Nummer');
        RequestAction(IPS_GetObjectIDByIdent('Camera', $id), 0);
        ok(tileData($id)['current'] === 0, 'Umschalten über die Variable');
        ok(WEBCAM_GetCameraLink($id) === 'https://www.youtube.com/watch?v=nQs-B8SNcWQ', 'WEBCAM_GetCameraLink');

        // WebHook
        $src = tileData($id)['cams'][2]['src'];
        parse_str((string) parse_url($src, PHP_URL_QUERY), $q);
        ok(hook($id, ['cam' => '2', 't' => 'falsch'])['body'] === 'Forbidden', 'WebHook: falscher Schlüssel abgewiesen');
        ok(hook($id, ['cam' => '1', 't' => $q['t']])['body'] === 'Not found', 'WebHook: nur Kameras, die über Symcon laden');
        ok(str_starts_with(hook($id, ['cam' => '2', 't' => $q['t']])['body'], "\xFF\xD8\xFF"), 'WebHook liefert das Bild der Kamera');
        setCameras($id, [
            ['Active' => true, 'Name' => 'Fake', 'Type' => 4, 'Source' => 'http://127.0.0.1:' . $port . '/fake.jpg', 'Proxy' => 1],
        ]);
        ok(hook($id, ['cam' => '0', 't' => $q['t']])['body'] === 'Camera not reachable', 'WebHook liefert kein HTML aus, auch wenn es .jpg heißt');
        ob_start();
        IPS_RequestAction($id, 'NewToken', 0);
        ob_end_clean();
        ok(hook($id, ['cam' => '0', 't' => $q['t']])['body'] === 'Forbidden', 'Neuer Zugriffsschlüssel macht alte Links ungültig');
    } catch (Throwable $e) {
        ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : "Ladetest: $failed Fehler.") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
