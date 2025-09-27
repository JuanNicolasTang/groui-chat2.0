(function ($) {
  const root = document.getElementById('groui-smart-assistant-root');
  if (!root) {
    return;
  }

  const state = {
    open: false,
    loading: false,
    hasWooCommerce: Boolean(GROUISmartAssistant.hasWooCommerce),
    messages: [],
  };

  function createTemplate() {
    root.innerHTML = `
      <button class="groui-launcher" aria-label="Abrir asistente">
        <span>🤖</span>
      </button>
      <section class="groui-chat-panel" role="dialog" aria-hidden="true">
        <header class="groui-chat-header">
          <div>
            <h3>GROUI Smart Assistant</h3>
            <small>Conectado a GPT-5 · WooCommerce</small>
          </div>
          <button class="groui-close" aria-label="Cerrar">×</button>
        </header>
        <div class="groui-messages" data-scroll></div>
        <div class="groui-carousel" hidden>
          <h4>Recomendaciones destacadas</h4>
          <div class="groui-carousel-track" data-carousel></div>
        </div>
        <div class="groui-input-area">
          <form data-form>
            <textarea name="message" rows="2" placeholder="¿En qué podemos ayudarte?" required></textarea>
            <button type="submit">Enviar</button>
          </form>
        </div>
      </section>
    `;
  }

  function togglePanel(force) {
    state.open = typeof force === 'boolean' ? force : !state.open;
    const panel = root.querySelector('.groui-chat-panel');
    const launcher = root.querySelector('.groui-launcher');

    if (!panel || !launcher) {
      return;
    }

    panel.setAttribute('aria-hidden', String(!state.open));
    panel.style.display = state.open ? 'flex' : 'none';
    launcher.setAttribute('aria-expanded', String(state.open));

    if (state.open) {
      panel.querySelector('textarea').focus();
      if (!state.messages.length) {
        pushAssistantMessage('Hola, soy tu asistente. Puedo resolver dudas sobre la web, ayudarte con compras y recomendar productos. ¡Pregúntame lo que quieras!');
      }
    }
  }

  function pushUserMessage(content) {
    state.messages.push({ role: 'user', content });
    renderMessage({ role: 'user', content });
  }

  function pushAssistantMessage(content) {
    state.messages.push({ role: 'assistant', content });
    renderMessage({ role: 'assistant', content });
  }

  function renderMessage(message) {
    const container = root.querySelector('[data-scroll]');
    if (!container) {
      return;
    }

    const div = document.createElement('div');
    div.className = `groui-message ${message.role}`;

    if (message.role === 'assistant') {
      div.innerHTML = contentToHtml(message.content);
    } else {
      div.textContent = message.content;
    }

    container.appendChild(div);
    container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' });
  }

  function contentToHtml(content) {
    if (!content) {
      return '';
    }
    return content;
  }

  function setLoading(isLoading) {
    state.loading = isLoading;
    const button = root.querySelector('[data-form] button');
    const textarea = root.querySelector('[data-form] textarea');

    if (button) {
      button.disabled = isLoading;
      button.textContent = isLoading ? 'Pensando…' : 'Enviar';
    }

    if (textarea && isLoading) {
      textarea.setAttribute('disabled', 'disabled');
    } else if (textarea) {
      textarea.removeAttribute('disabled');
      textarea.focus();
    }
  }

  function renderProducts(products) {
    const carouselWrapper = root.querySelector('.groui-carousel');
    const track = root.querySelector('[data-carousel]');

    if (!carouselWrapper || !track) {
      return;
    }

    if (!state.hasWooCommerce || !products || !products.length) {
      carouselWrapper.hidden = true;
      track.innerHTML = '';
      return;
    }

    carouselWrapper.hidden = false;
    track.innerHTML = products
      .map(
        (product) => `
          <article class="groui-product-card">
            <img src="${product.image || ''}" alt="${product.name}" loading="lazy" />
            <h5>${product.name}</h5>
            <p class="groui-price">${product.price || ''}</p>
            <p>${product.short_desc || ''}</p>
            <div class="groui-actions">
              <a href="${product.permalink}" target="_blank" rel="noopener">
                Ver detalles
              </a>
            </div>
          </article>
        `
      )
      .join('');
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

        if (response.data.productCards) {
          renderProducts(response.data.productCards);
        } else if (state.hasWooCommerce) {
          requestProducts(content);
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
      const launcher = root.querySelector('.groui-launcher');
      const closeBtn = root.querySelector('.groui-close');

      if (event.target === launcher || launcher.contains(event.target)) {
        togglePanel();
      }

      if (event.target === closeBtn || (closeBtn && closeBtn.contains(event.target))) {
        togglePanel(false);
      }
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

  if (state.hasWooCommerce) {
    requestProducts('');
  }
})(jQuery);
