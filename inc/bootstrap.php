<?php
/**
 * Núcleo del portafolio: rutas, almacenamiento JSON con bloqueo,
 * autenticación por sesión, registro de actividad y procesamiento de imágenes.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');   // nunca imprimir errores: romperían las respuestas JSON
ini_set('log_errors', '1');

// --- Rutas -----------------------------------------------------------------
define('APP_ROOT', dirname(__DIR__));
define('INC_DIR', APP_ROOT . '/inc');
define('DATA_DIR', APP_ROOT . '/data');
define('MEDIA_DIR', APP_ROOT . '/media');
define('PHOTOS_FILE', DATA_DIR . '/photos.json');
define('CONTENT_FILE', DATA_DIR . '/content.json');
define('ADMIN_FILE', DATA_DIR . '/admin.json');
define('LOG_FILE', DATA_DIR . '/activity.log');

require_once INC_DIR . '/config.php';

// --- Sesión ----------------------------------------------------------------
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(array(
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        ));
    } else {
        session_set_cookie_params(0, '/', '', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), true);
    }
    session_name('f18sess');
    session_start();
}

app_ensure_dirs();

// ===========================================================================
//  Estructura de carpetas
// ===========================================================================
function app_ensure_dirs()
{
    $dirs = array(
        DATA_DIR,
        MEDIA_DIR,
        MEDIA_DIR . '/web',
        MEDIA_DIR . '/thumbs',
        MEDIA_DIR . '/originals',
    );
    foreach ($dirs as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0755, true);
        }
    }
}

// ===========================================================================
//  Almacenamiento JSON con bloqueo de archivo
// ===========================================================================
function storage_read($file, $default = array())
{
    if (!is_file($file)) {
        return $default;
    }
    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return $default;
    }
    $raw = '';
    if (flock($fp, LOCK_SH)) {
        $raw = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function storage_write($file, $data)
{
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return false;
    }
    $tmp = $file . '.tmp';
    if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }
    if (is_file($file)) {
        @unlink($file);
    }
    return @rename($tmp, $file);
}

// ===========================================================================
//  Obras (fotografías)
// ===========================================================================
function photos_all()
{
    $photos = storage_read(PHOTOS_FILE, array());
    return photos_sort($photos);
}

function photos_sort($photos)
{
    usort($photos, function ($a, $b) {
        $sa = isset($a['sort']) ? (int) $a['sort'] : 0;
        $sb = isset($b['sort']) ? (int) $b['sort'] : 0;
        if ($sa === $sb) {
            return strcmp(isset($a['id']) ? $a['id'] : '', isset($b['id']) ? $b['id'] : '');
        }
        return $sa < $sb ? -1 : 1;
    });
    return $photos;
}

function photos_save($photos)
{
    $photos = photos_sort($photos);
    return storage_write(PHOTOS_FILE, $photos);
}

function photos_find($photos, $id)
{
    foreach ($photos as $i => $p) {
        if (isset($p['id']) && $p['id'] === $id) {
            return $i;
        }
    }
    return -1;
}

/**
 * Categorías derivadas de las obras existentes (slug + etiqueta + cantidad).
 */
function photos_categories($photos)
{
    $map = array();
    foreach ($photos as $p) {
        $slug = isset($p['category']) ? $p['category'] : '';
        if ($slug === '') {
            continue;
        }
        if (!isset($map[$slug])) {
            $map[$slug] = array(
                'slug'  => $slug,
                'label' => !empty($p['categoryLabel']) ? $p['categoryLabel'] : $slug,
                'count' => 0,
            );
        }
        $map[$slug]['count']++;
    }
    usort($map, function ($a, $b) {
        return strcasecmp($a['label'], $b['label']);
    });
    return array_values($map);
}

function photo_field($in, $key, $default = '')
{
    $v = isset($in[$key]) ? $in[$key] : $default;
    return trim(strip_tags((string) $v));
}

