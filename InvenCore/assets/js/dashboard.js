/**
 * InvenCore - Dashboard General (datos reales)
 *
 * Al cargar index.html pide las métricas a controllers/dashboard/obtener_metricas.php
 * y rellena las tarjetas de resumen y la tabla de "Monitoreo de Stock Crítico".
 */
document.addEventListener("DOMContentLoaded", () => {
  const URL_METRICAS = "controllers/dashboard/obtener_metricas.php";
  const URL_LOGIN = "views/seguridad/login.html";

  /* ---------- Elementos donde se inyectan los datos (ids de index.html) ---------- */
  const kpiProductos = document.getElementById("kpiProductos");
  const kpiUnidades = document.getElementById("kpiUnidades");
  const kpiEntradas = document.getElementById("kpiEntradas");
  const kpiSalidas = document.getElementById("kpiSalidas");
  const alertasBadge = document.getElementById("alertasBadge");
  const tablaAlertas = document.getElementById("tablaAlertas");

  /** 1250 -> "1.250" (separador de miles según el idioma) */
  const formatear = (n) => Number(n).toLocaleString("es-CO");

  /** "1 Producto" / "5 Productos" */
  const conPlural = (n, singular, plural) => `${formatear(n)} ${n === 1 ? singular : plural}`;

  /* =====================================================================
   * TARJETAS DE RESUMEN
   * ===================================================================== */
  function pintarTarjetas(m) {
    kpiProductos.textContent = conPlural(m.total_productos, "Producto", "Productos");
    kpiUnidades.textContent =
      `${conPlural(m.total_unidades, "Unidad", "Unidades")} físicamente en stock`;
    kpiEntradas.textContent = conPlural(m.total_entradas, "Orden", "Órdenes");
    kpiSalidas.textContent = conPlural(m.total_salidas, "Guía", "Guías");
  }

  /* =====================================================================
   * TABLA DE STOCK CRÍTICO
   * ===================================================================== */

  /** Crea una fila de alerta. Los datos se insertan con textContent (seguro contra XSS). */
  function crearFilaAlerta(p) {
    const fila = document.createElement("tr");

    const sku = fila.insertCell();
    sku.className = "ps-4 sku";
    sku.textContent = p.sku;

    const nombre = fila.insertCell();
    nombre.className = "fw-semibold";
    nombre.textContent = p.nombre;

    const ubicacion = fila.insertCell();
    ubicacion.className = "fw-semibold";
    ubicacion.textContent = p.ubicacion_sga || "Sin ubicación";

    const minimo = fila.insertCell();
    minimo.className = "text-center text-secondary";
    minimo.textContent = `${formatear(p.stock_minimo)} u.`;

    // Stock actual: pastilla roja/rosa (clase .badge-red de style.css)
    const actual = fila.insertCell();
    actual.className = "text-center";
    const badge = document.createElement("span");
    badge.className = "badge-red";
    badge.textContent = `${formatear(p.stock_actual)} u.`;
    actual.appendChild(badge);

    return fila;
  }

  /** Una sola fila que ocupa todo el ancho (cargando / todo en orden / error). */
  function filaMensaje(iconoHtml, texto, conReintento = false) {
    const fila = document.createElement("tr");
    const celda = fila.insertCell();
    celda.colSpan = 5;
    celda.className = "text-center text-muted py-5";
    celda.innerHTML = iconoHtml;           // Solo marcado propio (iconos), nunca datos
    celda.append(texto);

    if (conReintento) {
      const boton = document.createElement("button");
      boton.type = "button";
      boton.className = "btn btn-sm btn-outline-secondary d-block mx-auto mt-2";
      boton.textContent = "Reintentar";
      boton.addEventListener("click", cargarMetricas);
      celda.appendChild(boton);
    }
    return fila;
  }

  function pintarAlertas(m) {
    tablaAlertas.replaceChildren();

    if (m.stock_critico.length === 0) {
      // Sin productos críticos: mensaje positivo
      tablaAlertas.appendChild(
        filaMensaje(
          '<i class="bi bi-check-circle-fill text-success fs-3 d-block mb-2"></i>',
          "Todo en orden: no hay productos en stock crítico."
        )
      );
      alertasBadge.classList.add("d-none");
      return;
    }

    m.stock_critico.forEach((p) => tablaAlertas.appendChild(crearFilaAlerta(p)));

    alertasBadge.textContent = `${formatear(m.stock_critico_total)} ${m.stock_critico_total === 1 ? "requerido" : "requeridos"}`;
    alertasBadge.classList.remove("d-none");
  }

  /* =====================================================================
   * ERROR / CARGA
   * ===================================================================== */
  function mostrarError() {
    [kpiProductos, kpiEntradas, kpiSalidas].forEach((el) => (el.textContent = "—"));
    kpiUnidades.textContent = "No se pudieron cargar los datos";
    alertasBadge.classList.add("d-none");

    tablaAlertas.replaceChildren(
      filaMensaje(
        '<i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>',
        "No se pudieron cargar las alertas de stock.",
        true
      )
    );
  }

  async function cargarMetricas() {
    tablaAlertas.replaceChildren(
      filaMensaje('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>', "Cargando alertas...")
    );

    try {
      const response = await fetch(URL_METRICAS, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });

      if (response.status === 401) {          // Sesión vencida: al login
        window.location.replace(URL_LOGIN);
        return;
      }

      // Se lee como texto y se interpreta con cuidado: si PHP falla, el cuerpo puede venir vacío o no ser JSON
      const texto = await response.text();
      let datos = null;
      try {
        datos = JSON.parse(texto);
      } catch (errorJson) {
        /* respuesta vacía o que no es JSON: se informa abajo */
      }

      if (!response.ok || !datos || !datos.ok) {
        console.error(`obtener_metricas.php respondió HTTP ${response.status}:`, texto || "(respuesta vacía)");
        throw new Error((datos && datos.error) || `http_${response.status}`);
      }

      pintarTarjetas(datos.metricas);
      pintarAlertas(datos.metricas);
    } catch (error) {
      console.error("No se pudieron cargar las métricas:", error);
      mostrarError();
    }
  }

  cargarMetricas();
});