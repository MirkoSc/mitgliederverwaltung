# Betriebshandbuch

Für den Vorstand, der die Mitgliederverwaltung einrichtet, pflegt und
sichert. Ohne Vorwissen lesbar: Jeder Abschnitt sagt, *was* zu tun ist
und *warum*. Die genauen technischen Regeln stehen in den Specs unter
`docs/spec/`, dieses Handbuch verweist dorthin, wo es nötig ist.

Die Anwendung ist für **einen** Verein gedacht (eine Installation = ein
Verein). Sie läuft auf dem Webspace des Hosters und braucht keinen
eigenen Server.

---

## 1. Was Sie brauchen, bevor Sie anfangen

- Zugang zum **Kontrollpanel des Hosters** (Kundenmenü): dort werden
  Datenbank, Cron-Aufgabe und Domain eingerichtet.
- Zugang per **FTP** zum Webspace (für die Erstinstallation, zum Sichern
  von `config.php` und für Backups, die zu groß zum Hochladen sind).
- Eine **Datenbank** (MySQL/MariaDB) mit Benutzername und Passwort aus dem
  Kontrollpanel.
- Ein **Postfach** für den Versand der Anwendungsmails (SMTP-Zugang:
  Server, Port, Benutzer, Passwort). Ohne Mail gibt es keinen
  E-Mail-Code als zweiten Faktor bei der Anmeldung.
- Den **Ausdruck des Sicherungsschlüssels**, den der Installer einmal
  anzeigt (siehe Abschnitt 3). Ohne ihn sind Backups nicht lesbar.
- Ein Blatt Papier und ein sicherer Ort, an dem es **getrennt vom Webspace**
  liegt.

---

## 2. Erstinstallation

Die Installation dauert etwa eine halbe Stunde. Sie läuft im Browser;
die Anwendung wird über `setup.php` geladen.

