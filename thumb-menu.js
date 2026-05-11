// Right-click menu and inset-click handler for .thumb cards.
// Each .thumb carries data-back / data-front URLs and -dark flags.
(function () {
  'use strict';

  const menu = document.createElement('div');
  menu.className = 'ctx-menu';
  menu.setAttribute('role', 'menu');
  menu.style.display = 'none';
  document.body.appendChild(menu);

  const close = () => { menu.style.display = 'none'; };
  document.addEventListener('click', close);
  document.addEventListener('scroll', close, true);
  window.addEventListener('blur', close);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });

  function currentSide(thumb) {
    const main = thumb.querySelector('.main-link');
    if (!main) return null;
    return main.getAttribute('href') === thumb.dataset.front ? 'front' : 'back';
  }

  function setMain(thumb, side) {
    const back  = thumb.dataset.back;
    const front = thumb.dataset.front;
    const main  = thumb.querySelector('.main-link');
    const inset = thumb.querySelector('.inset');
    if (!main) return;
    const mainUrl  = side === 'front' ? front : back;
    const insetUrl = side === 'front' ? back  : front;
    main.href = mainUrl;
    main.title = side === 'front' ? 'Selfie — click to open' : 'Back camera — click to open';
    main.querySelector('img').src = mainUrl;
    if (inset && insetUrl) {
      inset.href = insetUrl;
      inset.title = side === 'front' ? 'Open back camera' : 'Open selfie';
      inset.querySelector('img').src = insetUrl;
    }
  }

  function buildMenu(thumb, e) {
    const back  = thumb.dataset.back  || '';
    const front = thumb.dataset.front || '';
    const taken = thumb.dataset.taken || '';
    const backDark  = thumb.dataset.backDark  === '1';
    const frontDark = thumb.dataset.frontDark === '1';
    const side = currentSide(thumb);

    const items = [];
    if (taken) items.push({type: 'label', text: taken});
    if (back  && !backDark)  items.push({text: 'Open back camera (new tab)', kbd: '↗', action: () => window.open(back,  '_blank', 'noopener')});
    if (front && !frontDark) items.push({text: 'Open selfie (new tab)',      kbd: '↗', action: () => window.open(front, '_blank', 'noopener')});
    items.push({type: 'hr'});

    if (back && front && !backDark && !frontDark) {
      items.push({
        text: side === 'back' ? 'Swap → selfie as main' : 'Swap → back as main',
        kbd: '⇄',
        action: () => setMain(thumb, side === 'back' ? 'front' : 'back'),
      });
    }
    if (back  && !backDark)  items.push({text: 'Set back camera as main',  action: () => setMain(thumb, 'back')});
    if (front && !frontDark) items.push({text: 'Set selfie as main',       action: () => setMain(thumb, 'front')});

    items.push({type: 'hr'});
    if (back)  items.push({text: 'Copy back-camera URL', action: () => copyUrl(back)});
    if (front) items.push({text: 'Copy selfie URL',      action: () => copyUrl(front)});

    menu.innerHTML = '';
    items.forEach(it => {
      if (it.type === 'hr')    { menu.appendChild(document.createElement('hr')); return; }
      if (it.type === 'label') {
        const d = document.createElement('div');
        d.className = 'label'; d.textContent = it.text;
        menu.appendChild(d); return;
      }
      const b = document.createElement('button');
      b.type = 'button';
      const lhs = document.createElement('span'); lhs.textContent = it.text; b.appendChild(lhs);
      if (it.kbd) {
        const k = document.createElement('span'); k.className = 'k'; k.textContent = it.kbd;
        b.appendChild(k);
      }
      b.addEventListener('click', ev => { ev.stopPropagation(); close(); it.action && it.action(); });
      menu.appendChild(b);
    });

    menu.style.display = 'block';
    const pad = 8;
    const w = menu.offsetWidth, h = menu.offsetHeight;
    const x = Math.min(e.clientX, window.innerWidth  - w - pad);
    const y = Math.min(e.clientY, window.innerHeight - h - pad);
    menu.style.left = Math.max(pad, x) + 'px';
    menu.style.top  = Math.max(pad, y) + 'px';
  }

  function copyUrl(rel) {
    const abs = new URL(rel, location.href).href;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(abs).catch(() => fallbackCopy(abs));
    } else {
      fallbackCopy(abs);
    }
  }
  function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (_) {}
    document.body.removeChild(ta);
  }

  // Delegate so the handler also covers thumbs added after page load.
  document.addEventListener('contextmenu', e => {
    const thumb = e.target.closest('.thumb');
    if (!thumb) return;
    e.preventDefault();
    buildMenu(thumb, e);
  });
})();
