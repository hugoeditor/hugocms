// version.js — Versionsnummer der App.
//
// Das Schema der Versionsnummer ist die fortlaufende Buildnummer (ab 100). Sie
// wird von Vite zur Bauzeit als globale Konstante __APP_BUILD__ eingesetzt
// (siehe vite.config.js und build-version.js).
export const buildNumber = __APP_BUILD__
