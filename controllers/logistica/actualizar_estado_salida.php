<?php
/**
 * InvenCore - Controlador: actualizar el estado de una salida (UPDATE)
 * Ruta: controllers/logistica/actualizar_estado_salida.php
 *
 * Avanza un despacho por las etapas logísticas. salidas.js lo llama con fetch().
 *
 *   accion = "iniciar_picking":    'Pendiente'  → 'En Picking'
 *   accion = "completar_packing":  'En Picking' → 'Embalado'
 *   accion = "despachar":          'Embalado'   → 'Despachado'
 *
 * Campos POST: id_movimiento, accion
 *
 * El stock NO cambia aquí: ya se restó al registrar la orden (registrar_salida.php).
 *
 * El servidor comprueba que el despacho esté en el estado que corresponde a la acción:
 * así un doble clic, o una pantalla desactualizada, no puede saltarse una etapa.
 *
 * Respuestas (JSON):
 *   200 {"ok": true, "estado": "Embalado", "nro_guia": "GR-2026-0417"}
 *   400 {"ok": false, "error": "id_invalido" | "accion_invalida"}
 *   401 {"ok": false, "error": "sin_sesion"}
 *   404 {"ok": false, "error": "no_encontrado"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   409 {"ok": false, "error": "estado_desactualizado"}
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const MODO_DEPURACION = false;                       // true SOLO mientras depuras en local

/**
 * Acciones permitidas:
 *   desde → estado actual requerido (normalizado: minúsculas)
 *   hasta → estado nuevo que se guarda en movimientos.estado
 */
const ACCIONES = [
    'iniciar_picking'   => ['desde' => 'pendiente',  'hasta' => 'En Picking'],
    'completar_packing' => ['desde' => 'en picking', 'hasta' => 'Embalado'],
    'despachar'         => ['desde' => 'embalado',   'hasta' => 'Despachado'],
];

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/* =====================================================================
 * 2. FUNCIONES AUXILIARES
 * ===================================================================== */

/** Envía una respuesta JSON con su código HTTP y detiene el script. */
function responderJson(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Normaliza un estado para compararlo: "En Picking" → "en picking". */
function claveEstado(string $estado): string
{
    return mb_strtolower(trim($estado), 'UTF-8');
}

/* =====================================================================
 * 3. SOLO POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* =====================================================================
 * 4. SOLO USUARIOS CON SESIÓN ACTIVA
 * ===================================================================== */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
session_write_close();

/* =====================================================================
 * 5. VALIDACIÓN DE LO RECIBIDO
 * ===================================================================== */
$idRecibido = $_POST['id_movimiento'] ?? '';
$idMovimiento = is_string($idRecibido)
    ? filter_var(trim($idRecibido), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($idMovimiento === false) {
    responderJson(['ok' => false, 'error' => 'id_invalido'], 400);
}

$accionRecibida = $_POST['accion'] ?? '';
$accion = is_string($accionRecibida) ? trim($accionRecibida) : '';
if (!array_key_exists($accion, ACCIONES)) {
    responderJson(['ok' => false, 'error' => 'accion_invalida'], 400);
}
$reglas = ACCIONES[$accion];

/* =====================================================================
 * 6. TRANSACCIÓN: comprobar el estado actual y guardar el nuevo
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conexion->beginTransaction();

    // 6.1 La salida debe existir. FOR UPDATE bloquea su fila: dos clics simultáneos no pueden aplicarse a la vez
    $consulta = $conexion->prepare(
        'SELECT nro_guia, estado
           FROM movimientos
          WHERE id_movimiento = :id_movimiento AND tipo_movimiento = :tipo
          FOR UPDATE'
    );
    $consulta->bindValue(':id_movimiento', $idMovimiento, PDO::PARAM_INT);
    $consulta->bindValue(':tipo', 'SALIDA', PDO::PARAM_STR);
    $consulta->execute();
    $salida = $consulta->fetch(PDO::FETCH_ASSOC);
    $consulta->closeCursor();

    if ($salida === false) {
        $conexion->rollBack();
        responderJson(['ok' => false, 'error' => 'no_encontrado'], 404);
    }

    // 6.2 El despacho debe estar en el estado que esa acción exige
    if (claveEstado((string) $salida['estado']) !== $reglas['desde']) {
        $conexion->rollBack();
        responderJson(['ok' => false, 'error' => 'estado_desactualizado'], 409);
    }

    // 6.3 Guardar el nuevo estado
    $actualizar = $conexion->prepare('UPDATE movimientos SET estado = :estado WHERE id_movimiento = :id_movimiento');
    $actualizar->bindValue(':estado',        $reglas['hasta'], PDO::PARAM_STR);
    $actualizar->bindValue(':id_movimiento', $idMovimiento,    PDO::PARAM_INT);
    $actualizar->execute();

    $conexion->commit();

    responderJson([
        'ok'       => true,
        'estado'   => $reglas['hasta'],
        'nro_guia' => (string) $salida['nro_guia'],
    ]);

} catch (Throwable $e) {
    if (isset($conexion) && $conexion instanceof PDO && $conexion->inTransaction()) {
        $conexion->rollBack();
    }

    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][actualizar_estado_salida] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}