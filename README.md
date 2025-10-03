# GROUI Smart Assistant

Plugin de WordPress que crea un asistente flotante con una IA conectada a OpenAI GPT-5, WooCommerce y el sitemap del sitio para recomendar productos, responder preguntas frecuentes y guiar en el proceso de compra.

## Características

- Botón flotante con interfaz oscura y moderna.
- Chat en vivo impulsado por OpenAI (modelo configurable, por defecto `gpt-5`).
- Indexa a toda la pagina web sin esepcion, pginas, productos, descripciones, precios, categorias, marcas etc.
- Recomienda productos de WooCommerce y muestra las sugerencias en un carrusel.
- Panel de ajustes en el administrador para definir API Key, modelo y indexado.
## Requisitos
- WordPress 6.0 o superior.
- WooCommerce.
- Clave de API de OpenAI con acceso al modelo GPT-5.

## Instalación

1. Copia la carpeta `groui-smart-assistant` en `wp-content/plugins/`.
2. Activa el plugin en el panel de WordPress.
3. Ve a **GROUI Assistant** en el menú de administración y configura tu API Key y ajustes preferidos.

## Uso

Tras activar el plugin, aparecerá un botón flotante en la esquina inferior derecha del sitio. Haz clic para conversar con la IA, resolver dudas y recibir recomendaciones de productos basadas en WooCommerce.

### Selección del modelo GPT-5

- El campo **Modelo de OpenAI** acepta los modelos de la familia GPT-5 publicados por OpenAI: `gpt-5`, `gpt-5-mini` y `gpt-5-nano`.
- El nombre del modelo es sensible a mayúsculas/minúsculas y a espacios. El plugin normaliza entradas comunes como `GPT 5 mini` o `gPt-5` para enviarlas correctamente.
- Si aparece el error “The model `GPT-5` does not exist or you do not have access to it”, revisa que el nombre coincida exactamente con uno de los anteriores y que tu cuenta tenga acceso activo al plan correspondiente.
- Puedes extender la lista de modelos permitidos usando el filtro `groui_smart_assistant_allowed_models` si OpenAI publica nuevas variantes compatibles.
