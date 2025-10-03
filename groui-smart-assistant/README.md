# GROUI Smart Assistant

Plugin de WordPress que crea un asistente flotante con una IA conectada a OpenAI GPT-5, WooCommerce y el sitemap del sitio para recomendar productos, responder preguntas frecuentes y guiar en el proceso de compra.

## Características

- Botón flotante con interfaz oscura y moderna.
- Chat en vivo impulsado por OpenAI (modelo configurable, por defecto `gpt-5`).
- Indexa páginas, categorías, FAQs, productos y sitemap para construir un contexto propio.
- Recomienda productos de WooCommerce y muestra las sugerencias en un carrusel.
- Panel de ajustes en el administrador para definir API Key, modelo y límites de indexado.
- Actualización automática del contexto mediante cron jobs horarios.

## Requisitos

- WordPress 6.0 o superior.
- WooCommerce (opcional pero recomendado).
- Clave de API de OpenAI con acceso al modelo GPT-5.

## Instalación

1. Copia la carpeta `groui-smart-assistant` en `wp-content/plugins/`.
2. Activa el plugin en el panel de WordPress.
3. Ve a **GROUI Assistant** en el menú de administración y configura tu API Key y ajustes preferidos.

## Uso

Tras activar el plugin, aparecerá un botón flotante en la esquina inferior derecha del sitio. Haz clic para conversar con la IA, resolver dudas y recibir recomendaciones de productos basadas en WooCommerce.

## Filtros disponibles

- `groui_smart_assistant_openai_request_args`: Permite modificar los argumentos enviados a `wp_remote_post()` antes de contactar con OpenAI. Úsalo para añadir cabeceras personalizadas o ajustar el `timeout` (por defecto 60 s) cuando necesites respuestas más largas sin editar el código del plugin.
- `groui_smart_assistant_use_full_context`: Te deja forzar (o desactivar) el modo de contexto completo desde código antes de construir el prompt, por ejemplo para habilitarlo solo a ciertos usuarios o en determinados tipos de petición.
- `groui_smart_assistant_deep_context`: Última oportunidad para modificar el contexto cuando se envía completo al modelo.
- `groui_smart_assistant_refined_context`: Sigue disponible para ajustar el subconjunto refinado (se invoca también cuando se usa el modo de contexto completo).
- `groui_smart_assistant_context_product_limit`: Ajusta el número de productos que se indexan cuando el contexto se refina por relevancia (modo normal).
- `groui_smart_assistant_context_maximum_products`: Permite fijar un máximo cuando el modo de contexto completo recopila todo el catálogo (por defecto sin límite).
- `groui_smart_assistant_context_product_query_args`: Modifica los argumentos de `wc_get_products()` usados para extraer los productos en cualquiera de los modos.
 codex/add-filter-for-wp_remote_post-args-ru6ifg
- `groui_smart_assistant_context_page_query_args`: Permite ajustar la consulta de `get_posts()` que recorre las páginas (puedes subir el `posts_per_page` por lote o cambiar el orden si lo prefieres).
- `groui_smart_assistant_context_faq_query_args`: Modifica la consulta paginada a `get_posts()` durante la extracción de FAQs para aumentar, reducir o filtrar el contenido escaneado en cada pasada.
- `groui_smart_assistant_context_taxonomies`: Cambia el listado de taxonomías incluidas en el contexto (por defecto categorías de producto, etiquetas, marca y blog).
- `groui_smart_assistant_context_taxonomy_query_args`: Ajusta los argumentos de `get_terms()` por taxonomía; en modo de contexto completo se itera por lotes (50 términos por defecto) y puedes personalizar `number`, `offset` u otros parámetros.
- `groui_smart_assistant_context_time_limit`: Permite elevar (o reducir) el límite de ejecución en segundos cuando el modo de contexto completo requiere más tiempo para recorrer catálogos extensos. Si tu hosting bloquea `set_time_limit()`, devuelve `0` mediante este filtro para omitir el ajuste automáticamente.
- `groui_smart_assistant_context_maximum_pages`: Establece un tope al número de páginas cargadas en modo de contexto completo (por defecto ilimitado).
- `groui_smart_assistant_context_maximum_terms`: Fija un máximo de términos por taxonomía cuando se recorre el catálogo completo (por defecto ilimitado).

### Búsqueda profunda y modo de contexto completo