function photo_slug($text, $fallback = 'general')
{
    $text = strtolower(trim((string) $text));
    if (function_exists('iconv')) {
        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        if ($conv !== false) {
            $text = $conv;
        }
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    $text = trim($text, '-');
    return $text === '' ? $fallback : $text;
}

/**
 * Normaliza los datos enviados desde el panel para una obra.
 */
function photo_from_input($in)
{
    $label = photo_field($in, 'categoryLabel');
    $slug  = photo_field($in, 'category');
    if ($slug === '' && $label !== '') {
        $slug = photo_slug($label);
    }
    if ($label === '' && $slug !== '') {
        $label = ucfirst($slug);
    }
    return array(
        'code'          => photo_field($in, 'code'),
        'title'         => photo_field($in, 'title'),
        'category'      => $slug,
        'categoryLabel' => $label,
        'location'      => photo_field($in, 'location'),
        'camera'        => photo_field($in, 'camera'),
        'lens'          => photo_field($in, 'lens'),
        'aperture'      => photo_field($in, 'aperture'),
        'shutter'       => photo_field($in, 'shutter'),
        'iso'           => photo_field($in, 'iso'),
        'lighting'      => photo_field($in, 'lighting'),
        'notes'         => photo_field($in, 'notes'),
        'featured'      => !empty($in['featured']), // destacada: aparece en el filtro «Todos»
    );
}

// ===========================================================================
//  Contenido editable: secciones "Sobre mí" y "Contacto"
// ===========================================================================
/**
 * Estructura completa con los valores por defecto. También sirve de
 * plantilla: cualquier campo que falte en el archivo guardado se completa
 * desde acá, de modo que agregar campos nuevos nunca rompa el sitio.
 */
function content_defaults()
{
    return array(
        'brand' => array(
            'title'    => 'Portafolio',
            'subtitle' => 'Estudio Visual',
            'icon'     => 'aperture',
            'logo'     => '',
            'logo_day' => '',
        ),
        'about' => array(
            'eyebrow'  => 'Perfil & Trayectoria',
            'heading'  => 'Detrás del Lente',
            'portrait' => array(
                'src'  => 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=1000&q=85',
                'alt'  => 'Retrato del fotógrafo en estudio',
                'role' => 'Director de Fotografía',
                'name' => '',
            ),
            'stats' => array(
                array('value' => '10+',  'label' => 'Años Exp.'),
                array('value' => '140+', 'label' => 'Campañas'),
                array('value' => '18',   'label' => 'Premios Int.'),
            ),
            'bio' => array(
                'heading'    => 'Biografía Profesional',
                'quote'      => '“Busco la quietud dentro del movimiento y la geometría oculta en la luz natural.”',
                'paragraphs' => array(
                    'Soy fotógrafo documental, deportivo y editorial con base en Buenos Aires y Madrid. Durante la última década me he especializado en capturar la tensión atlética de alto rendimiento, retratos de carácter íntimo y la sobriedad de la arquitectura contemporánea.',
                    'Mi trabajo se caracteriza por un enfoque minimalista y ordenado: elimino las distracciones visuales para que la composición, la textura y el gesto humano cuenten toda la historia sin artificios innecesarios.',
                ),
            ),
            'specialties' => array(
                'heading' => 'Áreas de Especialización',
                'items'   => array(
                    array('title' => 'Cobertura Deportiva & Acción', 'text' => 'Congelación de alta velocidad, seguimiento en pista y narrativa visual para atletas y marcas técnicas.'),
                    array('title' => 'Retrato Editorial & Estudio', 'text' => 'Iluminación controlada en clave baja, dirección de pose orgánica para perfiles corporativos y artistas.'),
                    array('title' => 'Arquitectura & Interiorismo', 'text' => 'Corrección de perspectiva rigurosa y estudio de la luz solar a lo largo del día para estudios de diseño.'),
                    array('title' => 'Expediciones & Naturaleza', 'text' => 'Documentación de paisajes remotos en condiciones climáticas extremas para publicaciones impresas.'),
                ),
            ),
            'gear' => array(
                'heading' => 'Equipo Técnico en Estudio y Campo',
                'items'   => array(
                    array('label' => 'Cuerpos Principales', 'value' => 'Sony Alpha 1 (50MP) · Leica Q3 (Reportaje)'),
                    array('label' => 'Ópticas Fijas (Primes)', 'value' => '35mm f/1.4 GM · 50mm f/1.2 GM · 85mm f/1.4 GM'),
                    array('label' => 'Teleobjetivos & Acción', 'value' => '70-200mm f/2.8 GM OSS II · 400mm f/2.8 GM'),
                    array('label' => 'Iluminación & Color', 'value' => 'Profoto B10X Plus Duo Kit · Flujo calibrado EIZO ColorEdge'),
                ),
            ),
            'cta' => array(
                'title'  => '¿Tienes un proyecto visual en mente?',
                'text'   => 'Disponible para comisiones comerciales y editoriales a nivel global.',
                'button' => 'Reservar Sesión',
            ),
        ),
        'contact' => array(
            'eyebrow' => 'Consultas & Reservas',
            'heading' => 'Iniciar una Conversación',
            'email' => array(
                'label' => 'Correo Directo de Estudio',
                'value' => 'contacto@estudio.com',
            ),
            'location' => array(
                'label' => 'Estudio Base & Cobertura',
                'title' => 'Palermo Soho, Buenos Aires / Madrid',
                'note'  => 'Pasaporte al día. Disponible para asignaciones internacionales con 72h de preaviso.',
            ),
            'delivery' => array(
                'label' => 'Tiempos de Entrega Habituales',
                'items' => array(
                    'Pre-selección en vivo (Tethering) durante sesión',
                    'Entrega deportiva/prensa en menos de 12 horas',
                    'Retoque editorial de alta gama en 5 días hábiles',
                ),
            ),
            'socials' => array(
                'label' => 'Canales & Portafolios Externos',
                'items' => array(
                    array('icon' => 'instagram', 'label' => 'Instagram', 'url' => '#'),
                    array('icon' => 'globe', 'label' => 'Behance', 'url' => '#'),
                    array('icon' => 'linkedin', 'label' => 'LinkedIn', 'url' => '#'),
                    array('icon' => 'book-open', 'label' => 'Press Kit PDF', 'url' => '#'),
                ),
            ),
            'form' => array(
                'title'       => 'Solicitud de Cotización o Reserva',
                'text'        => 'Completa los detalles del proyecto. Respondo personalmente en menos de 24 horas.',
                'specialties' => array(
                    'Cobertura Deportiva / Marca',
                    'Sesión de Retrato / Editorial',
                    'Fotografía de Arquitectura & Espacios',
                    'Documental & Naturaleza',
                    'Adquisición de Obra Fine Art',
                ),
            ),
        ),
    );
}

/** Texto simple: existe la clave -> se usa su valor (aunque quede vacío); si no, el valor por defecto. */
function content_text($arr, $key, $default = '')
{
    if (!is_array($arr) || !array_key_exists($key, $arr)) {
        return $default;
    }
    return trim(strip_tags((string) $arr[$key]));
}

/** Lista de textos (se descartan los vacíos). */
function content_text_list($arr, $key, $default)
{
    if (!is_array($arr) || !isset($arr[$key]) || !is_array($arr[$key])) {
        return $default;
    }
    $out = array();
    foreach ($arr[$key] as $v) {
        if (!is_string($v) && !is_numeric($v)) {
            continue;
        }
        $v = trim(strip_tags((string) $v));
        if ($v !== '') {
            $out[] = $v;
        }
    }
    return $out;
}

/** Lista de objetos con campos fijos; se descartan las filas completamente vacías. */
function content_obj_list($arr, $key, $default, $fields)
{
    if (!is_array($arr) || !isset($arr[$key]) || !is_array($arr[$key])) {
        return $default;
    }
    $out = array();
    foreach ($arr[$key] as $it) {
        if (!is_array($it)) {
            continue;
        }
        $row   = array();
        $empty = true;
        foreach ($fields as $f) {
            $v = isset($it[$f]) ? trim(strip_tags((string) $it[$f])) : '';
            $row[$f] = $v;
            if ($v !== '') {
                $empty = false;
            }
        }
        if (!$empty) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Normaliza el contenido guardado contra los valores por defecto.
 */
function content_normalize($stored)
{
    $d = content_defaults();
    if (!is_array($stored)) {
        $stored = array();
    }
    $a = isset($stored['about']) && is_array($stored['about']) ? $stored['about'] : array();
    $c = isset($stored['contact']) && is_array($stored['contact']) ? $stored['contact'] : array();
    $b = isset($stored['brand']) && is_array($stored['brand']) ? $stored['brand'] : array();

    $ap  = isset($a['portrait']) && is_array($a['portrait']) ? $a['portrait'] : array();
    $ab  = isset($a['bio']) && is_array($a['bio']) ? $a['bio'] : array();
    $asp = isset($a['specialties']) && is_array($a['specialties']) ? $a['specialties'] : array();
    $ag  = isset($a['gear']) && is_array($a['gear']) ? $a['gear'] : array();
    $act = isset($a['cta']) && is_array($a['cta']) ? $a['cta'] : array();

    $ce = isset($c['email']) && is_array($c['email']) ? $c['email'] : array();
    $cl = isset($c['location']) && is_array($c['location']) ? $c['location'] : array();
    $cd = isset($c['delivery']) && is_array($c['delivery']) ? $c['delivery'] : array();
    $cs = isset($c['socials']) && is_array($c['socials']) ? $c['socials'] : array();
    $cf = isset($c['form']) && is_array($c['form']) ? $c['form'] : array();

    return array(
        'brand' => array(
            'title'    => content_text($b, 'title', $d['brand']['title']),
            'subtitle' => content_text($b, 'subtitle', $d['brand']['subtitle']),
            'icon'     => content_text($b, 'icon', $d['brand']['icon']),
            'logo'     => content_text($b, 'logo', $d['brand']['logo']),
            'logo_day' => content_text($b, 'logo_day', $d['brand']['logo_day']),
        ),
        'about' => array(
            'eyebrow'  => content_text($a, 'eyebrow', $d['about']['eyebrow']),
            'heading'  => content_text($a, 'heading', $d['about']['heading']),
            'portrait' => array(
                'src'  => content_text($ap, 'src', $d['about']['portrait']['src']),
                'alt'  => content_text($ap, 'alt', $d['about']['portrait']['alt']),
                'role' => content_text($ap, 'role', $d['about']['portrait']['role']),
                'name' => content_text($ap, 'name', $d['about']['portrait']['name']),
            ),
            'stats' => content_obj_list($a, 'stats', $d['about']['stats'], array('value', 'label')),
            'bio'   => array(
                'heading'    => content_text($ab, 'heading', $d['about']['bio']['heading']),
                'quote'      => content_text($ab, 'quote', $d['about']['bio']['quote']),
                'paragraphs' => content_text_list($ab, 'paragraphs', $d['about']['bio']['paragraphs']),
            ),
            'specialties' => array(
                'heading' => content_text($asp, 'heading', $d['about']['specialties']['heading']),
                'items'   => content_obj_list($asp, 'items', $d['about']['specialties']['items'], array('title', 'text')),
            ),
            'gear' => array(
                'heading' => content_text($ag, 'heading', $d['about']['gear']['heading']),
                'items'   => content_obj_list($ag, 'items', $d['about']['gear']['items'], array('label', 'value')),
            ),
            'cta' => array(
                'title'  => content_text($act, 'title', $d['about']['cta']['title']),
                'text'   => content_text($act, 'text', $d['about']['cta']['text']),
                'button' => content_text($act, 'button', $d['about']['cta']['button']),
            ),
        ),
        'contact' => array(
            'eyebrow' => content_text($c, 'eyebrow', $d['contact']['eyebrow']),
            'heading' => content_text($c, 'heading', $d['contact']['heading']),
            'email'   => array(
                'label' => content_text($ce, 'label', $d['contact']['email']['label']),
                'value' => content_text($ce, 'value', $d['contact']['email']['value']),
            ),
            'location' => array(
                'label' => content_text($cl, 'label', $d['contact']['location']['label']),
                'title' => content_text($cl, 'title', $d['contact']['location']['title']),
                'note'  => content_text($cl, 'note', $d['contact']['location']['note']),
            ),
            'delivery' => array(
                'label' => content_text($cd, 'label', $d['contact']['delivery']['label']),
                'items' => content_text_list($cd, 'items', $d['contact']['delivery']['items']),
            ),
            'socials' => array(
                'label' => content_text($cs, 'label', $d['contact']['socials']['label']),
                'items' => content_obj_list($cs, 'items', $d['contact']['socials']['items'], array('icon', 'label', 'url')),
            ),
            'form' => array(
                'title'       => content_text($cf, 'title', $d['contact']['form']['title']),
                'text'        => content_text($cf, 'text', $d['contact']['form']['text']),
                'specialties' => content_text_list($cf, 'specialties', $d['contact']['form']['specialties']),
            ),
        ),
    );
}

/** Lee el contenido actual (siempre completo gracias a los valores por defecto). */
function content_all()
{
    return content_normalize(storage_read(CONTENT_FILE, array()));
}

/** Guarda el contenido normalizado. */
function content_save($content)
{
    return storage_write(CONTENT_FILE, content_normalize($content));
}

/** Borra de media/ los archivos asociados a una ruta local (retrato anterior). */
function content_delete_media($rel)
{
    if (!is_string($rel) || $rel === '' || strpos($rel, 'media/') !== 0) {
        return;
    }
    $base = pathinfo($rel, PATHINFO_FILENAME);
    if ($base === '') {
        return;
    }
    foreach (array('web', 'thumbs', 'originals') as $dir) {
        $pattern = MEDIA_DIR . '/' . $dir . '/' . $base . '.*';
        $files   = function_exists('glob') ? @glob($pattern) : array();
        if (is_array($files)) {
            foreach ($files as $f) {
                $abs = realpath($f);
                if ($abs && strpos($abs, MEDIA_DIR) === 0 && is_file($abs)) {
                    @unlink($abs);
                }
            }
        }
    }
}

// ===========================================================================
//  Autenticación
// ===========================================================================
function admin_credentials()
{
    if (!is_file(ADMIN_FILE)) {
        $data = array(
            'user'    => ADMIN_DEFAULT_USER,
            'pass'    => password_hash(ADMIN_DEFAULT_PASS, PASSWORD_DEFAULT),
            'updated' => date('c'),
        );
        storage_write(ADMIN_FILE, $data);
        return $data;
    }
    return storage_read(ADMIN_FILE, array());
}

function admin_login($user, $pass)
{
    $c = admin_credentials();
    if (empty($c['user']) || empty($c['pass'])) {
        return false;
    }
    if (hash_equals($c['user'], (string) $user) && password_verify((string) $pass, $c['pass'])) {
        $_SESSION['admin'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
        return true;
    }
    return false;
}

function admin_change_password($current, $new)
{
    $c = admin_credentials();
    if (empty($c['pass']) || !password_verify((string) $current, $c['pass'])) {
        return false;
    }
    $c['pass'] = password_hash((string) $new, PASSWORD_DEFAULT);
    $c['updated'] = date('c');
    return storage_write(ADMIN_FILE, $c);
}

function admin_logout()
{
    $_SESSION = array();
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function is_logged_in()
{
    return !empty($_SESSION['admin']);
}

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function check_csrf()
{
    $sent = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
    if ($sent === '' && isset($_POST['csrf'])) {
        $sent = $_POST['csrf'];
    }
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

// ===========================================================================
//  Registro de actividad (auditoría)
// ===========================================================================
function activity_log($action, $detail = '')
{
    $entry = array(
        'time'   => date('c'),
        'ip'     => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        'action' => $action,
        'detail' => $detail,
    );
    @file_put_contents(LOG_FILE, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

function activity_read($limit = 150)
{
    if (!is_file(LOG_FILE)) {
        return array();
    }
    $lines = @file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!$lines) {
        return array();
    }
    $lines = array_reverse($lines);
    $lines = array_slice($lines, 0, $limit);
    $out = array();
    foreach ($lines as $l) {
        $e = json_decode($l, true);
        if (is_array($e)) {
            $out[] = $e;
        }
    }
    return $out;
}

// ===========================================================================
//  Respuestas JSON
// ===========================================================================
function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_body()
{
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}

function require_api_auth()
{
    if (!is_logged_in()) {
        json_out(array('ok' => false, 'error' => 'No autorizado.'), 401);
    }
}

function require_csrf()
{
    if (!check_csrf()) {
        json_out(array('ok' => false, 'error' => 'Token de seguridad inválido. Recargá la página.'), 419);
    }
}

// ===========================================================================
//  Procesamiento de imágenes (GD)
// ===========================================================================
function image_supported()
{
    return extension_loaded('gd');
}

function image_output_ext()
{
    return function_exists('imagewebp') ? 'webp' : 'jpg';
}

function image_ext_for_type($type)
{
    $map = array(
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
    );
    if (defined('IMAGETYPE_WEBP')) {
        $map[IMAGETYPE_WEBP] = 'webp';
    }
    return isset($map[$type]) ? $map[$type] : 'jpg';
}

function image_load($path, $type)
{
    switch ($type) {
        case IMAGETYPE_JPEG:
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($path) : false;
        case IMAGETYPE_PNG:
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false;
        case IMAGETYPE_GIF:
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($path) : false;
    }
    if (defined('IMAGETYPE_WEBP') && $type === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) {
        return @imagecreatefromwebp($path);
    }
    return false;
}

function image_apply_orientation($img, $path)
{
    if (!function_exists('exif_read_data')) {
        return $img;
    }
    $exif = @exif_read_data($path);
    if (empty($exif['Orientation'])) {
        return $img;
    }
    switch ((int) $exif['Orientation']) {
        case 3:
            return imagerotate($img, 180, 0);
        case 6:
            return imagerotate($img, -90, 0);
        case 8:
            return imagerotate($img, 90, 0);
    }
    return $img;
}

/**
 * Redimensiona (manteniendo proporción, sin agrandar) y guarda en WebP/JPG.
 * Devuelve array(width, height) de la salida.
 */
function image_resize_save($src, $srcW, $srcH, $maxW, $destPath)
{
    $scale = ($srcW > $maxW) ? ($maxW / $srcW) : 1;
    $w = max(1, (int) round($srcW * $scale));
    $h = max(1, (int) round($srcH * $scale));

    $dst = imagecreatetruecolor($w, $h);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
    imagefilledrectangle($dst, 0, 0, $w, $h, $transparent);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $srcW, $srcH);

    if (substr($destPath, -5) === '.webp' && function_exists('imagewebp')) {
        @imagewebp($dst, $destPath, IMG_QUALITY);
    } else {
        @imagejpeg($dst, $destPath, IMG_QUALITY);
    }
    imagedestroy($dst);
    return array($w, $h);
}

/**
 * Convierte un valor racional EXIF ("28/10", "0.35" o array) a número decimal.
 */
function exif_ratio($value)
{
    if (is_array($value)) {
        $value = reset($value);
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (strpos($value, '/') !== false) {
        list($num, $den) = explode('/', $value, 2);
        $den = (float) $den;
        if ($den == 0) {
            return null;
        }
        return (float) $num / $den;
    }
    return (float) $value;
}

/**
 * Formatea un número quitando ceros sobrantes ("2.80" -> "2.8", "8.00" -> "8").
 */
function exif_trim_number($n)
{
    $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

/**
 * Extrae los metadatos fotográficos (cámara, óptica, exposición) del EXIF.
 * Devuelve un array con las mismas claves usadas por la ficha de la obra.
 * Para formatos sin EXIF (PNG, GIF, WebP) devuelve los campos vacíos.
 */
function image_read_exif($path)
{
    $meta = array('camera' => '', 'lens' => '', 'aperture' => '', 'shutter' => '', 'iso' => '');
    if (!function_exists('exif_read_data')) {
        return $meta;
    }
    $exif = @exif_read_data($path, null, true);
    if (!is_array($exif)) {
        return $meta;
    }

    // Aplana las secciones (IFD0, EXIF, COMPUTED…) en un solo nivel.
    $flat = array();
    foreach ($exif as $section) {
        if (is_array($section)) {
            $flat = array_merge($flat, $section);
        }
    }

    // Cámara: marca + modelo, evitando repetir la marca.
    $make  = isset($flat['Make']) ? trim((string) $flat['Make']) : '';
    $model = isset($flat['Model']) ? trim((string) $flat['Model']) : '';
    if ($model !== '') {
        $brand = strtoupper((string) strtok($make, ' '));
        $meta['camera'] = ($brand !== '' && stripos($model, $brand) === false) ? trim($make . ' ' . $model) : $model;
    } elseif ($make !== '') {
        $meta['camera'] = $make;
    }

    // Óptica: cada fabricante la publica en claves distintas.
    foreach (array('LensModel', 'UndefinedTag:0xA434', 'Lens') as $k) {
        if (!empty($flat[$k]) && is_string($flat[$k])) {
            $meta['lens'] = trim($flat[$k]);
            break;
        }
    }
    // Fallback: LensInfo = [focal mín., focal máx., apertura mín., apertura máx.].
    if ($meta['lens'] === '' && !empty($flat['LensInfo'])) {
        $li = is_array($flat['LensInfo']) ? $flat['LensInfo'] : explode(' ', (string) $flat['LensInfo']);
        if (count($li) >= 2 && ($fmin = exif_ratio($li[0]))) {
            $fmax = exif_ratio($li[1]);
            $meta['lens'] = ($fmax && $fmax != $fmin)
                ? exif_trim_number($fmin) . '-' . exif_trim_number($fmax) . 'mm'
                : exif_trim_number($fmin) . 'mm';
        }
    }

    // Apertura: FNumber ("f/2.8") o, en su defecto, ApertureValue (APEX).
    if (isset($flat['FNumber']) && ($f = exif_ratio($flat['FNumber']))) {
        $meta['aperture'] = 'f/' . exif_trim_number($f);
    } elseif (isset($flat['ApertureValue']) && ($apex = exif_ratio($flat['ApertureValue']))) {
        $meta['aperture'] = 'f/' . exif_trim_number(pow(2, $apex / 2));
    }

    // Velocidad de obturación: "1/3200s" o "2s".
    if (isset($flat['ExposureTime']) && ($t = exif_ratio($flat['ExposureTime']))) {
        $meta['shutter'] = ($t < 1) ? '1/' . round(1 / $t) . 's' : exif_trim_number($t) . 's';
    }

    // Sensibilidad ISO.
    $iso = null;
    foreach (array('ISOSpeedRatings', 'PhotographicSensitivity') as $k) {
        if (!empty($flat[$k])) {
            $iso = is_array($flat[$k]) ? reset($flat[$k]) : $flat[$k];
            break;
        }
    }
    if ($iso !== null && $iso !== '') {
        $meta['iso'] = 'ISO ' . (int) $iso;
    }

    return $meta;
}

/**
 * Procesa un archivo subido ($_FILES['file']).
 * Guarda el original protegido y genera versión web + miniatura.
 */
function process_upload($file)
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return array('ok' => false, 'error' => 'Archivo inválido.');
    }
    if (!empty($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
        return array('ok' => false, 'error' => 'Error al recibir el archivo (código ' . (int) $file['error'] . ').');
    }
    if (isset($file['size']) && $file['size'] > MAX_UPLOAD_BYTES) {
        return array('ok' => false, 'error' => 'El archivo supera el máximo permitido.');
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) {
        return array('ok' => false, 'error' => 'El archivo no es una imagen válida.');
    }
    $type = $info[2];
    $allowed = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
    if (defined('IMAGETYPE_WEBP')) {
        $allowed[] = IMAGETYPE_WEBP;
    }
    if (!in_array($type, $allowed, true)) {
        return array('ok' => false, 'error' => 'Formato no soportado. Usá JPG, PNG, GIF o WebP.');
    }

    $hash = hash_file('sha256', $file['tmp_name']);

    // Detección de duplicados por hash.
    foreach (photos_all() as $p) {
        if (!empty($p['hash']) && $p['hash'] === $hash) {
            return array('ok' => false, 'duplicate' => true, 'error' => 'Ya existe una obra con esta misma imagen.', 'existing' => isset($p['id']) ? $p['id'] : '');
        }
    }

    $origExt = image_ext_for_type($type);
    $origRel = 'media/originals/' . $hash . '.' . $origExt;
    $origAbs = APP_ROOT . '/' . $origRel;

    if (!@move_uploaded_file($file['tmp_name'], $origAbs)) {
        if (!@copy($file['tmp_name'], $origAbs)) {
            return array('ok' => false, 'error' => 'No se pudo guardar el archivo en el servidor.');
        }
    }

    $srcW = (int) $info[0];
    $srcH = (int) $info[1];
    $outW = $srcW;
    $outH = $srcH;

    if (image_supported() && ($img = image_load($origAbs, $type))) {
        $rotated = image_apply_orientation($img, $origAbs);
        if ($rotated !== $img) {
            imagedestroy($img);
            $img = $rotated;
        }
        $srcW = imagesx($img);
        $srcH = imagesy($img);

        $ext = image_output_ext();
        $webRel = 'media/web/' . $hash . '.' . $ext;
        $thumbRel = 'media/thumbs/' . $hash . '.' . $ext;

        list($outW, $outH) = image_resize_save($img, $srcW, $srcH, WEB_MAX_WIDTH, APP_ROOT . '/' . $webRel);
        image_resize_save($img, $srcW, $srcH, THUMB_MAX_WIDTH, APP_ROOT . '/' . $thumbRel);
        imagedestroy($img);
    } else {
        // Sin GD: se sirve el original copiado a la carpeta pública.
        $webRel = 'media/web/' . $hash . '.' . $origExt;
        $thumbRel = $webRel;
        @copy($origAbs, APP_ROOT . '/' . $webRel);
    }

    return array(
        'ok'       => true,
        'hash'     => $hash,
        'src'      => $webRel,
        'thumb'    => $thumbRel,
        'w'        => $outW,
        'h'        => $outH,
        'original' => $origRel,
        'size'     => isset($file['size']) ? (int) $file['size'] : 0,
        'format'   => $origExt,
        'exif'     => image_read_exif($origAbs),
    );
}
