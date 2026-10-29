<#
.SYNOPSIS
    Wandelt die Exporte der alten Mitgliedersoftware in eine import.csv um.

.DESCRIPTION
    Liest die Mitgliederdatei und die Familiendatei (je xlsx, erstes Blatt)
    und schreibt import.csv fuer die Seite Einstellungen -> Import sowie
    eine Zusammenfassung ohne Namen (zusammenfassung.txt und Bildschirm).
    Laeuft nur lokal, die Dateien verlassen den Rechner nicht
    (docs/spec/10-import.md Abschnitt 3). Kein Docker, kein PHP noetig:
    Windows PowerShell 5.1 oder PowerShell 7 genuegen.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File tools\altdaten-umwandeln.ps1 -Ordner C:\temp\mitgliederdaten

    Erwartet dort mitglieder.xlsx und familien.xlsx und schreibt import.csv
    und zusammenfassung.txt in denselben Ordner.

.EXAMPLE
    pwsh tools/altdaten-umwandeln.ps1 -Mitglieder a.xlsx -Familien b.xlsx -Ausgabe import.csv -Stichtag 01.10.2026
#>
[CmdletBinding()]
param(
    # Ordner mit mitglieder.xlsx und familien.xlsx; die Ausgabe landet dort.
    [string]$Ordner = '',
    [string]$Mitglieder = '',
    [string]$Familien = '',
    # Ziel der import.csv; zusammenfassung.txt kommt in denselben Ordner.
    [string]$Ausgabe = '',
    # Tag, an dem "unter 18" gemessen wird (TT.MM.JJJJ), Standard: heute.
    [string]$Stichtag = ''
)

Set-StrictMode -Version 2.0
$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

# ---------------------------------------------------------------------------
# Feste Zuordnungen (docs/fachlichkeit.md Abschnitt 8)
# ---------------------------------------------------------------------------

# Spalten der import.csv in Dateireihenfolge (spec 10 Abschnitt 2).
$script:SPALTEN = @(
    'mitgliedsnummer', 'anrede', 'titel', 'vorname', 'nachname', 'namenszusatz', 'geschlecht',
    'geburtsdatum', 'eintrittsdatum', 'austrittsdatum', 'austrittsgrund',
    'strasse', 'plz', 'ort', 'land', 'telefon', 'handy', 'email',
    'aktiv', 'abteilungen', 'beitragsart', 'zahlart', 'iban', 'bic', 'kontoinhaber',
    'mandatsreferenz', 'mandatsdatum', 'familie', 'familie_name', 'familie_rolle',
    'funktionen', 'lizenznummer', 'bemerkung'
)

# Spalten der Mitgliederdatei: Schluessel -> Anzeigename, Aliase. Verglichen
# wird ohne Gross-/Kleinschreibung und Satzzeichen ("E-Mail" = "EMail").
$script:QUELLSPALTEN = [ordered]@{
    'nummer'        = @('Adressnummer', 'adressnummer')
    'anrede'        = @('Anrede', 'anrede')
    'titel'         = @('Titel', 'titel')
    'vorname'       = @('Vorname', 'vorname')
    'nachname'      = @('Nachname', 'nachname')
    'geschlecht'    = @('Geschlecht', 'geschlecht')
    'geburt'        = @('GebDatum', 'gebdatum')
    'strasse'       = @('Strasse', 'strasse', "stra$([char]0x00DF)e")
    'plz'           = @('Plz', 'plz')
    'ort'           = @('Ort', 'ort')
    'land'          = @('Land', 'land')
    'lkz'           = @('Lkz', 'lkz')
    'telefon'       = @('Telefon', 'telefon')
    'handy'         = @('Handy', 'handy')
    'email'         = @('EMail', 'email')
    'iban'          = @('Konto1Iban', 'konto1iban')
    'bic'           = @('Konto1Bic', 'konto1bic')
    'zahlernummer'  = @('ZahlerAdressNummer', 'zahleradressnummer')
    'zahlervorname' = @('ZahlerVorname', 'zahlervorname')
    'zahlername'    = @('ZahlerNachname', 'zahlernachname')
    'zahleriban'    = @('MitZahlerIban', 'mitzahleriban')
    'eintritt'      = @('EintrittsDatum', 'eintrittsdatum')
    'austritt'      = @('AustrittsDatum', 'austrittsdatum')
    'grund'         = @('Austrittsgrund', 'austrittsgrund')
    'zahlart'       = @('Zahlart', 'zahlart')
    'adrnotizen'    = @('AdrNotizen', 'adrnotizen')
    'mitnotizen'    = @('MitNotizen', 'mitnotizen')
}

# Ohne diese Spalten ist es nicht die erwartete Datei.
$script:PFLICHT = @('nummer', 'nachname')

# Spalten der Familiendatei (nur FamID und AdrNr zaehlen, F4).
$script:FAMILIENSPALTEN = [ordered]@{
    'id'     = @('FamID', 'famid')
    'name'   = @('FamName', 'famname')
    'nummer' = @('AdrNr', 'adrnr')
}

# Alte Abteilungen nach normalisiertem Namen ("Turnen" ist BLSV 34).
# Exakter Zeichenvergleich: Windows PowerShell 5.1 haelt sonst "fussball"
# und die Schreibung mit sz fuer denselben Schluessel.
$script:ABTEILUNGSNAMEN = New-Object 'System.Collections.Generic.Dictionary[string,string]' ([System.StringComparer]::Ordinal)
$script:ABTEILUNGSNAMEN.Add('badminton', '01')
$script:ABTEILUNGSNAMEN.Add("fu$([char]0x00DF)ball", '09')
$script:ABTEILUNGSNAMEN.Add('fussball', '09')
$script:ABTEILUNGSNAMEN.Add('kegeln', '16')
$script:ABTEILUNGSNAMEN.Add('turnen', '34')
$script:ABTEILUNGSNAMEN.Add('gymnastik', '34')

$script:BEKANNTE_BLSV = @('01', '09', '16', '34')

# Alte Grundbeitraege (normalisiert -> Art). Der Zuschlag "aktiv" zaehlt extra.
$script:GRUNDBEITRAEGE = @{
    'erwachsene'     = 'erwachsene'
    'kinder'         = 'kinder'
    'jugendliche'    = 'jugendliche'
    'rentner'        = 'rentner'
    'familienkarte'  = 'familienkarte'
    'beitragsfreifk' = 'beitragsfrei_fk'
    'mindestbeitrag' = 'mindestbeitrag'
    'beitragsfrei'   = 'beitragsfrei'
}

