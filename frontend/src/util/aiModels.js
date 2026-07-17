// Auswählbare Claude-Modelle für den KI-Assistenten — der RÜCKFALL, wenn die
// hugocms.ini keine eigene Liste vorgibt ([ai] models). Der Aktualisieren-Knopf
// im Konfigurationsdialog holt die aktuelle Liste von der API und schreibt sie
// dorthin; danach gilt sie statt dieser. Bei der Ersteinrichtung gibt es weder
// INI noch API-Schlüssel — dort ist diese Liste die einzige Quelle.
//
// In der hugocms.ini lässt sich zusätzlich jede andere Modell-ID von Hand
// eintragen (das Panel zeigt sie dann als weitere Option).
export const AI_MODELS = [
  'claude-opus-4-8', // leistungsfähigstes Modell, Standard des Assistenten
  'claude-opus-4-7', // Vorgängergeneration, weiterhin verfügbar
  'claude-sonnet-5', // ausgewogen: nahe an Opus, günstiger
  'claude-haiku-4-5', // schnell und günstig für einfache Aufgaben
]
