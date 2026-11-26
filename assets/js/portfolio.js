/* =========================================================
   Portafolio fotográfico — carga las obras desde la API
   (api/portfolio.php). Las imágenes y textos se gestionan
   desde el panel de administración (/admin).
   ========================================================= */

var PFO = {
  photos: [],
  categories: [],
  content: null,
  filtered: [],
  index: 0,
  filter: 'todos',
  filterTimer: null,
  lbTimer: null
};

function pfoEsc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

function pfoSetText(id, value) {
  var el = document.getElementById(id);
  if (el) el.textContent = value;
}

function pfoIcons() {
  if (typeof lucide !== 'undefined' && lucide.createIcons) lucide.createIcons();
}

function pfoByFilter(category) {
  /* «Todos» muestra solo la selección destacada desde el panel; si todavía
     no se marcó ninguna, cae al listado completo para no dejar la grilla vacía. */
  if (category === 'todos') {
    var feat = PFO.photos.filter(function (p) { return !!p.featured; });
    return feat.length ? feat : PFO.photos.slice();
  }
  return PFO.photos.filter(function (p) { return p.category === category; });
}

/* --- Filtros por categoría -------------------------------------------- */
function pfoFilterClass(active) {
  var base = 'filter-btn px-3.5 py-1.5 rounded-lg text-xs font-medium tracking-wider uppercase transition-all duration-300 ';
  return active ? base + 'active bg-white text-zinc-950 shadow-sm' : base + 'text-zinc-400 hover:text-white';
}

function renderFilters() {
  var box = document.getElementById('gallery-filters');
  if (!box) return;
  box.innerHTML = [{ slug: 'todos', label: 'Todos' }].concat(PFO.categories).map(function (category) {
    var active = PFO.filter === category.slug;
    return '<button type="button" data-filter="' + pfoEsc(category.slug) + '" aria-pressed="' + active
      + '" class="' + pfoFilterClass(active) + '">' + pfoEsc(category.label) + '</button>';
  }).join('');
}

/* --- Grilla de obras (masonry) ---------------------------------------- */
/* Proporción de la tarjeta según el formato real de la imagen.
   Acotada para evitar tarjetas extremas; 4/3 si aún no hay dimensiones. */
function pfoAspect(photo) {
  var w = parseFloat(photo.w) || 0;
  var h = parseFloat(photo.h) || 0;
  if (w > 0 && h > 0) return Math.max(0.6, Math.min(1.9, w / h));
  return 4 / 3;
}

function pfoImageLoaded(img) {
  var frame = img.parentElement;
  if (!frame || !img.naturalWidth) return;
  if (img.dataset.auto === '1' && img.naturalWidth) {
    var r = img.naturalWidth / img.naturalHeight;
    frame.style.aspectRatio = Math.max(0.6, Math.min(1.9, r));
  }
  frame.classList.remove('img-skeleton');
  img.classList.add('is-loaded');
  img.dispatchEvent(new Event('pfo:image-ready'));
}

function pfoImageError(img) {
  var fallback = img.dataset.fallback;
  if (fallback) {
    delete img.dataset.fallback;
    img.src = fallback;
    return;
  }
  if (img.parentElement) img.parentElement.classList.remove('img-skeleton');
  img.classList.add('is-unavailable');
  img.dispatchEvent(new Event('pfo:image-ready'));
}