# Welcher Grundbeitrag gewinnt, wenn jemand mehrere hat (hoechster zuerst).
$script:RANGFOLGE = @(
    'familienkarte', 'erwachsene', 'kinder', 'jugendliche', 'rentner',
    'mindestbeitrag', 'beitragsfrei_fk', 'beitragsfrei'
)

$script:PLATZHALTER = '01.01.1901'

# Texte mit Umlauten als Zeichencodes, damit das Skript auch ohne BOM in
# jeder PowerShell gleich gelesen wird.
$kleinUe = [string][char]0x00FC
$grossUe = [string][char]0x00DC
$script:T_JUGEND = "Jugend/Sch${kleinUe}ler/Studenten/Azubis"
$script:T_UEBUNGSLEITER = "${grossUe}bungsleiter"

$script:INVARIANT = [System.Globalization.CultureInfo]::InvariantCulture

# ---------------------------------------------------------------------------
# Zaehler: nur Anzahlen und feste Bezeichnungen, nie Namen oder IBANs
# ---------------------------------------------------------------------------

$script:zaehler = @{}

function Zaehle([string]$gruppe, [string]$schluessel = '') {
    if (-not $script:zaehler.ContainsKey($gruppe)) {
        $script:zaehler[$gruppe] = New-Object 'System.Collections.Generic.Dictionary[string,int]'
    }
    $g = $script:zaehler[$gruppe]
    if ($g.ContainsKey($schluessel)) { $g[$schluessel] = $g[$schluessel] + 1 } else { $g[$schluessel] = 1 }
}

function Summe([string]$gruppe) {
    if (-not $script:zaehler.ContainsKey($gruppe)) { return 0 }
    $s = 0
    foreach ($w in $script:zaehler[$gruppe].Values) { $s += $w }
    return $s
}

function Anzahl([string]$gruppe, [string]$schluessel) {
    if (-not $script:zaehler.ContainsKey($gruppe)) { return 0 }
    if ($script:zaehler[$gruppe].ContainsKey($schluessel)) { return $script:zaehler[$gruppe][$schluessel] }
    return 0
}

# Schluessel einer Gruppe in fester Reihenfolge (ordinal, Zahlen numerisch).
function GruppenSchluessel([string]$gruppe) {
    if (-not $script:zaehler.ContainsKey($gruppe)) { return @() }
    $liste = New-Object 'System.Collections.Generic.List[string]'
    foreach ($k in $script:zaehler[$gruppe].Keys) { $liste.Add($k) }
    $vergleich = [System.Comparison[string]] {
        param([string]$a, [string]$b)
        if ($a -match '^[0-9]+$' -and $b -match '^[0-9]+$') { return ([long]$a).CompareTo([long]$b) }
        return [string]::CompareOrdinal($a, $b)
    }
    $liste.Sort($vergleich)
    return , $liste.ToArray()
}

# ---------------------------------------------------------------------------
# Hilfsfunktionen
# ---------------------------------------------------------------------------

# Kleinbuchstaben, nur Buchstaben und Ziffern - Schluessel fuer Vergleiche.
function Normal([string]$text) {
    return [regex]::Replace($text.ToLowerInvariant(), '[^\p{L}\p{N}]+', '')
}

function Feld($zeile, $spalten, [string]$schluessel) {
    if (-not $spalten.Contains($schluessel)) { return '' }
    $index = $spalten[$schluessel]
    if ($index -ge $zeile.Count) { return '' }
    return ([string]$zeile[$index]).Trim()
}

function IstZahl([string]$text) {
    return [regex]::IsMatch($text, '\A[0-9]+\z')
}

function AlsNummer([string]$text) {
    return ([long]::Parse($text, $script:INVARIANT)).ToString($script:INVARIANT)
}

# Erste Spalte je normalisiertem Kopfnamen -> Schluessel der Definition.
function SpaltenSuchen($kopf, $definition) {
    $nachName = New-Object 'System.Collections.Generic.Dictionary[string,int]' ([System.StringComparer]::Ordinal)
    for ($i = 0; $i -lt $kopf.Count; $i++) {
        $n = Normal $kopf[$i]
        if (-not $nachName.ContainsKey($n)) { $nachName[$n] = $i }
    }
    $gefunden = [ordered]@{}
    foreach ($schluessel in $definition.Keys) {
        $angaben = $definition[$schluessel]
        for ($j = 1; $j -lt $angaben.Count; $j++) {
            $n = Normal $angaben[$j]
            if ($nachName.ContainsKey($n)) {
                $gefunden[$schluessel] = $nachName[$n]
                break
            }
        }
    }
    return $gefunden
}

# Spalten "<basis> (alle aktuell)(1..3)" je Platz; die Historie "(alle)" nie.
function PlatzSpalten($kopf, [string]$basis) {
    $ergebnis = @{}
    for ($i = 0; $i -lt $kopf.Count; $i++) {
        $m = [regex]::Match((Normal $kopf[$i]), '\A' + $basis + 'alleaktuell([1-3])\z')
        if ($m.Success) {
            $platz = [int]$m.Groups[1].Value
            if (-not $ergebnis.ContainsKey($platz)) { $ergebnis[$platz] = $i }
        }
    }
    return $ergebnis
}

# Die Lizenznummer-Spalte unter den Uel*-Spalten.
function LizenzSpalte($kopf) {
    $uel = New-Object 'System.Collections.Generic.List[object]'
    for ($i = 0; $i -lt $kopf.Count; $i++) {
        $n = Normal $kopf[$i]
        if ($n.StartsWith('uel', [System.StringComparison]::Ordinal)) { $uel.Add(@($i, $n)) }
    }
    foreach ($teil in @('lizenz', 'nummer', 'nr')) {
        foreach ($eintrag in $uel) {
            if ($eintrag[1].Contains($teil)) { return $eintrag[0] }
        }
    }
    return $null
}

