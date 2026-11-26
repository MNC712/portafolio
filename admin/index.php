<?php
/**
 * Panel de administración del portafolio.
 * Protegido por sesión: si no hay sesión activa, muestra el ingreso.
 */
require_once __DIR__ . '/../inc/bootstrap.php';

// El panel incrusta el token de sesión en el HTML: no se debe cachear.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$authed = is_logged_in();
$csrf   = $authed ? csrf_token() : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Panel · <?php echo htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="stylesheet" href="../assets/vendor/fonts.css?v=6">
    <link rel="stylesheet" href="../assets/css/admin.css?v=11">
</head>
<body>

<?php if (!$authed): ?>

    <!-- ============================ INGRESO ============================ -->
    <main class="login-wrap">
        <form id="login-form" class="login-card" autocomplete="on">
            <div class="brand">
                <span class="brand-mark">&#9678;</span>
                <div>
                    <strong>PORTAFOLIO</strong>
                    <small>Panel de administración</small>
                </div>
            </div>

            <h1>Entrá a tu estudio</h1>
            <p class="intro">Administrá las fotos y el contenido de tu portafolio.</p>
            <label>Usuario
                <input type="text" id="login-user" name="user" autocomplete="username" required>
            </label>
            <label>Contraseña
                <input type="password" id="login-pass" name="pass" autocomplete="current-password" required>
            </label>

            <p id="login-error" class="alert hidden"></p>
            <button type="submit" class="btn primary" style="width:100%">Ingresar</button>
        </form>
    </main>

