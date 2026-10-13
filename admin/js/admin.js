(function () {
  'use strict';

  const { __, sprintf } = wp.i18n;
  const cfg = window.lingoraAdmin || {};
  const toastEl = document.getElementById('lingora-toast');
  const liveEl = document.getElementById('lingora-live-status');
  let toastTimer = 0;

  function toast(message, isError) {
    if (!toastEl) return;
    window.clearTimeout(toastTimer);
    toastEl.hidden = false;
    toastEl.textContent = message;
    toastEl.classList.toggle('is-error', Boolean(isError));
    if (liveEl) liveEl.textContent = message;
    toastTimer = window.setTimeout(function () { toastEl.hidden = true; }, isError ? 10000 : 5000);
  }

  function invalidResponse() {
    return new Error(__('服务器返回了无效响应，请刷新后重试。', 'lingora'));
  }

  async function api(path, options) {
    const res = await fetch(cfg.restUrl + path, Object.assign({
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
      credentials: 'same-origin',
    }, options || {}));
    let data;
    try {
      data = await res.json();
    } catch (err) {
      throw invalidResponse();
    }
    if (!data || typeof data !== 'object' || Array.isArray(data) || !Object.keys(data).length) {
      throw invalidResponse();
    }
    if (!res.ok || data.ok === false) {
      throw new Error(data.message || sprintf(__('请求失败（%d）。', 'lingora'), res.status));
    }
    return data;
  }

  function requireOk(data) {
    if (data.ok !== true) throw invalidResponse();
    return data;
  }

  function applyBootstrap(data) {
    if (!data.settings || !data.settings.ai) throw invalidResponse();
    cfg.aiConsent = data.settings.ai_consent === true;
    cfg.canSendAi = data.settings.can_send_ai === true;
    const status = document.getElementById('lingora-ai-status');
    if (status) {
      status.textContent = !data.settings.ai.has_key ? __('请先连接 AI 服务商', 'lingora')
        : (data.settings.can_send_ai ? __('AI 已就绪', 'lingora') : __('请先同意连接 AI 服务', 'lingora'));
    }
    return data.settings;
  }

  function readJson(id, fallback) {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try { return JSON.parse(el.textContent || ''); } catch (err) { return fallback; }
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
  }

  function initConnection() {
    const providerSelect = document.getElementById('lingora-provider');
    if (!providerSelect) return;
    let providers = readJson('lingora-providers-json', []);
    const form = document.getElementById('lingora-connection');
    const apiKey = document.getElementById('lingora-api-key');
    const projectKey = document.getElementById('lingora-project-key');
    const authMode = document.getElementById('lingora-auth-mode');
    const baseInput = document.getElementById('lingora-base-url');
    const modelSelect = document.getElementById('lingora-model');
    const modelManual = document.getElementById('lingora-model-manual');
    const consent = document.getElementById('lingora-ai-consent');
    const loadModelsBtn = document.getElementById('lingora-load-models');
    const testBtn = document.getElementById('lingora-test');
    const saveBtn = document.getElementById('lingora-save-ai');
    let remoteModels = [];
    let keepManual = false;
    let busy = false;

    function authority(value) {
      try { return new URL(value).origin; } catch (err) { return value.trim(); }
    }
    let previousAuthority = authority(baseInput.value);

    function currentProvider() {
      return providers.find(function (item) { return item.id === providerSelect.value; }) || {};
    }

    function renderLinks(provider) {
      const links = document.getElementById('lingora-provider-links');
      links.replaceChildren();
      const labels = {
        service_url: __('服务商网站', 'lingora'),
        terms_url: __('服务条款', 'lingora'),
        privacy_url: __('隐私政策', 'lingora'),
      };
      Object.keys(labels).forEach(function (key) {
        if (!provider[key]) return;
        let url;
        try { url = new URL(provider[key]); } catch (err) { return; }
        if (url.protocol !== 'https:' && url.protocol !== 'http:') return;
        const link = document.createElement('a');
        link.href = url.href;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        link.textContent = labels[key];
        link.setAttribute('aria-label', sprintf(__('%s（在新窗口打开）', 'lingora'), labels[key]));
        links.appendChild(link);
      });
      links.hidden = !links.childElementCount;
      const hint = document.getElementById('lingora-provider-link-hint');
      hint.textContent = provider.privacy_url && provider.terms_url ? ''
        : __('此服务未提供可核验的隐私或条款链接，请向网关或自定义服务运营方索取并确认。', 'lingora');
      hint.hidden = !hint.textContent;
    }

    function render() {
      const provider = currentProvider();
      const advanced = Boolean(provider.configurable_auth);
      if (!advanced) authMode.value = 'apiKey';
      const project = advanced && authMode.value === 'projectKey';
      document.getElementById('lingora-provider-hint').textContent = provider.hint || '';
      const extra = document.getElementById('lingora-provider-extra');
      extra.textContent = provider.id === 'dots'
        ? __('Dots 认证头固定为 api-key，插件会自动请求 /v1/chat/completions。', 'lingora')
        : (provider.id === 'sub2api' ? __('Base URL 可填网关根地址或以 /v1 结尾的地址；默认使用 Authorization: Bearer，并可从 /v1/models 读取该分组可用模型。', 'lingora') : '');
      extra.hidden = !extra.textContent;
      document.getElementById('lingora-auth-wrap').hidden = !advanced;
      document.getElementById('lingora-project-wrap').hidden = !project;
      document.getElementById('lingora-project-help').hidden = !project;
      apiKey.hidden = project;
      projectKey.hidden = !project;
      document.getElementById('lingora-secret-label').textContent = project
        ? __('网关 / Project Key', 'lingora') : sprintf(__('%s API Key', 'lingora'), provider.name || providerSelect.value);
      const keyHint = document.getElementById('lingora-key-hint');
      keyHint.textContent = provider.id === 'sub2api' && !project
        ? __('标准方式使用 Authorization: Bearer <API Key>；也可在上方切换为自定义请求头方式。', 'lingora') : '';
      keyHint.hidden = !keyHint.textContent;
      document.getElementById('lingora-base-wrap').hidden = !provider.configurable_base_url;
      baseInput.readOnly = !provider.configurable_base_url;
      if (!provider.configurable_base_url) baseInput.value = provider.base_url || '';
      document.getElementById('lingora-base-hint').textContent = provider.configurable_base_url ? ''
        : __('官方 API 地址与鉴权方式已内置，只需填写 API Key 并选择模型。', 'lingora');
      loadModelsBtn.hidden = !provider.models_path;
      modelManual.placeholder = provider.id === 'sub2api' ? __('填写分组模型 ID', 'lingora') : __('模型 ID', 'lingora');
      modelManual.hidden = !(provider.id === 'sub2api' || provider.id === 'custom' || keepManual || !(provider.model_options || []).length);
      modelSelect.replaceChildren();
      const empty = document.createElement('option');
      empty.value = '';
      empty.textContent = __('请选择模型', 'lingora');
      modelSelect.appendChild(empty);
      const models = remoteModels.length ? remoteModels.slice() : (provider.model_options || []).slice();
      if (modelManual.value && !models.includes(modelManual.value)) models.push(modelManual.value);
      models.forEach(function (model) {
        const option = document.createElement('option');
        option.value = model;
        option.textContent = model;
        modelSelect.appendChild(option);
      });
      modelSelect.value = modelManual.value;
      testBtn.disabled = busy || !consent.checked;
      loadModelsBtn.disabled = busy || !consent.checked;
      renderLinks(provider);
    }

    function payload() {
      const provider = currentProvider();
      return {
        ai_consent: consent.checked,
        ai: {
          provider_id: providerSelect.value,
          auth_mode: provider.configurable_auth ? authMode.value : 'apiKey',
          api_key: apiKey.value,
          project_key: projectKey.value,
          project_key_header: document.getElementById('lingora-project-header').value,
          project_key_scheme: document.getElementById('lingora-project-scheme').value,
          base_url: baseInput.value,
          model: modelManual.hidden ? modelSelect.value : modelManual.value,
        },
      };
    }

    function resetSecrets() {
      apiKey.value = '';
      projectKey.value = '';
      consent.checked = false;
      remoteModels = [];
      keepManual = false;
    }

    providerSelect.addEventListener('change', function () {
      const provider = currentProvider();
      resetSecrets();
      authMode.value = provider.preferred_auth_mode || provider.auth_mode || 'apiKey';
      baseInput.value = provider.base_url || '';
      previousAuthority = authority(baseInput.value);
      modelManual.value = provider.model || '';
      document.getElementById('lingora-project-header').value = provider.project_key_header || 'Authorization';
      document.getElementById('lingora-project-scheme').value = provider.project_key_scheme || 'bearer';
      render();
    });
    baseInput.addEventListener('change', function () {
      const next = authority(baseInput.value);
      if (next !== previousAuthority) {
        resetSecrets();
        modelManual.value = '';
        previousAuthority = next;
        render();
        toast(__('服务地址已更改，请重新填写密钥、选择模型并确认同意。', 'lingora'));
      }
    });
    authMode.addEventListener('change', render);
    consent.addEventListener('change', render);
    modelSelect.addEventListener('change', function () { modelManual.value = modelSelect.value; });
    document.getElementById('lingora-toggle-model').addEventListener('click', function () {
      keepManual = modelManual.hidden;
      modelManual.hidden = !modelManual.hidden;
      if (!modelManual.hidden) modelManual.focus();
    });
    document.getElementById('lingora-reveal').addEventListener('click', function (event) {
      const btn = event.currentTarget;
      const show = btn.getAttribute('data-reveal') !== '1';
      btn.setAttribute('data-reveal', show ? '1' : '0');
      btn.setAttribute('aria-pressed', String(show));
      btn.textContent = show ? __('隐藏', 'lingora') : __('显示', 'lingora');
      apiKey.type = projectKey.type = show ? 'text' : 'password';
    });

    function applyConnection(data) {
      const settings = applyBootstrap(data);
      const ai = settings.ai;
      if (Array.isArray(data.providers)) providers = data.providers;
      providerSelect.value = ai.provider_id;
      authMode.value = ai.auth_mode;
      apiKey.value = ai.api_key || '';
      projectKey.value = ai.project_key || '';
      baseInput.value = ai.base_url || '';
      previousAuthority = authority(baseInput.value);
      modelManual.value = ai.model || '';
      document.getElementById('lingora-project-header').value = ai.project_key_header || '';
      document.getElementById('lingora-project-scheme').value = ai.project_key_scheme || 'bearer';
      consent.checked = cfg.aiConsent;
    }

    async function submit(action) {
      if (busy || (action !== 'settings' && !consent.checked)) return;
      const body = payload();
      busy = true;
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('input, select, button').forEach(function (el) { el.disabled = true; });
      try {
        const data = await api(action, { method: 'POST', body: JSON.stringify(body) });
        if (action === 'settings') {
          applyConnection(data);
          toast(__('已保存', 'lingora'));
        } else if (action === 'models') {
          requireOk(data);
          if (!Array.isArray(data.models) || !data.models.every(function (model) { return typeof model === 'string'; })) throw invalidResponse();
          remoteModels = data.models;
          toast(sprintf(__('已读取 %d 个模型', 'lingora'), remoteModels.length));
        } else {
          requireOk(data);
          if (typeof data.text !== 'string') throw invalidResponse();
          toast(sprintf(__('连接成功：%s', 'lingora'), data.text));
        }
      } catch (err) {
        toast(err.message, true);
      } finally {
        busy = false;
        form.setAttribute('aria-busy', 'false');
        form.querySelectorAll('input, select, button').forEach(function (el) { el.disabled = false; });
        render();
      }
    }
    loadModelsBtn.addEventListener('click', function () { submit('models'); });
    testBtn.addEventListener('click', function () { submit('test'); });
    saveBtn.addEventListener('click', function () { submit('settings'); });
    render();
  }

  const ADMIN_LABELS = {
    en: __('英语', 'lingora'), zh: __('中文', 'lingora'), 'zh-tw': __('繁体中文', 'lingora'),
    ja: __('日语', 'lingora'), ko: __('韩语', 'lingora'), es: __('西班牙语', 'lingora'),
    fr: __('法语', 'lingora'), de: __('德语', 'lingora'), pt: __('葡萄牙语', 'lingora'),
    'pt-br': __('巴西葡萄牙语', 'lingora'), it: __('意大利语', 'lingora'), ru: __('俄语', 'lingora'),
    ar: __('阿拉伯语', 'lingora'), hi: __('印地语', 'lingora'), th: __('泰语', 'lingora'),
    vi: __('越南语', 'lingora'), id: __('印尼语', 'lingora'), tr: __('土耳其语', 'lingora'),
    nl: __('荷兰语', 'lingora'), pl: __('波兰语', 'lingora'), sv: __('瑞典语', 'lingora'),
    uk: __('乌克兰语', 'lingora'), he: __('希伯来语', 'lingora'), fa: __('波斯语', 'lingora'),
  };

  function adminLabel(lang) {
    return ADMIN_LABELS[lang.code] || lang.native || lang.name || lang.code;
  }

  function flagHtml(lang, className) {
    const country = /^[a-z]{2}$/.test(String(lang.flag || '').toLowerCase())
      ? String(lang.flag).toLowerCase() : '';
    if (!country || !cfg.flagBaseUrl) return '';
    return '<img class="' + escapeHtml(className || 'lingora-country-flag') + '" src="' +
      escapeHtml(cfg.flagBaseUrl + country + '.svg') + '" width="24" height="18" alt="" aria-hidden="true">';
  }

  function initLanguages() {
    const table = document.querySelector('#lingora-lang-table tbody');
    if (!table) return;
    let languages = readJson('lingora-languages-json', []);
    const catalog = readJson('lingora-catalog-json', []);
    const panel = table.closest('.lingora-panel');
    const save = document.getElementById('lingora-save-languages');
    const nativePicker = document.getElementById('lingora-add-language');
    let picker;

    function initLanguagePicker() {
      picker = document.createElement('div');
      picker.className = 'lingora-language-picker';
      picker.innerHTML = '<button type="button" class="lingora-language-picker__button" aria-expanded="false" aria-controls="lingora-language-options" aria-labelledby="lingora-add-language-label lingora-language-picker-value">' +
        '<span id="lingora-language-picker-value">' + escapeHtml(__('从目录选择…', 'lingora')) + '</span><span class="lingora-language-picker__chevron" aria-hidden="true"></span></button>' +
        '<div class="lingora-language-picker__menu" id="lingora-language-options" hidden>' +
        '<input type="search" class="lingora-language-picker__search" placeholder="' + escapeHtml(__('搜索语言或代码…', 'lingora')) + '" aria-label="' + escapeHtml(__('搜索语言', 'lingora')) + '">' +
        '<div class="lingora-language-picker__options" role="listbox"></div></div>';
      nativePicker.hidden = true;
      nativePicker.insertAdjacentElement('afterend', picker);
      const button = picker.querySelector('.lingora-language-picker__button');
      const menu = picker.querySelector('.lingora-language-picker__menu');
      const search = picker.querySelector('.lingora-language-picker__search');

      function closePicker(focus) {
        menu.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        if (focus) button.focus();
      }
      function openPicker() {
        menu.hidden = false;
        button.setAttribute('aria-expanded', 'true');
        search.value = '';
        renderPicker('');
        search.focus();
      }
      button.addEventListener('click', function () { menu.hidden ? openPicker() : closePicker(false); });
      search.addEventListener('input', function () { renderPicker(search.value); });
      picker.addEventListener('click', function (event) {
        const option = event.target.closest('[data-language-code]');
        if (!option || option.disabled) return;
        nativePicker.value = option.dataset.languageCode;
        const lang = catalog.find(function (item) { return item.code === nativePicker.value; });
        button.querySelector('#lingora-language-picker-value').innerHTML = flagHtml(lang) +
          '<span>' + escapeHtml(lang.native) + '</span><small>' + escapeHtml(lang.code.toUpperCase()) + '</small>';
        closePicker(true);
      });
      picker.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !menu.hidden) {
          event.preventDefault();
          closePicker(true);
        } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !menu.hidden) {
          const options = Array.from(menu.querySelectorAll('[data-language-code]:not(:disabled)'));
          if (!options.length) return;
          event.preventDefault();
          const current = options.indexOf(document.activeElement);
          const next = current < 0 ? (event.key === 'ArrowDown' ? 0 : options.length - 1)
            : (current + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
          options[next].focus();
        }
      });
      document.addEventListener('click', function (event) {
        if (!picker.contains(event.target)) closePicker(false);
      });
    }

    function renderPicker(query) {
      if (!picker) return;
      const normalized = String(query || '').trim().toLocaleLowerCase();
      const options = picker.querySelector('.lingora-language-picker__options');
      const matches = catalog.filter(function (lang) {
        return !normalized || [lang.native, lang.name, lang.code].some(function (value) {
          return String(value || '').toLocaleLowerCase().includes(normalized);
        });
      });
      options.innerHTML = matches.map(function (lang) {
        const exists = languages.some(function (item) { return item.code === lang.code; });
        return '<button type="button" role="option" class="lingora-language-picker__option" data-language-code="' + escapeHtml(lang.code) + '"' + (exists ? ' disabled aria-disabled="true"' : '') + '>' +
          flagHtml(lang) + '<span><strong>' + escapeHtml(lang.native) + '</strong><small>' + escapeHtml(lang.name) + '</small></span>' +
          '<code>' + escapeHtml(lang.code.toUpperCase()) + '</code></button>';
      }).join('') || '<p class="lingora-language-picker__empty">' + escapeHtml(__('没有匹配的语言', 'lingora')) + '</p>';
    }

    function updateUrlExamples() {
      const fallback = { code: 'en' };
      const def = languages.find(function (lang) { return lang.is_default; }) || languages[0] || fallback;
      const target = languages.find(function (lang) { return !lang.is_default && lang.enabled; }) || fallback;
      document.getElementById('lingora-lang-hero').textContent = sprintf(__('默认语言不复制文章。其它语言走目录前缀，例如 https://example.com/%s/ 。', 'lingora'), target.code);
      document.querySelector('#lingora-url-mode option[value="directory"]').textContent = sprintf(__('目录前缀 %s', 'lingora'), 'https://example.com/' + target.code + '/about/');
      document.querySelector('#lingora-url-mode option[value="query"]').textContent = sprintf(__('查询参数 ?lang=%s', 'lingora'), target.code);
      document.getElementById('lingora-hide-default-label').textContent = sprintf(__('隐藏默认语言前缀（%1$s首页是 /，%2$s是 /%3$s/）', 'lingora'), adminLabel(def), adminLabel(target), target.code);
    }

    function render() {
      table.replaceChildren();
      languages.forEach(function (lang, index) {
        if (lang.is_default) lang.enabled = true;
        const name = adminLabel(lang);
        const tr = document.createElement('tr');
        tr.innerHTML = '<td><span class="lingora-language-name">' + flagHtml(lang) + '<span><strong>' + escapeHtml(lang.native || lang.name || lang.code) + '</strong><small>' + escapeHtml(lang.name || '') + '</small></span></span></td><td><code>' + escapeHtml(lang.code.toUpperCase()) + '</code></td>' +
          '<td><input type="radio" name="lingora-default" ' + (lang.is_default ? 'checked ' : '') + 'data-index="' + index + '" aria-label="' + escapeHtml(sprintf(__('将 %s 设为默认语言', 'lingora'), name)) + '"></td>' +
          '<td><input type="checkbox" ' + (lang.enabled ? 'checked ' : '') + (lang.is_default ? 'disabled ' : '') + 'data-enabled="' + index + '" aria-label="' + escapeHtml(sprintf(__('启用 %s', 'lingora'), name)) + '"></td>' +
          '<td><button type="button" class="lingora-btn lingora-btn--ghost" data-remove="' + index + '" aria-label="' + escapeHtml(sprintf(__('移除 %s', 'lingora'), name)) + '">' + escapeHtml(__('移除', 'lingora')) + '</button></td>';
        table.appendChild(tr);
      });
      updateUrlExamples();
      renderPicker('');
    }
    table.addEventListener('change', function (event) {
      const target = event.target;
      if (target.name === 'lingora-default') {
        languages.forEach(function (lang, index) { lang.is_default = String(index) === target.dataset.index; });
        render();
        const selected = table.querySelector('input[name="lingora-default"]:checked');
        if (selected) selected.focus();
      } else if (target.hasAttribute('data-enabled')) {
        const language = languages[Number(target.dataset.enabled)];
        language.enabled = language.is_default || target.checked;
        updateUrlExamples();
      }
    });
    table.addEventListener('click', function (event) {
      const btn = event.target.closest('[data-remove]');
      if (!btn || btn.disabled) return;
      if (languages.length < 2) return toast(__('至少保留一种语言', 'lingora'), true);
      languages.splice(Number(btn.dataset.remove), 1);
      if (!languages.some(function (lang) { return lang.is_default; })) languages[0].is_default = true;
      render();
    });
    document.getElementById('lingora-add-lang-btn').addEventListener('click', function () {
      const code = document.getElementById('lingora-add-language').value;
      if (!code) return;
      if (languages.some(function (lang) { return lang.code === code; })) return toast(__('语言已存在', 'lingora'), true);
      const meta = catalog.find(function (item) { return item.code === code; });
      if (!meta) return;
      languages.push(Object.assign({}, meta, { enabled: true, is_default: false }));
      nativePicker.value = '';
      picker.querySelector('#lingora-language-picker-value').textContent = __('从目录选择…', 'lingora');
      render();
    });
    initLanguagePicker();
    save.addEventListener('click', async function () {
      if (save.disabled) return;
      panel.querySelectorAll('button, input, select').forEach(function (el) { el.disabled = true; });
      panel.setAttribute('aria-busy', 'true');
      try {
        const data = await api('languages', { method: 'POST', body: JSON.stringify({ languages: languages }) });
        if (!Array.isArray(data.languages) || !data.languages.length) throw invalidResponse();
        applyBootstrap(data);
        languages = data.languages;
        toast(__('语言已保存', 'lingora'));
      } catch (err) {
        toast(err.message, true);
      } finally {
        panel.setAttribute('aria-busy', 'false');
        panel.querySelectorAll('button, input, select').forEach(function (el) { el.disabled = false; });
        render();
      }
    });
    document.getElementById('lingora-save-url').addEventListener('click', async function (event) {
      const btn = event.currentTarget;
      if (btn.disabled) return;
      const mode = document.getElementById('lingora-url-mode');
      const hide = document.getElementById('lingora-hide-default');
      btn.disabled = mode.disabled = hide.disabled = true;
      try {
        const data = await api('settings', { method: 'POST', body: JSON.stringify({
          ai_consent: cfg.aiConsent === true, url_mode: mode.value, hide_default: hide.checked,
        }) });
        const settings = applyBootstrap(data);
        mode.value = settings.url_mode;
        hide.checked = settings.hide_default;
        toast(__('URL 设置已保存', 'lingora'));
      } catch (err) {
        toast(err.message, true);
      } finally {
        btn.disabled = mode.disabled = hide.disabled = false;
      }
    });
    render();
  }

  function statusCell(item) {
    let key = 'missing';
    let label = __('空缺', 'lingora');
    if (item.translated && item.status === 'human') { key = 'human'; label = __('人工', 'lingora'); }
    if (item.translated && item.status === 'auto') { key = 'auto'; label = __('自动', 'lingora'); }
    return '<span class="lingora-pill lingora-pill--' + key + '">' + escapeHtml(label) + '</span>';
  }

  function initStrings() {
    const tbody = document.querySelector('#lingora-string-table tbody');
    if (!tbody) return null;
    const search = document.getElementById('lingora-string-search');
    const lang = document.getElementById('lingora-string-lang');
    const kind = document.getElementById('lingora-string-kind');
    const label = document.getElementById('lingora-page-label');
    const prevBtn = document.getElementById('lingora-prev');
    const nextBtn = document.getElementById('lingora-next');
    const filters = document.querySelector('.lingora-filters');
    const refreshNote = document.querySelector('[data-job-refresh-note]');
    let page = 1;
    let pages = 1;
    let status = '';
    let searchTimer = 0;
    let sequence = 0;
    let controller = null;
    let loading = false;

    function invalidate() {
      sequence += 1;
      if (controller) controller.abort();
    }

    function renderItem(item, language) {
      const tr = document.createElement('tr');
      tr.dataset.language = language;
      const slug = item.context === 'slug';
      const same = item.translated && item.translated === item.original;
      tr.innerHTML = '<td class="lingora-original' + (slug ? ' lingora-original--slug' : '') + '">' +
        '<div class="lingora-original__text">' + escapeHtml(item.original) + '</div>' +
        (slug ? '<span class="lingora-badge">' + escapeHtml(__('别名/URL', 'lingora')) + '</span>' : '') + '</td><td>' +
        '<textarea class="lingora-input lingora-input--translation" data-id="' + escapeHtml(item.id) + '" rows="3" aria-label="' + escapeHtml(sprintf(__('%1$s 译文，条目 %2$s', 'lingora'), language, item.id)) + '" placeholder="' + escapeHtml(__('尚未翻译', 'lingora')) + '">' + escapeHtml(item.translated || '') + '</textarea>' +
        (same ? '<p class="lingora-hint">' + escapeHtml(__('译文与原文相同，请确认翻译结果。', 'lingora')) + '</p>' : '') +
        '<p class="lingora-hint" data-row-note hidden></p></td>' +
        '<td class="lingora-status">' + statusCell(item) + '</td><td class="lingora-row-actions">' +
        '<button type="button" class="lingora-btn" data-save="' + escapeHtml(item.id) + '">' + escapeHtml(__('保存', 'lingora')) + '</button>' +
        '<button type="button" class="lingora-btn lingora-btn--ghost" data-ai="' + escapeHtml(item.id) + '">' + escapeHtml(__('AI 翻译', 'lingora')) + '</button></td>';
      return tr;
    }

    async function load() {
      window.clearTimeout(searchTimer);
      invalidate();
      const requestSequence = sequence;
      controller = new AbortController();
      const language = lang.value;
      const params = new URLSearchParams({ search: search.value, language: language, page: String(page), status: status, kind: kind.value });
      loading = true;
      tbody.closest('table').setAttribute('aria-busy', 'true');
      prevBtn.disabled = nextBtn.disabled = true;
      tbody.innerHTML = '<tr><td colspan="4">' + escapeHtml(__('加载中…', 'lingora')) + '</td></tr>';
      if (refreshNote) refreshNote.hidden = true;
      try {
        const data = await api('strings?' + params.toString(), { signal: controller.signal });
        if (requestSequence !== sequence) return;
        if (!Array.isArray(data.items) || data.language !== language || !Number.isFinite(Number(data.pages))) throw invalidResponse();
        pages = Math.max(1, Number(data.pages));
        if (page > pages) { page = pages; load(); return; }
        tbody.replaceChildren();
        if (!data.items.length) {
          const filtered = search.value || status || kind.value;
          const text = filtered ? __('没有符合筛选条件的条目。试试改搜索词，或把状态切回「全部」。', 'lingora')
            : __('还没有采集到文案。请先扫描公开内容，或匿名访问目标页面。', 'lingora');
          tbody.innerHTML = '<tr><td colspan="4"><div class="lingora-empty"><p>' + escapeHtml(text) + '</p>' +
            (filtered ? '' : '<a class="lingora-btn lingora-btn--primary" href="' + escapeHtml(cfg.dashboardUrl || 'admin.php?page=lingora') + '">' + escapeHtml(__('前往总览', 'lingora')) + '</a>') + '</div></td></tr>';
        } else {
          data.items.forEach(function (item) { tbody.appendChild(renderItem(item, language)); });
        }
        label.textContent = sprintf(__('第 %1$d / %2$d 页', 'lingora'), page, pages);
        prevBtn.disabled = page <= 1;
        nextBtn.disabled = page >= pages;
      } catch (err) {
        if (requestSequence !== sequence || err.name === 'AbortError') return;
        tbody.innerHTML = '<tr><td colspan="4">' + escapeHtml(err.message) + '</td></tr>';
        toast(err.message, true);
      } finally {
        if (requestSequence === sequence) {
          loading = false;
          tbody.closest('table').setAttribute('aria-busy', 'false');
        }
      }
    }

    tbody.addEventListener('input', function (event) {
      const row = event.target.closest('tr');
      if (row && event.target.matches('textarea')) row.dataset.dirty = '1';
    });
    tbody.addEventListener('click', async function (event) {
      const btn = event.target.closest('[data-save], [data-ai]');
      if (!btn || btn.disabled) return;
      const row = btn.closest('tr');
      const textarea = row.querySelector('textarea');
      const id = Number(textarea.dataset.id);
      const language = row.dataset.language;
      const translated = textarea.value;
      const ai = btn.hasAttribute('data-ai');
      if (!Number.isInteger(id) || id <= 0 || !language) return toast(__('条目或目标语言无效，请刷新后重试。', 'lingora'), true);
      if (!ai && !translated.trim()) return toast(__('译文不能为空', 'lingora'), true);
      row.dataset.busy = '1';
      row.setAttribute('aria-busy', 'true');
      row.querySelectorAll('button').forEach(function (button) { button.disabled = true; });
      textarea.readOnly = true;
      const note = row.querySelector('[data-row-note]');
      try {
        const data = requireOk(await api(ai ? 'translate' : 'strings/' + id, {
          method: 'POST', body: JSON.stringify(ai ? { string_id: id, language: language } : { language: language, translated: translated }),
        }));
        if (ai && (typeof data.text !== 'string' || typeof data.saved !== 'boolean')) throw invalidResponse();
        // The request belongs to this row and language even if the table was replaced meanwhile.
        if (row.isConnected && row.dataset.language === language && lang.value === language) {
          if (ai) textarea.value = data.text;
          row.dataset.dirty = ai && !data.saved ? '1' : '0';
          if (!ai || data.saved) row.querySelector('.lingora-status').innerHTML = statusCell({ translated: ai ? data.text : translated, status: ai ? 'auto' : 'human' });
          note.textContent = ai && !data.saved ? __('AI 预览尚未保存，原人工译文未改变。点击「保存」后才会替换。', 'lingora') : '';
          note.hidden = !note.textContent;
        }
        toast(ai ? (data.saved ? sprintf(__('%s 的 AI 译文已保存', 'lingora'), language) : sprintf(__('%s 的 AI 预览已生成，尚未保存', 'lingora'), language)) : sprintf(__('%s 的译文已保存', 'lingora'), language));
      } catch (err) {
        toast(err.message, true);
      } finally {
        row.dataset.busy = '0';
        row.setAttribute('aria-busy', 'false');
        row.querySelectorAll('button').forEach(function (button) { button.disabled = false; });
        textarea.readOnly = false;
      }
    });
    document.getElementById('lingora-string-reload').addEventListener('click', load);
    prevBtn.addEventListener('click', function () { if (!loading && page > 1) { page -= 1; load(); } });
    nextBtn.addEventListener('click', function () { if (!loading && page < pages) { page += 1; load(); } });
    function resetAndLoad() { page = 1; load(); }
    search.addEventListener('input', function () {
      window.clearTimeout(searchTimer);
      invalidate();
      searchTimer = window.setTimeout(resetAndLoad, 280);
    });
    search.addEventListener('change', resetAndLoad);
    lang.addEventListener('change', resetAndLoad);
    kind.addEventListener('change', resetAndLoad);
    filters.addEventListener('click', function (event) {
      const chip = event.target.closest('[data-status]');
      if (!chip) return;
      status = chip.dataset.status || '';
      filters.querySelectorAll('.lingora-chip').forEach(function (el) {
        el.classList.toggle('is-active', el === chip);
        el.setAttribute('aria-pressed', String(el === chip));
      });
      resetAndLoad();
    });
    load();
    return {
      refresh: function () {
        if (tbody.querySelector('[data-dirty="1"], [data-busy="1"]')) {
          refreshNote.textContent = __('后台数据已更新；当前草稿已保留。保存或处理草稿后，点击「刷新」查看最新结果。', 'lingora');
          refreshNote.hidden = false;
        } else if (!loading) {
          load();
        }
      },
    };
  }

  function initSettings() {
    const btn = document.getElementById('lingora-save-settings');
    if (!btn) return;
    const fields = {
      auto_translate: 'opt-auto-translate', translate_on_fly: 'opt-on-fly', on_fly_limit: 'opt-on-fly-limit',
      scan_published: 'opt-scan-published', translate_gettext: 'opt-gettext', translate_slugs: 'opt-slugs',
      search_translations: 'opt-search', add_hreflang: 'opt-hreflang', multilingual_sitemap: 'opt-sitemap',
      detect_browser: 'opt-detect', remember_language: 'opt-remember', switcher_enabled: 'opt-switcher',
      switcher_in_menu: 'opt-switcher-menu', switcher_style: 'opt-switcher-style', switcher_position: 'opt-switcher-position',
      switcher_show_flag: 'opt-show-flag', switcher_show_code: 'opt-show-code', switcher_show_name: 'opt-show-name',
      exclude_selectors: 'opt-exclude', exclude_paths: 'opt-exclude-paths',
    };
    btn.addEventListener('click', async function () {
      if (btn.disabled) return;
      const body = { ai_consent: cfg.aiConsent === true };
      Object.keys(fields).forEach(function (key) {
        const el = document.getElementById(fields[key]);
        body[key] = el.type === 'checkbox' ? el.checked : (el.type === 'number' ? Number(el.value) : el.value);
        el.disabled = true;
      });
      btn.disabled = true;
      try {
        const settings = applyBootstrap(await api('settings', { method: 'POST', body: JSON.stringify(body) }));
        Object.keys(fields).forEach(function (key) {
          const el = document.getElementById(fields[key]);
          if (el.type === 'checkbox') el.checked = Boolean(settings[key]);
          else el.value = settings[key];
        });
        toast(__('设置已保存', 'lingora'));
      } catch (err) {
        toast(err.message, true);
      } finally {
        btn.disabled = false;
        Object.keys(fields).forEach(function (key) { document.getElementById(fields[key]).disabled = false; });
      }
    });
  }

  function initJobs(strings) {
    const scanStatus = document.querySelector('[data-job-status="scan"]');
    const queueStatus = document.querySelector('[data-job-status="queue"]');
    if (!scanStatus || !queueStatus) return;
    const scanBtn = document.getElementById('lingora-scan-now');
    const queueBtn = document.getElementById('lingora-run-queue');
    const missingBtn = document.getElementById('lingora-translate-missing');
    const scanProgress = document.querySelector('[data-job-progress="scan"]');
    const queueProgress = document.querySelector('[data-job-progress="queue"]');
    let jobs = { queue: {}, scan: {}, stats: {} };
    let initialized = false;
    let inFlight = false;
    let refreshRequested = false;
    let stopped = false;
    let timer = 0;
    let epoch = 0;
    let controller = null;
    let fingerprint = '';
    const submitting = { scan: false, queue: false, missing: false };
    const cooldown = { scan: 0, queue: 0, missing: 0 };
    function count(value) { return Math.max(0, Number(value) || 0); }

    function render() {
      const scan = jobs.scan;
      const queue = jobs.queue;
      const scanning = !scan.error && scan.status === 'running';
      const working = !queue.error && count(queue.claimed) > 0;
      const now = Date.now();
      if (scanBtn) {
        scanBtn.disabled = !initialized || submitting.scan || scanning || now < cooldown.scan;
        scanBtn.textContent = submitting.scan ? __('正在提交扫描…', 'lingora') : (scanning ? __('扫描进行中…', 'lingora') : __('扫描已发布内容', 'lingora'));
      }
      [queueBtn, missingBtn].forEach(function (btn) {
        if (btn) btn.disabled = !initialized || submitting.queue || submitting.missing || working || now < cooldown.queue || now < cooldown.missing;
      });
      if (queueBtn) queueBtn.textContent = submitting.queue ? __('正在提交队列…', 'lingora') : (working ? __('队列正在处理…', 'lingora') : __('立即处理队列', 'lingora'));
      if (missingBtn) missingBtn.textContent = submitting.missing ? __('正在加入队列…', 'lingora') : (working ? __('队列正在处理…', 'lingora') : __('用 AI 补齐本语言空缺', 'lingora'));
      scanProgress.hidden = !scanning;
      queueProgress.hidden = !working;
      if (!initialized) return;
      if (scan.error) scanStatus.textContent = sprintf(__('无法读取扫描状态：%s', 'lingora'), scan.error);
      else if (scanning) scanStatus.textContent = sprintf(__('扫描进行中：已扫描 %1$d 篇内容，已采集 %2$d 条字符串。', 'lingora'), count(scan.scanned), count(scan.strings));
      else if (scan.status === 'done') scanStatus.textContent = sprintf(__('扫描完成：共扫描 %1$d 篇内容，采集 %2$d 条字符串。', 'lingora'), count(scan.scanned), count(scan.strings));
      else if (scan.status === 'stalled') scanStatus.textContent = sprintf(__('扫描已暂停：%s', 'lingora'), scan.message || __('请重新提交扫描。', 'lingora'));
      else scanStatus.textContent = scan.message || __('当前没有进行中的扫描。', 'lingora');
      if (queue.error) queueStatus.textContent = sprintf(__('无法读取队列状态：%s', 'lingora'), queue.error);
      else queueStatus.textContent = sprintf(__('队列：等待 %1$d，延后重试 %2$d，处理中 %3$d，失败 %4$d；其中手动请求 %5$d。', 'lingora'), count(queue.pending), count(queue.delayed), count(queue.claimed), count(queue.failed), count(queue.manual));
      scanStatus.classList.toggle('is-error', Boolean(scan.error || scan.status === 'stalled'));
      queueStatus.classList.toggle('is-error', Boolean(queue.error || count(queue.failed)));
      document.querySelectorAll('[data-lingora-stat]').forEach(function (el) {
        const value = jobs.stats[el.dataset.lingoraStat];
        if (value !== undefined) el.textContent = String(count(value));
      });
      const summary = document.getElementById('lingora-job-summary');
      if (summary) summary.textContent = __('任务由 WordPress 后台调度；加入队列不代表翻译完成。刷新页面后会继续显示任务进度。', 'lingora');
    }

    // One scheduler owns both subsystems. Triggers request a refresh; they never start a second loop.
    function schedule(delay) {
      window.clearTimeout(timer);
      if (stopped || document.hidden) return;
      if (inFlight) { refreshRequested = true; return; }
      timer = window.setTimeout(poll, delay);
    }
    async function poll() {
      if (stopped || document.hidden || inFlight) return;
      inFlight = true;
      refreshRequested = false;
      controller = new AbortController();
      const requestEpoch = epoch;
      try {
        const data = await api('jobs', { signal: controller.signal });
        if (requestEpoch !== epoch || stopped) return;
        if (!data.queue || !data.scan || !data.stats) throw invalidResponse();
        const previous = jobs;
        jobs = data;
        initialized = true;
        const nextFingerprint = JSON.stringify([jobs.stats.strings, jobs.stats.translations, jobs.stats.failed, jobs.scan.status, jobs.scan.strings]);
        if (strings && fingerprint && fingerprint !== nextFingerprint) strings.refresh();
        fingerprint = nextFingerprint;
        if (previous.scan.status === 'running' && jobs.scan.status === 'done') toast(__('扫描完成，公开文案已采集；待译条目将由队列处理。', 'lingora'));
        if (count(previous.queue.claimed) > 0 && !count(jobs.queue.claimed) && !count(jobs.queue.pending) && !count(jobs.queue.delayed) && !jobs.queue.error) {
          toast(count(jobs.queue.failed) ? __('本轮队列已结束，仍有失败条目，请检查服务配置。', 'lingora') : __('当前队列处理已结束。', 'lingora'), count(jobs.queue.failed) > 0);
          if (strings) strings.refresh();
        }
        render();
      } catch (err) {
        if (err.name !== 'AbortError' && !stopped && requestEpoch === epoch) {
          initialized = true;
          jobs.scan = { error: err.message };
          jobs.queue = { error: err.message };
          render();
        }
      } finally {
        inFlight = false;
        const active = jobs.scan.status === 'running' || count(jobs.queue.claimed) || count(jobs.queue.pending) || count(jobs.queue.delayed);
        schedule(refreshRequested ? 0 : (active ? 3000 : 10000));
      }
    }

    async function trigger(action) {
      const btn = action === 'scan' ? scanBtn : (action === 'queue' ? queueBtn : missingBtn);
      if (!btn || btn.disabled || submitting[action]) return;
      const language = action === 'missing' ? document.getElementById('lingora-string-lang').value : '';
      submitting[action] = true;
      epoch += 1;
      render();
      try {
        const data = requireOk(await api(action, { method: 'POST', body: JSON.stringify(action === 'missing' ? { language: language } : {}) }));
        if (action === 'scan') {
          if (!data.scan || !data.scan.ok) throw new Error((data.scan && data.scan.message) || invalidResponse().message);
          jobs.scan = data.scan;
          toast(data.scan.message || __('扫描请求已提交，将在后台继续。', 'lingora'));
        } else if (action === 'queue') {
          if (!data.queue || !data.queue.ok) throw new Error((data.queue && data.queue.message) || invalidResponse().message);
          toast(data.queue.message || sprintf(__('已请求后台处理，新增 %d 条待译任务。', 'lingora'), count(data.queue.queued)));
        } else {
          if (!Number.isFinite(Number(data.queued))) throw invalidResponse();
          toast(sprintf(__('已为 %1$s 加入 %2$d 条待译任务；尚未完成翻译。', 'lingora'), language, count(data.queued)));
        }
        cooldown[action] = Date.now() + 3000;
      } catch (err) {
        toast(err.message, true);
      } finally {
        epoch += 1;
        submitting[action] = false;
        render();
        schedule(0);
      }
    }
    if (scanBtn) scanBtn.addEventListener('click', function () { trigger('scan'); });
    if (queueBtn) queueBtn.addEventListener('click', function () { trigger('queue'); });
    if (missingBtn) missingBtn.addEventListener('click', function () { trigger('missing'); });
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) window.clearTimeout(timer);
      else schedule(0);
    });
    window.addEventListener('pagehide', function () {
      stopped = true;
      window.clearTimeout(timer);
      if (controller) controller.abort();
    });
    window.addEventListener('pageshow', function (event) {
      if (event.persisted) { stopped = false; schedule(0); }
    });
    render();
    schedule(0);
  }

  initConnection();
  initLanguages();
  const strings = initStrings();
  initSettings();
  initJobs(strings);
})();