# "TT.MM.JJJJ" fuer das, was die alte Software schrieb; '' fuer nichts,
# Platzhalter oder Unlesbares.
function Datum([string]$text) {
    $text = $text.Trim()
    if ($text -eq '') { return '' }
    $m1 = [regex]::Match($text, '\A([0-9]{1,2})\.([0-9]{1,2})\.([0-9]{4})(?:\s+[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)?\z')
    $m2 = [regex]::Match($text, '\A([0-9]{4})-([0-9]{2})-([0-9]{2})(?:[T ].*)?\z')
    if ($m1.Success) {
        $tag = [int]$m1.Groups[1].Value; $monat = [int]$m1.Groups[2].Value; $jahr = [int]$m1.Groups[3].Value
    } elseif ($m2.Success) {
        $jahr = [int]$m2.Groups[1].Value; $monat = [int]$m2.Groups[2].Value; $tag = [int]$m2.Groups[3].Value
    } elseif ([regex]::IsMatch($text, '\A[0-9]{3,6}\z')) {
        # eine Excel-Datumszelle, die als Seriennummer ankam
        $serie = (New-Object DateTime 1899, 12, 30).AddDays([int]$text)
        $tag = $serie.Day; $monat = $serie.Month; $jahr = $serie.Year
    } else {
        Zaehle 'datum_kaputt'
        return ''
    }
    if ($jahr -lt 1 -or $jahr -gt 9999 -or $monat -lt 1 -or $monat -gt 12 -or $tag -lt 1 -or $tag -gt [DateTime]::DaysInMonth($jahr, $monat)) {
        Zaehle 'datum_kaputt'
        return ''
    }
    $ergebnis = '{0:00}.{1:00}.{2:0000}' -f $tag, $monat, $jahr
    if ($ergebnis -eq $script:PLATZHALTER) {
        Zaehle 'platzhalter_datum'
        return ''
    }
    return $ergebnis
}

# Alter in vollen Jahren am Stichtag; $geburt ist "TT.MM.JJJJ".
function Alter([string]$geburt) {
    $teile = $geburt.Split('.')
    $tag = [int]$teile[0]; $monat = [int]$teile[1]; $jahr = [int]$teile[2]
    $jahre = $script:stichtagDatum.Year - $jahr
    if ($script:stichtagDatum.Month -lt $monat -or ($script:stichtagDatum.Month -eq $monat -and $script:stichtagDatum.Day -lt $tag)) {
        $jahre--
    }
    return $jahre
}

function Geschlecht([string]$text) {
    $text = $text.Trim()
    if ($text -eq '') { return '' }
    $erster = $text.Substring(0, 1).ToLowerInvariant()
    if (@('m', 'w', 'd') -contains $erster) { return $erster }
    return ''
}

# AdrNotizen und MitNotizen in einem Text; gleiche Texte nur einmal.
function Notizen([string[]]$notizen) {
    $teile = New-Object 'System.Collections.Generic.List[string]'
    foreach ($notiz in $notizen) {
        $n = $notiz.Replace("`r`n", "`n").Replace("`r", "`n").Trim()
        if ($n -ne '' -and -not $teile.Contains($n)) { $teile.Add($n) }
    }
    return [string]::Join("`n", $teile.ToArray())
}

# ---------------------------------------------------------------------------
# xlsx lesen: Text und Zahlen des ERSTEN Blatts, sonst nichts
# ---------------------------------------------------------------------------

$script:NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'
$script:NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships'
$script:NS_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships'

function XmlLaden([string]$inhalt) {
    $einstellungen = New-Object System.Xml.XmlReaderSettings
    $einstellungen.DtdProcessing = [System.Xml.DtdProcessing]::Prohibit
    $einstellungen.XmlResolver = $null
    $leser = [System.Xml.XmlReader]::Create((New-Object System.IO.StringReader $inhalt), $einstellungen)
    try {
        $dokument = New-Object System.Xml.XmlDocument
        $dokument.PreserveWhitespace = $true
        $dokument.Load($leser)
        return $dokument
    } catch {
        throw "Die xlsx-Datei enth$([char]0x00E4)lt ung$([char]0x00FC)ltiges XML."
    } finally {
        $leser.Close()
    }
}

function EintragLesen($zip, [string]$name) {
    $eintrag = $zip.GetEntry($name)
    if ($null -eq $eintrag) { return $null }
    $strom = $eintrag.Open()
    try {
        $leser = New-Object System.IO.StreamReader($strom, [System.Text.Encoding]::UTF8)
        return $leser.ReadToEnd()
    } finally {
        $strom.Close()
    }
}

# Pfad des ersten Blatts in Reihenfolge der Arbeitsmappe.
function BlattPfad($zip) {
    $standard = 'xl/worksheets/sheet1.xml'
    $mappe = EintragLesen $zip 'xl/workbook.xml'
    $beziehungen = EintragLesen $zip 'xl/_rels/workbook.xml.rels'
    if ($null -eq $mappe -or $null -eq $beziehungen) { return $standard }
    $blaetter = (XmlLaden $mappe).GetElementsByTagName('sheet', $script:NS_MAIN)
    if ($blaetter.Count -eq 0) { return $standard }
    $kennung = $blaetter[0].GetAttribute('id', $script:NS_REL)
    foreach ($b in (XmlLaden $beziehungen).GetElementsByTagName('Relationship', $script:NS_PKG)) {
        if ($b.GetAttribute('Id') -ne $kennung) { continue }
        $ziel = $b.GetAttribute('Target')
        if ($ziel.StartsWith('/')) { return $ziel.TrimStart('/') }
        return 'xl/' + $ziel
    }
    return $standard
}

# Zusammengesetzte <t> eines <si>/<is>, ohne Lautschrift-Hinweise (<rPh>).
function TextVon($knoten) {
    $text = New-Object System.Text.StringBuilder
    foreach ($t in $knoten.GetElementsByTagName('t', $script:NS_MAIN)) {
        if ($t.ParentNode.LocalName -eq 'rPh') { continue }
        [void]$text.Append($t.InnerText)
    }
    return $text.ToString()
}

# "1001", "1001.0" und "1.001E3" werden "1001"; "12.5" bleibt.
function ZahlText([string]$wert) {
    $wert = $wert.Trim()
    if ($wert -eq '' -or -not [regex]::IsMatch($wert, '\A[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?\z')) { return $wert }
    $zahl = [double]::Parse($wert, [System.Globalization.NumberStyles]::Float, $script:INVARIANT)
    if ([Math]::Floor($zahl) -eq $zahl -and [Math]::Abs($zahl) -lt 1.0e15) {
        return ([long]$zahl).ToString($script:INVARIANT)
    }
    return $wert
}

