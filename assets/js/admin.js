/* =========================================================
   Panel de administración — Portafolio
   ========================================================= */
(function () {
  'use strict';

  var BASE = (window.ADMIN && window.ADMIN.base) || '../';
  var CSRF = (window.ADMIN && window.ADMIN.csrf) || '';

  var state = {
    photos: [],
    categories: [],
    content: null,
    q: '',
    cat: '',
    editing: null,
    queue: [],
    uploading: false
  };

  /* --- Utilidades -------------------------------------------------------- */
  function $(sel, root) { return (root || document).querySelector(sel); }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function mediaUrl(p) {
    if (!p) return '';
    return /^https?:\/\//i.test(p) ? p : BASE + p;
  }
  /* Pixel transparente: evita el ícono de imagen rota en previews vacíos. */
  var EMPTY_IMG = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
  function setPreviewImg(el, url) {
    if (!el) return;
    var u = (url || '').trim();
    el.src = u ? mediaUrl(u) : EMPTY_IMG;
    el.classList.toggle('has-img', !!u);
  }
  function fmtDate(iso) {
    if (!iso) return '';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  function humanSize(bytes) {
    if (!bytes) return '';
    var kb = bytes / 1024;
    if (kb < 1024) return Math.round(kb) + ' KB';
    return (kb / 1024).toFixed(1) + ' MB';
  }

  function apiFetch(path, opts) {
    opts = opts || {};
    var headers = opts.headers || {};
    headers['Accept'] = 'application/json';
    var isForm = opts.body instanceof FormData;
    if (opts.body && !isForm) headers['Content-Type'] = 'application/json';
    if (opts.method && opts.method !== 'GET') headers['X-CSRF-Token'] = CSRF;

    return fetch(BASE + 'api/' + path, {
      method: opts.method || 'GET',
      headers: headers,
      body: opts.body,
      credentials: 'same-origin'
    }).then(function (r) {
      return r.text().then(function (t) {
        var data;
        try { data = JSON.parse(t); } catch (e) { data = { ok: false, error: 'Respuesta inválida del servidor.' }; }
        data._status = r.status;
        return data;
      });
    });
  }

  /* La sesión es la fuente de verdad del token: si el HTML quedó viejo
     (página cacheada o sesión regenerada), se pide el vigente. */
  function refreshCsrf() {
    return fetch(BASE + 'api/auth.php?action=status', {
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.authed && d.csrf) {
        CSRF = d.csrf;
        return true;
      }
      return false;
    }).catch(function () { return false; });
  }

  function api(path, opts) {
    opts = opts || {};
    return apiFetch(path, opts).then(function (d) {
      // El token embebido ya no sirve: se toma el de la sesión y se reintenta.
      if (d._status === 419 && !opts._retried) {
        return refreshCsrf().then(function (ok) {
          if (!ok) { location.reload(); return d; }
          return apiFetch(path, { method: opts.method, body: opts.body, _retried: true });
        });
      }
      return d;
    });
  }

  var toastTimer = null;
  function toast(msg, type) {
    var el = $('#toast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'toast' + (type ? ' ' + type : '');
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.add('hidden'); }, 3600);
  }

  /* --- Ingreso ----------------------------------------------------------- */
  function bindLogin() {
    var form = $('#login-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var err = $('#login-error');
      var btn = form.querySelector('button[type="submit"]');
      err.classList.add('hidden');
      btn.disabled = true;
      btn.textContent = 'Ingresando…';

      api('auth.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'login', user: $('#login-user').value, pass: $('#login-pass').value })
      }).then(function (d) {
        if (d.ok) { location.reload(); return; }
        err.textContent = d.error || 'No se pudo ingresar.';
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Ingresar';
      }).catch(function () {
        err.textContent = 'Error de conexión.';
        err.classList.remove('hidden');
        btn.disabled = false;
        btn.textContent = 'Ingresar';
      });
    });
  }

  /* --- Panel ------------------------------------------------------------- */
  function bindTabs() {
    var tabs = document.querySelectorAll('.tab');
    Array.prototype.forEach.call(tabs, function (t) {
      t.addEventListener('click', function () { showTab(t.getAttribute('data-tab')); });
    });
  }

  function showTab(name) {
    Array.prototype.forEach.call(document.querySelectorAll('.tab'), function (t) {
      var current = t.getAttribute('data-tab') === name;
      t.classList.toggle('active', current);
      if (current) t.setAttribute('aria-current', 'page');
      else t.removeAttribute('aria-current');
    });
    Array.prototype.forEach.call(document.querySelectorAll('.panel'), function (p) {
      p.classList.toggle('active', p.id === 'panel-' + name);
    });
    if (name === 'actividad') loadPhotos();
    window.scrollTo(0, 0);
    if (name === 'contenido' && !state.content) loadContent();
  }

  function bindLogout() {
    var btn = $('#logout-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
      api('auth.php', { method: 'POST', body: JSON.stringify({ action: 'logout' }) })
        .then(function () { location.reload(); });
    });
  }

  function bindToolbar() {
    var search = $('#search');
    if (search) search.addEventListener('input', function () { state.q = search.value.toLowerCase(); renderObras(); });

    var sel = $('#filter-cat');
    if (sel) sel.addEventListener('change', function () { state.cat = sel.value; renderObras(); });

    var goto = $('#goto-upload');
    $('#only-featured').addEventListener('change', renderObras);
    if (goto) goto.addEventListener('click', function () { showTab('subir'); });

    var reload = $('#reload-activity');
    if (reload) reload.addEventListener('click', function () { loadPhotos(); });
  }

  function loadPhotos() {
    return api('photos.php').then(function (d) {
      if (!d.ok) { toast(d.error || 'No se pudieron cargar las obras.', 'error'); return; }
      state.photos = d.photos || [];
      state.categories = d.categories || [];
      fillCategoryOptions();
      renderObras();
      renderActivity(d.activity || []);
    });
  }

  function fillCategoryOptions() {
    var sel = $('#filter-cat');
    if (sel) {
      var html = '<option value="">Todas las categorías</option>';
      state.categories.forEach(function (c) {
        html += '<option value="' + esc(c.slug) + '">' + esc(c.label) + ' (' + c.count + ')</option>';
      });
      sel.innerHTML = html;
      sel.value = state.cat;
    }
  }

  function renderObras() {
    var grid = $('#obras-grid');
    var empty = $('#obras-empty');
    var count = $('#obras-count');
    if (!grid) return;

    var featuredOnly = $('#only-featured').checked;
    var list = state.photos.filter(function (p) {
      if (featuredOnly && !p.featured) return false;
      if (state.cat && p.category !== state.cat) return false;
      if (!state.q) return true;
      var hay = (p.title + ' ' + p.code + ' ' + p.location + ' ' + p.categoryLabel).toLowerCase();
      return hay.indexOf(state.q) !== -1;
    });

    if (count) count.textContent = list.length + ' de ' + state.photos.length + ' obras';
    empty.textContent = state.photos.length ? 'No hay coincidencias. Probá otra búsqueda o cambiá los filtros.' : 'La biblioteca está vacía. Usá «Subir imágenes» para agregar tu primera obra.';
    empty.classList.toggle('hidden', list.length > 0);

    grid.innerHTML = list.map(obraCardHtml).join('');
  }

  function obraCardHtml(p) {
    var label = p.categoryLabel || p.category || 'Sin clasificar';
    return [
      '<article class="obra-card" data-id="' + esc(p.id) + '">',
      '  <div class="obra-thumb">',
      '    <img src="' + esc(mediaUrl(p.thumb || p.src)) + '" alt="" loading="lazy">',
      '    <span class="obra-badge">' + esc(label) + '</span>',
      p.featured ? '    <span class="obra-badge obra-badge-featured">Destacada</span>' : '',
      '  </div>',
      '  <div class="obra-info">',
      '    <h4>' + esc(p.title || 'Sin título') + '</h4>',
      '    <p>' + esc(p.code || '') + (p.location ? ' · ' + esc(p.location) : '') + '</p>',
      '  </div>',
      '  <div class="obra-actions">',
      '    <button class="btn small" data-act="up" title="Subir">&#8593;</button>',
      '    <button class="btn small" data-act="down" title="Bajar">&#8595;</button>',
      '    <button class="btn small" data-act="edit">Editar</button>',
      '    <span class="spacer"></span>',
      '    <button class="btn small danger" data-act="del">Eliminar</button>',
      '  </div>',
      '</article>'
    ].join('\n');
  }

  /* Confirmación en línea: el primer clic arma el botón ("¿Confirmar?"),
     el segundo elimina. Evita window.confirm(), que se bloquea en
     navegadores embebidos y deja el botón "sin hacer nada". */
  var deleteConfirmTimer = null;

  function resetDeleteConfirm() {
    if (deleteConfirmTimer) { clearTimeout(deleteConfirmTimer); deleteConfirmTimer = null; }
    var armed = document.querySelector('#obras-grid button[data-act="del"][data-confirm]');
    if (armed) {
      armed.removeAttribute('data-confirm');
      armed.textContent = 'Eliminar';
    }
  }

  function bindObrasGrid() {
    var grid = $('#obras-grid');
    if (!grid) return;
    grid.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-act]');
      if (!btn) return;
      var card = e.target.closest('.obra-card');
      if (!card) return;
      var id = card.getAttribute('data-id');
      var act = btn.getAttribute('data-act');

      if (act === 'edit') { resetDeleteConfirm(); openEditor(id); }
      else if (act === 'up') movePhoto(id, 'up');
      else if (act === 'down') movePhoto(id, 'down');
      else if (act === 'del') {
        if (btn.getAttribute('data-confirm') === '1') {
          resetDeleteConfirm();
          deletePhoto(id);
        } else {
          resetDeleteConfirm();
          btn.setAttribute('data-confirm', '1');
          btn.textContent = '¿Confirmar?';
          deleteConfirmTimer = setTimeout(resetDeleteConfirm, 4000);
        }
      }
    });
  }

  function findPhoto(id) {
    for (var i = 0; i < state.photos.length; i++) {
      if (state.photos[i].id === id) return state.photos[i];
    }
    return null;
  }

  function deletePhoto(id) {
    api('photos.php', { method: 'POST', body: JSON.stringify({ action: 'delete', id: id }) })
      .then(function (d) {
        if (!d.ok) { toast(d.error || 'No se pudo eliminar.', 'error'); return; }
        toast('Obra eliminada.', 'ok');
        loadPhotos();
      });
  }

  function movePhoto(id, dir) {
    api('photos.php', { method: 'POST', body: JSON.stringify({ action: 'move', id: id, dir: dir }) })
      .then(function (d) {
        if (!d.ok) { toast(d.error || 'No se pudo mover.', 'error'); return; }
        loadPhotos();
      });
  }

  /* --- Editor ------------------------------------------------------------ */
  function bindEditor() {
    var modal = $('#editor');
    if (!modal) return;

    Array.prototype.forEach.call(modal.querySelectorAll('[data-close]'), function (el) {
      el.addEventListener('click', closeEditor);
    });

    $('#f-category').addEventListener('change', function () {
      var isNew = this.value === '__new';
      $('#newcat-wrap').classList.toggle('hidden', !isNew);
      if (isNew) $('#f-newcat').focus();
    });

    $('#editor-save').addEventListener('click', saveEditor);

    $('#editor-file').addEventListener('change', function () {
      if (this.files && this.files[0] && state.editing) {
        replaceImage(state.editing.id, this.files[0]);
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !modal.classList.contains('hidden')) closeEditor();
    });
  }

  function fillCategorySelect(currentSlug, currentLabel) {
    var sel = $('#f-category');
    var html = '<option value="">— Sin clasificar —</option>';
    var found = currentSlug === '' || currentSlug == null;

    state.categories.forEach(function (c) {
      if (c.slug === currentSlug) found = true;
      html += '<option value="' + esc(c.slug) + '">' + esc(c.label) + '</option>';
    });
    if (!found) {
      html += '<option value="' + esc(currentSlug) + '">' + esc(currentLabel || currentSlug) + '</option>';
    }
    html += '<option value="__new">＋ Nueva categoría…</option>';
    sel.innerHTML = html;
    sel.value = currentSlug || '';
    $('#newcat-wrap').classList.add('hidden');
    $('#f-newcat').value = '';
  }

  /* Completa los campos de metadatos que estén vacíos (nunca pisa lo ya escrito). */
  function fillMetaFromPhoto(p) {
    if (!p) return;
    [['#f-camera', p.camera], ['#f-lens', p.lens], ['#f-aperture', p.aperture],
     ['#f-shutter', p.shutter], ['#f-iso', p.iso]].forEach(function (pair) {
      var el = $(pair[0]);
      if (el && !el.value && pair[1]) el.value = pair[1];
    });
  }

  function openEditor(id) {
    var p = findPhoto(id);
    if (!p) return;
    state.editing = p;

    fillCategorySelect(p.category, p.categoryLabel);

    $('#f-code').value = p.code || '';
    $('#f-title').value = p.title || '';
    $('#f-location').value = p.location || '';
    $('#f-camera').value = p.camera || '';
    $('#f-lens').value = p.lens || '';
    $('#f-aperture').value = p.aperture || '';
    $('#f-shutter').value = p.shutter || '';
    $('#f-iso').value = p.iso || '';
    $('#f-lighting').value = p.lighting || '';
    $('#f-notes').value = p.notes || '';
    $('#f-featured').checked = !!p.featured;

    $('.tech-details').open = false;
    $('#editor-img').alt = p.title || 'Vista previa de la obra';
    $('#editor-img').src = mediaUrl(p.src || p.thumb);
    var info = [];
    if (p.w && p.h) info.push(p.w + ' × ' + p.h + ' px');
    if (p.hash) info.push('Hash ' + String(p.hash).slice(0, 10) + '…');
    $('#editor-media-info').textContent = info.join(' · ');

    $('#editor-msg').classList.add('hidden');
    $('#editor').classList.remove('hidden');
  }

  function closeEditor() {
    $('#editor').classList.add('hidden');
    state.editing = null;
  }

  function saveEditor() {
    if (!state.editing) return;
    var sel = $('#f-category');
    var cat, label;

    if (sel.value === '__new') {
      label = $('#f-newcat').value.trim();
      cat = '';
      if (!label) {
        showEditorError('Escribí el nombre de la nueva categoría.');
        return;
      }
    } else if (sel.value === '') {
      cat = '';
      label = '';
    } else {
      cat = sel.value;
      label = sel.options[sel.selectedIndex].text;
    }

    var title = $('#f-title').value.trim();
    if (!title) {
      showEditorError('El título es obligatorio.');
      return;
    }

    var payload = {
      action: 'save',
      id: state.editing.id,
      code: $('#f-code').value,
      title: title,
      category: cat,
      categoryLabel: label,
      location: $('#f-location').value,
      camera: $('#f-camera').value,
      lens: $('#f-lens').value,
      aperture: $('#f-aperture').value,
      shutter: $('#f-shutter').value,
      iso: $('#f-iso').value,
      lighting: $('#f-lighting').value,
      notes: $('#f-notes').value,
      featured: $('#f-featured').checked
    };

    var btn = $('#editor-save');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    api('photos.php', { method: 'POST', body: JSON.stringify(payload) }).then(function (d) {
      btn.disabled = false;
      btn.textContent = 'Guardar cambios';
      if (!d.ok) { showEditorError(d.error || 'No se pudo guardar.'); return; }
      toast('Cambios guardados.', 'ok');
      closeEditor();
      loadPhotos();
    });
  }

  function showEditorError(msg) {
    var el = $('#editor-msg');
    el.textContent = msg;
    el.classList.remove('hidden');
  }

  function replaceImage(id, file) {
    var info = $('#editor-media-info');
    info.textContent = 'Subiendo y optimizando…';

    var fd = new FormData();
    fd.append('file', file);
    fd.append('id', id);

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + 'api/upload.php');
    xhr.setRequestHeader('X-CSRF-Token', CSRF);
    xhr.onload = function () {
      var d;
      try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, error: 'Respuesta inválida.' }; }
      if (xhr.status === 401) { location.reload(); return; }
      if (!d.ok) { info.textContent = d.error || 'No se pudo reemplazar.'; return; }
      $('#editor-img').src = mediaUrl(d.photo.src) + '?v=' + Date.now();
      info.textContent = d.photo.w + ' × ' + d.photo.h + ' px';
      fillMetaFromPhoto(d.photo);   // completa metadatos vacíos con el EXIF de la nueva imagen
      toast('Imagen reemplazada.', 'ok');
      loadPhotos();
    };
    xhr.onerror = function () { info.textContent = 'Error de red.'; };
    xhr.send(fd);
  }

  /* --- Subida ------------------------------------------------------------ */
  function bindUpload() {
    var dz = $('#dropzone');
    var input = $('#file-input');
    if (!dz || !input) return;

    $('#pick-files').addEventListener('click', function () { input.click(); });
    dz.addEventListener('click', function (e) { if (e.target === dz) input.click(); });

    input.addEventListener('change', function () {
      addFiles(input.files);
      input.value = '';
    });

    ['dragenter', 'dragover'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('drag'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('drag'); });
    });
    dz.addEventListener('drop', function (e) {
      if (e.dataTransfer && e.dataTransfer.files) addFiles(e.dataTransfer.files);
    });
  }

  function addFiles(fileList) {
    if (!fileList || !fileList.length) return;
    Array.prototype.forEach.call(fileList, function (f) {
      if (!/^image\//.test(f.type)) return;
      var preview = '';
      try { preview = URL.createObjectURL(f); } catch (e) {}
      state.queue.push({
        id: 'q' + Date.now() + Math.random().toString(36).slice(2, 7),
        file: f,
        preview: preview,
        status: 'pending',
        progress: 0,
        error: ''
      });
    });
    renderQueue();
    processQueue();
  }

  function renderQueue() {
    var box = $('#queue');
    if (!box) return;
    box.innerHTML = state.queue.map(queueItemHtml).join('');
  }

  function queueItemHtml(item) {
    var statusText = { pending: 'En cola', uploading: 'Subiendo…', done: 'Listo', error: item.error || 'Error', duplicate: 'Duplicado' }[item.status] || '';
    var cls = item.status === 'error' ? 'error' : (item.status === 'done' ? 'done' : (item.status === 'duplicate' ? 'duplicate' : ''));
    var thumb = item.preview || '';
    var retry = (item.status === 'error') ? '<button class="btn small" data-retry="' + item.id + '">Reintentar</button>' : '';

    return [
      '<div class="queue-item" data-qid="' + item.id + '">',
      '  <img class="queue-thumb" src="' + esc(thumb) + '" alt="">',
      '  <div>',
      '    <p class="queue-name">' + esc(item.file.name) + ' <span class="muted">' + humanSize(item.file.size) + '</span></p>',
      '    <div class="queue-bar"><span style="width:' + item.progress + '%"></span></div>',
      '  </div>',
      '  <div class="queue-status ' + cls + '">' + esc(statusText) + ' ' + retry + '</div>',
      '</div>'
    ].join('\n');
  }

  function updateQueueItem(item) {
    var el = document.querySelector('[data-qid="' + item.id + '"]');
    if (!el) { renderQueue(); return; }
    el.outerHTML = queueItemHtml(item);
  }

  function bindQueueRetry() {
    var box = $('#queue');
    if (!box) return;
    box.addEventListener('click', function (e) {
      var btn = e.target.closest('button[data-retry]');
      if (!btn) return;
      var id = btn.getAttribute('data-retry');
      state.queue.forEach(function (i) { if (i.id === id) { i.status = 'pending'; i.error = ''; i.progress = 0; } });
      updateQueueItem(state.queue.filter(function (i) { return i.id === id; })[0]);
      processQueue();
    });
  }

  function uploadOne(item) {
    return new Promise(function (resolve) {
      // Miniatura local para la cola
      try { item.preview = URL.createObjectURL(item.file); } catch (e) {}
      item.status = 'uploading';
      item.progress = 0;
      updateQueueItem(item);

      var fd = new FormData();
      fd.append('file', item.file);

      var xhr = new XMLHttpRequest();
      xhr.open('POST', BASE + 'api/upload.php');
      xhr.setRequestHeader('X-CSRF-Token', CSRF);

      xhr.upload.onprogress = function (e) {
        if (e.lengthComputable) {
          item.progress = Math.round((e.loaded / e.total) * 100);
          var bar = document.querySelector('[data-qid="' + item.id + '"] .queue-bar span');
          if (bar) bar.style.width = item.progress + '%';
        }
      };

      xhr.onload = function () {
        var d;
        try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, error: 'Respuesta inválida.' }; }
        if (xhr.status === 401) { location.reload(); return; }
        if (d.ok) { item.status = 'done'; item.progress = 100; }
        else if (d.duplicate) { item.status = 'duplicate'; item.error = d.error; }
        else { item.status = 'error'; item.error = d.error || 'Error al subir.'; }
        updateQueueItem(item);
        resolve();
      };

      xhr.onerror = function () {
        item.status = 'error';
        item.error = 'Error de red.';
        updateQueueItem(item);
        resolve();
      };

      xhr.send(fd);
    });
  }

  function processQueue() {
    if (state.uploading) return;
    var next = null;
    for (var i = 0; i < state.queue.length; i++) {
      if (state.queue[i].status === 'pending') { next = state.queue[i]; break; }
    }
    if (!next) {
      var ok = state.queue.some(function (i) { return i.status === 'done'; });
      if (ok) { toast('Subida finalizada.', 'ok'); loadPhotos(); }
      return;
    }
    state.uploading = true;
    uploadOne(next).then(function () {
      state.uploading = false;
      processQueue();
    });
  }

  /* --- Actividad --------------------------------------------------------- */
  function renderActivity(list) {
    var body = $('#activity-body');
    if (!body) return;
    if (!list.length) {
      body.innerHTML = '<tr><td colspan="4" class="muted">Sin registros todavía.</td></tr>';
      return;
    }
    body.innerHTML = list.map(function (e) {
      return '<tr><td class="mono">' + esc(fmtDate(e.time)) + '</td><td>' + esc(e.action) + '</td><td>' + esc(e.detail) + '</td><td class="mono">' + esc(e.ip || '') + '</td></tr>';
    }).join('');
  }

  /* --- Contenido (Sobre mí / Contacto) ----------------------------------- */
  // Campos simples: selector del DOM -> ruta dentro del objeto de contenido.
  var CONTENT_FIELDS = {
    '#c-brand-title':              'brand.title',
    '#c-brand-subtitle':           'brand.subtitle',
    '#c-brand-icon':               'brand.icon',
    '#c-brand-logo':               'brand.logo',
    '#c-brand-logo-day':           'brand.logo_day',
    '#c-about-eyebrow':            'about.eyebrow',
    '#c-about-heading':            'about.heading',
    '#c-about-portrait-src':       'about.portrait.src',
    '#c-about-portrait-role':      'about.portrait.role',
    '#c-about-portrait-name':      'about.portrait.name',
    '#c-about-portrait-alt':       'about.portrait.alt',
    '#c-about-bio-heading':        'about.bio.heading',
    '#c-about-bio-quote':          'about.bio.quote',
    '#c-about-specialties-heading':'about.specialties.heading',
    '#c-about-gear-heading':       'about.gear.heading',
    '#c-about-cta-title':          'about.cta.title',
    '#c-about-cta-text':           'about.cta.text',
    '#c-about-cta-button':         'about.cta.button',
    '#c-contact-eyebrow':          'contact.eyebrow',
    '#c-contact-heading':          'contact.heading',
    '#c-contact-email-label':      'contact.email.label',
    '#c-contact-email-value':      'contact.email.value',
    '#c-contact-location-label':   'contact.location.label',
    '#c-contact-location-title':   'contact.location.title',
    '#c-contact-location-note':    'contact.location.note',
    '#c-contact-delivery-label':   'contact.delivery.label',
    '#c-contact-socials-label':    'contact.socials.label',
    '#c-contact-form-title':       'contact.form.title',
    '#c-contact-form-text':        'contact.form.text'
  };

  // Listas repetibles: contenedor, tipo y campos de cada fila.
  var REPEATS = {
    'stats':            { box: '#c-stats',             kind: 'obj',  cls: 'cols-2', fields: [['value', 'Valor'], ['label', 'Etiqueta']] },
    'paragraphs':       { box: '#c-bio-paragraphs',    kind: 'text', cls: 'single', ph: 'Escribí un párrafo…' },
    'specialties':      { box: '#c-specialties',       kind: 'obj',  cls: 'cols-title', fields: [['title', 'Título'], ['text', 'Descripción', 'area']] },
    'gear':             { box: '#c-gear',              kind: 'obj',  cls: 'cols-2', fields: [['label', 'Categoría'], ['value', 'Detalle']] },
    'delivery':         { box: '#c-delivery',          kind: 'text', cls: 'single', ph: 'Tiempo de entrega…' },
    'socials':          { box: '#c-socials',           kind: 'obj',  cls: 'cols-3', fields: [['icon', 'Ícono'], ['label', 'Nombre'], ['url', 'URL']] },
    'form-specialties': { box: '#c-form-specialties',  kind: 'text', cls: 'single', ph: 'Opción del formulario…' }
  };

  function getPath(obj, path) {
    var parts = path.split('.');
    var cur = obj;
    for (var i = 0; i < parts.length; i++) {
      if (cur == null || typeof cur !== 'object') return '';
      cur = cur[parts[i]];
    }
    return cur == null ? '' : cur;
  }

  function loadContent() {
    return api('content.php').then(function (d) {
      if (!d.ok) { toast(d.error || 'No se pudo cargar el contenido.', 'error'); return; }
      state.content = d.content;
      renderContent();
    });
  }

  function renderContent() {
    var c = state.content;
    if (!c) return;

    Object.keys(CONTENT_FIELDS).forEach(function (sel) {
      var el = $(sel);
      if (el) el.value = getPath(c, CONTENT_FIELDS[sel]);
    });

    var brand = c.brand || {};
    setPreviewImg($('#c-brand-logo-img'), brand.logo);
    $('#c-brand-logo-info').textContent = '';
    setPreviewImg($('#c-brand-logo-day-img'), brand.logo_day);
    $('#c-brand-logo-day-info').textContent = '';

    setPreviewImg($('#c-portrait-img'), c.about.portrait.src);
    $('#c-portrait-info').textContent = '';

    renderRepeat('stats', c.about.stats);
    renderRepeat('paragraphs', c.about.bio.paragraphs);
    renderRepeat('specialties', c.about.specialties.items);
    renderRepeat('gear', c.about.gear.items);
    renderRepeat('delivery', c.contact.delivery.items);
    renderRepeat('socials', c.contact.socials.items);
    renderRepeat('form-specialties', c.contact.form.specialties);
  }

  function repRowHtml(name, row) {
    var cfg = REPEATS[name];
    var html = '<div class="rep-row ' + cfg.cls + '" data-name="' + name + '">';
    if (cfg.kind === 'text') {
      html += '<textarea data-part="value" rows="1" placeholder="' + esc(cfg.ph) + '">' + esc(row || '') + '</textarea>';
    } else {
      cfg.fields.forEach(function (f) {
        if (f[2] === 'area') {
          html += '<textarea data-part="' + f[0] + '" rows="2" placeholder="' + esc(f[1]) + '">' + esc(row[f[0]] || '') + '</textarea>';
        } else {
          html += '<input data-part="' + f[0] + '" placeholder="' + esc(f[1]) + '" value="' + esc(row[f[0]] || '') + '">';
        }
      });
    }
    html += '<button type="button" class="icon-btn rep-del" title="Eliminar" aria-label="Eliminar">&#10005;</button>';
    html += '</div>';
    return html;
  }

  function renderRepeat(name, rows) {
    var box = $(REPEATS[name].box);
    if (!box) return;
    box.innerHTML = (rows || []).map(function (r) { return repRowHtml(name, r); }).join('');
  }

  function addRepeatRow(name) {
    var cfg = REPEATS[name];
    var box = $(cfg.box);
    if (!box) return;
    var tmp = document.createElement('div');
    tmp.innerHTML = repRowHtml(name, cfg.kind === 'text' ? '' : {});
    var rowEl = tmp.firstChild;
    box.appendChild(rowEl);
    var focusEl = rowEl.querySelector('input, textarea');
    if (focusEl) focusEl.focus();
  }

  function collectRepeat(name) {
    var cfg = REPEATS[name];
    var box = $(cfg.box);
    if (!box) return [];
    var out = [];
    Array.prototype.forEach.call(box.querySelectorAll('.rep-row'), function (rowEl) {
      if (cfg.kind === 'text') {
        var v = rowEl.querySelector('[data-part="value"]').value.trim();
        if (v) out.push(v);
        return;
      }
      var obj = {};
      var empty = true;
      cfg.fields.forEach(function (f) {
        var el = rowEl.querySelector('[data-part="' + f[0] + '"]');
        var val = el ? el.value.trim() : '';
        obj[f[0]] = val;
        if (val) empty = false;
      });
      if (!empty) out.push(obj);
    });
    return out;
  }

  function collectContent() {
    var data = {};
    Object.keys(CONTENT_FIELDS).forEach(function (sel) {
      var el = $(sel);
      if (!el) return;
      var parts = CONTENT_FIELDS[sel].split('.');
      var cur = data;
      for (var i = 0; i < parts.length - 1; i++) {
        if (!cur[parts[i]]) cur[parts[i]] = {};
        cur = cur[parts[i]];
      }
      cur[parts[parts.length - 1]] = el.value;
    });

    data.about.stats = collectRepeat('stats');
    data.about.bio.paragraphs = collectRepeat('paragraphs');
    data.about.specialties.items = collectRepeat('specialties');
    data.about.gear.items = collectRepeat('gear');
    data.contact.delivery.items = collectRepeat('delivery');
    data.contact.socials.items = collectRepeat('socials');
    data.contact.form.specialties = collectRepeat('form-specialties');
    return data;
  }

  function saveContent() {
    var btn = $('#content-save');
    var msg = $('#content-msg');
    msg.classList.add('hidden');
    btn.disabled = true;
    btn.textContent = 'Guardando…';

    api('content.php', { method: 'POST', body: JSON.stringify({ action: 'save', content: collectContent() }) })
      .then(function (d) {
        btn.disabled = false;
        btn.textContent = 'Guardar cambios';
        if (!d.ok) {
          msg.textContent = d.error || 'No se pudo guardar.';
          msg.className = 'alert';
          msg.classList.remove('hidden');
          return;
        }
        state.content = d.content;
        renderContent();
        toast('Contenido guardado.', 'ok');
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Guardar cambios';
        toast('Error de conexión.', 'error');
      });
  }

  function uploadPortrait(file) {
    var info = $('#c-portrait-info');
    info.textContent = 'Subiendo y optimizando…';

    var fd = new FormData();
    fd.append('file', file);
    fd.append('action', 'upload_portrait');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + 'api/content.php');
    xhr.setRequestHeader('X-CSRF-Token', CSRF);
    xhr.onload = function () {
      var d;
      try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, error: 'Respuesta inválida.' }; }
      if (xhr.status === 401) { location.reload(); return; }
      if (!d.ok) { info.textContent = d.error || 'No se pudo subir.'; return; }
      $('#c-about-portrait-src').value = d.src;
      setPreviewImg($('#c-portrait-img'), d.src + '?v=' + Date.now());
      info.textContent = d.w + ' × ' + d.h + ' px';
      toast('Retrato actualizado.', 'ok');
    };
    xhr.onerror = function () { info.textContent = 'Error de red.'; };
    xhr.send(fd);
  }

  function uploadLogo(file) {
    var info = $('#c-brand-logo-info');
    info.textContent = 'Subiendo y optimizando…';

    var fd = new FormData();
    fd.append('file', file);
    fd.append('action', 'upload_logo');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + 'api/content.php');
    xhr.setRequestHeader('X-CSRF-Token', CSRF);
    xhr.onload = function () {
      var d;
      try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, error: 'Respuesta inválida.' }; }
      if (xhr.status === 401) { location.reload(); return; }
      if (!d.ok) { info.textContent = d.error || 'No se pudo subir.'; return; }
      $('#c-brand-logo').value = d.src;
      setPreviewImg($('#c-brand-logo-img'), d.src + '?v=' + Date.now());
      info.textContent = d.w + ' × ' + d.h + ' px';
      toast('Logo actualizado.', 'ok');
    };
    xhr.onerror = function () { info.textContent = 'Error de red.'; };
    xhr.send(fd);
  }

  function uploadLogoDay(file) {
    var info = $('#c-brand-logo-day-info');
    info.textContent = 'Subiendo y optimizando…';

    var fd = new FormData();
    fd.append('file', file);
    fd.append('action', 'upload_logo_day');

    var xhr = new XMLHttpRequest();
    xhr.open('POST', BASE + 'api/content.php');
    xhr.setRequestHeader('X-CSRF-Token', CSRF);
    xhr.onload = function () {
      var d;
      try { d = JSON.parse(xhr.responseText); } catch (e) { d = { ok: false, error: 'Respuesta inválida.' }; }
      if (xhr.status === 401) { location.reload(); return; }
      if (!d.ok) { info.textContent = d.error || 'No se pudo subir.'; return; }
      $('#c-brand-logo-day').value = d.src;
      setPreviewImg($('#c-brand-logo-day-img'), d.src + '?v=' + Date.now());
      info.textContent = d.w + ' × ' + d.h + ' px';
      toast('Logo de modo día actualizado.', 'ok');
    };
    xhr.onerror = function () { info.textContent = 'Error de red.'; };
    xhr.send(fd);
  }

  function bindContent() {
    var panel = $('#panel-contenido');
    if (!panel) return;

    panel.addEventListener('click', function (e) {
      var del = e.target.closest('.rep-del');
      if (del) {
        var row = del.closest('.rep-row');
        if (row) row.remove();
        return;
      }
      var add = e.target.closest('[data-add]');
      if (add) addRepeatRow(add.getAttribute('data-add'));
    });

    var save = $('#content-save');
    if (save) save.addEventListener('click', saveContent);

    var file = $('#c-portrait-file');
    if (file) file.addEventListener('change', function () {
      if (this.files && this.files[0]) uploadPortrait(this.files[0]);
      this.value = '';
    });

    var src = $('#c-about-portrait-src');
    if (src) src.addEventListener('input', function () {
      setPreviewImg($('#c-portrait-img'), this.value);
    });

    var logoFile = $('#c-brand-logo-file');
    if (logoFile) logoFile.addEventListener('change', function () {
      if (this.files && this.files[0]) uploadLogo(this.files[0]);
      this.value = '';
    });

    var logoUrl = $('#c-brand-logo');
    if (logoUrl) logoUrl.addEventListener('input', function () {
      setPreviewImg($('#c-brand-logo-img'), this.value);
    });

    var logoDayFile = $('#c-brand-logo-day-file');
    if (logoDayFile) logoDayFile.addEventListener('change', function () {
      if (this.files && this.files[0]) uploadLogoDay(this.files[0]);
      this.value = '';
    });

    var logoDayUrl = $('#c-brand-logo-day');
    if (logoDayUrl) logoDayUrl.addEventListener('input', function () {
      setPreviewImg($('#c-brand-logo-day-img'), this.value);
    });
  }

  /* --- Cuenta ------------------------------------------------------------ */
  function bindAccount() {
    var form = $('#password-form');
    if (!form) return;
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var msg = $('#pw-msg');
      msg.classList.add('hidden');

      api('auth.php', {
        method: 'POST',
        body: JSON.stringify({ action: 'change_password', current: $('#pw-current').value, new: $('#pw-new').value })
      }).then(function (d) {
        if (!d.ok) {
          msg.textContent = d.error || 'No se pudo cambiar la contraseña.';
          msg.className = 'alert';
          msg.classList.remove('hidden');
          return;
        }
        msg.textContent = 'Contraseña actualizada correctamente.';
        msg.className = 'alert ok';
        msg.classList.remove('hidden');
        form.reset();
      });
    });
  }

  /* --- Arranque ---------------------------------------------------------- */
  function boot() {
    bindTabs();
    bindToolbar();
    bindObrasGrid();
    bindEditor();
    bindUpload();
    bindQueueRetry();
    bindContent();
    bindLogout();
    bindAccount();
    refreshCsrf(); // toma el token vigente de la sesión antes de operar
    loadPhotos();
  }

  if (document.getElementById('login-form')) {
    bindLogin();
  } else if (document.getElementById('obras-grid')) {
    boot();
  }
})();