function pfoCard(photo, index) {
  var thumb = photo.thumb || photo.src;
  var title = photo.title || ('Obra ' + (index + 1));
  var label = photo.categoryLabel || photo.category || '';
  var hasDims = (parseFloat(photo.w) > 0 && parseFloat(photo.h) > 0);
  var autoAttr = hasDims ? '' : ' data-auto="1"';
  var fallbackAttr = photo.src && photo.src !== thumb ? ' data-fallback="' + pfoEsc(photo.src) + '"' : '';

  return [
    '<article onclick="openLightbox(' + index + ')"',
    ' class="gallery-card on-dark group cursor-pointer rounded-2xl overflow-hidden bg-studio-900 border border-white/[0.07] relative focus-visible:ring-2 focus-visible:ring-white/40"',
    ' tabindex="0" role="button" aria-label="Ver obra ' + pfoEsc(title) + '">',
    '  <div class="relative w-full overflow-hidden bg-studio-950 img-skeleton" style="aspect-ratio:' + pfoAspect(photo) + ';">',
    '    <img src="' + pfoEsc(thumb) + '" alt="' + pfoEsc(title) + '" loading="lazy"' + autoAttr + fallbackAttr,
    '      onload="pfoImageLoaded(this)" onerror="pfoImageError(this)"',
    '      class="gallery-img blur-up w-full h-full object-cover">',
    '    <div class="gallery-card-top absolute top-3 left-3 right-3 flex items-center justify-between gap-1 pointer-events-none z-20">',
    '      <span class="gallery-category text-[10px] font-mono uppercase tracking-widest px-2.5 py-1 rounded-md bg-black/65 backdrop-blur-md text-zinc-200 border border-white/10">' + pfoEsc(label) + '</span>',
    '      <span class="hidden lg:flex shrink-0 w-8 h-8 rounded-full bg-black/65 backdrop-blur-md border border-white/15 items-center justify-center text-white opacity-0 group-hover:opacity-100 transition-all duration-300 scale-90 group-hover:scale-100"><i data-lucide="maximize-2" class="w-3.5 h-3.5"></i></span>',
    '    </div>',
    '    <div class="gallery-info absolute inset-x-0 bottom-0 z-10 p-4 sm:p-5 bg-gradient-to-t from-black/90 via-black/70 to-transparent">',
    '      <div class="flex items-start justify-between gap-3">',
    '        <div>',
    '          <h3 class="font-serif text-xl text-white font-medium leading-snug">' + pfoEsc(title) + '</h3>',
    '          <p class="text-xs text-zinc-300 flex items-center gap-1.5 mt-1"><i data-lucide="map-pin" class="w-3 h-3 text-zinc-400 shrink-0"></i><span>' + pfoEsc(photo.location || '') + '</span></p>',
    '        </div>',
    '        <span class="text-[10px] font-mono text-zinc-400 tracking-wider uppercase shrink-0 pt-1">' + pfoEsc(photo.code || '') + '</span>',
    '      </div>',
    '      <div class="pt-3 mt-3.5 border-t border-white/15 space-y-2.5">',
    '        <div class="flex items-center text-xs font-mono text-zinc-200">',
    '          <span class="inline-flex items-center gap-1.5" title="Cámara y Óptica"><i data-lucide="camera" class="w-3.5 h-3.5 text-zinc-400"></i><span>' + pfoEsc(photo.camera || '') + '</span><span class="text-zinc-500">&middot;</span><span class="text-zinc-100">' + pfoEsc(photo.lens || '') + '</span></span>',
    '        </div>',
    '        <div class="grid grid-cols-3 gap-1.5">',
    '          <div class="px-2 py-1.5 rounded-lg bg-black/45 border border-white/10 flex items-center justify-center gap-1.5 text-[11px] font-mono text-zinc-200" title="Apertura de diafragma"><i data-lucide="aperture" class="w-3 h-3 text-zinc-400 shrink-0"></i><span>' + pfoEsc(photo.aperture || '') + '</span></div>',
    '          <div class="px-2 py-1.5 rounded-lg bg-black/45 border border-white/10 flex items-center justify-center gap-1.5 text-[11px] font-mono text-zinc-200" title="Velocidad de obturación"><i data-lucide="timer" class="w-3 h-3 text-zinc-400 shrink-0"></i><span>' + pfoEsc(photo.shutter || '') + '</span></div>',
    '          <div class="px-2 py-1.5 rounded-lg bg-black/45 border border-white/10 flex items-center justify-center gap-1.5 text-[11px] font-mono text-zinc-200" title="Sensibilidad ISO"><i data-lucide="gauge" class="w-3 h-3 text-zinc-400 shrink-0"></i><span>' + pfoEsc(photo.iso || '') + '</span></div>',
    '        </div>',
    '      </div>',
    '    </div>',
    '  </div>',
    '</article>'
  ].join('\n');
}

