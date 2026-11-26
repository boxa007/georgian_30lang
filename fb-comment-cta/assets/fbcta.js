/* FB Comment CTA: fills the block from n8n (cta-get), logs copy/click to cta-hit. */
(function () {
  'use strict';
  function hit(d, ev, nr, hasPost) {
    try {
      var u = d.api + '/cta-hit?e=' + ev + '&u=' + encodeURIComponent(d.url) + '&v=' + (nr || 0) + '&l=' + encodeURIComponent(d.lang) + '&s=' + encodeURIComponent(d.site) + '&p=' + (hasPost ? 1 : 0);
      if (window.fetch) { fetch(u, { method: 'GET', mode: 'no-cors', keepalive: true }).catch(function () {}); } else { (new Image()).src = u; }
    } catch (e) {}
  }
  function copyText(txt) {
    if (navigator.clipboard && window.isSecureContext) { return navigator.clipboard.writeText(txt); }
    return new Promise(function (ok, bad) {
      var ta = document.createElement('textarea'); ta.value = txt; ta.setAttribute('readonly', '');
      ta.style.position = 'fixed'; ta.style.top = '-1000px'; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy') ? ok() : bad(); } catch (e) { bad(e); } document.body.removeChild(ta);
    });
  }
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt) e.textContent = txt; return e; }
  var COPY_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M8 3h9a3 3 0 0 1 3 3v9a3 3 0 0 1-3 3H8a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3zm0 2a1 1 0 0 0-1 1v9a1 1 0 0 0 1 1h9a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1H8zM3 8a1 1 0 0 1 1 1v9a2 2 0 0 0 2 2h9a1 1 0 1 1 0 2H6a4 4 0 0 1-4-4V9a1 1 0 0 1 1-1z"/></svg>';

  function fill(box, d, r) {
    var hasPost = !!r.fb_post_url;
    var link = r.fb_post_url || r.fb_page_url || d.fbPage || 'https://www.facebook.com/';
    box.querySelector('.fbcta-title').textContent = (d.pick && d.pick.title) || r.title || '';
    box.querySelector('.fbcta-body').textContent = (d.pick && d.pick.body) || r.body || '';
    if (d.pick) r.nr = d.pick.nr;
    var list = box.querySelector('.fbcta-list');
    var comments = (r.comments || []).slice(0, 3);
    if (!comments.length) {
      ['.fbcta-hint', '.fbcta-list'].forEach(function (s) { var x = box.querySelector(s); if (x) x.style.display = 'none'; });
    }
    comments.forEach(function (c) {
      var row = el('div', 'fbcta-item'); var p = el('span', 'fbcta-text', c);
      var b = el('button', 'fbcta-copy'); b.type = 'button'; b.title = d.t.copy; b.setAttribute('aria-label', d.t.copy); b.innerHTML = COPY_SVG;
      var act = function () {
        copyText(c).then(function () {
          box.querySelectorAll('.fbcta-item').forEach(function (x) { x.classList.remove('is-copied'); });
          row.classList.add('is-copied'); row.setAttribute('data-done', d.t.copied);
        }, function () {});
        hit(d, 'copy', r.nr, hasPost);
      };
      b.addEventListener('click', act); p.addEventListener('click', act);
      row.appendChild(p); row.appendChild(b); list.appendChild(row);
    });
    var btn = box.querySelector('.fbcta-btn'); btn.href = link;
    btn.addEventListener('click', function () { hit(d, 'click', r.nr, hasPost); });
    box.classList.remove('fbcta-wait'); box.hidden = false;
  }

  function load(box) {
    var d; try { d = JSON.parse(box.getAttribute('data-fbcta')); } catch (e) { return; }
    box.removeAttribute('data-fbcta');
    /* The call-to-action text is chosen here (texts are stored on this site), evenly at random. */
    if (d.variants && d.variants.length) d.pick = d.variants[Math.floor(Math.random() * d.variants.length)];
    var ctl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctl) ctl.abort(); }, 30000);
    fetch(d.api + '/cta-get', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, signal: ctl ? ctl.signal : undefined,
      body: JSON.stringify({ url: d.url, site: d.site, lang: d.lang, title: d.title, text: d.text, ua: navigator.userAgent, nr: d.pick ? d.pick.nr : 0 })
    }).then(function (res) { return res.ok ? res.json() : null; })
      .then(function (r) { clearTimeout(timer); if (r && r.ok) fill(box, d, r); })
      .catch(function () { clearTimeout(timer); });
  }

  function place(box) {
    /* Block printed in the footer (theme without standard loop): move it right before «Missed the beginning?». */
    if (!box.hasAttribute('data-move')) return;
    var all = document.querySelectorAll('div[style*="max-width:640px;margin:40px auto 0"]');
    var target = all.length ? all[all.length - 1] : null;
    if (target && target.parentNode) { target.parentNode.insertBefore(box, target); }
    box.removeAttribute('data-move');
  }

  function init() {
    var inContent = document.querySelector('.fbcta:not([data-move])');
    document.querySelectorAll('.fbcta[data-move]').forEach(function (b) {
      if (inContent) { b.parentNode.removeChild(b); } else { place(b); inContent = b; }
    });
    var extra = document.querySelectorAll('.fbcta');
    for (var i = 1; i < extra.length; i++) { extra[i].parentNode.removeChild(extra[i]); }
    var boxes = document.querySelectorAll('.fbcta[data-fbcta]');
    if (!boxes.length) return;
    if (!('IntersectionObserver' in window)) { boxes.forEach(load); return; }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { if (en.isIntersecting) { io.unobserve(en.target); load(en.target); } });
    }, { rootMargin: '900px 0px' });
    boxes.forEach(function (b) { b.hidden = false; b.classList.add('fbcta-wait'); io.observe(b); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
