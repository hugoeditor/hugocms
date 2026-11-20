# Drittkomponenten und Lizenzen

Stand: 20.11.2026. HugoCMS selbst steht unter der GNU Affero General Public
License v3 oder später, die Pro-Bestandteile unter der HugoCMS Pro-Lizenz
(siehe `LICENSE` und `LICENSE-PRO`). Diese Liste nennt alle Softwarekomponenten
Dritter, die HugoCMS einbindet, mitliefert oder beim Betrieb voraussetzt.

Pflege: Bei jeder Änderung an `frontend/package.json`, an den im Backend
genutzten PHP-Erweiterungen oder an `bin/get-hugo.sh` ist diese Datei
nachzuziehen. Die vollständigen Lizenztexte der mitgelieferten npm-Pakete
erzeugt `npm run licenses` (Skript `scripts/generate-third-party-licenses.mjs`)
in die Datei `THIRD_PARTY_LICENSES.md` im Wurzelverzeichnis; sie wird mit
eingecheckt und von `scripts/packaging.sh` ins Auslieferungs-Repo kopiert, weil
Vite beim Minifizieren die Lizenzkommentare aus dem Bündel entfernt.

Prüfung der npm-Lizenzen (alle Pakete inkl. transitiver):

```bash
node -e 'const fs=require("fs");const c={};for(const f of require("child_process").execSync("find frontend/node_modules -maxdepth 4 -name package.json").toString().trim().split("\n")){try{const p=JSON.parse(fs.readFileSync(f));if(!p.name||!p.version)continue;let l=p.license||"?";if(typeof l==="object")l=l.type;c[l]=(c[l]||0)+1}catch(e){}}console.log(c)'
```

## Lizenzverträglichkeit

Alle unten genannten Lizenzen sind mit der AGPL-3.0 verträglich und stehen
einer kommerziellen Lizenzierung nicht entgegen (MIT, ISC, BSD, Apache-2.0,
0BSD). Keine Komponente zwingt HugoCMS zur AGPL; die Wahl ist eine
Projektentscheidung. Das PHP-Backend hat bewusst keine externen Abhängigkeiten
(kein Composer); Hugo wird nicht mitgeliefert.

## Frontend (npm, direkte Abhängigkeiten aus `frontend/package.json`)

| Paket | Version | Lizenz | Einsatz |
|---|---|---|---|
| @codemirror/lang-css | 6.3.1 | MIT | Laufzeit |
| @codemirror/lang-html | 6.4.11 | MIT | Laufzeit |
| @codemirror/lang-javascript | 6.2.5 | MIT | Laufzeit |
| @codemirror/lang-json | 6.0.2 | MIT | Laufzeit |
| @codemirror/lang-markdown | 6.5.0 | MIT | Laufzeit |
| @codemirror/lang-xml | 6.1.0 | MIT | Laufzeit |
| @codemirror/lang-yaml | 6.1.3 | MIT | Laufzeit |
| @codemirror/legacy-modes | 6.5.3 | MIT | Laufzeit |
| @codemirror/lint | 6.9.7 | MIT | Laufzeit |
| @mdi/font | 7.4.47 | Apache-2.0 | Laufzeit |
| @tiptap/extension-link | 3.32.0 | MIT | Laufzeit |
| @tiptap/pm | 3.32.0 | MIT | Laufzeit |
| @tiptap/starter-kit | 3.32.0 | MIT | Laufzeit |
| @tiptap/vue-3 | 3.32.0 | MIT | Laufzeit |
| codemirror | 6.0.2 | MIT | Laufzeit |
| filerobot-image-editor | 4.8.1 | MIT | Laufzeit |
| marked | 18.0.5 | MIT | Laufzeit |
| pinia | 2.3.1 | MIT | Laufzeit |
| tiptap-markdown | 0.9.0 | MIT | Laufzeit |
| vue | 3.5.43 | MIT | Laufzeit |
| vue-i18n | 11.4.16 | MIT | Laufzeit |
| vuetify | 3.12.8 | MIT | Laufzeit |
| yaml | 2.9.0 | ISC | Laufzeit |
| @vitejs/plugin-vue | 5.2.4 | MIT | Build |
| sass-embedded | 1.100.0 | MIT | Build |
| vite | 6.4.6 | MIT | Build |
| vite-plugin-vue-devtools | 8.2.1 | MIT | Build |
| vite-plugin-vuetify | 2.1.3 | MIT | Build |