/* --- Revelado progresivo al hacer scroll ------------------------------ */
/* Cada tarjeta se revela cuando se aproxima al viewport, en lugar de
   aparecer todas juntas. Las que ya están visibles entran escalonadas. */
var pfoCardObserver = null;

function pfoObserveCards() {
  if (pfoCardObserver) {
    pfoCardObserver.disconnect();
    pfoCardObserver = null;
  }
  var grid = document.getElementById('gallery-grid');
  if (!grid) return;

  var cards = grid.querySelectorAll('.gallery-card');
  if (!cards.length) return;

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (reduced || !('IntersectionObserver' in window)) {
    Array.prototype.forEach.call(cards, function (card) { card.classList.add('card-in'); });
    return;
  }

  pfoCardObserver = new IntersectionObserver(function (entries, obs) {
    var visible = entries.filter(function (e) { return e.isIntersecting; });
    visible.sort(function (a, b) {
      return a.boundingClientRect.top - b.boundingClientRect.top ||
        a.boundingClientRect.left - b.boundingClientRect.left;
    });
    visible.forEach(function (entry, i) {
      obs.unobserve(entry.target);
      // Se retrasa la entrada, no solo su transición, para sincronizar el enfoque.
      if (i === 0) entry.target.classList.add('card-in');
      else setTimeout(function () {
        if (entry.target.isConnected) entry.target.classList.add('card-in');
      }, Math.min(i, 8) * 100);
    });
  }, { rootMargin: '0px 0px -6% 0px', threshold: 0.08 });

  Array.prototype.forEach.call(cards, function (c) { pfoCardObserver.observe(c); });
}

function renderGallery(filter, deferReveal) {
  var grid = document.getElementById('gallery-grid');
  var countLabel = document.getElementById('gallery-count');
  if (!grid) return;

  PFO.filtered = pfoByFilter(filter);

  if (countLabel) {
    var n = PFO.filtered.length;
    countLabel.textContent = 'Mostrando ' + n + (n === 1 ? ' fotografía' : ' fotografías');
  }

  if (!PFO.filtered.length) {
    grid.innerHTML = '<p class="text-center text-sm text-zinc-500 py-16 font-mono" style="column-span:all">Próximamente: trabajos seleccionados.</p>';
    pfoObserveCards();
    return;
  }

  grid.innerHTML = PFO.filtered.map(pfoCard).join('');
  Array.prototype.forEach.call(grid.querySelectorAll('.gallery-img'), function (img) {
    if (img.complete && img.naturalWidth) pfoImageLoaded(img);
  });
  pfoIcons();
  if (!deferReveal) pfoObserveCards();
}

function filterGallery(category) {
  if (PFO.filter === category) return;
  PFO.filter = category;
  document.querySelectorAll('#gallery-filters [data-filter]').forEach(function (btn) {
    var active = btn.dataset.filter === category;
    btn.className = pfoFilterClass(active);
    btn.setAttribute('aria-pressed', String(active));
  });

  var grid = document.getElementById('gallery-grid');
  var countLabel = document.getElementById('gallery-count');

  if (PFO.filterTimer) clearTimeout(PFO.filterTimer);
  if (grid) grid.classList.add('grid-switching');
  if (countLabel) countLabel.style.opacity = '0.3';

  PFO.filterTimer = setTimeout(function () {
    renderGallery(category);
    requestAnimationFrame(function () {
      if (grid) grid.classList.remove('grid-switching');
      if (countLabel) countLabel.style.opacity = '1';
    });
  }, 220);
}

