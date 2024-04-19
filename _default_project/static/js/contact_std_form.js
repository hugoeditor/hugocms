export const contactFormModel = [
		{
			"id": "name",
			"label": "Name",
			"required": true,
			"name": "name",
			"placeholder": "",
			"type": "text"
		},
		{
			"id": "email",
			"label": "Your email address",
			"required": false,
			"name": "email",
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
			"id": "issue",
			"label": "Issue",
			"required": true,
			"name": "issue",
			"placeholder": "",
			"type": "textarea"
		},
		{
			"id": "data-protection",
			"label": "Read privacy policy",
			"url": "/data-protection/",
			"required": true,
			"name": "data-protection",
			"placeholder": "",
			"type": "checkbox"
		}
	];

export const contactTexts = {
    "send-email-button": "Send an email",
    "send-whatsapp-button": "Send Whatsapp",
    "send-email-title": "Send an email",
    "close-button": "Close",
    "frontpage-button": "Go to start page",
    "error-form-data": "Please fill out all form fields with an asterisk (*). The field ",
    "missing-field": " is missing.",
    "error-email-address": "Please check the email address again.",
    "contact-message": "Contact via the contact form",
    "error-send-email": "The message could not be sent",
    "yes": "yes",
    "no": "no"
};