1. **Domain und Datenbank** im Kontrollpanel des Hosters anlegen. Die
   Domain soll auf den Ordner `web/` zeigen (der Hoster nennt das oft
   „Document Root"; Hinweis dazu steht im Kontrollpanel).
2. **`setup.php`** aus dem neuesten Release auf GitHub herunterladen
   (Release-Seite des Projekts, Datei `setup.php`) und per FTP in den
   Ordner legen, auf den die Domain zeigt. Dann die Adresse
   `https://<ihre-domain>/setup.php` **sofort** im Browser aufrufen.
3. **Umgebungs-Checkliste**: Die Seite prüft PHP-Version, Erweiterungen,
   Schreibrechte und HTTPS. Steht dort etwas in Rot, zuerst beheben
   (meist ist es eine Einstellung im Kontrollpanel des Hosters).
4. **Update-Kanal** wählen: `stable` für den Echtbetrieb (empfohlen).
   `beta` nur für eine Testinstanz.
5. Die Seite lädt die Anwendung und leitet auf **`/install?token=…`**
   weiter. Der Token ist nur für diesen Durchlauf gültig.
6. Im Installer der Reihe nach:
   1. **Datenbank**: Zugangsdaten eintragen, Verbindung wird getestet.
   2. **Art der Installation**: **Frische Installation** wählen (oder
      „Backup einspielen", siehe Abschnitt 7).
   3. **Erster Admin**: E-Mail, Name, Passwort (mindestens 12 Zeichen).
      Mit dem Absenden wird der Admin angelegt, und die Antwort zeigt
      direkt den nächsten Schritt.
   4. **Sicherungsschlüssel**: Dieser Schritt zeigt den Schlüssel
      **einmal** an, in der Antwort auf das Absenden des Admins.
      Ausdrucken oder abschreiben, dann den Haken „ausgedruckt oder
      notiert" setzen. Ein Neuladen der Seite zeigt ihn nicht wieder. Wer
      ihn verliert, kann bestehende Backups nicht mehr öffnen. Hat man ihn
      nicht notiert, gibt „Neuen Schlüssel erzeugen" einen neuen aus, der
      dann einmal angezeigt wird. Das geht nur bis zum Mail-Schritt, denn
      danach ist bereits mit dem Schlüssel verschlüsselt worden.
   5. **Mail**: SMTP-Zugang eintragen und eine **Testmail** schicken. Der Schritt
      darf übersprungen werden, dann gibt es aber nur die Anmeldung mit
      App-Code (siehe Abschnitt 6).
   6. **Abschluss**: Die Anwendung schreibt ihre Konfiguration und sperrt
      den Installer. `setup.php` und der Token werden gelöscht. Die
      Abschlussseite zeigt die **Cron-Adresse**, die Sie im nächsten
      Schritt brauchen.
7. **Ersten Login** mit E-Mail und Passwort. Anschließend richten Sie den
   **zweiten Faktor** ein (Pflicht für alle Konten, siehe Abschnitt 6).

Nach der Installation: Die Seite `/admin/systemcheck` zeigt, ob alles so
läuft, wie es soll (z. B. Schreibrechte, Erweiterungen, Limits).

---

## 3. Sicherungsschlüssel und `config.php`

Die Anwendung hat einen Schlüssel, den **Sicherungsschlüssel**. Er ist der
Server-Schlüssel aus `config.php`. Er öffnet Backups und die verschlüsselten
Dateien (Nachweis-Scans, Lastschriftdateien). Er wird nur einmal bei der
Installation angezeigt und liegt danach in `config.php`.

Regeln:

- **Den Sicherungsschlüssel doppelt sichern**: ausdrucken und an einem
  sicheren Ort aufbewahren (z. B. Tresor, Schließfach), dazu eine Kopie beim
  zweiten Vorstandsmitglied.
- **`config.php` getrennt sichern**: Ein Backup enthält `config.php`
  bewusst **nicht**, der Schlüssel steckt aber darin. Herunterladen per FTP
  und sicher ablegen, z. B. auf einem Rechner, der nur dem Vorstand gehört.
- Der Schlüssel wird **nicht** in der Datenbank gespeichert und nicht
  mit dem Backup mitgesichert. Ein verlorener Schlüssel ist nicht zu
  ersetzen: Ohne ihn sind alle Backups und verschlüsselten Dateien
  unlesbar. Einen neuen Schlüssel gibt es nach der Installation nicht.
- Die Anwendung verschickt keine Mails mit dem Schlüssel und zeigt ihn nie
  erneut an.

---

## 4. Cron (regelmäßige Aufgaben)

Die Anwendung braucht einen regelmäßigen Aufruf, damit Mails verschickt
und alte Daten aufgeräumt werden.

**Einrichten im Kontrollpanel des Hosters:**

1. Im Kundenmenü den Bereich **Cron-Jobs** öffnen (der Name kann beim
   Hoster leicht abweichen).
2. Neuen Cron-Job anlegen:
   - **Aufruf**: die **Cron-Adresse**. Sie setzt sich aus
     `https://<ihre-domain>/cron?token=<cron_token>` zusammen. Den Wert
     `cron_token` steht in `shared/config.php` auf dem Webspace; die
     Abschlussseite der Installation zeigt die fertige Adresse ebenfalls.
   - **Intervall**: etwa alle 10 Minuten (wie auf der Abschlussseite
     angegeben). Häufiger ist möglich, bringt aber nichts.
3. Speichern.

**Kontrolle**: Die Anwendung zeigt die letzte Cron-Ausführung derzeit
nicht an. Prüfen Sie stattdessen im Kontrollpanel des Hosters beim
Cron-Job, ob er gelaufen ist (viele Hoster zeigen dazu ein Protokoll
bzw. die letzte Ausführung).

Hinweise:

- Die Cron-Adresse ist ein **Geheimnis**: nicht weitergeben, nicht in
  Mails oder Notizen mit anderen teilen. Wer sie kennt, kann den Cron
  anstoßen (das ist harmlos, aber nicht gewollt).
- Läuft der Cron nicht, warten Einladungen und andere nachrangige Mails
  in der Warteschlange, bis er wieder läuft. Anmeldecodes und
  Passwort-Reset gehen **sofort** raus und hängen nicht am Cron. Kommen
  sie nicht an, liegt es an der Mail-Einstellung (Abschnitt 5).

---

## 5. Mail

- **Einstellungen → Mail** (Recht: Admin): SMTP-Zugang eintragen, dann
  **Testmail** schicken.
- Das SMTP-Passwort wird **verschlüsselt** gespeichert.
- Die Anwendung schickt nur Mails an Benutzer: Anmeldecodes,
  Einladungen, Passwort-Reset, Sicherheitshinweise („neues Gerät",
  „Passwort geändert", „2FA geändert"). **Mitgliederdaten stehen nicht in
  Mails.**
- Kommt eine Mail nicht an: Spam-Ordner prüfen, dann die Testmail
  wiederholen. Mails, die nach mehreren Versuchen endgültig nicht
  zugestellt werden konnten, zählt die Mail-Seite als Anzahl. Eine
  einzelne Liste der Mails gibt es nicht.

---

## 6. Benutzer und Anmeldung

**Zwei feste Rollen** (kein Rollen-Editor):

- **Admin**: alles, inkl. Einstellungen, Benutzer, Backup, Update,
  Audit-Log.
- **Vorstand**: Mitglieder suchen, ansehen, anlegen und bearbeiten;
  Listen, Beiträge und Einzug je nach Recht.

**Benutzer einladen** (Einstellungen → Benutzer):

1. E-Mail-Adresse und Rolle eintragen → die Person bekommt einen Link
   per Mail. Der Link ist **72 Stunden** gültig und nur **einmal**
   nutzbar.
2. Die Person setzt ihr Passwort und richtet den **zweiten Faktor** ein.
3. Sperren, entsperren, Rolle ändern oder löschen geht ebenfalls unter
   „Benutzer". Man kann sich nicht selbst löschen oder herabstufen, und
   der letzte aktive Admin bleibt Admin.

**Zweiter Faktor** (Pflicht für alle Konten). Jeder wählt:

- **E-Mail-Code** (Standard): bei jeder Anmeldung kommt eine Mail mit einer
  6-stelligen Nummer, 10 Minuten gültig.
- **Authenticator-App** (optional): z. B. eine App auf dem Handy.

Dazu **10 Backup-Codes** ausdrucken und sicher aufbewahren. Die
Backup-Codes sind **Pflicht** für jedes Konto. Mit „Dieses Gerät 30 Tage
merken" muss der Code nicht bei jeder Anmeldung kommen.

**Achtung**: Wer die Authenticator-App verliert und auch die Backup-Codes
nicht mehr hat, ist ausgesperrt. Einen Admin-Reset für den zweiten Faktor
gibt es derzeit **nicht**; die Backup-Codes sind deshalb der einzige Weg
zurück. Sie gehören also sicher aufbewahrt, nicht nur irgendwo abgelegt.

**Passwort vergessen**: Auf der Anmeldeseite den Link nutzen. Der Link in
der Mail ist 30 Minuten gültig. Danach sind alle anderen Sitzungen beendet.

**Sicherheit**: Die Anwendung leitet auf HTTPS um. Wiederholte
Fehlversuche werden gesperrt. Ein Fehlversuch zeigt nie an, ob eine Adresse
bekannt ist.

---

## 7. Backup und Wiederherstellung

**Backup erstellen**: Einstellungen → Backups → „Backup erstellen". Die
Anwendung arbeitet in mehreren kurzen Schritten; die Seite zeigt den
Fortschritt. Ein Backup enthält:

- alle Daten (Mitglieder, Familien, Beiträge, Benutzer usw.),
- die Nachweis-Dateien und Lastschriftdateien,
- das Vereinslogo.

Das Backup ist **verschlüsselt** (Endung `.zip.enc`). Ohne
Sicherungsschlüssel ist es wertlos, mit ihm ist es vollständig.

- Es werden die **letzten 10 Backups** aufbewahrt; ältere werden beim
  nächsten Backup gelöscht.
- **Herunterladen** und auf einem anderen Gerät ablegen. Empfehlung: einmal
  im Quartal und vor jedem Update ein Backup herunterladen und eine Kopie
  außerhalb des Webspaces ablegen.
- Ein abgebrochenes Backup kann mit „Abbrechen" beendet werden. Hängt es
  länger als eine Stunde, räumt der Cron es selbst auf.

**Wiederherstellen** (nur auf einer neuen oder leeren Installation):

1. Auf dem Webspace eine **neue Installation** wie in Abschnitt 2 beginnen
   (bis zum Installer).
2. Im Installer **„Backup einspielen"** wählen.
3. Die Backup-Datei **hochladen** oder – wenn sie größer als das
   Upload-Limit ist – per FTP nach `shared/var/backups/` legen und
   auswählen.
4. Den **Sicherungsschlüssel** abschreiben (Leerzeichen und Zeilenumbrüche
   sind egal) **oder** die alte `config.php` hochladen.
5. Die Anwendung spielt das Backup Schritt für Schritt ein. Die
   Mail-Einstellungen kommen mit dem Backup, der Mail-Schritt entfällt.

Ein falscher Schlüssel wird sofort abgelehnt, bevor etwas eingespielt wird.

---

## 8. Update

Updates kommen als fertiges Paket von GitHub. Die Anwendung holt es sich
selbst.

**Kanäle** (Einstellungen → Update → Release-Kanal):

- **stable**: nur freigegebene Versionen. Für den Echtbetrieb.
- **beta**: auch Vorabversionen. Nur für eine Testinstanz.

**Ablauf** (Einstellungen → Update):

1. **Backup vorher** erstellen (das Update macht es zusätzlich selbst, aber
   ein eigenes Backup schadet nie).
2. „Nach Updates suchen" zeigt die neue Version an.
3. „Update starten" wählen und die Schritte durchlaufen lassen. Die Seite zeigt
   jeden Schritt. Schlägt einer fehl, „Schritt wiederholen" wählen.
4. Während des Updates ist die Anwendung kurz für die Benutzer gesperrt
   (Wartungsmodus). Nach dem Update ist sie wieder erreichbar.

**Wenn etwas schiefgeht**:

- Schlägt der Schritt **Migration** oder **Abschluss** fehl, erscheint ein
  **Rollback-Knopf**. Er stellt die alte Version wieder her. Vor diesen
  Schritten ist kein Rollback nötig, weil dann noch die alte
  Version läuft.
- Nach einem Abbruch bleibt die Anwendung im Wartungsmodus. Im Admin
  erscheint dann ein Banner mit „Freigeben". Vorher nicht freigeben,
  sondern erst den Rollback oder „Schritt wiederholen" nutzen.
- Bei Fragen zuerst den Systemcheck laufen lassen
  (Einstellungen → System).

---

## 9. Import und Abgleich mit der alten Software

**Erstimport** (nur in einen **leeren** Mitgliederbestand):

1. Aus der alten Software die Mitglieder- und die Familiendatei als
   Excel-Datei exportieren.
2. Daraus auf dem eigenen Rechner eine `import.csv` im Importformat
   erstellen (UTF-8, Spalten wie in `docs/spec/10-import.md` §2); die
   Dateien verlassen den Rechner nicht.
3. Einstellungen → Import → `import.csv` hochladen.
4. **Vorschau** prüfen: Anzahl Mitglieder, Familien und Abteilungen sowie
   die Zeilen mit Hinweisen und Fehlern. Fehler (z. B. doppelte
   Mitgliedsnummer, unbekannte Abteilung) vor dem Import beheben.
5. „Importieren" – die Anwendung arbeitet in Schritten.
6. Danach die **Auffälligkeiten-Liste** durchgehen (z. B. Elternteile in
   Familien, die vielleicht volljährige Kinder sind).

Es werden **keine Jahresbeiträge** importiert. Die wenigen offenen
Posten der alten Software werden von Hand nachgetragen.

**Abgleich** (wenn der Bestand schon Mitglieder hat): Die Seite bietet dann
statt des Erstimports den **Abgleich** an. Eine neuere Datei aus der
alten Software wird in einer Merge-Ansicht verglichen; je Zeile wählen
Sie, was übernommen wird. Jeder Abgleich wird im Verlauf des Mitglieds
vermerkt.

---

## 10. Einzug (Lastschrift)

Der Einzug läuft auf **einer** Seite (Beiträge → Einzug):

1. **Zusammenstellen**: Die Liste zeigt alle offenen Jahresbeiträge mit
   Zahlart „Lastschrift" und gültiger IBAN. Alle sind vorausgewählt; was
   nicht einziehen soll, abwählen. Fälligkeitsdatum wählen (Vorschlag:
   nächster Bankarbeitstag + 1).
   Rechts daneben stehen die Beiträge, die **nicht** eingezogen werden
   können (keine gültige IBAN oder Zahlart „selbst kassieren"). Die Liste
   „Selbst kassieren" zeigt sie.
2. **Erzeugen**: Die Lastschriftdatei (Format pain.008) wird **einmal**
   erzeugt und verschlüsselt archiviert. Sie ist das Archiv dessen, was
   an die Bank ging, und wird nie neu erzeugt oder verändert.
3. **Datei herunterladen** und im Online-Banking hochladen. Die Datei kann
   beliebig oft heruntergeladen werden; es ist immer dieselbe.
4. **Bestätigen**, sobald die Bank eingezogen hat → die Beiträge gelten
   als bezahlt. Oder **Verwerfen**, wenn die Datei nicht eingereicht wurde
   → die Beiträge sind wieder offen.

Im **Archiv** unten auf der Seite stehen alle bisherigen Einzüge, auch
verworfene.

Die Lastschriftdatei enthält Bankdaten der Mitglieder. Sie wird nur
verschlüsselt gespeichert und nicht per Mail verschickt.

---

## 11. Datenschutzerklärung (Vorlage)

Die Vorlage ist für die Seite **Datenschutz** der Anwendung gedacht
(Einstellungen → Rechtstexte). Eckige Klammern `[…]` vor der Veröffentlichung
ausfüllen oder streichen. Die Vorlage ersetzt keine rechtliche Prüfung.

Die Anwendung kennt keinen Zitatblock. Die Vorlage steht deshalb als
Codeblock zum Kopieren; die Überschriften sind `##`, der Text darunter
ist Fließtext:

```markdown
## Datenschutzerklärung

## Verantwortlicher
[Name des Vereins], [Anschrift], [E-Mail des Vorstands].

## Zweck der Verarbeitung
Verwaltung der Mitgliedschaft, Beitragsabrechnung einschließlich des
Lastschrifteinzugs, Ehrungen und Geburtstagsliste, Kommunikation mit den
Mitgliedern.

## Welche Daten
Stammdaten der Mitglieder (Name, Anschrift, Geburtsdatum, Kontaktdaten,
Abteilungen, Funktionen), Familienzuordnung, Beitrags- und Zahlungsdaten
einschließlich Bankverbindung (IBAN) bei Lastschrift, Nachweise
(z. B. Ermäßigungen), sowie Zugangsdaten der Vorstandsmitglieder.

## Cookies
[Hinweis zu den Cookies, bitte vor der Veröffentlichung prüfen: Die
Anwendung setzt den Sitzungs-Cookie `__Host-sid` für die Anmeldung und den
Cookie `__Host-geraet` nur, wenn Sie „Dieses Gerät 30 Tage merken" wählen
(Laufzeit [30 Tage]). Beide sind für die Anmeldung nötig und enthalten keine
Mitgliederdaten.]

## Protokoll (Audit-Log)
Die Anwendung protokolliert sicherheitsrelevante Vorgänge (z. B.
Anmeldungen, Änderungen an Benutzern, Backups, Einzüge). Die IP-Adresse
wird dabei nur als nicht umkehrbare Prüfsumme (HMAC) gespeichert.

## Wie die Daten geschützt werden
Zugang zur Anwendung nur nach Anmeldung mit Passwort und zweitem Faktor;
Übertragung nur über HTTPS; Dateien (Nachweise, Lastschriftdateien) und
Backups sind verschlüsselt. Die Mitgliederdaten in der Datenbank sind nicht
einzeln verschlüsselt; der Schutz beruht auf der Zugangskontrolle.

## Hosting
Die Anwendung läuft auf dem Webspace von [Hoster]. [Hoster] verarbeitet die
Daten als Auftragsverarbeiter; [Auftragsverarbeitungsvertrag: Hinweis].

## Mails
Die Anwendung versendet nur Mails an Benutzer der Anwendung (Anmeldung,
Sicherheitshinweise). Mitgliederdaten werden nicht per Mail versendet,
sofern nicht [ausdrücklich geregelt].

## Speicherdauer
[z. B. Mitgliederdaten bis [X] Jahre nach Austritt; Backups werden in der
Anwendung auf die letzten 10 begrenzt; Beitrags- und Lastschriftdaten nach
[gesetzlicher Frist]].

## Ihre Rechte
Auskunft, Berichtigung, Löschung, Einschränkung, Widerspruch,
Datenübertragbarkeit; Beschwerde bei einer Aufsichtsbehörde.

## Kontakt zum Datenschutz
[Name/Funktion], [E-Mail].
```

Vor der Veröffentlichung prüfen: Die Angaben zur Speicherdauer und zu den
gesetzlichen Fristen (Beitragsdaten) gehören zur tatsächlichen Praxis des
Vereins.

---

## 12. Checkliste nach der Einrichtung

- [ ] Sicherungsschlüssel ausgedruckt, Kopie beim zweiten Vorstandsmitglied
- [ ] `config.php` heruntergeladen und getrennt gesichert
- [ ] Cron-Job im Kontrollpanel angelegt, letzte Ausführung im Admin sichtbar
- [ ] Testmail erfolgreich
- [ ] Alle Vorstandsmitglieder eingeladen, zweiter Faktor eingerichtet,
      Backup-Codes ausgedruckt
- [ ] Erstes Backup erstellt und heruntergeladen
- [ ] Systemcheck ohne Warnungen
- [ ] Datenschutzerklärung mit den eigenen Angaben ergänzt
- [ ] Release-Kanal gesetzt (`stable` im Echtbetrieb)