# "AB12" -> 27 (ab null), $null ohne brauchbare Referenz.
function SpaltenIndex([string]$referenz) {
    $m = [regex]::Match($referenz, '\A([A-Z]+)[0-9]*\z')
    if (-not $m.Success) { return $null }
    $index = 0
    foreach ($buchstabe in $m.Groups[1].Value.ToCharArray()) {
        $index = $index * 26 + ([int]$buchstabe - 64)
    }
    return $index - 1
}

function ZellWert($zelle, $texte) {
    $art = $zelle.GetAttribute('t')
    if ($art -eq 'inlineStr') {
        foreach ($roh in $zelle.GetElementsByTagName('is', $script:NS_MAIN)) { return (TextVon $roh) }
        return ''
    }
    $wert = ''
    foreach ($v in $zelle.GetElementsByTagName('v', $script:NS_MAIN)) { $wert = $v.InnerText; break }
    switch ($art) {
        's' {
            $i = 0
            if ([int]::TryParse($wert, [ref]$i) -and $i -ge 0 -and $i -lt $texte.Count) { return $texte[$i] }
            return ''
        }
        'str' { return $wert }
        'b' { if ($wert -eq '1') { return '1' } else { return '0' } }
        'e' { return '' }
        default { return (ZahlText $wert) }
    }
}

# Zeilen des ersten Blatts als Listen von Texten (Luecken mit ''); Zeilen
# ohne jeden Wert fallen weg.
function XlsxLesen([string]$pfad) {
    if (-not (Test-Path -LiteralPath $pfad -PathType Leaf)) {
        throw "Datei nicht gefunden: $pfad"
    }
    try {
        $zip = [System.IO.Compression.ZipFile]::OpenRead((Resolve-Path -LiteralPath $pfad).ProviderPath)
    } catch {
        throw "Keine g$([char]0x00FC)ltige xlsx-Datei: $pfad"
    }
    try {
        $blatt = BlattPfad $zip
        $xml = EintragLesen $zip $blatt
        if ($null -eq $xml) { throw "Tabellenblatt fehlt in der xlsx-Datei: $pfad" }

        $texte = New-Object 'System.Collections.Generic.List[string]'
        $geteilt = EintragLesen $zip 'xl/sharedStrings.xml'
        if ($null -ne $geteilt) {
            foreach ($si in (XmlLaden $geteilt).GetElementsByTagName('si', $script:NS_MAIN)) { $texte.Add((TextVon $si)) }
        }

        $zeilen = New-Object 'System.Collections.Generic.List[object]'
        foreach ($zeile in (XmlLaden $xml).GetElementsByTagName('row', $script:NS_MAIN)) {
            $werte = @{}
            $naechste = 0
            $hoechste = -1
            foreach ($zelle in $zeile.ChildNodes) {
                if ($zelle.NodeType -ne [System.Xml.XmlNodeType]::Element -or $zelle.LocalName -ne 'c') { continue }
                $spalte = SpaltenIndex $zelle.GetAttribute('r')
                if ($null -eq $spalte) { $spalte = $naechste }
                $naechste = $spalte + 1
                $werte[$spalte] = ZellWert $zelle $texte
                if ($spalte -gt $hoechste) { $hoechste = $spalte }
            }
            if ($hoechste -lt 0) { continue }
            $dicht = New-Object 'string[]' ($hoechste + 1)
            $leer = $true
            for ($i = 0; $i -le $hoechste; $i++) {
                if ($werte.ContainsKey($i)) { $dicht[$i] = [string]$werte[$i] } else { $dicht[$i] = '' }
                if ($dicht[$i] -ne '') { $leer = $false }
            }
            if ($leer) { continue }
            $zeilen.Add($dicht)
        }
        return , $zeilen
    } finally {
        $zip.Dispose()
    }
}

# ---------------------------------------------------------------------------
# Umwandlung
# ---------------------------------------------------------------------------

# AdrNr -> @{id; name} aus der Familiendatei; wer in zwei Familien steht,
# behaelt die erste.
function FamilienLesen($familien) {
    $kopf = @()
    if ($familien.Count -gt 0) { $kopf = $familien[0] }
    $spalten = SpaltenSuchen $kopf $script:FAMILIENSPALTEN
    foreach ($pflicht in @('id', 'nummer')) {
        if (-not $spalten.Contains($pflicht)) {
            throw ('In der Familiendatei fehlt die Spalte "' + $script:FAMILIENSPALTEN[$pflicht][0] + '" - ist das die richtige Datei?')
        }
    }
    $namen = @{}
    for ($r = 1; $r -lt $familien.Count; $r++) {
        $id = Feld $familien[$r] $spalten 'id'
        $name = Feld $familien[$r] $spalten 'name'
        if ($id -ne '' -and $name -ne '' -and -not $namen.ContainsKey($id)) { $namen[$id] = $name }
    }
    $zuordnung = @{}
    for ($r = 1; $r -lt $familien.Count; $r++) {
        $id = Feld $familien[$r] $spalten 'id'
        $nummer = Feld $familien[$r] $spalten 'nummer'
        if ($id -eq '' -or -not (IstZahl $nummer)) { continue }
        $n = [long]$nummer
        if ($zuordnung.ContainsKey($n)) {
            Zaehle 'familie_doppelt'
            continue
        }
        $fname = ''
        if ($namen.ContainsKey($id)) { $fname = $namen[$id] }
        $zuordnung[$n] = @{ id = $id; name = $fname }
    }
    return $zuordnung
}

# Verschiedene BLSV-Nummern der (bis zu drei) aktuellen Abteilungsplaetze.
function Abteilungen($zeile, $nummernSpalten, $namensSpalten) {
    $ergebnis = New-Object 'System.Collections.Generic.List[string]'
    foreach ($platz in 1, 2, 3) {
        $nummer = ''
        $name = ''
        if ($nummernSpalten.ContainsKey($platz) -and $nummernSpalten[$platz] -lt $zeile.Count) { $nummer = ([string]$zeile[$nummernSpalten[$platz]]).Trim() }
        if ($namensSpalten.ContainsKey($platz) -and $namensSpalten[$platz] -lt $zeile.Count) { $name = ([string]$zeile[$namensSpalten[$platz]]).Trim() }
        if ($nummer -eq '' -and $name -eq '') { continue }
        $blsv = $null
        $n = Normal $name
        if ($script:ABTEILUNGSNAMEN.ContainsKey($n)) { $blsv = $script:ABTEILUNGSNAMEN[$n] }
        if ($null -eq $blsv -and (IstZahl $nummer)) { $blsv = (AlsNummer $nummer).PadLeft(2, '0') }
        if ($null -eq $blsv) {
            Zaehle 'unbekannte_abteilung'
            continue
        }
        if ($script:BEKANNTE_BLSV -notcontains $blsv) { Zaehle 'abteilung_ohne_blsv' }
        if (-not $ergebnis.Contains($blsv)) { $ergebnis.Add($blsv) }
    }
    return , $ergebnis
}

