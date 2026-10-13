(function () {
  'use strict';

  const cfg = window.lingoraEditor;
  if (!cfg) return;
  const { __, sprintf } = wp.i18n;
  const targets = Array.isArray(cfg.targets) ? cfg.targets.filter(function (target) {
    return target.code && target.code !== cfg.default;
  }) : [];
  const language = cfg.language;
  const isTarget = language !== cfg.default && targets.some(function (target) { return target.code === language; });

  const panel = document.createElement('aside');
  panel.id = 'lingora-editor';
  panel.className = 'lingora-editor lingora-skip';
  panel.setAttribute('translate', 'no');
  panel.setAttribute('aria-labelledby', 'lingora-editor-title');
  panel.innerHTML =
    '<h2 id="lingora-editor-title"></h2>' +
    '<label for="lingora-editor-language" data-language-label></label><select id="lingora-editor-language"></select>' +
    '<p data-hint></p>' +
    '<label for="lingora-editor-field" data-field-label></label><select id="lingora-editor-field"></select>' +
    '<label for="lingora-editor-original" data-original-label></label><textarea id="lingora-editor-original" readonly></textarea>' +
    '<label for="lingora-editor-translated" data-translated-label></label><textarea id="lingora-editor-translated" disabled></textarea>' +
    '<div class="lingora-editor__actions"><button type="button" data-save disabled></button><button type="button" data-ai disabled></button></div>' +
    '<p data-status role="status" aria-live="polite" aria-atomic="true"></p>';
  const languageSelect = panel.querySelector('#lingora-editor-language');
  const fieldSelect = panel.querySelector('#lingora-editor-field');
  const originalInput = panel.querySelector('#lingora-editor-original');
  const translatedInput = panel.querySelector('#lingora-editor-translated');
  const saveBtn = panel.querySelector('[data-save]');
  const aiBtn = panel.querySelector('[data-ai]');
  const statusEl = panel.querySelector('[data-status]');
  const hint = panel.querySelector('[data-hint]');
  panel.querySelector('h2').textContent = __('Lingora 可视化改译', 'lingora');
  panel.querySelector('[data-language-label]').textContent = __('目标语言', 'lingora');
  panel.querySelector('[data-field-label]').textContent = __('文案或属性', 'lingora');
  panel.querySelector('[data-original-label]').textContent = __('原文', 'lingora');
  panel.querySelector('[data-translated-label]').textContent = __('目标语言译文', 'lingora');
  saveBtn.textContent = __('保存译文', 'lingora');
  aiBtn.textContent = __('AI 翻译', 'lingora');
  hint.textContent = isTarget
    ? __('点击高亮文案，或从列表选择属性。没有高亮时，请扫描公开内容，或先匿名访问目标页面。', 'lingora')
    : __('当前为默认语言页。请先选择目标语言再编辑译文；没有高亮时，请扫描公开内容，或先匿名访问目标页面。', 'lingora');
  document.body.appendChild(panel);
  document.body.classList.add('lingora-editor-active');

  function addOption(select, value, text) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = text;
    select.appendChild(option);
  }
  addOption(languageSelect, '', targets.length ? __('请选择目标语言', 'lingora') : __('请先在后台启用目标语言', 'lingora'));
  targets.forEach(function (target) { addOption(languageSelect, target.code, target.label || target.code); });
  languageSelect.value = isTarget ? language : '';
  languageSelect.disabled = !targets.length;

  const attrLabels = {
    alt: __('图片替代文本（alt）', 'lingora'),
    title: __('提示文本（title）', 'lingora'),
    placeholder: __('输入提示（placeholder）', 'lingora'),
    'aria-label': __('无障碍名称（aria-label）', 'lingora'),
    'aria-placeholder': __('无障碍输入提示（aria-placeholder）', 'lingora'),
    content: __('页面元信息（content）', 'lingora'),
    value: __('按钮文本（value）', 'lingora'),
  };
  const entries = [];
  const byNode = new Map();
  let active = null;
  let loaded = false;
  let loading = false;
  let pending = false;
  let sequence = 0;
  let loadController = null;

  function safeAttribute(node, attr) {
    if (!Object.prototype.hasOwnProperty.call(attrLabels, attr)) return false;
    if (attr === 'content') return node.tagName === 'META';
    if (attr === 'value') return node.tagName === 'INPUT' && /^(submit|button|reset)$/i.test(node.type);
    return true;
  }

  function registerEntry(node, attr, data) {
    const text = typeof data.original === 'string' ? data.original : '';
    const rendered = attr ? node.getAttribute(attr) || '' : node.textContent;
    const entry = {
      node: node, attr: attr, id: Number(data.id), hash: data.hash || '', original: text,
      leading: (rendered.match(/^\s*/) || [''])[0], trailing: (rendered.match(/\s*$/) || [''])[0],
      index: entries.length,
    };
    entries.push(entry);
    if (!byNode.has(node)) byNode.set(node, []);
    byNode.get(node).push(entry);
    const preview = text.replace(/\s+/g, ' ').slice(0, 70);
    addOption(fieldSelect, String(entry.index), sprintf(__('%1$s：%2$s', 'lingora'), attr ? attrLabels[attr] : __('页面文案', 'lingora'), preview));
  }

  addOption(fieldSelect, '', __('请选择高亮文案或属性', 'lingora'));
  document.querySelectorAll('.lingora-node, [data-lingora-attrs]').forEach(function (node) {
    if (panel.contains(node)) return;
    if (node.matches('.lingora-node')) {
      registerEntry(node, '', {
        id: node.getAttribute('data-lingora-id'), hash: node.getAttribute('data-lingora-hash'),
        original: node.getAttribute('data-lingora-original') || node.textContent,
      });
      node.tabIndex = 0;
      node.setAttribute('role', 'button');
      node.setAttribute('aria-controls', panel.id);
      node.setAttribute('aria-label', sprintf(__('编辑文案：%s', 'lingora'), (node.getAttribute('data-lingora-original') || node.textContent).slice(0, 100)));
    }
    if (node.hasAttribute('data-lingora-attrs')) {
      try {
        const attrs = JSON.parse(node.getAttribute('data-lingora-attrs'));
        if (Array.isArray(attrs)) attrs.forEach(function (data) {
          if (data && typeof data.attr === 'string' && safeAttribute(node, data.attr)) registerEntry(node, data.attr, data);
        });
      } catch (err) {
        // Invalid annotations never become editable fields.
      }
    }
  });
  fieldSelect.disabled = !entries.length;
  if (!entries.length) hint.textContent = __('尚无可编辑的公开文案。请先扫描公开内容，或匿名访问目标语言页面，然后刷新编辑器。', 'lingora');

  function setStatus(message, error) {
    statusEl.textContent = message || '';
    statusEl.classList.toggle('is-error', Boolean(error));
  }

  function validEntry(entry) {
    return entry && Number.isInteger(entry.id) && entry.id > 0;
  }

  function updateControls() {
    const ready = isTarget && validEntry(active) && loaded && !loading && !pending;
    saveBtn.disabled = !ready;
    aiBtn.disabled = !ready || cfg.canSendAi !== true;
    aiBtn.title = cfg.canSendAi === true ? '' : __('请先在后台配置 AI 接口并同意连接外部服务。', 'lingora');
    translatedInput.disabled = !isTarget || !validEntry(active) || !loaded || loading;
    translatedInput.readOnly = pending;
    panel.setAttribute('aria-busy', String(loading || pending));
  }

  function responseError() {
    return new Error(__('服务器返回了无效响应，请刷新后重试。', 'lingora'));
  }

  async function request(path, options) {
    const res = await fetch(cfg.restUrl + path, Object.assign({
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
    }, options || {}));
    let data;
    try { data = await res.json(); } catch (err) { throw responseError(); }
    if (!data || typeof data !== 'object' || Array.isArray(data) || !Object.keys(data).length) throw responseError();
    if (!res.ok || data.ok === false) throw new Error(data.message || sprintf(__('请求失败（%d）。', 'lingora'), res.status));
    return data;
  }

  async function fetchEntry(entry, signal) {
    const data = await request('strings/' + entry.id + '?language=' + encodeURIComponent(language), signal ? { signal: signal } : {});
    if (Number(data.id) !== entry.id || data.language !== language || typeof data.original !== 'string' ||
        (data.translated !== null && typeof data.translated !== 'string')) throw responseError();
    return data;
  }

  async function selectEntry(entry, focus) {
    sequence += 1;
    const requestSequence = sequence;
    if (loadController) loadController.abort();
    if (active) active.node.classList.remove('is-editing');
    active = entry;
    loaded = false;
    loading = false;
    originalInput.value = entry.original;
    translatedInput.value = '';
    fieldSelect.value = String(entry.index);
    entry.node.classList.add('is-editing');
    updateControls();
    if (!validEntry(entry)) {
      setStatus(__('该文案没有有效的字典 ID，无法保存。请扫描公开内容后刷新页面。', 'lingora'), true);
      return;
    }
    if (!isTarget) {
      setStatus(__('默认语言不可保存译文，请先选择目标语言。', 'lingora'));
      languageSelect.focus();
      return;
    }
    loading = true;
    updateControls();
    setStatus(__('正在读取当前语言的译文…', 'lingora'));
    loadController = new AbortController();
    try {
      const data = await fetchEntry(entry, loadController.signal);
      if (requestSequence !== sequence || active !== entry) return;
      originalInput.value = data.original;
      translatedInput.value = data.translated === null ? '' : data.translated;
      loaded = true;
      setStatus(data.status === 'human' ? __('当前为已保存的人工译文。AI 结果只提供预览，点击保存才会替换。', 'lingora')
        : (data.translated === null ? __('此语言尚无译文，请填写或使用 AI 翻译。', 'lingora') : __('已读取当前语言的译文。', 'lingora')));
      if (focus) translatedInput.focus();
    } catch (err) {
      if (requestSequence === sequence && err.name !== 'AbortError') setStatus(err.message, true);
    } finally {
      if (requestSequence === sequence) { loading = false; updateControls(); }
    }
  }

  languageSelect.addEventListener('change', function () {
    const target = targets.find(function (item) { return item.code === languageSelect.value; });
    if (!target || target.code === language) return;
    try {
      const url = new URL(target.url, window.location.href);
      if (!target.url || url.origin !== window.location.origin || !/^https?:$/.test(url.protocol)) throw responseError();
      saveBtn.disabled = aiBtn.disabled = true;
      setStatus(__('正在打开所选目标语言页面…', 'lingora'));
      window.location.assign(url.href);
    } catch (err) {
      languageSelect.value = isTarget ? language : '';
      setStatus(__('无法打开目标语言页面，请刷新后重试。', 'lingora'), true);
      updateControls();
    }
  });
  fieldSelect.addEventListener('change', function () {
    if (fieldSelect.value !== '') selectEntry(entries[Number(fieldSelect.value)], false);
  });

  function entryFromTarget(target) {
    if (!(target instanceof Element) || panel.contains(target)) return null;
    const node = target.closest('.lingora-node, [data-lingora-attrs]');
    const nodeEntries = node && byNode.get(node);
    return nodeEntries && nodeEntries[0];
  }
  document.addEventListener('click', function (event) {
    const entry = entryFromTarget(event.target);
    if (!entry) return;
    event.preventDefault();
    event.stopPropagation();
    selectEntry(entry, false);
  }, true);
  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    if (!(event.target instanceof Element) || !event.target.matches('.lingora-node')) return;
    const entry = entryFromTarget(event.target);
    if (!entry) return;
    event.preventDefault();
    event.stopPropagation();
    selectEntry(entry, true);
  }, true);

  function applyTranslation(snapshot, translated) {
    const entry = snapshot.entry;
    if (snapshot.language !== language || active !== entry || sequence !== snapshot.sequence || !entry.node.isConnected) return;
    if (snapshot.attr) {
      if (!safeAttribute(entry.node, snapshot.attr)) return;
      entry.node.setAttribute(snapshot.attr, translated);
      try {
        const attrs = JSON.parse(entry.node.getAttribute('data-lingora-attrs'));
        if (Array.isArray(attrs)) {
          attrs.forEach(function (item) { if (Number(item.id) === snapshot.id && item.attr === snapshot.attr) item.translated = translated; });
          entry.node.setAttribute('data-lingora-attrs', JSON.stringify(attrs));
        }
      } catch (err) {
        // The attribute itself was updated as plain text; do not touch the element's children.
      }
    } else {
      entry.node.textContent = entry.leading + translated.replace(/^\s+|\s+$/g, '') + entry.trailing;
    }
  }

  async function submit(ai) {
    if (!isTarget || !validEntry(active) || !loaded || loading || pending || (ai && cfg.canSendAi !== true)) return;
    const snapshot = { entry: active, id: active.id, attr: active.attr, language: language, value: translatedInput.value, sequence: sequence };
    if (!ai && !snapshot.value.trim()) return setStatus(__('译文不能为空', 'lingora'), true);
    pending = true;
    updateControls();
    setStatus(ai ? __('正在生成 AI 译文…', 'lingora') : __('正在保存译文…', 'lingora'));
    let accepted = false;
    try {
      const data = await request(ai ? 'translate' : 'strings/' + snapshot.id, {
        method: 'POST',
        body: JSON.stringify(ai ? { string_id: snapshot.id, language: snapshot.language } : { language: snapshot.language, translated: snapshot.value }),
      });
      if (data.ok !== true) throw responseError();
      if (ai && (typeof data.text !== 'string' || typeof data.saved !== 'boolean')) throw responseError();
      accepted = !ai;
      const saved = ai ? data.saved : true;
      const translated = ai ? data.text : (await fetchEntry(snapshot.entry)).translated;
      if (typeof translated !== 'string') throw responseError();
      if (saved) applyTranslation(snapshot, translated);
      if (active === snapshot.entry && sequence === snapshot.sequence) {
        translatedInput.value = translated;
        setStatus(ai ? (saved ? __('AI 译文已保存。', 'lingora') : __('AI 预览尚未保存，原人工译文未改变。点击「保存译文」后才会替换。', 'lingora')) : __('译文已保存。', 'lingora'));
      }
    } catch (err) {
      if (active === snapshot.entry && sequence === snapshot.sequence) {
        setStatus(accepted ? __('保存请求已接受，但无法重新读取译文。请刷新确认保存结果。', 'lingora') : err.message, true);
      }
    } finally {
      pending = false;
      updateControls();
    }
  }
  saveBtn.addEventListener('click', function () { submit(false); });
  aiBtn.addEventListener('click', function () { submit(true); });
  updateControls();
})();
