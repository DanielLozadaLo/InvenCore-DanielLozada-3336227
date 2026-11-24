<?php
/**
 * InvenCore - Verificación de sesión
 * Ruta: controllers/seguridad/sesion.php
 *
 * main.js la consulta al cargar cada pantalla protegida.
 *   - Con sesión:  200 {"autenticado": true, "nombre": "...", "rol": "..."}
 *   - Sin sesión:  401 {"autenticado": false}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');   // Que el navegador no guarde esta respuesta

iniciarSesionSegura();

if (isset($_SESSION['id_usuario'])) {
    $respuesta = [
        'autenticado' => true,
        'nombre'      => (string) ($_SESSION['nombre'] ?? ''),
        'rol'         => (string) ($_SESSION['rol'] ?? ''),
    ];
    session_write_close();           // Libera la sesión: no bloquea otras peticiones
    echo json_encode($respuesta, JSON_UNESCAPED_UNICODE);
    exit;
}

session_write_close();
http_response_code(401);
echo json_encode(['autenticado' => false]);