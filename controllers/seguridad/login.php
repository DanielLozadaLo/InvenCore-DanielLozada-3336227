<?php
/**
 * InvenCore - Controlador de inicio de sesión
 * Ruta: controllers/seguridad/login.php
 *
 * Recibe por POST el correo y la contraseña de views/seguridad/login.html,
 * verifica las credenciales contra la tabla `usuarios` y abre una sesión PHP.
 *
 * Flujo:
 *   login.html --POST--> login.php --consulta preparada--> MySQL (PDO)
 *                                  --password_verify()-->  ¿válida?
 *        sí: sesión + session_regenerate_id(true) --> ../../index.html
 *        no: login.html?error=codigo  (mensaje genérico)
 */

declare(strict_types=1);

// Función iniciarSesionSegura(): configuración compartida de la sesión
require_once __DIR__ . '/../../config/sesion.php';

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const URL_LOGIN  = '../../views/seguridad/login.html';
const URL_INICIO = '../../index.html';

/* =====================================================================
 * 2. FUNCIONES AUXILIARES
 * ===================================================================== */

/** Redirige a una URL (con parámetros opcionales en la query) y detiene el script. */
function redirigir(string $url, array $parametros = []): void
{
    if (!empty($parametros)) {
        $url .= '?' . http_build_query($parametros);
    }
    header('Location: ' . $url);
    exit;
}

/** Lee un campo de $_POST como texto. Si falta o no es texto (p. ej. un arreglo), devuelve ''. */
function obtenerTexto(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? $valor : '';
}

/* =====================================================================
 * 3. SOLO SE ACEPTA POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirigir(URL_LOGIN);
}

/* =====================================================================
 * 4. RECEPCIÓN Y VERIFICACIÓN DE CAMPOS
 *    (los nombres coinciden con el atributo name de login.html)
 * ===================================================================== */
$correo   = strtolower(trim(obtenerTexto('email')));
$password = obtenerTexto('password');                 // La contraseña NO se recorta con trim()

if ($correo === '' || $password === '') {
    redirigir(URL_LOGIN, ['error' => 'campos_vacios']);
}

/* =====================================================================
 * 5. AUTENTICACIÓN
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }

    // Garantiza que los errores de MySQL se lancen como excepciones (para el catch)
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 5.1 Buscar al usuario por correo (consulta preparada: sin riesgo de inyección SQL)
    $consulta = $conexion->prepare(
        'SELECT id_usuario, nombre, rol, contrasena
           FROM usuarios
          WHERE correo = :correo
          LIMIT 1'
    );
    $consulta->execute([':correo' => $correo]);
    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);

    // 5.2 Verificar la contraseña con password_verify().
    //     Si el usuario no existe se compara contra un hash falso, para que la
    //     respuesta tarde lo mismo y no se pueda deducir qué correos existen.
    $hashAComparar = ($usuario !== false)
        ? (string) $usuario['contrasena']
        : password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

    $credencialesValidas = password_verify($password, $hashAComparar) && $usuario !== false;

    // 5.3 Credenciales incorrectas: mensaje GENÉRICO (no revela si falló el correo o la clave)
    if (!$credencialesValidas) {
        redirigir(URL_LOGIN, ['error' => 'credenciales']);
    }

    // 5.4 Credenciales correctas: abrir sesión segura
    iniciarSesionSegura();
    session_regenerate_id(true);   // Nuevo ID de sesión: evita la fijación de sesión

    $_SESSION = [];                // Descarta cualquier dato anterior de esa sesión
    $_SESSION['id_usuario'] = (int) $usuario['id_usuario'];
    $_SESSION['nombre']     = (string) $usuario['nombre'];
    $_SESSION['rol']        = (string) $usuario['rol'];
    // Nunca se guarda la contraseña ni su hash en la sesión

    session_write_close();         // Asegura que la sesión quede guardada antes de redirigir

    // 5.5 Ir al menú principal
    redirigir(URL_INICIO);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][login] ' . get_class($e) . ': ' . $e->getMessage());
    redirigir(URL_LOGIN, ['error' => 'error_servidor']);
}