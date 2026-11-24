/**
 * InvenCore - Almacenamiento y Ubicaciones (SGA) (conectado al backend PHP)
 *
 * - Al cargar, pide la ocupación de cada zona a listar_almacenes.php (fetch) y actualiza
 *   en las 4 tarjetas: porcentaje, barra de progreso y "Estado SGA".
 * - Las pestañas filtran las tarjetas por almacén:
 *     "Todos los Almacenes" → todas | "Almacén Principal" → data-almacen="principal"
 *     "Almacén Secundario"  → data-almacen="secundario"
 *
 * Cada tarjeta se enlaza con su zona por data-zona (la misma clave que devuelve el servidor).
 */
document.addEventListener("DOMContentLoaded", () => {
  const URL_ZONAS = "../../controllers/logistica/listar_almacenes.php";
  const URL_LOGIN = "../seguridad/login.html";

  /* ---------- Elementos ---------- */
  const tabs = document.querySelectorAll(".alm-tab[data-filtro]");
  const columnas = document.querySelectorAll("#grillaPasillos [data-almacen]");
  const alertaError = document.getElementById("almError");
  const avisoSinZona = document.getElementById("almAvisoSinZona");
  const btnReintentar = document.getElementById("btnReintentarZonas");

  /** Umbrales de color de la barra (el estado "Casi Saturado" lo decide el servidor). */
  const UMBRAL_ALTO = 70;          // desde aquí la barra pasa a ámbar
  const UMBRAL_SATURADO = 90;      // desde aquí la barra pasa a rojo

  const formatear = (n) => Number(n).toLocaleString("es-CO");

  /* =====================================================================
   * TARJETAS: pintar los datos reales de cada zona
   * ===================================================================== */

  /** Clase de color de la barra según el porcentaje: verde < 70 %, ámbar 70-89 %, rojo >= 90 %. */
  function claseBarra(porcentaje) {
    if (porcentaje >= UMBRAL_SATURADO) return "bar-danger";
    if (porcentaje >= UMBRAL_ALTO) return "bar-warning";
    return "bar-success";
  }

  /** Actualiza una tarjeta con los datos de su zona. */
  function pintarZona(columna, zona) {
    const valor = columna.querySelector(".ocupacion-valor");
    const progreso = columna.querySelector(".alm-progress");
    const barra = columna.querySelector(".progress-bar");
    const estado = columna.querySelector(".estado-sga");

    // Porcentaje (puede pasar de 100 % si hay más stock que la capacidad teórica)
    valor.textContent = `${zona.porcentaje}%`;
    valor.classList.toggle("valor-critico", zona.estado === "Casi Saturado");
    valor.title = `${formatear(zona.unidades)} de ${formatear(zona.capacidad)} unidades · ${zona.productos} ${zona.productos === 1 ? "producto" : "productos"}`;

    // Barra de progreso: el ancho se limita a 100 %
    const ancho = Math.min(zona.porcentaje, 100);
    barra.style.width = `${ancho}%`;
    barra.className = `progress-bar ${claseBarra(zona.porcentaje)}`;
    progreso.setAttribute("aria-valuenow", String(ancho));

    // Estado SGA: "Espacio Disponible" (verde) o "Casi Saturado" (rojo)
    const saturada = zona.estado === "Casi Saturado";
    estado.className = `estado-sga ${saturada ? "estado-critico" : "estado-ok"}`;
    estado.replaceChildren();
    const icono = document.createElement("i");
    icono.className = `bi ${saturada ? "bi-exclamation-triangle" : "bi-check-circle"} me-1`;
    estado.append(icono, zona.estado);
  }

  /** Tarjeta sin datos (zona que el servidor no devolvió). */
  function pintarSinDatos(columna) {
    columna.querySelector(".ocupacion-valor").textContent = "—";
    columna.querySelector(".progress-bar").style.width = "0%";
    const estado = columna.querySelector(".estado-sga");
    estado.className = "estado-sga estado-cargando";
    estado.textContent = "Sin datos";
  }

  /** Aviso con los productos cuya ubicación no es una zona oficial (no entran en ningún porcentaje). */
  function mostrarSinZona(sinZona) {
    if (sinZona.productos > 0) {
      avisoSinZona.textContent =
        `${sinZona.productos} ${sinZona.productos === 1 ? "producto" : "productos"} ` +
        `(${formatear(sinZona.unidades)} unidades) tienen una ubicación que no es una zona oficial del SGA ` +
        "y no se cuentan en estos porcentajes. Edítalos en el Catálogo para asignarles una zona.";
      avisoSinZona.classList.remove("d-none");
    } else {
      avisoSinZona.classList.add("d-none");
    }
  }

  /* =====================================================================
   * READ: cargar la ocupación desde la base de datos
   * ===================================================================== */
  async function cargarZonas() {
    alertaError.classList.add("d-none");

    try {
      const response = await fetch(URL_ZONAS, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });

      if (response.status === 401) {          // Sesión vencida: al login
        window.location.replace(URL_LOGIN);
        return;
      }

      // Se lee como texto y se interpreta con cuidado: si PHP falla, el cuerpo puede venir vacío
      const texto = await response.text();
      let datos = null;
      try {
        datos = JSON.parse(texto);
      } catch (errorJson) {
        /* respuesta vacía o que no es JSON */
      }

      if (!response.ok || !datos || !datos.ok) {
        console.error(`listar_almacenes.php respondió HTTP ${response.status}:`, texto || "(respuesta vacía)");
        throw new Error((datos && datos.error) || `http_${response.status}`);
      }

      // Cada tarjeta busca su zona por la clave (data-zona)
      const porClave = new Map(datos.zonas.map((z) => [z.clave, z]));
      columnas.forEach((columna) => {
        const zona = porClave.get(columna.dataset.zona);
        if (zona) pintarZona(columna, zona);
        else pintarSinDatos(columna);
      });

      mostrarSinZona(datos.sin_zona);
    } catch (error) {
      console.error("No se pudo cargar la ocupación de los almacenes:", error);
      columnas.forEach(pintarSinDatos);
      alertaError.classList.remove("d-none");
    }
  }

  btnReintentar.addEventListener("click", cargarZonas);

  /* =====================================================================
   * PESTAÑAS: filtrar las tarjetas por almacén
   * ===================================================================== */

  /** Muestra solo las tarjetas del almacén indicado ("all" las muestra todas). */
  function filtrarAlmacen(filtro) {
    columnas.forEach((col) => {
      const visible = filtro === "all" || col.dataset.almacen === filtro;
      col.classList.toggle("d-none", !visible);
    });
  }

  tabs.forEach((tab) => {
    tab.addEventListener("click", () => {
      // Marca la pestaña activa (estilo y accesibilidad)
      tabs.forEach((t) => {
        const activa = t === tab;
        t.classList.toggle("active", activa);
        t.setAttribute("aria-selected", String(activa));
      });

      filtrarAlmacen(tab.dataset.filtro);
    });
  });

  /* ---------- Inicio ---------- */
  cargarZonas();
});