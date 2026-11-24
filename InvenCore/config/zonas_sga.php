<?php
/**
 * InvenCore - Zonas oficiales del SGA (Sistema de Gestión de Almacén)
 * Ruta: config/zonas_sga.php
 *
 * UNA sola lista que usan varios controladores, para que las ubicaciones no sean texto libre:
 *   - registrar_producto.php / editar_producto.php → productos.ubicacion_sga
 *   - registrar_entrada.php                        → movimientos.almacen_destino
 *   - listar_almacenes.php                         → ocupación de cada zona
 *
 * La CLAVE de cada elemento es el texto EXACTO que se guarda en la base de datos
 * (y el value de los <select> de los formularios). Si cambias un texto aquí,
 * cámbialo también en catalogo.html y entradas.html.
 *
 *   clave     → identificador corto que usan almacenes.html y almacenes.js
 *   almacen   → almacén al que pertenece la zona
 *   nombre    → nombre de la zona
 *   capacidad → capacidad máxima TEÓRICA en unidades (para calcular el % de ocupación)
 */

declare(strict_types=1);

const ZONAS_SGA = [
    'Almacén Principal - Pasillo A (Estante 01 - 04)' => [
        'clave' => 'pasillo-a', 'almacen' => 'Almacén Principal', 'nombre' => 'Pasillo A', 'capacidad' => 50,
    ],
    'Almacén Principal - Pasillo B (Estante 01 - 05)' => [
        'clave' => 'pasillo-b', 'almacen' => 'Almacén Principal', 'nombre' => 'Pasillo B', 'capacidad' => 50,
    ],
    'Almacén Secundario - Pasillo C (Estante 01 - 08)' => [
        'clave' => 'pasillo-c', 'almacen' => 'Almacén Secundario', 'nombre' => 'Pasillo C', 'capacidad' => 50,
    ],
    'Almacén Secundario - Zona D (Pallets)' => [
        'clave' => 'zona-d', 'almacen' => 'Almacén Secundario', 'nombre' => 'Zona D (Pallets)', 'capacidad' => 50,
    ],
];