/* --- Navegación por vistas y anclaje de contacto ----------------------- */
function pfoRoute() {
  var hash = location.hash;
  var isContact = hash === '#contacto';
  var page = isContact || hash === '#sobremi' ? 'sobremi' : 'galeria';
  document.querySelectorAll('main > .spa-view').forEach(function (section) {
    section.classList.toggle('active-view', section.id === page);
  });
  document.querySelectorAll('.nav-btn, .mob-nav-btn').forEach(function (link) {
    var active = link.hash === '#' + page;
    link.classList.toggle('active', active);
    if (link.classList.contains('nav-btn')) {
      link.classList.toggle('text-white', active);
      link.classList.toggle('text-zinc-400', !active);
    }
    if (active) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  pfoSetMenu(false);

  if (isContact) {
    var contact = document.getElementById('contacto');
    contact.focus({ preventScroll: true });
    contact.scrollIntoView({ block: 'start', behavior: 'instant' });
  } else if (hash === '#galeria' || hash === '#sobremi' || hash === '') {
    window.scrollTo({ top: 0, behavior: 'instant' });
  }
}

/* --- Lightbox ---------------------------------------------------------- */
function openLightbox(index) {
  if (!PFO.filtered.length) return;
  PFO.index = index;
  updateLightboxContent(false);

  var lb = document.getElementById('lightbox');
  if (!lb) return;
  lb.classList.remove('hidden');
  lb.classList.add('flex');
  document.body.style.overflow = 'hidden';

  requestAnimationFrame(function () {
    lb.classList.remove('opacity-0');
    lb.classList.add('opacity-100');
  });
}

function closeLightbox() {
  var lb = document.getElementById('lightbox');
  if (!lb) return;
  lb.classList.remove('opacity-100');
  lb.classList.add('opacity-0');
  document.body.style.overflow = '';

  setTimeout(function () {
    lb.classList.add('hidden');
    lb.classList.remove('flex');
  }, 280);
}

function handleLightboxBackdropClick(event) {
  if (event.target.id !== 'lb-image') {
    closeLightbox();
  }
}

function changeLightboxImage(direction) {
  var total = PFO.filtered.length;
  if (total <= 1) return;

  var imgEl = document.getElementById('lb-image');
  var metaPanel = document.getElementById('lb-meta-panel');
  if (PFO.lbTimer) clearTimeout(PFO.lbTimer);

  if (imgEl) imgEl.classList.add('lb-switching');
  if (metaPanel) metaPanel.classList.add('lb-switching');

  PFO.lbTimer = setTimeout(function () {
    PFO.index = (PFO.index + direction + total) % total;
    updateLightboxContent(true);
  }, 180);
}

function updateLightboxContent(animateEntrance) {
  var photo = PFO.filtered[PFO.index];
  if (!photo) return;

  var total = String(PFO.filtered.length).padStart(2, '0');
  var current = String(PFO.index + 1).padStart(2, '0');

  pfoSetText('lb-counter', current + ' / ' + total);
  pfoSetText('lb-category', photo.categoryLabel || '');
  pfoSetText('lb-lighting', photo.lighting || '');
  pfoSetText('lb-code', photo.code || '');

  var imgEl = document.getElementById('lb-image');
  var metaPanel = document.getElementById('lb-meta-panel');

  if (imgEl) {
    imgEl.onload = function () {
      if (animateEntrance) {
        requestAnimationFrame(function () {
          if (imgEl) imgEl.classList.remove('lb-switching');
          if (metaPanel) metaPanel.classList.remove('lb-switching');
        });
      }
    };
    imgEl.src = photo.src;
    imgEl.alt = photo.title || '';
  }

  pfoSetText('lb-title', photo.title || '');
  pfoSetText('lb-notes', photo.notes || '');

  var locEl = document.getElementById('lb-location');
  if (locEl) locEl.innerHTML = '<i data-lucide="map-pin" class="w-3 h-3 text-zinc-400"></i> <span>' + pfoEsc(photo.location || '') + '</span>';

  pfoSetText('lb-camera', photo.camera || '');
  pfoSetText('lb-lens', photo.lens || '');
  pfoSetText('lb-aperture', photo.aperture || '');
  pfoSetText('lb-shutter', photo.shutter || '');
  pfoSetText('lb-iso', photo.iso || '');

  pfoIcons();

  if (animateEntrance && imgEl && imgEl.complete) {
    requestAnimationFrame(function () {
      if (imgEl) imgEl.classList.remove('lb-switching');
      if (metaPanel) metaPanel.classList.remove('lb-switching');
    });
  }
}

document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    var menu = document.getElementById('mobile-menu');
    if (menu && menu.classList.contains('open')) { pfoSetMenu(false); return; }
  }
  var lb = document.getElementById('lightbox');
  if (!lb || lb.classList.contains('hidden')) return;
  if (e.key === 'Escape') closeLightbox();
  if (e.key === 'ArrowRight') changeLightboxImage(1);
  if (e.key === 'ArrowLeft') changeLightboxImage(-1);
});

