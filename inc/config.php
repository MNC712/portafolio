<?php
/**
 * Configuración general del portafolio.
 * El proyecto es autocontenido: funciona dentro de su propia carpeta,
 * sin rutas absolutas ni conexiones a otros sistemas.
 */

// Credenciales iniciales del panel. Se guardan con hash en data/admin.json.
// IMPORTANTE: cambiá la contraseña desde el panel apenas ingreses por primera vez.
define('ADMIN_DEFAULT_USER', 'admin');
define('ADMIN_DEFAULT_PASS', 'mateo2026');

// Nombre visible en el panel de administración.
define('APP_NAME', 'Portafolio');

// Contacto: se usa mail() con el transporte de correo configurado en PHP.
// F18_CONTACT_FROM (opcional) debe ser un remitente autorizado por el hosting.
// Si no se define, el transporte de PHP debe tener un remitente predeterminado.

// Tamaño máximo por archivo subido (bytes). 15 MB.
define('MAX_UPLOAD_BYTES', 15 * 1024 * 1024);

// Procesamiento de imágenes (genera versiones optimizadas WebP).
define('WEB_MAX_WIDTH', 1920);   // ancho máximo de la versión para el lightbox
define('THUMB_MAX_WIDTH', 800);   // ancho máximo de la miniatura para la grilla
define('IMG_QUALITY', 82);