<?php else: ?>

    <!-- ============================ PANEL ============================ -->
    <header class="topbar">
        <div class="brand">
            <span class="brand-mark">&#9678;</span>
            <div>
                <strong>PORTAFOLIO</strong>
                <small>Administración</small>
            </div>
        </div>

        <nav class="tabs" aria-label="Secciones del estudio">
            <button class="tab active" aria-current="page" data-tab="obras"><i aria-hidden="true">01</i>Obras</button>
            <button class="tab" data-tab="subir"><i aria-hidden="true">02</i>Subir</button>
            <button class="tab" data-tab="contenido"><i aria-hidden="true">03</i>Contenido</button>
            <button class="tab" data-tab="mensajes"><i aria-hidden="true">06</i>Mensajes</button>
            <button class="tab" data-tab="actividad"><i aria-hidden="true">04</i>Actividad</button>
            <button class="tab" data-tab="cuenta"><i aria-hidden="true">05</i>Cuenta</button>
        </nav>

        <div class="topbar-actions">
            <a href="../index.html" target="_blank" rel="noopener" class="btn ghost small">Ver sitio</a>
            <button id="logout-btn" class="btn ghost small">Salir</button>
        </div>
    </header>

    <main class="wrap">

        <!-- ---------------- Obras ---------------- -->
        <section id="panel-obras" class="panel active">
            <div class="section-head"><div><small class="kicker">01 / Archivo visual</small><h1>Biblioteca de obras</h1></div>
                <button id="goto-upload" class="btn primary">＋ Subir imágenes</button></div>
            <div class="toolbar">
                <input id="search" type="search" aria-label="Buscar obras" placeholder="Buscar por título, código o lugar…">
                <select id="filter-cat" aria-label="Filtrar por categoría"><option value="">Todas las categorías</option></select>
                <label class="check"><input id="only-featured" type="checkbox">Solo destacadas</label>
                <span id="obras-count" class="muted" role="status"></span>
            </div>

            <div id="obras-grid" class="obras-grid"></div>
            <p id="obras-empty" class="muted hidden" style="padding:40px 0;text-align:center">No hay obras que coincidan con la búsqueda.</p>
        </section>

        <!-- ---------------- Subir ---------------- -->
        <section id="panel-subir" class="panel">
            <div class="section-head"><div><small class="kicker">02 / Importación</small><h1>Subir imágenes</h1></div></div>
            <div id="dropzone" class="dropzone">
                <span class="upload-mark" aria-hidden="true">↑</span>
                <p><strong>Arrastrá las imágenes acá</strong> o <button type="button" id="pick-files" class="link">elegí archivos</button></p>
                <small class="muted">JPG, PNG, GIF o WebP · hasta 15 MB por archivo · los duplicados se omiten automáticamente</small>
                <input id="file-input" type="file" accept="image/*" multiple hidden>
            </div>
            <div id="queue" class="queue"></div>
        </section>

        <!-- ---------------- Contenido ---------------- -->
        <section id="panel-contenido" class="panel">
            <div class="section-head">
                <div><small class="kicker">03 / Sitio público</small><h1>Contenido del sitio</h1></div>
                <button id="content-save" class="btn primary small">Guardar cambios</button>
            </div>
            <p id="content-msg" class="alert hidden"></p>
            <p class="muted content-hint">Editá la <strong>marca del encabezado</strong>, <strong>Sobre mí</strong> y <strong>Contacto</strong>. Al guardar, los cambios se publican en el sitio.</p>

            <details class="content-block content-group" open>
                <summary>Identidad del estudio</summary>
                <div class="logo-grid">
                    <div class="portrait-editor logo-editor">
                        <img id="c-brand-logo-img" src="" alt="">
                        <div class="portrait-fields">
                            <label>URL del logo · modo noche<input id="c-brand-logo" placeholder="media/web/… o https://…"></label>
                            <label class="btn ghost small upload-label">Subir logo (noche)
                                <input id="c-brand-logo-file" type="file" accept="image/*" hidden>
                            </label>
                            <small id="c-brand-logo-info" class="muted"></small>
                        </div>
                    </div>
                    <div class="portrait-editor logo-editor logo-editor-day">
                        <img id="c-brand-logo-day-img" src="" alt="">
                        <div class="portrait-fields">
                            <label>URL del logo · modo día (opcional)<input id="c-brand-logo-day" placeholder="PNG pensado para fondos claros"></label>
                            <label class="btn ghost small upload-label">Subir logo (día)
                                <input id="c-brand-logo-day-file" type="file" accept="image/*" hidden>
                            </label>
                            <small id="c-brand-logo-day-info" class="muted"></small>
                            <small class="muted">Si se deja vacío, en modo día el sitio muestra el logo de noche invertido (negativo).</small>
                        </div>
                    </div>
                </div>
                <div class="row" style="margin-top:14px">
                    <label>Nombre principal<input id="c-brand-title" placeholder="Nombre del estudio"></label>
                    <label>Subtítulo<input id="c-brand-subtitle" placeholder="Estudio Visual"></label>
                </div>
                <label>Ícono Lucide (se usa si no hay logo)<input id="c-brand-icon" placeholder="aperture"></label>
            </details>

            <div class="content-groups">

                <!-- ============================ SOBRE MÍ ============================ -->
                <details class="content-group">
                    <summary>Sobre mí</summary>

                    <div class="content-block">
                        <h4 class="content-block-title">Encabezado</h4>
                        <div class="row">
                            <label>Antetítulo<input id="c-about-eyebrow" placeholder="Perfil & Trayectoria"></label>
                            <label>Título<input id="c-about-heading" placeholder="Detrás del Lente"></label>
                        </div>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Retrato</h4>
                        <div class="portrait-editor">
                            <img id="c-portrait-img" src="" alt="">
                            <div class="portrait-fields">
                                <label>URL de la imagen<input id="c-about-portrait-src" placeholder="https://… o media/web/…"></label>
                                <label class="btn ghost small upload-label">Subir imagen del retrato
                                    <input id="c-portrait-file" type="file" accept="image/*" hidden>
                                </label>
                                <small id="c-portrait-info" class="muted"></small>
                                <div class="row">
                                    <label>Cargo<input id="c-about-portrait-role" placeholder="Director de Fotografía"></label>
                                    <label>Nombre / año<input id="c-about-portrait-name" placeholder="Nombre del fotógrafo"></label>
                                </div>
                                <label>Texto alternativo<input id="c-about-portrait-alt" placeholder="Retrato del fotógrafo en estudio"></label>
                            </div>
                        </div>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Estadísticas</h4>
                        <div id="c-stats"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="stats">＋ Agregar estadística</button>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Biografía</h4>
                        <label>Título de la sección<input id="c-about-bio-heading"></label>
                        <label>Cita destacada<textarea id="c-about-bio-quote" rows="2"></textarea></label>
                        <span class="rep-label">Párrafos</span>
                        <div id="c-bio-paragraphs"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="paragraphs">＋ Agregar párrafo</button>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Áreas de especialización</h4>
                        <label>Título de la sección<input id="c-about-specialties-heading"></label>
                        <div id="c-specialties"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="specialties">＋ Agregar área</button>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Equipo técnico</h4>
                        <label>Título de la sección<input id="c-about-gear-heading"></label>
                        <div id="c-gear"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="gear">＋ Agregar equipo</button>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Llamado a la acción</h4>
                        <label>Título<input id="c-about-cta-title"></label>
                        <label>Texto<input id="c-about-cta-text"></label>
                        <label>Texto del botón<input id="c-about-cta-button"></label>
                    </div>
                </details>

                <!-- ============================ CONTACTO ============================ -->
                <details class="content-group">
                    <summary>Contacto</summary>

                    <div class="content-block">
                        <h4 class="content-block-title">Encabezado</h4>
                        <div class="row">
                            <label>Antetítulo<input id="c-contact-eyebrow" placeholder="Consultas & Reservas"></label>
                            <label>Título<input id="c-contact-heading" placeholder="Iniciar una Conversación"></label>
                        </div>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Correo directo</h4>
                        <div class="row">
                            <label>Etiqueta<input id="c-contact-email-label"></label>
                            <label>Correo<input id="c-contact-email-value" type="email"></label>
                        </div>
                        <p class="muted content-hint">Las consultas del formulario se envían a este correo y quedan guardadas en Mensajes.</p>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Ubicación y cobertura</h4>
                        <label>Etiqueta<input id="c-contact-location-label"></label>
                        <label>Ubicación<input id="c-contact-location-title"></label>
                        <label>Nota<textarea id="c-contact-location-note" rows="2"></textarea></label>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Tiempos de entrega</h4>
                        <label>Etiqueta<input id="c-contact-delivery-label"></label>
                        <div id="c-delivery"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="delivery">＋ Agregar tiempo</button>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Redes y portafolios</h4>
                        <label>Etiqueta<input id="c-contact-socials-label"></label>
                        <div id="c-socials"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="socials">＋ Agregar red</button>
                    </div>

                    <div class="content-block">
                        <h4 class="content-block-title">Formulario</h4>
                        <label>Título<input id="c-contact-form-title"></label>
                        <label>Texto de ayuda<input id="c-contact-form-text"></label>
                        <span class="rep-label">Opciones de "Especialidad requerida"</span>
                        <div id="c-form-specialties"></div>
                        <button type="button" class="btn ghost small rep-add" data-add="form-specialties">＋ Agregar opción</button>
                    </div>
                </details>

            </div>
        </section>

        <!-- ---------------- Mensajes ---------------- -->
        <section id="panel-mensajes" class="panel">
            <div class="section-head">
                <div><small class="kicker">06 / Contacto</small><h1>Bandeja de entrada</h1></div>
                <button id="reload-inbox" class="btn ghost small" type="button">Actualizar</button>
            </div>
            <p id="inbox-recipient" class="muted content-hint"></p>
            <p id="inbox-status" class="muted" role="status"></p>
            <div id="inbox-list" class="inbox-list"></div>
            <button id="inbox-more" class="btn ghost small hidden" type="button">Ver mensajes anteriores</button>
        </section>

        <!-- ---------------- Actividad ---------------- -->
        <section id="panel-actividad" class="panel">
            <div class="section-head">
                <div><small class="kicker">04 / Historial</small><h1>Registro de actividad</h1></div>
                <button id="reload-activity" class="btn ghost small">Actualizar</button>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>Fecha y hora</th><th>Acción</th><th>Detalle</th><th>IP</th></tr>
                    </thead>
                    <tbody id="activity-body">
                        <tr><td colspan="4" class="muted">Cargando…</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- ---------------- Cuenta ---------------- -->
        <section id="panel-cuenta" class="panel">
            <div class="section-head"><div><small class="kicker">05 / Acceso</small><h1>Mi cuenta</h1></div></div>
            <form id="password-form" class="card-narrow">
                <h2>Cambiar contraseña</h2>
                <label>Contraseña actual
                    <input type="password" id="pw-current" autocomplete="current-password" required>
                </label>
                <label>Nueva contraseña
                    <input type="password" id="pw-new" autocomplete="new-password" minlength="6" required>
                </label>
                <p id="pw-msg" class="alert hidden"></p>
                <button type="submit" class="btn primary">Guardar contraseña</button>
            </form>
        </section>

    </main>

    <!-- ---------------- Modal editor ---------------- -->
    <div id="editor" class="modal hidden">
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card">
            <header>
                <h3 id="editor-title">Editar obra</h3>
                <button type="button" class="icon-btn" data-close aria-label="Cerrar">&#10005;</button>
            </header>

            <div class="modal-body">
                <div class="editor-media">
                    <img id="editor-img" src="" alt="">
                    <label class="btn ghost small">Reemplazar imagen
                        <input id="editor-file" type="file" accept="image/*" hidden>
                    </label>
                    <small id="editor-media-info" class="muted"></small>
                </div>

                <div class="editor-fields">
                    <div class="row">
                        <label>Código<input id="f-code" placeholder="ARC-01"></label>
                        <label>Categoría
                            <select id="f-category"></select>
                        </label>
                    </div>
                    <label id="newcat-wrap" class="hidden">Nombre de la nueva categoría<input id="f-newcat" placeholder="Ej: Deportiva"></label>

                    <label>Título<input id="f-title" required></label>
                    <label>Ubicación<input id="f-location" placeholder="Ciudad, País"></label>

                    <label class="check">
                        <input type="checkbox" id="f-featured">
                        <span>Destacada: aparece en la selección del filtro «Todos» de la galería</span>
                    </label>

                    <details class="tech-details"><summary>Ficha técnica y notas</summary>
                    <div class="row">
                        <label>Cámara<input id="f-camera" placeholder="Sony A1"></label>
                        <label>Lente<input id="f-lens" placeholder="85mm GM"></label>
                    </div>
                    <div class="row three">
                        <label>Apertura<input id="f-aperture" placeholder="f/2.8"></label>
                        <label>Obturación<input id="f-shutter" placeholder="1/500s"></label>
                        <label>ISO<input id="f-iso" placeholder="ISO 100"></label>
                    </div>

                    <label>Iluminación<input id="f-lighting" placeholder="Hora dorada"></label>
                    <label>Notas<textarea id="f-notes" rows="3"></textarea></label>
                    </details>
                </div>
            </div>

            <footer>
                <span id="editor-msg" class="alert hidden" style="margin:0"></span>
                <button type="button" class="btn ghost" data-close>Cancelar</button>
                <button type="button" id="editor-save" class="btn primary">Guardar cambios</button>
            </footer>
        </div>
    </div>

    <div id="toast" class="toast hidden" role="status"></div>

    <script>
        window.ADMIN = {
            base: '../',
            csrf: '<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>'
        };
    </script>

<?php endif; ?>

<script src="../assets/js/admin.js?v=11"></script>
</body>
</html>
