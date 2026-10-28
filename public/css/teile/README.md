# Stylesheet-Teile

Das Designsystem steht in `public/css/app.css`. Ein Issue, das eigene Regeln
braucht, legt sie als eigene Datei hier ab (`<bereich>.css`) statt
`app.css` zu ändern (docs/spec/05-entwicklung.md §6). Das Layout bindet jede
`*.css` dieses Ordners nach `app.css` ein, in Namensreihenfolge
(`App\View\Stylesheets`) – ohne Änderung an einer zentralen Datei.

Nur Ergänzungen mit den Tokens aus `app.css` (Farben, Abstände, Schrift),
keine eigenen Farbwerte: hell/dunkel und der Barrierefrei-Modus wirken dann
von selbst.
