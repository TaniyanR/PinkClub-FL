(function () {
  'use strict';

  var modal = null;
  var mainImage = null;
  var thumbs = null;
  var titleNode = null;
  var statusNode = null;
  var previousButton = null;
  var nextButton = null;
  var images = [];
  var activeIndex = 0;
  var returnFocus = null;

  function cardFromElement(element) {
    return element ? element.closest('.pcf-dm-card, .rail-card, article') : null;
  }

  function itemLinkFromCard(card) {
    if (!card) return null;
    return card.querySelector('a[href*="item.php?id="]');
  }

  function itemIdFromCard(card) {
    var link = itemLinkFromCard(card);
    if (!link) return '';
    try {
      var url = new URL(link.href, window.location.href);
      var id = url.searchParams.get('id') || '';
      return /^\d+$/.test(id) ? id : '';
    } catch (_) {
      return '';
    }
  }

  function endpointFromItemLink(card, fileName, itemId) {
    var link = itemLinkFromCard(card);
    if (!link || !itemId) return '';
    try {
      var url = new URL(link.href, window.location.href);
      var basePath = url.pathname.replace(/\/item\.php$/i, '/');
      url.pathname = basePath + fileName;
      url.search = '';
      url.hash = '';
      url.searchParams.set('id', itemId);
      return url.toString();
    } catch (_) {
      return '';
    }
  }

  function normalizeVrCards() {
    var vrPattern = /(?:【|\[|［)?\s*VR\s*(?:】|\]|］)?/i;
    document.querySelectorAll('.pcf-dm-card, .rail-card').forEach(function (card) {
      var titleNodeInCard = card.querySelector('.pcf-dm-card__title, .rail-card__title, h2, h3, h4');
      var title = (titleNodeInCard ? titleNodeInCard.textContent : '').trim();
      if (!vrPattern.test(title)) return;

      var itemId = itemIdFromCard(card);
      if (!itemId) return;

      var href = endpointFromItemLink(card, 'vr_affiliate.php', itemId);
      if (!href) return;

      var controls = Array.prototype.slice.call(card.querySelectorAll('button, span, a'));
      var source = controls.find(function (node) {
        var text = (node.textContent || '').trim();
        return text === 'サンプル動画' || text === '元サイトで見る' || text === '元サイトを見る';
      });
      if (!source) return;

      var link = document.createElement('a');
      link.className = (source.className || '')
        .replace(/\bis-disabled\b/g, '')
        .replace(/\bsample-button--disabled\b/g, '')
        .trim();
      if (link.className.indexOf('sample-button') !== -1 && link.className.indexOf('sample-button--enabled') === -1) {
        link.className += ' sample-button--enabled';
      }
      link.href = href;
      link.target = '_blank';
      link.rel = 'noopener noreferrer sponsored';
      link.textContent = '元サイトで見る';
      link.setAttribute('aria-label', title + 'を元サイトで見る');
      link.style.display = 'flex';
      link.style.alignItems = 'center';
      link.style.justifyContent = 'center';
      link.style.textDecoration = 'none';
      source.replaceWith(link);
    });
  }

  function ensureStylesheet() {
    if (document.querySelector('link[data-pcf-sample-image-modal]')) return;
    var cssUrl = document.documentElement.getAttribute('data-sample-image-modal-css');
    if (!cssUrl) return;
    var link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = cssUrl;
    link.setAttribute('data-pcf-sample-image-modal', '1');
    document.head.appendChild(link);
  }

  function buildModal() {
    if (modal) return;
    ensureStylesheet();
    modal = document.createElement('div');
    modal.className = 'sample-image-modal';
    modal.setAttribute('aria-hidden', 'true');
    modal.innerHTML =
      '<div class="sample-image-modal__backdrop" data-sample-image-close="1"></div>' +
      '<section class="sample-image-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="sample-image-modal-title">' +
        '<header class="sample-image-modal__header">' +
          '<h2 id="sample-image-modal-title" class="sample-image-modal__title">サンプル画像</h2>' +
          '<button type="button" class="sample-image-modal__close" data-sample-image-close="1" aria-label="閉じる">×</button>' +
        '</header>' +
        '<div class="sample-image-modal__stage">' +
          '<button type="button" class="sample-image-modal__arrow sample-image-modal__arrow--prev" aria-label="前の画像">‹</button>' +
          '<img class="sample-image-modal__main" src="" alt="">' +
          '<button type="button" class="sample-image-modal__arrow sample-image-modal__arrow--next" aria-label="次の画像">›</button>' +
          '<p class="sample-image-modal__status" role="status"></p>' +
        '</div>' +
        '<div class="sample-image-modal__thumbs" aria-label="サンプル画像一覧"></div>' +
      '</section>';
    document.body.appendChild(modal);

    mainImage = modal.querySelector('.sample-image-modal__main');
    thumbs = modal.querySelector('.sample-image-modal__thumbs');
    titleNode = modal.querySelector('.sample-image-modal__title');
    statusNode = modal.querySelector('.sample-image-modal__status');
    previousButton = modal.querySelector('.sample-image-modal__arrow--prev');
    nextButton = modal.querySelector('.sample-image-modal__arrow--next');

    modal.addEventListener('click', function (event) {
      if (event.target.closest('[data-sample-image-close="1"]')) closeModal();
    });
    previousButton.addEventListener('click', function () { showImage(activeIndex - 1); });
    nextButton.addEventListener('click', function () { showImage(activeIndex + 1); });
  }

  function showImage(index) {
    if (!images.length) return;
    activeIndex = (index + images.length) % images.length;
    mainImage.src = images[activeIndex];
    mainImage.alt = 'サンプル画像 ' + (activeIndex + 1) + ' / ' + images.length;
    statusNode.textContent = (activeIndex + 1) + ' / ' + images.length;
    previousButton.hidden = images.length < 2;
    nextButton.hidden = images.length < 2;
    Array.prototype.forEach.call(thumbs.querySelectorAll('button'), function (button, buttonIndex) {
      button.classList.toggle('is-active', buttonIndex === activeIndex);
      button.setAttribute('aria-current', buttonIndex === activeIndex ? 'true' : 'false');
    });
  }

  function renderThumbs() {
    thumbs.innerHTML = '';
    images.forEach(function (url, index) {
      var button = document.createElement('button');
      var image = document.createElement('img');
      button.type = 'button';
      button.className = 'sample-image-modal__thumb';
      button.setAttribute('aria-label', '画像 ' + (index + 1) + ' を表示');
      image.src = url;
      image.alt = '';
      image.loading = 'lazy';
      button.appendChild(image);
      button.addEventListener('click', function () { showImage(index); });
      thumbs.appendChild(button);
    });
  }

  function sampleRequestUrl(trigger) {
    var card = cardFromElement(trigger);
    var itemId = itemIdFromCard(card);
    if (!itemId) return '';

    var link = itemLinkFromCard(card);
    if (!link) return '';
    try {
      var url = new URL(link.href, window.location.href);
      var basePath = url.pathname.replace(/\/item\.php$/i, '/');
      url.pathname = basePath + 'sample_images.php';
      url.search = '';
      url.hash = '';
      url.searchParams.set('item_id', itemId);
      url.searchParams.set('format', 'json');
      return url.toString();
    } catch (_) {
      return '';
    }
  }

  function openModal(trigger) {
    var requestUrl = sampleRequestUrl(trigger);
    if (!requestUrl) return;

    buildModal();
    returnFocus = trigger;
    images = [];
    thumbs.innerHTML = '';
    mainImage.removeAttribute('src');
    titleNode.textContent = trigger.dataset.sampleImagesTitle || 'サンプル画像';
    statusNode.textContent = '画像を読み込んでいます…';
    previousButton.hidden = true;
    nextButton.hidden = true;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('sample-image-modal-open');
    modal.querySelector('.sample-image-modal__close').focus();

    fetch(requestUrl, {
      credentials: 'same-origin',
      cache: 'no-store',
      headers: { Accept: 'application/json' }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('sample image request failed');
        return response.json();
      })
      .then(function (payload) {
        images = Array.isArray(payload.images) ? payload.images.map(function (url) {
          var value = String(url || '').trim();
          if (value.indexOf('//') === 0) return 'https:' + value;
          return value;
        }).filter(function (url) { return /^https?:\/\//i.test(url); }) : [];

        titleNode.textContent = payload.title || trigger.dataset.sampleImagesTitle || 'サンプル画像';
        if (!images.length) {
          statusNode.textContent = '表示できるサンプル画像がありません。';
          return;
        }
        renderThumbs();
        showImage(0);
      })
      .catch(function () {
        statusNode.textContent = 'サンプル画像を読み込めませんでした。';
      });
  }

  function closeModal() {
    if (!modal) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('sample-image-modal-open');
    mainImage.removeAttribute('src');
    if (returnFocus) returnFocus.focus();
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest('.sample-image-trigger');
    if (!trigger || trigger.disabled || trigger.classList.contains('is-disabled')) return;
    event.preventDefault();
    openModal(trigger);
  });

  document.addEventListener('keydown', function (event) {
    if (!modal || !modal.classList.contains('is-open')) return;
    if (event.key === 'Escape') closeModal();
    if (event.key === 'ArrowLeft') showImage(activeIndex - 1);
    if (event.key === 'ArrowRight') showImage(activeIndex + 1);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', normalizeVrCards);
  } else {
    normalizeVrCards();
  }
}());