Zweck der wichtigsten Pakete: Vue, Vuetify, Pinia und vue-i18n bilden die
Oberfläche; CodeMirror den Quelltext-Editor; TipTap mit tiptap-markdown den
visuellen Markdown-Editor; filerobot-image-editor die Bildbearbeitung (bringt
React 18 mit, siehe `overrides` in `frontend/package.json`); marked rendert die
Hilfe-Texte; yaml liest und schreibt Front Matter; @mdi/font liefert die
Symbole (Material Design Icons, Apache-2.0).

Transitive Produktionspakete (ca. 220): MIT, ISC, BSD-2/3-Clause, Apache-2.0,
0BSD. Abweichend nur caniuse-lite (CC-BY-4.0, Datentabelle für
Browser-Unterstützung, Build-Werkzeug) und @bufbuild/protobuf (Apache-2.0 AND
BSD-3-Clause, Teil von sass-embedded, Build-Werkzeug). Build-Werkzeuge landen
nicht im ausgelieferten Bündel.

## Entwicklungswerkzeuge im Wurzelverzeichnis (`package.json`)

| Paket | Lizenz | Zweck |
|---|---|---|
| jsdom | MIT | Prüfskripte in der Entwicklung; nicht ausgeliefert |

## PHP-Backend

Keine Composer-Pakete, keine eingebetteten Bibliotheken. Genutzt werden
ausschließlich PHP-Erweiterungen (PHP-Lizenz 3.01 bzw. die Lizenzen der
jeweils gebundenen Systembibliotheken):

| Erweiterung | Zweck |
|---|---|
| mbstring | Zeichenkettenverarbeitung (UTF-8) |
| json | API-Antworten, Berichte |
| curl | Claude-API, PageSpeed, seo-success, OpensourceERP |
| sodium | Ed25519-Prüfung der Pro-Schlüssel |
| gd | Vorschaubilder, Bildumwandlung (WebP/AVIF) |
| dom / libxml | HTML-Analyse im SEO-Check und in der Hyperlink-Suche |
| fileinfo | MIME-Erkennung beim Hochladen |
| exif | Bildausrichtung |
| posix / proc_open (Kern) | Hugo- und Git-Aufrufe |

## Externe Programme und Dienste (nicht mitgeliefert)

| Komponente | Lizenz / Bedingungen | Einbindung |
|---|---|---|
| Hugo (gohugo.io) | Apache-2.0 | `bin/get-hugo.sh` lädt das Binary im Zielsystem nach `bin/hugo/`; wird nicht ausgeliefert |
| Git | GPL-2.0 | Systemprogramm, als getrennter Prozess aufgerufen (Pro: Versionierung) |
| Anthropic Claude API | Nutzungsbedingungen von Anthropic | HTTP-Dienst, eigener Schlüssel des Betreibers (`[ai] api_key`) |
| Google PageSpeed Insights API | Google-API-Nutzungsbedingungen | HTTP-Dienst, eigener Schlüssel (Pro) |
| seo-success | eigener Dienst von Inter-Data | HTTP-Dienst für Live-Analyse und Sprachausgabe (Pro) |
| OpensourceERP (OSERP) | AGPL-3.0-or-later | ruft die Shop-Anbindung von HugoCMS auf; kein Code von OSERP in HugoCMS |

## Schriften und Grafiken

Keine eingebetteten Webfonts; die Oberfläche nutzt die Systemschrift des
Browsers. Symbole stammen aus @mdi/font (siehe oben).
