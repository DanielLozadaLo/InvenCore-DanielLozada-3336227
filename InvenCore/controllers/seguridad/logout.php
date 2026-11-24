<?php
/**
 * InvenCore - Cierre de sesión
 * Ruta: controllers/seguridad/logout.php
 *
 * Destruye la sesión en el servidor, borra la cookie y vuelve al login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';

iniciarSesionSegura();

// 1. Vaciar los datos de la sesión
$_SESSION = [];

// 2. Borrar la cookie de sesión del navegador
if (ini_get('session.use_cookies')) {
    $cookie = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $cookie['path'],
        'domain'   => $cookie['domain'],
        'secure'   => $cookie['secure'],
        'httponly' => $cookie['httponly'],
        'samesite' => $cookie['samesite'] ?: 'Lax',
    ]);
}

// 3. Destruir la sesión en el servidor
session_destroy();

// 4. Volver al login
header('Location: ../../views/seguridad/login.html');
exit;