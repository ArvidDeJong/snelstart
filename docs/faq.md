---
title: "FAQ"
nav_order: 9
description: "Short answers about darvis/snelstart: what it is, what you need, authentication and the token, failed calls, retries, key safety, plain PHP and testing."
faq: true
---

# Frequently asked questions

{% for item in site.data.faq %}
## {{ item.q }}

{{ item.a | markdownify }}
{% endfor %}
