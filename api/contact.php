<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once INC_DIR . '/contact.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

$type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
if ($type !== 'application/json') {
    json_out(array('ok' => false, 'error' => 'Formato de solicitud no admitido.'), 415);
}

$raw = file_get_contents('php://input', false, null, 0, 32769);
if ($raw === false || strlen($raw) > 32768) {
    json_out(array('ok' => false, 'error' => 'La consulta supera el tamaño permitido.'), 413);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    json_out(array('ok' => false, 'error' => 'Consulta inválida.'), 400);
}
if (($body['website'] ?? '') !== '') {
    json_out(array('ok' => false, 'error' => 'No se pudo validar la consulta.'), 422);
}

$message = array();
foreach (array('name' => 480, 'email' => 254, 'projectType' => 800, 'date' => 10, 'message' => 16000) as $key => $limit) {
    $value = $body[$key] ?? '';
    if (!is_string($value) || strlen($value) > $limit || strpos($value, "\0") !== false ||
        ($key !== 'date' && trim($value) === '')) {
        json_out(array('ok' => false, 'error' => 'Revisá los campos obligatorios y su longitud.'), 422);
    }
    $message[$key] = trim($value);
}
if (!contact_email_valid($message['email']) || preg_match('/[\r\n]/', $body['email'])) {
    json_out(array('ok' => false, 'error' => 'Ingresá un correo electrónico válido.'), 422);
}
if ($message['date'] !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $message['date'], $parts)
    || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]))) {
    json_out(array('ok' => false, 'error' => 'Ingresá una fecha válida.'), 422);
}

$now = time();
if ($now - ($_SESSION['contact_last_submit'] ?? 0) < 15) {
    header('Retry-After: 15');
    json_out(array('ok' => false, 'error' => 'Esperá unos segundos antes de enviar otra consulta.'), 429);
}
$content = content_all();
$message['id'] = gmdate('YmdHis', $now) . '-' . bin2hex(random_bytes(12));
$message['receivedAt'] = gmdate('c', $now);
$message['recipient'] = $content['contact']['email']['value'];
$message['mailStatus'] = 'pending';
if (!contact_save($message)) {
    json_out(array('ok' => false, 'error' => 'No se pudo guardar la consulta. Volvé a intentarlo.'), 500);
}
$_SESSION['contact_last_submit'] = $now;
session_write_close();

// Un problema de correo nunca elimina una consulta ya recibida.
$message['mailStatus'] = contact_notify($message);
if (!contact_save($message)) {
    error_log('No se pudo actualizar el estado del correo de contacto: ' . $message['id']);
}
json_out(array('ok' => true), 201);
