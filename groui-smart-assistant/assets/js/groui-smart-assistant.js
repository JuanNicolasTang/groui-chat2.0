(function ($) {
  const root = document.getElementById('groui-smart-assistant-root');
  if (!root) {
    return;
  }

  const widgetId = 'gsa-dialog';
  const state = {
    open: false,
    loading: false,
    hasWooCommerce: Boolean(GROUISmartAssistant.hasWooCommerce),
    messages: [],
    badge: 0,
    greeted: false,
  };

  const SEND_BUTTON_TEMPLATE =
    '<span>Enviar</span><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="m3.5 10 13-6-3 6-10 0 10 0 3 6-13-6Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>';
  const LOADING_BUTTON_TEMPLATE =
    '<span>Pensando…</span><svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M10 3a7 7 0 1 0 6.2 10.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" /></svg>';
  const PRODUCT_PLACEHOLDER_IMAGE =
    "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 160 160'%3E%3Crect width='160' height='160' rx='24' fill='%236c5ce7'/%3E%3Cpath d='M112 56H48a4 4 0 0 0-4 4v60a4 4 0 0 0 4 4h64a4 4 0 0 0 4-4V60a4 4 0 0 0-4-4Zm-4 56H52V64h56v48Z' fill='white' opacity='0.85'/%3E%3Cpath d='M92 48h-24l-4-12h32l-4 12Z' fill='white' opacity='0.85'/%3E%3Ccircle cx='72' cy='92' r='10' fill='%23c8cbe0'/%3E%3Ccircle cx='96' cy='84' r='6' fill='%23c8cbe0'/%3E%3C/svg%3E";

  let typingNode = null;

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
      <section class="gsa-window" id="${widgetId}" role="dialog" aria-modal="false" aria-hidden="true" aria-label="Asistente virtual">
        <header class="gsa-header">
          <div>
            <p class="gsa-title">GROUI Smart Assistant</p>
            <p class="gsa-subtitle">Tu copiloto para explorar la tienda</p>
          </div>
          <div class="gsa-actions">
            <button type="button" class="gsa-btn" data-refresh title="Actualizar recomendaciones">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="M4.5 4.5a6.5 6.5 0 0 1 11 3.5h1.5a.5.5 0 0 1 .4.8l-2.3 3a.5.5 0 0 1-.8 0l-2.3-3a.5.5 0 0 1 .4-.8H14a5 5 0 0 0-9-2.7.75.75 0 0 1-1.24-.83l.74-1.17Zm11 11a6.5 6.5 0 0 1-11-3.5H3a.5.5 0 0 1-.4-.8l2.3-3a.5.5 0 0 1 .8 0l2.3 3a.5.5 0 0 1-.4.8H6a5 5 0 0 0 9 2.7.75.75 0 0 1 1.24.83l-.74 1.17Z" fill="currentColor"/>
              </svg>
              <span class="gsa-visually-hidden">Actualizar recomendaciones</span>
            </button>
            <button type="button" class="gsa-btn" data-close title="Cerrar asistente">
              <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                <path d="m6 6 8 8M6 14 14 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
              </svg>
              <span class="gsa-visually-hidden">Cerrar asistente</span>
            </button>
          </div>
        </header>
        <div class="gsa-messages" data-scroll>
          <div class="gsa-messages__list" data-messages></div>
          <section class="gsa-products gsa-hidden" data-products-section>
            <div class="gsa-products__header">
              <h4>Recomendaciones destacadas</h4>
              <button type="button" class="gsa-btn gsa-btn--ghost" data-refresh-secondary>
                <svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                  <path d="M4.5 4.5a6.5 6.5 0 0 1 11 3.5h1.5a.5.5 0 0 1 .4.8l-2.3 3a.5.5 0 0 1-.8 0l-2.3-3a.5.5 0 0 1 .4-.8H14a5 5 0 0 0-9-2.7.75.75 0 0 1-1.24-.83l.74-1.17Zm11 11a6.5 6.5 0 0 1-11-3.5H3a.5.5 0 0 1-.4-.8l2.3-3a.5.5 0 0 1 .8 0l2.3 3a.5.5 0 0 1-.4.8H6a5 5 0 0 0 9 2.7.75.75 0 0 1 1.24.83l-.74 1.17Z" fill="currentColor"/>
                </svg>
                <span>Actualizar</span>
              </button>
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

  function scrollMessages() {
    const container = root.querySelector('[data-scroll]');
    if (container) {
      container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
    }
  }

  function pushUserMessage(content) {
    state.messages.push({ role: 'user', content });
    renderMessage({ role: 'user', content });
  }

  function pushAssistantMessage(content) {
    hideTypingIndicator();
    state.messages.push({ role: 'assistant', content });
    renderMessage({ role: 'assistant', content });
    if (!state.open) {
      state.badge += 1;
      updateBadge(state.badge);
    }
  }

  function getMessagesContainer() {
    return root.querySelector('[data-messages]') || root.querySelector('[data-scroll]');
  }

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

  function contentToHtml(content) {
    if (!content) {
      return '';
    }
    return content;
  }

  function escapeAttribute(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

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

  function hideTypingIndicator() {
    if (!typingNode) {
      return;
    }
    typingNode.remove();
    typingNode = null;
  }

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

  function requestProducts(query) {
    if (!state.hasWooCommerce) {
      return;
    }

    $.post(
      GROUISmartAssistant.ajaxUrl,
      {
        action: 'groui_smart_assistant_products',
        nonce: GROUISmartAssistant.nonce,
        query: query || '',
      },
      (response) => {
        if (response && response.success && response.data) {
          renderProducts(response.data.products || []);
        }
      }
    );
  }

  function submitMessage(content) {
    setLoading(true);
    $.post(
      GROUISmartAssistant.ajaxUrl,
      {
        action: 'groui_smart_assistant_chat',
        nonce: GROUISmartAssistant.nonce,
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

  function bindEvents() {
    root.addEventListener('click', (event) => {
      const launcher = root.querySelector('[data-launcher]');
      const closeBtn = root.querySelector('[data-close]');
      const refreshButtons = root.querySelectorAll('[data-refresh], [data-refresh-secondary]');

      if (launcher && (event.target === launcher || launcher.contains(event.target))) {
        togglePanel();
        return;
      }

      if (closeBtn && (event.target === closeBtn || closeBtn.contains(event.target))) {
        togglePanel(false);
        return;
      }

      refreshButtons.forEach((button) => {
        if (event.target === button || button.contains(event.target)) {
          requestProducts('');
        }
      });
    });

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

  createTemplate();
  bindEvents();
  togglePanel(false);

  // Product recommendations are now opt-in via the refresh button or explicit assistant responses.
})(jQuery);