/* --- Contenido editable (Sobre mí / Contacto) ------------------------- */
function pfoSetContent(c) {
  if (!c) return;
  PFO.content = c;

  /* ---- Marca / logo del encabezado ---- */
  var b = c.brand || {};
  pfoSetText('brand-title', b.title || '');
  pfoSetText('brand-subtitle', b.subtitle || '');
  if (b.title) {
    document.title = b.title + (b.subtitle ? ' | ' + b.subtitle : '');
    pfoSetText('footer-copy', '© ' + new Date().getFullYear() + ' ' + b.title + '. Todos los derechos reservados.');
  }
  pfoApplyBrand();

  /* ---- Sobre mí ---- */
  var a = c.about || {};
  pfoSetText('about-eyebrow', a.eyebrow || '');
  pfoSetText('about-heading', a.heading || '');

  var port = a.portrait || {};
  var pImg = document.getElementById('about-portrait');
  if (pImg) {
    if (port.src && pImg.getAttribute('src') !== port.src) pImg.src = port.src;
    pImg.alt = port.alt || '';
  }
  pfoSetText('about-portrait-role', port.role || '');
  pfoSetText('about-portrait-name', port.name || '');

  var stats = document.getElementById('about-stats');
  if (stats) {
    stats.innerHTML = (a.stats || []).map(function (s, i) {
      return '<div' + (i > 0 ? ' class="border-l border-white/[0.07]"' : '') + '>' +
        '<span class="block font-serif text-3xl sm:text-4xl text-white font-semibold">' + pfoEsc(s.value) + '</span>' +
        '<span class="text-[11px] font-mono uppercase tracking-wider text-zinc-400 mt-1 block">' + pfoEsc(s.label) + '</span>' +
        '</div>';
    }).join('');
  }

  var bio = a.bio || {};
  pfoSetText('about-bio-heading', bio.heading || '');
  pfoSetText('about-bio-quote', bio.quote || '');
  var paras = document.getElementById('about-bio-paragraphs');
  if (paras) {
    paras.innerHTML = (bio.paragraphs || []).map(function (p) { return '<p>' + pfoEsc(p) + '</p>'; }).join('');
  }

  var sp = a.specialties || {};
  pfoSetText('about-specialties-heading', sp.heading || '');
  var spBox = document.getElementById('about-specialties');
  if (spBox) {
    spBox.innerHTML = (sp.items || []).map(function (it) {
      return '<div class="p-4 rounded-xl bg-studio-900/70 border border-white/[0.05]">' +
        '<h4 class="text-white font-medium text-sm mb-1">' + pfoEsc(it.title) + '</h4>' +
        '<p class="text-xs text-zinc-400 leading-relaxed">' + pfoEsc(it.text) + '</p></div>';
    }).join('');
  }

  var g = a.gear || {};
  pfoSetText('about-gear-heading', g.heading || '');
  var gBox = document.getElementById('about-gear');
  if (gBox) {
    gBox.innerHTML = (g.items || []).map(function (it) {
      return '<div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-2">' +
        '<span class="text-zinc-400 font-mono text-xs uppercase">' + pfoEsc(it.label) + '</span>' +
        '<span class="text-zinc-100 font-medium">' + pfoEsc(it.value) + '</span></div>';
    }).join('');
  }

  var cta = a.cta || {};
  pfoSetText('about-cta-title', cta.title || '');
  pfoSetText('about-cta-text', cta.text || '');
  pfoSetText('about-cta-button', cta.button || '');

  /* ---- Contacto ---- */
  var co = c.contact || {};
  pfoSetText('contact-eyebrow', co.eyebrow || '');

  var em = co.email || {};
  pfoSetText('contact-email-label', em.label || '');
  var link = document.getElementById('contact-email-link');
  if (link) {
    link.textContent = em.value || '';
    link.href = 'mailto:' + (em.value || '');
  }

  var loc = co.location || {};
  pfoSetText('contact-location-label', loc.label || '');
  pfoSetText('contact-location-title', loc.title || '');
  pfoSetText('contact-location-note', loc.note || '');

  var de = co.delivery || {};
  pfoSetText('contact-delivery-label', de.label || '');
  var deBox = document.getElementById('contact-delivery');
  if (deBox) {
    deBox.innerHTML = (de.items || []).map(function (t) {
      return '<li class="flex items-center gap-2"><i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-400 shrink-0"></i><span>' + pfoEsc(t) + '</span></li>';
    }).join('');
  }

  var so = co.socials || {};
  pfoSetText('contact-socials-label', so.label || '');
  var soBox = document.getElementById('contact-socials');
  if (soBox) {
    soBox.innerHTML = (so.items || []).map(function (s) {
      var hasUrl = s.url && s.url !== '#';
      var href = hasUrl ? s.url : '#contacto';
      var extra = hasUrl ? ' target="_blank" rel="noopener"' : ' onclick="event.preventDefault()"';
      var tail = (s.icon === 'book-open') ? 'download' : 'arrow-up-right';
      return '<a href="' + pfoEsc(href) + '"' + extra + ' class="flex items-center justify-between gap-1.5 p-2 sm:p-3 rounded-xl bg-studio-950 border border-white/[0.05] hover:border-white/20 text-zinc-300 hover:text-white transition-all text-xs font-medium">' +
        '<span class="flex min-w-0 items-center gap-1.5"><i data-lucide="' + pfoEsc(s.icon) + '" class="w-4 h-4 shrink-0"></i><span>' + pfoEsc(s.label) + '</span></span>' +
        '<i data-lucide="' + tail + '" class="w-3.5 h-3.5 shrink-0 text-zinc-500"></i></a>';
    }).join('');
  }

  var fm = co.form || {};
  pfoSetText('contact-form-title', fm.title || '');
  pfoSetText('contact-form-text', fm.text || '');
  var select = document.getElementById('project-type');
  if (select) {
    var html = '<option value="" disabled selected>Seleccionar categoría...</option>';
    (fm.specialties || []).forEach(function (t) {
      html += '<option value="' + pfoEsc(t) + '">' + pfoEsc(t) + '</option>';
    });
    select.innerHTML = html;
  }

  pfoIcons();
}

