<?php
/**
 * InvenCore - Punto de entrada del proyecto
 * Ruta: index.php (raíz de InvenCore)
 *
 * Al abrir http://localhost/InvenCore/ Apache busca primero index.php
 * (antes que index.html), así que esta es la primera página que se ejecuta:
 *   - Sin sesión activa  → login
 *   - Con sesión activa  → dashboard (index.html)
 *
 * La redirección la hace el servidor, antes de enviar HTML: no hay parpadeos.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/sesion.php';

header('Cache-Control: no-store');

iniciarSesionSegura();

$destino = isset($_SESSION['id_usuario'])
    ? 'index.html'                    // Ya inició sesión: menú principal
    : 'views/seguridad/login.html';   // No ha iniciado sesión: login

session_write_close();
header('Location: ' . $destino);
exit;