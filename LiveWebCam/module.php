<?php

declare(strict_types=1);

/**
 * LiveWebCam – Live-Kameras und Webcams in der Kachel-Visualisierung
 *
 * Bindet YouTube-Livestreams, Player-Seiten von Webcam-Anbietern, Standbilder, MJPEG- und
 * Video-Streams ein. Mehrere Kameras pro Instanz, umschaltbar in der Kachel und über eine Variable.
 * Standbilder von Kameras im Heimnetz (auch mit Zugangsdaten) lädt auf Wunsch Symcon selbst
 * über einen WebHook – die Zugangsdaten verlassen den Server dabei nicht.
 *
 * @author    Armin Frohwerk
 * @copyright 2026 Armin Frohwerk
 * @license   MIT – siehe Datei LICENSE im Hauptverzeichnis
 *
 * SPDX-License-Identifier: MIT
 */
class LiveWebCam extends IPSModuleStrict
{
    // Quelltypen (Spalte „Art“ in der Kameraliste)
    private const TYPE_AUTO = 0;
    private const TYPE_YOUTUBE = 1;
    private const TYPE_YOUTUBE_CHANNEL = 2;
    private const TYPE_PAGE = 3;
    private const TYPE_IMAGE = 4;
    private const TYPE_MJPEG = 5;
    private const TYPE_VIDEO = 6;

    // Laden über Symcon (Spalte „Über Symcon laden“)
    private const PROXY_AUTO = 0;
    private const PROXY_ALWAYS = 1;
    private const PROXY_NEVER = 2;

    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
    private const MAX_CACHE_BYTES = 2 * 1024 * 1024;

    public function Create(): void
    {
        // Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('Cameras', '[]');
        $this->RegisterPropertyInteger('TileTheme', 0);
        $this->RegisterPropertyInteger('Fit', 0);
        $this->RegisterPropertyBoolean('Autoplay', true);
        $this->RegisterPropertyBoolean('StartOnTap', false);
        $this->RegisterPropertyBoolean('PauseHidden', true);
        $this->RegisterPropertyBoolean('ShowBar', true);
        $this->RegisterPropertyInteger('ImageRefresh', 10);
        $this->RegisterPropertyBoolean('PrivacyMode', true);
        $this->RegisterPropertyBoolean('HtmlVariable', false);
        $this->RegisterPropertyInteger('HtmlHeight', 360);

        $this->RegisterAttributeInteger('Current', 0);
        $this->RegisterAttributeString('Token', '');
        $this->RegisterAttributeString('TileData', '{}');
    }

