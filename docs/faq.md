---
title: FAQ
nav_order: 9
description: "Short answers about darvis/snelstart: authentication, the token lifetime, failed calls, where the keys go, use without Laravel and testing."
faq: true
---

# Frequently asked questions

{% for item in site.data.faq %}
## {{ item.q }}

{{ item.a | markdownify }}
{% endfor %}
