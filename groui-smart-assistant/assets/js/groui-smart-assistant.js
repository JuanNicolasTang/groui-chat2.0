/*
 * Improved frontend script for GROUI Smart Assistant.
 *
 * This version of the JavaScript enhances the UX/UI of the floating chat widget
 * by introducing a product search bar in the recommendations section, adding
 * accessibility attributes to announce new messages, and improving general
 * layout responsiveness.  It preserves all core functionality of the
 * original script while making it more flexible and user friendly.
 */

(function ($) {
  // Ensure the root container exists even if it's not present yet when this script runs.
  function ensureRoot() {
    let el = document.getElementById('groui-smart-assistant-root');
    if (!el) {
      el = document.createElement('div');
      el.id = 'groui-smart-assistant-root';
      el.className = 'groui-smart-assistant-root';
      // Live region to announce updates for assistive technologies.
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    return el;
  }

  // Read localized settings from the global object if available.  Provide
  // fallbacks to WordPress globals or sensible defaults so the script
  // continues to work even when GROUISmartAssistant is undefined.
  const GSA = window.GROUISmartAssistant || {};
  const ajaxUrl = GSA.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
  const nonce   = GSA.nonce || '';
  const hasWooFlag = !!GSA.hasWooCommerce;

  // Always work with a root element.  Create it if necessary.
  const root = ensureRoot();

  // Identifier for the dialog element.
  const widgetId = 'gsa-dialog';

  // Internal state used by the widget.  These flags control loading
  // indicators, whether WooCommerce is present and if a greeting has been shown.
  const state = {
    open: false,
    loading: false,
    // Determine WooCommerce availability using the flag computed above.
    hasWooCommerce: hasWooFlag,
    messages: [],
    badge: 0,
    greeted: false,
    hasUserMessage: false,
  };

  // Templates for the send and loading buttons.  These are used to swap
  // the contents of the submit button as the assistant thinks.
  const SEND_BUTTON_TEMPLATE =
    '<span>Enviar</span><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="m3.5 10 13-6-3 6-10 0 10 0 3 6-13-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
  const LOADING_BUTTON_TEMPLATE =
    '<span>Pensando…</span><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 3a7 7 0 1 0 6.2 10.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>';
  const PRODUCT_PLACEHOLDER_IMAGE =
    "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 160 160'%3E%3Crect width='160' height='160' rx='24' fill='%236c5ce7'/%3E%3Cpath d='M112 56H48a4 4 0 0 0-4 4v60a4 4 0 0 0 4 4h64a4 4 0 0 0 4-4V60a4 4 0 0 0-4-4Zm-4 56H52V64h56v48Z' fill='white' opacity='0.85'/%3E%3Cpath d='M92 48h-24l-4-12h32l-4 12Z' fill='white' opacity='0.85'/%3E%3Ccircle cx='72' cy='92' r='10' fill='%23c8cbe0'/%3E%3Ccircle cx='96' cy='84' r='6' fill='%23c8cbe0'/%3E%3C/svg%3E";

  // Node representing the typing indicator.  This is created and destroyed
  // dynamically as the assistant responds.
  let typingNode = null;

  /**
   * Build the DOM structure for the chat widget and insert it into the root.
   * The markup uses semantic elements and accessibility attributes where
   * appropriate.  A search bar has been added to the products header to
   * allow users to filter WooCommerce products.
   */
  function createTemplate() {
    root.innerHTML = `
      <button type="button" class="gsa-fab" aria-label="Abrir asistente" aria-controls="${widgetId}" data-launcher>
        <span class="gsa-fab__glow" aria-hidden="true"></span>
        <span class="gsa-fab__icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M5 11C5 6.582 8.582 3 13 3s8 3.582 8 8c0 4.418-3.582 8-8 8h-1l-3.2 2.4c-.8.6-1.8-.2-1.6-1.1L6.7 17.5C5.6 15.9 5 13.9 5 12v-1Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="9.75" cy="11" r="1" fill="currentColor" />
            <circle cx="13.75" cy="11" r="1" fill="currentColor" />
            <path d="M12 14.5c.8 0 1.5-.4 2-.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
          </svg>
        </span>
        <span class="gsa-fab__badge gsa-hidden" data-badge>1</span>
      </button>
      <section class="gsa-window" id="${widgetId}" role="dialog" aria-modal="false" aria-hidden="true" aria-label="Asistente virtual" aria-describedby="gsa-dialog-desc">
        <header class="gsa-header">
          <div>
            <p class="gsa-title">GROUI Smart Assistant</p>
            <p class="gsa-subtitle">Tu copiloto para explorar la tienda y resolver dudas en tiempo real</p>
          </div>
          <div class="gsa-actions">
            <!-- El botón de actualización de productos se elimina. Solo permanece el botón de cierre. -->
            <button type="button" class="gsa-btn" data-close title="Cerrar asistente">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="m6 6 8 8M6 14 14 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
              </svg>
              <span class="gsa-visually-hidden">Cerrar asistente</span>
            </button>
          </div>
        </header>
        <div class="gsa-messages" data-scroll>
          <div class="gsa-visually-hidden" id="gsa-dialog-desc">
            Asistente virtual con respuestas y recomendaciones de productos.
          </div>
          <section class="gsa-onboarding" data-onboarding>
            <p class="gsa-onboarding__title">¿En qué puedo ayudarte?</p>
            <p class="gsa-onboarding__text">Escribe tu pregunta o usa un atajo rápido para empezar.</p>
            <div class="gsa-onboarding__chips">
              <button type="button" class="gsa-chip-action" data-prompt="Busco un regalo, ¿qué me recomiendas?">🎁 Ideas de regalo</button>
              <button type="button" class="gsa-chip-action" data-prompt="¿Cuáles son los productos más populares?">🔥 Más populares</button>
              <button type="button" class="gsa-chip-action" data-prompt="Quiero algo por menos de $50">💸 Presupuesto</button>
            </div>
            <ul class="gsa-onboarding__list">
              <li>Resuelve dudas sobre envíos, pagos y devoluciones.</li>
              <li>Encuentra productos según tu presupuesto o categoría.</li>
              <li>Te guía paso a paso en tu compra.</li>
            </ul>
          </section>
          <div class="gsa-messages__list" data-messages aria-live="polite"></div>
          <section class="gsa-products gsa-hidden" data-products-section>
            <!-- Solo se muestra este contenedor cuando hay productos devueltos por la IA. -->
            <div class="gsa-products__header">
              <div>
                <h4>Recomendaciones</h4>
                <p class="gsa-products__hint">Filtra por marca o categoría si quieres afinar resultados.</p>
              </div>
              <div class="gsa-product-search">
                <input type="search" class="gsa-search-input" placeholder="Buscar productos…" aria-label="Buscar productos" data-product-search />
                <button type="button" class="gsa-btn gsa-btn--ghost" data-product-refresh>
                  <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M4 10a6 6 0 0 1 10-4.4M16 10a6 6 0 0 1-10 4.4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="m14 4 2 2-2 2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                    <path d="m6 16-2-2 2-2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                  </svg>
                  Actualizar
                </button>
              </div>
            </div>
            <div class="gsa-products__grid" data-products-grid></div>
          </section>
        </div>
        <form class="gsa-inputbar" data-form>
          <label for="gsa-message" class="gsa-visually-hidden">Escribe tu mensaje</label>
          <textarea id="gsa-message" class="gsa-input" name="message" rows="2" placeholder="Cuéntame qué necesitas…" required></textarea>
          <button type="submit" class="gsa-send">
            ${SEND_BUTTON_TEMPLATE}
          </button>
        </form>
      </section>
    `;
  }

  /**
   * Update the badge counter on the floating action button.  When the panel
   * is closed and new assistant messages arrive, a badge will appear to
   * notify the user.  This helper ensures the badge hides when count is zero.
   *
   * @param {number} count The number of unread messages.
   */
  function updateBadge(count) {
    const badge = root.querySelector('[data-badge]');
    if (!badge) {
      return;
    }
    if (count > 0) {
      badge.textContent = count > 9 ? '9+' : String(count);
      badge.classList.remove('gsa-hidden');
    } else {
      badge.classList.add('gsa-hidden');
    }
  }

  /**
   * Toggle the open/closed state of the chat window.  Updates ARIA attributes
   * accordingly and resets the badge counter when opened.  Also triggers
   * a greeting on first open.
   *
   * @param {boolean} [force] Optional boolean to explicitly set the open state.
   */
  function togglePanel(force) {
    state.open = typeof force === 'boolean' ? force : !state.open;
    const panel = root.querySelector('.gsa-window');
    const launcher = root.querySelector('[data-launcher]');
    if (!panel || !launcher) {
      return;
    }
    panel.classList.toggle('is-open', state.open);
    panel.setAttribute('aria-hidden', String(!state.open));
    launcher.setAttribute('aria-expanded', String(state.open));
    if (state.open) {
      state.badge = 0;
      updateBadge(state.badge);
      const textarea = root.querySelector('[data-form] textarea');
      if (textarea) {
        setTimeout(() => textarea.focus(), 100);
      }
      if (!state.greeted) {
        state.greeted = true;
        pushAssistantMessage(
          '<strong>¡Hola! 👋</strong><p class="muted">Estoy lista para resolver dudas, guiarte por la web y recomendar productos especiales para ti.</p>'
        );
      }
    }
  }

  /**
   * Scroll the messages container to the bottom.  Used after adding new
   * messages or product cards.
   */
  function scrollMessages() {
    const container = root.querySelector('[data-scroll]');
    if (container) {
      container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
    }
  }

  /**
   * Push a user message into the state and render it to the screen.
   *
   * @param {string} content The content of the message.
   */
  function pushUserMessage(content) {
    state.hasUserMessage = true;
    state.messages.push({ role: 'user', content });
    renderMessage({ role: 'user', content });
    updateOnboardingVisibility();
  }

  /**
   * Push an assistant message into the state and render it to the screen.
   * Resets the typing indicator and updates the badge when the panel is closed.
   *
   * @param {string} content The HTML content of the assistant message.
   */
  function pushAssistantMessage(content) {
    hideTypingIndicator();
    state.messages.push({ role: 'assistant', content });
    renderMessage({ role: 'assistant', content });
    if (!state.open) {
      state.badge += 1;
      updateBadge(state.badge);
    }
  }

  /**
   * Get the container element where messages should be appended.  Falls back
   * to the scroll container if the messages list is unavailable.
   *
   * @returns {HTMLElement|null}
   */
  function getMessagesContainer() {
    return root.querySelector('[data-messages]') || root.querySelector('[data-scroll]');
  }

  /**
   * Render a single message into the messages container.  User messages are
   * inserted as plain text while assistant messages are inserted as HTML to
   * preserve formatting returned by OpenAI.
   *
   * @param {object} message The message object containing role and content.
   */
  function renderMessage(message) {
    const container = getMessagesContainer();
    if (!container) {
      return;
    }
    const div = document.createElement('div');
    div.className = `gsa-msg ${message.role}`;
    if (message.role === 'assistant') {
      div.innerHTML = contentToHtml(message.content);
    } else {
      div.textContent = message.content;
    }
    container.appendChild(div);
    scrollMessages();
  }

  /**
   * Show or hide the onboarding panel based on whether the user has engaged.
   */
  function updateOnboardingVisibility() {
    const onboarding = root.querySelector('[data-onboarding]');
    if (!onboarding) {
      return;
    }
    onboarding.classList.toggle('gsa-hidden', state.hasUserMessage);
  }

  /**
   * Pass-through helper for assistant content.  Future processing could
   * sanitize or process Markdown if desired.
   *
   * @param {string} content HTML content returned by the assistant.
   * @returns {string}
   */
  function contentToHtml(content) {
    if (!content) {
      return '';
    }
    return content;
  }

  /**
   * Escape an attribute value for inclusion in HTML.
   *
   * @param {string} value The raw value.
   * @returns {string} The escaped value.
   */
  function escapeAttribute(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  /**
   * Escape HTML content to avoid injection.
   *
   * @param {string} value The raw HTML.
   * @returns {string} The escaped HTML.
   */
  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  /**
   * Display a typing indicator while waiting for a response.  Creates a
   * temporary message element with animated dots.
   */
  function showTypingIndicator() {
    if (typingNode) {
      return;
    }
    const container = getMessagesContainer();
    if (!container) {
      return;
    }
    typingNode = document.createElement('div');
    typingNode.className = 'gsa-msg assistant';
    typingNode.innerHTML = `
      <span class="gsa-typing" aria-label="El asistente está escribiendo">
        <span class="gsa-typing-dot"></span>
        <span class="gsa-typing-dot"></span>
        <span class="gsa-typing-dot"></span>
      </span>
    `;
    container.appendChild(typingNode);
    scrollMessages();
  }

  /**
   * Hide the typing indicator and reset the reference.
   */
  function hideTypingIndicator() {
    if (!typingNode) {
      return;
    }
    typingNode.remove();
    typingNode = null;
  }

  /**
   * Update loading state across the UI.  Disables the submit button and
   * textarea while waiting for a response and shows/hides the typing
   * indicator accordingly.
   *
   * @param {boolean} isLoading Whether the assistant is waiting for a response.
   */
  function setLoading(isLoading) {
    state.loading = isLoading;
    const button = root.querySelector('[data-form] button');
    const textarea = root.querySelector('[data-form] textarea');
    if (button) {
      button.disabled = isLoading;
      button.innerHTML = isLoading ? LOADING_BUTTON_TEMPLATE : SEND_BUTTON_TEMPLATE;
    }
    if (textarea) {
      if (isLoading) {
        textarea.setAttribute('disabled', 'disabled');
        showTypingIndicator();
      } else {
        textarea.removeAttribute('disabled');
        if (state.open) {
          textarea.focus();
        }
        hideTypingIndicator();
      }
    }
  }

  /**
   * Render products into the product grid.  If there are no products or
   * WooCommerce is not available, hides the product section.  Each product
   * is displayed as a card with image, name, price, status chip and link.
   *
   * @param {Array} products Array of product card data.
   */
  function renderProducts(products) {
    const section = root.querySelector('[data-products-section]');
    const grid = root.querySelector('[data-products-grid]');
    if (!section || !grid) {
      return;
    }
    if (!state.hasWooCommerce || !products || !products.length) {
      section.classList.add('gsa-hidden');
      grid.innerHTML = '';
      return;
    }
    section.classList.remove('gsa-hidden');
    grid.innerHTML = products
      .map((product) => {
        const statusChip = product.in_stock
          ? '<span class="gsa-chip gsa-chip--success">En stock</span>'
          : '<span class="gsa-chip gsa-chip--danger">Agotado</span>';
        const description = product.short_desc ? `<p>${escapeHtml(product.short_desc)}</p>` : '';
        const imageSrc = product.image || PRODUCT_PLACEHOLDER_IMAGE;
        const altText = escapeAttribute(product.name);
        const permalink = escapeAttribute(product.permalink);
        const productName = escapeHtml(product.name);
        const productPrice = escapeHtml(product.price || '');
        return `
          <article class="gsa-card">
            <img src="${imageSrc}" alt="${altText}" class="gsa-card__img" loading="lazy" />
            <div class="gsa-card__body">
              <div>
                <h5>${productName}</h5>
                <p class="gsa-price">${productPrice}</p>
              </div>
              ${statusChip}
              ${description}
              <a class="gsa-cta" href="${permalink}" target="_blank" rel="noopener">
                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <path d="M6 14 14 6m0 0H7m7 0v7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Ver detalles
              </a>
            </div>
          </article>
        `;
      })
      .join('');
    scrollMessages();
  }

  /**
   * Request product recommendations from the server.  If WooCommerce is not
   * present, this method does nothing.  It posts to the dedicated AJAX
   * endpoint with an optional search query and updates the product grid.
   *
   * @param {string} query A search term for filtering products.
   */
  function requestProducts(query) {
    if (!state.hasWooCommerce) {
      return;
    }
    $.post(
      ajaxUrl,
      {
        action: 'groui_smart_assistant_products',
        nonce: nonce,
        query: query || '',
      },
      (response) => {
        if (response && response.success && response.data) {
          renderProducts(response.data.products || []);
        }
      }
    );
  }

  /**
   * Submit a user message to the chat endpoint.  Sets loading state,
   * sends the AJAX request and processes the response or error.
   *
   * @param {string} content The message to send.
   */
  function submitMessage(content) {
    setLoading(true);
    $.post(
      ajaxUrl,
      {
        action: 'groui_smart_assistant_chat',
        nonce: nonce,
        message: content,
      }
    )
      .done((response) => {
        if (!response || !response.success) {
          const message = (response && response.data && response.data.message) || 'No se pudo obtener respuesta.';
          pushAssistantMessage(message);
          return;
        }
        if (response.data.answer) {
          pushAssistantMessage(response.data.answer);
        }
        if (
          state.hasWooCommerce &&
          Object.prototype.hasOwnProperty.call(response.data, 'productCards')
        ) {
          const cards = Array.isArray(response.data.productCards)
            ? response.data.productCards
            : [];
          renderProducts(cards);
        }
      })
      .fail((jqXHR) => {
        let message = 'Error de conexión con el asistente.';
        if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
          message = jqXHR.responseJSON.data.message;
        }
        pushAssistantMessage(message);
      })
      .always(() => {
        setLoading(false);
      });
  }

  /**
   * Debounce helper for input events.
   *
   * @param {Function} fn
   * @param {number} wait
   * @returns {Function}
   */
  function debounce(fn, wait) {
    let timeoutId;
    return (...args) => {
      window.clearTimeout(timeoutId);
      timeoutId = window.setTimeout(() => fn(...args), wait);
    };
  }

  /**
   * Bind event handlers for user interactions.  Handles opening and closing
   * the panel, refreshing products, searching within products, and
   * submitting the chat form.
   */
  function bindEvents() {
    root.addEventListener('click', (event) => {
      const launcher = root.querySelector('[data-launcher]');
      const closeBtn = root.querySelector('[data-close]');
      const promptBtn = event.target.closest('[data-prompt]');
      const refreshBtn = root.querySelector('[data-product-refresh]');
      if (launcher && (event.target === launcher || launcher.contains(event.target))) {
        togglePanel();
        return;
      }
      if (closeBtn && (event.target === closeBtn || closeBtn.contains(event.target))) {
        togglePanel(false);
        return;
      }
      if (promptBtn) {
        const prompt = promptBtn.getAttribute('data-prompt');
        if (prompt) {
          pushUserMessage(prompt);
          submitMessage(prompt);
        }
        return;
      }
      if (refreshBtn && (event.target === refreshBtn || refreshBtn.contains(event.target))) {
        const searchInput = root.querySelector('[data-product-search]');
        requestProducts(searchInput ? searchInput.value.trim() : '');
        return;
      }
    });
    const searchInput = root.querySelector('[data-product-search]');
    if (searchInput) {
      const handleSearch = debounce(() => {
        requestProducts(searchInput.value.trim());
      }, 450);
      searchInput.addEventListener('input', handleSearch);
    }
    const form = root.querySelector('[data-form]');
    if (!form) {
      return;
    }
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (state.loading) {
        return;
      }
      const textarea = form.querySelector('textarea');
      if (!textarea) {
        return;
      }
      const value = textarea.value.trim();
      if (!value) {
        return;
      }
      pushUserMessage(value);
      textarea.value = '';
      submitMessage(value);
    });
  }

  // Initialize the widget once the DOM is ready.
  createTemplate();
  bindEvents();
  togglePanel(false);
  updateOnboardingVisibility();
})(jQuery);
