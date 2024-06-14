module.exports = {
  safelist: [
    // Liste der Klassen (oder Muster), die nicht entfernt werden sollen
    'navbar', 'navbar-light', 'navbar-expand-lg', 'nav-item', 'nav-link', 'dropdown', 'dropdown-menu', 'dropdown-item', 'dropdown-toggle',
    'btn', 'btn-primary', 'btn-secondary', 'fade', 'show', 'row', 'col-md-2', 'col-md-3', 'mt-2', 'tooltip', 'tooltip-inner', 'tooltip-arrow',
    'bs-tooltip-top', 'bs-tooltip-bottom', 'bs-tooltip-left', 'bs-tooltip-right', 'bs-tooltip-auto', 'fade', 'show',
    // Fügen Sie weitere Klassen hinzu, wie benötigt
    /^modal-/, // Beispiel für ein Muster, das alle Klassen einbezieht, die mit "modal-" beginnen
    // Weitere spezifische Klassen oder Muster...
  ],
  // Sie können auch andere Optionen hier einfügen, wie z.B. 'defaultExtractor' für benutzerdefinierte Extraktionslogik
};