/* --- Marca: logo día/noche y favicon ------------------------------------ */
/* El modo día usa brand.logo_day cuando existe; si no, muestra el logo de
   noche invertido (negativo) para que no desaparezca sobre fondo claro. */
function pfoIsDark() {
  return document.documentElement.classList.contains('dark');
}

function pfoApplyBrand() {
  var c = PFO.content;
  if (!c) return;
  var b = c.brand || {};
  var dark = pfoIsDark();
  var src = dark ? (b.logo || '') : (b.logo_day || b.logo || '');
  var invert = !dark && !b.logo_day && !!b.logo;

  var brandLogo = document.getElementById('brand-logo');
  var brandIcon = document.getElementById('brand-icon');
  if (brandLogo && brandIcon) {
    if (src) {
      if (brandLogo.getAttribute('src') !== src) brandLogo.src = src;
      brandLogo.alt = b.title || '';
      brandLogo.style.filter = invert ? 'invert(1)' : '';
      brandLogo.classList.remove('hidden');
      brandIcon.classList.add('hidden');
    } else {
      brandLogo.classList.add('hidden');
      brandLogo.style.filter = '';
      brandIcon.classList.remove('hidden');
      brandIcon.setAttribute('data-lucide', b.icon || 'aperture');
      pfoIcons();
    }
  }

  pfoSetFavicon(src);
}

