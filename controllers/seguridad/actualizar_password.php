<?php
/**
 * InvenCore - Controlador: actualizar contraseña con token
 * Ruta: controllers/seguridad/actualizar_password.php
 *
 * Paso 2 de la recuperación. Recibe por POST desde recuperar-password.html:
 *   token (campo oculto), password y confirmar_password.
 *
 * Reglas:
 *   - Las contraseñas deben coincidir y tener al menos 8 caracteres.
 *   - El token debe existir y no haber expirado (token_expiracion > NOW()).
 *   - Si todo es válido: guarda el nuevo hash de la contraseña e INVALIDA el token (NULL),
 *     para que el enlace no pueda usarse una segunda vez.
 *
 * SEGURIDAD:
 *  - El token se busca por su hash SHA-256 (así se guardó en solicitar_recuperacion.php).
 *  - Se usa UN solo UPDATE condicionado al token y a la expiración: la verificación y el cambio
 *    son atómicos. Si dos peticiones llegan a la vez con el mismo token, solo una modifica la fila.
 *
 * Flujo:
 *   recuperar-password.html?token=... --POST--> actualizar_password.php
 *        éxito:  login.html?recuperacion=ok
 *        error de contraseña: recuperar-password.html?token=...&error=codigo  (puede reintentar)
 *        token inválido o expirado: recuperar-password.html?error=token_invalido
 */

declare(strict_types=1);

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const URL_RECUPERAR            = '../../views/seguridad/recuperar-password.html';
const URL_LOGIN                = '../../views/seguridad/login.html';
const LONGITUD_MINIMA_PASSWORD = 8;

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
 * 4. RECEPCIÓN Y VALIDACIÓN
 *    (los nombres coinciden con el atributo name de recuperar-password.html)
 * ===================================================================== */
$token        = strtolower(trim(obtenerTexto('token')));
$password     = obtenerTexto('password');               // La contraseña NO se recorta con trim()
$confirmacion = obtenerTexto('confirmar_password');

// 4.1 El token debe tener el formato esperado: 64 caracteres hexadecimales.
//     Así, además, solo se devuelve a la pantalla un token que es seguro mostrar
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    redirigir(URL_RECUPERAR, ['error' => 'token_invalido']);
}

// 4.2 Contraseña: obligatoria, mínimo 8 caracteres y coincidir con la confirmación.
//     Si falla, se vuelve a la pantalla de cambio con el MISMO token para reintentar
if ($password === '' || $confirmacion === '') {
    redirigir(URL_RECUPERAR, ['token' => $token, 'error' => 'campos_vacios']);
}
if (strlen($password) < LONGITUD_MINIMA_PASSWORD) {
    redirigir(URL_RECUPERAR, ['token' => $token, 'error' => 'password_corta']);
}
if ($password !== $confirmacion) {
    redirigir(URL_RECUPERAR, ['token' => $token, 'error' => 'password_no_coincide']);
}

/* =====================================================================
 * 5. ACTUALIZACIÓN EN LA BASE DE DATOS (PDO + consultas preparadas)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 5.1 Hash seguro de la nueva contraseña (nunca se guarda en texto plano)
    $hashPassword = password_hash($password, PASSWORD_DEFAULT);

    // 5.2 UN solo UPDATE: solo modifica al usuario cuyo token coincide Y no ha expirado.
    //     Al mismo tiempo invalida el token (NULL): no puede usarse otra vez
    $actualizar = $conexion->prepare(
        'UPDATE usuarios
            SET contrasena         = :contrasena,
                token_recuperacion = NULL,
                token_expiracion   = NULL
          WHERE token_recuperacion = :token_hash
            AND token_expiracion   > NOW()'
    );
    $actualizar->bindValue(':contrasena', $hashPassword,           PDO::PARAM_STR);
    $actualizar->bindValue(':token_hash', hash('sha256', $token),  PDO::PARAM_STR);
    $actualizar->execute();

    // 5.3 rowCount() = filas modificadas. Si es 0, el token no existe, ya se usó o expiró
    if ($actualizar->rowCount() !== 1) {
        redirigir(URL_RECUPERAR, ['error' => 'token_invalido']);
    }

    // 5.4 Éxito: al login con un mensaje
    redirigir(URL_LOGIN, ['recuperacion' => 'ok']);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][actualizar_password] ' . get_class($e) . ': ' . $e->getMessage());
    redirigir(URL_RECUPERAR, ['token' => $token, 'error' => 'error_servidor']);
}