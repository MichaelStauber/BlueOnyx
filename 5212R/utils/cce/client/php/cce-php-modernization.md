# Modernisierung des PHP Zend API Moduls cce.so für PHP 8.3.30

## Aktueller Status

### Verzeichnisstruktur
- SVN Code Tree: `/home/devel/BlueOnyx/BlueOnyx/5212R/`
- Produktiv-Verzeichnis: `/usr/sausalito/`
- CCE PHP-Modul Source: `/home/devel/BlueOnyx/BlueOnyx/5212R/utils/cce/client/php/src/cce.c`
- GUI-Komponenten: `/home/devel/BlueOnyx/BlueOnyx/5212R/platform/alpine.mod/ci4/app/Libraries/`

### Problemstellung
- Das aktuelle `cce.c` ist für PHP 5.4 geschrieben und nicht kompatibel mit PHP 8.3.30
- Es verwendet veraltete Zend API-Funktionen
- Die Datei ist aus dem Jahr 2004 und wurde nie für moderne PHP-Versionen aktualisiert

### Bestehende Lösung
- Es gibt bereits eine vollständige PHP-Implementierung in `CCE.php`, die über Unix-Sockets mit CCEd kommuniziert
- Diese Implementierung ist deutlich langsamer als das native `cce.so`, aber funktioniert mit aktuellen PHP-Versionen

## Geplante Umsetzung

### Phase 1: Analyse und Vorbereitung (abgeschlossen)
Wir haben bereits analysiert:
- Die aktuelle `cce.c` Implementation mit veralteten PHP API-Aufrufen
- Die `CCE.php` Implementierung als Fallback-Lösung
- Die RPM-Build-Infrastruktur (`sausalito-cce.spec.in`)
- Die Makefile-Struktur in `/home/devel/BlueOnyx/BlueOnyx/5212R/utils/cce/client/php/`

### Phase 2: Entwicklung des modernisierten PHP Zend API Moduls

#### Schritt 1: Anpassung der cce.c für PHP 8.3.30
Wir müssen die Hauptunterschiede zwischen PHP 5.4 und PHP 8.3 berücksichtigen:
1. Änderungen an der Zend API
2. Neue Parameter für Funktionen wie `zend_parse_parameters`
3. Veraltete Makros und Funktionen ersetzen
4. Neue Header-Dateien und Strukturen verwenden

#### Schritt 2: Erstellen einer kompatiblen Makefile
Wir müssen die Build-Umgebung für PHP 8.3.30 konfigurieren:
1. PHP-Entwicklungsheader korrekt einbinden
2. Linken gegen die richtigen Bibliotheken
3. Kompilieroptionen anpassen

#### Schritt 3: Integrationstest
Nach der Entwicklung müssen wir sicherstellen:
1. Kompatibilität mit bestehenden PHP-Code
2. Vollständige Funktionsparität mit der alten Version
3. Keine Performance-Einbußen gegenüber der originalen Version

### Phase 3: Sicherheits- und Qualitätskontrolle
1. Code-Review auf Sicherheitslücken
2. Validierung der Eingabeverarbeitung
3. Überprüfung der Speicherverwaltung
4. Integrationstests mit bestehender Infrastruktur

## Technische Details für die Migration

### Wichtige Änderungen in PHP 8.x
1. Die Zend API hat sich stark verändert seit PHP 5.4
2. Neue Speicherverwaltungskonzepte
3. Striktere Typisierung
4. Geänderte Funktionsparameter und Rückgabetypen

### Benötigte Anpassungen in cce.c
1. Ersetzen von `ARG_COUNT(ht)` durch `ZEND_NUM_ARGS()`
2. Aktualisieren der Parameterverarbeitung mit `zend_parse_parameters`
3. Anpassen der Speicherallokation und -freigabe
4. Verwenden neuer Makros für String-Handling

## Nächste Schritte
Mit der Implementierung der modernisierten `cce.c` beginnen, die mit PHP 8.3.30 kompatibel ist.
