import { contactFormModel, contactTexts } from "./contact_std_form.js";

const contactForm = document.getElementById("contact-form");
for(const item of contactFormModel)
{
	const container = document.createElement("div");
	container.className = "row py-4";
	container.id = item.id + "-container";
	let html = '<div class="col-md-3"><label for="' + item.id + '" style="word-break: break-word">'
	if(item.hasOwnProperty('url')) html += '<a href="' + item.url + '" target="_blank">';
	html += item.label;
	if(item.hasOwnProperty('url')) html += '</a>';
	if(item.required) html += '*';
	html += ':</label></div>';
	html += '<div class="col-md-3">';
	if(item.type == 'textarea') html += '<textarea class="contact-input" id="' + item.id  + '" name="' + item.name  + '" aria-label="' + item.label + '" rows="5" cols="40" placeholder="' + item.placeholder + '"';
	else html += '<input class="contact-input" type="' + item.type + '" id="' + item.id  + '" name="' + item.name  + '" aria-label="' + item.label + '" placeholder="' + item.placeholder + '"';
	if(item.required) html += ' required';
	if(item.type == 'textarea') html += '></textarea></div>';
	else html += ' /></div>';
	container.innerHTML = html;
	contactForm.appendChild(container);
}

function getText(id)
{
    return (undefined == contactTexts[id])? id : contactTexts[id];
}

const container = document.createElement("div");
container.className = "py-2";
let html = '<div id="contact-response"></div>';
html += '<button class="btn btn-secondary email me-2 my-2" id="sendmail-button">' + getText('send-email-button') + '</button>';
html += '<button class="btn btn-secondary my-2" id="whatsapp-button">' + getText('send-whatsapp-button') + '</button>';

html += '<div class="modal fade" id="email-modal" tabindex="-1" aria-labelledby="exampleModalLabel" aria-hidden="true">';
html += '  <div class="modal-dialog">';
html += '    <div class="modal-content">';
html += '      <div class="modal-header">';
html += '        <h1 class="modal-title fs-5" id="exampleModalLabel">' + getText('send-email-title') + '</h1>';
html += '        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>';
html += '      </div>';
html += '      <div class="modal-body">';
html += '        <div id="email-response"></div>';
html += '      </div>';
html += '      <div class="modal-footer">';
html += '        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' + getText('close-button') + '</button>';
html += '        <a href="/" class="btn btn-primary">' + getText('frontpage-button') + '</a>';
html += '      </div>';
html += '    </div>';
html += '  </div>';
html += '</div>';

container.innerHTML = html;
contactForm.appendChild(container);

export function validateContactForm()
{
	const response = document.getElementById('contact-response');
	let elements = document.getElementsByTagName('input');
	response.className = 'py-4 text-danger';
	response.innerHTML = '';
	let element;
	for(element of elements)
	{
		if(element.hasAttribute('required'))
		{
			if(element.value.length == 0 || element.type == 'checkbox' && !element.checked)
			{
				response.innerHTML = getText('error-form-data') + element.getAttribute('aria-label') + getText('missing-field');
				return false;
			}
		}
	}
	elements = document.getElementsByTagName('textarea');
	for(element of elements)
	{
		if(element.hasAttribute('required'))
		{
			if(element.value.length == 0)
			{
				response.innerHTML = getText('error-form-data') + element.getAttribute('aria-label') + getText('missing-field');
				return false;
			}
		}
	}
	const email = document.getElementById('email');
	if(email != undefined && email.validity.typeMismatch)
	{
		response.innerHTML = getText('error-email-address');
		return false;
	}
	return true;
}

export function formatMessage(separator)
{
	let message = getText('contact-message') + separator;
	let elements = document.getElementsByClassName('contact-input');
	let element;
	for(element of elements)
	{
		message += element.getAttribute('aria-label') + ': ';
		if(element.type == 'checkbox') message += (element.checked)? getText('yes') : getText('no');
		else message += element.value;
		message += separator;
	}
	return message;
}

export function sendWhatsapp()
{
	if(!validateContactForm()) return;
	const message = formatMessage("%0D%0A");
	window.open("https://api.whatsapp.com/send?phone=" + whatsappTel + "&text=" + message, '_blank');
}

export function sendEmail()
{
	if(!validateContactForm()) return;
	const term = formatMessage('\n');
	const email = document.getElementById('email').value;
	var httpRequest = new XMLHttpRequest();
	httpRequest.onreadystatechange = function()
	{
		if (httpRequest.readyState === 4)
		{
			const modal = new bootstrap.Modal('#email-modal');
			const response = document.getElementById('email-response');
			modal.show();
			if (httpRequest.status === 200)
			{
				if(null != httpRequest.responseText)
				{
					if(!httpRequest.responseText.includes('Fehler')) response.className = 'py-4 text-success';
					response.innerHTML = httpRequest.responseText;
				}
				else
				{
					response.innerHTML = getText('error-send-email');
					console.log("error empty response");
				}
  			}
			else
			{
				response.innerHTML = getText('error-send-email');
				console.log("error loading response");
			}
		}
	};
	let formData = new FormData();
	formData.append('message', term);
	if(email.length > 0) formData.append('email', email);
	httpRequest.open('POST', "/mail-api/");
	httpRequest.send(formData); 
}

document.querySelector('#sendmail-button').addEventListener('click', sendEmail);
document.querySelector('#whatsapp-button').addEventListener('click', sendWhatsapp);

