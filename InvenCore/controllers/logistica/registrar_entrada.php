<?php
/**
 * InvenCore - Controlador: registrar entrada (CREATE)
 * Ruta: controllers/logistica/registrar_entrada.php
 *
 * Recibe por POST los datos del modal "Registrar Nueva Entrada" (entradas.js lo envía con fetch)
 * e inserta un movimiento de tipo 'ENTRADA' con estado 'Pendiente'.
 *
 * IMPORTANTE: aquí NO se modifica el stock. La mercancía todavía debe pasar por inspección;
 * el stock se suma recién cuando la entrada se aprueba (actualizar_estado_entrada.php).
 *
 * Campos POST: id_producto, id_proveedor, cantidad, almacen_destino, motivo (opcional)
 * Los demás datos NO los decide el cliente: tipo_movimiento, nro_guia, fecha, estado e id_usuario.
 *
 * Respuestas (JSON):
 *   200 {"ok": true, "nro_guia": "OC-2026-0417", "estado": "Pendiente"}
 *   401 / 404 / 405 / 422 / 500 {"ok": false, "error": "codigo"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';   // iniciarSesionSegura()
require_once __DIR__ . '/../../config/zonas_sga.php';   // ZONAS_SGA (lista oficial de ubicaciones)

/* =====================================================================
 * 1. CONSTANTES
 * ===================================================================== */
const MODO_DEPURACION   = false;                       // true SOLO mientras depuras en local
const TIPO_MOVIMIENTO   = 'ENTRADA';
const ESTADO_INICIAL    = 'Pendiente';                 // Primera etapa del flujo: Pendiente → En Inspección → Recepcionado
const PREFIJO_GUIA      = 'OC';                        // Resultado: OC-2026-0417
const INTENTOS_GUIA     = 5;                           // Reintentos si el número aleatorio ya existe
const CANTIDAD_MAXIMA   = 1000000;

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

/** Lee un campo de $_POST como texto sin espacios sobrantes. Si falta o no es texto, devuelve ''. */
function obtenerTexto(string $campo): string
{
    $valor = $_POST[$campo] ?? '';
    return is_string($valor) ? trim($valor) : '';
}

/**
 * Valida y normaliza los datos de la entrada.
 * Devuelve [$datos, $error]: si hay error, $error es un código y $datos queda vacío.
 */
function validarEntrada(array $entrada): array
{
    // a) Campos obligatorios
    foreach (['id_producto', 'id_proveedor', 'cantidad', 'almacen_destino'] as $campo) {
        if ($entrada[$campo] === '') {
            return [[], 'campos_vacios'];
        }
    }

    // b) Llaves foráneas: enteros positivos (que existan se comprueba en la base de datos)
    $opcionesId = ['options' => ['min_range' => 1]];
    $idProducto = filter_var($entrada['id_producto'], FILTER_VALIDATE_INT, $opcionesId);
    if ($idProducto === false) {
        return [[], 'producto_invalido'];
    }
    $idProveedor = filter_var($entrada['id_proveedor'], FILTER_VALIDATE_INT, $opcionesId);
    if ($idProveedor === false) {
        return [[], 'proveedor_invalido'];
    }

    // c) Cantidad: entero entre 1 y el máximo permitido
    $cantidad = filter_var(
        $entrada['cantidad'],
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => CANTIDAD_MAXIMA]]
    );
    if ($cantidad === false) {
        return [[], 'cantidad_invalida'];
    }

    // d) Almacén y pasillo: solo una de las zonas oficiales del SGA (config/zonas_sga.php)
    if (!array_key_exists($entrada['almacen_destino'], ZONAS_SGA)) {
        return [[], 'almacen_invalido'];
    }

    // e) Motivo / observación: opcional, máximo 255 caracteres
    if (mb_strlen($entrada['motivo'], 'UTF-8') > 255) {
        return [[], 'motivo_largo'];
    }

    return [[
        'id_producto'     => (int) $idProducto,
        'id_proveedor'    => (int) $idProveedor,
        'cantidad'        => (int) $cantidad,
        'almacen_destino' => $entrada['almacen_destino'],
        'motivo'          => $entrada['motivo'],
    ], null];
}

/* =====================================================================
 * 3. SOLO POST
 * ===================================================================== */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* =====================================================================
 * 4. SOLO USUARIOS CON SESIÓN ACTIVA: el id_usuario sale de la sesión, nunca del formulario
 * ===================================================================== */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
