---
title: "Contact form"
type: "contact"
weight: "1"
description: "Contact form example"
metaDescription: "Enter a meta description✓"
category: "Home"
---

To change language of labels and messages edit 'static/js/contact_std_form.js' and 'static/mail-api/messages.json'.  
To configure the mail API, edit the file 'static/mail-api/mailapi.json'

<div id="contact-form"></div>
<script type="module">
    import { sendEmail, sendWhatsapp, validateContactForm } from "/js/contact.js";
</script>
<script type="text/javascript">
    const whatsappTel = "%2b49" + "1757880999";
</script>
