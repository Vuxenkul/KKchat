(function () {
  'use strict';

  const rootSelector = '.kkchat';
  const settings = window.KKCHAT_SETTINGS || {};

  function qs(el, selector) {
    return el.querySelector(selector);
  }

  function createMessageMarkup(message) {
    const li = document.createElement('li');
    li.className = 'kkchat__message';

    const meta = document.createElement('span');
    meta.className = 'kkchat__meta';
    meta.textContent = `${message.display_name || 'Unknown'} · ${formatTimestamp(message.created_at_gmt)}`;

    const text = document.createElement('p');
    text.className = 'kkchat__text';
    text.textContent = message.message;

    li.appendChild(meta);
    li.appendChild(text);

    return li;
  }

  function formatTimestamp(isoString) {
    if (!isoString) {
      return '';
    }

    try {
      const date = new Date(isoString);
      if (Number.isNaN(date.getTime())) {
        return '';
      }
      return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    } catch (err) {
      return '';
    }
  }

  function scrollToBottom(list) {
    list.scrollTop = list.scrollHeight;
  }

  function renderHistory(list, history) {
    list.innerHTML = '';
    history.forEach((msg) => {
      list.appendChild(createMessageMarkup(msg));
    });
    scrollToBottom(list);
  }

  function appendMessage(list, message) {
    list.appendChild(createMessageMarkup(message));
    scrollToBottom(list);
  }

  function setStatus(statusEl, text, state) {
    statusEl.textContent = text || '';
    statusEl.dataset.state = state || '';
  }

  function fetchHistory(list, statusEl) {
    if (!settings.restUrl) {
      return Promise.resolve();
    }

    return fetch(settings.restUrl + '?limit=50', {
      credentials: 'same-origin',
    })
      .then((response) => {
        if (!response.ok) {
          throw new Error('Failed to load history');
        }
        return response.json();
      })
      .then((data) => {
        if (Array.isArray(data)) {
          renderHistory(list, data);
        }
      })
      .catch(() => {
        setStatus(statusEl, settings.i18n?.historyError || 'Unable to load previous messages.', 'error');
      });
  }

  function createSocket(statusEl, list, composer) {
    let socket;
    let reconnectAttempts = 0;
    let closedManually = false;

    const authPayload = {
      type: 'auth',
      signature: settings.auth?.signature || '',
      nonce: settings.auth?.nonce || '',
      participant: settings.participant || {},
    };

    function sendMessage(text) {
      const payload = {
        type: 'message',
        text,
      };

      if (socket && socket.readyState === WebSocket.OPEN) {
        socket.send(JSON.stringify(payload));
        return Promise.resolve();
      }

      return fetch(settings.restUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': settings.restNonce || '',
        },
        body: JSON.stringify({ message: text }),
      }).then((response) => {
        if (!response.ok) {
          throw new Error('Failed');
        }
        return response.json().then((msg) => {
          if (msg && msg.id) {
            appendMessage(list, msg);
          }
        });
      });
    }

    function scheduleReconnect() {
      if (closedManually) {
        return;
      }

      reconnectAttempts += 1;
      const delay = Math.min(10000, 1000 * reconnectAttempts);
      setStatus(statusEl, settings.i18n?.reconnecting || 'Reconnecting…', 'reconnecting');
      setTimeout(() => {
        connect();
      }, delay);
    }

    function connect() {
      if (!settings.wsUrl) {
        setStatus(statusEl, settings.i18n?.disconnected || 'Disconnected', 'error');
        return;
      }

      setStatus(statusEl, settings.i18n?.connecting || 'Connecting…', 'connecting');

      try {
        socket = new WebSocket(settings.wsUrl);
      } catch (error) {
        setStatus(statusEl, settings.i18n?.disconnected || 'Disconnected', 'error');
        scheduleReconnect();
        return;
      }

      socket.addEventListener('open', () => {
        reconnectAttempts = 0;
        setStatus(statusEl, settings.i18n?.connected || 'Connected', 'connected');
        socket.send(JSON.stringify(authPayload));
      });

      socket.addEventListener('message', (event) => {
        try {
          const data = JSON.parse(event.data);
          if (!data || typeof data !== 'object') {
            return;
          }

          if (data.type === 'ready' && Array.isArray(data.messages)) {
            renderHistory(list, data.messages);
            return;
          }

          if (data.type === 'message') {
            appendMessage(list, data.message);
          }
        } catch (err) {
          // eslint-disable-next-line no-console
          console.warn('Invalid message from server', err);
        }
      });

      socket.addEventListener('close', () => {
        setStatus(statusEl, settings.i18n?.disconnected || 'Disconnected', 'error');
        scheduleReconnect();
      });

      socket.addEventListener('error', () => {
        setStatus(statusEl, settings.i18n?.disconnected || 'Disconnected', 'error');
        if (socket) {
          socket.close();
        }
      });
    }

    function manualClose() {
      closedManually = true;
      if (socket && (socket.readyState === WebSocket.OPEN || socket.readyState === WebSocket.CONNECTING)) {
        socket.close();
      }
    }

    function manualReconnect() {
      closedManually = false;
      reconnectAttempts = 0;
      connect();
    }

    connect();

    return {
      sendMessage,
      manualReconnect,
      manualClose,
    };
  }

  function boot(root) {
    const list = qs(root, '[data-role="messages"]');
    const statusEl = qs(root, '.kkchat__status');
    const form = qs(root, '[data-role="composer"]');
    const textarea = form ? qs(form, 'textarea') : null;
    const reloadButton = qs(root, '[data-action="reload"]');

    if (!list || !form || !textarea) {
      return;
    }

    fetchHistory(list, statusEl).finally(() => {
      const socketController = createSocket(statusEl, list, form);

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        const value = textarea.value.trim();
        if (!value) {
          return;
        }

        textarea.disabled = true;
        socketController
          .sendMessage(value)
          .then(() => {
            textarea.value = '';
          })
          .catch(() => {
            setStatus(statusEl, settings.i18n?.sendFailed || 'Failed to send message.', 'error');
          })
          .finally(() => {
            textarea.disabled = false;
            textarea.focus();
          });
      });

      if (reloadButton) {
        reloadButton.addEventListener('click', () => {
          socketController.manualReconnect();
        });
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll(rootSelector).forEach(boot);
  });
})();
