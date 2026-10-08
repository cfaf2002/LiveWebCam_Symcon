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
        // Die Stubs kennen UnregisterHook (Symcon ≥ 8.2) noch nicht. Nachrüsten, damit eine gleichnamige eigene
        // Methode im Modul hier genauso scheitert wie in Symcon („Access level … must be protected“).
        if (!str_contains($code, 'function UnregisterHook')) {
            $code = str_replace(
                "    protected function RegisterOAuth(",
                "    protected function UnregisterHook(string \$HookPath): bool\n    {\n        \$GLOBALS['unregisteredHooks'][] = \$HookPath;\n        return true;\n    }\n\n    protected function RegisterOAuth(",
                $code
            );
        }
    }
    file_put_contents($copy . '/' . basename($file), $code);
}

// Kleiner Testserver für den WebHook-Abruf
$docroot = $copy . '/www';
@mkdir($docroot);
file_put_contents($docroot . '/cam.jpg', "\xFF\xD8\xFF\xE0" . str_repeat("\0", 64));
file_put_contents($docroot . '/fake.jpg', '<html><script>alert(1)</script></html>');
// Seiten mit den Kopfzeilen, mit denen Seiten das Einbetten verbieten (oder erlauben)
file_put_contents($docroot . '/router.php', <<<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
switch ($path) {
    case '/xfo':
        header('X-Frame-Options: SAMEORIGIN');
        break;
    case '/csp-none':
        header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'");
        break;
    case '/csp-self':
        header("Content-Security-Policy: frame-ancestors 'self' https://example.org");
        header('X-Frame-Options: DENY');
        break;
    case '/csp-star':
        // frame-ancestors hat Vorrang vor X-Frame-Options
        header('Content-Security-Policy: frame-ancestors *');
        header('X-Frame-Options: DENY');
        break;
    case '/redirect':
        header('Location: /xfo', true, 302);
        return true;
    case '/missing':
        http_response_code(404);
        break;
    case '/ok':
        break;
    case '/api/v1/videos/AbCdEfGhIjKlMnOpQrStUv':
        // nachgebaute PeerTube-API: Inhalt steht in live.json und wird vom Test geändert
        header('Content-Type: application/json');
        echo file_get_contents(__DIR__ . '/live.json');
        return true;
    default:
        return false;
}
echo '<html><body>Webcam</body></html>';
return true;
PHP);
$port = random_int(20000, 40000);
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $docroot, $docroot . '/router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
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
            ['Active' => true, 'Name' => 'PeerTube', 'Type' => 0, 'Source' => 'https://peertube.livespotting.com/w/qLZ7kfvg1PJjGPXDsBv9iy', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Wiki', 'Type' => 0, 'Source' => 'https://de.wikipedia.org/w/index.php?title=Kugelbake', 'Proxy' => 0],
            ['Active' => false, 'Name' => 'Aus', 'Type' => 0, 'Source' => 'https://example.org/x.jpg', 'Proxy' => 0],
        ]);
        ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Mit Kameras Status 102');
        $data = tileData($id);
        $kinds = array_column($data['cams'], 'kind');
        ok($kinds === ['youtube', 'youtube', 'youtube', 'image', 'image', 'mjpeg', 'video', 'page', 'peertube', 'page'], 'Arten erkannt: ' . implode(', ', $kinds));
        ok(str_starts_with($data['cams'][0]['src'], 'https://www.youtube-nocookie.com/embed/nQs-B8SNcWQ?'), 'YouTube im Datenschutzmodus');
        ok(str_contains($data['cams'][1]['src'], 'live_stream?channel=UC1234567890abcdefghijkl'), 'Kanal → aktueller Livestream');
        ok(str_contains($data['cams'][2]['src'], '/embed/nQs-B8SNcWQ'), 'youtu.be-Link');
        ok($data['cams'][3]['src'] === 'https://example.org/cam/current.jpg', 'Standbild direkt (https)');
        $garden = $data['cams'][4];
        ok(str_starts_with($garden['src'], '/hook/webcam' . $id . '?cam=4&t='), 'Standbild mit Zugangsdaten über WebHook');
        ok(!str_contains(json_encode($data), 'geheim') && !str_contains($tile . WEBCAM_GetVisualizationTile($id), 'geheim'), 'Zugangsdaten kommen nie in der Kachel an');
        ok($garden['link'] === '', 'Kein Browser-Link bei Zugangsdaten');
        ok(in_array('webcam' . $id, $GLOBALS['registeredHooks'] ?? [], true), 'WebHook registriert (Adresse ohne „/hook/“, wie Symcon es verlangt)');
        ok(count($data['cams']) === 10, 'Inaktive Kamera ausgeblendet');
        $peertube = $data['cams'][8];
        ok($peertube['src'] === 'https://peertube.livespotting.com/videos/embed/qLZ7kfvg1PJjGPXDsBv9iy?title=0&warningTitle=0&peertubeLink=0&p2p=0', 'PeerTube-Link → Einbettungs-Player');
        ok(str_ends_with($peertube['auto'], '&autoplay=1&muted=1') && str_ends_with($data['cams'][0]['auto'], '&autoplay=1&mute=1'), 'Autostart-Adressen für YouTube und PeerTube');
        ok($peertube['link'] === 'https://peertube.livespotting.com/w/qLZ7kfvg1PJjGPXDsBv9iy', 'PeerTube: Link zum Öffnen im Browser');
        ok($data['cams'][9]['src'] === 'https://de.wikipedia.org/w/index.php?title=Kugelbake', 'Andere /w/-Adressen bleiben Webseiten');

        // Abgelehnte Einträge
        setCameras($id, [
            ['Active' => true, 'Name' => 'ok', 'Type' => 1, 'Source' => 'nQs-B8SNcWQ', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'js', 'Type' => 3, 'Source' => 'javascript:alert(1)', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'quote', 'Type' => 3, 'Source' => 'https://x.org/"><script>', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'handle', 'Type' => 2, 'Source' => 'https://www.youtube.com/@Kanal', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'cred', 'Type' => 3, 'Source' => 'https://a:b@x.org/', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'never', 'Type' => 4, 'Source' => 'http://a:b@x.org/s.jpg', 'Proxy' => 2],
            ['Active' => true, 'Name' => 'empty', 'Type' => 0, 'Source' => '', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'nopt', 'Type' => 7, 'Source' => 'https://peertube.example.org/w/p/abc', 'Proxy' => 0],
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

        // Player-Seiten: verbietet die Seite das Einbetten?
        $base = 'http://127.0.0.1:' . $port;
        setCameras($id, [
            ['Active' => true, 'Name' => 'OK', 'Type' => 3, 'Source' => $base . '/ok', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'XFO', 'Type' => 3, 'Source' => $base . '/xfo', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'CSP none', 'Type' => 3, 'Source' => $base . '/csp-none', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'CSP self', 'Type' => 3, 'Source' => $base . '/csp-self', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'CSP star', 'Type' => 3, 'Source' => $base . '/csp-star', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Weiterleitung', 'Type' => 3, 'Source' => $base . '/redirect', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Fehlt', 'Type' => 3, 'Source' => $base . '/missing', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'YouTube', 'Type' => 1, 'Source' => 'nQs-B8SNcWQ', 'Proxy' => 0],
        ]);
        ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'Vor der Prüfung Status 102 (Prüfung läuft im Hintergrund)');
        ok(WEBCAM_CheckPages($id) === false, 'WEBCAM_CheckPages meldet Probleme');
        ok(IPS_GetInstance($id)['InstanceStatus'] === 202, 'Status 202 bei Seiten, die das Einbetten verbieten');
        $blocked = array_column(tileData($id)['cams'], 'blocked', 'name');
        ok($blocked === ['OK' => false, 'XFO' => true, 'CSP none' => true, 'CSP self' => true, 'CSP star' => false, 'Weiterleitung' => true, 'Fehlt' => false, 'YouTube' => false],
            'Erkannt: X-Frame-Options, frame-ancestors none/self, * erlaubt, nach Weiterleitung');
        $labels = json_encode(json_decode(IPS_GetConfigurationForm($id), true)['actions'], JSON_UNESCAPED_UNICODE);
        ok(str_contains($labels, 'XFO') && str_contains($labels, 'HTTP 404') && !str_contains($labels, 'CSP star'), 'Probleme im Formular aufgelistet (inkl. nicht erreichbar)');
        ob_start();
        IPS_RequestAction($id, 'CheckPagesNow', 0);
        ok(str_contains((string) ob_get_clean(), 'XFO'), 'Knopf „Player-Seiten jetzt prüfen“');
        setCameras($id, [
            ['Active' => true, 'Name' => 'OK', 'Type' => 3, 'Source' => $base . '/ok', 'Proxy' => 0],
        ]);
        ok(WEBCAM_CheckPages($id) === true && IPS_GetInstance($id)['InstanceStatus'] === 102, 'Nach Korrektur wieder Status 102');

        // PeerTube-Livestream: Neustart und Sendepause erkennen
        $liveFile = $docroot . '/live.json';
        $live = static function (int $state, string $playlist) use ($liveFile): void {
            file_put_contents($liveFile, json_encode(['isLive' => true, 'state' => ['id' => $state], 'updatedAt' => '2026-10-07T11:46:13Z',
                'streamingPlaylists' => [['playlistUrl' => 'https://x/hls/' . $playlist . '/master.m3u8']]]));
        };
        $live(1, 'aaaa');
        setCameras($id, [
            ['Active' => true, 'Name' => 'Elbe', 'Type' => 0, 'Source' => $base . '/w/AbCdEfGhIjKlMnOpQrStUv', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'Bild', 'Type' => 4, 'Source' => 'https://example.org/b.jpg', 'Proxy' => 0],
        ]);
        ok(tileData($id)['cams'][0]['kind'] === 'peertube', 'PeerTube auf eigenem Server erkannt');
        ok(WEBCAM_WatchLive($id) === true, 'WEBCAM_WatchLive fragt die PeerTube-API ab');
        $first = tileData($id)['cams'][0];
        ok($first['session'] !== '' && $first['offline'] === false && tileData($id)['cams'][1]['session'] === '', 'Sitzung nur für PeerTube, sendet');
        WEBCAM_WatchLive($id);
        ok(tileData($id)['cams'][0]['session'] === $first['session'], 'Ohne Neustart bleibt die Sitzung gleich (kein unnötiges Neuladen)');
        $live(1, 'bbbb');
        WEBCAM_WatchLive($id);
        ok(tileData($id)['cams'][0]['session'] !== $first['session'], 'Neustart des Streams → neue Sitzung, Kachel lädt den Player neu');
        $live(4, 'bbbb');
        WEBCAM_WatchLive($id);
        ok(tileData($id)['cams'][0]['offline'] === true, 'Stream wartet → „Livestream läuft gerade nicht“');
        unlink($liveFile);
        $before = tileData($id)['cams'][0];
        ok(WEBCAM_WatchLive($id) === false && tileData($id)['cams'][0] === $before, 'API nicht erreichbar → letzter Stand bleibt');
        IPS_SetProperty($id, 'LiveReload', 15);
        IPS_ApplyChanges($id);
        ok(tileData($id)['liveReload'] === 15, 'Einstellung „Live-Player neu laden alle“ geht an die Kachel');

        // WebHook
        setCameras($id, [
            ['Active' => true, 'Name' => 'A', 'Type' => 1, 'Source' => 'nQs-B8SNcWQ', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'B', 'Type' => 4, 'Source' => 'https://example.org/b.jpg', 'Proxy' => 0],
            ['Active' => true, 'Name' => 'C', 'Type' => 4, 'Source' => 'http://admin:geheim@127.0.0.1:' . $port . '/cam.jpg', 'Proxy' => 0],
        ]);
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
