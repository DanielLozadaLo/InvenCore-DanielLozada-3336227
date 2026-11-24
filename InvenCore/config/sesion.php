<?php
/**
 * InvenCore - Configuración compartida de la sesión PHP
 * Ruta: config/sesion.php
 *
 * Lo usan login.php, sesion.php y logout.php, para que la cookie de sesión
 * tenga SIEMPRE la misma configuración segura.
 */

declare(strict_types=1);

/** Inicia la sesión PHP (si aún no está activa) con una cookie de sesión segura. */
function iniciarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $esHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.use_strict_mode', '1');   // Rechaza IDs de sesión que el servidor no creó
    ini_set('session.use_only_cookies', '1');  // El ID de sesión nunca viaja por la URL

    session_set_cookie_params([
        'lifetime' => 0,          // La cookie expira al cerrar el navegador
        'path'     => '/',
        'secure'   => $esHttps,   // Solo por HTTPS cuando el sitio lo use
        'httponly' => true,       // JavaScript no puede leer la cookie (mitiga XSS)
        'samesite' => 'Lax',      // Mitiga ataques CSRF
    ]);

    session_start();
}