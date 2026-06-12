// Deutsche Übersetzungen. Platzhalter {0}, {1}, … werden positionsweise aus
// den Parametern der Backend-Meldungen bzw. den t()-Argumenten gefüllt.
export default {
  language: {
    de: 'Deutsch',
    en: 'English',
  },

  app: {
    title: 'HugoCMS – Dateimanager',
    logout: 'Abmelden',
    notReady: 'HugoCMS ist nicht einsatzbereit',
    retry: 'Erneut prüfen',
    setupWarningTitle: 'Server-Hinweis zur Einrichtung',
    close: 'Schließen',
  },

  login: {
    title: 'Anmeldung',
    username: 'Benutzername',
    password: 'Passwort',
    submit: 'Anmelden',
  },

  setup: {
    title: 'HugoCMS einrichten',
    intro: 'Es wurde noch keine Konfiguration gefunden. Lege hier den Zugang an — daraus wird die hugocms.ini erzeugt.',
    sectionLogin: 'Anmeldung',
    sectionDirs: 'Verzeichnisse und Logging',
    username: 'Benutzername',
    password: 'Passwort',
    passwordHint: 'Mindestens {0} Zeichen',
    passwordConfirm: 'Passwort bestätigen',
    sessionPath: 'Sitzungsverzeichnis',
    sessionPathHint: 'Relativ zum backend-Verzeichnis; wird angelegt, falls es fehlt.',
    logFile: 'Logdatei',
    logFileHint: 'Relativ zum backend-Verzeichnis; das Verzeichnis wird angelegt.',
    logLevel: 'Log-Stufe',
    submit: 'Einrichten und anmelden',
    passwordTooShort: 'Das Passwort muss mindestens {0} Zeichen lang sein.',
    passwordMismatch: 'Die Passwörter stimmen nicht überein.',
  },

  files: {
    places: 'Orte',
    chooseMount: 'Wähle links einen Ort.',
    colName: 'Name',
    colSize: 'Größe',
    colType: 'Typ',
    colModified: 'Geändert',
    typeFolder: 'Ordner',
    emptyDir: 'Dieser Ordner ist leer',
    noMatch: 'Kein Treffer für „{0}“',
  },

  nav: {
    back: 'Zurück',
    forward: 'Vor',
    up: 'Übergeordneter Ordner',
    refresh: 'Aktualisieren',
  },

  view: {
    icons: 'Symbolansicht',
    list: 'Listenansicht',
  },

  toolbar: {
    filter: 'Im Ordner filtern …',
  },

  status: {
    items: '{0} Elemente',
    itemOne: '1 Element',
    selected: '{0} ausgewählt',
  },

  ctx: {
    open: 'Öffnen',
    edit: 'Bearbeiten',
    cut: 'Ausschneiden',
    copy: 'Kopieren',
    paste: 'Einfügen',
    rename: 'Umbenennen',
    trash: 'In den Papierkorb',
    newFolder: 'Neuer Ordner',
    newFile: 'Neue Datei',
    download: 'Herunterladen',
    upload: 'Dateien hochladen …',
  },

  dnd: {
    dropHint: 'Zum Hochladen hier ablegen',
  },

  search: {
    placeholder: 'Suchen (Enter) oder filtern …',
    resultsFor: 'Suchergebnisse für „{0}“',
    colPath: 'Pfad',
    none: 'Keine Treffer für „{0}“',
    truncated: 'Nur die ersten {0} Treffer werden angezeigt.',
    leave: 'Suche verlassen',
  },

  trash: {
    title: 'Papierkorb',
    empty: 'Der Papierkorb ist leer',
    colOrigin: 'Ursprungsort',
    colMount: 'Ort',
    colDeleted: 'Gelöscht am',
    restore: 'Wiederherstellen',
    emptyAction: 'Papierkorb leeren',
    emptyConfirm: 'Alle Einträge endgültig löschen? Das kann nicht rückgängig gemacht werden.',
  },

  viewer: {
    counter: 'Bild {0} von {1}',
    prev: 'Vorheriges Bild',
    next: 'Nächstes Bild',
    close: 'Schließen',
    download: 'Herunterladen',
  },

  dialog: {
    create: 'Anlegen',
    rename: 'Umbenennen',
    cancel: 'Abbrechen',
    newFolderTitle: 'Neuer Ordner',
    newFolderLabel: 'Ordnername',
    newFileTitle: 'Neue Datei',
    newFileLabel: 'Dateiname',
    renameTitle: 'Umbenennen',
    renameLabel: 'Neuer Name',
  },

  editor: {
    save: 'Speichern (Strg+S)',
    discardConfirm: 'Ungespeicherte Änderungen verwerfen?',
    conflictConfirm: 'Die Datei wurde seit dem Öffnen extern geändert. Mit Ihrer Fassung überschreiben?',
    cursor: 'Zeile {0}, Spalte {1}',
    plainText: 'Text',
    undo: 'Rückgängig (Strg+Z)',
    redo: 'Wiederholen (Strg+Y)',
    search: 'Suchen und Ersetzen (Strg+F)',
    gotoLine: 'Gehe zu Zeile',
    indentLess: 'Einzug verringern (Strg+[)',
    indentMore: 'Einzug vergrößern (Strg+])',
    toggleComment: 'Kommentar umschalten (Strg+/)',
    foldAll: 'Alle Abschnitte einklappen',
    unfoldAll: 'Alle Abschnitte ausklappen',
    sourceView: 'Quelltext',
    wysiwygView: 'Visuell',
    frontMatter: 'Front-Matter (Metadaten)',
  },

  wysiwyg: {
    bold: 'Fett',
    italic: 'Kursiv',
    strike: 'Durchgestrichen',
    h1: 'Überschrift 1',
    h2: 'Überschrift 2',
    h3: 'Überschrift 3',
    bulletList: 'Aufzählung',
    orderedList: 'Nummerierte Liste',
    blockquote: 'Zitat',
    codeBlock: 'Code-Block',
    inlineCode: 'Code (Zeile)',
    hr: 'Trennlinie',
  },

  // Feldnamen — vom Backend über Parameter {t: "fields.x"} referenziert.
  fields: {
    username: 'Benutzername',
    password: 'Passwort',
    sessionPath: 'Sitzungsverzeichnis',
    logFile: 'Logdatei',
    logLevel: 'Log-Stufe',
  },

  // Fehlermeldungen. Schlüssel sind entweder die eindeutige Fehlerklasse
  // (Code) oder der genauere Meldungsschlüssel aus dem Backend.
  errors: {
    EUNKNOWN: 'Unbekannter Fehler.',
    ENETWORK: 'Ungültige Antwort vom Server (HTTP {0}).',
    EAUTH: 'Anmeldung erforderlich.',
    ENOENT: 'Nicht gefunden.',
    EACCES: 'Zugriff verweigert.',
    EINVAL: 'Ungültige Anfrage.',
    ECONFLICT: 'Konflikt mit dem aktuellen Stand auf dem Server.',
    EINTERNAL: 'Interner Fehler.',
    EFATAL: 'Interner Fehler.',
    ESESSION: 'Sitzungsverzeichnis nicht les-/beschreibbar: {0}. Eine Anmeldung ist nicht möglich — bitte Lese- und Schreibrechte für den Webserver-Benutzer setzen.',
    ESITE: 'Unbekannte Webseite „{0}“: weder mounts/{1}.ini noch der Rückfall mounts.ini vorhanden. Die Einrichtung folgt.',

    'CONFIG-NOT-READABLE': 'Konfiguration nicht lesbar: {0}',
    'CONFIG-INVALID-INI': 'Konfiguration ist kein gültiges INI: {0}',
    'CONFIG-INCOMPLETE': 'Unvollständige Konfiguration in {0}: Folgende Pflichtfelder fehlen oder sind leer: {1}.',

    'AUTH-MISSING': 'Authentifizierung fehlt: Option „auth“ übergeben oder über „config“ konfigurieren.',
    'UNKNOWN-COMMAND': 'Unbekannter Befehl: {0}',
    'LOGIN-FAILED': 'Benutzername oder Passwort falsch.',
    'PARAM-MISSING': 'Parameter „{0}“ fehlt.',
    'PARAM-INVALID': 'Parameter „{0}“ ist ungültig.',
    'OPERATION-NOT-ALLOWED': 'Operation „{0}“ auf diesem Mount nicht erlaubt.',
    'METHOD-REQUIRED': 'Diese Operation erfordert {0}.',

    'NOT-A-DIRECTORY': 'Kein Verzeichnis.',
    'FILE-NOT-FOUND': 'Datei nicht gefunden.',
    'FILETYPE-NOT-EDITABLE': 'Dieser Dateityp kann nicht im Editor geöffnet werden.',
    'FILE-TOO-LARGE': 'Datei ist zu groß für den Editor.',
    'FILE-READ-FAILED': 'Datei konnte nicht gelesen werden.',
    'FILETYPE-NOT-SAVABLE': 'Dieser Dateityp kann nicht im Editor gespeichert werden.',
    'FILETYPE-NOT-ALLOWED-MOUNT': 'Dieser Dateityp ist auf diesem Mount nicht erlaubt.',
    'CONTENT-TOO-LARGE': 'Inhalt ist zu groß.',
    'TEMPFILE-FAILED': 'Temporäre Datei konnte nicht angelegt werden.',
    'FILE-SAVE-FAILED': 'Datei konnte nicht gespeichert werden.',
    'CONFLICT-MTIME': 'Die Datei wurde seit dem Öffnen extern geändert.',

    'INVALID-NAME': 'Ungültiger Name: „{0}“.',
    'ALREADY-EXISTS': '„{0}“ existiert bereits.',
    'MKDIR-FAILED': 'Der Ordner konnte nicht angelegt werden.',
    'CREATE-FAILED': 'Die Datei konnte nicht angelegt werden.',
    'RENAME-FAILED': 'Umbenennen fehlgeschlagen.',
    'COPY-FAILED': 'Kopieren fehlgeschlagen.',
    'MOVE-FAILED': 'Verschieben fehlgeschlagen.',
    'DELETE-FAILED': 'Löschen fehlgeschlagen.',
    'DEST-NOT-DIRECTORY': 'Das Ziel ist kein Ordner.',
    'CROSS-MOUNT-NOT-ALLOWED': 'Über Mounts hinweg ist diese Aktion nicht möglich.',
    'CANNOT-MOVE-INTO-SELF': 'Ein Ordner kann nicht in sich selbst verschoben oder kopiert werden.',

    'UPLOAD-FAILED': 'Hochladen von „{0}“ fehlgeschlagen.',
    'UPLOAD-TOO-LARGE': '„{0}“ ist zu groß (höchstens {1}).',
    'NOT-AN-IMAGE': 'Keine Bilddatei.',

    ECSRF: 'Sicherheits-Token ungültig — bitte die Seite neu laden.',
    'TRASH-NOT-FOUND': 'Papierkorb-Eintrag nicht gefunden.',
    'RESTORE-FAILED': 'Wiederherstellen fehlgeschlagen.',

    'MOUNT-NAME-INVALID': 'Ungültiger Mount-Name: {0}',
    'MOUNT-NAME-TAKEN': 'Mount bereits vergeben: {0}',
    'MOUNT-UNKNOWN': 'Unbekannter Mount: {0}',
    'ID-INVALID': 'Ungültige ID.',
    'PATH-NOT-FOUND': 'Datei oder Verzeichnis nicht gefunden.',
    'TARGET-DIR-NOT-FOUND': 'Zielverzeichnis nicht gefunden.',
    'PATH-OUTSIDE-MOUNT': 'Pfad liegt außerhalb des Mounts.',
    'PATH-INVALID-CHAR': 'Ungültiges Zeichen im Pfad.',
    'PARENT-PATH-NOT-ALLOWED': 'Übergeordnete Pfade sind nicht erlaubt.',
    'MOUNT-PATH-MISSING': 'Mount-Pfad existiert nicht oder ist kein Verzeichnis: {0}',

    'MOUNTS-NOT-READABLE': 'Mount-Konfiguration nicht lesbar: {0}',
    'MOUNTS-INVALID-INI': 'Mount-Konfiguration ist kein gültiges INI: {0}',
    'MOUNTS-ENTRY-OUTSIDE-SECTION': 'Mount-Konfiguration: Eintrag „{0}“ steht außerhalb einer [Sektion].',
    'MOUNTS-PATH-REQUIRED': 'Mount „{0}“: Pflichtfeld „path“ fehlt.',
    'MOUNTS-NO-SECTION': 'Mount-Konfiguration enthält keine [Sektion]: {0}',

    'AUTH-SINGLEUSER-REQUIRED': '[auth] driver=singleuser: „username“ und „password_hash“ sind erforderlich.',
    'AUTH-DRIVER-UNKNOWN': '[auth]: unbekannter driver „{0}“.',
    'AUTH-DRIVER-INVALID': '[auth]: driver „{0}“ lieferte kein AuthInterface.',

    'SETUP-REQUIRED': 'HugoCMS ist noch nicht eingerichtet.',
    'SETUP-METHOD-POST': 'Die Einrichtung erfordert POST.',
    'SETUP-ALREADY-CONFIGURED': 'Die Konfiguration besteht bereits.',
    'SETUP-DIR-NOT-WRITABLE': 'Verzeichnis nicht beschreibbar: {0} — bitte Schreibrechte für den Webserver-Benutzer setzen.',
    'SETUP-INI-WRITE-FAILED': 'Die hugocms.ini konnte nicht angelegt werden.',
    'SETUP-AUTOLOGIN-FAILED': 'Die automatische Anmeldung nach der Einrichtung ist fehlgeschlagen.',
    'SETUP-FIELD-REQUIRED': 'Feld „{0}“ ist erforderlich.',
    'SETUP-FIELD-INVALID-CHARS': 'Feld „{0}“ enthält unzulässige Zeichen (Anführungszeichen oder Zeilenumbruch).',
    'SETUP-PASSWORD-TOO-SHORT': 'Das Passwort muss mindestens {0} Zeichen lang sein.',
    'SETUP-LOG-LEVEL-INVALID': 'Ungültige Log-Stufe. Erlaubt: {0}.',
  },

  warnings: {
    'SESSION-DIR-MISSING': 'Sitzungsverzeichnis fehlt: {0} — Anmeldungen sind möglicherweise nicht von Dauer.',
    'LOG-DIR-MISSING': 'Log-Verzeichnis fehlt: {0} — Meldungen gehen ins Server-Log.',
    'MOUNT-CONFIG-MISSING': 'Keine eigene Mount-Konfiguration für „{0}“.',
  },
}
