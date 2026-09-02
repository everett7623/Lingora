(function () {
  const cfg = window.lingoraAdmin || {};
  const toastEl = document.getElementById('lingora-toast');

  function toast(message, isError) {
    if (!toastEl) return;
    toastEl.hidden = false;
    toastEl.textContent = message;
    toastEl.classList.toggle('is-error', Boolean(isError));
    window.setTimeout(function () {
      toastEl.hidden = true;
    }, 3600);
  }

  async function api(path, options) {
    const res = await fetch(cfg.restUrl + path, Object.assign({
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce,
      },
      credentials: 'same-origin',
    }, options || {}));
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok) {
      throw new Error(data.message || res.statusText || 'Request failed');
    }
    return data;
  }

  function readJson(id, fallback) {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try {
      return JSON.parse(el.textContent || '');
    } catch (e) {
      return fallback;
    }
  }

  function initConnection() {
    const providers = readJson('lingora-providers-json', []);
    const seed = readJson('lingora-ai-json', {});
    const providerSelect = document.getElementById('lingora-provider');
    if (!providerSelect) return;

    const hint = document.getElementById('lingora-provider-hint');
    const authWrap = document.getElementById('lingora-auth-wrap');
    const projectWrap = document.getElementById('lingora-project-wrap');
    const baseWrap = document.getElementById('lingora-base-wrap');
    const baseHint = document.getElementById('lingora-base-hint');
    const secretLabel = document.getElementById('lingora-secret-label');
    const apiKey = document.getElementById('lingora-api-key');
    const projectKey = document.getElementById('lingora-project-key');
    const authMode = document.getElementById('lingora-auth-mode');
    const modelSelect = document.getElementById('lingora-model');
    const modelManual = document.getElementById('lingora-model-manual');
    const transcribe = document.getElementById('lingora-transcribe');
    let remoteModels = [];

    function currentProvider() {
      return providers.find(function (item) { return item.id === providerSelect.value; }) || providers[0];
    }

    function fillSelect(select, options, value) {
      select.innerHTML = '';
      options.forEach(function (opt) {
        const option = document.createElement('option');
        option.value = opt;
        option.textContent = opt;
        select.appendChild(option);
      });
      if (value && options.indexOf(value) === -1) {
        const extra = document.createElement('option');
        extra.value = value;
        extra.textContent = value;
        select.appendChild(extra);
      }
      if (value) select.value = value;
    }

    function usesProjectKey(provider) {
      return provider.configurable_auth && authMode.value === 'projectKey';
    }

    function render() {
      const provider = currentProvider();
      hint.textContent = provider.hint || '';
      authWrap.hidden = !provider.configurable_auth;
      projectWrap.hidden = !usesProjectKey(provider);
      apiKey.hidden = usesProjectKey(provider);
      projectKey.hidden = !usesProjectKey(provider);
      secretLabel.textContent = usesProjectKey(provider)
        ? '网关 / Project Key'
        : (provider.id === 'sub2api' ? 'Sub2API API Key' : provider.name + ' API Key');
      if (provider.configurable_base_url) {
        baseWrap.hidden = false;
        baseHint.textContent = '';
      } else {
        baseWrap.hidden = false;
        document.getElementById('lingora-base-url').readOnly = true;
        baseHint.textContent = '官方 API 地址与鉴权方式已内置，只需填写 API Key 并选择模型。';
      }
      document.getElementById('lingora-base-url').readOnly = !provider.configurable_base_url;
      const models = remoteModels.length ? remoteModels : (provider.model_options || []);
      fillSelect(modelSelect, models, modelManual.value);
      fillSelect(transcribe, provider.transcribe_model_options || [], seed.transcribe_model || '');
      transcribe.disabled = !provider.supports_transcribe;
    }

    function payload() {
      const provider = currentProvider();
      return {
        ai: {
          provider_id: providerSelect.value,
          auth_mode: authMode.value,
          api_key: apiKey.value,
          project_key: projectKey.value,
          project_key_header: document.getElementById('lingora-project-header').value,
          project_key_scheme: document.getElementById('lingora-project-scheme').value,
          base_url: document.getElementById('lingora-base-url').value,
          model: modelManual.hidden === false ? modelManual.value : modelSelect.value,
          transcribe_model: transcribe.value,
        },
      };
    }

    providerSelect.addEventListener('change', function () {
      const provider = currentProvider();
      document.getElementById('lingora-base-url').value = provider.base_url || '';
      modelManual.value = provider.model || '';
      remoteModels = [];
      render();
    });

    authMode.addEventListener('change', render);
    modelSelect.addEventListener('change', function () {
      modelManual.value = modelSelect.value;
    });

    document.getElementById('lingora-toggle-model').addEventListener('click', function () {
      modelManual.hidden = !modelManual.hidden;
    });

    document.getElementById('lingora-reveal').addEventListener('click', function (event) {
      const btn = event.currentTarget;
      const show = btn.getAttribute('data-reveal') !== '1';
      btn.setAttribute('data-reveal', show ? '1' : '0');
      btn.textContent = show ? '隐藏' : '显示';
      apiKey.type = show ? 'text' : 'password';
      projectKey.type = show ? 'text' : 'password';
    });

    document.getElementById('lingora-load-models').addEventListener('click', async function (event) {
      const btn = event.currentTarget;
      btn.disabled = true;
      try {
        const data = await api('models', { method: 'POST', body: JSON.stringify(payload()) });
        remoteModels = data.models || [];
        render();
        toast('已读取 ' + remoteModels.length + ' 个模型');
      } catch (err) {
        toast(err.message, true);
      } finally {
        btn.disabled = false;
      }
    });

    document.getElementById('lingora-test').addEventListener('click', async function (event) {
      const btn = event.currentTarget;
      btn.disabled = true;
      try {
        const data = await api('test', { method: 'POST', body: JSON.stringify(payload()) });
        toast(data.ok ? '连接成功：' + data.text : (data.message || '失败'), !data.ok);
      } catch (err) {
        toast(err.message, true);
      } finally {
        btn.disabled = false;
      }
    });

    document.getElementById('lingora-save-ai').addEventListener('click', async function () {
      try {
        await api('settings', { method: 'POST', body: JSON.stringify(payload()) });
        toast('已保存');
      } catch (err) {
        toast(err.message, true);
      }
    });

    render();
  }

  function initLanguages() {
    const table = document.querySelector('#lingora-lang-table tbody');
    if (!table) return;
    let languages = readJson('lingora-languages-json', []);
    const catalog = readJson('lingora-catalog-json', []);

    function render() {
      table.innerHTML = '';
      languages.forEach(function (lang, index) {
        const tr = document.createElement('tr');
        tr.innerHTML =
          '<td>' + escapeHtml(lang.native || lang.name) + '</td>' +
          '<td>' + escapeHtml(lang.code) + '</td>' +
          '<td><input type="radio" name="lingora-default" ' + (lang.is_default ? 'checked' : '') + ' data-index="' + index + '"></td>' +
          '<td><input type="checkbox" ' + (lang.enabled ? 'checked' : '') + ' data-enabled="' + index + '"></td>' +
          '<td><button type="button" class="lingora-btn lingora-btn--ghost" data-remove="' + index + '">移除</button></td>';
        table.appendChild(tr);
      });
    }

    table.addEventListener('change', function (event) {
      const target = event.target;
      if (target.name === 'lingora-default') {
        languages.forEach(function (lang, i) {
          lang.is_default = String(i) === target.getAttribute('data-index');
        });
      }
      if (target.hasAttribute('data-enabled')) {
        languages[Number(target.getAttribute('data-enabled'))].enabled = target.checked;
      }
    });

    table.addEventListener('click', function (event) {
      const btn = event.target.closest('[data-remove]');
      if (!btn) return;
      const index = Number(btn.getAttribute('data-remove'));
      if (languages.length < 2) {
        toast('至少保留一种语言', true);
        return;
      }
      languages.splice(index, 1);
      if (!languages.some(function (lang) { return lang.is_default; })) {
        languages[0].is_default = true;
      }
      render();
    });

    document.getElementById('lingora-add-lang-btn').addEventListener('click', function () {
      const code = document.getElementById('lingora-add-language').value;
      if (!code) return;
      if (languages.some(function (lang) { return lang.code === code; })) {
        toast('语言已存在', true);
        return;
      }
      const meta = catalog.find(function (item) { return item.code === code; });
      languages.push(Object.assign({}, meta, { enabled: true, is_default: false }));
      render();
    });

    document.getElementById('lingora-save-languages').addEventListener('click', async function () {
      try {
        await api('languages', { method: 'POST', body: JSON.stringify({ languages: languages }) });
        toast('语言已保存');
      } catch (err) {
        toast(err.message, true);
      }
    });

    document.getElementById('lingora-save-url').addEventListener('click', async function () {
      try {
        await api('settings', {
          method: 'POST',
          body: JSON.stringify({
            url_mode: document.getElementById('lingora-url-mode').value,
            hide_default: document.getElementById('lingora-hide-default').checked,
          }),
        });
        toast('URL 设置已保存');
      } catch (err) {
        toast(err.message, true);
      }
    });

    render();
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  function initStrings() {
    const tbody = document.querySelector('#lingora-string-table tbody');
    if (!tbody) return;
    const search = document.getElementById('lingora-string-search');
    const lang = document.getElementById('lingora-string-lang');
    const label = document.getElementById('lingora-page-label');
    let page = 1;

    async function load() {
      const params = new URLSearchParams({
        search: search.value,
        language: lang.value,
        page: String(page),
      });
      tbody.innerHTML = '<tr><td colspan="4">加载中…</td></tr>';
      try {
        const data = await api('strings?' + params.toString());
        if (!data.items || !data.items.length) {
          tbody.innerHTML = '<tr><td colspan="4">还没有采集到字符串。先打开前台页面。</td></tr>';
          return;
        }
        tbody.innerHTML = '';
        data.items.forEach(function (item) {
          const tr = document.createElement('tr');
          tr.innerHTML =
            '<td class="lingora-original">' + escapeHtml(item.original) + '</td>' +
            '<td><textarea class="lingora-input" data-id="' + item.id + '">' + escapeHtml(item.translated || '') + '</textarea></td>' +
            '<td>' + escapeHtml(item.status || '—') + '</td>' +
            '<td><button type="button" class="lingora-btn" data-save="' + item.id + '">保存</button> <button type="button" class="lingora-btn lingora-btn--ghost" data-ai="' + item.id + '">AI</button></td>';
          tbody.appendChild(tr);
        });
        label.textContent = String(page);
      } catch (err) {
        tbody.innerHTML = '<tr><td colspan="4">' + escapeHtml(err.message) + '</td></tr>';
      }
    }

    tbody.addEventListener('click', async function (event) {
      const save = event.target.closest('[data-save]');
      const aiBtn = event.target.closest('[data-ai]');
      const id = save ? save.getAttribute('data-save') : (aiBtn ? aiBtn.getAttribute('data-ai') : '');
      if (!id) return;
      const textarea = tbody.querySelector('textarea[data-id="' + id + '"]');
      const row = textarea.closest('tr');
      const original = row.querySelector('.lingora-original').textContent;
      try {
        if (aiBtn) {
          const data = await api('translate', {
            method: 'POST',
            body: JSON.stringify({ text: original, language: lang.value, string_id: Number(id) }),
          });
          textarea.value = data.text || '';
          toast('已生成译文');
          return;
        }
        await api('strings/' + id, {
          method: 'POST',
          body: JSON.stringify({ language: lang.value, translated: textarea.value }),
        });
        toast('已保存');
      } catch (err) {
        toast(err.message, true);
      }
    });

    document.getElementById('lingora-string-reload').addEventListener('click', load);
    document.getElementById('lingora-prev').addEventListener('click', function () {
      page = Math.max(1, page - 1);
      load();
    });
    document.getElementById('lingora-next').addEventListener('click', function () {
      page += 1;
      load();
    });
    search.addEventListener('change', function () {
      page = 1;
      load();
    });
    lang.addEventListener('change', function () {
      page = 1;
      load();
    });
    load();
  }

  function initSettings() {
    const btn = document.getElementById('lingora-save-settings');
    if (!btn) return;
    btn.addEventListener('click', async function () {
      try {
        await api('settings', {
          method: 'POST',
          body: JSON.stringify({
            auto_translate: document.getElementById('opt-auto-translate').checked,
            translate_on_fly: document.getElementById('opt-on-fly').checked,
            on_fly_limit: Number(document.getElementById('opt-on-fly-limit').value),
            translate_gettext: document.getElementById('opt-gettext').checked,
            translate_slugs: document.getElementById('opt-slugs').checked,
            search_translations: document.getElementById('opt-search').checked,
            add_hreflang: document.getElementById('opt-hreflang').checked,
            multilingual_sitemap: document.getElementById('opt-sitemap').checked,
            detect_browser: document.getElementById('opt-detect').checked,
            remember_language: document.getElementById('opt-remember').checked,
            switcher_enabled: document.getElementById('opt-switcher').checked,
            switcher_in_menu: document.getElementById('opt-switcher-menu').checked,
            switcher_style: document.getElementById('opt-switcher-style').value,
            switcher_position: document.getElementById('opt-switcher-position').value,
            switcher_show_flag: document.getElementById('opt-show-flag').checked,
            switcher_show_code: document.getElementById('opt-show-code').checked,
            switcher_show_name: document.getElementById('opt-show-name').checked,
            exclude_selectors: document.getElementById('opt-exclude').value,
            exclude_paths: document.getElementById('opt-exclude-paths').value,
          }),
        });
        toast('设置已保存');
      } catch (err) {
        toast(err.message, true);
      }
    });
  }

  initConnection();
  initLanguages();
  initStrings();
  initSettings();
})();
