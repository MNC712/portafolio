<?php
require_once __DIR__ . '/../inc/bootstrap.php';
require_once INC_DIR . '/contact.php';
require_api_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

$before = $_GET['before'] ?? '';
if (!is_string($before) || ($before !== '' && !preg_match('/^\d{14}-[a-f0-9]{24}$/D', $before))) {
    json_out(array('ok' => false, 'error' => 'Página inválida.'), 400);
}

try {
    $messages = contact_list($before, 31);
} catch (RuntimeException $e) {
    json_out(array('ok' => false, 'error' => 'No se pudo leer la bandeja.'), 500);
}
$next = null;
if (count($messages) > 30) {
    array_pop($messages);
    $next = $messages[29]['id'];
}
$content = content_all();
$recipient = $content['contact']['email']['value'];
json_out(array(
    'ok' => true,
    'messages' => $messages,
    'next' => $next,
    'recipient' => contact_recipient($recipient) ? $recipient : null,
));
