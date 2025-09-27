# GROUI Smart Assistant

Plugin de WordPress que crea un asistente flotante con una IA conectada a OpenAI GPT-5, WooCommerce y el sitemap del sitio para recomendar productos, responder preguntas frecuentes y guiar en el proceso de compra.

## Características

- Botón flotante con interfaz oscura y moderna.
- Chat en vivo impulsado por OpenAI (modelo configurable, por defecto `gpt-5.1`).
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
