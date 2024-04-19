export const contactFormModel = [
		{
			"id": "name",
			"label": "Name",
			"required": true,
			"name": "nachname",
			"placeholder": "",
			"type": "text"
		},
		{
			"id": "email",
			"label": "Ihre E-Mail-Adresse",
			"required": false,
			"name": "vorname",
			"placeholder": "",
			"type": "email"
		},
		{
			"id": "telefon",
			"label": "Telefon",
			"required": false,
			"name": "telefon",
			"placeholder": "",
			"type": "text"
		},
		{
			"id": "anliegen",
			"label": "Anliegen",
			"required": true,
			"name": "anliegen",
			"placeholder": "",
			"type": "textarea"
		},
		{
			"id": "datenschutz",
			"label": "Datenschutzbestimmungen gelesen",
			"url": "https://autoprofis24.de/datenschutz/",
			"required": true,
			"name": "datenschutz",
			"placeholder": "",
			"type": "checkbox"
		}
	];

export const contactTexts = {
    "send-email-button": "E-Mail absenden",
    "send-whatsapp-button": "Whatsapp absenden",
    "send-email-title": "E-Mail absenden",
    "close-button": "Schließen",
    "frontpage-button": "Zur Startseite zurück",
    "error-form-data": "Bitte füllen Sie alle Formularfelder mit einem Stern (*) aus. Das Feld ",
    "missing-field": " fehlt.",
    "error-email-address": "Bitte prüfen Sie die E-Mail-Adresse noch einmal.",
    "contact": "Kontaktaufnahme über das Kontaktformular",
    "error-send-email": "Die Nachricht konnte nicht versendet werden",
    "yes": "ja",
    "no": "nein"
};

