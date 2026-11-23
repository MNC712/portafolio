<?php
/**
 * Gestión de obras (solo administrador).
 * GET  -> lista completa.
 * POST -> { action: save | delete | move | reorder }
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_api_auth();

/**
 * Borra los archivos locales asociados a una obra (solo dentro de media/).
 */
function photo_delete_files($p)
{
    $keys = array('src', 'thumb', 'original');
    $done = array();
    foreach ($keys as $k) {
        if (empty($p[$k])) {
            continue;
        }
        $rel = $p[$k];
        if (strpos($rel, 'media/') !== 0) {
            continue; // URLs externas: no se tocan
        }
        $abs = realpath(APP_ROOT . '/' . $rel);
        if ($abs && strpos($abs, MEDIA_DIR) === 0 && is_file($abs) && !isset($done[$abs])) {
            @unlink($abs);
            $done[$abs] = true;
        }
    }
}

/**
 * Reasigna el orden secuencial (sort) según el orden actual del array.
 */
function photos_reindex($photos)
{
    $i = 1;
    foreach ($photos as &$p) {
        $p['sort'] = $i++;
    }
    unset($p);
    return $photos;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $photos = photos_all();
    json_out(array(
        'ok'         => true,
        'photos'     => $photos,
        'categories' => photos_categories($photos),
        'activity'   => activity_read(120),
    ));
}

if ($method !== 'POST') {
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

require_csrf();

$body   = json_body();
$action = isset($body['action']) ? $body['action'] : '';
$photos = photos_all();

// --- Guardar (editar) ------------------------------------------------------
if ($action === 'save') {
    $id     = isset($body['id']) ? (string) $body['id'] : '';
    $fields = photo_from_input($body);
    $idx    = photos_find($photos, $id);

    if ($idx < 0) {
        json_out(array('ok' => false, 'error' => 'Obra no encontrada.'), 404);
    }

    $photos[$idx]              = array_merge($photos[$idx], $fields);
    $photos[$idx]['updatedAt'] = date('c');
    photos_save($photos);

    $label = $fields['title'] !== '' ? $fields['title'] : $id;
    activity_log('edit', 'Obra editada: ' . $label);
    json_out(array('ok' => true, 'photo' => $photos[$idx]));
}

// --- Eliminar --------------------------------------------------------------
if ($action === 'delete') {
    $id  = isset($body['id']) ? (string) $body['id'] : '';
    $idx = photos_find($photos, $id);

    if ($idx < 0) {
        json_out(array('ok' => false, 'error' => 'Obra no encontrada.'), 404);
    }

    $p = $photos[$idx];
    photo_delete_files($p);
    array_splice($photos, $idx, 1);
    $photos = photos_reindex($photos);
    photos_save($photos);

    $label = !empty($p['title']) ? $p['title'] : $id;
    activity_log('delete', 'Obra eliminada: ' . $label);
    json_out(array('ok' => true));
}

// --- Mover arriba / abajo --------------------------------------------------
if ($action === 'move') {
    $id  = isset($body['id']) ? (string) $body['id'] : '';
    $dir = isset($body['dir']) ? $body['dir'] : 'up';
    $idx = photos_find($photos, $id);

    if ($idx < 0) {
        json_out(array('ok' => false, 'error' => 'Obra no encontrada.'), 404);
    }

    $target = ($dir === 'up') ? $idx - 1 : $idx + 1;
    if ($target < 0 || $target >= count($photos)) {
        json_out(array('ok' => true, 'moved' => false));
    }

    $tmp             = $photos[$idx];
    $photos[$idx]    = $photos[$target];
    $photos[$target] = $tmp;
    $photos          = photos_reindex($photos);
    photos_save($photos);

    json_out(array('ok' => true, 'moved' => true));
}

// --- Reordenar (lista de ids) ---------------------------------------------
if ($action === 'reorder') {
    $ids = isset($body['ids']) && is_array($body['ids']) ? $body['ids'] : array();
    $byId = array();
    foreach ($photos as $p) {
        $byId[$p['id']] = $p;
    }
    $ordered = array();
    foreach ($ids as $rid) {
        if (isset($byId[$rid])) {
            $ordered[] = $byId[$rid];
            unset($byId[$rid]);
        }
    }
    foreach ($byId as $p) { // los que no vinieron en la lista quedan al final
        $ordered[] = $p;
    }
    $ordered = photos_reindex($ordered);
    photos_save($ordered);
    activity_log('reorder', 'Orden de obras actualizado');
    json_out(array('ok' => true));
}

json_out(array('ok' => false, 'error' => 'Acción desconocida.'), 400);