function pfoSetFavicon(src) {
  var link = document.getElementById('pfo-favicon');
  if (!src) {
    if (link) link.parentNode.removeChild(link);
    return;
  }
  if (!link) {
    link = document.createElement('link');
    link.id = 'pfo-favicon';
    link.rel = 'icon';
    document.head.appendChild(link);
  }
  if (link.getAttribute('href') !== src) link.href = src;
}

/* --- Contacto ---------------------------------------------------------- */
function copyEmail() {
  var email = (PFO.content && PFO.content.contact && PFO.content.contact.email && PFO.content.contact.email.value) || '';
  if (!email) return;
  var tempInput = document.createElement('textarea');
  tempInput.value = email;
  document.body.appendChild(tempInput);
  tempInput.select();
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(email);
  } else {
    try { document.execCommand('copy'); } catch (e) {}
  }
  document.body.removeChild(tempInput);

  var copyText = document.getElementById('copy-email-text');
  if (copyText) {
    copyText.textContent = '¡Copiado!';
    setTimeout(function () { copyText.textContent = 'Copiar'; }, 2200);
  }
}

async function handleContactSubmit(event) {
  event.preventDefault();

  var form = event.currentTarget;
  var button = form.querySelector('button[type="submit"]');
  if (button.disabled) return;
  var label = button.querySelector('span');
  var errorEl = document.getElementById('form-error');
  var successBox = document.getElementById('form-success');
  var successDetails = document.getElementById('form-success-details');
  errorEl.classList.add('hidden');
  successBox.classList.add('hidden');
  if (!form.reportValidity()) return;

  var payload = {};
  new FormData(form).forEach(function (value, key) { payload[key] = value; });
  button.disabled = true;
  label.textContent = 'Enviando…';
  try {
    var response = await fetch(form.action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(payload)
    });
    var result = await response.json().catch(function () { return null; });
    if (!response.ok || !result || !result.ok) {
      throw new Error((result && result.error) || 'No se pudo confirmar la recepción. Inténtalo de nuevo más tarde.');
    }
    form.reset();
    successDetails.textContent = 'Gracias, ' + payload.name.trim() + '. Recibimos tu consulta y podremos responderte a ' + payload.email.trim() + '.';
    successBox.classList.remove('hidden');
  } catch (error) {
    errorEl.textContent = error instanceof TypeError
      ? 'No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.'
      : error.message;
    errorEl.classList.remove('hidden');
  } finally {
    button.disabled = false;
    label.textContent = 'Enviar Consulta';
  }
}

/* --- Tema (día / noche) ------------------------------------------------ */
function pfoSetTheme(theme) {
  var root = document.documentElement;
  if (theme === 'light') {
    root.classList.remove('dark');
  } else {
    root.classList.add('dark');
  }
  try { localStorage.setItem('f18-theme', theme); } catch (e) {}
  pfoApplyBrand(); // el logo y el favicon dependen del tema activo
}

function toggleTheme() {
  pfoSetTheme(document.documentElement.classList.contains('dark') ? 'light' : 'dark');
}

/* --- Menú móvil (hamburguesa) ------------------------------------------ */
/* El ícono (menu/x) se alterna por CSS según aria-expanded. NO se reemplaza
   el SVG con lucide.createIcons() acá: hacerlo durante un clic desconecta el
   target del evento del DOM y el listener de "cerrar al tocar fuera" cerraba
   el menú en el mismo instante (los botones parecían no hacer nada). */
