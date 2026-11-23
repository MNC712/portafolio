<?php
/**
 * Autenticación del panel: login, logout, estado y cambio de contraseña.
 */
require_once __DIR__ . '/../inc/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Estado de sesión
if ($action === 'status') {
    json_out(array(
        'ok'     => true,
        'authed' => is_logged_in(),
        'csrf'   => is_logged_in() ? csrf_token() : null,
    ));
}

if ($method !== 'POST') {
    json_out(array('ok' => false, 'error' => 'Método no permitido.'), 405);
}

$body   = json_body();
$act    = isset($body['action']) ? $body['action'] : $action;

// --- Ingreso ---------------------------------------------------------------
if ($act === 'login') {
    $user = isset($body['user']) ? trim((string) $body['user']) : '';
    $pass = isset($body['pass']) ? (string) $body['pass'] : '';

    if ($user === '' || $pass === '') {
        json_out(array('ok' => false, 'error' => 'Completá usuario y contraseña.'), 400);
    }

    if (admin_login($user, $pass)) {
        activity_log('login', 'Ingreso correcto: ' . $user);
        json_out(array('ok' => true, 'csrf' => csrf_token()));
    }

    usleep(300000); // pequeña demora ante intentos fallidos
    activity_log('login_fail', 'Intento fallido: ' . $user);
    json_out(array('ok' => false, 'error' => 'Usuario o contraseña incorrectos.'), 401);
}

// --- Salir -----------------------------------------------------------------
if ($act === 'logout') {
    require_api_auth();
    require_csrf();
    activity_log('logout', 'Cierre de sesión');
    admin_logout();
    json_out(array('ok' => true));
}

// --- Cambio de contraseña --------------------------------------------------
if ($act === 'change_password') {
    require_api_auth();
    require_csrf();

    $current = isset($body['current']) ? (string) $body['current'] : '';
    $new     = isset($body['new']) ? (string) $body['new'] : '';

    if (strlen($new) < 6) {
        json_out(array('ok' => false, 'error' => 'La nueva contraseña debe tener al menos 6 caracteres.'), 400);
    }
    if (!admin_change_password($current, $new)) {
        json_out(array('ok' => false, 'error' => 'La contraseña actual no es correcta.'), 400);
    }
    activity_log('password', 'Contraseña actualizada');
    json_out(array('ok' => true));
}

json_out(array('ok' => false, 'error' => 'Acción desconocida.'), 400);
