(function () {
  const cfg = window.lingoraEditor;
  if (!cfg) return;

  const panel = document.createElement('aside');
  panel.className = 'lingora-editor lingora-skip';
  panel.setAttribute('translate', 'no');
  panel.innerHTML =
    '<h2></h2>' +
    '<p data-empty></p>' +
    '<label><span data-original-label></span><textarea data-original readonly></textarea></label>' +
    '<label><span data-translated-label></span><textarea data-translated></textarea></label>' +
    '<div class="lingora-editor__actions">' +
    '<button type="button" data-save></button>' +
    '<button type="button" data-ai></button>' +
    '</div>' +
    '<p data-status></p>';
  panel.querySelector('h2').textContent = cfg.i18n.title;
  panel.querySelector('[data-empty]').textContent = cfg.i18n.empty;
  panel.querySelector('[data-save]').textContent = cfg.i18n.save;
  panel.querySelector('[data-ai]').textContent = cfg.i18n.ai;
  panel.querySelector('[data-original-label]').textContent = 'Original';
  panel.querySelector('[data-translated-label]').textContent = cfg.language;
  document.body.appendChild(panel);

  let active = null;

  function setStatus(message) {
    panel.querySelector('[data-status]').textContent = message || '';
  }

  async function request(path, body) {
    const res = await fetch(cfg.restUrl + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      body: JSON.stringify(body),
    });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok) throw new Error(data.message || 'Request failed');
    return data;
  }

  document.addEventListener('click', function (event) {
    const node = event.target.closest('.lingora-node');
    if (!node || panel.contains(node)) return;
    event.preventDefault();
    if (active) active.classList.remove('is-editing');
    active = node;
    node.classList.add('is-editing');
    panel.querySelector('[data-original]').value = node.getAttribute('data-lingora-original') || node.textContent.trim();
    panel.querySelector('[data-translated]').value = node.textContent.trim();
    panel.querySelector('[data-empty]').hidden = true;
    setStatus('');
  });

  panel.querySelector('[data-save]').addEventListener('click', async function () {
    if (!active) return;
    const id = active.getAttribute('data-lingora-id');
    const translated = panel.querySelector('[data-translated]').value;
    try {
      if (id) {
        await request('strings/' + id, { language: cfg.language, translated: translated });
      } else {
        await request('translate', {
          text: panel.querySelector('[data-original]').value,
          language: cfg.language,
        });
      }
      active.textContent = translated;
      setStatus(cfg.i18n.saved);
    } catch (err) {
      setStatus(err.message);
    }
  });

  panel.querySelector('[data-ai]').addEventListener('click', async function () {
    if (!active) return;
    try {
      const data = await request('translate', {
        text: panel.querySelector('[data-original]').value,
        language: cfg.language,
        string_id: Number(active.getAttribute('data-lingora-id') || 0),
      });
      panel.querySelector('[data-translated]').value = data.text || '';
      setStatus(cfg.i18n.saved);
    } catch (err) {
      setStatus(err.message);
    }
  });
})();