function pfoSetMenu(open) {
  var menu = document.getElementById('mobile-menu');
  if (!menu) return;
  menu.classList.toggle('open', open);
  var btn = document.getElementById('menu-toggle');
  if (btn) btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function toggleMenu() {
  var menu = document.getElementById('mobile-menu');
  pfoSetMenu(!!menu && !menu.classList.contains('open'));
}

/* --- Inicialización ---------------------------------------------------- */
function pfoPreloadVisible() {
  if (!document.documentElement.classList.contains('pfo-loading')) return;
  var images = document.querySelectorAll('.active-view .gallery-img');
  var header = document.querySelector('header');
  var topEdge = header ? header.getBoundingClientRect().bottom : 0;

  return Promise.all(Array.from(images).filter(function (img) {
    var rect = img.getBoundingClientRect();
    return rect.height > 0 && rect.top < window.innerHeight && rect.bottom > topEdge;
  }).map(function (img) {
    return new Promise(function (resolve) {
      if (img.classList.contains('blur-up')) {
        if (img.matches('.is-loaded, .is-unavailable')) { resolve(); return; }
        img.addEventListener('pfo:image-ready', resolve, { once: true });
      } else {
        if (img.complete) { resolve(); return; }
        img.addEventListener('load', resolve, { once: true });
        img.addEventListener('error', resolve, { once: true });
      }
      img.fetchPriority = 'high';
      img.loading = 'eager';
    });
  }));
}

function pfoInit() {
  return fetch('api/portfolio.php', { headers: { 'Accept': 'application/json' } })
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (d) {
      PFO.photos = (d && d.photos) || [];
      PFO.categories = (d && d.categories) || [];
      pfoSetContent(d && d.content);
      renderFilters();
      renderGallery('todos', document.documentElement.classList.contains('pfo-loading'));
      return pfoPreloadVisible();
    })
    .catch(function () {
      var grid = document.getElementById('gallery-grid');
      if (grid) {
        grid.innerHTML = '<p class="text-center text-sm text-zinc-500 py-16 font-mono" style="column-span:all">No se pudo cargar el portafolio. Recargá la página.</p>';
      }
    });
}

function pfoReady() {
  pfoRoute();
  window.addEventListener('hashchange', pfoRoute);
  document.addEventListener('click', function (event) {
    var link = event.target.closest('a[href^="#"]');
    if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if (link.hash === location.hash && /^#(galeria|sobremi|contacto)$/.test(link.hash)) {
      event.preventDefault();
      pfoRoute();
    }
  });

  var filters = document.getElementById('gallery-filters');
  if (filters) filters.addEventListener('click', function (event) {
    var button = event.target.closest('[data-filter]');
    if (button) filterGallery(button.dataset.filter);
  });

  var gallery = document.getElementById('gallery-grid');
  if (gallery) gallery.addEventListener('focusin', function (event) {
    var card = event.target.closest('.gallery-card');
    if (card) card.classList.add('card-in');
  });

  var toggle = document.getElementById('theme-toggle');
  if (toggle) toggle.addEventListener('click', toggleTheme);

  var menuBtn = document.getElementById('menu-toggle');
  if (menuBtn) menuBtn.addEventListener('click', toggleMenu);

  // Cierra el menú móvil al tocar fuera de él. Se usa composedPath() (la ruta
  // del evento calculada al despacharlo) además de target.closest(): si el
  // target salió del DOM durante el clic, closest() ya no encuentra al botón.
  document.addEventListener('click', function (e) {
    var menu = document.getElementById('mobile-menu');
    if (!menu || !menu.classList.contains('open')) return;
    var path = (e.composedPath && e.composedPath()) || [];
    for (var i = 0; i < path.length; i++) {
      var n = path[i];
      if (n && (n.id === 'mobile-menu' || n.id === 'menu-toggle')) return;
    }
    if (e.target && e.target.closest && (e.target.closest('#mobile-menu') || e.target.closest('#menu-toggle'))) return;
    pfoSetMenu(false);
  });

  var root = document.documentElement;
  function finishPreload() {
    if (!root.classList.contains('pfo-loading')) {
      // El límite de emergencia del documento pudo ejecutarse primero.
      if (!pfoCardObserver) pfoObserveCards();
      return;
    }
    root.classList.remove('pfo-loading');
    var reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) pfoObserveCards();
    else setTimeout(function () { if (!pfoCardObserver) pfoObserveCards(); }, 160);
  }

  var preloadTimeout = setTimeout(finishPreload, 2500);
  pfoInit().then(function () {
    clearTimeout(preloadTimeout);
    finishPreload();
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', pfoReady);
} else {
  pfoReady();
}
