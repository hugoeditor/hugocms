// Schmale Brücke zwischen Editor und Assistent, ohne die beiden Komponenten
// zu koppeln: Der EditorPanel registriert seine Speichern-Funktion, andere
// (der KI-Assistent) können vor einer Aktion den offenen Entwurf sichern.
//
// Modul-lokale Variable statt Store-State — die Funktion ist nicht reaktiv und
// soll nicht in Pinia-Devtools/Serialisierung auftauchen.

let saver = null

/** Registriert die Speichern-Funktion des Editors (oder null beim Abmelden). */
export function setEditorSaver(fn) {
  saver = fn
}

/**
 * Sichert ungespeicherte Editor-Änderungen, falls ein Editor aktiv ist.
 * Gibt true zurück, wenn nichts zu tun war oder das Speichern gelang,
 * false bei einem Speicherfehler (dann sollte der Aufrufer abbrechen).
 */
export async function flushEditor() {
  if (!saver) return true
  return saver()
}