$idUsuario = (int) $_SESSION['id_usuario'];
session_write_close();

/* =====================================================================
 * 5. RECEPCIÓN Y VALIDACIÓN
 * ===================================================================== */
$entrada = [
    'id_producto'     => obtenerTexto('id_producto'),
    'id_proveedor'    => obtenerTexto('id_proveedor'),
    'cantidad'        => obtenerTexto('cantidad'),
    'almacen_destino' => obtenerTexto('almacen_destino'),
    'motivo'          => obtenerTexto('motivo'),
];

[$datos, $error] = validarEntrada($entrada);
if ($error !== null) {
    responderJson(['ok' => false, 'error' => $error], 422);
}

/* =====================================================================
 * 6. INSERCIÓN (PDO + consultas preparadas)
 * ===================================================================== */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 6.1 El producto debe existir
    $consulta = $conexion->prepare('SELECT id_producto FROM productos WHERE id_producto = :id_producto LIMIT 1');
    $consulta->bindValue(':id_producto', $datos['id_producto'], PDO::PARAM_INT);
    $consulta->execute();
    if ($consulta->fetch() === false) {
        responderJson(['ok' => false, 'error' => 'producto_no_encontrado'], 404);
    }
    $consulta->closeCursor();

    // 6.2 El proveedor debe existir
    $consulta = $conexion->prepare('SELECT id_proveedor FROM proveedores WHERE id_proveedor = :id_proveedor LIMIT 1');
    $consulta->bindValue(':id_proveedor', $datos['id_proveedor'], PDO::PARAM_INT);
    $consulta->execute();
    if ($consulta->fetch() === false) {
        responderJson(['ok' => false, 'error' => 'proveedor_no_encontrado'], 404);
    }
    $consulta->closeCursor();

    // 6.3 Generar un nro_guia único: OC-AAAA-NNNN (NNNN aleatorio), verificando que no exista
    $buscarGuia = $conexion->prepare('SELECT 1 FROM movimientos WHERE nro_guia = :nro_guia LIMIT 1');
    $nroGuia = null;
    for ($intento = 0; $intento < INTENTOS_GUIA; $intento++) {
        $candidato = sprintf('%s-%s-%04d', PREFIJO_GUIA, date('Y'), random_int(0, 9999));
        $buscarGuia->execute([':nro_guia' => $candidato]);
        $ocupado = $buscarGuia->fetchColumn() !== false;
        $buscarGuia->closeCursor();

        if (!$ocupado) {
            $nroGuia = $candidato;
            break;
        }
    }
    if ($nroGuia === null) {
        throw new RuntimeException('No se pudo generar un nro_guia único.');
    }

    // 6.4 Insertar el movimiento en estado 'Pendiente' (fecha = NOW() del servidor de base de datos)
    $insertar = $conexion->prepare(
        'INSERT INTO movimientos
            (tipo_movimiento, nro_guia, cantidad, fecha, estado, motivo, almacen_destino, id_producto, id_usuario, id_proveedor)
         VALUES
            (:tipo_movimiento, :nro_guia, :cantidad, NOW(), :estado, :motivo, :almacen_destino, :id_producto, :id_usuario, :id_proveedor)'
    );
    $insertar->bindValue(':tipo_movimiento', TIPO_MOVIMIENTO,           PDO::PARAM_STR);
    $insertar->bindValue(':nro_guia',        $nroGuia,                  PDO::PARAM_STR);
    $insertar->bindValue(':cantidad',        $datos['cantidad'],        PDO::PARAM_INT);
    $insertar->bindValue(':estado',          ESTADO_INICIAL,            PDO::PARAM_STR);
    $insertar->bindValue(':motivo',          $datos['motivo'],          PDO::PARAM_STR);
    $insertar->bindValue(':almacen_destino', $datos['almacen_destino'], PDO::PARAM_STR);
    $insertar->bindValue(':id_producto',     $datos['id_producto'],     PDO::PARAM_INT);
    $insertar->bindValue(':id_usuario',      $idUsuario,                PDO::PARAM_INT);
    $insertar->bindValue(':id_proveedor',    $datos['id_proveedor'],    PDO::PARAM_INT);
    $insertar->execute();

    responderJson([
        'ok'       => true,
        'nro_guia' => $nroGuia,
        'estado'   => ESTADO_INICIAL,
    ]);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][registrar_entrada] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}