    public function ApplyChanges(): void
    {
        // Never delete this line!
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $this->SetVisualizationType(1);

        if ($this->ReadAttributeString('Token') === '') {
            $this->WriteAttributeString('Token', bin2hex(random_bytes(16)));
        }

        [$cameras, $errors] = $this->Cameras();

        // Auswahl-Variable: eine Option je aktiver Kamera
        $options = [];
        foreach ($cameras as $i => $cam) {
            $options[] = ['Value' => $i, 'Caption' => $cam['name'], 'IconActive' => false, 'IconValue' => '', 'Color' => -1];
        }
        $this->MaintainVariable('Camera', $this->Translate('Camera'), VARIABLETYPE_INTEGER, [
            'PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION,
            'ICON'         => 'video',
            'OPTIONS'      => (string) json_encode($options),
        ], 1, true);
        $this->EnableAction('Camera');

        $this->MaintainVariable('Embed', $this->Translate('Live view'), VARIABLETYPE_STRING, [
            'PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT,
        ], 2, $this->ReadPropertyBoolean('HtmlVariable'));

        // WebHook nur, wenn ein Standbild über Symcon geladen wird
        foreach ($cameras as $cam) {
            if ($cam['proxy']) {
                $this->RegisterHook('/hook/webcam' . $this->InstanceID);
                break;
            }
        }

        $current = min(max(0, $this->ReadAttributeInteger('Current')), max(0, count($cameras) - 1));
        $this->WriteAttributeInteger('Current', $current);

        if ($errors !== []) {
            $this->SetStatus(201);
            $this->SetSummary($this->Translate('Check camera list'));
        } elseif ($cameras === []) {
            $this->SetStatus(104);
            $this->SetSummary($this->Translate('No camera'));
        } else {
            $this->SetStatus(102);
            $this->SetSummary($cameras[$current]['name']);
        }
        foreach ($errors as $error) {
            $this->SendDebug('Camera list', $error, 0);
        }

        $this->UpdateOutputs();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->ApplyChanges();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Camera':
                $this->SelectRun((int) $Value);
                return;
            case 'Next':
                $this->StepRun(1);
                return;
            case 'Previous':
                $this->StepRun(-1);
                return;
            case 'NewToken':
                $this->WriteAttributeString('Token', bin2hex(random_bytes(16)));
                $this->UpdateOutputs();
                echo $this->Translate('New access key created. Open tiles reload the images with the new key.');
                return;
        }
        throw new InvalidArgumentException('Invalid ident: ' . $Ident);
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        [, $errors] = $this->Cameras();
        if ($errors !== []) {
            array_unshift($form['actions'], [
                'type'    => 'Label',
                'caption' => $this->Translate('Problems in the camera list:') . "\n• " . implode("\n• ", $errors),
            ]);
        }
        return (string) json_encode($form);
    }

    /**
     * HTML der Kachel mit den aktuellen Daten als Startwert.
     */
    public function GetVisualizationTile(): string
    {
        $html = (string) file_get_contents(__DIR__ . '/tile.html');
        $data = json_decode($this->ReadAttributeString('TileData'), true) ?: $this->TileData();
        // JSON_HEX_* verhindert, dass Werte wie "</script>" das Skript der Kachel beenden
        $json = (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return str_replace('/*INITIAL_DATA*/null', $json, $html);
    }

    // ------------------------------------------------------------------
    // Öffentliche Befehle (WEBCAM_…)
    // ------------------------------------------------------------------

    /** Zeigt die Kamera mit der angegebenen Nummer (0 = erste aktive Kamera). */
    public function SelectCamera(int $Index): bool
    {
        return (bool) $this->SelectRun($Index);
    }

    /** Schaltet zur nächsten Kamera (nach der letzten wieder zur ersten). */
    public function NextCamera(): bool
    {
        return (bool) $this->StepRun(1);
    }

    /** Schaltet zur vorherigen Kamera. */
    public function PreviousCamera(): bool
    {
        return (bool) $this->StepRun(-1);
    }

    /** Liefert die Adresse zum Öffnen der aktuellen Kamera im Browser (leer bei Kameras mit Zugangsdaten). */
    public function GetCameraLink(): string
    {
        [$cameras] = $this->Cameras();
        $cam = $cameras[$this->ReadAttributeInteger('Current')] ?? null;
        return $cam === null ? '' : $cam['link'];
    }

    // ------------------------------------------------------------------
    // WebHook: Standbilder über Symcon
    // ------------------------------------------------------------------

    protected function ProcessHookData(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');

        $token = (string) ($_GET['t'] ?? '');
        $stored = $this->ReadAttributeString('Token');
        if ($stored === '' || !hash_equals($stored, $token)) {
            http_response_code(403);
            echo 'Forbidden';
            return;
        }
        [$cameras] = $this->Cameras();
        $index = filter_var($_GET['cam'] ?? null, FILTER_VALIDATE_INT);
        $cam = is_int($index) ? ($cameras[$index] ?? null) : null;
        if ($cam === null || !$cam['proxy']) {
            http_response_code(404);
            echo 'Not found';
            return;
        }
        $image = $this->ProxyImage($index, $cam['url']);
        if ($image === null) {
            http_response_code(502);
            echo 'Camera not reachable';
            return;
        }
        header('Content-Type: ' . $image['type']);
        header('Content-Length: ' . strlen($image['body']));
        echo $image['body'];
    }

    // ------------------------------------------------------------------
    // Interne Abläufe
    // ------------------------------------------------------------------

    private function SelectRun(int $index): bool
    {
        [$cameras] = $this->Cameras();
        if (!isset($cameras[$index])) {
            return false;
        }
        $this->WriteAttributeInteger('Current', $index);
        if ($this->GetStatus() === 102) {
            $this->SetSummary($cameras[$index]['name']);
        }
        $this->UpdateOutputs();
        return true;
    }

    private function StepRun(int $step): bool
    {
        [$cameras] = $this->Cameras();
        $count = count($cameras);
        if ($count === 0) {
            return false;
        }
        return $this->SelectRun((($this->ReadAttributeInteger('Current') + $step) % $count + $count) % $count);
    }

    /** Schreibt Auswahl-Variable, HTML-Variable und Kachel – jeweils nur bei Änderung. */
    private function UpdateOutputs(): void
    {
        $current = $this->ReadAttributeInteger('Current');
        if (@$this->GetIDForIdent('Camera') !== false && $this->GetValue('Camera') !== $current) {
            $this->SetValue('Camera', $current);
        }
        if ($this->ReadPropertyBoolean('HtmlVariable') && @$this->GetIDForIdent('Embed') !== false) {
            $html = $this->EmbedHtml();
            if ($this->GetValue('Embed') !== $html) {
                $this->SetValue('Embed', $html);
            }
        }

        $json = (string) json_encode($this->TileData());
        if ($json === $this->ReadAttributeString('TileData')) {
            return; // unverändert: nichts an die Visualisierung schicken
        }
        $this->WriteAttributeString('TileData', $json);
        $this->UpdateVisualizationValue($json);
    }

    private function TileData(): array
    {
        [$cameras, $errors] = $this->Cameras();
        $list = [];
        foreach ($cameras as $cam) {
            $list[] = ['name' => $cam['name'], 'kind' => $cam['kind'], 'src' => $cam['src'], 'link' => $cam['link']];
        }
        $error = '';
        if ($cameras === []) {
            $error = $errors === [] ? $this->Translate('Please add a camera in the instance.') : $this->Translate('Please check the camera list in the instance.');
        }
        return [
            'theme'       => $this->ReadPropertyInteger('TileTheme'),
            'fit'         => $this->ReadPropertyInteger('Fit') === 1 ? 'contain' : 'cover',
            'autoplay'    => $this->ReadPropertyBoolean('Autoplay'),
            'startOnTap'  => $this->ReadPropertyBoolean('StartOnTap'),
            'pauseHidden' => $this->ReadPropertyBoolean('PauseHidden'),
            'bar'         => $this->ReadPropertyBoolean('ShowBar'),
            'refresh'     => max(2, min(3600, $this->ReadPropertyInteger('ImageRefresh'))),
            'current'     => $this->ReadAttributeInteger('Current'),
            'cams'        => $list,
            'error'       => $error,
            't'           => [
                'play'        => $this->Translate('Play'),
                'paused'      => $this->Translate('Paused'),
                'unreachable' => $this->Translate('Camera not reachable'),
                'retry'       => $this->Translate('Retrying automatically'),
                'noCamera'    => $this->Translate('No camera'),
                'previous'    => $this->Translate('Previous camera'),
                'next'        => $this->Translate('Next camera'),
                'fullscreen'  => $this->Translate('Full screen'),
                'open'        => $this->Translate('Open in browser'),
                'reload'      => $this->Translate('Reload'),
            ],
        ];
    }

    /** HTML für die Variable „Live-Bild“ (WebFront, ältere Visualisierungen). */
    private function EmbedHtml(): string
    {
        [$cameras] = $this->Cameras();
        $cam = $cameras[$this->ReadAttributeInteger('Current')] ?? null;
        if ($cam === null) {
            return '';
        }
        $height = max(120, min(2000, $this->ReadPropertyInteger('HtmlHeight')));
        $src = htmlspecialchars($this->AutoplaySrc($cam), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = htmlspecialchars($cam['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $fit = $this->ReadPropertyInteger('Fit') === 1 ? 'contain' : 'cover';
        $style = 'display:block;width:100%;height:' . $height . 'px;border:0;object-fit:' . $fit . ';background:#000';
        switch ($cam['kind']) {
            case 'image':
            case 'mjpeg':
                return '<img src="' . $src . '" alt="' . $title . '" style="' . $style . '">';
            case 'video':
                return '<video src="' . $src . '" title="' . $title . '" style="' . $style . '" muted autoplay playsinline controls></video>';
        }
        return '<iframe src="' . $src . '" title="' . $title . '" style="' . $style . '" allow="autoplay; fullscreen; picture-in-picture; encrypted-media"'
            . ' allowfullscreen referrerpolicy="strict-origin-when-cross-origin"'
            . ' sandbox="allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox"></iframe>';
    }

    private function AutoplaySrc(array $cam): string
    {
        if ($cam['kind'] === 'youtube' && $this->ReadPropertyBoolean('Autoplay')) {
            return $cam['src'] . '&autoplay=1&mute=1';
        }
        return $cam['src'];
    }

    // ------------------------------------------------------------------
    // Kameraliste auswerten
    // ------------------------------------------------------------------

    /**
     * Liefert die aktiven, gültigen Kameras (fortlaufend nummeriert) und Fehlermeldungen.
     *
     * @return array{0: list<array{name: string, kind: string, src: string, link: string, proxy: bool, url: string}>, 1: list<string>}
     */
    private function Cameras(): array
    {
        $rows = json_decode($this->ReadPropertyString('Cameras'), true);
        if (!is_array($rows)) {
            return [[], [$this->Translate('Camera list is not readable')]];
        }
        $cameras = [];
        $errors = [];
        foreach (array_values($rows) as $n => $row) {
            if (!is_array($row) || !($row['Active'] ?? true)) {
                continue;
            }
            $name = trim((string) ($row['Name'] ?? ''));
            if ($name === '') {
                $name = $this->Translate('Camera') . ' ' . ($n + 1);
            }
            $result = $this->Resolve((string) ($row['Source'] ?? ''), (int) ($row['Type'] ?? self::TYPE_AUTO), (int) ($row['Proxy'] ?? self::PROXY_AUTO));
            if (is_string($result)) {
                $errors[] = $name . ': ' . $result;
                continue;
            }
            $result['name'] = mb_substr($name, 0, 60);
            $cameras[] = $result;
        }
        // Adresse des WebHooks erst jetzt einsetzen: Nummer = Position in der Liste der aktiven Kameras
        foreach ($cameras as $i => $cam) {
            if ($cam['proxy']) {
                $cameras[$i]['src'] = '/hook/webcam' . $this->InstanceID . '?cam=' . $i . '&t=' . $this->ReadAttributeString('Token');
            }
        }
        return [$cameras, $errors];
    }

    /**
     * Prüft eine Quelle und baut die Adressen für Kachel und Browser.
     *
     * @return array{kind: string, src: string, link: string, proxy: bool, url: string}|string Fehlermeldung als String
     */
    private function Resolve(string $source, int $type, int $proxyMode): array|string
    {
        $source = trim($source);
        if ($source === '') {
            return $this->Translate('no address entered');
        }
        if (preg_match('/[\x00-\x20\x7f"<>\\\\`]/', $source)) {
            return $this->Translate('address contains invalid characters');
        }
        if ($type === self::TYPE_AUTO) {
            $type = self::DetectType($source);
        }
        $host = $this->ReadPropertyBoolean('PrivacyMode') ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';

        if ($type === self::TYPE_YOUTUBE) {
            $id = self::YouTubeId($source);
            if ($id === null) {
                return $this->Translate('no YouTube video found in the address');
            }
            return [
                'kind'  => 'youtube',
                'src'   => $host . '/embed/' . $id . '?playsinline=1&rel=0',
                'link'  => 'https://www.youtube.com/watch?v=' . $id,
                'proxy' => false,
                'url'   => '',
            ];
        }
        if ($type === self::TYPE_YOUTUBE_CHANNEL) {
            $channel = self::YouTubeChannel($source);
            if ($channel === null) {
                return $this->Translate('please enter the channel ID (UC…) – a @name cannot be resolved');
            }
            return [
                'kind'  => 'youtube',
                'src'   => $host . '/embed/live_stream?channel=' . $channel . '&playsinline=1&rel=0',
                'link'  => 'https://www.youtube.com/channel/' . $channel . '/live',
                'proxy' => false,
                'url'   => '',
            ];
        }

        $url = self::ParseUrl($source);
        if ($url === null) {
            return $this->Translate('not a valid http(s) address');
        }
        $hasCredentials = $url['user'] !== '' || $url['pass'] !== '';
        $kind = match ($type) {
            self::TYPE_IMAGE => 'image',
            self::TYPE_MJPEG => 'mjpeg',
            self::TYPE_VIDEO => 'video',
            default          => 'page',
        };

        if ($kind === 'image') {
            $proxy = $proxyMode === self::PROXY_ALWAYS
                || ($proxyMode === self::PROXY_AUTO && ($hasCredentials || $url['scheme'] === 'http'));
            if ($hasCredentials && !$proxy) {
                return $this->Translate('access data in the address only with „load via Symcon“');
            }
            return [
                'kind'  => 'image',
                'src'   => $proxy ? '' : $url['clean'],
                'link'  => $hasCredentials ? '' : $url['clean'],
                'proxy' => $proxy,
                'url'   => $url['full'],
            ];
        }
        if ($hasCredentials) {
            return $this->Translate('access data in the address are only possible for still images');
        }
        return ['kind' => $kind, 'src' => $url['clean'], 'link' => $url['clean'], 'proxy' => false, 'url' => ''];
    }

    private static function DetectType(string $source): int
    {
        if (self::YouTubeChannel($source) !== null && !str_contains($source, 'v=')) {
            return self::TYPE_YOUTUBE_CHANNEL;
        }
        if (self::YouTubeId($source) !== null) {
            return self::TYPE_YOUTUBE;
        }
        $path = strtolower((string) parse_url($source, PHP_URL_PATH));
        $query = strtolower((string) parse_url($source, PHP_URL_QUERY));
        if (preg_match('/\.(jpe?g|png|gif|webp)$/', $path) || preg_match('/snapshot|snap\.cgi|image\.cgi|still/', $path . '?' . $query)) {
            return self::TYPE_IMAGE;
        }
        if (preg_match('/\.(mjpe?g)$|mjpeg|mjpg|video\.cgi|faststream/', $path . '?' . $query)) {
            return self::TYPE_MJPEG;
        }
        if (preg_match('/\.(m3u8|mp4|webm)$/', $path)) {
            return self::TYPE_VIDEO;
        }
        return self::TYPE_PAGE;
    }

    /** Video-ID aus ID, watch-, youtu.be-, live-, shorts- oder embed-Adresse. */
    private static function YouTubeId(string $source): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $source)) {
            return $source;
        }
        $host = self::YouTubeHost($source);
        if ($host === null) {
            return null;
        }
        $path = (string) parse_url($source, PHP_URL_PATH);
        parse_str((string) parse_url($source, PHP_URL_QUERY), $query);
        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', trim($path, '/'))[0] ?? null;
        } elseif (isset($query['v']) && is_string($query['v'])) {
            $id = $query['v'];
        } elseif (preg_match('#^/(embed|live|shorts|v)/([^/?]+)#', $path, $m) && $m[2] !== 'live_stream') {
            $id = $m[2];
        }
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
    }

    /** Kanal-ID (UC…) aus ID, /channel/-Adresse oder live_stream?channel=. */
    private static function YouTubeChannel(string $source): ?string
    {
        $pattern = '/^UC[A-Za-z0-9_-]{22}$/';
        if (preg_match($pattern, $source)) {
            return $source;
        }
        if (self::YouTubeHost($source) === null) {
            return null;
        }
        parse_str((string) parse_url($source, PHP_URL_QUERY), $query);
        if (isset($query['channel']) && is_string($query['channel']) && preg_match($pattern, $query['channel'])) {
            return $query['channel'];
        }
        if (preg_match('#^/channel/(UC[A-Za-z0-9_-]{22})(/|$)#', (string) parse_url($source, PHP_URL_PATH), $m)) {
            return $m[1];
        }
        return null;
    }

    private static function YouTubeHost(string $source): ?string
    {
        $scheme = strtolower((string) parse_url($source, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = preg_replace('/^(www|m|music)\./', '', strtolower((string) parse_url($source, PHP_URL_HOST)));
        return in_array($host, ['youtube.com', 'youtube-nocookie.com', 'youtu.be'], true) ? $host : null;
    }

    /**
     * Zerlegt eine http(s)-Adresse.
     *
     * @return array{scheme: string, user: string, pass: string, clean: string, full: string}|null
     *         clean = ohne Zugangsdaten (für Kachel und Browser), full = mit (nur für den Abruf durch Symcon)
     */
    private static function ParseUrl(string $source): ?array
    {
        $parts = parse_url($source);
        if (!is_array($parts)) {
            return null;
        }
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
            return null;
        }
        $rest = $host . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $user = rawurldecode($parts['user'] ?? '');
        $pass = rawurldecode($parts['pass'] ?? '');
        return [
            'scheme' => $scheme,
            'user'   => $user,
            'pass'   => $pass,
            'clean'  => $scheme . '://' . $rest,
            'full'   => $source,
        ];
    }

    // ------------------------------------------------------------------
    // Abruf von Standbildern (nur für den WebHook)
    // ------------------------------------------------------------------

    /**
     * Liefert das Bild aus dem Zwischenspeicher oder holt es neu.
     *
     * @return array{type: string, body: string}|null
     */
    private function ProxyImage(int $index, string $url): ?array
    {
        $maxAge = max(1, min(3600, $this->ReadPropertyInteger('ImageRefresh')) - 1);
        $key = 'Image' . $index . '_' . substr(hash('sha256', $url), 0, 12);
        $cached = json_decode($this->GetBuffer($key), true);
        if (is_array($cached) && (time() - (int) ($cached['time'] ?? 0)) < $maxAge) {
            $body = base64_decode((string) ($cached['body'] ?? ''), true);
            if (is_string($body) && $body !== '') {
                return ['type' => (string) $cached['type'], 'body' => $body];
            }
        }
        $image = $this->FetchImage($url);
        if ($image !== null && strlen($image['body']) <= self::MAX_CACHE_BYTES) {
            $this->SetBuffer($key, (string) json_encode(['time' => time(), 'type' => $image['type'], 'body' => base64_encode($image['body'])]));
        }
        return $image;
    }

    /**
     * Holt ein Bild per HTTP(S). Ausgeliefert wird nur, was nach Dateikopf wirklich ein Bild ist –
     * so kann eine Kamera oder ein fremder Server über den WebHook kein HTML oder Skript einschleusen.
     *
     * @return array{type: string, body: string}|null
     */
    private function FetchImage(string $url): ?array
    {
        $parts = self::ParseUrl($url);
        if ($parts === null) {
            return null;
        }
        $ch = curl_init($parts['clean']);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'IP-Symcon LiveWebCam',
            CURLOPT_NOPROGRESS     => false,
            CURLOPT_XFERINFOFUNCTION => static fn ($ch, int $total, int $now): int => $now > self::MAX_IMAGE_BYTES ? 1 : 0,
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
            $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
        }
        if ($parts['user'] !== '' || $parts['pass'] !== '') {
            // Basic oder Digest – je nachdem, was die Kamera verlangt
            $options[CURLOPT_HTTPAUTH] = CURLAUTH_ANY;
            $options[CURLOPT_USERPWD] = $parts['user'] . ':' . $parts['pass'];
            // Zugangsdaten nicht an eine andere Adresse weitergeben
            $options[CURLOPT_UNRESTRICTED_AUTH] = false;
        }
        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        // Nie die Adresse mit Zugangsdaten ins Debug schreiben
        if (!is_string($body) || $code !== 200) {
            $this->SendDebug('Image', $parts['clean'] . ' → ' . ($error !== '' ? $error : 'HTTP ' . $code), 0);
            return null;
        }
        $type = self::ImageType($body);
        if ($type === null) {
            $this->SendDebug('Image', $parts['clean'] . ' → ' . $this->Translate('response is not an image'), 0);
            return null;
        }
        return ['type' => $type, 'body' => $body];
    }

    private static function ImageType(string $body): ?string
    {
        return match (true) {
            str_starts_with($body, "\xFF\xD8\xFF")                                         => 'image/jpeg',
            str_starts_with($body, "\x89PNG\r\n\x1A\n")                                    => 'image/png',
            str_starts_with($body, 'GIF87a'), str_starts_with($body, 'GIF89a')             => 'image/gif',
            str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP'               => 'image/webp',
            default                                                                         => null,
        };
    }
}
