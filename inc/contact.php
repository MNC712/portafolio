<?php

const CONTACT_DIR = DATA_DIR . '/inbox';
const CONTACT_FILE_HEADER = "<?php http_response_code(404); exit; __halt_compiler();\n";

function contact_email_valid($email)
{
    return strlen($email) <= 254 && !preg_match('/[\r\n]/', $email)
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function contact_recipient($email)
{
    return contact_email_valid($email) && strcasecmp($email, 'contacto@estudio.com') !== 0;
}

function contact_save($message)
{
    if (!is_dir(CONTACT_DIR) && !@mkdir(CONTACT_DIR, 0700, true) && !is_dir(CONTACT_DIR)) {
        return false;
    }
    $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    $file = CONTACT_DIR . '/' . $message['id'] . '.php';
    // Publicar el archivo completo sin sobrescribir la copia previa si falla la escritura.
    $tmp = $file . '.' . bin2hex(random_bytes(6)) . '.tmp.php';
    $data = CONTACT_FILE_HEADER . $json;
    if (@file_put_contents($tmp, $data) !== strlen($data) || !@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

function contact_list($before, $limit)
{
    if (!is_dir(CONTACT_DIR)) {
        return array();
    }
    $files = glob(CONTACT_DIR . '/*.php', GLOB_NOSORT);
    if ($files === false) {
        throw new RuntimeException('No se pudo leer la bandeja.');
    }
    rsort($files, SORT_STRING);
    $messages = array();
    foreach ($files as $file) {
        $id = basename($file, '.php');
        if (!preg_match('/^\d{14}-[a-f0-9]{24}$/D', $id) || ($before !== '' && strcmp($id, $before) >= 0)) {
            continue;
        }
        $raw = @file_get_contents($file);
        $message = $raw === false ? null : json_decode(substr($raw, strlen(CONTACT_FILE_HEADER)), true);
        if (!is_array($message)) {
            throw new RuntimeException('No se pudo leer un mensaje de la bandeja.');
        }
        $messages[] = $message;
        if (count($messages) >= $limit) {
            break;
        }
    }
    return $messages;
}

function contact_notify($message)
{
    if (!contact_recipient($message['recipient'])) {
        return 'not_configured';
    }
    $from = trim((string) getenv('F18_CONTACT_FROM'));
    if ($from !== '' && !contact_email_valid($from)) {
        return 'failed';
    }
    $headers = array(
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'Reply-To: ' . $message['email'],
    );
    if ($from !== '') {
        $headers[] = 'From: ' . $from;
    }
    $body = "Nueva consulta desde el portafolio.\n\n"
        . "Nombre: {$message['name']}\nCorreo: {$message['email']}\n"
        . "Proyecto: {$message['projectType']}\nFecha estimada: " . ($message['date'] ?: 'Sin especificar')
        . "\nReferencia: {$message['id']}\n\n{$message['message']}";
    try {
        return function_exists('mail') && @mail(
            $message['recipient'],
            'Nueva consulta del portafolio',
            chunk_split(base64_encode($body), 76, "\r\n"),
            implode("\r\n", $headers)
        ) ? 'accepted' : 'failed';
    } catch (Throwable $e) {
        return 'failed';
    }
}
