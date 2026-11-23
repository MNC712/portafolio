<?php
/**
 * Subida de imágenes (solo administrador).
 * - Sin "id": crea una obra nueva.
 * - Con "id": reemplaza la imagen de una obra existente.
 * Genera original protegido + versión web optimizada + miniatura.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

require_csrf();

if (empty($_FILES['file'])) {
    json_out(array('ok' => false, 'error' => 'No se recibió ningún archivo.'), 400);
}

$res = process_upload($_FILES['file']);
if (empty($res['ok'])) {
    json_out($res, 400);
}

$photos = photos_all();
$id     = isset($_POST['id']) ? trim((string) $_POST['id']) : '';
$exif   = isset($res['exif']) && is_array($res['exif']) ? $res['exif'] : array();
$metaKeys = array('camera', 'lens', 'aperture', 'shutter', 'iso');

// --- Reemplazar imagen de una obra existente -------------------------------
$idx = $id !== '' ? photos_find($photos, $id) : -1;
if ($idx >= 0) {
    $old = $photos[$idx];

    // Borrar archivos locales anteriores (evitando tocar URLs externas).
    foreach (array('src', 'thumb', 'original') as $k) {
        if (empty($old[$k]) || strpos($old[$k], 'media/') !== 0) {
            continue;
        }
        $abs = realpath(APP_ROOT . '/' . $old[$k]);
        if ($abs && strpos($abs, MEDIA_DIR) === 0 && is_file($abs)) {
            @unlink($abs);
        }
    }

    $photos[$idx]['src']       = $res['src'];
    $photos[$idx]['thumb']     = $res['thumb'];
    $photos[$idx]['w']         = $res['w'];
    $photos[$idx]['h']         = $res['h'];
    $photos[$idx]['hash']      = $res['hash'];
    $photos[$idx]['original']  = $res['original'];
    $photos[$idx]['updatedAt'] = date('c');

    // Autocompleta metadatos vacíos con los leídos del EXIF de la nueva imagen.
    foreach ($metaKeys as $k) {
        if (empty($photos[$idx][$k]) && !empty($exif[$k])) {
            $photos[$idx][$k] = $exif[$k];
        }
    }

    photos_save($photos);

    activity_log('replace_image', 'Imagen reemplazada: ' . (isset($old['title']) ? $old['title'] : $id));
    json_out(array('ok' => true, 'replaced' => true, 'photo' => $photos[$idx]));
}

// --- Crear una obra nueva --------------------------------------------------
$title = isset($_POST['title']) ? trim(strip_tags((string) $_POST['title'])) : '';
if ($title === '') {
    $base  = pathinfo(isset($_FILES['file']['name']) ? $_FILES['file']['name'] : '', PATHINFO_FILENAME);
    $title = $base !== '' ? $base : 'Nueva obra';
}

$count = count($photos);
$rec = array(
    'id'            => bin2hex(random_bytes(8)),
    'code'          => 'ARC-' . str_pad((string) ($count + 1), 2, '0', STR_PAD_LEFT),
    'title'         => $title,
    'category'      => '',
    'categoryLabel' => 'Sin clasificar',
    'location'      => '',
    'src'           => $res['src'],
    'thumb'         => $res['thumb'],
    'w'             => $res['w'],
    'h'             => $res['h'],
    'camera'        => isset($exif['camera']) ? $exif['camera'] : '',
    'lens'          => isset($exif['lens']) ? $exif['lens'] : '',
    'aperture'      => isset($exif['aperture']) ? $exif['aperture'] : '',
    'shutter'       => isset($exif['shutter']) ? $exif['shutter'] : '',
    'iso'           => isset($exif['iso']) ? $exif['iso'] : '',
    'lighting'      => '',
    'notes'         => '',
    'hash'          => $res['hash'],
    'original'      => $res['original'],
    'createdAt'     => date('c'),
    'sort'          => $count + 1,
);

$photos[] = $rec;
photos_save($photos);

activity_log('upload', 'Imagen subida: ' . $title);
json_out(array('ok' => true, 'photo' => $rec));
