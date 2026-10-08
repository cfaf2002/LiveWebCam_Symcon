# LiveWebCam für IP-Symcon

[![IP-Symcon ab 8.1](https://img.shields.io/badge/IP--Symcon-ab_8.1-0b6fb3.svg)](https://www.symcon.de)
[![Optimiert für Symcon 9.0](https://img.shields.io/badge/optimiert_f%C3%BCr-Symcon_9.0-0b6fb3.svg)](https://www.symcon.de/de/service/dokumentation/installation/migrationen/v81-v90-q1-2026/)
[![Modul-Version 1.4 (Build 9)](https://img.shields.io/badge/Modul--Version-1.4_(Build_9)-informational.svg)](library.json)
[![Tests](https://github.com/cfaf2002/LiveWebCam_Symcon/actions/workflows/tests.yml/badge.svg)](https://github.com/cfaf2002/LiveWebCam_Symcon/actions/workflows/tests.yml)
[![PHP 8.3 und 8.5](https://img.shields.io/badge/PHP-8.3_%7C_8.5-777bb4.svg?logo=php&logoColor=white)](https://www.php.net)
[![SDK: IPSModuleStrict](https://img.shields.io/badge/SDK-IPSModuleStrict-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/module/)
[![Variablen: Darstellungen](https://img.shields.io/badge/Variablen-Darstellungen-success.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/darstellungen/)
[![Kachel-Visualisierung: HTML-SDK](https://img.shields.io/badge/Kachel--Visualisierung-HTML--SDK-orange.svg)](https://www.symcon.de/de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/html-sdk/)
[![Farbschema: Symcon-Design, Dunkel, Hell](https://img.shields.io/badge/Farbschema-Symcon--Design_%7C_Dunkel_%7C_Hell-blueviolet.svg)](STYLEGUIDE.md)
![Sprachen: Deutsch | Englisch](https://img.shields.io/badge/Sprachen-Deutsch_%7C_Englisch-blueviolet.svg)
[![Lizenz: MIT](https://img.shields.io/badge/Lizenz-MIT-green.svg)](LICENSE)
[![Quellen: YouTube | Webcam-Anbieter | eigene Kameras](https://img.shields.io/badge/Quellen-YouTube_%7C_Webcam--Anbieter_%7C_eigene_Kameras-lightgrey.svg)](#4-einrichtung)

IP-Symcon-Modul, das Live-Kameras und Webcams in die Kachel-Visualisierung holt: YouTube-Livestreams, Player-Seiten von Webcam-Anbietern, Standbilder, MJPEG- und Video-Streams – mehrere Kameras pro Instanz, umschaltbar in der Kachel und über eine Variable.

> Die Bilder und Streams gehören den jeweiligen Betreibern. Das Modul bettet nur deren offizielle Player bzw. öffentlich angebotene Bilder ein und speichert nichts dauerhaft.

Autor: Armin Frohwerk · Lizenz: MIT

## Inhalt

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen und Technik](#2-voraussetzungen-und-technik)
3. [Installation](#3-installation)
4. [Einrichtung](#4-einrichtung)
5. [Kachel](#5-kachel)
6. [Variablen und Darstellungen](#6-variablen-und-darstellungen)
7. [PHP-Befehle](#7-php-befehle)
8. [Sicherheit und Geschwindigkeit](#8-sicherheit-und-geschwindigkeit)
9. [Entwicklung und Tests](#9-entwicklung-und-tests)
10. [Changelog](#10-changelog)
11. [Lizenz](#11-lizenz)

## 1. Funktionsumfang

- Beliebig viele Kameras pro Instanz, Reihenfolge per Ziehen, einzeln (de)aktivierbar
- Quellen:
  - **YouTube-Video oder Livestream** – Link (`watch?v=`, `youtu.be/`, `/live/`, `/embed/`) oder die 11-stellige Video-ID
  - **YouTube-Kanal** – Kanal-ID (`UC…`): zeigt immer den aktuellen Livestream des Kanals, auch wenn der Betreiber den Stream neu startet
  - **PeerTube-Video oder Livestream** – normaler PeerTube-Link (`…/w/<id>`), z. B. von livespotting; das Modul baut daraus den reinen Player
  - **Player-Seite** – Einbettungs-Link eines Webcam-Anbieters (iframe)
  - **Standbild** (JPG/PNG/GIF/WebP) – wird in einstellbarem Takt neu geladen
  - **MJPEG-Stream** – z. B. von IP-Kameras im Heimnetz
  - **Video** (MP4, HLS) – direkt im Browser, ohne Zusatzbibliothek; HLS (`.m3u8`) nur in Browsern, die es selbst können (Safari, iOS, Android, neuere Chrome/Edge) – sonst zeigt die Kachel einen Hinweis statt endloser Neuversuche
- „Automatisch erkennen“ ordnet die Adresse selbst einer Art zu
- **Livestreams werden beobachtet (PeerTube, z. B. livespotting):** Symcon fragt jede Minute bei PeerTube nach. Startet der Betreiber den Stream neu – sonst bleibt der eingebettete Player auf dem letzten Bild stehen –, lädt die Kachel den Player selbst neu; sendet die Kamera gerade nicht, steht „Livestream läuft gerade nicht“ in der Kachel, bis sie wieder sendet. Für andere Quellen lässt sich der Live-Player zusätzlich in festen Abständen neu laden
- **Prüfung der Player-Seiten:** Nach dem Speichern prüft Symcon im Hintergrund, ob eine eingetragene Seite das Einbetten verbietet (`X-Frame-Options`, `Content-Security-Policy: frame-ancestors`) oder nicht erreichbar ist. Das Formular nennt Kamera und Grund, die Instanz meldet Status 202, und die Kachel zeigt einen Hinweis statt des Verbotssymbols des Browsers
- **Über Symcon laden** für Standbilder: Symcon holt das Bild selbst über einen WebHook. Damit funktionieren Kameras im Heimnetz – auch mit Zugangsdaten (`http://benutzer:passwort@…`, Basic oder Digest) – unterwegs in der App, und die Zugangsdaten verlassen den Server nicht
- YouTube wahlweise im erweiterten Datenschutzmodus (`youtube-nocookie.com`)
- Eigene Kachel: Bild füllend oder ganz, Kameraleiste zum Umschalten, Wischen zum Wechseln, Vollbild, „Im Browser öffnen“, Neu laden
- Streams auf Wunsch erst nach Antippen starten und automatisch anhalten, wenn die Kachel nicht zu sehen ist
- Variable „Kamera“ zum Umschalten (auch aus Ablaufplänen und Skripten) und optional „Live-Bild“ als HTML für WebFront und ältere Visualisierungen
- Deutsch und Englisch

## 2. Voraussetzungen und Technik

- IP-Symcon ab 8.1, optimiert für 9.0; PHP 8.3 und 8.5
- Basisklasse `IPSModuleStrict`, Variablen mit Darstellungen (keine Profile)
- Kachel über das HTML-SDK der Kachel-Visualisierung
- Für „Über Symcon laden“: Symcon muss die Kamera erreichen können; der WebHook `/hook/webcam<InstanzID>` wird automatisch angelegt

## 3. Installation

Im Objektbaum unter **Kern Instanzen → Modules** das Repository hinzufügen:

```
https://github.com/cfaf2002/LiveWebCam_Symcon
```

Danach eine Instanz **LiveWebCam** anlegen.

## 4. Einrichtung

### Kameras

| Spalte | Bedeutung |
| :-- | :-- |
| Aktiv | Kamera in Kachel und Auswahl zeigen |
| Name | Anzeigename in der Kachel |
| Art | Automatisch erkennen, YouTube-Video/Livestream, YouTube-Kanal, Player-Seite, Standbild, MJPEG-Stream, Video |
| Adresse, Video-ID oder Kanal-ID | die Quelle |
| Über Symcon laden | nur für Standbilder: Automatisch (bei `http` oder Zugangsdaten), Immer, Nie |

**Wichtig:** Nicht die Adresse der Webseite eintragen, auf der die Kamera zu sehen ist (z. B. die Seite der Kurverwaltung), sondern den Link zum **Player** – sonst erscheint die ganze Webseite mit Menü in der Kachel.

**Beispiel – Webcams in Cuxhaven** (Betreiber livespotting, auch als PeerTube- bzw. YouTube-Stream):

| Name | Art | Adresse |
| :-- | :-- | :-- |
| Kugelbake | Automatisch erkennen | `https://peertube.livespotting.com/w/xyBqKQEAeS6fJgFa91B9ox` |
| Duhnen Rettungsstation | Automatisch erkennen | `https://peertube.livespotting.com/w/qLZ7kfvg1PJjGPXDsBv9iy` |
| Sahlenburg | Automatisch erkennen | `https://peertube.livespotting.com/w/pQF6LXtAFguUDK8sZfmjsz` |
| Altenbruch | Automatisch erkennen | `https://peertube.livespotting.com/w/7no6Tm9JDjyDKJKBMYGFyE` |

**YouTube-Livestreams:** Startet der Betreiber den Livestream neu, bekommt er eine neue Video-ID, und unter der alten Adresse steht nur noch „Diese Livestream-Aufzeichnung ist nicht verfügbar“. Dann entweder den neuen Link eintragen oder – robuster – Art „YouTube-Kanal“ mit der Kanal-ID (`UC…`, zu finden in der Kanal-Adresse oder unter „Kanal teilen → Kanal-ID kopieren“). Ein `@Name` lässt sich ohne YouTube-Schlüssel nicht auflösen. PeerTube-Livestreams behalten dagegen ihre Adresse.

**Hinweise:**
- Bei Webcam-Anbietern den **Einbettungs-Link** aus „Teilen/Einbetten“ verwenden, nicht die normale Seite – viele Seiten verbieten das Einbetten. Bei PeerTube und YouTube genügt der normale Link, das Modul baut den Player-Link selbst.
- Manche Anbieter-Player starten grundsätzlich erst nach Antippen (z. B. Windy) – das kann die Kachel nicht ändern. YouTube und PeerTube starten automatisch (stumm).
- PeerTube wird ohne Titelzeile und mit `p2p=0` eingebettet: Der Browser lädt den Stream nur herunter und verteilt ihn nicht an andere Zuschauer weiter.
- Spielt ein YouTube-Kanal im Datenschutzmodus nicht, den Schalter „YouTube im erweiterten Datenschutzmodus“ ausschalten.
- Kameras mit `http` in einer Visualisierung über `https` (z. B. Symcon Connect) blockiert der Browser. Standbilder deshalb über Symcon laden; MJPEG und Video brauchen dafür eine `https`-Adresse.
- Ungültige Einträge stehen mit Grund oben unter **Aktionen**; die übrigen Kameras laufen weiter.
- Seiten, die das Einbetten verbieten (z. B. `livespotting.tv` oder die Webcam-Seiten der Kurverwaltungen), stehen ebenfalls dort. Geprüft wird nach jedem Speichern; danach sieht Symcon täglich nach und prüft Seiten erneut, deren Prüfung älter als eine Woche ist oder am Netz scheiterte; „Player-Seiten jetzt prüfen“ prüft sofort.

### Kachel

| Einstellung | Standard |
| :-- | :-- |
| Farbschema der Kachel | Symcon-Design |
| Bildausschnitt | Kachel füllen |
| Kameraleiste unter dem Bild | an |
| Videos automatisch starten (stumm) | an |
| Streams erst nach Antippen starten | aus |
| Stream anhalten, wenn die Kachel nicht zu sehen ist | an |
| Live-Player neu laden alle … min | 0 (aus) |
| Standbilder neu laden alle | 10 s |

## 5. Kachel

Die Kachel zeigt die aktuelle Kamera mit Namen (roter Punkt = Live-Stream), oben rechts Neu laden, Im Browser öffnen und Vollbild. Bei mehreren Kameras gibt es darunter eine Leiste zum Umschalten; auf kleinen Kacheln stattdessen Pfeile im Bild. Auf Standbildern wechselt auch Wischen die Kamera.

Die gewählte Kamera gilt für alle Anzeigen gleichzeitig (wie ein Sender am Fernseher) und steht auch in der Variable „Kamera“.

Farbschema nach Hausstil: **Symcon-Design** (Farben der Visualisierung), **Dunkel**, **Hell**.

## 6. Variablen und Darstellungen

| Ident | Name | Typ | Darstellung |
| :-- | :-- | :-- | :-- |
| `Camera` | Kamera | Integer | Aufzählung mit den Namen der aktiven Kameras, schaltbar |
| `Embed` | Live-Bild | String | HTML (nur mit „Variable Live-Bild“) |

## 7. PHP-Befehle

```php
WEBCAM_SelectCamera(int $InstanzID, int $Nummer): bool   // 0 = erste aktive Kamera
WEBCAM_NextCamera(int $InstanzID): bool
WEBCAM_PreviousCamera(int $InstanzID): bool
WEBCAM_GetCameraLink(int $InstanzID): string             // Adresse zum Öffnen im Browser, leer bei Zugangsdaten
WEBCAM_CheckPages(int $InstanzID): bool                  // Player-Seiten prüfen; true = alle lassen sich einbetten
WEBCAM_WatchLive(int $InstanzID): bool                   // PeerTube-Livestreams abfragen (läuft automatisch jede Minute)
```

## 8. Sicherheit und Geschwindigkeit

- Nur `http`/`https`-Adressen; Adressen mit Leerzeichen, Anführungszeichen oder spitzen Klammern werden abgelehnt.
- Fremde Seiten laufen in einem iframe mit `sandbox` und dürfen die Visualisierung nicht umleiten.
- Texte in der Kachel nur per `textContent`, Kacheldaten mit `JSON_HEX_*`, HTML-Variable mit `htmlspecialchars`.
- Zugangsdaten bleiben auf dem Server: Sie gehen weder in die Kachel noch in die HTML-Variable noch ins Debug.
- Der WebHook verlangt einen zufälligen Zugriffsschlüssel (128 Bit, Vergleich mit `hash_equals`) und liefert nur Kameras aus, die über Symcon laden. Ausgeliefert wird nur, was am Dateikopf wirklich ein Bild ist (kein HTML/Skript über den Symcon-Server). Abruf mit Zeitlimit, Größengrenze 8 MB, Zertifikatsprüfung bei `https`. „Neuen Zugriffsschlüssel erzeugen“ macht alte Links ungültig.
- Mehrere Anzeigen teilen sich ein Bild: Standbilder werden kurz zwischengespeichert, Symcon fragt die Kamera höchstens einmal je Takt.
- Der Player wird nur neu aufgebaut, wenn sich die Kamera ändert; Standbilder laden nur bei sichtbarer Kachel; Streams enden auf Wunsch 10 s nach dem Wegblättern. MJPEG-Streams laufen direkt vom Browser zur Kamera und belasten Symcon nicht.
- Variablen und Kachel werden nur bei Änderung geschrieben.
- Die Beobachtung der Livestreams fragt je PeerTube-Kamera einmal pro Minute eine kleine JSON-Antwort ab (Zeitlimit 6 s, höchstens 256 KB); die Kachel lädt den Player nur neu, wenn sich die Sendung wirklich geändert hat. Ist die API nicht erreichbar, bleibt alles, wie es ist.
- Die Prüfung der Player-Seiten läuft per Timer nach dem Speichern, nicht im Speichervorgang selbst, liest nur die Kopfzeilen (höchstens 512 KB, Zeitlimit 8 s, Zertifikatsprüfung) und wird eine Woche lang zwischengespeichert.

## 9. Entwicklung und Tests

| Datei | Inhalt |
| :-- | :-- |
| `LiveWebCam/module.php` | Modul |
| `LiveWebCam/tile.html` | Kachel |
| `LiveWebCam/form.json`, `locale.json` | Formular und deutsche Übersetzung |
| `tests/structure.php` | Strukturprüfung nach Hausstil |
| `tests/stubs.php` | Ladetest mit den offiziellen Symcon-Stubs inkl. Funktionstests |

```
php tests/structure.php
php tests/stubs.php <Pfad zu SymconStubs>
```

Der Ladetest prüft neben Formular, Kachel und Farbschema die Erkennung aller Quellarten, das Ablehnen unsicherer Adressen, das Umschalten, die HTML-Variable und den WebHook mit einem kleinen lokalen Testserver (Bild wird geliefert, getarntes HTML nicht, falscher oder alter Schlüssel wird abgewiesen). GitHub Actions führt alles bei jedem Push mit PHP 8.3 und 8.5 aus.

## 10. Changelog

| Version | Build | Datum | Beschreibung |
|---|---|---|---|
| 1.4 | 9 | 08.10.2026 | **Behoben:** Modul ließ sich in Symcon nicht laden („Modul der Instanz wurde nicht korrekt geladen“, Kacheln „Internal Server Error“) – eine eigene Methode `UnregisterHook` kollidierte mit der gleichnamigen Methode der Basisklasse (Symcon ≥ 8.2); jetzt wird die von Symcon genutzt. WebHook wird ohne „/hook/“ angemeldet, wie Symcon es verlangt. Ladetest kennt `UnregisterHook`, damit so etwas künftig auffällt |
| 1.4 | 8 | 07.10.2026 | Korrekturen: Standbild in der Variable „Live-Bild“ aktualisiert sich im eingestellten Takt (bisher blieb das erste Bild stehen); Kachel holt nach kurzer Unsichtbarkeit Kamerawechsel und fällige Neuversuche nach; HLS-Streams (`.m3u8`) in Browsern ohne HLS-Unterstützung (z. B. Firefox) zeigen einen verständlichen Hinweis statt einer Fehlerschleife; nicht erreichbare Kamera wird 30 s lang sofort gemeldet statt jeden Abruf bis zu 8 s zu blockieren; Player-Seiten werden regelmäßig erneut geprüft; Benutzername mit Doppelpunkt funktioniert; WebHook wird abgemeldet, wenn keine Kamera mehr über Symcon lädt oder die Instanz gelöscht wird |
| 1.3 | 7 | 07.10.2026 | PeerTube-Livestreams werden beobachtet: nach einem Neustart des Streams (Player blieb bisher auf dem letzten Bild stehen) lädt die Kachel den Player selbst neu; sendet die Kamera nicht, zeigt die Kachel „Livestream läuft gerade nicht“; neue Einstellung „Live-Player neu laden alle … min“ und Befehl `WEBCAM_WatchLive` |
| 1.2 | 6 | 06.10.2026 | Bereinigt: ungenutzter Kachel-Text und ungenutzte Konstante entfernt; README ohne veraltetes YouTube-Beispiel, mit Hinweisen zu neu gestarteten YouTube-Livestreams und zu Playern, die nur nach Antippen starten |
| 1.2 | 5 | 06.10.2026 | Prüfung der Player-Seiten: erkennt Seiten, die das Einbetten verbieten (`X-Frame-Options`, `frame-ancestors`) oder nicht erreichbar sind – Hinweis im Formular, Status 202, Kachel zeigt eine Erklärung statt des Verbotssymbols; Knopf „Player-Seiten jetzt prüfen“ und Befehl `WEBCAM_CheckPages` |
| 1.1 | 4 | 06.10.2026 | PeerTube (z. B. livespotting): normaler Link wird automatisch zum reinen Player (ohne Titel, ohne P2P); Hinweis im Formular, den Player-Link statt der Webseite einzutragen; Beispiele für die Webcams in Cuxhaven |
| 1.0 | 3 | 06.10.2026 | Hausstil: Regel für die Modulliste (`vendor` gesetzt, höchstens ein Alias) in `STYLEGUIDE.md` und Strukturprüfung ergänzt |
| 1.0 | 2 | 06.10.2026 | Modulliste: Hersteller „Webcam“ statt „(Gerät)“, keine zusätzlichen Suchbegriffe mehr – das Modul erscheint nur noch einmal als „LiveWebCam“ |
| 1.0 | 1 | 06.10.2026 | Erste Version |

## 11. Lizenz

Dieses Modul steht unter der **MIT-Lizenz** (siehe Datei [`LICENSE`](LICENSE)).

Das Modul darf jeder kostenlos nutzen, verändern und weitergeben, auch kommerziell. Bedingung ist nur, dass der Copyright-Hinweis und der Lizenztext in Kopien erhalten bleiben. Eine Gewährleistung gibt es nicht.

Jede Code-Datei trägt einen Lizenzkopf mit `SPDX-License-Identifier: MIT`. Wer das Modul weitergibt oder Teile davon übernimmt, behält diesen Kopf und die Datei `LICENSE` bei.

**Inhalte:** Kamerabilder und Streams gehören den jeweiligen Betreibern und unterliegen deren Nutzungsbedingungen. YouTube ist eine Marke von Google LLC; dieses Modul steht in keiner Verbindung zu Google oder den Kamerabetreibern.
