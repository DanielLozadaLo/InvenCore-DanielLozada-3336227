/**
 * InvenCore - Gestión de Entradas y Recepciones (conectado al backend PHP)
 *
 * - READ:   al cargar, pide las entradas (y los catálogos de productos y proveedores)
 *           a listar_entradas.php y dibuja la tabla y los indicadores
 * - CREATE: el modal envía el formulario con fetch() a registrar_entrada.php, sin recargar la página.
 *           La entrada nace 'Pendiente' (el stock NO se suma todavía)
 * - UPDATE: los botones de "Acciones de Flujo" llaman a actualizar_estado_entrada.php con fetch():
 *           Pendiente → En Inspección → Recepcionado (al aprobar, el servidor suma el stock)
 * - Búsqueda (OC, proveedor o producto) + filtro por estado, sobre los datos cargados
 */
document.addEventListener("DOMContentLoaded", () => {
  /* ---------- Rutas de los controladores y del login ---------- */
  const URL_LISTAR = "../../controllers/logistica/listar_entradas.php";
  const URL_REGISTRAR = "../../controllers/logistica/registrar_entrada.php";
  const URL_ACTUALIZAR = "../../controllers/logistica/actualizar_estado_entrada.php";
  const URL_LOGIN = "../seguridad/login.html";

  /* ---------- Referencias al DOM ---------- */
  const body = document.getElementById("entradasBody");
  const filaEstado = document.getElementById("filaEstado");
  const celdaEstado = document.getElementById("celdaEstado");
  const searchInput = document.getElementById("buscarEntrada");
  const estadoLabel = document.getElementById("estadoActual");
  const estadoItems = document.querySelectorAll(".ent-dropdown [data-filtro]"); // Solo los ítems del desplegable

  // Modal
  const modalEl = document.getElementById("modalNuevaEntrada");
  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const form = document.getElementById("formNuevaEntrada");
  const selectProducto = document.getElementById("entProducto");
  const selectProveedor = document.getElementById("entProveedor");
  const selectAlmacen = document.getElementById("entAlmacen");
  const errorModal = document.getElementById("entradaError");
  const btnRegistrar = document.getElementById("btnRegistrarEntrada");
  const spinner = document.getElementById("entradaSpinner");
  const iconoBoton = document.getElementById("entradaIcono");

  /* ---------- Estado ---------- */
  let entradas = [];       // Movimientos de tipo ENTRADA
  let productos = [];      // Catálogo para el select del modal
  let proveedores = [];    // Catálogo para el select del modal
  let estadoFiltro = "all";

  /* ---------- Utilidades ---------- */
  // Minúsculas y sin tildes ("Inspección" == "inspeccion")
  const normalize = (text) =>
    text.toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");

  const formatear = (n) => Number(n).toLocaleString("es-CO");
  const capitalizar = (t) => (t ? t.charAt(0).toUpperCase() + t.slice(1) : t);

  /** Estados posibles de una entrada (el texto guardado en movimientos.estado). */
  const ESTADOS = {
    pendiente: { clave: "pendiente", etiqueta: "Pendiente", clase: "estado-pendiente", icono: "bi-clock" },
    "en inspeccion": { clave: "inspeccion", etiqueta: "En Inspección", clase: "estado-inspeccion", icono: "bi-info-circle" },
    recepcionado: { clave: "recibido", etiqueta: "Recepcionado", clase: "estado-recibido", icono: "bi-check-circle" },
  };
  const estadoDe = (texto) =>
    ESTADOS[normalize(texto)] || { clave: "otro", etiqueta: texto || "Sin estado", clase: "estado-pendiente", icono: "bi-question-circle" };

  // Mensajes para los códigos de error que envían los controladores
  const ERRORES_SERVIDOR = {
    campos_vacios: "Completa todos los campos obligatorios.",
    producto_invalido: "Selecciona un producto válido.",
    proveedor_invalido: "Selecciona un proveedor válido.",
    cantidad_invalida: "La cantidad debe ser un número entero entre 1 y 1.000.000.",
    almacen_invalido: "Selecciona un almacén y pasillo de destino válidos.",
    motivo_largo: "El motivo no puede superar los 255 caracteres.",
    producto_no_encontrado: "El producto seleccionado ya no existe.",
    proveedor_no_encontrado: "El proveedor seleccionado ya no existe.",
    stock_excedido: "La cantidad supera el stock máximo que admite el sistema.",
    id_invalido: "La entrada indicada no es válida.",
    accion_invalida: "La acción solicitada no es válida.",
    no_encontrado: "La entrada ya no existe.",
    estado_desactualizado: "Esta entrada ya cambió de estado. Se actualizó la tabla.",
    error_servidor: "No pudimos registrar la entrada. Inténtalo de nuevo más tarde.",
  };

  /* =====================================================================
   * MENSAJES (Toast de Bootstrap)
   * ===================================================================== */
  function showToast(message, type = "success") {
    const container = document.getElementById("toastContainer");
    const toastEl = document.createElement("div");
    toastEl.className = `toast align-items-center text-bg-${type} border-0`;
    toastEl.setAttribute("role", "alert");
    toastEl.setAttribute("aria-live", "assertive");
    toastEl.setAttribute("aria-atomic", "true");

    const flex = document.createElement("div");
    flex.className = "d-flex";
    const toastBody = document.createElement("div");
    toastBody.className = "toast-body";
    toastBody.textContent = message;                  // textContent: nunca se interpreta como HTML
    const close = document.createElement("button");
    close.type = "button";
    close.className = "btn-close btn-close-white me-2 m-auto";
    close.setAttribute("data-bs-dismiss", "toast");
    close.setAttribute("aria-label", "Cerrar");
    flex.append(toastBody, close);
    toastEl.appendChild(flex);

    container.appendChild(toastEl);
    toastEl.addEventListener("hidden.bs.toast", () => toastEl.remove());
    new bootstrap.Toast(toastEl, { delay: 3500 }).show();
  }

  /** Lee una respuesta del servidor: devuelve { response, datos } (datos = null si no era JSON). */
  async function leerRespuesta(response) {
    const texto = await response.text();
    let datos = null;
    try {
      datos = JSON.parse(texto);
    } catch (errorJson) {
      /* respuesta vacía o que no es JSON */
    }
    if (!response.ok || !datos || !datos.ok) {
      console.error(`El servidor respondió HTTP ${response.status}:`, texto || "(respuesta vacía)");
    }
    return { response, datos };
  }

  /* =====================================================================
   * TABLA
   * ===================================================================== */

  /** Botón o texto de la columna "Acciones de Flujo" según el estado. */
  function crearAccion(estado) {
    if (estado.clave === "recibido") {
      // Etapa final: ya se sumó al stock
      const texto = document.createElement("span");
      texto.className = "accion-texto";
      texto.textContent = "Ingresado al Kardex";
      return texto;
    }

    // Pendiente → botón "Pasar a Inspección"; En Inspección → botón "Aprobar y Conforme"
    const boton = document.createElement("button");
    boton.type = "button";
    if (estado.clave === "pendiente") {
      boton.className = "btn btn-flujo btn-flujo-outline";
      boton.dataset.accion = "inspeccionar";
      boton.textContent = "Pasar a Inspección";
    } else {
      boton.className = "btn btn-flujo btn-flujo-solid";
      boton.dataset.accion = "aprobar";
      boton.textContent = "Aprobar y Conforme";
    }
    return boton;
  }

  /** Crea la fila <tr> de una entrada. Los datos se insertan con textContent (seguro contra XSS). */
  function crearFila(e) {
    const estado = estadoDe(e.estado);
    const fila = document.createElement("tr");
    fila.dataset.id = String(e.id_movimiento);
    fila.dataset.oc = e.nro_guia;
    fila.dataset.estado = estado.clave;

    // Estructura fija: solo marcado propio, sin datos del usuario
    fila.innerHTML =
      '<td class="ps-4"><div class="oc-cell"><i class="bi bi-file-earmark-check oc-icon"></i>' +
      '<div><div class="oc-code"></div><div class="oc-date"></div></div></div></td>' +
      '<td class="cell-proveedor"><i class="bi bi-building me-2"></i><span class="prov-texto"></span></td>' +
      '<td class="cell-producto fw-semibold"></td>' +
      '<td class="text-center cell-qty"></td>' +
      '<td class="cell-almacen"></td>' +
      '<td class="cell-estado"></td>' +
      '<td class="cell-inspector"></td>' +
      '<td class="text-end pe-4 cell-accion"></td>';

    fila.querySelector(".oc-code").textContent = e.nro_guia || `MOV-${e.id_movimiento}`;
    fila.querySelector(".oc-date").textContent = e.fecha.slice(0, 10);
    fila.querySelector(".prov-texto").textContent = e.proveedor;
    fila.querySelector(".cell-producto").textContent = e.producto;
    fila.querySelector(".cell-producto").title = e.sku;
    fila.querySelector(".cell-almacen").textContent = e.almacen_destino || "—";

    // Cantidad esperada / recibida (la BD guarda una sola cantidad: pendiente = aún no recibida)
    const recibida = estado.clave === "pendiente" ? 0 : e.cantidad;
    const celdaQty = fila.querySelector(".cell-qty");
    const spanRecibida = document.createElement("span");
    spanRecibida.className = recibida > 0 ? "qty-ok" : "qty-zero";
    spanRecibida.textContent = formatear(recibida);
    celdaQty.append(`${formatear(e.cantidad)} / `, spanRecibida);

    // Estado
    const badge = document.createElement("span");
    badge.className = `estado-badge ${estado.clase}`;
    const icono = document.createElement("i");
    icono.className = `bi ${estado.icono} me-1`;
    badge.append(icono, estado.etiqueta);
    fila.querySelector(".cell-estado").appendChild(badge);

    // Inspector / usuario que registró
    fila.querySelector(".cell-inspector").textContent = e.inspector
      ? `${e.inspector}${e.rol_inspector ? ` (${capitalizar(e.rol_inspector)})` : ""}`
      : "Pendiente asignación";

    fila.querySelector(".cell-accion").appendChild(crearAccion(estado));
    return fila;
  }

  /** Muestra la fila de estado: "cargando", "vacio", "sinResultados" o "error". */
  function mostrarEstado(tipo) {
    celdaEstado.replaceChildren();

    if (tipo === "cargando") {
      const spinnerEl = document.createElement("span");
      spinnerEl.className = "spinner-border spinner-border-sm me-2";
      spinnerEl.setAttribute("aria-hidden", "true");
      celdaEstado.append(spinnerEl, "Cargando entradas...");
    } else if (tipo === "vacio") {
      celdaEstado.innerHTML = '<i class="bi bi-inbox fs-4 d-block mb-2"></i>Aún no hay entradas registradas.';
    } else if (tipo === "sinResultados") {
      celdaEstado.innerHTML = '<i class="bi bi-search fs-4 d-block mb-2"></i>No se encontraron entradas.';
    } else if (tipo === "error") {
      celdaEstado.innerHTML =
        '<i class="bi bi-exclamation-triangle fs-4 d-block mb-2"></i>No se pudieron cargar las entradas.' +
        '<div class="mt-2"><button type="button" class="btn btn-sm btn-outline-secondary" data-action="reintentar">Reintentar</button></div>';
    }

    filaEstado.classList.remove("d-none");
  }

  /** Dibuja la tabla con las entradas cargadas y reaplica búsqueda y filtro. */
  function renderTabla() {
    body.querySelectorAll("tr[data-oc]").forEach((fila) => fila.remove());
    entradas.forEach((e) => body.insertBefore(crearFila(e), filaEstado));
    actualizarKpis();
    aplicarFiltros();
  }

  /** Indicadores de arriba, calculados con las entradas cargadas. */
  function actualizarKpis() {
    const hoy = new Date();
    const mesActual = `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, "0")}`;

    const pendientes = entradas.filter((e) => estadoDe(e.estado).clave === "pendiente").length;
    const enInspeccion = entradas.filter((e) => estadoDe(e.estado).clave === "inspeccion").length;
    const recibidosMes = entradas.filter(
      (e) => estadoDe(e.estado).clave === "recibido" && e.fecha.slice(0, 7) === mesActual
    ).length;

    document.getElementById("kpiPendientes").textContent = `${formatear(pendientes)} OC`;
    document.getElementById("kpiInspeccion").textContent = `${formatear(enInspeccion)} ${enInspeccion === 1 ? "Lote" : "Lotes"}`;
    document.getElementById("kpiRecibidos").textContent = `${formatear(recibidosMes)} ${recibidosMes === 1 ? "Completado" : "Completados"}`;
  }

  /* =====================================================================
   * BÚSQUEDA Y FILTRO POR ESTADO
   * ===================================================================== */
  function aplicarFiltros() {
    const query = normalize(searchInput.value.trim());
    let visibles = 0;

    body.querySelectorAll("tr[data-oc]").forEach((fila) => {
      const texto = normalize(
        [
          fila.dataset.oc,
          fila.querySelector(".cell-proveedor").textContent,
          fila.querySelector(".cell-producto").textContent,
        ].join(" ")
      );
      const coincideTexto = texto.includes(query);
      const coincideEstado = estadoFiltro === "all" || fila.dataset.estado === estadoFiltro;
      const mostrar = coincideTexto && coincideEstado;

      fila.classList.toggle("d-none", !mostrar);
      if (mostrar) visibles++;
    });

    if (entradas.length === 0) {
      mostrarEstado("vacio");
    } else if (visibles === 0) {
      mostrarEstado("sinResultados");
    } else {
      filaEstado.classList.add("d-none");
    }
  }

  searchInput.addEventListener("input", aplicarFiltros);

  estadoItems.forEach((item) => {
    item.addEventListener("click", () => {
      estadoFiltro = item.dataset.filtro;
      estadoLabel.textContent = item.textContent;
      estadoItems.forEach((i) => i.classList.toggle("active", i === item));
      aplicarFiltros();
    });
  });

  // Clics dentro de la tabla (las filas se crean dinámicamente: se usa delegación de eventos)
  body.addEventListener("click", (event) => {
    // Botón "Reintentar" de la fila de error
    if (event.target.closest('[data-action="reintentar"]')) {
      cargarEntradas();
      return;
    }

    // Botones de "Acciones de Flujo"
    const boton = event.target.closest("[data-accion]");
    if (!boton || boton.disabled) return;

    const fila = boton.closest("tr[data-id]");
    const entrada = fila && entradas.find((e) => e.id_movimiento === Number(fila.dataset.id));
    if (entrada) cambiarEstado(entrada, boton.dataset.accion, boton);
  });

  /* =====================================================================
   * READ: cargar entradas desde la base de datos
   * ===================================================================== */
  async function cargarEntradas() {
    if (entradas.length === 0) mostrarEstado("cargando");

    try {
      const response = await fetch(URL_LISTAR, {
        credentials: "same-origin",
        cache: "no-store",
        headers: { Accept: "application/json" },
      });

      if (response.status === 401) {          // Sesión vencida: al login
        window.location.replace(URL_LOGIN);
        return;
      }

      const { datos } = await leerRespuesta(response);
      if (!response.ok || !datos || !datos.ok) throw new Error((datos && datos.error) || `http_${response.status}`);

      entradas = datos.entradas;
      productos = datos.productos;
      proveedores = datos.proveedores;
      renderTabla();
    } catch (error) {
      console.error("No se pudieron cargar las entradas:", error);
      entradas = [];
      renderTabla();
      mostrarEstado("error");
      showToast("No se pudieron cargar las entradas.", "danger");
    }
  }

  /* =====================================================================
   * UPDATE: avanzar una entrada por el flujo de aprobación
   *   "inspeccionar": Pendiente     → En Inspección
   *   "aprobar":      En Inspección → Recepcionado (el servidor suma el stock del producto)
   * ===================================================================== */
  async function cambiarEstado(entrada, accion, boton) {
    const textoOriginal = boton.textContent;
    boton.disabled = true;                 // Evita dobles clics mientras el servidor responde
    boton.textContent = "Procesando...";

    const restaurarBoton = () => {
      boton.disabled = false;
      boton.textContent = textoOriginal;
    };

    try {
      const response = await fetch(URL_ACTUALIZAR, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: new URLSearchParams({ id_movimiento: String(entrada.id_movimiento), accion }),
      });

      if (response.status === 401) {       // Sesión vencida: al login
        window.location.replace(URL_LOGIN);
        return;
      }

      const { datos } = await leerRespuesta(response);

      if (!response.ok || !datos || !datos.ok) {
        showToast(ERRORES_SERVIDOR[datos && datos.error] || ERRORES_SERVIDOR.error_servidor, "danger");

        if (datos && datos.error === "estado_desactualizado") {
          await cargarEntradas();          // La tabla estaba desactualizada: se refresca
        } else {
          restaurarBoton();
        }
        return;
      }

      // Éxito: mensaje y recarga inmediata de la tabla y los indicadores
      if (accion === "aprobar") {
        const stock = datos.stock_actual !== undefined ? ` Stock actual del producto: ${formatear(datos.stock_actual)} u.` : "";
        showToast(`Guía ${datos.nro_guia} aprobada: ingresada al Kardex.${stock}`, "success");
      } else {
        showToast(`Guía ${datos.nro_guia} pasó a Inspección de Calidad.`, "primary");
      }
      await cargarEntradas();
    } catch (error) {
      console.error("No se pudo actualizar el estado de la entrada:", error);
      showToast("No se pudo conectar con el servidor. Inténtalo de nuevo.", "danger");
      restaurarBoton();
    }
  }

  /* =====================================================================
   * MODAL: selects dinámicos
   * ===================================================================== */

  /** Llena un <select> con una lista; conserva la opción inicial (placeholder). */
  function poblarSelect(select, lista, textoOpcion, valor, placeholder) {
    select.replaceChildren(new Option(placeholder, "", true, true));
    select.options[0].disabled = true;

    lista.forEach((item) => select.add(new Option(textoOpcion(item), String(valor(item)))));
  }

  // Al abrir el modal se llenan los selects con los datos más recientes de la base de datos
  modalEl.addEventListener("show.bs.modal", () => {
    poblarSelect(
      selectProducto, productos,
      (p) => `${p.sku} · ${p.nombre}`, (p) => p.id_producto,
      productos.length ? "Selecciona un producto" : "No hay productos registrados"
    );
    poblarSelect(
      selectProveedor, proveedores,
      (pr) => pr.nombre_empresa, (pr) => pr.id_proveedor,
      proveedores.length ? "Selecciona un proveedor" : "No hay proveedores registrados"
    );
  });

  // Al elegir un producto, el destino se preselecciona con su ubicación SGA actual
  // (se puede cambiar: así el stock entra donde el producto ya está guardado)
  selectProducto.addEventListener("change", () => {
    const producto = productos.find((p) => p.id_producto === Number(selectProducto.value));
    if (!producto) return;

    const existe = [...selectAlmacen.options].some((o) => o.value === producto.ubicacion_sga);
    if (existe) selectAlmacen.value = producto.ubicacion_sga;
  });

  // Al cerrar el modal se limpia el formulario y los mensajes
  modalEl.addEventListener("hidden.bs.modal", () => {
    form.reset();
    form.classList.remove("was-validated");
    errorModal.classList.add("d-none");
  });

  /* =====================================================================
   * CREATE: enviar el formulario con fetch() (sin recargar la página)
   * ===================================================================== */
  function mostrarErrorModal(mensaje) {
    errorModal.textContent = mensaje;
    errorModal.classList.remove("d-none");
  }

  function setEnviando(enviando) {
    btnRegistrar.disabled = enviando;
    spinner.classList.toggle("d-none", !enviando);
    iconoBoton.classList.toggle("d-none", enviando);
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault(); // Se envía con fetch(), no con la recarga normal del formulario
    errorModal.classList.add("d-none");

    if (!form.checkValidity()) {
      event.stopPropagation();
      form.classList.add("was-validated");
      form.querySelector(":invalid").focus();
      return;
    }

    setEnviando(true);
    try {
      const response = await fetch(URL_REGISTRAR, {
        method: "POST",
        credentials: "same-origin",
        headers: { Accept: "application/json" },
        body: new FormData(form),
      });

      if (response.status === 401) {
        window.location.replace(URL_LOGIN);
        return;
      }

      const { datos } = await leerRespuesta(response);
      if (!response.ok || !datos || !datos.ok) {
        // El modal se queda abierto con los datos escritos
        mostrarErrorModal(ERRORES_SERVIDOR[datos && datos.error] || ERRORES_SERVIDOR.error_servidor);
        return;
      }

      modal.hide();
      showToast("Entrada registrada con éxito. Queda Pendiente de inspección.", "success");
      await cargarEntradas();          // Recarga la tabla y los indicadores con el nuevo registro
    } catch (error) {
      console.error("No se pudo registrar la entrada:", error);
      mostrarErrorModal("No se pudo conectar con el servidor. Revisa tu conexión e inténtalo de nuevo.");
    } finally {
      setEnviando(false);
    }
  });

  /* ---------- Inicio ---------- */
  cargarEntradas();
});