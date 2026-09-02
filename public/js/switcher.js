(function () {
  const cookieName = (window.lingoraSwitcher && window.lingoraSwitcher.cookie) || 'lingora_lang';

  function setCookie(name, value) {
    const maxAge = 30 * 24 * 60 * 60;
    document.cookie = encodeURIComponent(name) + '=' + encodeURIComponent(value) + '; path=/; max-age=' + maxAge + '; samesite=lax';
  }

  document.querySelectorAll('.lingora-switcher').forEach(function (root) {
    const button = root.querySelector('.lingora-switcher__current');
    const list = root.querySelector('.lingora-switcher__list');
    if (!button || !list) return;

    function close() {
      list.setAttribute('hidden', '');
      button.setAttribute('aria-expanded', 'false');
    }

    function open() {
      list.removeAttribute('hidden');
      button.setAttribute('aria-expanded', 'true');
    }

    button.addEventListener('click', function (event) {
      event.preventDefault();
      if (list.hasAttribute('hidden')) {
        open();
      } else {
        close();
      }
    });

    list.querySelectorAll('a[data-lingora-lang]').forEach(function (link) {
      link.addEventListener('click', function () {
        setCookie(cookieName, link.getAttribute('data-lingora-lang') || '');
      });
    });

    document.addEventListener('click', function (event) {
      if (!root.contains(event.target)) {
        close();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        close();
      }
    });
  });
})();
