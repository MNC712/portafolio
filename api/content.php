<?php
/**
 * Contenido editable de las secciones "Sobre mí" y "Contacto" (solo administrador).
 * GET  -> devuelve el contenido actual.
 * POST -> { action: save }            guarda todo el contenido.
 *         { action: upload_portrait }  sube el retrato de "Sobre mí" y devuelve su ruta.
 */
require_once __DIR__ . '/../inc/bootstrap.php';
require_api_auth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_out(array('ok' => true, 'content' => content_all()));
}

if ($method !== 'POST') {
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

require_csrf();

// --- Subir el retrato de "Sobre mí" ----------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'upload_portrait') {
    if (empty($_FILES['file'])) {
        json_out(array('ok' => false, 'error' => 'No se recibió ningún archivo.'), 400);
    }

    $res = process_upload($_FILES['file']);
    if (empty($res['ok'])) {
        json_out($res, 400);
    }

    $content = content_all();
    $old     = isset($content['about']['portrait']['src']) ? $content['about']['portrait']['src'] : '';
    if ($old !== $res['src']) {
        content_delete_media($old); // limpia la versión local anterior
    }

    $content['about']['portrait']['src'] = $res['src'];
    content_save($content);

    activity_log('content_portrait', 'Retrato de "Sobre mí" actualizado');
    json_out(array('ok' => true, 'src' => $res['src'], 'w' => $res['w'], 'h' => $res['h']));
}

// --- Subir el logo de la marca ----------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'upload_logo') {
    if (empty($_FILES['file'])) {
        json_out(array('ok' => false, 'error' => 'No se recibió ningún archivo.'), 400);
    }

    $res = process_upload($_FILES['file']);
    if (empty($res['ok'])) {
        json_out($res, 400);
    }

    $content = content_all();
    $old     = isset($content['brand']['logo']) ? $content['brand']['logo'] : '';
    if ($old !== $res['src']) {
        content_delete_media($old); // limpia el logo anterior
    }

    $content['brand']['logo'] = $res['src'];
    content_save($content);

    activity_log('content_logo', 'Logo de la marca actualizado');
    json_out(array('ok' => true, 'src' => $res['src'], 'w' => $res['w'], 'h' => $res['h']));
}

// --- Subir el logo de la marca para el modo día ------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'upload_logo_day') {
    if (empty($_FILES['file'])) {
        json_out(array('ok' => false, 'error' => 'No se recibió ningún archivo.'), 400);
    }

    $res = process_upload($_FILES['file']);
    if (empty($res['ok'])) {
        json_out($res, 400);
    }

    $content = content_all();
    $old     = isset($content['brand']['logo_day']) ? $content['brand']['logo_day'] : '';
    if ($old !== $res['src']) {
        content_delete_media($old); // limpia el logo anterior
    }

    $content['brand']['logo_day'] = $res['src'];
    content_save($content);

    activity_log('content_logo', 'Logo de modo día actualizado');
    json_out(array('ok' => true, 'src' => $res['src'], 'w' => $res['w'], 'h' => $res['h']));
}

// --- Guardar contenido ------------------------------------------------------
$body   = json_body();
$action = isset($body['action']) ? $body['action'] : '';

if ($action === 'save') {
    $content = isset($body['content']) ? $body['content'] : array();
    if (!content_save($content)) {
        json_out(array('ok' => false, 'error' => 'No se pudo guardar el contenido.'), 500);
    }
    activity_log('content_save', 'Contenido de "Sobre mí" y "Contacto" actualizado');
    json_out(array('ok' => true, 'content' => content_all()));
}

json_out(array('ok' => false, 'error' => 'Acción desconocida.'), 400);