# Alter Grundbeitrag + Zuschlag "aktiv" -> neue Beitragsart und Aktiv-Haekchen.
function Beitrag($zeile, $beitragsSpalten, [string]$notiz) {
    $gefunden = @{}
    $zuschlag = $false
    foreach ($platz in ($beitragsSpalten.Keys | Sort-Object)) {
        $spalte = $beitragsSpalten[$platz]
        $name = ''
        if ($spalte -lt $zeile.Count) { $name = ([string]$zeile[$spalte]).Trim() }
        if ($name -eq '') { continue }
        $schluessel = Normal $name
        if ($schluessel -eq 'aktiv') {
            $zuschlag = $true
        } elseif ($script:GRUNDBEITRAEGE.ContainsKey($schluessel)) {
            $gefunden[$script:GRUNDBEITRAEGE[$schluessel]] = $true
        } else {
            Zaehle 'unbekannter_beitragsname' $name
        }
    }
    $grund = ''
    foreach ($art in $script:RANGFOLGE) {
        if ($gefunden.ContainsKey($art)) { $grund = $art; break }
    }
    if ($gefunden.Count -gt 1) { Zaehle 'mehrere_grundbeitraege' }
    # Aktiv laut Notiz nur am Wortanfang ("Fussball-Aktiv", nicht "inaktiv").
    $ausNotiz = [regex]::IsMatch($notiz, '(?<!\p{L})aktiv', [System.Text.RegularExpressions.RegexOptions]::IgnoreCase)

    switch ($grund) {
        'erwachsene' { if ($zuschlag) { $e = @('Aktiv', $true, 'Erwachsene + aktiv') } else { $e = @('Passiv', $false, 'Erwachsene') } }
        'kinder' { $e = @($script:T_JUGEND, $true, 'Kinder') }
        'jugendliche' { $e = @($script:T_JUGEND, $true, 'Jugendliche') }
        'rentner' { if ($zuschlag) { $e = @('Rentner aktiv', $true, 'Rentner + aktiv') } else { $e = @('Rentner passiv', $false, 'Rentner') } }
        'familienkarte' { $e = @('Familie', ($zuschlag -or $ausNotiz), 'Familienkarte') }
        'beitragsfrei_fk' { $e = @('', ($zuschlag -or $ausNotiz), 'Beitragsfrei FK') }
        'mindestbeitrag' { if ($zuschlag) { $e = @('Mindestbeitrag', $true, 'Mindestbeitrag + aktiv') } else { $e = @('Mindestbeitrag', $false, 'Mindestbeitrag') } }
        'beitragsfrei' { $e = @('Beitragsfrei', $true, 'Beitragsfrei') }
        default { $e = @('', ($zuschlag -or $ausNotiz), '(nicht zuordenbar)') }
    }
    if ($grund -eq '') { Zaehle 'beitrag_nicht_zuordenbar' }
    $aktiv = '0'
    if ($e[1]) { $aktiv = '1' }
    return @{ grund = $grund; art = $e[0]; aktiv = $aktiv; kombination = $e[2] }
}

# Zahlart, Konto und - nur wenn jemand anderes zahlt - der Kontoinhaber.
function Zahlung($zeile, $spalten, [string]$nummer, $nachNummer) {
    $art = Normal (Feld $zeile $spalten 'zahlart')
    $zahlart = ''
    if ($art.StartsWith('lastschrift', [System.StringComparison]::Ordinal)) { $zahlart = 'lastschrift' }
    elseif ($art.StartsWith('rechnung', [System.StringComparison]::Ordinal)) { $zahlart = 'selbst' }
    if ($zahlart -eq '') { Zaehle 'zahlart_unbekannt' } else { Zaehle 'zahlart' $zahlart }

    $zahlernummer = Feld $zeile $spalten 'zahlernummer'
    $hatNummer = IstZahl $zahlernummer
    $zahlerzeile = $null
    if ($hatNummer -and $nachNummer.ContainsKey([long]$zahlernummer)) { $zahlerzeile = $nachNummer[[long]$zahlernummer] }
    $zahlername = ((Feld $zeile $spalten 'zahlervorname') + ' ' + (Feld $zeile $spalten 'zahlername')).Trim()
    $eigenerName = ((Feld $zeile $spalten 'vorname') + ' ' + (Feld $zeile $spalten 'nachname')).Trim()

    if ($hatNummer) {
        $abweichend = (AlsNummer $zahlernummer) -ne $nummer
    } else {
        $abweichend = ($zahlername -ne '' -and $zahlername.ToLowerInvariant() -ne $eigenerName.ToLowerInvariant())
    }

    $iban = Feld $zeile $spalten 'iban'
    $bic = Feld $zeile $spalten 'bic'
    $kontoinhaber = ''
    if ($abweichend) {
        Zaehle 'abweichender_zahler'
        if ($zahlername -eq '' -and $null -ne $zahlerzeile) {
            $zahlername = ((Feld $zahlerzeile $spalten 'vorname') + ' ' + (Feld $zahlerzeile $spalten 'nachname')).Trim()
        }
        if ($zahlername -eq '') { Zaehle 'zahler_unbekannt' }
        $kontoinhaber = $zahlername
        $zahleriban = Feld $zeile $spalten 'zahleriban'
        if ($zahleriban -ne '') {
            $iban = $zahleriban
        } elseif ($iban -eq '' -and $null -ne $zahlerzeile) {
            $iban = Feld $zahlerzeile $spalten 'iban'
            if ($bic -eq '') { $bic = Feld $zahlerzeile $spalten 'bic' }
        }
    }
    $iban = [regex]::Replace($iban, '\s+', '').ToUpperInvariant()
    if ($zahlart -eq 'lastschrift' -and $iban -eq '') { Zaehle 'lastschrift_ohne_iban' }
    return @{ zahlart = $zahlart; iban = $iban; bic = $bic; kontoinhaber = $kontoinhaber }
}

