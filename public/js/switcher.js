(function () {
  'use strict';

  const { __ } = wp.i18n;
  const cfg = window.lingoraSwitcher || {};

  function rememberLanguage(value) {
    if (cfg.remember !== true || !value) return;
    const path = typeof cfg.cookiePath === 'string' && cfg.cookiePath.startsWith('/')
      ? cfg.cookiePath.replace(/[;\r\n]/g, '') : '/';
    const domain = typeof cfg.cookieDomain === 'string' ? cfg.cookieDomain.replace(/[;\r\n]/g, '') : '';
    let cookie = encodeURIComponent(cfg.cookie || 'lingora_lang') + '=' + encodeURIComponent(value) +
      '; Path=' + path + '; Max-Age=' + (30 * 24 * 60 * 60) + '; SameSite=Lax';
    if (domain) cookie += '; Domain=' + domain;
    if (cfg.secure === true) cookie += '; Secure';
    document.cookie = cookie;
  }

  function initSwitchers() {
    document.querySelectorAll('.lingora-switcher').forEach(function (root, index) {
      const button = root.querySelector('.lingora-switcher__current');
      const list = root.querySelector('.lingora-switcher__list');
      if (!button || !list || root.dataset.lingoraReady === 'true') return;
      root.dataset.lingoraReady = 'true';
      const links = Array.from(list.querySelectorAll('a[data-lingora-lang]'));
      if (!list.id) list.id = 'lingora-language-list-' + index;
      button.setAttribute('aria-controls', list.id);
      if (!button.hasAttribute('aria-label')) button.setAttribute('aria-label', __('切换网站语言', 'lingora'));
      // 语言项是导航链接，保持原生链接语义。
      button.removeAttribute('aria-haspopup');
      list.removeAttribute('role');
      root.setAttribute('aria-label', __('网站语言', 'lingora'));

      function close(focus) {
        const wasOpen = !list.hidden;
        list.hidden = true;
        button.setAttribute('aria-expanded', 'false');
        if (focus && wasOpen) button.focus();
      }
      function open() {
        list.hidden = false;
        button.setAttribute('aria-expanded', 'true');
      }
      button.addEventListener('click', function (event) {
        event.preventDefault();
        if (list.hidden) open(); else close(false);
      });
      links.forEach(function (link) {
        link.addEventListener('click', function () { rememberLanguage(link.getAttribute('data-lingora-lang') || ''); });
      });
      root.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !list.hidden) {
          event.preventDefault();
          event.stopPropagation();
          close(true);
        } else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && links.length) {
          event.preventDefault();
          open();
          const current = links.indexOf(document.activeElement);
          const next = current < 0 ? (event.key === 'ArrowDown' ? 0 : links.length - 1)
            : (current + (event.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
          links[next].focus();
        }
      });
      root.addEventListener('focusout', function (event) {
        if (!root.contains(event.relatedTarget)) close(false);
      });
      document.addEventListener('click', function (event) {
        if (!root.contains(event.target)) close(false);
      });
      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !list.hidden) close(root.contains(document.activeElement));
      });
      close(false);
    });
  }

  if ('loading' === document.readyState) {
    document.addEventListener('DOMContentLoaded', initSwitchers, { once: true });
  } else {
    initSwitchers();
  }
})();
