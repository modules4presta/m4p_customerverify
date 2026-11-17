/**
 * m4p_customerverify
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */
(function () {
  'use strict';

  function place(article) {
    var content = document.querySelector('#wrapper > .container');
    if (content) {
      content.insertBefore(article, content.firstChild);
      return;
    }
    container().appendChild(article);
  }

  function container() {
    var existing = document.querySelector('#notifications .container');
    if (existing) {
      return existing;
    }
    var aside = document.createElement('aside');
    aside.id = 'notifications';
    var inner = document.createElement('div');
    inner.className = 'container';
    aside.appendChild(inner);
    var anchor = document.getElementById('wrapper') || document.querySelector('main') || document.body;
    anchor.insertBefore(aside, anchor.firstChild);

    return inner;
  }

  function show(notices) {
    if (!notices.length) {
      return;
    }
    var article = document.createElement('article');
    article.className = 'alert alert-success m4p-cv-notice';
    article.setAttribute('role', 'alert');
    article.setAttribute('data-alert', 'success');
    var list = document.createElement('ul');
    notices.forEach(function (text) {
      var item = document.createElement('li');
      item.textContent = text;
      list.appendChild(item);
    });
    article.appendChild(list);
    place(article);
  }

  function load() {
    var shop = window.prestashop;
    if (!shop || !shop.customer || !shop.customer.is_logged || !window.m4pCvNoticeUrl) {
      return;
    }
    var body = new URLSearchParams({ ajax: '1', token: shop.static_token || '' });
    fetch(window.m4pCvNoticeUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { Accept: 'application/json' } })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        show(Array.isArray(data.notices) ? data.notices : []);
      })
      .catch(function (error) {
        console.warn('m4p_customerverify: notices not loaded', error);
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', load);
  } else {
    load();
  }
})();
