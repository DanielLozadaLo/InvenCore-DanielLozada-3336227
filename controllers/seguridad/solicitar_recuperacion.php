<?php
/**
 * InvenCore - Controlador: solicitar recuperación de contraseña
 * Ruta: controllers/seguridad/solicitar_recuperacion.php
 *
 * Paso 1 de la recuperación. Recibe por POST el correo (campo "correo") desde
 * views/seguridad/recuperar-password.html. Si el usuario existe:
 *   1) genera un token aleatorio criptográficamente seguro,
 *   2) guarda en la BD el HASH del token y una expiración de 1 hora,
 *   3) (aquí iría el envío del correo con el enlace de recuperación).
 *
 * SEGURIDAD:
 *  - La respuesta es SIEMPRE la misma exista o no el correo: nunca se revela quién está registrado.
 *  - En la BD solo se guarda el hash SHA-256 del token. El token real únicamente viaja en el enlace
 *    (correo): si alguien viera la tabla `usuarios`, no podría usar los tokens guardados.
 *  - Un token nuevo reemplaza al anterior, así que solo el último enlace solicitado sirve.
 *
 * Flujo:
 *   recuperar-password.html --POST--> solicitar_recuperacion.php
 *        siempre: recuperar-password.html?enviado=1   (mensaje genérico)
 *        solo en pruebas locales: &prueba=TOKEN       (enlace simulado en pantalla)
 */

declare(strict_types=1);

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const URL_RECUPERAR = '../../views/seguridad/recuperar-password.html';

/**
 * SOLO PARA PRUEBAS LOCALES: muestra en pantalla el enlace que normalmente llegaría por correo.
 * Con esto activo, la pantalla deja ver si el correo existe (solo así aparece el enlace).
 * En un sistema real debe ser false y el enlace se envía por correo (mail() o PHPMailer).
 */
const MOSTRAR_ENLACE_SIMULADO = true;

const LONGITUD_MAXIMA_CORREO = 100;

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
    redirigir(URL_RECUPERAR);
}

/* =====================================================================
 * 4. VALIDACIÓN DEL CORREO (solo formato: no revela nada sobre la BD)
 * ===================================================================== */
$correo = strtolower(trim(obtenerTexto('correo')));

if ($correo === ''
    || mb_strlen($correo, 'UTF-8') > LONGITUD_MAXIMA_CORREO
    || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
    redirigir(URL_RECUPERAR, ['error' => 'correo_invalido']);
}

/* =====================================================================
 * 5. GENERAR Y GUARDAR EL TOKEN (PDO + consultas preparadas)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 5.1 Buscar al usuario por correo
    $consulta = $conexion->prepare('SELECT id_usuario FROM usuarios WHERE correo = :correo LIMIT 1');
    $consulta->execute([':correo' => $correo]);
    $usuario = $consulta->fetch(PDO::FETCH_ASSOC);
    $consulta->closeCursor();

    // La respuesta al usuario es la misma en ambos casos
    $parametros = ['enviado' => '1'];

    if ($usuario !== false) {
        // 5.2 Token aleatorio de 64 caracteres hexadecimales (32 bytes de CSPRNG)
        $token = bin2hex(random_bytes(32));

        // 5.3 En la BD se guarda el hash del token (64 caracteres), con vigencia de 1 hora.
        //     La expiración se calcula con el reloj de MySQL (DATE_ADD(NOW(), ...)), el mismo
        //     que usa actualizar_password.php para compararla: así no hay desfases de zona horaria
        $guardar = $conexion->prepare(
            'UPDATE usuarios
                SET token_recuperacion = :token_hash,
                    token_expiracion   = DATE_ADD(NOW(), INTERVAL 1 HOUR)
              WHERE id_usuario = :id_usuario'
        );
        $guardar->bindValue(':token_hash', hash('sha256', $token), PDO::PARAM_STR);
        $guardar->bindValue(':id_usuario', (int) $usuario['id_usuario'], PDO::PARAM_INT);
        $guardar->execute();

        // 5.4 Aquí iría el envío real del correo con el enlace:
        //     recuperar-password.html?token=$token
        //     Para pruebas locales se muestra el enlace en pantalla (ver MOSTRAR_ENLACE_SIMULADO)
        if (MOSTRAR_ENLACE_SIMULADO) {
            $parametros['prueba'] = $token;
        }
    }

    redirigir(URL_RECUPERAR, $parametros);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][solicitar_recuperacion] ' . get_class($e) . ': ' . $e->getMessage());
    redirigir(URL_RECUPERAR, ['error' => 'error_servidor']);
}