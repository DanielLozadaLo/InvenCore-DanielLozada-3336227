<?php
/**
 * InvenCore - Controlador: ocupación de almacenes y zonas del SGA (READ)
 * Ruta: controllers/logistica/listar_almacenes.php
 *
 * Suma el stock_actual de los productos AGRUPADO por ubicacion_sga y calcula, para cada zona
 * oficial (config/zonas_sga.php), su porcentaje de ocupación frente a una capacidad máxima teórica.
 *
 *   porcentaje = unidades en la zona / capacidad * 100
 *   estado     = "Casi Saturado" si porcentaje >= 90, de lo contrario "Espacio Disponible"
 *
 * Respuestas:
 *   200 {
 *         "ok": true,
 *         "zonas": [ {"clave":"pasillo-a","ubicacion":"Almacén Principal - Pasillo A (Estante 01 - 04)",
 *                     "almacen":"Almacén Principal","nombre":"Pasillo A","productos":3,"unidades":39,
 *                     "capacidad":50,"porcentaje":78,"estado":"Espacio Disponible"}, ... ],
 *         "sin_zona": {"productos": 0, "unidades": 0}   productos cuya ubicación NO es una zona oficial
 *       }
 *   401 {"ok": false, "error": "sin_sesion"}
 *   405 {"ok": false, "error": "metodo_no_permitido"}
 *   500 {"ok": false, "error": "error_servidor"}
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/sesion.php';      // iniciarSesionSegura()
require_once __DIR__ . '/../../config/zonas_sga.php';   // ZONAS_SGA

// Ponla en true SOLO mientras depuras en local: la respuesta incluirá el mensaje técnico del error.
const MODO_DEPURACION = false;

const UMBRAL_SATURACION  = 90;                 // % a partir del cual la zona se considera casi saturada
const ESTADO_SATURADO    = 'Casi Saturado';
const ESTADO_DISPONIBLE  = 'Espacio Disponible';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');              // Siempre datos frescos

/** Envía una respuesta JSON con su código HTTP y detiene el script. */
function responderJson(array $datos, int $codigoHttp = 200): void
{
    http_response_code($codigoHttp);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/* 1. Solo GET */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    responderJson(['ok' => false, 'error' => 'metodo_no_permitido'], 405);
}

/* 2. Solo usuarios con sesión activa */
iniciarSesionSegura();
if (!isset($_SESSION['id_usuario'])) {
    responderJson(['ok' => false, 'error' => 'sin_sesion'], 401);
}
session_write_close();

/* 3. Consulta y cálculo */
try {
    // Reutiliza la conexión existente: config/database.php define $conexion (PDO)
    require_once __DIR__ . '/../../config/database.php';

    if (!isset($conexion) || !($conexion instanceof PDO)) {
        throw new RuntimeException('La variable $conexion no está disponible.');
    }
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 3.1 Productos y unidades por ubicación (COALESCE evita NULL si no hay stock)
    $consulta = $conexion->prepare(
        'SELECT ubicacion_sga,
                COUNT(*) AS productos,
                COALESCE(SUM(stock_actual), 0) AS unidades
           FROM productos
          GROUP BY ubicacion_sga'
    );
    $consulta->execute();
    $filas = $consulta->fetchAll(PDO::FETCH_ASSOC);

    // Se indexa por ubicación para buscar cada zona rápidamente
    $porUbicacion = [];
    foreach ($filas as $fila) {
        $porUbicacion[(string) ($fila['ubicacion_sga'] ?? '')] = [
            'productos' => (int) $fila['productos'],
            'unidades'  => (int) $fila['unidades'],
        ];
    }

    // 3.2 Una entrada por cada zona oficial (las zonas sin productos quedan en 0%)
    $zonas = [];
    foreach (ZONAS_SGA as $ubicacion => $zona) {
        $productos = $porUbicacion[$ubicacion]['productos'] ?? 0;
        $unidades  = $porUbicacion[$ubicacion]['unidades'] ?? 0;
        $capacidad = (int) $zona['capacidad'];

        // Puede superar 100% si hay más stock que la capacidad teórica
        $porcentaje = $capacidad > 0 ? (int) round($unidades / $capacidad * 100) : 0;

        $zonas[] = [
            'clave'      => $zona['clave'],
            'ubicacion'  => $ubicacion,
            'almacen'    => $zona['almacen'],
            'nombre'     => $zona['nombre'],
            'productos'  => $productos,
            'unidades'   => $unidades,
            'capacidad'  => $capacidad,
            'porcentaje' => $porcentaje,
            'estado'     => $porcentaje >= UMBRAL_SATURACION ? ESTADO_SATURADO : ESTADO_DISPONIBLE,
        ];
        unset($porUbicacion[$ubicacion]);
    }

    // 3.3 Lo que sobra no coincide con ninguna zona oficial (p. ej. ubicaciones escritas a mano antes)
    $sinZona = ['productos' => 0, 'unidades' => 0];
    foreach ($porUbicacion as $datos) {
        $sinZona['productos'] += $datos['productos'];
        $sinZona['unidades']  += $datos['unidades'];
    }

    responderJson(['ok' => true, 'zonas' => $zonas, 'sin_zona' => $sinZona]);

} catch (Throwable $e) {
    // El detalle técnico va al log del servidor, NUNCA al usuario
    error_log('[InvenCore][listar_almacenes] ' . get_class($e) . ': ' . $e->getMessage());

    $respuesta = ['ok' => false, 'error' => 'error_servidor'];
    if (MODO_DEPURACION) {
        $respuesta['detalle'] = $e->getMessage();   // Solo en depuración
    }
    responderJson($respuesta, 500);
}