function Umwandeln($mitglieder, $familien) {
    $kopf = @()
    if ($mitglieder.Count -gt 0) { $kopf = $mitglieder[0] }
    $spalten = SpaltenSuchen $kopf $script:QUELLSPALTEN
    $fehlend = New-Object 'System.Collections.Generic.List[string]'
    foreach ($schluessel in $script:QUELLSPALTEN.Keys) {
        if ($spalten.Contains($schluessel)) { continue }
        $anzeige = $script:QUELLSPALTEN[$schluessel][0]
        if ($script:PFLICHT -contains $schluessel) {
            throw ('In der Mitgliederdatei fehlt die Spalte "' + $anzeige + '" - ist das die richtige Datei?')
        }
        if ($schluessel -ne 'lkz' -and $schluessel -ne 'land') { $fehlend.Add($anzeige) }
    }
    if (-not $spalten.Contains('land') -and -not $spalten.Contains('lkz')) { $fehlend.Add('Land/Lkz') }
    $abteilungsnummern = PlatzSpalten $kopf 'abteilungsnummer'
    $abteilungsnamen = PlatzSpalten $kopf 'abteilungsname'
    $beitragsspalten = PlatzSpalten $kopf 'beitragsname'
    if ($abteilungsnummern.Count -eq 0 -and $abteilungsnamen.Count -eq 0) {
        throw 'In der Mitgliederdatei fehlen die Abteilungsspalten "(alle aktuell)(1..3)".'
    }
    if ($beitragsspalten.Count -eq 0) {
        throw 'In der Mitgliederdatei fehlen die Beitragsspalten "Beitragsname (alle aktuell)(1..3)".'
    }
    $lizenzspalte = LizenzSpalte $kopf
    if ($null -eq $lizenzspalte) { $fehlend.Add('Uel* (Lizenznummer)') }

    $familienzuordnung = FamilienLesen $familien

    $zeilen = New-Object 'System.Collections.Generic.List[object]'
    for ($r = 1; $r -lt $mitglieder.Count; $r++) {
        if ([string]::Join('', $mitglieder[$r]) -ne '') { $zeilen.Add($mitglieder[$r]) }
    }

    # Alle Zeilen nach Adressnummer: ein Zahler ohne Mitgliedschaft wird nur
    # als Name und Konto der Mitglieder gebraucht, fuer die er zahlt.
    $nachNummer = @{}
    foreach ($zeile in $zeilen) {
        $nummer = Feld $zeile $spalten 'nummer'
        if ((IstZahl $nummer) -and -not $nachNummer.ContainsKey([long]$nummer)) { $nachNummer[[long]$nummer] = $zeile }
    }

    $ausgabe = New-Object 'System.Collections.Generic.List[object]'
    $gesehen = @{}
    $familienrollen = [ordered]@{}
    $familienmitglieder = @{}
    foreach ($zeile in $zeilen) {
        Zaehle 'gelesen'
        $nummer = Feld $zeile $spalten 'nummer'
        if (-not (IstZahl $nummer)) {
            Zaehle 'ohne_nummer'
            continue
        }
        $nummer = AlsNummer $nummer

        $abteilungen = Abteilungen $zeile $abteilungsnummern $abteilungsnamen
        if ($abteilungen.Count -eq 0) {
            Zaehle 'ohne_mitgliedschaft'
            continue
        }
        if ($gesehen.ContainsKey($nummer)) { Zaehle 'doppelte_nummer' }
        $gesehen[$nummer] = $true

        $notiz = Notizen @((Feld $zeile $spalten 'adrnotizen'), (Feld $zeile $spalten 'mitnotizen'))
        $beitrag = Beitrag $zeile $beitragsspalten $notiz
        $geburt = Datum (Feld $zeile $spalten 'geburt')
        $zahlung = Zahlung $zeile $spalten $nummer $nachNummer

        $familie = $null
        if ($familienzuordnung.ContainsKey([long]$nummer)) { $familie = $familienzuordnung[[long]$nummer] }
        $rolle = ''
        if ($null -ne $familie) {
            if ($beitrag.grund -eq 'familienkarte') {
                $rolle = 'zahler'
            } elseif ($geburt -ne '' -and (Alter $geburt) -lt 18) {
                $rolle = 'kind'
            } else {
                $rolle = 'elternteil'
                if ($geburt -eq '') { Zaehle 'rolle_ohne_geburtsdatum' }
            }
            Zaehle ('rolle_' + $rolle)
            if (-not $familienrollen.Contains($familie.id)) { $familienrollen[$familie.id] = New-Object 'System.Collections.Generic.List[string]' }
            $familienrollen[$familie.id].Add($rolle)
            $familienmitglieder[[long]$nummer] = $true
        } elseif ($beitrag.grund -eq 'familienkarte') {
            Zaehle 'fk_ohne_familie'
        } elseif ($beitrag.grund -eq 'beitragsfrei_fk') {
            Zaehle 'fk_frei_ohne_familie'
        }

        $eintritt = Datum (Feld $zeile $spalten 'eintritt')
        if ($eintritt -eq '') { Zaehle 'eintritt_unbekannt' }

        $funktionen = ''
        $lizenz = ''
        if ($null -ne $lizenzspalte -and $lizenzspalte -lt $zeile.Count) {
            $wert = ([string]$zeile[$lizenzspalte]).Trim()
            if ($wert -ne '' -and $wert -ne '0') {
                $funktionen = $script:T_UEBUNGSLEITER
                $lizenz = $wert
                Zaehle 'uebungsleiter'
            }
        }

        Zaehle 'beitragsart' $beitrag.art
        Zaehle 'kombination' $beitrag.kombination
        Zaehle 'aktiv' $beitrag.aktiv
        foreach ($blsv in $abteilungen) { Zaehle 'abteilung' $blsv }
        if ($abteilungen.Count -gt 1) { Zaehle 'mehrere_abteilungen' }

        $land = Feld $zeile $spalten 'lkz'
        if ($land -eq '') { $land = Feld $zeile $spalten 'land' }
        # Der Import nimmt hoechstens 3 Zeichen: "Deutschland" wird "D", alles
        # andere so Lange kommt in die Bemerkung statt den Import zu blockieren.
        if ((Normal $land) -eq 'deutschland') {
            $land = 'D'
        } elseif ($land.Length -gt 3) {
            Zaehle 'land_unbekannt' $land
            $notiz = Notizen @($notiz, ('Land laut Altdaten: ' + $land))
            $land = ''
        }
        $plz = Feld $zeile $spalten 'plz'
        if ([regex]::IsMatch($plz, '\A[0-9]{4}\z') -and @('', 'D', 'DE') -contains $land.ToUpperInvariant()) {
            $plz = '0' + $plz # eine Zahlenzelle hat die fuehrende Null verloren
        }

        $familieId = ''
        $familieName = ''
        if ($null -ne $familie) { $familieId = $familie.id; $familieName = $familie.name }

        $ausgabe.Add([ordered]@{
            'mitgliedsnummer' = $nummer
            'anrede'          = Feld $zeile $spalten 'anrede'
            'titel'           = Feld $zeile $spalten 'titel'
            'vorname'         = Feld $zeile $spalten 'vorname'
            'nachname'        = Feld $zeile $spalten 'nachname'
            'namenszusatz'    = ''
            'geschlecht'      = Geschlecht (Feld $zeile $spalten 'geschlecht')
            'geburtsdatum'    = $geburt
            'eintrittsdatum'  = $eintritt
            'austrittsdatum'  = Datum (Feld $zeile $spalten 'austritt')
            'austrittsgrund'  = Feld $zeile $spalten 'grund'
            'strasse'         = Feld $zeile $spalten 'strasse'
            'plz'             = $plz
            'ort'             = Feld $zeile $spalten 'ort'
            'land'            = $land
            'telefon'         = Feld $zeile $spalten 'telefon'
            'handy'           = Feld $zeile $spalten 'handy'
            'email'           = Feld $zeile $spalten 'email'
            'aktiv'           = $beitrag.aktiv
            'abteilungen'     = [string]::Join(',', $abteilungen.ToArray())
            'beitragsart'     = $beitrag.art
            'zahlart'         = $zahlung.zahlart
            'iban'            = $zahlung.iban
            'bic'             = $zahlung.bic
            'kontoinhaber'    = $zahlung.kontoinhaber
            'mandatsreferenz' = ''
            'mandatsdatum'    = ''
            'familie'         = $familieId
            'familie_name'    = $familieName
            'familie_rolle'   = $rolle
            'funktionen'      = $funktionen
            'lizenznummer'    = $lizenz
            'bemerkung'       = $notiz
        })
    }

    foreach ($rollen in $familienrollen.Values) {
        Zaehle 'familie'
        $zahler = 0
        foreach ($rolle in $rollen) { if ($rolle -eq 'zahler') { $zahler++ } }
        if ($zahler -eq 0) { Zaehle 'familien_ohne_zahler' } elseif ($zahler -gt 1) { Zaehle 'familien_mehrere_zahler' }
    }
    foreach ($n in $familienzuordnung.Keys) {
        if (-not $familienmitglieder.ContainsKey($n)) { Zaehle 'familie_person_ohne_mitglied' }
    }

    return @{ zeilen = $ausgabe; fehlend = $fehlend }
}

