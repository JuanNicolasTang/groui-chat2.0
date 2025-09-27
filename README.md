# GROUI Chat 2.0

Este repositorio contiene el plugin de WordPress **GROUI Smart Assistant**, un asistente flotante que se integra con OpenAI y WooCommerce para ofrecer respuestas inteligentes y recomendaciones de productos directamente en el front‑end de tu tienda o sitio web.

## Características principales

* **Asistente inteligente** – Un chatbot flotante, moderno y oscuro que utiliza la API de OpenAI (configurable, por defecto `gpt-5.1`) para entender las preguntas de los usuarios y responder con contexto del sitio.
* **Integración con WooCommerce** – Recomienda productos relevantes y los muestra en un carrusel interactivo si WooCommerce está instalado y activo.
* **Indexación de contenido** – Construye un contexto basado en páginas, categorías, FAQs, productos y el sitemap público del sitio para que el modelo disponga de la mayor cantidad de información posible.
* **Panel de ajustes** – Permite configurar la clave de API de OpenAI, el modelo a utilizar y los límites de indexación (páginas y productos) desde el área de administración de WordPress.
* **Soporte para depuración** – Opción para habilitar un modo de depuración que registra información adicional en los logs.

## Instalación

1. Descarga este repositorio y copia la carpeta `groui-smart-assistant` dentro de `wp‑content/plugins/` de tu instalación de WordPress.
2. Accede al panel de administración de WordPress y activa el plugin **GROUI Smart Assistant**.
3. En el menú de administración aparecerá una entrada llamada “GROUI Assistant”. Introduce tu clave de API de OpenAI y ajusta los demás parámetros según tus necesidades.

## Uso

Una vez activado y configurado el plugin, se mostrará un botón flotante en la esquina inferior derecha del sitio. Al hacer clic sobre él se abrirá un panel de chat donde los visitantes podrán:

* Realizar preguntas sobre tus productos o contenidos.
* Obtener respuestas contextuales basadas en la información del sitio (páginas, posts, FAQs, sitemap).
* Recibir recomendaciones de productos de WooCommerce en un carrusel cuando corresponda.

## Estructura del proyecto

```
groui-chat2.0/
├── groui-smart-assistant/      Código principal del plugin
│   ├── assets/                 Archivos CSS y JavaScript del asistente
│   ├── includes/               Clases PHP con la lógica del plugin
│   └── README.md               Documentación específica del plugin
├── tests/                      Pruebas unitarias de PHPUnit
├── CODE_OF_CONDUCT.md          Código de conducta para colaboradores
├── CONTRIBUTING.md             Guía para contribuir al proyecto
├── LICENSE                     Licencia MIT
└── phpunit.xml                 Configuración de PHPUnit
```

Para una descripción más detallada del funcionamiento interno del asistente, consulta el archivo `groui-smart-assistant/README.md`.