- En la página de ajustes encontrarás el checkbox **Modo de contexto completo**. Al activarlo, la IA recibirá todas las páginas, productos, FAQs, categorías y URLs recopiladas sin aplicar el recorte por relevancia; además, ahora el contexto se construye en lotes para evitar picos de memoria mientras recorre catálogos grandes, elevando automáticamente los límites de tiempo/memoria cuando sea necesario. El modo profundo cargará el catálogo completo de WooCommerce y consultará todas las páginas publicadas, entradas para FAQs y términos de las taxonomías incluidas (salvo que limites las cifras con los filtros anteriores o los nuevos topes por página/término) para que las respuestas puedan hacer referencia a todo tu contenido publicado.
- Si prefieres mantener el recorte pero con límites más altos, aumenta los campos **Máximo de páginas a indexar** y **Máximo de productos a indexar** desde los ajustes. Esos valores se usarán como límite por defecto al refinar el contexto.
 codex/add-filter-for-wp_remote_post-args-zyr1kv
- `groui_smart_assistant_context_page_query_args`: Permite ajustar la consulta de `get_pages()` y, por ejemplo, quitar cualquier tope cuando necesites indexar todas las páginas.
- `groui_smart_assistant_context_faq_query_args`: Modifica la consulta a `get_posts()` durante la extracción de FAQs para aumentar o reducir el número de entradas analizadas.
- `groui_smart_assistant_context_taxonomies`: Cambia el listado de taxonomías incluidas en el contexto (por defecto categorías de producto, etiquetas, marca y blog).
- `groui_smart_assistant_context_taxonomy_query_args`: Ajusta los argumentos de `get_terms()` por taxonomía; en modo de contexto completo el valor `number` se envía como `0` para traer todos los términos a menos que lo limites aquí.

### Búsqueda profunda y modo de contexto completo

- En la página de ajustes encontrarás el checkbox **Modo de contexto completo**. Al activarlo, la IA recibirá todas las páginas, productos, FAQs, categorías y URLs recopiladas sin aplicar el recorte por relevancia; además, ahora cargará el catálogo completo de WooCommerce y consulta todas las páginas publicadas, entradas para FAQs y términos de las taxonomías incluidas (salvo que limites las cifras con los filtros anteriores) para que las respuestas puedan hacer referencia a todo tu contenido publicado.
- Si prefieres mantener el recorte pero con límites más altos, aumenta los campos **Máximo de páginas a indexar** y **Máximo de productos a indexar** desde los ajustes. Esos valores se usarán como límite por defecto al refinar el contexto.


### Búsqueda profunda y modo de contexto completo

- En la página de ajustes encontrarás el checkbox **Modo de contexto completo**. Al activarlo, la IA recibirá todas las páginas, productos, FAQs, categorías y URLs recopiladas sin aplicar el recorte por relevancia; además, ahora cargará el catálogo completo de WooCommerce (salvo que limites la cifra con `groui_smart_assistant_context_maximum_products`) para que las respuestas puedan hacer referencia a todos los productos publicados.
- Si prefieres mantener el recorte pero con límites más altos, aumenta los campos **Máximo de páginas a indexar** y **Máximo de productos a indexar** desde los ajustes. Esos valores se usarán como límite por defecto al refinar el contexto.

codex/add-filter-for-wp_remote_post-args-jp0hue
- `groui_smart_assistant_use_full_context`: Te deja forzar (o desactivar) el modo de contexto completo desde código antes de construir el prompt, por ejemplo para habilitarlo solo a ciertos usuarios o en determinados tipos de petición.
- `groui_smart_assistant_deep_context`: Última oportunidad para modificar el contexto cuando se envía completo al modelo.
- `groui_smart_assistant_refined_context`: Sigue disponible para ajustar el subconjunto refinado (se invoca también cuando se usa el modo de contexto completo).

### Búsqueda profunda y modo de contexto completo

- En la página de ajustes encontrarás el checkbox **Modo de contexto completo**. Al activarlo, la IA recibirá todas las páginas, productos, FAQs, categorías y URLs recopiladas sin aplicar el recorte por relevancia, lo que ayuda a obtener respuestas más exhaustivas.
- Si prefieres mantener el recorte pero con límites más altos, aumenta los campos **Máximo de páginas a indexar** y **Máximo de productos a indexar** desde los ajustes. Esos valores se usarán como límite por defecto al refinar el contexto.


### Selección del modelo GPT-5

- El campo **Modelo de OpenAI** acepta los modelos de la familia GPT-5 publicados por OpenAI: `gpt-5`, `gpt-5-mini` y `gpt-5-nano`.
- El nombre del modelo es sensible a mayúsculas/minúsculas y a espacios. El plugin normaliza entradas comunes como `GPT 5 mini` o `gPt-5` para enviarlas correctamente.
- Si aparece el error “The model `GPT-5` does not exist or you do not have access to it”, revisa que el nombre coincida exactamente con uno de los anteriores y que tu cuenta tenga acceso activo al plan correspondiente.
- Puedes extender la lista de modelos permitidos usando el filtro `groui_smart_assistant_allowed_models` si OpenAI publica nuevas variantes compatibles.