# ---------------------------------------------------------------------------
# Ausgabe
# ---------------------------------------------------------------------------

# Ein CSV-Feld: in Anfuehrungszeichen, wenn es ; " Zeilenumbruch, Tab oder
# Leerzeichen enthaelt (wie bisher das PHP-Skript).
function CsvFeld([string]$wert) {
    if ($wert.IndexOfAny([char[]]@(';', '"', "`n", "`r", "`t", ' ')) -ge 0) {
        return '"' + $wert.Replace('"', '""') + '"'
    }
    return $wert
}

function Csv($zeilen) {
    $sb = New-Object System.Text.StringBuilder
    [void]$sb.Append([string]::Join(';', ($script:SPALTEN | ForEach-Object { CsvFeld $_ }))).Append("`n")
    foreach ($zeile in $zeilen) {
        $felder = foreach ($s in $script:SPALTEN) { CsvFeld ([string]$zeile[$s]) }
        [void]$sb.Append([string]::Join(';', @($felder))).Append("`n")
    }
    return $sb.ToString()
}

# Links ausgerichtet auf eine Breite in UTF-8-Bytes (wie printf in PHP).
function Links([string]$text, [int]$breite) {
    $bytes = [System.Text.Encoding]::UTF8.GetByteCount($text)
    if ($bytes -ge $breite) { return $text }
    return $text + (' ' * ($breite - $bytes))
}

