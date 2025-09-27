=== GPT5 Shop Assistant (Onefile) ===
Contributors: you
Tags: ai, assistant, chat, woocommerce
Requires at least: 6.0
Tested up to: 6.6
Stable tag: 1.5.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Asistente de compra con RAG, streaming, catálogo con filtros/chips, mini-carrito y recomendaciones. **Multi-proveedor** (OpenAI, Azure, OpenRouter, Local).

== Changelog ==
= 1.5.2 =
* Fix: header OpenRouter `Referer` (antes `HTTP-Referer`).
* Mejora: CORS robusto (OPTIONS preflight, Allow-Methods/Headers, normalize origin).

= 1.5.1 =
* Fix: se reintroduce soporte multi-proveedor (openai|azure|openrouter|local) en chat + streaming.
* Fix: CORS — se añade `Access-Control-Allow-Origin` dinámico al origin permitido.
* Fix: `add_to_cart` para variaciones ahora envía atributos completos de la variación.
* Fix: `wc-search` devuelve atributos de variación correctos (keys `attribute_*`). 
* Mejora: sanitización y headers SSE consistentes.
* Incluye `uninstall.php` para limpieza total.
