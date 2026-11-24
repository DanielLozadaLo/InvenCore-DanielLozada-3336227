<?php
/**
 * InvenCore - Controlador de registro de usuarios
 * Ruta: controllers/seguridad/registro.php
 *
 * Recibe por POST los datos de views/seguridad/registro.html, los valida
 * y crea el usuario en la tabla `usuarios` de forma segura.
 *
 * Flujo:
 *   registro.html --POST--> registro.php --valida--> MySQL (PDO) --> login.html
 *                                         \-- si falla --> registro.html?error=codigo
 */

declare(strict_types=1);

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const URL_REGISTRO             = '../../views/seguridad/registro.html';
const URL_LOGIN                = '../../views/seguridad/login.html';
const ROL_PUBLICO              = 'empleado'; // El rol lo decide el servidor, nunca el usuario
const LONGITUD_MINIMA_PASSWORD = 8;
const LONGITUD_MAXIMA_TEXTO    = 100;        // Límite de nombre y correo (ajústalo al VARCHAR de tu tabla)

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

/**
 * Valida las reglas de negocio del registro.
 * Devuelve un código de error (string) o null si todo es correcto.
 */
function validarDatos(string $nombre, string $correo, string $password, string $confirmacion): ?string
{
    // a) Campos obligatorios
    if ($nombre === '' || $correo === '' || $password === '' || $confirmacion === '') {
        return 'campos_vacios';
    }

    // b) Nombre: longitud razonable
    $largoNombre = mb_strlen($nombre, 'UTF-8');
    if ($largoNombre < 3 || $largoNombre > LONGITUD_MAXIMA_TEXTO) {
        return 'nombre_invalido';
    }

    // c) Formato de correo
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL) || mb_strlen($correo, 'UTF-8') > LONGITUD_MAXIMA_TEXTO) {
        return 'correo_invalido';
    }

    // d) Longitud mínima de la contraseña
    if (strlen($password) < LONGITUD_MINIMA_PASSWORD) {
        return 'password_corta';
    }

    // e) Las contraseñas deben coincidir
    if ($password !== $confirmacion) {
        return 'password_no_coincide';
    }

    return null; // Todo correcto
}

/* =====================================================================
 * 3. SOLO SE ACEPTA POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirigir(URL_REGISTRO);
}

/* =====================================================================
 * 4. RECEPCIÓN Y LIMPIEZA DE DATOS
 *    (los nombres coinciden con el atributo name de registro.html)
 * ===================================================================== */
$nombre       = trim(obtenerTexto('nombre'));
$correo       = strtolower(trim(obtenerTexto('correo')));
$password     = obtenerTexto('password');            // La contraseña NO se recorta con trim()
$confirmacion = obtenerTexto('confirmar_password');
$rol          = ROL_PUBLICO;                         // Se ignora cualquier "rol" que llegue por POST

/* =====================================================================
 * 5. VALIDACIONES DE NEGOCIO
 * ===================================================================== */
$error = validarDatos($nombre, $correo, $password, $confirmacion);
if ($error !== null) {
    redirigir(URL_REGISTRO, ['error' => $error]);
}

/* =====================================================================
 * 6. ACCESO A LA BASE DE DATOS (PDO + consultas preparadas)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }

    // Garantiza que los errores de MySQL se lancen como excepciones (para el catch)
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 6.1 ¿El correo ya está registrado?
    $consulta = $conexion->prepare('SELECT id_usuario FROM usuarios WHERE correo = :correo LIMIT 1');
    $consulta->execute([':correo' => $correo]);

    if ($consulta->fetch() !== false) {
        redirigir(URL_REGISTRO, ['error' => 'correo_existente']);
    }

    // 6.2 Hash seguro de la contraseña (nunca se guarda en texto plano)
    $hash = password_hash($password, PASSWORD_DEFAULT);

    // 6.3 Inserción del nuevo usuario con rol 'empleado'
    $insertar = $conexion->prepare(
        'INSERT INTO usuarios (nombre, rol, correo, contrasena)
         VALUES (:nombre, :rol, :correo, :contrasena)'
    );
    $insertar->execute([
        ':nombre'     => $nombre,
        ':rol'        => $rol,
        ':correo'     => $correo,
        ':contrasena' => $hash,
    ]);

    // 6.4 Registro exitoso → login
    redirigir(URL_LOGIN, ['registro' => 'ok']);

} catch (PDOException $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][registro] PDOException: ' . $e->getMessage());

    // Código 1062 de MySQL = clave duplicada (el UNIQUE de correo lo rechazó)
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        redirigir(URL_REGISTRO, ['error' => 'correo_existente']);
    }
    redirigir(URL_REGISTRO, ['error' => 'error_servidor']);

} catch (Throwable $e) {
    error_log('[InvenCore][registro] Error: ' . $e->getMessage());
    redirigir(URL_REGISTRO, ['error' => 'error_servidor']);
}