function Zusammenfassung($ergebnis) {
    $ae = [string][char]0x00E4
    $oe = [string][char]0x00F6
    $z = New-Object 'System.Collections.Generic.List[string]'
    $z.Add('Umwandlung der Altdaten')
    $z.Add('')
    $z.Add('Mitgliederdatei')
    $z.Add('  Zeilen gelesen:                      ' + (Summe 'gelesen'))
    $z.Add("  als Mitglied ${kleinUe}bernommen:             " + $ergebnis.zeilen.Count)
    $z.Add("  nicht ${kleinUe}bernommen, keine Mitgliedschaft: " + (Summe 'ohne_mitgliedschaft'))
    $z.Add("  nicht ${kleinUe}bernommen, ohne Adressnummer: " + (Summe 'ohne_nummer'))
    $z.Add('  doppelte Adressnummer (beide drin):  ' + (Summe 'doppelte_nummer'))
    $z.Add('')
    $z.Add('Beitragsart (neu)')
    foreach ($name in (GruppenSchluessel 'beitragsart')) {
        $anzeige = $name
        if ($name -eq '') { $anzeige = '(keine)' }
        $z.Add('  ' + (Links $anzeige 34) + ' ' + (Anzahl 'beitragsart' $name))
    }
    $z.Add('')
    $z.Add('Alte Beitragskombination')
    foreach ($name in (GruppenSchluessel 'kombination')) {
        $z.Add('  ' + (Links $name 34) + ' ' + (Anzahl 'kombination' $name))
    }
    $z.Add('')
    $z.Add('aktiv / passiv')
    $z.Add('  aktiv:  ' + (Anzahl 'aktiv' '1'))
    $z.Add('  passiv: ' + (Anzahl 'aktiv' '0'))
    $z.Add('')
    $z.Add('Abteilungen (BLSV-Nummer)')
    foreach ($nummer in (GruppenSchluessel 'abteilung')) {
        $z.Add('  ' + (Links $nummer 34) + ' ' + (Anzahl 'abteilung' $nummer))
    }
    $z.Add('  Mitglieder in mehreren Abteilungen:  ' + (Summe 'mehrere_abteilungen'))
    $z.Add('')
    $z.Add('Familien')
    $z.Add('  Familien:                            ' + (Summe 'familie'))
    $z.Add('  Zahler:                              ' + (Summe 'rolle_zahler'))
    $z.Add("  Elternteile (Pr${kleinUe}fliste):              " + (Summe 'rolle_elternteil'))
    $z.Add('  Kinder:                              ' + (Summe 'rolle_kind'))
    $z.Add('')
    $z.Add('Zahlung')
    $z.Add('  Lastschrift:                         ' + (Anzahl 'zahlart' 'lastschrift'))
    $z.Add('  selbst (Rechnungszahler):            ' + (Anzahl 'zahlart' 'selbst'))
    $z.Add('  abweichender Zahler (Kontoinhaber):  ' + (Summe 'abweichender_zahler'))
    $z.Add('')
    $z.Add('Sonstiges')
    $z.Add("  ${grossUe}bungsleiter (mit Lizenznummer):     " + (Summe 'uebungsleiter'))
    $z.Add('  Platzhalterdatum 01.01.1901 geleert: ' + (Summe 'platzhalter_datum'))
    $z.Add('  Eintrittsdatum unbekannt:            ' + (Summe 'eintritt_unbekannt'))

    $hinweise = [ordered]@{
        'beitrag_nicht_zuordenbar'     = "Mitglieder ohne erkennbare Beitragsart (bitte pr${kleinUe}fen)"
        'mehrere_grundbeitraege'       = "Mitglieder mit mehreren verschiedenen Grundbeitr${ae}gen (der h${oe}chste z${ae}hlt)"
        'unbekannte_abteilung'         = 'Abteilungsangaben ohne erkennbare Abteilung (ignoriert)'
        'abteilung_ohne_blsv'          = 'Abteilungen mit unbekannter BLSV-Nummer (Vorschau des Imports meldet sie)'
        'fk_ohne_familie'              = 'Familienkarte ohne Familie'
        'fk_frei_ohne_familie'         = 'Beitragsfrei FK ohne Familie'
        'familie_person_ohne_mitglied' = 'Familienzeilen ohne passendes Mitglied'
        'familie_doppelt'              = "Personen in mehr als einer Familie (erste z${ae}hlt)"
        'familien_ohne_zahler'         = 'Familien ohne Zahler'
        'familien_mehrere_zahler'      = 'Familien mit mehreren Zahlern'
        'rolle_ohne_geburtsdatum'      = "Familienmitglieder ohne Geburtsdatum (als Elternteil gef${kleinUe}hrt)"
        'zahler_unbekannt'             = 'abweichende Zahler ohne auffindbaren Namen'
        'zahlart_unbekannt'            = 'Mitglieder mit unbekannter Zahlart (leer gelassen)'
        'lastschrift_ohne_iban'        = 'Lastschriftzahler ohne IBAN'
        'datum_kaputt'                 = 'nicht lesbare Datumsangaben (geleert)'
    }
    $z.Add('')
    $z.Add("Zur Pr${kleinUe}fung")
    $gefunden = $false
    foreach ($schluessel in $hinweise.Keys) {
        $summe = Summe $schluessel
        if ($summe -gt 0) {
            $z.Add('  ' + (Links ($hinweise[$schluessel] + ':') 56) + ' ' + $summe)
            $gefunden = $true
        }
    }
    foreach ($name in (GruppenSchluessel 'unbekannter_beitragsname')) {
        $z.Add('  unbekannter Beitragsname "' + $name + '": ' + (Anzahl 'unbekannter_beitragsname' $name))
        $gefunden = $true
    }
    foreach ($name in (GruppenSchluessel 'land_unbekannt')) {
        $z.Add("  Land nicht erkannt, in die Bemerkung ${kleinUe}bernommen `"" + $name + '": ' + (Anzahl 'land_unbekannt' $name))
        $gefunden = $true
    }
    foreach ($spalte in $ergebnis.fehlend) {
        $z.Add('  Spalte nicht gefunden: ' + $spalte)
        $gefunden = $true
    }
    if (-not $gefunden) { $z.Add("  nichts Auff${ae}lliges") }
    return [string]::Join("`n", $z.ToArray()) + "`n"
}

# ---------------------------------------------------------------------------
# Aufruf
# ---------------------------------------------------------------------------

try {
    if ($Ordner -ne '') {
        if ($Mitglieder -eq '') { $Mitglieder = Join-Path $Ordner 'mitglieder.xlsx' }
        if ($Familien -eq '') { $Familien = Join-Path $Ordner 'familien.xlsx' }
        if ($Ausgabe -eq '') { $Ausgabe = Join-Path $Ordner 'import.csv' }
    }
    if ($Mitglieder -eq '' -or $Familien -eq '') {
        throw 'Aufruf: altdaten-umwandeln.ps1 -Ordner <Ordner mit mitglieder.xlsx und familien.xlsx> [-Stichtag TT.MM.JJJJ]'
    }
    if ($Ausgabe -eq '') { $Ausgabe = Join-Path (Split-Path -Parent $Mitglieder) 'import.csv' }

    $script:stichtagDatum = [DateTime]::Today
    if ($Stichtag -ne '') {
        $gelesen = [DateTime]::MinValue
        if (-not [DateTime]::TryParseExact($Stichtag, 'dd.MM.yyyy', $script:INVARIANT, [System.Globalization.DateTimeStyles]::None, [ref]$gelesen)) {
            throw 'Stichtag bitte als TT.MM.JJJJ angeben.'
        }
        $script:stichtagDatum = $gelesen
    }

    $ergebnis = Umwandeln (XlsxLesen $Mitglieder) (XlsxLesen $Familien)

    # Relative Pfade gelten ab dem aktuellen Ordner der PowerShell.
    $ziel = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Ausgabe)
    $utf8 = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($ziel, (Csv $ergebnis.zeilen), $utf8)
    $text = Zusammenfassung $ergebnis
    [System.IO.File]::WriteAllText((Join-Path (Split-Path -Parent $ziel) 'zusammenfassung.txt'), $text, $utf8)

    Write-Host $text
    Write-Host "Geschrieben: $ziel"
    exit 0
} catch {
    Write-Host ('Abbruch: ' + $_.Exception.Message) -ForegroundColor Red
    exit 1
}
